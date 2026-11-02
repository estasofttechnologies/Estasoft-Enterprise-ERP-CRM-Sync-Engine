<?php
/**
 * Session ids in memory (one request, tests).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the session ids for the life of the object.
 */
final class ArraySessionStore implements SessionStore {

	/**
	 * Session ids by connection.
	 *
	 * @var array<int,string>
	 */
	private array $sessions = array();

	/**
	 * The session id of a connection.
	 *
	 * @param int $connectionId Connection.
	 */
	public function get( int $connectionId ): ?string {
		return $this->sessions[ $connectionId ] ?? null;
	}

	/**
	 * Keep a session id.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $session      Session id.
	 * @param int    $ttl          Seconds (ignored).
	 */
	public function put( int $connectionId, string $session, int $ttl ): void {
		unset( $ttl );
		$this->sessions[ $connectionId ] = $session;
	}

	/**
	 * Forget it.
	 *
	 * @param int $connectionId Connection.
	 */
	public function forget( int $connectionId ): void {
		unset( $this->sessions[ $connectionId ] );
	}
}
