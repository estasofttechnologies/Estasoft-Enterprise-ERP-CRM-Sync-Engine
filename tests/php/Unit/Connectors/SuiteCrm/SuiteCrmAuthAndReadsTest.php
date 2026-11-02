<?php
/**
 * Sign-in, the address, error mapping and the test connection of the SuiteCRM connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SuiteCrm;

use Estasoft\SyncEngine\Connectors\SuiteCrm\SuiteCrmAddress;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
use Estasoft\SyncEngine\Tests\Support\FakeSuiteCrmServer;

/**
 * The server is the merchant's own, so the address is typed, the keys are made on it, and a token lives one hour with no refresh token.
 */
final class SuiteCrmAuthAndReadsTest extends SuiteCrmTestCase {

	public function test_the_form_asks_for_the_server_the_version_the_client_and_for_the_password_grant_the_sync_user(): void {
		$fields = $this->connector->credentialFields();
		$names  = array_map( static fn( $f ) => $f->name, $fields );
		$this->assertSame( array( 'address', 'version', 'grant', 'client_id', 'client_secret', 'username', 'password' ), $names );
		$secrets = array_map( static fn( $f ) => $f->name, array_values( array_filter( $fields, static fn( $f ) => $f->secret ) ) );
		$this->assertSame( array( 'client_secret', 'password' ), $secrets );
	}

	public function test_the_client_credentials_grant_gets_one_token_from_the_access_token_path_and_keeps_it_for_the_next_calls(): void {
		$connection = $this->connection();
		list( $a, $pa ) = $this->order( '2001' );
		list( $b, $pb ) = $this->order( '2002' );
		$this->assertTrue( $this->connector->send( $connection, $a, $pa )->isSuccess() );
		$this->assertTrue( $this->connector->send( $this->kit->storage->load( 1 ), $b, $pb )->isSuccess() );
		$this->assertSame( array( 'client_credentials' ), $this->server->grants );
		$request = $this->kit->http->matching( '#/legacy/Api/access_token$#' )[0];
		$this->assertSame( 'POST', $request['method'] );
		parse_str( (string) $request['body'], $form );
		$this->assertSame( FakeSuiteCrmServer::CLIENT_ID, $form['client_id'] );
		$this->assertSame( FakeSuiteCrmServer::CLIENT_SECRET, $form['client_secret'] );
		$this->assertArrayNotHasKey( 'username', $form );
		$this->assertSame( 'application/x-www-form-urlencoded', $request['headers']['Content-Type'] ?? $request['headers']['content-type'] );
	}

	public function test_the_password_grant_sends_the_sync_user_and_a_wrong_password_needs_a_person(): void {
		$config  = array( 'grant' => 'password', 'username' => FakeSuiteCrmServer::USER );
		$secrets = array( 'password' => FakeSuiteCrmServer::PASSWORD );
		list( $item, $payload ) = $this->order( '2003' );
		$this->assertTrue( $this->connector->send( $this->connection( $config, $secrets ), $item, $payload )->isSuccess() );
		$this->assertSame( array( 'password' ), $this->server->grants );
		$request = $this->kit->http->matching( '#access_token$#' )[0];
		parse_str( (string) $request['body'], $form );
		$this->assertSame( 'password', $form['grant_type'] );
		$this->assertSame( FakeSuiteCrmServer::USER, $form['username'] );
		$this->assertSame( FakeSuiteCrmServer::PASSWORD, $form['password'] );
		list( $other, $otherPayload ) = $this->order( '2004' );
		$wrong = $this->connector->send( $this->connection( $config, array( 'password' => 'not-the-password' ), 2 ), $other, $otherPayload );
		$this->assertSame( ErrorCode::Auth001, $wrong->error->code );
		$this->assertSame( ErrorClass::NeedsPerson, $wrong->error->errorClass() );
	}

	public function test_a_wrong_client_secret_is_a_refused_key(): void {
		list( $item, $payload ) = $this->order( '2005' );
		$result = $this->connector->send( $this->connection( array(), array( 'client_secret' => 'wrong-secret-0000' ) ), $item, $payload );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertSame( array(), $this->records( 'Opportunities' ) );
		$test = $this->connector->test( $this->connection( array(), array( 'client_secret' => 'wrong-secret-0000' ), 2 ) );
		$this->assertFalse( $test->ok );
		$this->assertSame( ErrorCode::Auth001, $test->error->code );
	}

