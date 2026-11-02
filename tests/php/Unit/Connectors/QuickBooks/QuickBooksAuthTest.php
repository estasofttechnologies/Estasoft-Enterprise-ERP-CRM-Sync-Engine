<?php
/**
 * Sign-in, company, token refresh and Test connection of the QuickBooks connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\QuickBooks;

use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConnector;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeQuickBooksServer;

final class QuickBooksAuthTest extends QuickBooksTestCase {

	private function quickbooks(): QuickBooksConnector {
		$inner = $this->connector->inner();
		$this->assertInstanceOf( QuickBooksConnector::class, $inner );
		return $inner;
	}

	public function test_the_connector_is_a_sign_in_connector_with_intuits_endpoints_and_the_accounting_scope(): void {
		$this->assertInstanceOf( OAuthConnector::class, $this->quickbooks() );
		$config = $this->quickbooks()->oauthProvider()->config( $this->connection );

		$this->assertSame( 'https://appcenter.intuit.com/connect/oauth2', $config->authorizeUrl );
		$this->assertSame( 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', $config->tokenUrl );
		$this->assertSame( 'authorization_code', $config->grant );
		$this->assertSame( 'basic', $config->clientAuth );
		$this->assertFalse( $config->usePkce );
		$this->assertSame( 100, $config->refreshTokenLifetimeDays );
		$this->assertSame( 'com.intuit.quickbooks.accounting', $config->scopeString() );
	}

	public function test_the_company_is_the_one_that_sign_in_named(): void {
		$list = $this->quickbooks()->organisations( $this->connection );

		$this->assertSame( array( array( 'id' => FakeQuickBooksServer::REALM, 'name' => 'Demo Trading Ltd' ) ), $list );
	}

	public function test_choosing_the_company_stores_its_id_and_name(): void {
		$config = $this->quickbooks()->selectOrganisation( $this->connection, FakeQuickBooksServer::REALM );

		$this->assertSame( array( 'realm_id' => FakeQuickBooksServer::REALM, 'company_name' => 'Demo Trading Ltd' ), $config );
	}

	public function test_the_company_id_in_the_sign_in_callback_is_used_when_none_is_stored(): void {
		$connection = $this->kit->connection(
			'quickbooks',
			array(
				'client_id'      => FakeQuickBooksServer::CLIENT_ID,
				'oauth_callback' => array( 'realmId' => FakeQuickBooksServer::REALM ),
			),
			$this->qbo->signedIn( $this->kit->clock->now()->getTimestamp() ),
			4
		);

		$test = $this->connector->test( $connection );

		$this->assertTrue( $test->ok, (string) $test->error?->message() );
		$this->assertSame( 'Demo Trading Ltd', $test->company );
	}

	public function test_an_access_token_that_is_about_to_end_is_refreshed_with_the_apps_secret_and_the_new_pair_is_stored(): void {
		$secrets                     = $this->qbo->signedIn( $this->kit->clock->now()->getTimestamp() );
		$secrets['oauth_expires_at'] = (string) ( $this->kit->clock->now()->getTimestamp() + 60 );
		$connection                  = $this->kit->connection( 'quickbooks', array( 'client_id' => FakeQuickBooksServer::CLIENT_ID, 'realm_id' => FakeQuickBooksServer::REALM ), $secrets, 5 );
		$oldRefresh                  = $secrets['oauth_refresh_token'];

		$test = $this->connector->test( $connection );

		$this->assertTrue( $test->ok, (string) $test->error?->message() );
		$this->assertCount( 1, $this->qbo->tokenRequests );
		$request = $this->qbo->tokenRequests[0];
		$this->assertSame( 'refresh_token', $request['grant_type'] );
		$this->assertSame( $oldRefresh, $request['refresh_token'] );
		$this->assertSame( 'Basic ' . base64_encode( FakeQuickBooksServer::CLIENT_ID . ':' . FakeQuickBooksServer::CLIENT_SECRET ), $request['_authorization'] );
		$this->assertArrayNotHasKey( 'client_secret', $request, 'The secret travels in the Basic header, not in the body.' );
		$stored = $this->kit->storage->rows[5]['secrets'];
		$this->assertNotSame( $oldRefresh, $stored['oauth_refresh_token'], 'Intuit hands out a new refresh token; the old one is replaced.' );
		$this->assertTrue( $this->qbo->accessTokens[ $stored['oauth_access_token'] ] );
	}

	public function test_a_refresh_token_intuit_refuses_pauses_the_connection_for_a_new_sign_in(): void {
		$secrets                     = $this->qbo->signedIn( $this->kit->clock->now()->getTimestamp() );
		$secrets['oauth_expires_at'] = (string) ( $this->kit->clock->now()->getTimestamp() - 10 );
		$this->qbo->refreshTokens    = array();
		$connection                  = $this->kit->connection( 'quickbooks', array( 'client_id' => FakeQuickBooksServer::CLIENT_ID, 'realm_id' => FakeQuickBooksServer::REALM ), $secrets, 6 );

		$result = $this->connector->send( $connection, $this->item( '5001' ), $this->payload( '5001' ) );

		$this->assertSame( ErrorCode::Auth002, $result->error->code );
		$this->assertSame( ConnectionStatus::NeedsReconnect, $this->kit->storage->rows[6]['status'] );
		$this->assertNotEmpty( $this->kit->storage->holds );
	}

	public function test_an_expired_access_token_is_replaced_once_when_quickbooks_answers_401(): void {
		$this->qbo->accessTokens = array();
		$result                  = $this->connector->send( $this->connection, $this->item( '5002' ), $this->payload( '5002' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 1, count( $this->qbo->tokenRequests ) );
	}

	public function test_test_connection_shows_the_company_country_currency_and_tax_codes(): void {
		$result = $this->connector->test( $this->connect( array( 'generic_item' => 'WC-SERVICE' ) ) );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'Demo Trading Ltd', $result->company );
		$this->assertStringContainsString( 'minor version 75', $result->version );
		$this->assertSame( 'GB', $result->detected['country'] );
		$this->assertSame( 'GBP', $result->detected['home_currency'] );
		$this->assertTrue( $result->detected['multi_currency'] );
		$this->assertSame( 5, $result->detected['tax_codes'] );
		$this->assertSame( 75, $result->detected['minor_version'] );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertTrue( $result->permissions['generic_item_found'] );
	}

	public function test_test_connection_says_when_custom_transaction_numbers_is_off_and_when_an_item_is_missing(): void {
		$this->qbo->customNumbers = false;
		$result                   = $this->connector->test( $this->connect( array( 'shipping_item' => 'No such item' ) ) );

		$this->assertTrue( $result->ok );
		$this->assertSame( array( 'custom_transaction_numbers', 'shipping_item_found' ), $result->missingPermissions() );
	}

	public function test_test_connection_before_a_company_is_chosen_says_so_and_calls_nothing(): void {
		$connection = $this->kit->connection( 'quickbooks', array( 'client_id' => FakeQuickBooksServer::CLIENT_ID ), $this->qbo->signedIn( $this->kit->clock->now()->getTimestamp() ), 8 );
		$result     = $this->connector->test( $connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_company_the_signed_in_user_cannot_use_is_a_permission_error_and_the_id_is_removed_from_messages(): void {
		$this->qbo->noAccess = true;
		$result              = $this->connector->test( $this->connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertStringNotContainsString( FakeQuickBooksServer::REALM, $result->error->message() . json_encode( $result->error->context ) . $result->error->detail );
	}

	public function test_an_application_intuit_has_not_authorised_is_its_own_error(): void {
		$this->kit->http->queue( \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json( array( 'Fault' => array( 'Error' => array( array( 'Message' => 'ApplicationAuthorizationFailed', 'code' => '3100' ) ), 'type' => 'SERVICE' ) ), 403 ) );

		$result = $this->connector->test( $this->connection );

		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth007, $result->error->code );
	}

	public function test_nothing_the_connector_reports_repeats_the_secret_or_a_token(): void {
		$bad  = $this->kit->connection( 'quickbooks', array( 'client_id' => FakeQuickBooksServer::CLIENT_ID, 'realm_id' => FakeQuickBooksServer::REALM ), array( 'client_secret' => 'wrong-secret-zzzzzzzz', 'oauth_access_token' => 'stale', 'oauth_refresh_token' => 'stale-refresh', 'oauth_expires_at' => '1' ), 10 );
		$test = $this->connector->test( $bad );

		$text = $test->error?->message() . json_encode( $test->error?->context ) . $test->error?->detail;
		$this->assertStringNotContainsString( 'wrong-secret-zzzzzzzz', $text );
		$this->assertSame( ErrorCode::Auth001, $test->error->code, 'The app\'s secret is wrong: a person must fix the credentials.' );
		$this->assertStringNotContainsString( FakeQuickBooksServer::CLIENT_SECRET, (string) json_encode( array( $test->version, $test->company, $test->permissions, $test->detected ) ) );
	}
}
