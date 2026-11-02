<?php
/**
 * Sign-in, tenant, token refresh and Test connection of the Xero connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Xero;

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Connectors\Xero\XeroConnector;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeXeroServer;

final class XeroAuthTest extends XeroTestCase {

	private function xeroConnector(): XeroConnector {
		$inner = $this->connector->inner();
		$this->assertInstanceOf( XeroConnector::class, $inner );
		return $inner;
	}

	public function test_the_connector_is_a_sign_in_connector_with_xeros_endpoints_and_granular_scopes(): void {
		$this->assertInstanceOf( OAuthConnector::class, $this->xeroConnector() );
		$config = $this->xeroConnector()->oauthProvider()->config( $this->connection );

		$this->assertSame( 'https://login.xero.com/identity/connect/authorize', $config->authorizeUrl );
		$this->assertSame( 'https://identity.xero.com/connect/token', $config->tokenUrl );
		$this->assertSame( 'authorization_code', $config->grant );
		$this->assertSame( 'basic', $config->clientAuth );
		$this->assertFalse( $config->usePkce );
		$this->assertSame( 60, $config->refreshTokenLifetimeDays );
		$this->assertSame( 'offline_access accounting.invoices accounting.payments accounting.contacts accounting.settings.read', $config->scopeString() );
	}

	public function test_the_organisations_are_the_organisations_not_the_practices(): void {
		$list = $this->xeroConnector()->organisations( $this->connection );

		$this->assertSame( array( array( 'id' => FakeXeroServer::TENANT, 'name' => 'Demo Trading LLC' ) ), $list );
		$this->assertSame( array(), $this->xero->calls( 'GET', '/Organisation' ), 'The list comes from /connections, which needs no tenant.' );
	}

	public function test_choosing_an_organisation_stores_its_id_and_name(): void {
		$config = $this->xeroConnector()->selectOrganisation( $this->kit->unsaved( 'xero', array(), $this->xero->signedIn( $this->kit->clock->now()->getTimestamp() ) ), FakeXeroServer::TENANT );

		$this->assertSame( array( 'tenant_id' => FakeXeroServer::TENANT, 'organisation_name' => 'Demo Trading LLC' ), $config );
	}

	public function test_an_access_token_that_is_about_to_end_is_refreshed_with_the_apps_secret_and_the_new_pair_is_stored(): void {
		$secrets = $this->xero->signedIn( $this->kit->clock->now()->getTimestamp() );
		$secrets['oauth_expires_at'] = (string) ( $this->kit->clock->now()->getTimestamp() + 60 );
		$connection = $this->kit->connection( 'xero', array( 'client_id' => FakeXeroServer::CLIENT_ID, 'tenant_id' => FakeXeroServer::TENANT ), $secrets, 5 );
		$oldRefresh = $secrets['oauth_refresh_token'];

		$test = $this->connector->test( $connection );

		$this->assertTrue( $test->ok, (string) $test->error?->message() );
		$this->assertCount( 1, $this->xero->tokenRequests );
		$request = $this->xero->tokenRequests[0];
		$this->assertSame( 'refresh_token', $request['grant_type'] );
		$this->assertSame( $oldRefresh, $request['refresh_token'] );
		$this->assertSame( 'Basic ' . base64_encode( FakeXeroServer::CLIENT_ID . ':' . FakeXeroServer::CLIENT_SECRET ), $request['_authorization'] );
		$this->assertArrayNotHasKey( 'client_secret', $request, 'The secret travels in the Basic header, not in the body.' );
		$stored = $this->kit->storage->rows[5]['secrets'];
		$this->assertNotSame( $oldRefresh, $stored['oauth_refresh_token'] );
		$this->assertTrue( $this->xero->accessTokens[ $stored['oauth_access_token'] ] );
	}

	public function test_a_refresh_token_xero_refuses_pauses_the_connection_for_a_new_sign_in(): void {
		$secrets = $this->xero->signedIn( $this->kit->clock->now()->getTimestamp() );
		$secrets['oauth_expires_at'] = (string) ( $this->kit->clock->now()->getTimestamp() - 10 );
		$this->xero->refreshTokens = array();
		$connection = $this->kit->connection( 'xero', array( 'client_id' => FakeXeroServer::CLIENT_ID, 'tenant_id' => FakeXeroServer::TENANT ), $secrets, 6 );

		$result = $this->connector->send( $connection, $this->item( '5001' ), $this->payload( '5001' ) );

		$this->assertSame( ErrorCode::Auth002, $result->error->code );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $this->kit->storage->rows[6]['status'] );
		$this->assertNotEmpty( $this->kit->storage->holds );
	}

	public function test_an_expired_access_token_is_replaced_once_when_xero_answers_401(): void {
		$this->xero->accessTokens = array();
		$result                   = $this->connector->send( $this->connection, $this->item( '5002' ), $this->payload( '5002' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 1, count( $this->xero->tokenRequests ) );
	}

	public function test_test_connection_shows_the_organisation_currency_region_and_tax_rates(): void {
		$result = $this->connector->test( $this->connect( array( 'sales_account' => '200', 'payment_account' => '090' ) ) );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'Demo Trading LLC', $result->company );
		$this->assertStringContainsString( '2.0', $result->version );
		$this->assertSame( 'AED', $result->detected['base_currency'] );
		$this->assertSame( 'GLOBAL', $result->detected['region'] );
		$this->assertSame( 4321, $result->detected['daily_calls_left'] );
		$this->assertSame( 3, $result->detected['tax_rates'] );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertArrayHasKey( 'payment_account_exists', $result->permissions );
	}

	public function test_an_account_named_in_the_options_that_xero_does_not_have_is_reported(): void {
		$result = $this->connector->test( $this->connect( array( 'sales_account' => '999' ) ) );

		$this->assertTrue( $result->ok );
		$this->assertSame( array( 'sales_account_exists' ), $result->missingPermissions() );
	}

	public function test_test_connection_before_an_organisation_is_chosen_says_so_and_calls_nothing(): void {
		$connection = $this->kit->connection( 'xero', array( 'client_id' => FakeXeroServer::CLIENT_ID ), $this->xero->signedIn( $this->kit->clock->now()->getTimestamp() ), 8 );
		$result     = $this->connector->test( $connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_tenant_the_signed_in_user_cannot_see_is_a_permission_error(): void {
		$connection = $this->kit->connection( 'xero', array( 'client_id' => FakeXeroServer::CLIENT_ID, 'tenant_id' => '99999999-0000-4000-8000-000000000000' ), $this->xero->signedIn( $this->kit->clock->now()->getTimestamp() ), 9 );
		$result     = $this->connector->test( $connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertStringNotContainsString( '99999999-0000-4000-8000-000000000000', $result->error->message() . json_encode( $result->error->context ) . $result->error->detail, 'The tenant id is removed from messages.' );
	}

	public function test_nothing_the_connector_reports_repeats_the_secret_or_a_token(): void {
		$this->xero->missingScope = false;
		$bad = $this->kit->connection( 'xero', array( 'client_id' => FakeXeroServer::CLIENT_ID, 'tenant_id' => FakeXeroServer::TENANT ), array( 'client_secret' => 'wrong-secret-zzzzzzzz', 'oauth_access_token' => 'stale', 'oauth_refresh_token' => 'stale-refresh', 'oauth_expires_at' => '1' ), 10 );
		$test = $this->connector->test( $bad );

		$text = $test->error?->message() . json_encode( $test->error?->context ) . $test->error?->detail;
		$this->assertStringNotContainsString( 'wrong-secret-zzzzzzzz', $text );
		$this->assertSame( ErrorCode::Auth001, $test->error->code, 'The app\'s secret is wrong: a person must fix the credentials.' );
		$this->assertStringNotContainsString( FakeXeroServer::CLIENT_SECRET, (string) json_encode( array( $test->version, $test->company, $test->permissions, $test->detected ) ) );
	}
}
