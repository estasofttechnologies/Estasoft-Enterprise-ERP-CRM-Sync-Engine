<?php
/**
 * Shows what a rule set would send for a real order, and sends nothing.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\Ports\CanonicalSource;
use Estasoft\SyncEngine\Mapping\Ports\ConnectionSource;
use Estasoft\SyncEngine\Mapping\Ports\MappingStore;
use Estasoft\SyncEngine\Mapping\Ports\SourceNotFoundException;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The preview reads the order through WooCommerce, runs the live rules or a draft, and returns the source, the target, which targets differ from the
 * plain source and any problems. It never calls send() and never writes: not to the queue, not to the id map, not to the connected system.
 * With lookups switched on it also asks the connected system read-only questions (does this customer and each SKU exist?), and labels them as such.
 */
final class Previewer {

	/**
	 * Most SKUs looked up in one preview.
	 */
	private const MAX_SKU_LOOKUPS = 25;

	/**
	 * Set up with what it depends on.
	 *
	 * @param MappingStore      $store       Mapping versions.
	 * @param CanonicalSource   $source      Reads WooCommerce.
	 * @param RuleEngine        $engine      Rule engine.
	 * @param TaxMap            $taxes       Tax mapping.
	 * @param SkuMatcher        $products    Product matching.
	 * @param ConnectionSource  $connections Connections.
	 * @param ConnectorRegistry $connectors  Installed connectors.
	 * @param CanonicalFields   $fields      Canonical fields.
	 */
	public function __construct(
		private readonly MappingStore $store,
		private readonly CanonicalSource $source,
		private readonly RuleEngine $engine,
		private readonly TaxMap $taxes,
		private readonly SkuMatcher $products,
		private readonly ConnectionSource $connections,
		private readonly ConnectorRegistry $connectors,
		private readonly CanonicalFields $fields
	) {
	}

