<?php
/**
 * Builds the payload for a queue item at send time.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\Support\Json;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Mapping\Ports\CanonicalSource;
use Estasoft\SyncEngine\Mapping\Ports\EventLog;
use Estasoft\SyncEngine\Mapping\Ports\MappingStore;
use Estasoft\SyncEngine\Mapping\Ports\SourceNotFoundException;
use Estasoft\SyncEngine\Sync\PayloadBuilderInterface;
use Estasoft\SyncEngine\Sync\PayloadResult;

defined( 'ABSPATH' ) || exit;

/**
 * The queue calls this for every claimed item (QUE-2), so the latest order data and the live mapping are always used. It loads the
 * live mapping for the item's type, reads the order through the WooCommerce data layer, checks the buyer's tax number, runs the rules
 * and passes the result through the filter estasoft_sync_payload. A problem found here (SYNC-CFG-001, SYNC-MAP-001, -002, -003, SYNC-VAL-001)
 * comes back as a PayloadResult with an error; nothing has been sent at that point.
 */
final class PayloadBuilder implements PayloadBuilderInterface {

	/**
	 * Filter hook for add-ons.
	 */
	public const FILTER = 'estasoft_sync_payload';

	/**
	 * The filter function (apply_filters unless replaced in tests).
	 *
	 * @var \Closure
	 */
	private \Closure $filter;

	/**
	 * Set up with what it depends on.
	 *
	 * @param MappingStore      $store      Mapping versions.
	 * @param CanonicalSource   $source     Reads WooCommerce.
	 * @param RuleEngine        $engine     Rule engine.
	 * @param TaxMap            $taxes      Tax mapping.
	 * @param SkuMatcher        $products   Product matching.
	 * @param ConnectorRegistry $connectors Installed connectors.
	 * @param CanonicalFields   $fields     Canonical fields (for the meta keys rules ask for).
	 * @param EventLog          $log        Event log.
	 * @param \Closure|null     $filter     Replacement for apply_filters, for tests.
	 */
	public function __construct(
		private readonly MappingStore $store,
		private readonly CanonicalSource $source,
		private readonly RuleEngine $engine,
		private readonly TaxMap $taxes,
		private readonly SkuMatcher $products,
		private readonly ConnectorRegistry $connectors,
		private readonly CanonicalFields $fields,
		private readonly EventLog $log,
		?\Closure $filter = null
	) {
		$this->filter = $filter ?? static fn ( string $hook, mixed $value, mixed ...$args ): mixed => apply_filters( $hook, $value, ...$args );
	}

	/**
	 * Read the entity through the WooCommerce CRUD layer and apply the live mapping.
	 *
	 * @param Item       $item       Claimed item.
	 * @param Connection $connection Its connection.
	 */
	public function build( Item $item, Connection $connection ): PayloadResult {
		$type = self::mappingType( $item->entityType );
		if ( null === $type ) {
			return $this->failure( new ConnectorError( ErrorCode::Cfg001, array( 'type' => $item->entityType->value ) ), null );
		}
		$live = $this->store->live( $connection->id, $type );
		if ( null === $live ) {
			return $this->failure( new ConnectorError( ErrorCode::Cfg001, array( 'type' => $type ) ), null );
		}

		$version = (int) $live['version'];
		$rules   = is_array( $live['rules'] ?? null ) ? $live['rules'] : array();
		$system  = $this->systemName( $connection );

		try {
			$order  = $this->source->read( $item->entityType, $item->entityId, $this->fields->metaKeys( $rules ) );
			$reason = NothingToSend::reason( $item->entityType, $order );
			if ( '' !== $reason ) {
				return $this->failure(
					new ConnectorError(
						ErrorCode::Val002,
						array(
							'system' => $system,
							'field'  => __( 'Order lines', 'estasoft-enterprise-erpcrm-sync-engine' ),
							'reason' => $reason,
						)
					),
					$version
				);
			}
			if ( EntityType::Order === $item->entityType ) {
				$this->taxes->assertBuyerTaxNumber( $connection->id, $order );
			}
			$output = $this->engine->apply( $rules, $order, new MappingEnvironment( $connection->id, $system, $this->taxes, $this->products ) );
		} catch ( SourceNotFoundException $e ) {
			return $this->failure(
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => __( 'WooCommerce', 'estasoft-enterprise-erpcrm-sync-engine' ),
						'field'  => $item->entityType->value,
						'reason' => __( 'It no longer exists in WooCommerce.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					)
				),
				$version
			);
		} catch ( MappingException $e ) {
			return $this->failure( $e->toConnectorError(), $version );
		} catch ( LookupFailedException $e ) {
			return $this->failure( $e->error, $version );
		}

		$payload = $this->filtered( $output->payload, $item, $connection );
		return new PayloadResult( $payload, null, $version, $output->warningMessages() );
	}

	/**
	 * A stable hash of a payload, for skipping updates that change nothing.
	 *
	 * @param array<string,mixed> $payload Payload.
	 */
	public function hash( array $payload ): string {
		return hash( 'sha256', Json::canonical( $payload ) );
	}

	/**
	 * Mapping type used for an entity type, or null for types that are read from the connected system and never built here.
	 *
	 * @param EntityType $type Entity type.
	 */
	public static function mappingType( EntityType $type ): ?string {
		return match ( $type ) {
			EntityType::Order    => 'orders',
			EntityType::Refund   => 'refunds',
			EntityType::Customer => 'customers',
			default              => null,
		};
	}

	/**
	 * Run the payload through the add-on filter; a filter that returns anything but an array is ignored.
	 *
	 * @param array<string,mixed> $payload    Payload.
	 * @param Item                $item       Item.
	 * @param Connection          $connection Connection.
	 * @return array<string,mixed>
	 */
	private function filtered( array $payload, Item $item, Connection $connection ): array {
		/**
		 * Filter the mapped payload just before it is handed to the connector.
		 *
		 * @param array<string,mixed> $payload    Payload in the connector's target fields.
		 * @param Item                $item       The queue item.
		 * @param Connection          $connection The connection.
		 */
		$result = ( $this->filter )( self::FILTER, $payload, $item, $connection );
		if ( ! is_array( $result ) ) {
			$this->log->warning( __( 'A payload filter returned something other than an array and was ignored.', 'estasoft-enterprise-erpcrm-sync-engine' ), array( 'connection_id' => $connection->id ) );
			return $payload;
		}
		return $result;
	}

	/**
	 * The name shown in messages: the connector's name, or the connection's label.
	 *
	 * @param Connection $connection Connection.
	 */
	private function systemName( Connection $connection ): string {
		$connector = $this->connectors->get( $connection->connector );
		return null === $connector ? $connection->label : $connector->label();
	}

	/**
	 * A result that carries an error and no payload.
	 *
	 * @param ConnectorError $error   Error.
	 * @param int|null       $version Live version used, if any.
	 */
	private function failure( ConnectorError $error, ?int $version ): PayloadResult {
		return new PayloadResult( array(), $error, $version );
	}
}
