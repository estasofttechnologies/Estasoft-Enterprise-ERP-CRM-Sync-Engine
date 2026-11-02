<?php
/**
 * The queue, as the WooCommerce screens and hooks see it.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo\Ports;

use Estasoft\SyncEngine\Domain\EntityType;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented over Core\Queue\Enqueuer and Core\Repositories\QueueRepository. Items are arrays shaped like QueueItem in src/api/types.ts.
 */
interface QueueGateway {

	/**
	 * Add an item. Never throws; failures are logged by the implementation.
	 *
	 * @param EntityType          $type     Entity type.
	 * @param string              $entityId WooCommerce id.
	 * @param string              $event    created, paid, updated, refunded, cancelled.
	 * @param array<string,mixed> $meta     Optional values for the queue: partition (ordering key; a refund passes order:{order id} so it waits behind its order), label, customer, total_minor, currency, search.
	 * @return int|null Queue id, or null when nothing was queued (no active connection, filtered out, or an error).
	 */
	public function enqueue( EntityType $type, string $entityId, string $event, array $meta = array() ): ?int;

	/**
	 * Send an order again with its latest data (the person asked for it).
	 *
	 * @param int $orderId WooCommerce order id.
	 * @return int|null Queue id, or null when nothing was queued.
	 */
	public function syncAgain( int $orderId ): ?int;

	/**
	 * The newest queue item of an order.
	 *
	 * @param int $orderId WooCommerce order id.
	 * @return array<string,mixed>|null
	 */
	public function latestForOrder( int $orderId ): ?array;

	/**
	 * One queue item.
	 *
	 * @param int $itemId Queue id.
	 * @return array<string,mixed>|null
	 */
	public function item( int $itemId ): ?array;

	/**
	 * Put a failed or retrying item back in the queue now.
	 *
	 * @param int $itemId Queue id.
	 */
	public function retry( int $itemId ): bool;

	/**
	 * Leave an item out of syncing.
	 *
	 * @param int    $itemId Queue id.
	 * @param string $reason Why.
	 */
	public function skip( int $itemId, string $reason ): bool;

	/**
	 * Bring a skipped item back.
	 *
	 * @param int $itemId Queue id.
	 */
	public function restore( int $itemId ): bool;
}