	/**
	 * Preview a mapping on one order.
	 *
	 * @param int                                 $connectionId Connection.
	 * @param string                              $type         orders, customers or refunds.
	 * @param int                                 $orderId      WooCommerce order id (for refunds, the order whose latest refund is shown).
	 * @param array<int,array<string,mixed>>|null $draftRules   Rules to try instead of the live ones, or null for the live ones.
	 * @param bool                                $lookups      Whether read-only lookups in the connected system are allowed.
	 * @return array{source:array<string,mixed>,target:array<string,mixed>,changed_paths:array<int,string>,rule_notes:array<int,array{path:string,note:string}>,lookups:array<int,array{label:string,found:bool,detail:string}>,errors:array<int,array<string,mixed>>}
	 */
	public function preview( int $connectionId, string $type, int $orderId, ?array $draftRules = null, bool $lookups = false ): array {
		try {
			$connection = $this->connections->find( $connectionId );
		} catch ( \Throwable $e ) {
			$connection = null;
		}
		$entity = self::entityFor( $type );
		if ( null === $connection ) {
			return $this->refused( __( 'The connection does not exist', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( null === $entity ) {
			return $this->refused( __( 'Only orders, customers and refunds can be previewed', 'estasoft-erp-crm-sync-engine' ) );
		}

		$rules = $draftRules ?? $this->liveRules( $connectionId, $type );
		if ( null === $rules ) {
			return $this->failed( array(), array( self::apiError( ErrorCode::Cfg001, array( 'type' => $type ) ) ) );
		}

		try {
			$order = $this->source->read( $entity, (string) $orderId, $this->fields->metaKeys( $rules ) );
		} catch ( SourceNotFoundException $e ) {
			return $this->refused( __( 'The order does not exist in WooCommerce', 'estasoft-erp-crm-sync-engine' ) );
		}

		$errors   = array();
		$system   = $this->systemName( $connection );
		$products = $lookups ? new ReadOnlyProductResolver( $this->products, true ) : null;
		$env      = new MappingEnvironment( $connectionId, $system, $this->taxes, $products );

		$empty = 'customers' === $type ? '' : NothingToSend::reason( $entity, $order );
		if ( '' !== $empty ) {
			$errors[] = self::apiError(
				ErrorCode::Val002,
				array(
					'system' => $system,
					'field'  => __( 'Order lines', 'estasoft-erp-crm-sync-engine' ),
					'reason' => $empty,
				)
			);
		}

		if ( 'orders' === $type ) {
			try {
				$this->taxes->assertBuyerTaxNumber( $connectionId, $order );
			} catch ( MappingException $e ) {
				$errors[] = self::apiError( $e->errorCode, $e->context );
			}
		}

		try {
			$output = $this->engine->applyAll( $rules, $order, $env );
		} catch ( LookupFailedException $e ) {
			return $this->failed( $order->toArray(), array_merge( $errors, array( self::apiError( $e->error->code, $e->error->context ) ) ) );
		}
		// A rate that is not mapped fails every rule that uses it (each line, the shipping line). Say it once.
		$told = array();
		foreach ( $output->errors as $failure ) {
			$error = self::apiError( $failure->errorCode, $failure->context );
			$same  = $error['code'] . '|' . $error['message'];
			if ( isset( $told[ $same ] ) ) {
				continue;
			}
			$told[ $same ] = true;
			$errors[]      = $error;
		}

		$notes = array();
		foreach ( array_merge( $output->notes, $output->warnings ) as $note ) {
			$notes[] = array(
				'path' => $note['target'],
				'note' => $note['message'],
			);
		}

		return array(
			'source'        => $order->toArray(),
			'target'        => $output->payload,
			'changed_paths' => $output->changed,
			'rule_notes'    => $notes,
			'lookups'       => $lookups ? $this->lookups( $connection, $system, $order ) : array(),
			'errors'        => $errors,
		);
	}

	/**
	 * The entity read for a mapping type. Customer rules read the same billing and customer fields as an order has.
	 *
	 * @param string $type Mapping type.
	 */
	private static function entityFor( string $type ): ?EntityType {
		return match ( $type ) {
			'orders', 'customers' => EntityType::Order,
			'refunds'             => EntityType::Refund,
			default               => null,
		};
	}

	/**
	 * Live rules of a type, or null when none were published.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $type         Mapping type.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function liveRules( int $connectionId, string $type ): ?array {
		$live = $this->store->live( $connectionId, $type );
		return null === $live ? null : ( is_array( $live['rules'] ?? null ) ? $live['rules'] : array() );
	}

	/**
	 * Read-only questions to the connected system.
	 *
	 * @param Connection     $connection Connection.
	 * @param string         $system     System name.
	 * @param CanonicalOrder $order      The order.
	 * @return array<int,array{label:string,found:bool,detail:string}>
	 */
	private function lookups( Connection $connection, string $system, CanonicalOrder $order ): array {
		$connector = $this->connectors->get( $connection->connector );
		if ( null === $connector ) {
			return array();
		}
		$lines = array();

		$email = $order->get( 'billing.email' );
		if ( is_string( $email ) && '' !== Text::trim( $email ) ) {
			/* translators: %s: name of the connected system. */
			$label   = sprintf( __( 'Customer in %s (read-only lookup)', 'estasoft-erp-crm-sync-engine' ), $system );
			$lines[] = $this->lookupLine( $label, $connector->lookupCustomer( $connection, Text::trim( $email ) ) );
		}

		$skus = array_map( static fn( $sku ): string => Text::trim( (string) $sku ), array_filter( (array) $order->get( 'lines[].sku' ), 'is_string' ) );
		foreach ( array_slice( array_values( array_unique( array_filter( $skus, static fn( string $sku ): bool => '' !== $sku ) ) ), 0, self::MAX_SKU_LOOKUPS ) as $sku ) {
			/* translators: 1: SKU, 2: name of the connected system. */
			$label   = sprintf( __( 'Product %1$s in %2$s (read-only lookup)', 'estasoft-erp-crm-sync-engine' ), $sku, $system );
			$lines[] = $this->lookupLine( $label, $connector->lookupProduct( $connection, $sku ) );
		}
		return $lines;
	}

	/**
	 * One lookup result.
	 *
	 * @param string                          $label  Label.
	 * @param ExternalRef|ConnectorError|null $result What the connector returned.
	 * @return array{label:string,found:bool,detail:string}
	 */
	private function lookupLine( string $label, ExternalRef|ConnectorError|null $result ): array {
		if ( $result instanceof ConnectorError ) {
			return array(
				'label'  => $label,
				'found'  => false,
				'detail' => $result->message(),
			);
		}
		if ( null === $result ) {
			return array(
				'label'  => $label,
				'found'  => false,
				'detail' => __( 'Not found.', 'estasoft-erp-crm-sync-engine' ),
			);
		}
		return array(
			'label'  => $label,
			'found'  => true,
			'detail' => '' === $result->ref ? $result->id : $result->ref,
		);
	}

	/**
	 * The name shown in messages.
	 *
	 * @param Connection $connection Connection.
	 */
	private function systemName( Connection $connection ): string {
		$connector = $this->connectors->get( $connection->connector );
		return null === $connector ? $connection->label : $connector->label();
	}

	/**
	 * A preview that could not be built at all (SYNC-PRV-001).
	 *
	 * @param string $reason Plain-language reason.
	 * @return array{source:array<string,mixed>,target:array<string,mixed>,changed_paths:array<int,string>,rule_notes:array<int,array{path:string,note:string}>,lookups:array<int,array{label:string,found:bool,detail:string}>,errors:array<int,array<string,mixed>>}
	 */
	private function refused( string $reason ): array {
		return $this->failed( array(), array( self::apiError( ErrorCode::Prv001, array( 'reason' => $reason ) ) ) );
	}

	/**
	 * An empty preview with errors.
	 *
	 * @param array<string,mixed>            $source Source data, if it was read.
	 * @param array<int,array<string,mixed>> $errors Errors.
	 * @return array{source:array<string,mixed>,target:array<string,mixed>,changed_paths:array<int,string>,rule_notes:array<int,array{path:string,note:string}>,lookups:array<int,array{label:string,found:bool,detail:string}>,errors:array<int,array<string,mixed>>}
	 */
	private function failed( array $source, array $errors ): array {
		return array(
			'source'        => $source,
			'target'        => array(),
			'changed_paths' => array(),
			'rule_notes'    => array(),
			'lookups'       => array(),
			'errors'        => $errors,
		);
	}

	/**
	 * An error in the shape of ApiErrorBody (src/api/types.ts).
	 *
	 * @param ErrorCode           $code    Catalog code.
	 * @param array<string,mixed> $context Values for the message.
	 * @return array{code:string,message:string,data:array{status:int,class:string,context:array<string,string>}}
	 */
	public static function apiError( ErrorCode $code, array $context = array() ): array {
		$strings = array();
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$strings[ (string) $key ] = Text::toString( $value );
			}
		}
		return array(
			'code'    => $code->value,
			'message' => ( new ConnectorError( $code, $context ) )->message(),
			'data'    => array(
				'status'  => 422,
				'class'   => $code->errorClass()->value,
				'context' => $strings,
			),
		);
	}
}
