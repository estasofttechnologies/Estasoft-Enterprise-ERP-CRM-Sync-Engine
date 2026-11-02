<?php
/**
 * Where the item index is kept between requests.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

/**
 * A small key-value store for one value per connection. The engine uses a transient; tests use memory.
 */
interface WafeqIndexStore {

	/**
	 * The value kept for a connection.
	 *
	 * @param int $connectionId Connection.
	 * @return array<string,mixed>|null
	 */
	public function get( int $connectionId ): ?array;

	/**
	 * Keep a value.
	 *
	 * @param int                 $connectionId Connection.
	 * @param array<string,mixed> $value        Value.
	 * @param int                 $ttl          Seconds.
	 */
	public function put( int $connectionId, array $value, int $ttl ): void;

	/**
	 * Forget the value of a connection.
	 *
	 * @param int $connectionId Connection.
	 */
	public function forget( int $connectionId ): void;
}
