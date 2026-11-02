<?php
/**
 * An in-memory KeyValueStore for tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Rest\Support\KeyValueStore;

/**
 * Keeps values in an array and remembers the lifetime asked for, so a test can check it and can expire a value.
 */
final class RestArrayStore implements KeyValueStore {

	/**
	 * Values by key.
	 *
	 * @var array<string,mixed>
	 */
	public array $values = array();

	/**
	 * Seconds asked for, by key.
	 *
	 * @var array<string,int>
	 */
	public array $ttl = array();

	/**
	 * Read a value.
	 *
	 * @param string $key Key.
	 */
	public function get( string $key ): mixed {
		return $this->values[ $key ] ?? null;
	}

	/**
	 * Store a value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $value   Value.
	 * @param int    $seconds Seconds.
	 */
	public function set( string $key, mixed $value, int $seconds ): void {
		$this->values[ $key ] = $value;
		$this->ttl[ $key ]    = $seconds;
	}

	/**
	 * Delete a value.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void {
		unset( $this->values[ $key ], $this->ttl[ $key ] );
	}

	/**
	 * Forget every value, as if their time had run out.
	 */
	public function expireAll(): void {
		$this->values = array();
		$this->ttl    = array();
	}
}
