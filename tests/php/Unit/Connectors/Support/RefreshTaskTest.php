<?php
/**
 * The scheduled refresh over many connections.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthResolver;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Connectors\Support\OAuth\RefreshTask;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceCrmConnector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\Support\OAuth\RefreshTask
 */
final class RefreshTaskTest extends TestCase {

	private ApiTestKit $kit;

	protected function setUp(): void {
		$this->kit = ApiTestKit::make();
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	private function task(): RefreshTask {
		$connector = GuardedConnector::wrap( new ReferenceCrmConnector( $this->kit->deps ), $this->kit->tokens );
		$registry  = new ConnectorRegistry( array( 'reference-crm' => static fn() => $connector ) );
		$resolver  = new OAuthResolver( static fn(): ConnectorRegistry => $registry );
		$tokens    = new OAuthTokenManager( $this->kit->http, $this->kit->clock, $this->kit->storage, $this->kit->lock, static fn( Connection $c ) => $resolver->providerFor( $c ), static function (): void {} );
		return new RefreshTask( $this->kit->storage, $tokens, $resolver, null, $this->kit->clock );
	}

	/**
	 * Sign-ins that expire in some seconds.
	 *
	 * @param int $from      First connection id.
	 * @param int $count     How many.
	 * @param int $expiresIn Seconds until the access token expires.
	 */
	private function connections( int $from, int $count, int $expiresIn ): void {
		$now = $this->kit->clock->now()->getTimestamp();
		for ( $id = $from; $id < $from + $count; ++$id ) {
			$this->kit->connection(
				'reference-crm',
				array( 'tenant_id' => 't' . $id ),
				array(
					'client_secret'                  => 'secret-0123456789',
					OAuthTokenManager::ACCESS_TOKEN  => 'old-access-' . $id,
					OAuthTokenManager::REFRESH_TOKEN => 'rt-' . $id,
					OAuthTokenManager::EXPIRES_AT    => (string) ( $now + $expiresIn ),
					OAuthTokenManager::LIFETIME      => '1800',
				),
				$id
			);
		}
	}

	/**
	 * Refresh tokens the token endpoint received, in order.
	 *
	 * @return array<int,string>
	 */
	private function refreshed(): array {
		$tokens = array();
		foreach ( $this->kit->http->matching( '#/oauth/token$#' ) as $request ) {
			parse_str( (string) $request['body'], $fields );
			$tokens[] = (string) ( $fields['refresh_token'] ?? '' );
		}
		return $tokens;
	}

	public function test_connections_that_are_not_due_do_not_use_up_the_limit_of_a_run(): void {
		$this->connections( 1, 60, 1800 ); // Thirty minutes left: not due.
		$this->connections( 61, 30, 120 );   // Two minutes left: due.
		$this->kit->http->route( 'POST', '#/oauth/token$#', fn() => FakeHttpClient::json( array( 'access_token' => 'new-' . $this->kit->http->count(), 'expires_in' => 1800 ) ) );

		$first = $this->task()->run();
		$this->assertSame( array( 'checked' => 25, 'refreshed' => 25, 'failed' => 0 ), $first, 'Sixty fresh connections come first in the list and cost nothing.' );
		$second = $this->task()->run();
		$this->assertSame( array( 'checked' => 5, 'refreshed' => 5, 'failed' => 0 ), $second, 'The rest follow in the next run.' );
		$this->assertSame( array( 'checked' => 0, 'refreshed' => 0, 'failed' => 0 ), $this->task()->run() );

		$tokens = $this->refreshed();
		$this->assertCount( 30, $tokens );
		$this->assertCount( 30, array_unique( $tokens ), 'Every due connection was refreshed once.' );
		foreach ( $tokens as $token ) {
			$this->assertGreaterThan( 60, (int) substr( $token, 3 ), 'Only the due ones (61 to 90).' );
		}
	}

	public function test_connections_that_keep_failing_do_not_starve_the_others(): void {
		$this->connections( 1, 40, 120 ); // Forty due connections; the endpoint is down.
		$this->kit->http->route( 'POST', '#/oauth/token$#', static fn() => FakeHttpClient::status( 503, 'down' ) );

		$this->assertSame( array( 'checked' => 25, 'refreshed' => 0, 'failed' => 25 ), $this->task()->run() );
		$seen = $this->refreshed();
		$this->kit->clock->advance( RefreshTask::INTERVAL ); // The next run starts one run's worth further down the list.
		$this->assertSame( 25, $this->task()->run()['failed'] );
		$seen = array_merge( $seen, array_slice( $this->refreshed(), count( $seen ) ) );
		$this->assertCount( 40, array_unique( $seen ), 'Two runs reached all forty connections.' );
	}

	public function test_a_short_token_is_due_when_half_of_its_life_is_left(): void {
		$this->connections( 1, 1, 400 );
		$this->kit->storage->rows[1]['secrets'][ OAuthTokenManager::LIFETIME ] = '300'; // A 300 second token with 400 left is a fresh one.
		$this->kit->http->route( 'POST', '#/oauth/token$#', static fn() => FakeHttpClient::json( array( 'access_token' => 'n', 'expires_in' => 300 ) ) );
		$this->assertSame( 0, $this->task()->run()['checked'], '400 seconds left of 300: not due.' );
		$this->kit->storage->rows[1]['secrets'][ OAuthTokenManager::EXPIRES_AT ] = (string) ( $this->kit->clock->now()->getTimestamp() + 140 );
		$this->assertSame( array( 'checked' => 1, 'refreshed' => 1, 'failed' => 0 ), $this->task()->run(), '140 seconds left, under half of 300.' );
	}

	public function test_the_task_does_not_refresh_a_token_the_vendor_would_refuse_for_being_too_young(): void {
		$kit       = $this->kit;
		$connector = GuardedConnector::wrap(
			new class( $kit->deps ) extends ReferenceCrmConnector {
				public function config( Connection $connection ): OAuthConfig {
					return new OAuthConfig( authorizeUrl: 'https://login.acme-crm.test/authorize', tokenUrl: 'https://login.acme-crm.test/oauth/token', allowedHosts: array( 'login.acme-crm.test' ), minTokenAgeSeconds: 570 );
				}
			},
			$kit->tokens
		);
		$registry = new ConnectorRegistry( array( 'reference-crm' => static fn() => $connector ) );
		$resolver = new OAuthResolver( static fn(): ConnectorRegistry => $registry );
		$tokens   = new OAuthTokenManager( $kit->http, $kit->clock, $kit->storage, $kit->lock, static fn( Connection $c ) => $resolver->providerFor( $c ), static function (): void {} );
		$task     = new RefreshTask( $kit->storage, $tokens, $resolver, null, $kit->clock );
		$kit->http->route( 'POST', '#/oauth/token$#', static fn() => FakeHttpClient::json( array( 'access_token' => 'n', 'expires_in' => 600 ) ) );

		$this->connections( 1, 1, 200 ); // 200 seconds left of 600: inside the task's 300 second window, but only 400 seconds old.
		$this->kit->storage->rows[1]['secrets'][ OAuthTokenManager::LIFETIME ] = '600';
		$this->assertSame( array( 'checked' => 0, 'refreshed' => 0, 'failed' => 0 ), $task->run(), 'Too young for this vendor.' );
		$this->assertSame( 0, $kit->http->count() );

		$this->kit->storage->rows[1]['secrets'][ OAuthTokenManager::EXPIRES_AT ] = (string) ( $kit->clock->now()->getTimestamp() + 20 ); // Age 580.
		$this->assertSame( array( 'checked' => 1, 'refreshed' => 1, 'failed' => 0 ), $task->run() );
	}
}
