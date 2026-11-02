<?php
/**
 * Sign-in, token refresh and reconnect, shown with the reference OAuth connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Connectors\Support\GuardedSignInConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthRedirect;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthResolver;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Connectors\Support\OAuth\RefreshTask;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Rest\OAuth\SignInConnector;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceCrmConnector;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceCrmVendor;
use PHPUnit\Framework\TestCase;

final class ReferenceCrmOAuthTest extends TestCase {

	private const SECRET = 'crm-client-secret-0123456789';

	private ApiTestKit $kit;

	private ReferenceCrmVendor $vendor;

	private ReferenceCrmConnector $raw;

	private GuardedConnector $connector;

	protected function setUp(): void {
		$this->kit       = ApiTestKit::make();
		$this->vendor    = new ReferenceCrmVendor( $this->kit->http, 1800 );
		$this->raw       = new ReferenceCrmConnector( $this->kit->deps );
		$this->connector = GuardedConnector::wrap( $this->raw, $this->kit->tokens );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	private function waiting(): Connection {
		return $this->kit->connection( 'reference-crm', array( 'client_id' => 'crm-client-1' ), array( 'client_secret' => self::SECRET ), 1, ConnectionStatus::NeedsReconnect );
	}

	private function item( string $id ): Item {
		return new Item( (int) $id, 1, EntityType::Order, $id, 'created', 'order:' . $id, 'k-' . $id, ItemState::Syncing );
	}

	private function deal( string $id ): array {
		return array( 'deal' => array( 'title' => '#' . $id, 'amount' => 12345 ) );
	}

	/**
	 * Sign in: trade the vendor's code, choose the organisation, activate. Returns the connection as stored afterwards.
	 */
	private function signedIn(): Connection {
		$connection = $this->waiting();
		$result     = $this->connector->completeSignIn( $connection, 'good-code', OAuthRedirect::uri(), 'verifier-1' ); // @phpstan-ignore-line -- the guarded connector is a sign-in connector.
		$this->assertTrue( $result );
		$this->kit->storage->rows[1]['config']['tenant_id'] = 'tenant-7';
		$this->kit->storage->rows[1]['status']              = ConnectionStatus::Active;
		return $this->kit->storage->load( 1 );
	}

	public function test_the_registry_connector_is_a_sign_in_connector_and_the_redirect_address_is_one_stable_address(): void {
		$this->assertInstanceOf( GuardedSignInConnector::class, $this->connector );
		$this->assertInstanceOf( SignInConnector::class, $this->connector );
		$this->assertSame( 'https://shop.example.test/wp-json/estasoft-sync/v1/oauth/callback', OAuthRedirect::uri() );
		$this->assertSame( 'https://shop.example.test/wp-json/estasoft-sync/v1/connections/7/oauth/callback', OAuthRedirect::uriFor( 7 ) );
		$fields = $this->connector->credentialFields();
		$this->assertSame( array( 'client_id', 'client_secret', 'redirect_uri' ), array_map( static fn( $f ) => $f->name, $fields ) );
		$this->assertSame( OAuthRedirect::uri(), $fields[2]->default );
		$this->assertSame( 'display', $fields[2]->type );
	}

	public function test_the_sign_in_address_carries_state_pkce_scopes_and_the_vendors_extra_parameters(): void {
		$url = $this->connector->authorizeUrl( $this->waiting(), 'state-abc-123', OAuthRedirect::uri(), 'challenge-xyz' );
		$this->assertStringStartsWith( 'https://login.acme-crm.test/authorize?', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'crm-client-1', $query['client_id'] );
		$this->assertSame( OAuthRedirect::uri(), $query['redirect_uri'] );
		$this->assertSame( 'state-abc-123', $query['state'] );
		$this->assertSame( 'challenge-xyz', $query['code_challenge'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertSame( 'deals.write contacts.read offline_access', $query['scope'] );
		$this->assertSame( 'offline', $query['access_type'] );
		$this->assertStringNotContainsString( self::SECRET, $url );
	}

	public function test_completing_the_sign_in_stores_encrypted_token_fields_and_lists_the_organisations(): void {
		$connection = $this->signedIn();
		$this->assertNotSame( '', $connection->secret( OAuthTokenManager::ACCESS_TOKEN ) );
		$this->assertNotSame( '', $connection->secret( OAuthTokenManager::REFRESH_TOKEN ) );
		$this->assertSame( $this->kit->clock->now()->getTimestamp() + 60 * 86400, $this->kit->storage->rows[1]['credential_expires_at'] );

		$organisations = $this->connector->organisations( $connection );
		$this->assertSame( array( array( 'id' => 'tenant-7', 'name' => 'Acme Trading LLC' ), array( 'id' => 'tenant-8', 'name' => 'Acme Retail' ) ), $organisations );
		$this->assertSame( array( 'tenant_id' => 'tenant-8' ), $this->raw->selectOrganisation( $connection, 'tenant-8' ) );
	}

	public function test_a_wrong_code_gives_an_error_not_an_exception(): void {
		$result = $this->connector->completeSignIn( $this->waiting(), 'stale-code', OAuthRedirect::uri(), 'v' );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Auth003, $result->code );
		$this->assertSame( 'Acme CRM', $result->context['system'] );
	}

	public function test_test_connection_and_create_order_use_the_bearer_and_the_tenant(): void {
		$connection = $this->signedIn();
		$test       = $this->connector->test( $connection );
		$this->assertTrue( $test->ok, (string) $test->error?->message() );
		$this->assertSame( 'Acme Trading LLC', $test->company );

		$result = $this->connector->send( $connection, $this->item( '11' ), $this->deal( '11' ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$request = $this->vendor->server->requests( 'POST', '#^/v1/deals$#' )[0];
		$this->assertStringStartsWith( 'Bearer access-', (string) $request->header( 'Authorization' ) );
		$this->assertSame( 'tenant-7', $request->header( 'X-Tenant' ) );
		$this->assertSame( '123.45', $request->json()['amount'] );
		$this->assertSame( 'WC-11', $request->json()['external_id'] );
	}

	public function test_find_before_create_never_duplicates_a_deal(): void {
		$connection = $this->signedIn();
		$first      = $this->connector->send( $connection, $this->item( '12' ), $this->deal( '12' ) );
		$second     = $this->connector->send( $connection, $this->item( '12' ), $this->deal( '12' ) );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->vendor->deals );
	}

	public function test_an_access_token_that_is_about_to_expire_is_refreshed_once_before_the_call(): void {
		$connection = $this->signedIn();
		$issued     = $this->vendor->tokens->issued;
		$this->kit->clock->advance( 1800 - 200 ); // Less than five minutes left.

		$result = $this->connector->send( $this->kit->storage->load( 1 ), $this->item( '13' ), $this->deal( '13' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( $issued + 1, $this->vendor->tokens->issued, 'One refresh.' );
		$refresh = end( $this->vendor->tokens->requests );
		$this->assertSame( 'refresh_token', $refresh['grant_type'] );
		unset( $connection );
	}

	public function test_a_401_in_the_middle_of_a_token_s_life_refreshes_once_and_repeats_the_call(): void {
		$connection = $this->signedIn();
		$this->vendor->tokens->expireAccessTokens(); // The vendor dropped the token early.
		$issued = $this->vendor->tokens->issued;

		$result = $this->connector->send( $connection, $this->item( '14' ), $this->deal( '14' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( $issued + 1, $this->vendor->tokens->issued );
		$this->assertCount( 2, $this->vendor->server->requests( 'GET', '#^/v1/deals$#' ), 'The search went out twice: before and after the refresh.' );
	}

	public function test_a_revoked_refresh_token_is_auth_002_and_the_connection_needs_reconnect(): void {
		$connection = $this->signedIn();
		$this->vendor->tokens->expireAccessTokens();
		$this->vendor->tokens->refreshTokens = array();

		$result = $this->connector->send( $connection, $this->item( '15' ), $this->deal( '15' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Auth002, $result->error->code );
		$this->assertSame( 'Acme CRM', $result->error->context['system'] );
		$this->assertStringContainsString( 'Acme CRM', $result->error->message() );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $this->kit->storage->status( 1 ) );
		$this->assertSame( array( array( 1, 'SYNC-AUTH-002' ) ), $this->kit->storage->holds );
		$this->assertSame( array(), $this->vendor->deals );
	}

	public function test_a_rate_limit_from_the_api_keeps_the_vendors_wait(): void {
		$connection = $this->signedIn();
		$this->vendor->server->failNext( 'POST', '/v1/deals', FakeHttpClient::tooManyRequests( 55 ) );
		$result = $this->connector->send( $connection, $this->item( '16' ), $this->deal( '16' ) );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 55, $result->error->retryAfter );
		$this->assertSame( ConnectionStatus::Active, $this->kit->storage->status( 1 ), 'A rate limit is not a reason to hold the connection.' );
	}

	public function test_no_token_or_secret_ever_reaches_an_error_a_log_line_or_the_printed_connection(): void {
		$connection = $this->signedIn();
		$token      = $connection->secret( OAuthTokenManager::ACCESS_TOKEN );
		$refresh    = $connection->secret( OAuthTokenManager::REFRESH_TOKEN );
		$this->vendor->server->failNext( 'GET', '/v1/me', FakeHttpClient::status( 400, '{"message":"Bad bearer ' . $token . ' secret ' . self::SECRET . ' refresh ' . $refresh . '"}' ) );
		$test = $this->connector->test( $connection );
		$text = $test->error->message() . '|' . $test->error->detail . '|' . wp_json_encode( $test->error->context ) . '|' . print_r( $connection, true );
		foreach ( array( $token, $refresh, self::SECRET ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $text );
		}
	}

	public function test_a_token_address_on_another_host_is_refused(): void {
		$connector = new class( $this->kit->deps ) extends \Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceCrmConnector {
			public function config( Connection $connection ): \Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig {
				return new \Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig( authorizeUrl: 'https://evil.test/a', tokenUrl: 'https://evil.test/token', allowedHosts: array( 'login.acme-crm.test' ) );
			}
		};
		$result = GuardedConnector::wrap( $connector, $this->kit->tokens )->completeSignIn( $this->waiting(), 'good-code', OAuthRedirect::uri(), 'v' );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Val002, $result->code );
		$this->assertSame( 0, count( $this->vendor->tokens->requests ) );
	}

	/* ------------------------------------------------------------ scheduled refresh */

	private function task(): RefreshTask {
		$connector = $this->connector;
		$registry  = new ConnectorRegistry( array( 'reference-crm' => static fn() => $connector ) );
		$resolver  = new OAuthResolver( static fn(): ConnectorRegistry => $registry );
		$tokens    = new OAuthTokenManager( $this->kit->http, $this->kit->clock, $this->kit->storage, $this->kit->lock, static fn( Connection $c ) => $resolver->providerFor( $c ), static function (): void {} );
		return new RefreshTask( $this->kit->storage, $tokens, $resolver );
	}

	public function test_the_scheduled_task_refreshes_tokens_that_expire_within_ten_minutes_and_leaves_the_rest(): void {
		$this->signedIn();
		$issued = $this->vendor->tokens->issued;
		$this->assertSame( array( 'checked' => 0, 'refreshed' => 0, 'failed' => 0 ), $this->task()->run(), 'Thirty minutes left: not due, so not counted.' );

		$this->kit->clock->advance( 1800 - 550 ); // 9 minutes 10 seconds left.
		$this->assertSame( array( 'checked' => 1, 'refreshed' => 1, 'failed' => 0 ), $this->task()->run() );
		$this->assertSame( $issued + 1, $this->vendor->tokens->issued );
		$this->assertSame( 'refresh_token', end( $this->vendor->tokens->requests )['grant_type'] );
		$this->assertSame( array( 'checked' => 0, 'refreshed' => 0, 'failed' => 0 ), $this->task()->run(), 'Fresh again.' );
	}

	public function test_the_scheduled_task_marks_a_revoked_connection_as_needing_reconnect_and_skips_connections_that_are_not_oauth(): void {
		$this->signedIn();
		$this->kit->connection( 'reference-books', array(), array( 'api_key' => 'k-123456' ), 2 );
		$this->kit->clock->advance( 1700 );
		$this->vendor->tokens->refreshTokens = array();

		$report = $this->task()->run();

		$this->assertSame( array( 'checked' => 1, 'refreshed' => 0, 'failed' => 1 ), $report );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $this->kit->storage->status( 1 ) );
		$this->assertSame( ConnectionStatus::Active, $this->kit->storage->status( 2 ) );
		$this->assertSame( array( array( 1, 'SYNC-AUTH-002' ) ), $this->kit->storage->holds );
		$this->assertSame( array( 'checked' => 0, 'refreshed' => 0, 'failed' => 0 ), $this->task()->run(), 'A held connection is not tried again.' );
	}

	public function test_a_connection_that_never_signed_in_is_not_touched_by_the_task(): void {
		$this->kit->connection( 'reference-crm', array( 'client_id' => 'x' ), array( 'client_secret' => self::SECRET ), 1 );
		$this->assertSame( array( 'checked' => 0, 'refreshed' => 0, 'failed' => 0 ), $this->task()->run() );
		$this->assertSame( 0, $this->vendor->tokens->issued );
	}

	public function test_a_vendor_with_short_lived_tokens_is_left_to_the_next_call_but_its_refresh_token_is_kept_alive(): void {
		$connector = new class( $this->kit->deps ) extends ReferenceCrmConnector {
			public function config( Connection $connection ): \Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig {
				return new \Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig( authorizeUrl: 'https://login.acme-crm.test/authorize', tokenUrl: 'https://login.acme-crm.test/oauth/token', refreshOnDemand: true, allowedHosts: array( 'login.acme-crm.test' ) );
			}
		};
		$wrapped  = GuardedConnector::wrap( $connector, $this->kit->tokens );
		$registry = new ConnectorRegistry( array( 'reference-crm' => static fn() => $wrapped ) );
		$resolver = new OAuthResolver( static fn(): ConnectorRegistry => $registry );
		$task     = new RefreshTask( $this->kit->storage, $this->kit->tokens, $resolver, null, $this->kit->clock );

		$this->signedIn();
		$this->kit->clock->advance( 1700 ); // The 5 minute window would refresh a normal connector now.
		$this->assertSame( array( 'checked' => 0, 'refreshed' => 0, 'failed' => 0 ), $task->run(), 'The refresh token has weeks left: nothing to do.' );

		$this->kit->clock->advance( 54 * 86400 ); // Less than a week of the 60 days left.
		$report = $task->run();
		$this->assertSame( 1, $report['refreshed'] );
	}
}
