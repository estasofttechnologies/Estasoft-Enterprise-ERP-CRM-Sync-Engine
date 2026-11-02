<?php
/**
 * The simple authentication strategies and server-login sessions.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiRequest;
use Estasoft\SyncEngine\Connectors\Support\Auth\ArraySessionStore;
use Estasoft\SyncEngine\Connectors\Support\Auth\BasicAuth;
use Estasoft\SyncEngine\Connectors\Support\Auth\BearerAuth;
use Estasoft\SyncEngine\Connectors\Support\Auth\HeaderKeyAuth;
use Estasoft\SyncEngine\Connectors\Support\Auth\NoAuth;
use Estasoft\SyncEngine\Connectors\Support\Auth\QueryKeyAuth;
use Estasoft\SyncEngine\Connectors\Support\Auth\SessionAuth;
use Estasoft\SyncEngine\Connectors\Support\HostPolicy;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Connectors\Support\Redaction;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\ApiMemoryLock;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AuthStrategiesTest extends TestCase {

	private function connection( array $config = array(), array $secrets = array(), int $id = 3 ): Connection {
		return new Connection( $id, 'demo', ConnectorType::Erp, 'Demo', ConnectionStatus::Active, $config, array(), $secrets );
	}

	private function request(): ApiRequest {
		return new ApiRequest( 'GET', 'https://api.demo.test/x?a=1' );
	}

	public function test_no_auth_changes_nothing(): void {
		$this->assertEquals( $this->request(), ( new NoAuth() )->apply( $this->connection(), $this->request() ) );
	}

	public function test_a_header_key_with_and_without_a_prefix(): void {
		$connection = $this->connection( array(), array( 'api_key' => 'k-123456' ) );
		$this->assertSame( 'k-123456', ( new HeaderKeyAuth( 'X-Api-Key', 'api_key' ) )->apply( $connection, $this->request() )->header( 'x-api-key' ) );
		$this->assertSame( 'Token k-123456', ( new HeaderKeyAuth( 'Authorization', 'api_key', 'Token ' ) )->apply( $connection, $this->request() )->header( 'Authorization' ) );
	}

	public function test_bearer(): void {
		$this->assertSame( 'Bearer tok-123456', ( new BearerAuth( 'token' ) )->apply( $this->connection( array(), array( 'token' => 'tok-123456' ) ), $this->request() )->header( 'authorization' ) );
	}

	public function test_basic_reads_the_user_from_the_secrets_or_the_config(): void {
		$expected = 'Basic ' . base64_encode( 'sync@example.test:pw-123456' ); // phpcs:ignore
		$fromConfig = $this->connection( array( 'user' => 'sync@example.test' ), array( 'password' => 'pw-123456' ) );
		$fromSecret = $this->connection( array(), array( 'user' => 'sync@example.test', 'password' => 'pw-123456' ) );
		$this->assertSame( $expected, ( new BasicAuth( 'user', 'password' ) )->apply( $fromConfig, $this->request() )->header( 'Authorization' ) );
		$this->assertSame( $expected, ( new BasicAuth( 'user', 'password' ) )->apply( $fromSecret, $this->request() )->header( 'Authorization' ) );
	}

	public function test_basic_with_a_user_field_needs_a_user_and_without_one_sends_the_key_alone(): void {
		$noUser    = $this->connection( array(), array( 'password' => 'pw-123456' ) );
		$emptyUser = $this->connection( array( 'user' => '' ), array( 'password' => 'pw-123456' ) );
		foreach ( array( $noUser, $emptyUser ) as $connection ) {
			try {
				( new BasicAuth( 'user', 'password' ) )->apply( $connection, $this->request() );
				$this->fail( 'A configured user field with no value is an error.' );
			} catch ( ApiException $exception ) {
				$this->assertSame( ErrorCode::Auth001, $exception->error()->code );
			}
		}
		$keyOnly = ( new BasicAuth( '', 'key' ) )->apply( $this->connection( array(), array( 'key' => 'k-123456' ) ), $this->request() );
		$this->assertSame( 'Basic ' . base64_encode( ':k-123456' ), $keyOnly->header( 'Authorization' ), 'A connector that names no user field sends the key alone.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials.
	}

	public function test_basic_with_no_user_field_and_no_secret_is_still_an_error(): void {
		$this->expectException( ApiException::class );
		( new BasicAuth( '', 'key' ) )->apply( $this->connection(), $this->request() );
	}

	public function test_line_breaks_in_a_header_name_or_value_are_removed(): void {
		$request = $this->request()->withHeader( "X-A\r\nX-Injected: 1", "v1\r\nX-Injected: yes\nmore\0" );
		$this->assertSame( array( 'X-AX-Injected: 1' => 'v1X-Injected: yesmore' ), $request->headers );
		foreach ( $request->headers as $name => $value ) {
			$this->assertDoesNotMatchRegularExpression( '/[\r\n\0]/', $name . $value );
		}
		$connection = $this->connection( array(), array( 'api_key' => "k-123456\r\nX-Evil: 1", 'token' => "tok-123456\nX-Evil: 1" ) );
		$this->assertSame( 'Token k-123456X-Evil: 1', ( new HeaderKeyAuth( 'Authorization', 'api_key', 'Token ' ) )->apply( $connection, $this->request() )->header( 'Authorization' ) );
		$this->assertSame( 'Bearer tok-123456X-Evil: 1', ( new BearerAuth( 'token' ) )->apply( $connection, $this->request() )->header( 'Authorization' ) );
		$this->assertSame( "k-123456\r\nX-Evil: 1", $connection->secret( 'api_key' ), 'The stored secret is not changed, only what is sent.' );
	}

	public function test_a_query_key_replaces_a_parameter_of_the_same_name_and_keeps_the_others(): void {
		$request = new ApiRequest( 'GET', 'https://api.demo.test/x?a=1&key=old#frag' );
		$signed  = ( new QueryKeyAuth( 'key', 'api_key' ) )->apply( $this->connection( array(), array( 'api_key' => 'new-key-123' ) ), $request );
		$this->assertSame( 'https://api.demo.test/x?a=1&key=new-key-123#frag', $signed->url );
	}

	public function test_a_missing_secret_is_auth_001_and_the_message_does_not_name_the_field_value(): void {
		foreach ( array( new HeaderKeyAuth( 'X-K', 'api_key' ), new BearerAuth( 'api_key' ), new BasicAuth( 'user', 'api_key' ), new QueryKeyAuth( 'k', 'api_key' ) ) as $strategy ) {
			try {
				$strategy->apply( $this->connection(), $this->request() );
				$this->fail( 'Expected an exception.' );
			} catch ( ApiException $exception ) {
				$this->assertSame( ErrorCode::Auth001, $exception->error()->code );
			}
		}
	}

	public function test_header_changes_return_copies(): void {
		$original = $this->request();
		$changed  = $original->withHeader( 'X-A', '1' )->withHeader( 'x-a', '2' );
		$this->assertNull( $original->header( 'X-A' ) );
		$this->assertSame( '2', $changed->header( 'X-A' ) );
		$this->assertCount( 1, $changed->headers );
	}

	/* ------------------------------------------------------------ sessions */

	/**
	 * A client that signs in with a login closure and a cookie.
	 *
	 * @param FakeHttpClient $http  Fake.
	 * @param int            $logins Counter of logins (by reference).
	 */
	private function sessionClient( FakeHttpClient $http, int &$logins, ArraySessionStore $store, int $id = 3 ): ApiClient {
		$connection = $this->connection( array(), array( 'password' => 'pw-secret-123' ), $id );
		$auth       = new SessionAuth(
			static function ( Connection $c ) use ( &$logins ): array {
				++$logins;
				return array( 'id' => 'session-' . $logins . '-abcdef', 'ttl' => 1800 );
			},
			cookie: 'B1SESSION',
			store: $store
		);
		return new ApiClient( $http, $connection, 'https://sl.demo.test:50000/b1s/v1', $auth, new HostPolicy( array(), true ), 'Demo', Redaction::forConnection( $connection, array( 'password' ) ), new RateLimitHints() );
	}

	public function test_a_session_is_made_once_kept_and_sent_as_a_cookie(): void {
		$http   = ( new FakeHttpClient() )->fallback( static fn() => FakeHttpClient::json( array() ) );
		$logins = 0;
		$store  = new ArraySessionStore();
		$client = $this->sessionClient( $http, $logins, $store );
		$client->get( '/Items' );
		$client->get( '/Orders' );
		$this->assertSame( 1, $logins );
		$this->assertSame( 'B1SESSION=session-1-abcdef', $http->last()['headers']['Cookie'] );
		$this->assertSame( 'session-1-abcdef', $store->get( 3 ) );

		// A new client (a new request) finds the stored session and logs in no more.
		$this->sessionClient( $http, $logins, $store )->get( '/Items' );
		$this->assertSame( 1, $logins );
	}

	public function test_an_expired_session_is_replaced_once_and_the_call_is_sent_again(): void {
		$http   = new FakeHttpClient();
		$logins = 0;
		$store  = new ArraySessionStore();
		$store->put( 3, 'stale-session-xyz', 600 );
		$http->queue( FakeHttpClient::status( 401, '{"error":{"code":301,"message":{"value":"Invalid session"}}}' ) )->queue( FakeHttpClient::json( array( 'value' => array() ) ) );

		$response = $this->sessionClient( $http, $logins, $store )->get( '/Items' );

		$this->assertTrue( $response->isSuccess() );
		$this->assertSame( 1, $logins );
		$this->assertSame( 'B1SESSION=session-1-abcdef', $http->last()['headers']['Cookie'] );
		$this->assertSame( 'session-1-abcdef', $store->get( 3 ) );
		$this->assertSame( 2, $http->count(), 'One failed call, one repeat.' );
	}

	public function test_a_second_401_after_the_new_login_is_a_refusal_and_the_session_is_not_in_the_message(): void {
		$http   = ( new FakeHttpClient() )->fallback( static fn() => FakeHttpClient::status( 401, 'bad session session-1-abcdef' ) );
		$logins = 0;
		try {
			$this->sessionClient( $http, $logins, new ArraySessionStore() )->get( '/Items' );
			$this->fail( 'Expected a refusal.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( ErrorCode::Auth001, $exception->error()->code );
			$this->assertStringNotContainsString( 'session-1', $exception->getMessage() . $exception->error()->detail );
		}
		$this->assertSame( 2, $logins );
		$this->assertSame( 2, $http->count() );
	}

	public function test_an_unsaved_connection_keeps_its_session_in_memory_only(): void {
		$http   = ( new FakeHttpClient() )->fallback( static fn() => FakeHttpClient::json( array() ) );
		$logins = 0;
		$store  = new ArraySessionStore();
		$client = $this->sessionClient( $http, $logins, $store, 0 );
		$client->get( '/a' );
		$client->get( '/b' );
		$this->assertSame( 1, $logins );
		$this->assertNull( $store->get( 0 ) );
	}

	public function test_a_header_or_query_session_and_a_login_that_returns_nothing(): void {
		$connection = $this->connection();
		$header     = new SessionAuth( static fn() => 'sess-header-123', header: 'X-Session', prefix: 'S ', store: new ArraySessionStore() );
		$this->assertSame( 'S sess-header-123', $header->apply( $connection, $this->request() )->header( 'X-Session' ) );
		$query = new SessionAuth( static fn() => 'sess-query-123', query: 'sid', store: new ArraySessionStore() );
		$this->assertStringContainsString( 'sid=sess-query-123', $query->apply( $connection, $this->request() )->url );
		$this->expectException( ApiException::class );
		( new SessionAuth( static fn() => '', cookie: 'X', store: new ArraySessionStore() ) )->apply( $connection, $this->request() );
	}

	/* ------------------------------------------------------------ session lock */

	private function lockedSession( ArraySessionStore $store, ApiMemoryLock $lock, int &$logins, ?\Closure $sleep = null, string $login = 'own' ): SessionAuth {
		return new SessionAuth(
			static function ( Connection $c ) use ( &$logins, $login ): array {
				++$logins;
				return array( 'id' => $login . '-session-' . $logins, 'ttl' => 1800 );
			},
			cookie: 'B1SESSION',
			store: $store,
			lock: $lock,
			sleep: $sleep ?? static function (): void {}
		);
	}

	public function test_workers_that_need_a_session_together_open_one_and_the_others_use_it(): void {
		$store  = new ArraySessionStore();
		$lock   = new ApiMemoryLock();
		$logins = 0;
		// Worker A is in the middle of logging in and holds the lock.
		$lock->held['session:3'] = 'worker-a';
		$sleeps                  = 0;
		$workerB                 = $this->lockedSession(
			$store,
			$lock,
			$logins,
			function () use ( $store, $lock, &$sleeps ): void {
				++$sleeps;
				// While B waits, A finishes: its session is stored and the lock is free.
				$store->put( 3, 'session-made-by-a', 600 );
				unset( $lock->held['session:3'] );
			}
		);
		$signed = $workerB->apply( $this->connection( array(), array(), 3 ), $this->request() );

		$this->assertSame( 'B1SESSION=session-made-by-a', $signed->header( 'Cookie' ) );
		$this->assertSame( 0, $logins, 'B opened no session of its own.' );
		$this->assertSame( 1, $sleeps );
		$this->assertSame( array(), $lock->held, 'The lock is not left behind.' );
	}

	public function test_the_lock_is_taken_released_and_the_session_stored_when_nobody_else_logs_in(): void {
		$store  = new ArraySessionStore();
		$lock   = new ApiMemoryLock();
		$logins = 0;
		$this->lockedSession( $store, $lock, $logins )->apply( $this->connection( array(), array(), 3 ), $this->request() );
		$this->assertSame( 1, $logins );
		$this->assertSame( 1, $lock->acquired );
		$this->assertSame( array(), $lock->held );
		$this->assertSame( 'own-session-1', $store->get( 3 ) );
	}

	public function test_a_session_that_appears_while_the_lock_is_taken_is_used_instead_of_logging_in_again(): void {
		$store  = new ArraySessionStore();
		$lock   = new ApiMemoryLock();
		$logins = 0;
		$lock->busy = 1; // The first look at the lock fails; by the second, a session is there and the lock is free.
		$worker     = $this->lockedSession(
			$store,
			$lock,
			$logins,
			static function () use ( $store ): void {
				$store->put( 3, 'session-from-elsewhere', 600 );
			}
		);
		$worker->apply( $this->connection( array(), array(), 3 ), $this->request() );
		$this->assertSame( 0, $logins );
	}

	public function test_a_401_for_a_session_another_worker_already_replaced_does_not_log_anybody_out(): void {
		$store  = new ArraySessionStore();
		$lock   = new ApiMemoryLock();
		$logins = 0;
		$store->put( 3, 'session-old', 600 );
		$worker = $this->lockedSession( $store, $lock, $logins );
		$worker->apply( $this->connection( array(), array(), 3 ), $this->request() ); // Sends session-old.

		// Another worker saw the same 401 first and made a new session.
		$store->put( 3, 'session-new-from-b', 600 );

		$this->assertTrue( $worker->onUnauthorized( $this->connection( array(), array(), 3 ) ) );
		$this->assertSame( 0, $logins, 'No second login.' );
		$this->assertSame( 'session-new-from-b', $store->get( 3 ), 'The newer session was not thrown away.' );
		$this->assertSame( 'B1SESSION=session-new-from-b', $worker->apply( $this->connection( array(), array(), 3 ), $this->request() )->header( 'Cookie' ), 'The retry uses the newer session.' );
	}

	public function test_a_401_for_the_session_that_is_still_stored_replaces_it_once(): void {
		$store  = new ArraySessionStore();
		$lock   = new ApiMemoryLock();
		$logins = 0;
		$store->put( 3, 'session-old', 600 );
		$worker = $this->lockedSession( $store, $lock, $logins );
		$worker->apply( $this->connection( array(), array(), 3 ), $this->request() );

		$this->assertTrue( $worker->onUnauthorized( $this->connection( array(), array(), 3 ) ) );
		$this->assertSame( 1, $logins );
		$this->assertSame( 'own-session-1', $store->get( 3 ) );
		$this->assertSame( array(), $lock->held );
	}
}
