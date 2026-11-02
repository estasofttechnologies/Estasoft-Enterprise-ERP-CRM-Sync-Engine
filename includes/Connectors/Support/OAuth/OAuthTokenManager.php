<?php
/**
 * Gets, stores and refreshes OAuth 2 tokens.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects and fixed texts, not output.

use Estasoft\SyncEngine\Connectors\Support\HostPolicy;
use Estasoft\SyncEngine\Connectors\Support\Redaction;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * One manager for every OAuth connector. Grants: authorization_code (with PKCE S256) and its refresh_token, client_credentials, jwt_bearer
 * (an RS256 assertion signed from the private key the merchant pasted) and password. The merchant's own client id and secret are read from
 * the connection; the plugin contains no secret.
 *
 * Tokens are stored encrypted in the connection secrets under oauth_access_token, oauth_refresh_token, oauth_expires_at and
 * oauth_refresh_expires_at and oauth_lifetime. A token is refreshed when less than five minutes remain (half its life for a token that lives ten minutes or less), under a lock: two workers never refresh at once, and
 * the one that waits reuses the new token. A refusal that only a person can fix (SYNC-AUTH-001, 002, 005) holds the connection (needs
 * reconnect, with the existing notification); failures that may pass (time-out, 5xx, 429) are thrown for the queue to retry and change nothing.
 */
final class OAuthTokenManager {

	public const ACCESS_TOKEN    = 'oauth_access_token';
	public const REFRESH_TOKEN   = 'oauth_refresh_token';
	public const EXPIRES_AT      = 'oauth_expires_at';
	public const REFRESH_EXPIRES = 'oauth_refresh_expires_at';

	/**
	 * Seconds the last access token lived (`expires_in`), so the margin can follow a short token.
	 */
	public const LIFETIME = 'oauth_lifetime';

	/**
	 * Refresh when fewer seconds than this remain, for a token that lives longer than ten minutes. A shorter token is refreshed
	 * when half of its life is left (see margin()), so a 300 second token is not refreshed on every call.
	 */
	public const MARGIN = 300;

	/**
	 * Seconds a refresh lock lives if its owner dies. It must outlast the token request (30 seconds) with room to spare.
	 */
	private const LOCK_TTL = 90;

	/**
	 * Times a waiting worker looks again, a quarter of a second apart.
	 */
	private const WAIT_ROUNDS = 40;

	/**
	 * Tokens obtained in this request, so repeated calls do not read the store.
	 *
	 * @var array<string,array{token:string,expires:int,lifetime:int}>
	 */
	private array $memory = array();

	/**
	 * Receives microseconds.
	 *
	 * @var \Closure
	 */
	private \Closure $sleep;

	/**
	 * Set up with what it depends on.
	 *
	 * @param HttpClientInterface $http     The engine's HTTP client.
	 * @param Clock               $clock    Time source.
	 * @param TokenStorage        $storage  Where tokens are kept.
	 * @param RefreshLock         $lock     Refresh lock.
	 * @param \Closure|null       $resolver Receives a Connection, returns its OAuthProvider or null (used when a caller does not pass one).
	 * @param \Closure|null       $sleep    Receives microseconds; defaults to usleep.
	 * @param Logger|null         $logger   Log.
	 */
	public function __construct(
		private readonly HttpClientInterface $http,
		private readonly Clock $clock,
		private readonly TokenStorage $storage,
		private readonly RefreshLock $lock,
		private readonly ?\Closure $resolver = null,
		?\Closure $sleep = null,
		private readonly ?Logger $logger = null,
	) {
		$this->sleep = $sleep ?? static function ( int $microseconds ): void {
			usleep( $microseconds );
		};
	}

	/**
	 * A valid access token, refreshed first when fewer than five minutes remain.
	 *
	 * @param Connection         $connection Connection.
	 * @param OAuthProvider|null $provider   Provider; found through the registry when null.
	 *
	 * @throws OAuthException When no token can be had.
	 */
	public function accessToken( Connection $connection, ?OAuthProvider $provider = null ): string {
		$provider = $this->provider( $connection, $provider );
		$known    = $this->current( $connection );
		if ( '' !== $known['token'] && $known['expires'] - $this->now() > self::margin( $known['lifetime'] ) ) {
			return $known['token'];
		}
		return $this->obtain( $connection, $provider, $known['token'] );
	}

