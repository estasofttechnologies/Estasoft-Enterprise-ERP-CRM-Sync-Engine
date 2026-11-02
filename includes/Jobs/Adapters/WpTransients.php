<?php
/**
 * Transients over the WordPress API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Adapters;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Ports\Transients;

/**
 * `get_transient()`, `set_transient()` and `delete_transient()`. Names start with the plugin prefix so uninstall removes them.
 */
final class WpTransients implements Transients {

	/**
	 * Read a value.
	 *
	 * @param string $key Name.
	 * @return mixed False when absent or expired.
	 */
	public function get( string $key ): mixed {
		return get_transient( $key );
	}

	/**
	 * Write a value.
	 *
	 * @param string $key     Name.
	 * @param mixed  $value   Value.
	 * @param int    $seconds Lifetime in seconds.
	 */
	public function set( string $key, mixed $value, int $seconds ): void {
		set_transient( $key, $value, max( 1, $seconds ) );
	}

	/**
	 * Remove a value.
	 *
	 * @param string $key Name.
	 */
	public function delete( string $key ): void {
		delete_transient( $key );
	}
}
