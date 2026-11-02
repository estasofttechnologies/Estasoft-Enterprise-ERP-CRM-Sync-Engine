<?php
/**
 * Short-lived key and value storage.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Support;

defined( 'ABSPATH' ) || exit;

/**
 * What the idempotency cache and the sign-in state need: put a value for a number of seconds, read it, delete it.
 * WordPress transients are the real implementation; tests use an array.
 */
interface KeyValueStore {

	/**
	 * Read a value.
	 *
	 * @param string $key Key.
	 * @return mixed The value, or null when missing or expired.
	 */
	public function get( string $key ): mixed;

	/**
	 * Store a value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $value   Value; arrays and scalars only.
	 * @param int    $seconds Seconds to keep it.
	 */
	public function set( string $key, mixed $value, int $seconds ): void;

	/**
	 * Delete a value.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void;
}
