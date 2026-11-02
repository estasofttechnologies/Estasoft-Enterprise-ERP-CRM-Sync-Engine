<?php
/**
 * Queue gateway backed by the core queue classes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo\Adapters;

use Estasoft\SyncEngine\Core\Queue\Enqueuer;
use Estasoft\SyncEngine\Core\Queue\QueueService;
use Estasoft\SyncEngine\Core\Repositories\QueueQuery;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Repositories\StateRepository;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Woo\Ports\QueueGateway;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueuer never throws and is meant to run inside the order's transaction; QueueService writes the audit log entry and wakes the
 * worker; the repositories answer the reads. The core services are fetched only when a call needs them, so a request that never touches
 * the queue (most front-end page views) builds none of them.
 */
final class CoreQueueGateway implements QueueGateway {

	/**
	 * Most items read when looking for the newest item of one order (the fallback search, and the list of connections).
	 */
	private const LOOKUP_LIMIT = 100;

	/**
	 * Keep the lookup of core services.
	 *
	 * @param \Closure $services Returns a core service by its container key (Core\Services constants).
	 */
	public function __construct( private readonly \Closure $services ) {
	}

	/**
	 * Add an item.
	 *
	 * @param EntityType          $type     Entity type.
	 * @param string              $entityId WooCommerce id.
	 * @param string              $event    Event.
	 * @param array<string,mixed> $meta     Partition and display values.
	 * @return int|null
	 */
	public function enqueue( EntityType $type, string $entityId, string $event, array $meta = array() ): ?int {
		return $this->enqueuer()->enqueue( $type, $entityId, $event, null, $meta );
	}

	/**
	 * Send an order again with its latest data: forget the last-synced hash on every connection that has the order, so the send is not
	 * skipped as "nothing changed", then queue an update.
	 *
	 * @param int $orderId Order id.
	 * @return int|null
	 */
	public function syncAgain( int $orderId ): ?int {
		$latest = $this->latestForOrder( $orderId );
		foreach ( $this->connectionIds( $orderId, $latest ) as $connectionId ) {
			$this->state()->forget( $connectionId, EntityType::Order, (string) $orderId );
		}
		return $this->enqueuer()->enqueue( EntityType::Order, (string) $orderId, 'updated', null !== $latest ? (int) $latest['connection_id'] : null );
	}

	/**
	 * The newest queue item of an order.
	 *
	 * @param int $orderId Order id.
	 * @return array<string,mixed>|null
	 */
	public function latestForOrder( int $orderId ): ?array {
		return $this->latestForEntity( EntityType::Order, (string) $orderId );
	}

	/**
	 * The newest queue item of one WooCommerce object. Core answers this from the entity index (QueueRepository::latestForEntity);
	 * where that method is not there yet, the queue is searched and the result checked for an exact match on type and id.
	 *
	 * @param EntityType $type     Entity type.
	 * @param string     $entityId WooCommerce id.
	 * @return array<string,mixed>|null A row in the QueueItem shape of types.ts.
	 */
	private function latestForEntity( EntityType $type, string $entityId ): ?array {
		$queue = $this->queue();
		if ( is_callable( array( $queue, 'latestForEntity' ) ) ) {
			$row = call_user_func( array( $queue, 'latestForEntity' ), $type, $entityId );
			return is_array( $row ) ? $row : null;
		}
		$page = $queue->query( new QueueQuery( 'all', $type->value, null, $entityId, null, null, null, 1, self::LOOKUP_LIMIT ) );
		foreach ( $page->items as $row ) {
			if ( is_array( $row ) && (string) ( $row['entity_id'] ?? '' ) === $entityId && (string) ( $row['entity_type'] ?? '' ) === $type->value ) {
				return $this->queue()->findRow( (int) $row['id'] );
			}
		}
		return null;
	}

	/**
	 * Connections that hold a queue item for the order: the latest item's, plus the others when Core can list them.
	 *
	 * @param int                      $orderId Order id.
	 * @param array<string,mixed>|null $latest  The newest item.
	 * @return array<int,int>
	 */
	private function connectionIds( int $orderId, ?array $latest ): array {
		$ids   = null === $latest ? array() : array( (int) $latest['connection_id'] );
		$queue = $this->queue();
		if ( is_callable( array( $queue, 'forEntity' ) ) ) {
			$rows = call_user_func( array( $queue, 'forEntity' ), EntityType::Order, (string) $orderId, self::LOOKUP_LIMIT );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( is_array( $row ) && isset( $row['connection_id'] ) ) {
					$ids[] = (int) $row['connection_id'];
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * One queue item.
	 *
	 * @param int $itemId Queue id.
	 * @return array<string,mixed>|null
	 */
	public function item( int $itemId ): ?array {
		return $this->queue()->findRow( $itemId );
	}

	/**
	 * Retry an item.
	 *
	 * @param int $itemId Queue id.
	 */
	public function retry( int $itemId ): bool {
		return null !== $this->service()->retry( $itemId );
	}

	/**
	 * Skip an item.
	 *
	 * @param int    $itemId Queue id.
	 * @param string $reason Why.
	 */
	public function skip( int $itemId, string $reason ): bool {
		return null !== $this->service()->skip( $itemId, $reason );
	}

	/**
	 * Restore an item.
	 *
	 * @param int $itemId Queue id.
	 */
	public function restore( int $itemId ): bool {
		return null !== $this->service()->restore( $itemId );
	}

	/**
	 * The enqueuer.
	 */
	private function enqueuer(): Enqueuer {
		return ( $this->services )( Services::ENQUEUER );
	}

	/**
	 * The queue repository.
	 */
	private function queue(): QueueRepository {
		return ( $this->services )( Services::QUEUE_REPOSITORY );
	}

	/**
	 * The queue service.
	 */
	private function service(): QueueService {
		return ( $this->services )( Services::QUEUE_SERVICE );
	}

	/**
	 * The state repository.
	 */
	private function state(): StateRepository {
		return ( $this->services )( Services::STATE_REPOSITORY );
	}
}
