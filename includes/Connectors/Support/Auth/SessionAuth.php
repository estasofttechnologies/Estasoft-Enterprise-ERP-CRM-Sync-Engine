<?php
/**
 * Server-login sessions (SAP Business One Service Layer, Sage 300, Sage X3).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Auth;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects and fixed texts, not output.

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiRequest;
use Estasoft\SyncEngine\Connectors\Support\OAuth\RefreshLock;
use Estasoft\SyncEngine\Connectors\Support\OAuth\WpdbRefreshLock;
use Estasoft\SyncEngine\Core\Support\SystemClock;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * The connector gives a login closure: it makes the vendor's login call (with its own ApiClient and NoAuth) and returns the session id, or
 * `array( 'id' => $session, 'ttl' => $seconds )`. SessionAuth keeps the id per connection, sends it on every call as a cookie (`B1SESSION=...`),
 * a header or a query parameter, and when the system answers 401 (or the client sees the login redirect) it forgets the id and logs in once more.
 * Logging in is done under a lock per connection: workers that need a session at the same moment wait for the one that logs in and use its
 * session instead of opening one each. A 401 for a session another worker has already replaced uses the newer session and logs nobody out.
 *
 * Example: `new SessionAuth( fn( Connection $c ) => $this->login( $c ), cookie: 'B1SESSION' )`.
 */
final class SessionAuth implements AuthStrategy {

	/**
	 * Default session lifetime in seconds when the login does not say.
	 */
	public const DEFAULT_TTL = 1200;

	/**
	 * Seconds the login lock lives if its owner dies (longer than the login call can take).
	 */
	private const LOCK_TTL = 90;

	/**
	 * Times a waiting worker looks for the session of the one that logs in, a quarter of a second apart.
	 */
	private const WAIT_ROUNDS = 60;

	/**
	 * Receives a Connection, returns the session id or `array{id:string,ttl:int}`.
	 *
	 * @var \Closure
	 */
	private \Closure $login;

	/**
	 * Sessions made by this object (an unsaved connection has no stored one).
	 *
	 * @var array<int,string>
	 */
	private array $memory = array();

	/**
	 * The session this object sent last, per connection: the one a 401 refers to.
	 *
	 * @var array<int,string>
	 */
	private array $applied = array();

	/**
	 * Login lock; null means none (a request without a database).
	 *
	 * @var RefreshLock|null
	 */
	private ?RefreshLock $lock;

	/**
	 * Receives microseconds.
	 *
	 * @var \Closure
	 */
	private \Closure $sleep;

	/**
	 * Set up with what it depends on.
	 *
	 * @param \Closure          $login   Login call.
	 * @param string            $cookie  Cookie name that carries the session id (empty to use a header or a query parameter instead).
	 * @param string            $header  Header name that carries the session id (used when no cookie name is given).
	 * @param string            $query   Query parameter that carries the session id (used when neither a cookie nor a header name is given).
	 * @param SessionStore|null $store   Session store; transients by default.
	 * @param string            $prefix  Text put before the session id in a header.
	 * @param RefreshLock|null  $lock    Login lock; the database lock of the plugin by default.
	 * @param \Closure|null     $sleep   Receives microseconds; defaults to usleep. Tests pass a recorder.
	 */
	public function __construct(
		\Closure $login,
		private readonly string $cookie = '',
		private readonly string $header = '',
		private readonly string $query = '',
		private readonly ?SessionStore $store = null,
		private readonly string $prefix = '',
		?RefreshLock $lock = null,
		?\Closure $sleep = null,
	) {
		$this->login = $login;
		$this->lock  = $lock ?? self::defaultLock();
		$this->sleep = $sleep ?? static function ( int $microseconds ): void {
			usleep( $microseconds );
		};
	}

	/**
	 * Add the session id to the request, logging in first when there is none.
	 *
	 * @param Connection $connection Connection.
	 * @param ApiRequest $request    Request.
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException When the login fails.
	 */
	public function apply( Connection $connection, ApiRequest $request ): ApiRequest {
		$session                          = $this->memory[ $connection->id ] ?? $this->store()->get( $connection->id ) ?? $this->signIn( $connection, '' );
		$this->applied[ $connection->id ] = $session;
		if ( '' !== $this->cookie ) {
			$existing = $request->header( 'Cookie' );
			return $request->withHeader( 'Cookie', ( null !== $existing && '' !== $existing ? $existing . '; ' : '' ) . $this->cookie . '=' . $session );
		}
		if ( '' !== $this->header ) {
			return $request->withHeader( $this->header, $this->prefix . $session );
		}
		return '' !== $this->query ? $request->withQuery( $this->query, $session ) : $request;
	}

