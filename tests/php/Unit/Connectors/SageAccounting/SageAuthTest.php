<?php
/**
 * Sign-in, business, token refresh and Test connection of the Sage connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageAccounting;

use Estasoft\SyncEngine\Connectors\SageAccounting\SageAccountingConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeSageAccountingServer;

final class SageAuthTest extends SageTestCase {

	private function sageConnector(): SageAccountingConnector {
		$inner = $this->connector->inner();
		$this->assertInstanceOf( SageAccountingConnector::class, $inner );
		return $inner;
	}

	public function test_the_connector_is_a_sign_in_connector_with_sages_endpoints_pkce_and_the_country(): void {
		$this->assertInstanceOf( OAuthConnector::class, $this->sageConnector() );
		$config = $this->sageConnector()->oauthProvider()->config( $this->connection );

		$this->assertSame( 'https://www.sageone.com/oauth2/auth/central', $config->authorizeUrl );
		$this->assertSame( 'https://oauth.accounting.sage.com/token', $config->tokenUrl );
		$this->assertSame( 'authorization_code', $config->grant );
		$this->assertSame( 'body', $config->clientAuth, 'Sage wants the client id and secret in the form body.' );
		$this->assertTrue( $config->usePkce );
		$this->assertSame( array( 'filter' => 'apiv3.1', 'country' => 'gb' ), $config->extraAuthorizeParams );
		$this->assertSame( 'full_access', $config->scopeString() );
		$this->assertSame( 31, $config->refreshTokenLifetimeDays );
		$this->assertSame( 300, $config->accessTokenFallbackSeconds );
		$this->assertTrue( $config->refreshOnDemand, 'Tokens that live five minutes are refreshed by the next call, not by the scheduled task.' );

		$ireland = $this->sageConnector()->oauthProvider()->config( $this->connect( array(), 2, array( 'country' => 'IE' ) ) );
		$this->assertSame( 'ie', $ireland->extraAuthorizeParams['country'] );
		$other = $this->sageConnector()->oauthProvider()->config( $this->connect( array(), 3, array( 'country' => 'us' ) ) );
		$this->assertSame( 'gb', $other->extraAuthorizeParams['country'], 'Only the regions the connector supports.' );
	}

	public function test_the_businesses_come_from_the_list_and_no_business_header_is_needed_for_it(): void {
		$connection = $this->kit->connection( 'sage-accounting', array( 'client_id' => FakeSageAccountingServer::CLIENT_ID, 'country' => 'gb' ), $this->sage->signedIn( $this->kit->clock->now()->getTimestamp() ), 3 );

		$list = $this->sageConnector()->organisations( $connection );

		$this->assertSame(
			array(
				array( 'id' => FakeSageAccountingServer::BUSINESS, 'name' => 'Demo Trading Ltd' ),
				array( 'id' => 'ffffffffffffffffffffffffffffffff', 'name' => 'Other Business' ),
			),
			$list
		);
		$this->assertNull( $this->sage->server->received[0]->header( 'X-Business' ) );
	}

	public function test_choosing_a_business_stores_its_id_and_name(): void {
		$config = $this->sageConnector()->selectOrganisation( $this->connection, FakeSageAccountingServer::BUSINESS );

		$this->assertSame( array( 'business_id' => FakeSageAccountingServer::BUSINESS, 'business_name' => 'Demo Trading Ltd' ), $config );
		$this->assertSame( array( 'business_id' => '' ), $this->sageConnector()->selectOrganisation( $this->connection, 'not a valid id!' ) );
	}

	public function test_an_access_token_that_is_about_to_end_is_refreshed_with_the_apps_secret_in_the_body_and_the_new_pair_is_stored(): void {
		$secrets                     = $this->sage->signedIn( $this->kit->clock->now()->getTimestamp() );
		$secrets['oauth_expires_at'] = (string) ( $this->kit->clock->now()->getTimestamp() + 30 );
		$connection                  = $this->kit->connection( 'sage-accounting', array( 'client_id' => FakeSageAccountingServer::CLIENT_ID, 'business_id' => FakeSageAccountingServer::BUSINESS, 'country' => 'gb' ), $secrets, 5 );
		$oldRefresh                  = $secrets['oauth_refresh_token'];

		$test = $this->connector->test( $connection );

		$this->assertTrue( $test->ok, (string) $test->error?->message() );
		$this->assertCount( 1, $this->sage->tokenRequests, 'One refresh for the whole test, not one per call: the margin follows the token\'s five minute life.' );
		$request = $this->sage->tokenRequests[0];
		$this->assertSame( 'refresh_token', $request['grant_type'] );
		$this->assertSame( $oldRefresh, $request['refresh_token'] );
		$this->assertSame( FakeSageAccountingServer::CLIENT_ID, $request['client_id'] );
		$this->assertSame( FakeSageAccountingServer::CLIENT_SECRET, $request['client_secret'] );
		$this->assertSame( '', $request['_authorization'] );
		$stored = $this->kit->storage->rows[5]['secrets'];
		$this->assertNotSame( $oldRefresh, $stored['oauth_refresh_token'] );
		$this->assertTrue( $this->sage->accessTokens[ $stored['oauth_access_token'] ] );
	}

	public function test_a_refresh_token_sage_refuses_pauses_the_connection_for_a_new_sign_in(): void {
		$secrets                     = $this->sage->signedIn( $this->kit->clock->now()->getTimestamp() );
		$secrets['oauth_expires_at'] = (string) ( $this->kit->clock->now()->getTimestamp() - 10 );
		$this->sage->refreshTokens   = array();
		$connection                  = $this->kit->connection( 'sage-accounting', array( 'client_id' => FakeSageAccountingServer::CLIENT_ID, 'business_id' => FakeSageAccountingServer::BUSINESS, 'country' => 'gb', 'options' => array( 'sales_ledger_account' => '4000' ) ), $secrets, 6 );

		$result = $this->connector->send( $connection, $this->item( '5001' ), $this->payload( '5001' ) );

		$this->assertSame( ErrorCode::Auth002, $result->error->code );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $this->kit->storage->rows[6]['status'] );
		$this->assertNotEmpty( $this->kit->storage->holds );
	}

	public function test_an_expired_access_token_is_replaced_once_when_sage_answers_401(): void {
		$this->sage->accessTokens = array();
		$result                   = $this->connector->send( $this->connection, $this->item( '5002' ), $this->payload( '5002' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->sage->tokenRequests );
	}

	public function test_test_connection_shows_the_business_country_currency_and_tax_rates(): void {
		$result = $this->connector->test( $this->connect( array( 'sales_ledger_account' => '4000', 'payment_bank_account' => 'bank-1200' ) ) );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'Demo Trading Ltd', $result->company );
		$this->assertStringContainsString( '3.1', $result->version );
		$this->assertSame( 'GB', $result->detected['country'] );
		$this->assertSame( 'GBP', $result->detected['currency'] );
		$this->assertSame( 4, $result->detected['tax_rates'] );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertTrue( $result->permissions['sales_ledger_account_found'] );
		$this->assertTrue( $result->permissions['payment_bank_account_found'] );
	}

	public function test_test_connection_says_when_the_ledger_account_or_bank_account_the_options_name_is_missing(): void {
		$result = $this->connector->test( $this->connect( array( 'sales_ledger_account' => '9999', 'payment_bank_account' => 'Nowhere' ) ) );

		$this->assertTrue( $result->ok );
		$this->assertSame( array( 'sales_ledger_account_found', 'payment_bank_account_found' ), $result->missingPermissions() );
	}

	public function test_test_connection_before_a_business_is_chosen_says_so_and_calls_nothing(): void {
		$connection = $this->kit->connection( 'sage-accounting', array( 'client_id' => FakeSageAccountingServer::CLIENT_ID, 'country' => 'gb' ), $this->sage->signedIn( $this->kit->clock->now()->getTimestamp() ), 8 );
		$result     = $this->connector->test( $connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_business_the_user_cannot_use_is_a_permission_error_and_nothing_repeats_a_secret(): void {
		$this->sage->noAccess = true;
		$result               = $this->connector->test( $this->connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );

		$this->sage->noAccess = false;
		$bad  = $this->kit->connection( 'sage-accounting', array( 'client_id' => FakeSageAccountingServer::CLIENT_ID, 'business_id' => FakeSageAccountingServer::BUSINESS, 'country' => 'gb' ), array( 'client_secret' => 'wrong-secret-zzzzzzzz', 'oauth_access_token' => 'stale', 'oauth_refresh_token' => 'stale-refresh', 'oauth_expires_at' => '1' ), 10 );
		$test = $this->connector->test( $bad );
		$text = $test->error?->message() . json_encode( $test->error?->context ) . $test->error?->detail;
		$this->assertStringNotContainsString( 'wrong-secret-zzzzzzzz', $text );
		$this->assertSame( ErrorCode::Auth001, $test->error->code, 'The app\'s secret is wrong: a person must fix the credentials.' );
		$this->assertStringNotContainsString( FakeSageAccountingServer::CLIENT_SECRET, (string) json_encode( array( $test->version, $test->company, $test->permissions, $test->detected ) ) );
	}
}
