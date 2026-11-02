<?php
/**
 * Where the Holded indexes and the call quota are kept between requests.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

/**
 * A small key-value store, one value per connection and name (`contacts`, `products`, `quota`). The engine uses a transient; tests use memory.
 */
interface HoldedIndexStore {

	/**
	 * The value kept for a connection and name.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $name         Name.
	 * @return array<string,mixed>|null
	 */
	public function get( int $connectionId, string $name ): ?array;

	/**
	 * Keep a value.
	 *
	 * @param int                 $connectionId Connection.
	 * @param string              $name         Name.
	 * @param array<string,mixed> $value        Value.
	 * @param int                 $ttl          Seconds.
	 */
	public function put( int $connectionId, string $name, array $value, int $ttl ): void;

	/**
	 * Forget a value.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $name         Name.
	 */
	public function forget( int $connectionId, string $name ): void;
}