	/**
	 * The system refused the session: forget it and log in again, once.
	 *
	 * @param Connection $connection Connection.
	 * @return bool True when a new session was made and the request should be sent again.
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException When the login fails.
	 */
	public function onUnauthorized( Connection $connection ): bool {
		$stale = $this->applied[ $connection->id ] ?? '';
		if ( $connection->id > 0 && '' !== $stale ) {
			$stored = $this->store()->get( $connection->id );
			if ( null !== $stored && '' !== $stored && $stored !== $stale ) {
				// Another worker made a new session after ours was issued: the 401 was about the old one. Use the newer one.
				$this->memory[ $connection->id ] = $stored;
				return true;
			}
		}
		unset( $this->memory[ $connection->id ] );
		$this->store()->forget( $connection->id );
		$this->signIn( $connection, $stale );
		return true;
	}

	/**
	 * Forget the stored session (after a disconnect or a password change).
	 *
	 * @param Connection $connection Connection.
	 */
	public function forget( Connection $connection ): void {
		unset( $this->memory[ $connection->id ] );
		$this->store()->forget( $connection->id );
	}

	/**
	 * Get a session: log in under the lock of the connection, or take the one another worker made while this one waited.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $stale      The session that was refused (empty when there was none); a stored session that differs from it is somebody else's newer one.
	 * @return string The session id.
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException When the login returns no session.
	 */
	private function signIn( Connection $connection, string $stale ): string {
		if ( $connection->id <= 0 || null === $this->lock ) {
			return $this->login( $connection );
		}
		$key   = 'session:' . $connection->id;
		$owner = $this->lock->acquire( $key, self::LOCK_TTL );
		for ( $round = 0; null === $owner && $round < self::WAIT_ROUNDS; ++$round ) {
			( $this->sleep )( 250000 );
			$found = $this->newerSession( $connection, $stale );
			if ( null !== $found ) {
				return $found;
			}
			$owner = $this->lock->acquire( $key, self::LOCK_TTL );
		}
		try {
			$found = $this->newerSession( $connection, $stale ); // Looked again with the lock in hand: somebody may have just finished.
			return $found ?? $this->login( $connection );
		} finally {
			if ( null !== $owner ) {
				$this->lock->release( $key, $owner );
			}
		}
	}

	/**
	 * A stored session that is not the refused one.
	 *
	 * @param Connection $connection Connection.
	 * @param string     $stale      The refused session.
	 */
	private function newerSession( Connection $connection, string $stale ): ?string {
		$found = $this->store()->get( $connection->id );
		if ( null === $found || '' === $found || $found === $stale ) {
			return null;
		}
		$this->memory[ $connection->id ] = $found;
		return $found;
	}

	/**
	 * Make the login call and keep the session.
	 *
	 * @param Connection $connection Connection.
	 * @return string The session id.
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException When the login returns no session.
	 */
	private function login( Connection $connection ): string {
		$result  = ( $this->login )( $connection );
		$session = is_array( $result ) ? (string) ( $result['id'] ?? '' ) : (string) $result;
		$ttl     = is_array( $result ) ? (int) ( $result['ttl'] ?? self::DEFAULT_TTL ) : self::DEFAULT_TTL;
		if ( '' === $session ) {
			throw new ApiException( MissingSecret::error( 'session' ) );
		}
		$this->memory[ $connection->id ] = $session;
		if ( $connection->id > 0 ) {
			$this->store()->put( $connection->id, $session, max( 60, $ttl - 60 ) );
		}
		return $session;
	}

	/**
	 * The plugin's database lock when there is a database, else none.
	 */
	private static function defaultLock(): ?RefreshLock {
		global $wpdb;
		return $wpdb instanceof \wpdb ? new WpdbRefreshLock( $wpdb, new SystemClock() ) : null;
	}

	/**
	 * The session store.
	 */
	private function store(): SessionStore {
		return $this->store ?? new TransientSessionStore();
	}
}
