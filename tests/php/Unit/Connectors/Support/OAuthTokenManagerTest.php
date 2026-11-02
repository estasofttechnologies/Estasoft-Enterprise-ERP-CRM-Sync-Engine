<?php
/**
 * OAuth 2 grants, refresh under a lock, error mapping and storage.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthException;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class OAuthTokenManagerTest extends TestCase {

	private const TOKEN_URL = 'https://login.vendor.test/oauth/token';
	private const SECRET    = 'client-secret-0123456789';
	private const REFRESH   = 'refresh-token-AAAAAAAAAAAA';
	private const ACCESS    = 'access-token-BBBBBBBBBBBB';

	private ApiTestKit $kit;

	private OAuthConfig $config;

	protected function setUp(): void {
		$this->kit    = ApiTestKit::make();
		$this->config = new OAuthConfig( authorizeUrl: 'https://login.vendor.test/authorize', tokenUrl: self::TOKEN_URL, scopes: array( 'read', 'write' ), refreshTokenLifetimeDays: 60 , allowedHosts: array( 'login.vendor.test' ) );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	private function provider( ?OAuthConfig $config = null ): OAuthProvider {
		$config ??= $this->config;
		return new class( $config ) implements OAuthProvider {
			public function __construct( private OAuthConfig $config ) {}
			public function config( Connection $connection ): OAuthConfig {
				return $this->config;
			}
		};
	}

	/**
	 * A saved connection with tokens that expire in some seconds.
	 *
	 * @param int $expiresIn Seconds until the access token expires.
	 */
	private function signedIn( int $expiresIn, array $secrets = array() ): Connection {
		return $this->kit->connection(
			'vendor',
			array( 'client_id' => 'client-abc' ),
			$secrets + array(
				'client_secret'                       => self::SECRET,
				OAuthTokenManager::ACCESS_TOKEN       => self::ACCESS,
				OAuthTokenManager::REFRESH_TOKEN      => self::REFRESH,
				OAuthTokenManager::EXPIRES_AT         => (string) ( $this->kit->clock->now()->getTimestamp() + $expiresIn ),
			)
		);
	}

	private function tokenAnswer( array $extra = array() ): \Closure {
		return static fn() => FakeHttpClient::json( $extra + array( 'access_token' => 'new-access-CCCCCCCCCCCC', 'refresh_token' => 'new-refresh-DDDDDDDDDDDD', 'expires_in' => 1800, 'token_type' => 'Bearer' ) );
	}

	/**
	 * @param array<string,mixed> $request A recorded request.
	 * @return array<string,string>
	 */
	private function form( array $request ): array {
		parse_str( (string) $request['body'], $fields );
		return $fields;
	}

	private function expectRefusal( callable $call, ErrorCode $code ): OAuthException {
		try {
			$call();
		} catch ( OAuthException $exception ) {
			$this->assertSame( $code, $exception->error()->code );
			$this->assertNoSecret( $exception->getMessage() . '|' . $exception->error()->detail . '|' . wp_json_encode( $exception->error()->context ) );
			return $exception;
		}
		$this->fail( 'Expected an OAuthException.' );
	}

	private function assertNoSecret( string $text ): void {
		foreach ( array( self::SECRET, self::REFRESH, self::ACCESS, 'new-access', 'new-refresh' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}
	}

	/* ------------------------------------------------------------ authorization code */

	public function test_the_sign_in_code_is_traded_with_pkce_and_a_basic_client_header_and_the_tokens_are_stored(): void {
		$connection = $this->kit->connection( 'vendor', array( 'client_id' => 'client-abc' ), array( 'client_secret' => self::SECRET ), 1, ConnectionStatus::NeedsReconnect );
		$this->kit->http->route( 'POST', '#/oauth/token$#', $this->tokenAnswer() );

		$this->kit->tokens->exchangeCode( $connection, $this->provider(), 'the-code', 'https://shop.example.test/cb', 'the-verifier' );

		$request = $this->kit->http->last();
		$fields  = $this->form( $request );
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( self::TOKEN_URL, $request['url'] );
		$this->assertSame( 'authorization_code', $fields['grant_type'] );
		$this->assertSame( 'the-code', $fields['code'] );
		$this->assertSame( 'https://shop.example.test/cb', $fields['redirect_uri'] );
		$this->assertSame( 'the-verifier', $fields['code_verifier'] );
		$this->assertArrayNotHasKey( 'client_secret', $fields, 'Basic client authentication keeps the secret out of the body.' );
		$this->assertSame( 'Basic ' . base64_encode( 'client-abc:' . self::SECRET ), $request['headers']['Authorization'] ); // phpcs:ignore
		$this->assertSame( 'application/x-www-form-urlencoded', $request['headers']['Content-Type'] );

		$saved = $this->kit->storage->rows[1];
		$now   = $this->kit->clock->now()->getTimestamp();
		$this->assertSame( 'new-access-CCCCCCCCCCCC', $saved['secrets'][ OAuthTokenManager::ACCESS_TOKEN ] );
		$this->assertSame( 'new-refresh-DDDDDDDDDDDD', $saved['secrets'][ OAuthTokenManager::REFRESH_TOKEN ] );
		$this->assertSame( (string) ( $now + 1800 ), $saved['secrets'][ OAuthTokenManager::EXPIRES_AT ] );
		$this->assertSame( (string) ( $now + 60 * 86400 ), $saved['secrets'][ OAuthTokenManager::REFRESH_EXPIRES ] );
		$this->assertSame( $now + 60 * 86400, $saved['credential_expires_at'], 'The key-expiry warning follows the refresh token lifetime.' );
		$this->assertSame( 0, $this->kit->lock->acquired, 'No lock is needed to store the first tokens.' );
	}

	public function test_the_client_secret_can_go_in_the_body_and_the_refresh_lifetime_from_the_answer_wins(): void {
		$config     = new OAuthConfig( authorizeUrl: 'https://login.vendor.test/authorize', tokenUrl: self::TOKEN_URL, clientAuth: 'body', refreshTokenLifetimeDays: 60, extraTokenParams: array( 'audience' => 'api' ) , allowedHosts: array( 'login.vendor.test' ) );
		$connection = $this->kit->connection( 'vendor', array( 'client_id' => 'client-abc' ), array( 'client_secret' => self::SECRET ) );
		$this->kit->http->route( 'POST', '#/oauth/token$#', $this->tokenAnswer( array( 'x_refresh_token_expires_in' => 8640000 ) ) );

		$this->kit->tokens->exchangeCode( $connection, $this->provider( $config ), 'c', 'https://shop.example.test/cb' );

		$request = $this->kit->http->last();
		$fields  = $this->form( $request );
		$this->assertSame( 'client-abc', $fields['client_id'] );
		$this->assertSame( self::SECRET, $fields['client_secret'] );
		$this->assertSame( 'api', $fields['audience'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
		$this->assertArrayNotHasKey( 'code_verifier', $fields );
		$this->assertSame( $this->kit->clock->now()->getTimestamp() + 8640000, $this->kit->storage->rows[1]['credential_expires_at'] );
	}

	public function test_a_refused_code_is_auth_003_and_does_not_hold_the_connection(): void {
		$connection = $this->kit->connection( 'vendor', array( 'client_id' => 'c' ), array( 'client_secret' => self::SECRET ) );
		foreach ( array( 'invalid_grant', 'access_denied' ) as $error ) {
			$this->kit->http->queue( FakeHttpClient::json( array( 'error' => $error, 'error_description' => 'code ' . self::SECRET ), 400 ) );
			$this->expectRefusal( fn() => $this->kit->tokens->exchangeCode( $connection, $this->provider(), 'bad', 'https://shop.example.test/cb' ), ErrorCode::Auth003 );
		}
		$this->assertSame( array(), $this->kit->storage->holds );
		$this->assertSame( ConnectionStatus::Active, $this->kit->storage->status( 1 ) );
	}

	/* ------------------------------------------------------------ access token and refresh */

	public function test_a_token_with_more_than_five_minutes_left_is_used_without_a_call(): void {
		$connection = $this->signedIn( 301 );
		$this->assertSame( self::ACCESS, $this->kit->tokens->accessToken( $connection, $this->provider() ) );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_token_with_less_than_five_minutes_left_is_refreshed_and_the_new_refresh_token_is_kept(): void {
		$connection = $this->signedIn( 299 );
		$this->kit->http->route( 'POST', '#/oauth/token$#', $this->tokenAnswer( array( 'expires_in' => '1800' ) ) );

		$token = $this->kit->tokens->accessToken( $connection, $this->provider() );

		$this->assertSame( 'new-access-CCCCCCCCCCCC', $token );
		$fields = $this->form( $this->kit->http->last() );
		$this->assertSame( 'refresh_token', $fields['grant_type'] );
		$this->assertSame( self::REFRESH, $fields['refresh_token'] );
		$this->assertSame( 'new-refresh-DDDDDDDDDDDD', $this->kit->storage->rows[1]['secrets'][ OAuthTokenManager::REFRESH_TOKEN ], 'Refresh tokens that rotate are replaced.' );
		$this->assertSame( 0, count( $this->kit->lock->held ), 'The lock is released.' );
		$this->assertSame( 1, $this->kit->lock->acquired );

		// The same request asks again with the old Connection object: no second call.
		$this->assertSame( 'new-access-CCCCCCCCCCCC', $this->kit->tokens->accessToken( $connection, $this->provider() ) );
		$this->assertSame( 1, $this->kit->http->count() );
	}

	public function test_a_refresh_answer_without_a_new_refresh_token_keeps_the_old_one(): void {
		$connection = $this->signedIn( 10 );
		$this->kit->http->route( 'POST', '#/oauth/token$#', static fn() => FakeHttpClient::json( array( 'access_token' => 'next-access-EEEEEEEE', 'expires_in' => 600 ) ) );
		$this->kit->tokens->accessToken( $connection, $this->provider() );
		$this->assertSame( self::REFRESH, $this->kit->storage->rows[1]['secrets'][ OAuthTokenManager::REFRESH_TOKEN ] );
	}

	public function test_the_second_worker_waits_for_the_lock_and_reuses_the_token_the_first_one_stored(): void {
		$connection = $this->signedIn( 10 );
		$this->kit->lock->busy = 2; // Another worker holds the lock for two looks.
		$polls = 0;
		$this->kit->storage->onLoad = function () use ( &$polls ): void {
			if ( 2 === ++$polls ) { // The other worker finishes: it saved a fresh token.
				$this->kit->storage->save( 1, array( OAuthTokenManager::ACCESS_TOKEN => 'by-other-worker-FFFFFFF', OAuthTokenManager::EXPIRES_AT => (string) ( $this->kit->clock->now()->getTimestamp() + 1800 ) ) );
			}
		};

		$token = $this->kit->tokens->accessToken( $connection, $this->provider() );

		$this->assertSame( 'by-other-worker-FFFFFFF', $token );
		$this->assertSame( 0, $this->kit->http->count(), 'Two workers never refresh at once: the second one made no call.' );
		$this->assertNotSame( array(), $this->kit->slept, 'It waited instead of racing.' );
	}

	public function test_after_the_lock_is_taken_the_token_is_read_again_before_calling(): void {
		$connection = $this->signedIn( 10 );
		$this->kit->storage->onLoad = function (): void {
			$this->kit->storage->onLoad = null;
			$this->kit->storage->save( 1, array( OAuthTokenManager::ACCESS_TOKEN => 'saved-meanwhile-GGGGGGG', OAuthTokenManager::EXPIRES_AT => (string) ( $this->kit->clock->now()->getTimestamp() + 1800 ) ) );
		};
		$this->assertSame( 'saved-meanwhile-GGGGGGG', $this->kit->tokens->accessToken( $connection, $this->provider() ) );
		$this->assertSame( 0, $this->kit->http->count() );
		$this->assertSame( array(), $this->kit->lock->held );
	}

	public function test_a_lock_that_never_frees_ends_in_a_retryable_error_not_a_second_refresh(): void {
		$connection = $this->signedIn( 10 );
		$this->kit->lock->busy = 1000;
		$exception = $this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Net001 );
		$this->assertSame( 0, $this->kit->http->count() );
		$this->assertSame( array(), $this->kit->storage->holds, 'Nothing changed for the person.' );
		$this->assertCount( 40, $this->kit->slept );
		unset( $exception );
	}

	public function test_refresh_now_after_a_401_refreshes_unless_another_worker_already_did(): void {
		$connection = $this->signedIn( 3000 );
		$this->kit->http->route( 'POST', '#/oauth/token$#', $this->tokenAnswer() );
		$this->assertSame( 'new-access-CCCCCCCCCCCC', $this->kit->tokens->refreshNow( $connection, $this->provider() ), 'The token still looked valid, but the system refused it.' );
		$this->assertSame( 1, $this->kit->http->count() );

		$other = $this->signedIn( 3000 );
		$this->kit->storage->onLoad = function (): void {
			$this->kit->storage->onLoad = null;
			$this->kit->storage->save( 1, array( OAuthTokenManager::ACCESS_TOKEN => 'refreshed-by-peer-HHHHHH', OAuthTokenManager::EXPIRES_AT => (string) ( $this->kit->clock->now()->getTimestamp() + 3000 ) ) );
		};
		$this->kit->tokens = new OAuthTokenManager( $this->kit->http, $this->kit->clock, $this->kit->storage, $this->kit->lock );
		$this->assertSame( 'refreshed-by-peer-HHHHHH', $this->kit->tokens->refreshNow( $other, $this->provider() ) );
		$this->assertSame( 1, $this->kit->http->count(), 'No second refresh.' );
	}

	/* ------------------------------------------------------------ refusals */

	public function test_a_refused_refresh_token_is_auth_002_and_the_connection_needs_reconnect(): void {
		$connection = $this->signedIn( 10 );
		$this->kit->http->queue( FakeHttpClient::json( array( 'error' => 'invalid_grant', 'error_description' => 'Token revoked: ' . self::REFRESH ), 400 ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Auth002 );

		$this->assertSame( array( array( 1, 'SYNC-AUTH-002' ) ), $this->kit->storage->holds );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $this->kit->storage->status( 1 ) );
		$this->assertSame( self::REFRESH, $this->kit->storage->rows[1]['secrets'][ OAuthTokenManager::REFRESH_TOKEN ], 'Nothing was thrown away.' );
		$this->assertSame( 0, count( $this->kit->lock->held ), 'The lock is released after a refusal.' );
	}

	public function test_invalid_client_pauses_the_connection_and_an_expired_secret_is_auth_005(): void {
		$connection = $this->signedIn( 10 );
		$this->kit->http->queue( FakeHttpClient::json( array( 'error' => 'invalid_client' ), 401 ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Auth001 );
		$this->assertSame( ConnectionStatus::Paused, $this->kit->storage->status( 1 ) );

		$second = $this->kit->connection( 'vendor', array( 'client_id' => 'c' ), array( 'client_secret' => self::SECRET, OAuthTokenManager::REFRESH_TOKEN => self::REFRESH ), 2 );
		$this->kit->http->queue( FakeHttpClient::json( array( 'error' => 'invalid_client', 'error_description' => 'The client secret has expired' ), 401 ) );
		$exception = $this->expectRefusal( fn() => $this->kit->tokens->accessToken( $second, $this->provider() ), ErrorCode::Auth005 );
		$this->assertSame( '2026-11-02', $exception->error()->context['date'] );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $this->kit->storage->status( 2 ) );
	}

	public function test_failures_that_may_pass_change_nothing(): void {
		$connection = $this->signedIn( 10 );
		$cases      = array(
			array( FakeHttpClient::timeout(), ErrorCode::Net001 ),
			array( FakeHttpClient::status( 502 ), ErrorCode::Net002 ),
			array( FakeHttpClient::tooManyRequests( 17 ), ErrorCode::Rate001 ),
			array( FakeHttpClient::certificateError(), ErrorCode::Net003 ),
		);
		foreach ( $cases as $case ) {
			$this->kit->http->queue( $case[0] );
			$exception = $this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), $case[1] );
			if ( ErrorCode::Rate001 === $case[1] ) {
				$this->assertSame( 17, $exception->error()->retryAfter );
			}
		}
		$this->assertSame( array(), $this->kit->storage->holds );
		$this->assertSame( ConnectionStatus::Active, $this->kit->storage->status( 1 ) );
		$this->assertSame( 0, $this->kit->storage->saves );
	}

	public function test_a_web_page_in_place_of_the_token_answer_never_holds_the_connection(): void {
		$page  = '<html><head><title>Access denied</title></head><body><h1>Attention Required! | Cloudflare</h1><p>Sorry, you have been blocked.</p></body></html>';
		$cases = array(
			'403 page'         => FakeHttpClient::status( 403, $page ),
			'401 page'         => FakeHttpClient::status( 401, $page ),
			'400 without code' => FakeHttpClient::status( 400, '{"message":"bad"}' ),
			'401 empty body'   => FakeHttpClient::status( 401 ),
			'502 page'         => FakeHttpClient::status( 502, $page ),
			'503 page'         => FakeHttpClient::status( 503, $page ),
		);
		foreach ( $cases as $label => $answer ) {
			$connection = $this->signedIn( 10 );
			$this->kit->http->queue( $answer );
			$exception = $this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Net002 );
			$this->assertStringNotContainsString( '<', $exception->error()->detail, $label . ': the markup is not kept.' );
		}
		$this->assertSame( array(), $this->kit->storage->holds, 'Nothing was held.' );
		$this->assertSame( ConnectionStatus::Active, $this->kit->storage->status( 1 ) );
		$this->assertSame( 0, $this->kit->storage->saves );
		$this->assertSame( self::REFRESH, $this->kit->storage->rows[1]['secrets'][ OAuthTokenManager::REFRESH_TOKEN ], 'The refresh token stays.' );
	}

	public function test_a_not_found_page_for_the_token_address_is_a_refusal_for_a_person_but_holds_nothing(): void {
		foreach ( array( 404, 405, 410 ) as $status ) {
			$connection = $this->signedIn( 10 );
			$this->kit->http->queue( FakeHttpClient::status( $status, '<html><body>Not Found</body></html>' ) );
			$exception = $this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Auth001 );
			$this->assertSame( $status, $exception->error()->httpStatus );
		}
		$this->assertSame( array(), $this->kit->storage->holds, 'A wrong address is not an answer about the credentials.' );
		$this->assertSame( ConnectionStatus::Active, $this->kit->storage->status( 1 ) );
	}

	public function test_a_server_side_oauth_error_is_retryable_and_only_the_three_refusals_hold(): void {
		$connection = $this->signedIn( 10 );
		$this->kit->http->queue( FakeHttpClient::json( array( 'error' => 'temporarily_unavailable' ), 400 ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Net002 );
		$this->kit->http->queue( FakeHttpClient::json( array( 'error' => 'server_error' ), 400 ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Net002 );
		$this->kit->http->queue( FakeHttpClient::json( array( 'error' => 'invalid_request' ), 400 ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Auth001 );
		$this->kit->http->queue( FakeHttpClient::json( array( 'error' => 'unsupported_grant_type' ), 400 ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Auth001 );
		$this->assertSame( array(), $this->kit->storage->holds, 'An error that does not judge the credentials holds nothing.' );

		$this->kit->http->queue( FakeHttpClient::json( array( 'error' => 'unauthorized_client' ), 400 ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Auth001 );
		$this->assertCount( 1, $this->kit->storage->holds, 'unauthorized_client is a refusal of the app.' );
	}

	public function test_a_200_that_is_a_web_page_is_a_failure_that_may_pass(): void {
		$connection = $this->signedIn( 10 );
		$this->kit->http->queue( FakeHttpClient::status( 200, '<html><body>Please sign in</body></html>' ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Net002 );
		$this->assertSame( array(), $this->kit->storage->holds );
	}

	public function test_a_token_that_lives_five_minutes_is_not_refreshed_on_every_call(): void {
		$connection = $this->kit->connection( 'vendor', array( 'client_id' => 'c' ), array( 'client_secret' => self::SECRET, OAuthTokenManager::REFRESH_TOKEN => self::REFRESH ) );
		$this->kit->http->route( 'POST', '#/oauth/token$#', fn() => FakeHttpClient::json( array( 'access_token' => 'short-' . $this->kit->http->count(), 'refresh_token' => self::REFRESH, 'expires_in' => 300 ) ) );

		$first = $this->kit->tokens->refreshNow( $connection, $this->provider() );
		$this->assertSame( 1, $this->kit->http->count() );
		$this->assertSame( '300', $this->kit->storage->rows[1]['secrets'][ OAuthTokenManager::LIFETIME ] );
		$saved = $this->kit->storage->load( 1 );

		$this->kit->clock->advance( 100 );
		$this->assertSame( $first, $this->kit->tokens->accessToken( $saved, $this->provider() ), 'A 300 second token is good for its first 150 seconds.' );
		$this->assertSame( 1, $this->kit->http->count() );

		$this->kit->clock->advance( 60 ); // 160 seconds in: fewer than 150 left.
		$second = $this->kit->tokens->accessToken( $this->kit->storage->load( 1 ), $this->provider() );
		$this->assertNotSame( $first, $second );
		$this->assertSame( 2, $this->kit->http->count() );
		$this->assertSame( 2, $this->kit->http->count(), 'And one refresh, not one per call.' );
		$this->assertSame( $second, $this->kit->tokens->accessToken( $this->kit->storage->load( 1 ), $this->provider() ) );
		$this->assertSame( 2, $this->kit->http->count() );
	}

	public function test_a_token_that_lives_one_minute_is_used_for_thirty_seconds(): void {
		$connection = $this->kit->connection( 'vendor', array( 'client_id' => 'c' ), array( 'client_secret' => self::SECRET, OAuthTokenManager::REFRESH_TOKEN => self::REFRESH ) );
		$this->kit->http->route( 'POST', '#/oauth/token$#', fn() => FakeHttpClient::json( array( 'access_token' => 'tiny-' . $this->kit->http->count(), 'refresh_token' => self::REFRESH, 'expires_in' => 60 ) ) );
		$first = $this->kit->tokens->refreshNow( $connection, $this->provider() );

		$this->kit->clock->advance( 20 );
		$this->assertSame( $first, $this->kit->tokens->accessToken( $this->kit->storage->load( 1 ), $this->provider() ) );
		$this->assertSame( 1, $this->kit->http->count() );
		$this->kit->clock->advance( 11 );
		$this->assertNotSame( $first, $this->kit->tokens->accessToken( $this->kit->storage->load( 1 ), $this->provider() ), 'Fewer than 30 seconds left.' );
		$this->assertSame( 2, $this->kit->http->count() );
	}

	public function test_the_margin_is_five_minutes_for_a_long_token_and_half_the_life_for_a_short_one(): void {
		$this->assertSame( 300, OAuthTokenManager::margin( 3600 ) );
		$this->assertSame( 300, OAuthTokenManager::margin( 600 ) );
		$this->assertSame( 150, OAuthTokenManager::margin( 300 ) );
		$this->assertSame( 30, OAuthTokenManager::margin( 60 ) );
		$this->assertSame( 300, OAuthTokenManager::margin( 0 ), 'An unknown life keeps the five minutes.' );
	}

	public function test_a_waiting_worker_applies_the_same_margin_to_the_token_the_other_one_stored(): void {
		$connection = $this->signedIn( 10 );
		$this->kit->lock->busy = 1; // Another worker holds the lock for one look.
		$fresh                 = array( OAuthTokenManager::ACCESS_TOKEN => 'other-worker-token', OAuthTokenManager::EXPIRES_AT => (string) ( $this->kit->clock->now()->getTimestamp() + 200 ), OAuthTokenManager::LIFETIME => '300' );
		$this->kit->storage->onLoad = function () use ( $fresh ): void {
			$this->kit->storage->onLoad = null;
			$this->kit->storage->save( 1, $fresh );
		};
		$this->assertSame( 'other-worker-token', $this->kit->tokens->accessToken( $connection, $this->provider() ), '200 seconds left of a 300 second token is more than the 150 second margin.' );
		$this->assertSame( 0, $this->kit->http->count(), 'Reused, not refreshed.' );
	}

	public function test_the_refresh_lock_outlives_the_token_request(): void {
		$ttls = array();
		$lock = new class( $ttls ) implements \Estasoft\SyncEngine\Connectors\Support\OAuth\RefreshLock {
			public function __construct( private array &$ttls ) {}
			public function acquire( string $key, int $ttl ): ?string {
				$this->ttls[] = $ttl;
				return 'owner';
			}
			public function release( string $key, string $token ): void {}
		};
		$manager    = new OAuthTokenManager( $this->kit->http, $this->kit->clock, $this->kit->storage, $lock );
		$connection = $this->signedIn( 10 );
		$this->kit->http->queue( FakeHttpClient::json( array( 'access_token' => 'x-token', 'expires_in' => 3600 ) ) );
		$manager->accessToken( $connection, $this->provider() );
		$this->assertNotEmpty( $ttls );
		$this->assertGreaterThanOrEqual( 60, min( $ttls ), 'The token request may take 30 seconds; the lock must not expire first.' );
	}

	public function test_no_refresh_token_means_reconnect(): void {
		$connection = $this->kit->connection( 'vendor', array( 'client_id' => 'c' ), array( 'client_secret' => self::SECRET ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Auth002 );
		$this->assertSame( 0, $this->kit->http->count() );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $this->kit->storage->status( 1 ) );
	}

	public function test_an_answer_without_an_access_token_is_a_refusal(): void {
		$connection = $this->signedIn( 10 );
		$this->kit->http->queue( FakeHttpClient::json( array( 'hello' => 'world' ) ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider() ), ErrorCode::Auth001 );
	}

	public function test_token_addresses_must_be_https_and_on_the_declared_hosts(): void {
		$connection = $this->signedIn( 10 );
		$http       = new OAuthConfig( authorizeUrl: '', tokenUrl: 'http://login.vendor.test/token' , allowedHosts: array( 'login.vendor.test' ) );
		$other      = new OAuthConfig( authorizeUrl: '', tokenUrl: 'https://evil.test/token', allowedHosts: array( 'login.vendor.test' ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider( $http ) ), ErrorCode::Val002 );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider( $other ) ), ErrorCode::Val002 );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	/* ------------------------------------------------------------ other grants */

	public function test_client_credentials_asks_again_when_the_token_runs_out(): void {
		$config     = new OAuthConfig( authorizeUrl: '', tokenUrl: self::TOKEN_URL, scopes: array( 'a', 'b' ), grant: 'client_credentials', usePkce: false, clientAuth: 'body', resource: 'https://api.vendor.test' , allowedHosts: array( 'login.vendor.test' ) );
		$connection = $this->kit->connection( 'vendor', array(), array( 'client_id' => 'cid-secretly', 'client_secret' => self::SECRET ) );
		$this->kit->http->route( 'POST', '#/oauth/token$#', static fn() => FakeHttpClient::json( array( 'access_token' => 'cc-token-IIIIIIIIII', 'expires_in' => 3600 ) ) );

		$this->assertSame( 'cc-token-IIIIIIIIII', $this->kit->tokens->accessToken( $connection, $this->provider( $config ) ) );
		$fields = $this->form( $this->kit->http->last() );
		$this->assertSame( 'client_credentials', $fields['grant_type'] );
		$this->assertSame( 'a b', $fields['scope'] );
		$this->assertSame( 'https://api.vendor.test', $fields['resource'] );
		$this->assertSame( 'cid-secretly', $fields['client_id'], 'The client id may be stored as a secret.' );
		$this->assertArrayNotHasKey( OAuthTokenManager::REFRESH_TOKEN, $this->kit->storage->rows[1]['secrets'] );
		$this->assertNull( $this->kit->storage->rows[1]['credential_expires_at'], 'No sign-in to run out.' );

		$this->kit->clock->advance( 3600 );
		$this->kit->tokens = new OAuthTokenManager( $this->kit->http, $this->kit->clock, $this->kit->storage, $this->kit->lock );
		$connection        = $this->kit->storage->load( 1 );
		$this->kit->tokens->accessToken( $connection, $this->provider( $config ) );
		$this->assertSame( 2, $this->kit->http->count() );
	}

	public function test_an_unsaved_connection_gets_a_token_for_the_test_without_touching_storage_or_the_lock(): void {
		$config     = new OAuthConfig( authorizeUrl: '', tokenUrl: self::TOKEN_URL, grant: 'client_credentials', clientAuth: 'basic' , allowedHosts: array( 'login.vendor.test' ) );
		$connection = $this->kit->unsaved( 'vendor', array( 'client_id' => 'abc' ), array( 'client_secret' => self::SECRET ) );
		$this->kit->http->queue( FakeHttpClient::json( array( 'access_token' => 'unsaved-token-JJJJJJJ', 'expires_in' => 3600 ) ) );

		$this->assertSame( 'unsaved-token-JJJJJJJ', $this->kit->tokens->accessToken( $connection, $this->provider( $config ) ) );
		$this->assertSame( 'unsaved-token-JJJJJJJ', $this->kit->tokens->accessToken( $connection, $this->provider( $config ) ) );
		$this->assertSame( 1, $this->kit->http->count(), 'Kept in memory for the rest of the request.' );
		$this->assertSame( 0, $this->kit->storage->saves );
		$this->assertSame( 0, $this->kit->lock->acquired );
		$this->assertStringStartsWith( 'Basic ', $this->kit->http->last()['headers']['Authorization'] );
	}

	public function test_the_password_grant_sends_the_user_and_password(): void {
		$config     = new OAuthConfig( authorizeUrl: '', tokenUrl: self::TOKEN_URL, grant: 'password', usePkce: false, clientAuth: 'body' , allowedHosts: array( 'login.vendor.test' ) );
		$connection = $this->kit->connection( 'vendor', array( 'client_id' => 'c', 'username' => 'sync@example.test' ), array( 'client_secret' => self::SECRET, 'password' => 'pw-pw-pw-pw-pw' ) );
		$this->kit->http->queue( FakeHttpClient::json( array( 'access_token' => 'pw-token-KKKKKKKK', 'expires_in' => 600 ) ) );
		$this->kit->tokens->accessToken( $connection, $this->provider( $config ) );
		$fields = $this->form( $this->kit->http->last() );
		$this->assertSame( 'password', $fields['grant_type'] );
		$this->assertSame( 'sync@example.test', $fields['username'] );
		$this->assertSame( 'pw-pw-pw-pw-pw', $fields['password'] );
	}

	public function test_the_jwt_bearer_grant_sends_a_signed_assertion_with_the_claims_of_the_provider(): void {
		$key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		openssl_pkey_export( $key, $pem );
		$public = openssl_pkey_get_details( $key )['key'];
		$config = new OAuthConfig(
			authorizeUrl: '',
			tokenUrl: self::TOKEN_URL,
			scopes: array( 'api' ),
			grant: 'jwt_bearer',
			usePkce: false,
			audience: 'https://login.vendor.test',
			jwtSubject: 'integration@example.test',
			jwtClaims: array( 'custom' => 'x' ),
			jwtHeaders: array( 'kid' => 'kid-1' ),
			jwtLifetimeSeconds: 180,
			allowedHosts: array( 'login.vendor.test' )
		);
		$connection = $this->kit->connection( 'vendor', array( 'client_id' => 'consumer-key' ), array( 'private_key' => $pem ) );
		$this->kit->http->queue( FakeHttpClient::json( array( 'access_token' => 'jwt-token-LLLLLLLL' ) ) );

		$this->assertSame( 'jwt-token-LLLLLLLL', $this->kit->tokens->accessToken( $connection, $this->provider( $config ) ) );

		$request = $this->kit->http->last();
		$fields  = $this->form( $request );
		$this->assertSame( 'urn:ietf:params:oauth:grant-type:jwt-bearer', $fields['grant_type'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
		$this->assertArrayNotHasKey( 'client_secret', $fields );
		$parts = explode( '.', $fields['assertion'] );
		$this->assertSame( 1, openssl_verify( $parts[0] . '.' . $parts[1], (string) base64_decode( strtr( $parts[2], '-_', '+/' ) ), $public, OPENSSL_ALGO_SHA256 ) ); // phpcs:ignore
		$claims = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ) ), true ); // phpcs:ignore
		$now    = $this->kit->clock->now()->getTimestamp();
		$this->assertSame( 'consumer-key', $claims['iss'] );
		$this->assertSame( 'integration@example.test', $claims['sub'] );
		$this->assertSame( 'https://login.vendor.test', $claims['aud'] );
		$this->assertSame( $now + 180, $claims['exp'] );
		$this->assertSame( 'x', $claims['custom'] );
		$this->assertNotEmpty( $claims['jti'] );
		$this->assertSame( $now + 3600, (int) $this->kit->storage->rows[1]['secrets'][ OAuthTokenManager::EXPIRES_AT ], 'The default lifetime is used when the answer has none.' );
		$this->assertStringNotContainsString( 'PRIVATE KEY', $this->kit->http->transcript() );
	}

	public function test_the_client_assertion_style_uses_client_credentials_with_the_assertion(): void {
		$key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		openssl_pkey_export( $key, $pem );
		$config     = new OAuthConfig( authorizeUrl: '', tokenUrl: self::TOKEN_URL, scopes: array( 'rest_webservices' ), grant: 'jwt_bearer', usePkce: false, jwtMode: 'client_assertion', jwtAlgorithm: 'ES256', jwtHeaders: array( 'kid' => 'cert-1' ) , allowedHosts: array( 'login.vendor.test' ) );
		$connection = $this->kit->connection( 'vendor', array( 'client_id' => 'ns-client' ), array( 'private_key' => $pem ) );
		$this->kit->http->queue( FakeHttpClient::json( array( 'access_token' => 'ns-token-MMMMMMMM', 'expires_in' => 3600 ) ) );

		$this->kit->tokens->accessToken( $connection, $this->provider( $config ) );

		$fields = $this->form( $this->kit->http->last() );
		$this->assertSame( 'client_credentials', $fields['grant_type'] );
		$this->assertSame( 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $fields['client_assertion_type'] );
		$header = json_decode( (string) base64_decode( strtr( explode( '.', $fields['client_assertion'] )[0], '-_', '+/' ) ), true ); // phpcs:ignore
		$this->assertSame( array( 'kid' => 'cert-1', 'alg' => 'ES256', 'typ' => 'JWT' ), $header );
	}

	public function test_a_bad_private_key_is_a_refusal_that_pauses_the_connection(): void {
		$config     = new OAuthConfig( authorizeUrl: '', tokenUrl: self::TOKEN_URL, grant: 'jwt_bearer', usePkce: false , allowedHosts: array( 'login.vendor.test' ) );
		$connection = $this->kit->connection( 'vendor', array( 'client_id' => 'c' ), array( 'private_key' => 'not a key' ) );
		$this->expectRefusal( fn() => $this->kit->tokens->accessToken( $connection, $this->provider( $config ) ), ErrorCode::Auth001 );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_seconds_left_and_the_resolver(): void {
		$connection = $this->signedIn( 120 );
		$this->assertSame( 120, $this->kit->tokens->secondsLeft( $connection ) );
		$bare = $this->kit->connection( 'vendor', array(), array(), 2 );
		$this->assertSame( 0, $this->kit->tokens->secondsLeft( $bare ) );

		$this->expectException( \LogicException::class );
		$this->kit->tokens->accessToken( $connection );
	}

	public function test_the_resolver_finds_the_provider_when_the_caller_passes_none(): void {
		$connection = $this->signedIn( 10 );
		$provider   = $this->provider();
		$manager    = new OAuthTokenManager( $this->kit->http, $this->kit->clock, $this->kit->storage, $this->kit->lock, static fn() => $provider );
		$this->kit->http->queue( FakeHttpClient::json( array( 'access_token' => 'resolved-token-NNNNNN', 'expires_in' => 600 ) ) );
		$this->assertSame( 'resolved-token-NNNNNN', $manager->accessToken( $connection ) );
	}
}
