<?php
/**
 * A queue item (the outbox and the queue are one table).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable view of a row in {prefix}estasoft_sync_queue.
 */
final class Item {

	/**
	 * @param int         $id             Row id.
	 * @param int         $connectionId   Owning connection.
	 * @param EntityType  $entityType     What the item is about.
	 * @param string      $entityId       WooCommerce id.
	 * @param string      $event          created, paid, updated, refunded, cancelled, delivered.
	 * @param string      $partitionKey   For example order:10490.
	 * @param string      $idempotencyKey sha256 hex (see IdempotencyKey).
	 * @param ItemState   $state          Current state.
	 * @param ItemMode    $mode           live or shadow.
	 * @param int         $attempt        Tries made so far.
	 * @param int         $maxAttempts    Tries allowed.
	 * @param int|null    $mappingVersion Mapping version used at the last send.
	 * @param string|null $errorCode      SYNC-... code of the last error.
	 * @param string|null $externalId     The system's id once synced.
	 * @param string|null $externalRef    The system's human number once synced.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $connectionId,
		public readonly EntityType $entityType,
		public readonly string $entityId,
		public readonly string $event,
		public readonly string $partitionKey,
		public readonly string $idempotencyKey,
		public readonly ItemState $state,
		public readonly ItemMode $mode = ItemMode::Live,
		public readonly int $attempt = 0,
		public readonly int $maxAttempts = 5,
		public readonly ?int $mappingVersion = null,
		public readonly ?string $errorCode = null,
		public readonly ?string $externalId = null,
		public readonly ?string $externalRef = null,
	) {
	}

	/**
	 * The client reference written into the system so a retry can find an existing record (QUE-5).
	 */
	public function clientReference(): string {
		return 'WC-' . $this->entityId;
	}
}
