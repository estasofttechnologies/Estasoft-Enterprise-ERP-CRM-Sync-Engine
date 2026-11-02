<?php
/**
 * A queue gateway that records what it is asked and answers from tables set by the test.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Woo\Ports\QueueGateway;

class WooRecordingQueue implements QueueGateway {

	/**
	 * Calls made, as strings such as "enqueue:order:10490:created".
	 *
	 * @var array<int,string>
	 */
	public array $calls = array();

	/**
	 * Meta passed to enqueue(), by call number.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $metas = array();

	/**
	 * Items by queue id (QueueItem shape plus mapping_version).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $items = array();

	/**
	 * The id the next enqueue() returns; null makes it return null (nothing queued).
	 */
	public ?int $nextId = 100;

	/**
	 * Throw from enqueue() to test that hooks never let an exception out.
	 */
	public ?\Throwable $throw = null;

	/**
	 * Result of retry, skip, restore.
	 */
	public bool $actionResult = true;

	public function enqueue( EntityType $type, string $entityId, string $event, array $meta = array() ): ?int {
		if ( null !== $this->throw ) {
			throw $this->throw;
		}
		$this->calls[] = 'enqueue:' . $type->value . ':' . $entityId . ':' . $event;
		$this->metas[] = $meta;
		return $this->nextId;
	}

	public function syncAgain( int $orderId ): ?int {
		$this->calls[] = 'syncAgain:' . $orderId;
		return $this->nextId;
	}

	/**
	 * How many times the newest item of an order was asked for.
	 */
	public int $lookups = 0;

	public function latestForOrder( int $orderId ): ?array {
		++$this->lookups;
		$found = null;
		foreach ( $this->items as $item ) {
			if ( (string) $orderId === (string) $item['entity_id'] && 'order' === $item['entity_type'] ) {
				$found = $item;
			}
		}
		return $found;
	}

	public function item( int $itemId ): ?array {
		return $this->items[ $itemId ] ?? null;
	}

	public function retry( int $itemId ): bool {
		$this->calls[] = 'retry:' . $itemId;
		return $this->actionResult;
	}

	public function skip( int $itemId, string $reason ): bool {
		$this->calls[] = 'skip:' . $itemId . ':' . $reason;
		return $this->actionResult;
	}

	public function restore( int $itemId ): bool {
		$this->calls[] = 'restore:' . $itemId;
		return $this->actionResult;
	}

	/**
	 * Add an item in the shape of QueueRepository::findRow().
	 *
	 * @param int                 $id      Queue id.
	 * @param int                 $orderId Order id.
	 * @param string              $state   State.
	 * @param array<string,mixed> $more    Other fields.
	 */
	public function addOrderItem( int $id, int $orderId, string $state, array $more = array() ): void {
		$this->items[ $id ] = array_merge(
			array(
				'id'              => $id,
				'connection_id'   => 1,
				'entity_type'     => 'order',
				'entity_id'       => (string) $orderId,
				'event'           => 'created',
				'state'           => $state,
				'mode'            => 'live',
				'attempt'         => 0,
				'max_attempts'    => 5,
				'next_attempt_at' => null,
				'error_code'      => null,
				'error_message'   => null,
				'external_id'     => null,
				'external_ref'    => null,
				'external_url'    => null,
				'skip_reason'     => null,
				'created_at'      => '2026-11-02T11:40:00Z',
				'updated_at'      => '2026-11-02T11:40:00Z',
				'finished_at'     => null,
				'mapping_version' => null,
			),
			$more
		);
	}
}
