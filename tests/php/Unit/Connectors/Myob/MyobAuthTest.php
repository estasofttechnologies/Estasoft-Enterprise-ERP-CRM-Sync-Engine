<?php
/**
 * Sign-in, company file, token refresh and Test connection of the MYOB connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Myob;

use Estasoft\SyncEngine\Connectors\Myob\MyobConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeMyobServer;

final class MyobAuthTest extends MyobTestCase {

	private function myobConnector(): MyobConnector {
		$inner = $this->connector->inner();
		$this->assertInstanceOf( MyobConnector::class, $inner );
		return $inner;
	}

	public function test_the_connector_is_a_sign_in_connector_with_myobs_endpoints_scopes_and_the_consent_prompt(): void {
		$this->assertInstanceOf( OAuthConnector::class, $this->myobConnector() );
		$config = $this->myobConnector()->oauthProvider()->config( $this->connection );

		$this->assertSame( 'https://secure.myob.com/oauth2/account/authorize', $config->authorizeUrl );
		$this->assertSame( 'https://secure.myob.com/oauth2/v1/authorize', $config->tokenUrl );
		$this->assertSame( 'authorization_code', $config->grant );
		$this->assertSame( 'body', $config->clientAuth, 'MYOB wants the client id and secret in the form body.' );
		$this->assertFalse( $config->usePkce );
		$this->assertSame( array( 'prompt' => 'consent' ), $config->extraAuthorizeParams, 'Without it MYOB does not name the company file in the redirect.' );
		$this->assertSame( 'sme-company-file sme-general-ledger sme-sales sme-inventory sme-contacts-customer', $config->scopeString() );
		$this->assertSame( array( 'scope' => $config->scopeString() ), $config->extraTokenParams );
	}

	public function test_the_company_files_come_from_the_list_and_the_one_in_the_redirect_comes_first(): void {
		$connection = $this->kit->connection( 'myob', array( 'client_id' => FakeMyobServer::CLIENT_ID, 'oauth_callback' => array( 'businessId' => '11111111-2222-4333-8444-555555555555' ) ), $this->myob->signedIn( $this->kit->clock->now()->getTimestamp() ), 3 );

		$list = $this->myobConnector()->organisations( $connection );

		$this->assertSame(
			array(
				array( 'id' => '11111111-2222-4333-8444-555555555555', 'name' => 'Other Company' ),
				array( 'id' => FakeMyobServer::FILE, 'name' => 'Demo Trading Pty Ltd' ),
			),
			$list
		);
		$this->assertSame( 'https://api.myob.com/accountright/', $this->myob->server->received[0]->url );
	}

	public function test_choosing_a_company_file_stores_its_id_and_name(): void {
		$config = $this->myobConnector()->selectOrganisation( $this->connection, strtoupper( FakeMyobServer::FILE ) );

		$this->assertSame( array( 'company_file_id' => FakeMyobServer::FILE, 'company_file_name' => 'Demo Trading Pty Ltd' ), $config );
		$this->assertSame( array( 'company_file_id' => '' ), $this->myobConnector()->selectOrganisation( $this->connection, 'not-a-guid' ) );
	}

	public function test_the_company_file_id_in_the_sign_in_callback_is_used_when_none_is_stored(): void {
		$connection = $this->kit->connection(
			'myob',
			array(
				'client_id'      => FakeMyobServer::CLIENT_ID,
				'currency'       => 'AUD',
				'oauth_callback' => array( 'businessId' => FakeMyobServer::FILE ),
			),
			$this->myob->signedIn( $this->kit->clock->now()->getTimestamp() ),
			4
		);

		$test = $this->connector->test( $connection );

		$this->assertTrue( $test->ok, (string) $test->error?->message() );
		$this->assertSame( 'Demo Trading Pty Ltd', $test->company );
	}

	public function test_an_access_token_that_is_about_to_end_is_refreshed_with_the_key_and_secret_in_the_body_and_the_new_pair_is_stored(): void {
		$secrets                     = $this->myob->signedIn( $this->kit->clock->now()->getTimestamp() );
		$secrets['oauth_expires_at'] = (string) ( $this->kit->clock->now()->getTimestamp() + 60 );
		$connection                  = $this->kit->connection( 'myob', array( 'client_id' => FakeMyobServer::CLIENT_ID, 'company_file_id' => FakeMyobServer::FILE ), $secrets, 5 );
		$oldRefresh                  = $secrets['oauth_refresh_token'];

		$test = $this->connector->test( $connection );

		$this->assertTrue( $test->ok, (string) $test->error?->message() );
		$this->assertCount( 1, $this->myob->tokenRequests );
		$request = $this->myob->tokenRequests[0];
		$this->assertSame( 'refresh_token', $request['grant_type'] );
		$this->assertSame( $oldRefresh, $request['refresh_token'] );
		$this->assertSame( FakeMyobServer::CLIENT_ID, $request['client_id'] );
		$this->assertSame( FakeMyobServer::CLIENT_SECRET, $request['client_secret'] );
		$this->assertSame( '', $request['_authorization'], 'No Basic header: MYOB reads the body.' );
		$stored = $this->kit->storage->rows[5]['secrets'];
		$this->assertNotSame( $oldRefresh, $stored['oauth_refresh_token'] );
		$this->assertTrue( $this->myob->accessTokens[ $stored['oauth_access_token'] ] );
	}

	public function test_a_refresh_token_myob_refuses_pauses_the_connection_for_a_new_sign_in(): void {
		$secrets                     = $this->myob->signedIn( $this->kit->clock->now()->getTimestamp() );
		$secrets['oauth_expires_at'] = (string) ( $this->kit->clock->now()->getTimestamp() - 10 );
		$this->myob->refreshTokens   = array();
		$connection                  = $this->kit->connection( 'myob', array( 'client_id' => FakeMyobServer::CLIENT_ID, 'company_file_id' => FakeMyobServer::FILE ), $secrets, 6 );

		$result = $this->connector->send( $connection, $this->item( '5001' ), $this->payload( '5001' ) );

		$this->assertSame( ErrorCode::Auth002, $result->error->code );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $this->kit->storage->rows[6]['status'] );
		$this->assertNotEmpty( $this->kit->storage->holds );
	}

	public function test_an_expired_access_token_is_replaced_once_when_myob_answers_401(): void {
		$this->myob->accessTokens = array();
		$result                   = $this->connector->send( $this->connection, $this->item( '5002' ), $this->payload( '5002' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 1, count( $this->myob->tokenRequests ) );
	}

	public function test_test_connection_shows_the_file_country_currency_and_tax_codes(): void {
		$result = $this->connector->test( $this->connect( array( 'generic_item' => 'WC-GENERAL', 'payment_account' => '1-1110' ) ) );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'Demo Trading Pty Ltd', $result->company );
		$this->assertStringContainsString( '2026.1', $result->version );
		$this->assertSame( 'AU', $result->detected['country'] );
		$this->assertSame( 'AUD', $result->detected['currency'] );
		$this->assertSame( 5, $result->detected['tax_codes'] );
		$this->assertSame( 'api.myob.com', $result->detected['api_host'] );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertTrue( $result->permissions['generic_item_found'] );
		$this->assertTrue( $result->permissions['payment_account_found'] );
	}

	public function test_test_connection_says_when_an_item_or_the_account_the_options_name_is_missing(): void {
		$result = $this->connector->test( $this->connect( array( 'fee_item' => 'No such item', 'payment_account' => '9-9999' ) ) );

		$this->assertTrue( $result->ok );
		$this->assertSame( array( 'fee_item_found', 'payment_account_found' ), $result->missingPermissions() );
	}

	public function test_test_connection_before_a_company_file_is_chosen_says_so_and_calls_nothing(): void {
		$connection = $this->kit->connection( 'myob', array( 'client_id' => FakeMyobServer::CLIENT_ID ), $this->myob->signedIn( $this->kit->clock->now()->getTimestamp() ), 8 );
		$result     = $this->connector->test( $connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_file_login_that_is_wrong_is_a_permission_error_and_neither_name_nor_password_is_repeated(): void {
		$this->myob->fileLogin = FakeMyobServer::FILE_USER . ':' . FakeMyobServer::FILE_PASSWORD;
		$connection            = $this->connect( array(), 9, array( 'company_file_user' => FakeMyobServer::FILE_USER ), array( 'company_file_password' => 'wrong-password-123' ) );

		$result = $this->connector->test( $connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$text = $result->error->message() . json_encode( $result->error->context ) . $result->error->detail;
		$this->assertStringNotContainsString( 'wrong-password-123', $text );
		$this->assertStringNotContainsString( FakeMyobServer::FILE, $text );
	}

	public function test_nothing_the_connector_reports_repeats_the_secret_or_a_token(): void {
		$bad  = $this->kit->connection( 'myob', array( 'client_id' => FakeMyobServer::CLIENT_ID, 'company_file_id' => FakeMyobServer::FILE ), array( 'client_secret' => 'wrong-secret-zzzzzzzz', 'oauth_access_token' => 'stale', 'oauth_refresh_token' => 'stale-refresh', 'oauth_expires_at' => '1' ), 10 );
		$test = $this->connector->test( $bad );

		$text = $test->error?->message() . json_encode( $test->error?->context ) . $test->error?->detail;
		$this->assertStringNotContainsString( 'wrong-secret-zzzzzzzz', $text );
		$this->assertSame( ErrorCode::Auth001, $test->error->code, 'The app\'s secret is wrong: a person must fix the credentials.' );
		$this->assertStringNotContainsString( FakeMyobServer::CLIENT_SECRET, (string) json_encode( array( $test->version, $test->company, $test->permissions, $test->detected ) ) );
	}
}