	/**
	 * Get a new access token now (after the system answered 401, or from the scheduled task).
	 *
	 * @param Connection         $connection Connection.
	 * @param OAuthProvider|null $provider   Provider; found through the registry when null.
	 *
	 * @throws OAuthException When the refresh fails.
	 */
	public function refreshNow( Connection $connection, ?OAuthProvider $provider = null ): string {
		$provider = $this->provider( $connection, $provider );
		return $this->obtain( $connection, $provider, $this->current( $connection )['token'] );
	}

	/**
	 * Trade the code from the sign-in callback for tokens and store them.
	 *
	 * @param Connection    $connection  The connection being signed in (saved).
	 * @param OAuthProvider $provider    Provider.
	 * @param string        $code        Code from the callback.
	 * @param string        $redirectUri The redirect address used in the sign-in address.
	 * @param string        $verifier    PKCE verifier.
	 *
	 * @throws OAuthException When the vendor refuses the code (SYNC-AUTH-003) or the call fails.
	 */
	public function exchangeCode( Connection $connection, OAuthProvider $provider, string $code, string $redirectUri, string $verifier = '' ): void {
		$config = $provider->config( $connection );
		$body   = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $redirectUri,
		);
		if ( $config->usePkce && '' !== $verifier ) {
			$body['code_verifier'] = $verifier;
		}
		$this->store( $connection, $config, $this->tokenRequest( $connection, $config, $body, TokenErrors::PHASE_CODE ) );
	}

	/**
	 * Get a token with the client credentials grant and keep it.
	 *
	 * @param Connection    $connection Connection (saved or not).
	 * @param OAuthProvider $provider   Provider.
	 *
	 * @throws OAuthException On failure.
	 */
	public function clientCredentials( Connection $connection, OAuthProvider $provider ): string {
		$config = $provider->config( $connection );
		return $this->store( $connection, $config, $this->tokenRequest( $connection, $config, $this->grantBody( $connection, $config, 'client_credentials' ), TokenErrors::PHASE_GRANT ) );
	}

	/**
	 * Get a token with a signed assertion and keep it.
	 *
	 * @param Connection    $connection Connection (saved or not).
	 * @param OAuthProvider $provider   Provider.
	 *
	 * @throws OAuthException On failure.
	 */
	public function jwtBearer( Connection $connection, OAuthProvider $provider ): string {
		$config = $provider->config( $connection );
		return $this->store( $connection, $config, $this->tokenRequest( $connection, $config, $this->grantBody( $connection, $config, 'jwt_bearer' ), TokenErrors::PHASE_GRANT ) );
	}

	/**
	 * Get a token for an unsaved connection and keep it for this request only (Test connection before the first save).
	 *
	 * @param Connection    $connection Connection.
	 * @param OAuthProvider $provider   Provider.
	 *
	 * @throws OAuthException On failure.
	 */
	public function acquire( Connection $connection, OAuthProvider $provider ): string {
		$config = $provider->config( $connection );
		return $this->store( $connection, $config, $this->tokenRequest( $connection, $config, $this->grantBody( $connection, $config, $config->grant ), 'authorization_code' === $config->grant ? TokenErrors::PHASE_REFRESH : TokenErrors::PHASE_GRANT ) );
	}

	/**
	 * Seconds left on the stored access token (negative when it ran out, zero when there is none).
	 *
	 * @param Connection $connection Connection.
	 */
	public function secondsLeft( Connection $connection ): int {
		$known = $this->current( $connection );
		return '' === $known['token'] ? 0 : $known['expires'] - $this->now();
	}

	/**
	 * The provider for a connection.
	 *
	 * @param Connection         $connection Connection.
	 * @param OAuthProvider|null $provider   Provider the caller knows.
	 *
	 * @throws \LogicException When no provider is known.
	 */
	private function provider( Connection $connection, ?OAuthProvider $provider ): OAuthProvider {
		$provider ??= null === $this->resolver ? null : ( $this->resolver )( $connection );
		if ( ! $provider instanceof OAuthProvider ) {
			throw new \LogicException( 'No OAuth provider is known for connector ' . $connection->connector . '.' );
		}
		return $provider;
	}

	/**
	 * The newest token known: this request's memory or the connection's secrets.
	 *
	 * @param Connection $connection Connection.
	 * @return array{token:string,expires:int,lifetime:int}
	 */
	private function current( Connection $connection ): array {
		$stored = array(
			'token'    => $connection->secret( self::ACCESS_TOKEN ),
			'expires'  => (int) $connection->secret( self::EXPIRES_AT ),
			'lifetime' => (int) $connection->secret( self::LIFETIME ),
		);
		$memory = $this->memory[ $this->memoryKey( $connection ) ] ?? null;
		return null !== $memory && $memory['expires'] >= $stored['expires'] ? $memory : $stored;
	}

	/**
	 * Get a new token under the lock; a worker that waits reuses the token the other one obtained.
	 *
	 * @param Connection    $connection Connection.
	 * @param OAuthProvider $provider   Provider.
	 * @param string        $stale      The token the caller found out of date or refused.
	 *
	 * @throws OAuthException On failure.
	 */
	private function obtain( Connection $connection, OAuthProvider $provider, string $stale ): string {
		$config = $provider->config( $connection );
		if ( $connection->id <= 0 ) {
			return $this->store( $connection, $config, $this->tokenRequest( $connection, $config, $this->grantBody( $connection, $config, $config->grant ), 'authorization_code' === $config->grant ? TokenErrors::PHASE_REFRESH : TokenErrors::PHASE_GRANT ) );
		}
		$key   = 'oauth:' . $connection->id;
		$owner = $this->lock->acquire( $key, self::LOCK_TTL );
		for ( $round = 0; null === $owner; ++$round ) {
			if ( $round >= self::WAIT_ROUNDS ) {
				throw new OAuthException( new ConnectorError( ErrorCode::Net001, array( 'system' => '' ), null, null, null, 'token refresh in progress elsewhere' ) );
			}
			( $this->sleep )( 250000 );
			$reuse = $this->reusable( $connection->id, $stale );
			if ( null !== $reuse ) {
				return $reuse;
			}
			$owner = $this->lock->acquire( $key, self::LOCK_TTL );
		}
		try {
			$reuse = $this->reusable( $connection->id, $stale );
			if ( null !== $reuse ) {
				return $reuse;
			}
			$fresh = $this->storage->load( $connection->id ) ?? $connection;
			return $this->store( $fresh, $config, $this->tokenRequest( $fresh, $config, $this->grantBody( $fresh, $config, $config->grant ), 'authorization_code' === $config->grant ? TokenErrors::PHASE_REFRESH : TokenErrors::PHASE_GRANT ) );
		} finally {
			$this->lock->release( $key, $owner );
		}
	}

	/**
	 * A token another worker stored since the caller read its own.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $stale        The out-of-date token.
	 */
	private function reusable( int $connectionId, string $stale ): ?string {
		$fresh = $this->storage->load( $connectionId );
		if ( null === $fresh ) {
			return null;
		}
		$token    = $fresh->secret( self::ACCESS_TOKEN );
		$expires  = (int) $fresh->secret( self::EXPIRES_AT );
		$lifetime = (int) $fresh->secret( self::LIFETIME );
		if ( '' === $token || $token === $stale || $expires - $this->now() <= self::margin( $lifetime ) ) {
			return null;
		}
		$this->memory[ $this->memoryKey( $fresh ) ] = array(
			'token'    => $token,
			'expires'  => $expires,
			'lifetime' => $lifetime,
		);
		return $token;
	}

	/**
	 * Seconds before expiry at which a token counts as out of date: five minutes, or half the token's life when it lives ten minutes or
	 * less (a token of 300 seconds is then used for its first 150 seconds, one of 60 seconds for its first 30). Unknown life: five minutes.
	 *
	 * @param int $lifetime Seconds the token lived when it was issued (0 when unknown).
	 */
	public static function margin( int $lifetime ): int {
		return $lifetime > 0 ? min( self::MARGIN, intdiv( $lifetime, 2 ) ) : self::MARGIN;
	}

	/**
	 * Seconds the stored access token lived when it was issued (0 when unknown).
	 *
	 * @param Connection $connection Connection.
	 */
	public function lifetime( Connection $connection ): int {
		return $this->current( $connection )['lifetime'];
	}

	/**
	 * The form fields of a grant.
	 *
	 * @param Connection  $connection Connection.
	 * @param OAuthConfig $config     Settings.
	 * @param string      $grant      Grant to run.
	 * @return array<string,string>
	 *
	 * @throws \LogicException For a grant that does not exist. A connection that lacks what the grant needs ends in an OAuthException.
	 */
	private function grantBody( Connection $connection, OAuthConfig $config, string $grant ): array {
		$scope = $config->scopeString();
		switch ( $grant ) {
			case 'authorization_code':
				$refresh = $connection->secret( self::REFRESH_TOKEN );
				if ( '' === $refresh ) {
					$this->refuse( $connection, ErrorCode::Auth002, 'no refresh token stored' );
				}
				return array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
				);
			case 'client_credentials':
				return array( 'grant_type' => 'client_credentials' ) + ( '' !== $scope ? array( 'scope' => $scope ) : array() );
			case 'password':
				return array(
					'grant_type' => 'password',
					'username'   => (string) ( '' !== $connection->secret( $config->usernameKey ) ? $connection->secret( $config->usernameKey ) : $connection->config( $config->usernameKey, '' ) ),
					'password'   => $connection->secret( $config->passwordKey ),
				) + ( '' !== $scope ? array( 'scope' => $scope ) : array() );
			case 'jwt_bearer':
				$assertion = $this->assertion( $connection, $config );
				if ( 'client_assertion' === $config->jwtMode ) {
					return array(
						'grant_type'            => 'client_credentials',
						'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
						'client_assertion'      => $assertion,
					) + ( '' !== $scope ? array( 'scope' => $scope ) : array() );
				}
				return array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $assertion,
				) + ( '' !== $scope && 'assertion' !== $config->jwtMode ? array( 'scope' => $scope ) : array() );
		}
		throw new \LogicException( 'Unknown OAuth grant ' . $grant . '.' );
	}

	/**
	 * Build the signed assertion: iss, sub, aud, iat, exp, jti and the vendor's own claims.
	 *
	 * @param Connection  $connection Connection.
	 * @param OAuthConfig $config     Settings.
	 *
	 * @throws OAuthException When the key is missing or unreadable.
	 */
	private function assertion( Connection $connection, OAuthConfig $config ): string {
		$clientId = $config->clientId( $connection );
		$now      = $this->now();
		$claims   = array(
			'iss' => $config->jwtIssuer ?? $clientId,
			'sub' => $config->jwtSubject ?? $clientId,
			'aud' => $config->jwtAudience ?? $config->audience ?? $config->tokenUrl,
			'iat' => $now,
			'exp' => $now + max( 30, $config->jwtLifetimeSeconds ),
			'jti' => bin2hex( random_bytes( 16 ) ),
		);
		if ( 'client_assertion' === $config->jwtMode && '' !== $config->scopeString() ) {
			$claims['scope'] = $config->scopes;
		}
		foreach ( $config->jwtClaims as $name => $value ) {
			$claims[ (string) $name ] = $value;
		}
		return JwtAssertion::sign( $claims, $connection->secret( $config->privateKeyKey ), $config->jwtHeaders, $config->jwtAlgorithm );
	}

	/**
	 * Send a token request.
	 *
	 * @param Connection           $connection Connection.
	 * @param OAuthConfig          $config     Settings.
	 * @param array<string,string> $body      Form fields of the grant.
	 * @param string               $phase      TokenErrors phase.
	 * @return array<string,mixed> The decoded answer.
	 *
	 * @throws OAuthException On failure.
	 */
	private function tokenRequest( Connection $connection, OAuthConfig $config, array $body, string $phase ): array {
		try {
			( new HostPolicy( $config->allowedHosts, $config->merchantHost ) )->assert( $config->tokenUrl );
		} catch ( OAuthException $exception ) {
			throw $exception;
		} catch ( \Estasoft\SyncEngine\Connectors\Support\ApiException $exception ) {
			throw new OAuthException( $exception->error() );
		}
		$clientId = $config->clientId( $connection );
		$secret   = $connection->secret( $config->clientSecretKey );
		$headers  = array(
			'Content-Type' => 'application/x-www-form-urlencoded',
			'Accept'       => 'application/json',
		);
		$fields   = $body;
		if ( ( ! isset( $fields['client_assertion'] ) && ! isset( $fields['assertion'] ) ) || '' !== $secret ) {
			if ( 'basic' === $config->clientAuth && '' !== $clientId && '' !== $secret ) {
				$headers['Authorization'] = 'Basic ' . base64_encode( rawurlencode( $clientId ) . ':' . rawurlencode( $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials, not code.
			} elseif ( '' !== $clientId ) {
				$fields['client_id'] = $clientId;
				if ( '' !== $secret ) {
					$fields['client_secret'] = $secret;
				}
			}
		}
		if ( null !== $config->resource ) {
			$fields['resource'] = $config->resource;
		}
		if ( null !== $config->audience && ! isset( $fields['assertion'] ) && ! isset( $fields['client_assertion'] ) ) {
			$fields['audience'] = $config->audience;
		}
		$fields += $config->extraTokenParams;

		$response  = $this->http->request( 'POST', $config->tokenUrl, $headers, http_build_query( $fields, '', '&', PHP_QUERY_RFC1738 ), 30 );
		$redaction = $this->redaction( $connection, $config, array_values( $fields ) );
		if ( 0 === $response->status || $response->status < 200 || $response->status >= 300 ) {
			$error = TokenErrors::map( $response, $phase, '', $redaction, $this->clock->now()->format( 'Y-m-d' ) );
			// Only the vendor's own OAuth refusal holds the connection. A firewall page, a proxy answer or an answer without an error code
			// is not proof that the credentials are wrong: it is thrown as a failure that may pass and nothing is changed.
			if ( TokenErrors::PHASE_CODE !== $phase && TokenErrors::holds( $response, $phase ) && in_array( $error->code, array( ErrorCode::Auth001, ErrorCode::Auth002, ErrorCode::Auth005 ), true ) ) {
				$this->hold( $connection, $error->code );
			}
			$this->note( $connection, $error );
			throw new OAuthException( $error, null );
		}
		$data = $this->decode( $response );
		if ( ! is_string( $data['access_token'] ?? null ) || '' === $data['access_token'] ) {
			// An answer that is not a JSON object (a login or firewall page with a 200) is a failure that may pass, not a refusal of the credentials.
			$error = is_array( $response->json() )
				? new ConnectorError( ErrorCode::Auth001, array( 'system' => '' ), $response->status, null, null, 'the token answer had no access token' )
				: new ConnectorError(
					ErrorCode::Net002,
					array(
						'system' => '',
						'status' => $response->status,
					),
					$response->status,
					null,
					null,
					'the token answer was not JSON'
				);
			$this->note( $connection, $error );
			throw new OAuthException( $error );
		}
		return $data;
	}

	/**
	 * Decode a token answer.
	 *
	 * @param HttpResponse $response Response.
	 * @return array<string,mixed>
	 */
	private function decode( HttpResponse $response ): array {
		$data = $response->json();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Keep the tokens of an answer: in the store when the connection is saved, always in memory.
	 *
	 * @param Connection          $connection Connection.
	 * @param OAuthConfig         $config     Settings.
	 * @param array<string,mixed> $data       Decoded token answer.
	 * @return string The access token.
	 */
	private function store( Connection $connection, OAuthConfig $config, array $data ): string {
		$now     = $this->now();
		$token   = (string) $data['access_token'];
		$seconds = isset( $data['expires_in'] ) && is_numeric( $data['expires_in'] ) && (int) $data['expires_in'] > 0 ? (int) $data['expires_in'] : $config->accessTokenFallbackSeconds;
		$expires = $now + $seconds;

		$secrets           = array(
			self::ACCESS_TOKEN => $token,
			self::EXPIRES_AT   => (string) $expires,
			self::LIFETIME     => (string) $seconds,
		);
		$credentialExpires = null;
		if ( $config->usesRefreshToken() ) {
			if ( isset( $data['refresh_token'] ) && is_string( $data['refresh_token'] ) && '' !== $data['refresh_token'] ) {
				$secrets[ self::REFRESH_TOKEN ] = $data['refresh_token'];
			}
			$lifetime = null;
			foreach ( array( 'refresh_token_expires_in', 'x_refresh_token_expires_in', 'refresh_expires_in' ) as $name ) {
				if ( isset( $data[ $name ] ) && is_numeric( $data[ $name ] ) && (int) $data[ $name ] > 0 ) {
					$lifetime = (int) $data[ $name ];
					break;
				}
			}
			if ( null === $lifetime && null !== $config->refreshTokenLifetimeDays ) {
				$lifetime = $config->refreshTokenLifetimeDays * 86400;
			}
			if ( null !== $lifetime ) {
				$credentialExpires                = $now + $lifetime;
				$secrets[ self::REFRESH_EXPIRES ] = (string) $credentialExpires;
			}
		}
		$this->memory[ $this->memoryKey( $connection ) ] = array(
			'token'    => $token,
			'expires'  => $expires,
			'lifetime' => $seconds,
		);
		if ( $connection->id > 0 ) {
			$this->storage->save( $connection->id, $secrets, $credentialExpires );
		}
		return $token;
	}

	/**
	 * Stop the flow with a refusal that only a person can fix, and hold the connection.
	 *
	 * @param Connection $connection Connection.
	 * @param ErrorCode  $code       Code.
	 * @param string     $detail     Short reason for the log.
	 *
	 * @throws OAuthException Always.
	 */
	private function refuse( Connection $connection, ErrorCode $code, string $detail ): never {
		$error = new ConnectorError( $code, array( 'system' => '' ), null, null, null, $detail );
		$this->hold( $connection, $code );
		$this->note( $connection, $error );
		throw new OAuthException( $error );
	}

	/**
	 * Hold a saved connection for a person.
	 *
	 * @param Connection $connection Connection.
	 * @param ErrorCode  $code       Code.
	 */
	private function hold( Connection $connection, ErrorCode $code ): void {
		if ( $connection->id > 0 ) {
			$this->storage->hold( $connection->id, $code, $connection->label );
		}
	}

	/**
	 * Write a failure to the log (code and short reason only).
	 *
	 * @param Connection     $connection Connection.
	 * @param ConnectorError $error      Failure.
	 */
	private function note( Connection $connection, ConnectorError $error ): void {
		$this->logger?->warning(
			'Token request failed.',
			array(
				'connection_id' => $connection->id > 0 ? $connection->id : null,
				'code'          => $error->code,
				'http_status'   => $error->httpStatus,
			)
		);
	}

	/**
	 * Secret remover for the messages of one token request.
	 *
	 * @param Connection        $connection Connection.
	 * @param OAuthConfig       $config     Settings.
	 * @param array<int,string> $extra      Values sent in the request.
	 */
	private function redaction( Connection $connection, OAuthConfig $config, array $extra ): Redaction {
		$redaction = Redaction::forConnection( $connection, array( $config->clientSecretKey, $config->privateKeyKey, $config->passwordKey ) );
		foreach ( $extra as $value ) {
			if ( is_string( $value ) ) {
				$redaction->add( $value );
			}
		}
		return $redaction;
	}

	/**
	 * Key of the in-memory token of a connection (an unsaved connection is told apart by its client id).
	 *
	 * @param Connection $connection Connection.
	 */
	private function memoryKey( Connection $connection ): string {
		return $connection->id > 0 ? 'c' . $connection->id : 'u' . hash( 'sha256', $connection->connector . '|' . (string) $connection->config( 'client_id', '' ) . '|' . $connection->secret( 'client_id' ) );
	}

	/**
	 * Current time as epoch seconds.
	 */
	private function now(): int {
		return $this->clock->now()->getTimestamp();
	}
}
