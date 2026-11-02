<?php
/**
 * Sends a WooCommerce order to Sage X3 as a sales order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageX3;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The class, representation and property names come from the settings. The order is found by its customer order reference before it is
 * created; the answer of the create is read for `$diagnoses`, because a 201 only means the body was accepted.
 */
final class SageX3OrderSender {

	/**
	 * Set up with the services it uses.
	 *
	 * @param SageX3Client   $client   Client.
	 * @param SageX3Settings $settings Names of the folder.
	 * @param Options        $options  Connection options.
	 * @param Amounts        $amounts  Money helper.
	 * @param string         $system   System name.
	 */
	public function __construct(
		private readonly SageX3Client $client,
		private readonly SageX3Settings $settings,
		private readonly Options $options,
		private readonly Amounts $amounts,
		private readonly string $system
	) {
	}

	/**
	 * The record of an order that exists; null otherwise.
	 *
	 * @param Item $item The queue item.
	 *
	 * @throws ApiException On failure.
	 */
	public function finished( Item $item ): ?ExternalRef {
		$order = $this->find( $item->clientReference() );
		return null === $order ? null : $this->reference( $order, '' );
	}

	/**
	 * The order of a client reference (the first).
	 *
	 * @param string $clientRef WC-{id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $clientRef ): ?array {
		$property = $this->settings->get( 'prop_reference' );
		$rows     = $this->client->query( $this->settings->get( 'order_class' ), $this->settings->get( 'order_representation' ), $property . ' eq ' . SageX3Client::literal( $clientRef ), 20 );
		foreach ( $rows as $row ) {
			if ( strtolower( trim( (string) ( $row[ $property ] ?? '' ) ) ) === strtolower( $clientRef ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Send an order.
	 *
	 * @param Item         $item    The queue item.
	 * @param OrderPayload $payload The order.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Item $item, OrderPayload $payload ): SendResult {
		unset( $item );
		if ( '' === $this->settings->get( 'customer_code' ) ) {
			return SendResult::failure( Problems::refused( $this->system, 'customer_code', __( 'Enter the customer code that should receive the web orders in the connection settings.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$unchecked = $this->totalUnchecked();
		if ( $unchecked instanceof ConnectorError ) {
			return SendResult::failure( $unchecked );
		}
		$existing = $this->find( $payload->clientRef );
		$location = '';
		if ( null === $existing ) {
			$created = $this->create( $payload );
			if ( $created instanceof ConnectorError ) {
				return SendResult::failure( $created );
			}
			$order    = $created['body'];
			$location = $created['location'];
		} else {
			$order = $existing;
		}
		$warnings = array();
		$problem  = $this->verify( $order, $payload, $warnings );
		if ( $problem instanceof ConnectorError ) {
			return SendResult::failure( $problem );
		}
		$ref = $this->reference( $order, $location, $payload->currency, $warnings );
		return null === $existing ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The refusal for a connection that cannot compare totals, unless the person chose to continue with a warning.
	 *
	 * Prices from the folder's price lists may differ from the shop's unnoticed, so an order is not sent while the total cannot be read back.
	 */
	public function totalUnchecked(): ?ConnectorError {
		if ( '' !== $this->settings->get( 'total_property' ) || ! ( new TotalCheck( $this->options, $this->amounts, $this->system ) )->isStrict() ) {
			return null;
		}
		return Problems::refused(
			$this->system,
			'total_property',
			__( 'Enter the property that holds the order total in "total_property", so the total can be compared with the shop. Or choose "Continue with a warning" for totals that differ, and accept that prices from the folder\'s price lists can differ unnoticed.', 'estasoft-erp-crm-sync-engine' ),
			'total not verifiable'
		);
	}

	/**
	 * The record for an order: the number read from the answer, else from the Location header.
	 *
	 * @param array<string,mixed> $order    Order record.
	 * @param string              $location Location header of the create, or empty.
	 * @param string              $currency ISO code of the order, or empty.
	 * @param array<int,string>   $warnings Warnings to carry.
	 */
	public function reference( array $order, string $location, string $currency = '', array $warnings = array() ): ExternalRef {
		$number = $this->numberOf( $order, $location );
		if ( '' === $currency && isset( $order[ $this->settings->get( 'prop_currency' ) ] ) && is_string( $order[ $this->settings->get( 'prop_currency' ) ] ) ) {
			$currency = strtoupper( trim( $order[ $this->settings->get( 'prop_currency' ) ] ) );
		}
		$extra = array( 'currency' => $currency );
		$total = $this->totalOf( $order );
		if ( null !== $total && '' !== $currency ) {
			$extra['total_minor'] = $this->amounts->toMinor( $total, $this->amounts->exponent( $currency ) );
		}
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( $number, $number, null, $extra );
	}

