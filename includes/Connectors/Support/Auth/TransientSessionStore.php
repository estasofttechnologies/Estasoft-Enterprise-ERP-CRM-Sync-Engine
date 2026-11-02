<?php
/**
 * Session ids in transients.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * A transient per connection, `estasoft_sync_sess_{id}`. A session expires on the server anyway, and a lost one is made again with one login.
 */
final class TransientSessionStore implements SessionStore {

	/**
	 * The session id of a connection.
	 *
	 * @param int $connectionId Connection.
	 */
	public function get( int $connectionId ): ?string {
		$value = get_transient( $this->key( $connectionId ) );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Keep a session id.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $session      Session id.
	 * @param int    $ttl          Seconds.
	 */
	public function put( int $connectionId, string $session, int $ttl ): void {
		set_transient( $this->key( $connectionId ), $session, max( 60, $ttl ) );
	}

	/**
	 * Forget it.
	 *
	 * @param int $connectionId Connection.
	 */
	public function forget( int $connectionId ): void {
		delete_transient( $this->key( $connectionId ) );
	}

	/**
	 * The transient name.
	 *
	 * @param int $connectionId Connection.
	 */
	private function key( int $connectionId ): string {
		return 'estasoft_sync_sess_' . $connectionId;
	}
}
