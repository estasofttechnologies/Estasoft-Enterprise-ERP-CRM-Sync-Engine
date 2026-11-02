<?php
/**
 * The Holded indexes in WordPress transients.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

/**
 * One transient per connection and name, `estasoft_sync_holded_{name}_{id}`. A lost transient only costs one more read of the list.
 */
final class HoldedTransientStore implements HoldedIndexStore {

	/**
	 * The value kept for a connection and name.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $name         Name.
	 * @return array<string,mixed>|null
	 */
	public function get( int $connectionId, string $name ): ?array {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}
		$value = get_transient( $this->key( $connectionId, $name ) );
		return is_array( $value ) ? $value : null;
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
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $this->key( $connectionId, $name ), $value, max( 60, $ttl ) );
		}
	}

	/**
	 * Forget a value.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $name         Name.
	 */
	public function forget( int $connectionId, string $name ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $this->key( $connectionId, $name ) );
		}
	}

	/**
	 * The transient name (letters only in the name, so the key stays within the 172 characters a transient allows).
	 *
	 * @param int    $connectionId Connection.
	 * @param string $name         Name.
	 */
	private function key( int $connectionId, string $name ): string {
		return 'estasoft_sync_holded_' . preg_replace( '/[^a-z]/', '', $name ) . '_' . $connectionId;
	}
}
