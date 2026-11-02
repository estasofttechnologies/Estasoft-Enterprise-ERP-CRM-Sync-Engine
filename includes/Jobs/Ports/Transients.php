<?php
/**
 * Short-lived values.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * A narrow view of the WordPress transients API, so de-duplication, replay protection and caches can be tested.
 */
interface Transients {

	/**
	 * Read a value.
	 *
	 * @param string $key Name (the plugin prefix is part of it).
	 * @return mixed False when absent or expired.
	 */
	public function get( string $key ): mixed;

	/**
	 * Write a value.
	 *
	 * @param string $key     Name.
	 * @param mixed  $value   Value.
	 * @param int    $seconds Lifetime in seconds.
	 */
	public function set( string $key, mixed $value, int $seconds ): void;

	/**
	 * Add one to a counter and return the new count. The counter starts at one and lives for `$seconds` from its first count.
	 * Where the cache supports it the step is atomic (an external object cache), so two requests cannot both read the same
	 * count; without one it is a plain read and write.
	 *
	 * @param string $key     Name.
	 * @param int    $seconds Lifetime in seconds.
	 * @return int The count after adding one.
	 */
	public function increment( string $key, int $seconds ): int;

	/**
	 * Remove a value.
	 *
	 * @param string $key Name.
	 */
	public function delete( string $key ): void;
}
