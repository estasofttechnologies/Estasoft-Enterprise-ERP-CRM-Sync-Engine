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
	 * Remove a value.
	 *
	 * @param string $key Name.
	 */
	public function delete( string $key ): void;
}