	public function test_a_token_that_has_run_out_is_replaced_by_a_new_grant_there_is_no_refresh_token(): void {
		$connection = $this->connection();
		list( $a, $pa ) = $this->order( '2006' );
		$this->connector->send( $connection, $a, $pa );
		$this->kit->clock->advance( 3700 );
		list( $b, $pb ) = $this->order( '2007' );
		$this->assertTrue( $this->connector->send( $this->kit->storage->load( 1 ), $b, $pb )->isSuccess() );
		$this->assertSame( array( 'client_credentials', 'client_credentials' ), $this->server->grants );
		foreach ( $this->kit->http->matching( '#access_token$#' ) as $request ) {
			$this->assertStringNotContainsString( 'refresh_token', (string) $request['body'] );
		}
	}

	public function test_a_token_the_server_no_longer_accepts_is_replaced_once_and_the_call_repeated(): void {
		$connection = $this->connection();
		list( $a, $pa ) = $this->order( '2008' );
		$this->connector->send( $connection, $a, $pa );
		$this->server->accessTokens = array();
		list( $b, $pb ) = $this->order( '2009' );
		$result = $this->connector->send( $this->kit->storage->load( 1 ), $b, $pb );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 2, $this->server->grants );
		$this->assertCount( 2, $this->records( 'Opportunities' ) );
	}

	public function test_a_server_that_keeps_refusing_the_token_is_a_lapsed_sign_in_not_a_loop(): void {
		$this->server->fail( '*', '#V8/#', $this->server->error( 401, 'Unauthorized', 'The resource owner or authorization server denied the request.' ), 50 );
		list( $item, $payload ) = $this->order( '2010' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Auth002, $result->error->code );
		$this->assertLessThanOrEqual( 3, count( $this->server->grants ) );
	}

	public function test_the_errors_of_suitecrm_map_to_the_catalog(): void {
		$cases = array(
			'no right on the module' => array( $this->server->error( 403, 'Forbidden', 'You do not have access to this module' ), ErrorCode::Auth004, ErrorClass::NeedsPerson ),
			'a refused field'        => array( $this->server->error( 400, 'Bad Request', 'Field currency_id is not valid' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'an unprocessable body'  => array( $this->server->error( 422, 'Unprocessable Entity', 'amount must be a number' ), ErrorCode::Val002, ErrorClass::NeedsPerson ),
			'a plain 500'            => array( FakeHttpClient::status( 500, 'oops' ), ErrorCode::Net002, ErrorClass::Retry ),
			'a bad gateway'          => array( FakeHttpClient::status( 502, '' ), ErrorCode::Net002, ErrorClass::Retry ),
			'a time out'             => array( FakeHttpClient::timeout(), ErrorCode::Net001, ErrorClass::Retry ),
			'a certificate'          => array( FakeHttpClient::certificateError(), ErrorCode::Net003, ErrorClass::NeedsPerson ),
		);
		$index = 0;
		foreach ( $cases as $name => $case ) {
			$this->server->failures = array();
			$this->server->fail( 'POST', '#/V8/module\?#', $case[0] );
			list( $item, $payload ) = $this->order( (string) ( 2100 + $index++ ) );
			$result = $this->connector->send( $this->connection(), $item, $payload );
			$this->assertSame( $case[1], $result->error->code, $name );
			$this->assertSame( $case[2], $result->error->errorClass(), $name );
		}
	}

	public function test_a_429_waits_for_the_time_the_server_names_or_a_default(): void {
		$this->server->fail( 'POST', '#/V8/module\?#', FakeHttpClient::tooManyRequests( 9 ) );
		list( $item, $payload ) = $this->order( '2020' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 9, $result->error->retryAfter );
		$this->assertSame( ErrorClass::Retry, $result->error->errorClass() );
		$this->server->fail( 'POST', '#/V8/module\?#', new HttpResponse( 429, '' ) );
		list( $item2, $payload2 ) = $this->order( '2021' );
		$this->assertGreaterThan( 0, $this->connector->send( $this->connection(), $item2, $payload2 )->error->retryAfter );
	}

	public function test_a_server_with_url_rewriting_off_is_told_apart_from_a_missing_record(): void {
		$this->server->rewriteOff = true;
		list( $item, $payload ) = $this->order( '2030' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'address', $result->error->context['field'] );
		$this->assertStringContainsString( 'URL rewriting', $result->error->context['reason'] );
		$test = $this->connector->test( $this->connection( array(), array(), 2 ) );
		$this->assertFalse( $test->ok );
		$this->assertSame( 'address', $test->error->context['field'] );
	}

	public function test_the_address_is_normalised_to_the_api_of_the_version(): void {
		$cases = array(
			'https://crm.example.test'                  => 'https://crm.example.test/legacy/Api',
			'crm.example.test'                          => 'https://crm.example.test/legacy/Api',
			'  https://CRM.example.test/  '             => 'https://crm.example.test/legacy/Api',
			'https://crm.example.test/legacy/Api'       => 'https://crm.example.test/legacy/Api',
			'https://crm.example.test/legacy/Api/V8/'   => 'https://crm.example.test/legacy/Api',
			'https://crm.example.test/crm/'             => 'https://crm.example.test/crm/legacy/Api',
			'https://crm.example.test:8443/crm/Api'     => 'https://crm.example.test:8443/crm/legacy/Api',
		);
		foreach ( $cases as $typed => $expected ) {
			$this->assertSame( $expected, SuiteCrmAddress::base( $this->connection( array( 'address' => $typed ) ) ), $typed );
		}
		$this->assertSame( 'https://crm.example.test/Api', SuiteCrmAddress::base( $this->connection( array( 'address' => 'https://crm.example.test', 'version' => '7' ) ) ) );
	}

	public function test_a_version_7_server_is_used_under_api_without_legacy(): void {
		$this->server->prefix = '/Api';
		list( $item, $payload ) = $this->order( '2040' );
		$result = $this->connector->send( $this->connection( array( 'version' => 'v7' ) ), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertNotEmpty( $this->logged( '#^POST /Api/access_token#' ) );
	}

	public function test_an_empty_address_http_and_servers_inside_the_network_are_refused_before_any_call(): void {
		foreach ( array( '', 'http://crm.example.test', 'https://192.168.1.20', 'https://localhost', 'ftp://crm.example.test' ) as $typed ) {
			$this->kit->http->requests = array();
			list( $item, $payload ) = $this->order( '2050' );
			$result = $this->connector->send( $this->connection( array( 'address' => $typed ) ), $item, $payload );
			$this->assertNotNull( $result->error, '"' . $typed . '" must be refused.' );
			$this->assertContains( $result->error->code, array( ErrorCode::Val002, ErrorCode::Net003 ), $typed );
			$this->assertSame( array(), $this->kit->http->matching( '#.#' ), '"' . $typed . '" must not be called.' );
		}
	}

	public function test_test_connection_reports_the_user_and_the_reads_it_may_do(): void {
		$result = $this->connector->test( $this->connection() );
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'SuiteCRM API v8', $result->version );
		$this->assertSame( 'Sync User', $result->company );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertSame( '/legacy/Api', $result->detected['api_prefix'] );
		$this->assertSame( array(), $this->records( 'Opportunities'), 'The test writes nothing.' );
		$this->assertEmpty( $this->logged( '#^(POST|PATCH|DELETE) /legacy/Api/V8#' ) );
	}

	public function test_test_connection_names_a_module_the_user_may_not_read(): void {
		$this->server->fail( 'GET', '#module/Notes#', $this->server->error( 403, 'Forbidden', 'No access' ) );
		$result = $this->connector->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( array( 'read_notes' ), $result->missingPermissions() );
	}

	public function test_products_are_tested_only_when_the_connection_matches_them(): void {
		$plain = $this->connector->test( $this->connection() );
		$this->assertArrayNotHasKey( 'read_aos_products', $plain->permissions );
		$this->server->fail( 'GET', '#module/AOS_Products#', $this->server->error( 403, 'Forbidden', 'No access' ) );
		$with = $this->connector->test( $this->connection( $this->options( array( 'match_products' => 'yes' ) ), array(), 2 ) );
		$this->assertFalse( $with->ok );
		$this->assertSame( array( 'read_aos_products' ), $with->missingPermissions() );
	}

	public function test_test_connection_warns_about_an_empty_default_currency_and_about_matching_orders_by_the_editable_name(): void {
		$bare = $this->connector->test( $this->connection( array( 'options' => array() ) ) );
		$this->assertTrue( $bare->ok, 'A warning is not a failure.' );
		$text = implode( ' ', $bare->detected['warnings'] );
		$this->assertCount( 2, $bare->detected['warnings'] );
		$this->assertStringContainsString( 'base_currency', $text );
		$this->assertStringContainsString( 'reference_field', $text );
		$this->server->customFields['Opportunities'] = array( 'wc_reference_c' );
		$set = $this->connector->test( $this->connection( $this->options( array( 'reference_field' => 'wc_reference_c' ) ), array(), 2 ) );
		$this->assertTrue( $set->ok, (string) $set->error?->message() );
		$this->assertSame( array(), $set->detected['warnings'], 'Both are set: nothing to warn about.' );
	}

	public function test_test_connection_fails_for_a_reference_field_the_server_does_not_have(): void {
		$result = $this->connector->test( $this->connection( $this->options( array( 'reference_field' => 'wc_reference_c' ) ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'reference_field', $result->error->context['field'] );
		$this->assertStringContainsString( 'wc_reference_c', $result->error->context['reason'] );
		$bad = $this->connector->test( $this->connection( $this->options( array( 'reference_field' => 'a b' ) ), array(), 2 ) );
		$this->assertFalse( $bad->ok );
		$this->assertSame( 'reference_field', $bad->error->context['field'] );
	}

	public function test_ping_is_one_read_of_the_current_user_and_not_the_whole_test_connection(): void {
		$this->assertTrue( $this->connector->ping( $this->connection() ) );
		$this->assertCount( 1, $this->logged( '#^GET /legacy/Api/V8/#' ) );
		$this->assertCount( 1, $this->logged( '#^GET /legacy/Api/V8/current-user$#' ) );
		$this->server->fail( 'GET', '#current-user#', $this->server->error( 500, 'Server Error', 'down' ) );
		$this->assertFalse( $this->connector->ping( $this->connection( array(), array(), 2 ) ) );
		$this->assertFalse( $this->connector->ping( $this->connection( array(), array( 'client_secret' => 'wrong-secret-0000' ), 3 ) ) );
	}

	public function test_the_text_of_an_error_is_cut_and_has_no_secret_token_or_host(): void {
		$this->server->fail( 'POST', '#/V8/module\?#', $this->server->error( 400, 'Bad Request', 'Fault at crm.example.test with ' . FakeSuiteCrmServer::CLIENT_SECRET . ' and crm-access-1 ' . str_repeat( 'x', 400 ) ) );
		list( $item, $payload ) = $this->order( '2060' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$text   = $result->error->message() . '|' . $result->error->detail . '|' . wp_json_encode( $result->error->context );
		$this->assertStringNotContainsString( FakeSuiteCrmServer::CLIENT_SECRET, $text );
		$this->assertStringNotContainsString( 'crm-access-1', $text );
		$this->assertStringNotContainsString( 'crm.example.test', $text );
		$this->assertLessThanOrEqual( 200, mb_strlen( (string) ( $result->error->context['reason'] ?? '' ) ) );
	}

	public function test_the_connector_is_a_crm_that_orders_customers_refunds_and_the_nightly_check_and_keeps_no_stock(): void {
		$this->assertSame( 'suitecrm', $this->connector->id() );
		$this->assertSame( ConnectorType::Crm, $this->connector->type() );
		$on = array_keys( array_filter( $this->connector->capabilities() ) );
		$this->assertEqualsCanonicalizing(
			array( Capability::Orders->value, Capability::Customers->value, Capability::Refunds->value, Capability::Reconciliation->value, Capability::ShadowLookup->value ),
			$on
		);
		$this->assertEqualsCanonicalizing( array( EntityType::Order, EntityType::Refund, EntityType::Customer ), $this->connector->supportedEntities() );
		$this->assertSame( 2.0, $this->connector->rateLimitHints()['requests_per_second'] );
		$this->assertSame( array(), $this->connector->fetchStock( $this->connection(), array( 'CS-MUG-01' ), array() ), 'A CRM has no stock.' );
	}
}
