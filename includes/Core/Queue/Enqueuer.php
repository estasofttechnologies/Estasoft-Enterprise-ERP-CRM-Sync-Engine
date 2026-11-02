<?php
/**
 * Adds events to the queue. The only thing that runs in a customer's request.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\ConnectionSettings;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\IdempotencyRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ItemMode;

/**
 * Writes one row to the queue table and returns. No network call, no payload built, nothing slow: it is designed
 * to run inside the order's own database transaction, so the queue row commits or rolls back with the order. It
 * never throws; if anything goes wrong it records the problem in the log and returns null, so checkout is never blocked.
 *
 * A second call for the same event of the same object is a no-op (same idempotency key). An event that can repeat
 * (`updated`) joins an item that is still waiting instead of adding another one, because the payload is built at send
 * time from the latest data. While the setup is in its shadow window, new items are marked shadow.
 */
final class Enqueuer {

	/**
	 * @param ConnectionRepository  $connections Connections.
	 * @param QueueRepository       $queue       Queue.
	 * @param IdempotencyRepository $idempotency Idempotency ledger.
	 * @param OnboardingState       $onboarding  Shadow window.
	 * @param Logger                $logger      Log.
	 * @param Clock                 $clock       Time source.
	 * @param \Closure():ConnectorRegistry $connectors Returns the connector registry.
	 */
	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly QueueRepository $queue,
		private readonly IdempotencyRepository $idempotency,
		private readonly OnboardingState $onboarding,
		private readonly Logger $logger,
		private readonly Clock $clock,
		private readonly \Closure $connectors
	) {
	}

	/**
	 * Add an event to the queue.
	 *
	 * Optional `$meta` keys: `partition` (ordering key; default `order:{id}` for orders, tracking and invoices, otherwise
	 * `{type}:{id}`; a refund should pass `order:{parent order id}`), `label` (order number for the lists), `customer`,
	 * `total_minor`, `currency`, `search` (text or list of SKUs and emails to search by). The filter
	 * `estasoft_sync_queue_display` can supply the same values.
	 *
	 * @param EntityType          $type         What the event is about.
	 * @param string              $entityId     WooCommerce id (the SKU for stock).
	 * @param string              $event        created, paid, updated, refunded, cancelled, delivered.
	 * @param int|null            $connectionId Connection; null uses the first connection that is not disconnected and can send this entity.
	 * @param array<string,mixed> $meta         Optional display and ordering values.
	 * @return int|null The queue item id (the existing one for a repeated event); null when nothing was queued.
	 */
	public function enqueue( EntityType $type, string $entityId, string $event, ?int $connectionId = null, array $meta = array() ): ?int {
		try {
			return $this->doEnqueue( $type, $entityId, $event, $connectionId, $meta );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				sprintf(
					/* translators: %s: PHP error class name. */
					__( 'An event could not be added to the queue (%s).', 'estasoft-erp-crm-sync-engine' ),
					get_class( $e )
				),
				array(
					'entity_ref' => $type->value . ':' . $entityId,
				)
			);
			return null;
		}
	}

	/**
	 * Do the work.
	 *
	 * @param EntityType          $type         Entity type.
	 * @param string              $entityId     Id.
	 * @param string              $event        Event.
	 * @param int|null            $connectionId Connection.
	 * @param array<string,mixed> $meta         Optional values.
	 */
	private function doEnqueue( EntityType $type, string $entityId, string $event, ?int $connectionId, array $meta ): ?int {
		$connection = $this->resolve( $type, $connectionId );
		if ( null === $connection ) {
			return null;
		}

		/**
		 * Filter whether an event is queued.
		 *
		 * @param bool       $enqueue      True to queue it.
		 * @param EntityType $type         Entity type.
		 * @param string     $entityId     Id.
		 * @param string     $event        Event.
		 * @param int        $connectionId Connection id.
		 */
		if ( ! apply_filters( 'estasoft_sync_should_enqueue', true, $type, $entityId, $event, $connection->id ) ) {
			return null;
		}

		$repeatable = IdempotencyKey::isRepeatable( $event );
		if ( $repeatable ) {
			$waiting = $this->queue->findUnfinished( $connection->id, $type, $entityId, $event );
			if ( null !== $waiting ) {
				$this->queue->noteChangedWhileWaiting( $waiting );
				return $waiting;
			}
		}

		$ordinal = $repeatable ? $this->idempotency->countFor( $connection->id, $type, $entityId, $event ) : 0;
		$key     = IdempotencyKey::make( $connection->id, $type, $entityId, $event, $ordinal );
		if ( ! $this->idempotency->reserve( $key, $connection->id, $type, $entityId, $event, $ordinal ) ) {
			// Seen before. Return the existing item, or null when it was cleaned up long ago (the key remembers, the row is gone).
			$existing = $this->idempotency->queueIdFor( $key );
			return ( null !== $existing && null !== $this->queue->find( $existing ) ) ? $existing : null;
		}

		$mode    = $this->onboarding->isShadowActive( $this->clock->now() ) ? ItemMode::Shadow : ItemMode::Live;
		$display = $this->display( $type, $entityId, $event, $meta );
		$id      = $this->queue->enqueue(
			$connection->id,
			$type,
			$entityId,
			$event,
			$this->partition( $type, $entityId, $meta ),
			$key,
			$mode,
			count( (array) ( $connection->settings['retry_delays_seconds'] ?? ConnectionSettings::DEFAULT_DELAYS ) ) + 1,
			$display
		);
		if ( $id <= 0 ) {
			$this->idempotency->release( $key );
			$this->logger->error(
				__( 'An event could not be added to the queue.', 'estasoft-erp-crm-sync-engine' ),
				array(
					'connection_id' => $connection->id,
					'entity_ref'    => $type->value . ':' . $entityId,
				)
			);
			return null;
		}
		$this->idempotency->attach( $key, $id );
		return $id;
	}

	/**
	 * The connection an event goes to.
	 *
	 * @param EntityType $type         Entity type.
	 * @param int|null   $connectionId Requested connection, or null.
	 */
	private function resolve( EntityType $type, ?int $connectionId ): ?Connection {
		if ( null !== $connectionId ) {
			$connection = $this->connections->findWithoutSecrets( $connectionId );
			return ( null !== $connection && ConnectionStatus::Disconnected !== $connection->status ) ? $connection : null;
		}
		$registry = ( $this->connectors )();
		foreach ( $this->connections->allWithoutSecrets() as $connection ) {
			if ( ConnectionStatus::Disconnected === $connection->status ) {
				continue;
			}
			$connector = $registry->get( $connection->connector );
			if ( null === $connector || in_array( $type, $connector->supportedEntities(), true ) ) {
				return $connection;
			}
		}
		return null;
	}

	/**
	 * The ordering key.
	 *
	 * @param EntityType          $type     Entity type.
	 * @param string              $entityId Id.
	 * @param array<string,mixed> $meta     Optional values.
	 */
	private function partition( EntityType $type, string $entityId, array $meta ): string {
		if ( isset( $meta['partition'] ) && '' !== (string) $meta['partition'] ) {
			return (string) $meta['partition'];
		}
		return match ( $type ) {
			EntityType::Order, EntityType::Tracking, EntityType::Invoice => 'order:' . $entityId,
			default => $type->value . ':' . $entityId,
		};
	}

	/**
	 * The display values for the lists.
	 *
	 * @param EntityType          $type     Entity type.
	 * @param string              $entityId Id.
	 * @param string              $event    Event.
	 * @param array<string,mixed> $meta     Values from the caller.
	 * @return array<string,mixed>
	 */
	private function display( EntityType $type, string $entityId, string $event, array $meta ): array {
		$display = array_intersect_key( $meta, array_flip( array( 'label', 'customer', 'total_minor', 'currency', 'search' ) ) );
		/**
		 * Filter the values shown for a queue item in the lists.
		 *
		 * @param array<string,mixed> $display  label, customer, total_minor, currency, search.
		 * @param EntityType          $type     Entity type.
		 * @param string              $entityId Id.
		 * @param string              $event    Event.
		 */
		$filtered = apply_filters( 'estasoft_sync_queue_display', $display, $type, $entityId, $event );
		return is_array( $filtered ) ? $filtered : $display;
	}
}
