<?php
/**
 * An in-memory store for the Wafeq item index in tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Connectors\Wafeq\WafeqIndexStore;

/**
 * Keeps one value per connection like a transient, and counts what was written so a test can see that the index survives a new connector.
 */
final class FakeWafeqIndexStore implements WafeqIndexStore {

	/**
	 * Values by connection id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $values = array();

	/**
	 * Writes made.
	 */
	public int $writes = 0;

	/**
	 * The value kept for a connection.
	 *
	 * @param int $connectionId Connection.
	 * @return array<string,mixed>|null
	 */
	public function get( int $connectionId ): ?array {
		return $this->values[ $connectionId ] ?? null;
	}

	/**
	 * Keep a value.
	 *
	 * @param int                 $connectionId Connection.
	 * @param array<string,mixed> $value        Value.
	 * @param int                 $ttl          Seconds.
	 */
	public function put( int $connectionId, array $value, int $ttl ): void {
		unset( $ttl );
		$this->values[ $connectionId ] = $value;
		++$this->writes;
	}

	/**
	 * Forget the value of a connection.
	 *
	 * @param int $connectionId Connection.
	 */
	public function forget( int $connectionId ): void {
		unset( $this->values[ $connectionId ] );
	}
}
