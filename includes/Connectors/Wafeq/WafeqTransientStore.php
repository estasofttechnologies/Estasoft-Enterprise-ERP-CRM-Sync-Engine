<?php
/**
 * The item index in a WordPress transient.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

/**
 * One transient per connection, `estasoft_sync_wafeq_items_{id}`. A lost transient only costs one more read of the item list.
 */
final class WafeqTransientStore implements WafeqIndexStore {

	/**
	 * The value kept for a connection.
	 *
	 * @param int $connectionId Connection.
	 * @return array<string,mixed>|null
	 */
	public function get( int $connectionId ): ?array {
		if ( ! function_exists( 'get_transient' ) ) {
			return null;
		}
		$value = get_transient( $this->key( $connectionId ) );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Keep a value.
	 *
	 * @param int                 $connectionId Connection.
	 * @param array<string,mixed> $value        Value.
	 * @param int                 $ttl          Seconds.
	 */
	public function put( int $connectionId, array $value, int $ttl ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $this->key( $connectionId ), $value, max( 60, $ttl ) );
		}
	}

	/**
	 * Forget the value of a connection.
	 *
	 * @param int $connectionId Connection.
	 */
	public function forget( int $connectionId ): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $this->key( $connectionId ) );
		}
	}

	/**
	 * The transient name.
	 *
	 * @param int $connectionId Connection.
	 */
	private function key( int $connectionId ): string {
		return 'estasoft_sync_wafeq_items_' . $connectionId;
	}
}
