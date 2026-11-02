<?php
/**
 * Key and value storage in WordPress transients.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Every key is prefixed so the Delete data tool, which removes options and transients that start with the plugin prefix,
 * finds them.
 */
final class TransientStore implements KeyValueStore {

	/**
	 * Prefix of every transient this store writes.
	 */
	public const PREFIX = 'estasoft_sync_';

	/**
	 * Read a value.
	 *
	 * @param string $key Key without the prefix.
	 * @return mixed The value, or null when missing or expired.
	 */
	public function get( string $key ): mixed {
		$value = get_transient( self::PREFIX . $key );
		return false === $value ? null : $value;
	}

	/**
	 * Store a value.
	 *
	 * @param string $key     Key without the prefix.
	 * @param mixed  $value   Value.
	 * @param int    $seconds Seconds to keep it.
	 */
	public function set( string $key, mixed $value, int $seconds ): void {
		set_transient( self::PREFIX . $key, $value, max( 1, $seconds ) );
	}

	/**
	 * Delete a value.
	 *
	 * @param string $key Key without the prefix.
	 */
	public function delete( string $key ): void {
		delete_transient( self::PREFIX . $key );
	}
}
