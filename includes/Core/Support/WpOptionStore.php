<?php
/**
 * Options in the WordPress options table.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Implements OptionStore with get_option, update_option and delete_option (autoload off by default).
 */
final class WpOptionStore implements OptionStore {

	/**
	 * Read an option.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $fallback Value when the option does not exist.
	 * @return mixed
	 */
	public function get( string $name, mixed $fallback = null ): mixed {
		return get_option( $name, $fallback );
	}

	/**
	 * Write an option.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Whether WordPress loads it on every request.
	 */
	public function set( string $name, mixed $value, bool $autoload = false ): void {
		update_option( $name, $value, $autoload );
	}

	/**
	 * Remove an option.
	 *
	 * @param string $name Option name.
	 */
	public function delete( string $name ): void {
		delete_option( $name );
	}
}
