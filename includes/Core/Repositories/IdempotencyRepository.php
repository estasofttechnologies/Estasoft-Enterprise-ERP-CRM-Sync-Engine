<?php
/**
 * Idempotency keys that prevent duplicates.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Domain\EntityType;

/**
 * Reads and writes `estasoft_sync_idempotency`. A key is reserved once. The row survives queue clean-up and
 * is deleted after 90 days.
 */
final class IdempotencyRepository extends Repository {

	/**
	 * Days a key is kept.
	 */
	public const KEEP_DAYS = 90;

	/**
	 * Reserve a key.
	 *
	 * @param string     $key          sha256 hex.
	 * @param int        $connectionId Connection.
	 * @param EntityType $type         Entity type.
	 * @param string     $entityId     WooCommerce id.
	 * @param string     $event        Event.
	 * @param int        $ordinal      Ordinal that went into the key.
	 * @return bool True when the key was new; false when it already existed.
	 */
	public function reserve( string $key, int $connectionId, EntityType $type, string $entityId, string $event, int $ordinal ): bool {
		return $this->run(
			"INSERT IGNORE INTO {$this->tables->idempotency()} (idempotency_key, connection_id, entity_type, entity_id, event, ordinal, result, created_at)
			VALUES (%s, %d, %s, %s, %s, %d, %s, %s)",
			array( $key, $connectionId, $type->value, mb_substr( $entityId, 0, 64 ), mb_substr( $event, 0, 32 ), $ordinal, 'pending', $this->now() )
		) > 0;
	}

	/**
	 * Remember which queue item belongs to a key.
	 *
	 * @param string $key     Key.
	 * @param int    $queueId Queue item id.
	 */
	public function attach( string $key, int $queueId ): void {
		$this->run( "UPDATE {$this->tables->idempotency()} SET queue_id = %d WHERE idempotency_key = %s", array( $queueId, $key ) );
	}

	/**
	 * Release a reservation whose queue row could not be written, so a later attempt can reserve it again.
	 *
	 * @param string $key Key.
	 */
	public function release( string $key ): void {
		$this->run( "DELETE FROM {$this->tables->idempotency()} WHERE idempotency_key = %s AND queue_id IS NULL", array( $key ) );
	}

	/**
	 * The queue item recorded for a key.
	 *
	 * @param string $key Key.
	 * @return int|null
	 */
	public function queueIdFor( string $key ): ?int {
		$id = $this->value( "SELECT queue_id FROM {$this->tables->idempotency()} WHERE idempotency_key = %s", array( $key ) );
		return null === $id ? null : (int) $id;
	}

	/**
	 * Whether a key exists.
	 *
	 * @param string $key Key.
	 */
	public function exists( string $key ): bool {
		return null !== $this->value( "SELECT id FROM {$this->tables->idempotency()} WHERE idempotency_key = %s", array( $key ) );
	}

	/**
	 * Mark the work behind a key as done (the system accepted it).
	 *
	 * @param string      $key        Key.
	 * @param string|null $externalId The system's id.
	 */
	public function markDone( string $key, ?string $externalId = null ): void {
		$args = array( 'done' );
		$ext  = $this->bind( $externalId, '%s', $args );
		array_push( $args, $this->now(), $key );
		$this->run(
			"UPDATE {$this->tables->idempotency()} SET result = %s, external_id = $ext, completed_at = %s WHERE idempotency_key = %s",
			$args
		);
	}

	/**
	 * The result stored for a key.
	 *
	 * @param string $key Key.
	 * @return array{result:string,external_id:?string}|null
	 */
	public function get( string $key ): ?array {
		$row = $this->row( "SELECT result, external_id FROM {$this->tables->idempotency()} WHERE idempotency_key = %s", array( $key ) );
		if ( null === $row ) {
			return null;
		}
		return array(
			'result'      => (string) $row['result'],
			'external_id' => self::stringOrNull( $row['external_id'] ),
		);
	}

	/**
	 * How many keys exist for an entity and event: the next ordinal for events that can repeat.
	 *
	 * @param int        $connectionId Connection.
	 * @param EntityType $type         Entity type.
	 * @param string     $entityId     WooCommerce id.
	 * @param string     $event        Event.
	 */
	public function countFor( int $connectionId, EntityType $type, string $entityId, string $event ): int {
		return (int) $this->value(
			"SELECT COUNT(*) FROM {$this->tables->idempotency()} WHERE connection_id = %d AND entity_type = %s AND entity_id = %s AND event = %s",
			array( $connectionId, $type->value, $entityId, $event )
		);
	}

	/**
	 * Delete keys older than the retention time.
	 *
	 * @param int $days Keep this many days.
	 * @return int Rows deleted.
	 */
	public function prune( int $days = self::KEEP_DAYS ): int {
		$cutoff = Time::sql( $this->clock->now()->modify( sprintf( '-%d days', max( 1, $days ) ) ) );
		$total  = 0;
		for ( $i = 0; $i < 200; ++$i ) {
			$deleted = $this->run( "DELETE FROM {$this->tables->idempotency()} WHERE created_at < %s LIMIT %d", array( $cutoff, 2000 ) );
			$total  += $deleted;
			if ( $deleted < 2000 ) {
				break;
			}
		}
		return $total;
	}
}
