<?php
/**
 * Last synced data hash per object.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Json;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Domain\EntityType;

/**
 * Reads and writes `estasoft_sync_state`. The hash lets the queue skip an update that would change nothing.
 * The `extra` array holds small facts that belong to the object, such as the invoice number and tracking.
 */
final class StateRepository extends Repository {

	/**
	 * The state of an object.
	 *
	 * @param int               $connectionId Connection.
	 * @param EntityType|string $type         Entity type.
	 * @param string            $wcId         WooCommerce id.
	 * @return array{data_hash:string,mapping_version:?int,synced_at:?string,extra:array<string,mixed>}|null
	 */
	public function get( int $connectionId, EntityType|string $type, string $wcId ): ?array {
		$row = $this->row(
			"SELECT * FROM {$this->tables->state()} WHERE connection_id = %d AND entity_type = %s AND wc_id = %s",
			array( $connectionId, self::typeValue( $type ), $wcId )
		);
		if ( null === $row ) {
			return null;
		}
		return array(
			'data_hash'       => (string) $row['data_hash'],
			'mapping_version' => self::intOrNull( $row['mapping_version'] ),
			'synced_at'       => Time::iso( self::stringOrNull( $row['synced_at'] ) ),
			'extra'           => Json::decode( (string) $row['extra_json'] ),
		);
	}

	/**
	 * Whether the stored hash equals a new one.
	 *
	 * @param int               $connectionId Connection.
	 * @param EntityType|string $type         Entity type.
	 * @param string            $wcId         WooCommerce id.
	 * @param string            $hash         New hash.
	 */
	public function isUnchanged( int $connectionId, EntityType|string $type, string $wcId, string $hash ): bool {
		$state = $this->get( $connectionId, $type, $wcId );
		return null !== $state && '' !== $state['data_hash'] && hash_equals( $state['data_hash'], $hash );
	}

	/**
	 * Remember what was synced. The extra facts already stored are kept unless the same key is given.
	 *
	 * @param int                 $connectionId   Connection.
	 * @param EntityType|string   $type           Entity type.
	 * @param string              $wcId           WooCommerce id.
	 * @param string              $hash           Hash of the payload that was sent.
	 * @param int|null            $mappingVersion Mapping version used.
	 * @param array<string,mixed> $extra          Facts to merge into the stored extra.
	 */
	public function save( int $connectionId, EntityType|string $type, string $wcId, string $hash, ?int $mappingVersion = null, array $extra = array() ): void {
		$existing = $this->get( $connectionId, $type, $wcId );
		$merged   = array_merge( $existing['extra'] ?? array(), $extra );
		$now      = $this->now();
		$this->run(
			"INSERT INTO {$this->tables->state()} (connection_id, entity_type, wc_id, data_hash, mapping_version, synced_at, extra_json, updated_at)
			VALUES (%d, %s, %s, %s, %d, %s, %s, %s)
			ON DUPLICATE KEY UPDATE data_hash = VALUES(data_hash), mapping_version = VALUES(mapping_version), synced_at = VALUES(synced_at), extra_json = VALUES(extra_json), updated_at = VALUES(updated_at)",
			array( $connectionId, self::typeValue( $type ), $wcId, $hash, (int) $mappingVersion, $now, Json::encode( $merged ), $now )
		);
	}

	/**
	 * Merge facts into the extra without touching the hash (invoice and tracking read-back).
	 *
	 * @param int                 $connectionId Connection.
	 * @param EntityType|string   $type         Entity type.
	 * @param string              $wcId         WooCommerce id.
	 * @param array<string,mixed> $extra        Facts to merge.
	 */
	public function mergeExtra( int $connectionId, EntityType|string $type, string $wcId, array $extra ): void {
		$existing = $this->get( $connectionId, $type, $wcId );
		$hash     = $existing['data_hash'] ?? '';
		$merged   = array_merge( $existing['extra'] ?? array(), $extra );
		$now      = $this->now();
		$this->run(
			"INSERT INTO {$this->tables->state()} (connection_id, entity_type, wc_id, data_hash, extra_json, updated_at)
			VALUES (%d, %s, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE extra_json = VALUES(extra_json), updated_at = VALUES(updated_at)",
			array( $connectionId, self::typeValue( $type ), $wcId, $hash, Json::encode( $merged ), $now )
		);
	}

	/**
	 * Forget an object (the next sync will send in full).
	 *
	 * @param int               $connectionId Connection.
	 * @param EntityType|string $type         Entity type.
	 * @param string            $wcId         WooCommerce id.
	 */
	public function forget( int $connectionId, EntityType|string $type, string $wcId ): void {
		$this->run(
			"DELETE FROM {$this->tables->state()} WHERE connection_id = %d AND entity_type = %s AND wc_id = %s",
			array( $connectionId, self::typeValue( $type ), $wcId )
		);
	}

	/**
	 * The string stored for a type.
	 *
	 * @param EntityType|string $type Entity type or string.
	 */
	private static function typeValue( EntityType|string $type ): string {
		return $type instanceof EntityType ? $type->value : $type;
	}
}
