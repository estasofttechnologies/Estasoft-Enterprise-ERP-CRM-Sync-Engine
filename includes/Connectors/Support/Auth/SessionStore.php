<?php
/**
 * Where a server-login session id is kept between requests.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * One entry per connection, with a lifetime. The session id is never logged or shown.
 */
interface SessionStore {

	/**
	 * The stored session id, or null.
	 *
	 * @param int $connectionId Connection.
	 */
	public function get( int $connectionId ): ?string;

	/**
	 * Keep a session id.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $session      Session id.
	 * @param int    $ttl          Seconds it is kept.
	 */
	public function put( int $connectionId, string $session, int $ttl ): void;

	/**
	 * Forget the session id.
	 *
	 * @param int $connectionId Connection.
	 */
	public function forget( int $connectionId ): void;
}