	/**
	 * The number of an order.
	 *
	 * @param array<string,mixed> $order    Order record.
	 * @param string              $location Location header, or empty.
	 */
	private function numberOf( array $order, string $location ): string {
		$value = $order[ $this->settings->get( 'prop_number' ) ] ?? '';
		if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
			return trim( (string) $value );
		}
		if ( 1 === preg_match( "/\\('([^']+)'\\)\\s*$/", rawurldecode( $location ), $match ) ) {
			return $match[1];
		}
		return trim( (string) ( $order[ $this->settings->get( 'prop_reference' ) ] ?? '' ) );
	}

	/**
	 * The total of an order record, or null when the setting is empty or the record has none.
	 *
	 * @param array<string,mixed> $order Order record.
	 */
	private function totalOf( array $order ): ?float {
		$name = $this->settings->get( 'total_property' );
		return '' !== $name && isset( $order[ $name ] ) && is_numeric( $order[ $name ] ) ? (float) $order[ $name ] : null;
	}

	/**
	 * Check, then create the order.
	 *
	 * @param OrderPayload $payload The order.
	 * @return array{body:array<string,mixed>,location:string}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( OrderPayload $payload ): array|ConnectorError {
		$problem = $this->precheck( $payload );
		if ( $problem instanceof ConnectorError ) {
			return $problem;
		}
		$skus = array_map( static fn( array $line ): string => (string) $line['sku'], $payload->lines );
		foreach ( $payload->extras as $extra ) {
			$skus[] = $this->settings->get( $this->itemOption( $extra ) );
		}
		if ( $this->settings->itemCheck() ) {
			foreach ( array_unique( $skus ) as $sku ) {
				$rows = $this->client->query( $this->settings->get( 'item_class' ), $this->settings->get( 'item_representation' ), $this->settings->get( 'prop_item_code' ) . ' eq ' . SageX3Client::literal( $sku ), 1 );
				if ( array() === $rows ) {
					return Problems::missingProduct( $this->system, $sku );
				}
			}
		}
		$exponent = $this->amounts->exponent( $payload->currency );
		$lines    = array();
		foreach ( $payload->lines as $line ) {
			$price   = $this->discounted( (int) $line['price_minor'], (float) $line['discount'] );
			$lines[] = array(
				$this->settings->get( 'prop_line_item' )  => (string) $line['sku'],
				$this->settings->get( 'prop_line_quantity' ) => (float) $line['quantity'],
				$this->settings->get( 'prop_line_price' ) => $this->amounts->toDecimal( $price, $exponent ),
			);
		}
		foreach ( $payload->extras as $extra ) {
			$sign    = 'discount' === $extra['kind'] ? -1 : 1;
			$lines[] = array(
				$this->settings->get( 'prop_line_item' )  => $this->settings->get( $this->itemOption( $extra ) ),
				$this->settings->get( 'prop_line_quantity' ) => 1.0,
				$this->settings->get( 'prop_line_price' ) => $sign * $this->amounts->toDecimal( (int) $extra['amount_minor'], $exponent ),
			);
		}
		$body = array(
			$this->settings->get( 'prop_customer' )  => $this->settings->get( 'customer_code' ),
			$this->settings->get( 'prop_date' )      => '' !== $payload->date ? $payload->date : gmdate( 'Y-m-d' ),
			$this->settings->get( 'prop_reference' ) => $payload->clientRef,
			$this->settings->get( 'prop_currency' )  => $payload->currency,
		);
		if ( '' !== $this->settings->get( 'site' ) ) {
			$body[ $this->settings->get( 'prop_site' ) ] = $this->settings->get( 'site' );
		}
		$body[ $this->settings->get( 'prop_lines' ) ] = $lines;

		$created = $this->client->create( $this->settings->get( 'order_class' ), $this->settings->get( 'order_representation' ), $body );
		if ( '' === $this->numberOf( $created['body'], $created['location'] ) || '' === trim( (string) ( $created['body'][ $this->settings->get( 'prop_reference' ) ] ?? '' ) ) ) {
			$found = $this->find( $payload->clientRef );
			if ( null === $found ) {
				return Problems::refused( $this->system, 'order_class', __( 'Sage X3 accepted the order but it cannot be found again by its reference. Check that the reference property is the one that holds the customer order reference.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$created['body'] = $found + $created['body'];
		}
		return $created;
	}

	/**
	 * Everything that can be refused without a request.
	 *
	 * @param OrderPayload $payload The order.
	 */
	private function precheck( OrderPayload $payload ): ?ConnectorError {
		if ( '' === $payload->currency ) {
			return Problems::refused( $this->system, 'currency', __( 'The order has no currency.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( $payload->pricesIncludeTax ) {
			return Problems::refused(
				$this->system,
				'prices_include_tax',
				__( 'Sage X3 works out tax itself and this connector sends prices without tax. Set the shop to show prices without tax in the mapping.', 'estasoft-erp-crm-sync-engine' )
			);
		}
		foreach ( $payload->extras as $extra ) {
			if ( '' === $this->settings->get( $this->itemOption( $extra ) ) ) {
				$option = $this->itemOption( $extra );
				return Problems::refused(
					$this->system,
					$option,
					sprintf(
						/* translators: %s: setting name. */
						__( 'The order has a shipping, fee or discount amount. How Sage X3 carries these differs per folder, so the connection settings need the item code of a service item in "%s"; the amount is then sent as a line with that item.', 'estasoft-erp-crm-sync-engine' ),
						$option
					)
				);
			}
		}
		return null;
	}

	/**
	 * The setting that holds the item code of an extra row.
	 *
	 * @param array<string,mixed> $extra Extra row.
	 */
	private function itemOption( array $extra ): string {
		return match ( $extra['kind'] ) {
			'shipping' => 'shipping_item',
			'discount' => 'discount_item',
			default    => 'fee_item',
		};
	}

	/**
	 * Check currency and total of a stored order.
	 *
	 * @param array<string,mixed> $order    Order record.
	 * @param OrderPayload        $payload  The order.
	 * @param array<int,string>   $warnings Receives warnings.
	 */
	private function verify( array $order, OrderPayload $payload, array &$warnings ): ?ConnectorError {
		$seen = $order[ $this->settings->get( 'prop_currency' ) ] ?? null;
		if ( is_string( $seen ) && '' !== trim( $seen ) && strtoupper( trim( $seen ) ) !== $payload->currency ) {
			return Problems::refused(
				$this->system,
				'currency',
				sprintf(
					/* translators: 1: currency the order has in Sage X3, 2: currency of the shop. */
					__( 'The order has the currency %1$s in Sage X3 but the shop order is in %2$s; Sage X3 takes the currency of the customer. It stays in Sage X3; delete it there and use a customer in the currency of the shop.', 'estasoft-erp-crm-sync-engine' ),
					strtoupper( trim( $seen ) ),
					$payload->currency
				),
				'currency mismatch'
			);
		}
		$total = $this->totalOf( $order );
		$check = new TotalCheck( $this->options, $this->amounts, $this->system );
		if ( null === $total ) {
			if ( $check->isStrict() ) {
				return Problems::refused(
					$this->system,
					'total_property',
					sprintf(
						/* translators: 1: order number, 2: property name. */
						__( 'Order %1$s was made but its answer has no "%2$s" value, so the total could not be compared with the shop. The order stays in Sage X3; check the property name, or choose "Continue with a warning".', 'estasoft-erp-crm-sync-engine' ),
						$this->numberOf( $order, '' ),
						$this->settings->get( 'total_property' )
					),
					'total missing'
				);
			}
			$warnings[] = __( 'No total was compared with the shop: the answer has no value in the "total_property" property.', 'estasoft-erp-crm-sync-engine' );
			return null;
		}
		$diff = $check->difference( $payload->totalMinor, $total, $payload->currency, $this->numberOf( $order, '' ) );
		if ( null === $diff ) {
			return null;
		}
		if ( $check->isStrict() ) {
			return $check->error( $diff, __( 'The order stays in Sage X3. Delete it there after fixing the tax setup; the next try makes a new one.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$warnings[] = $diff['message'];
		return null;
	}

	/**
	 * A unit price reduced by a line discount in percent, in whole numbers.
	 *
	 * @param int   $minor    Price in minor units.
	 * @param float $discount Percent (0 to 100).
	 */
	private function discounted( int $minor, float $discount ): int {
		if ( $discount <= 0.0 ) {
			return $minor;
		}
		$per = (int) round( $discount * 100 );
		$abs = abs( $minor );
		$net = intdiv( $abs * ( 10000 - $per ) + 5000, 10000 );
		return $minor < 0 ? -$net : $net;
	}
}
