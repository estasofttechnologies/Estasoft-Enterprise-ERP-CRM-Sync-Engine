<?php
/**
 * An in-memory store for the Holded indexes and the call quota in tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Connectors\Holded\HoldedIndexStore;

/**
 * Keeps one value per connection and name like a transient, and counts the writes.
 */
final class FakeHoldedIndexStore implements HoldedIndexStore {

	/**
	 * Values by `name|connection`.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $values = array();

	/**
	 * Writes made.
	 */
	public int $writes = 0;

	/**
	 * The value kept for a connection and name.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $name         Name.
	 * @return array<string,mixed>|null
	 */
	public function get( int $connectionId, string $name ): ?array {
		return $this->values[ $name . '|' . $connectionId ] ?? null;
	}

	/**
	 * Keep a value.
	 *
	 * @param int                 $connectionId Connection.
	 * @param string              $name         Name.
	 * @param array<string,mixed> $value        Value.
	 * @param int                 $ttl          Seconds.
	 */
	public function put( int $connectionId, string $name, array $value, int $ttl ): void {
		unset( $ttl );
		$this->values[ $name . '|' . $connectionId ] = $value;
		++$this->writes;
	}

	/**
	 * Forget a value.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $name         Name.
	 */
	public function forget( int $connectionId, string $name ): void {
		unset( $this->values[ $name . '|' . $connectionId ] );
	}
}
