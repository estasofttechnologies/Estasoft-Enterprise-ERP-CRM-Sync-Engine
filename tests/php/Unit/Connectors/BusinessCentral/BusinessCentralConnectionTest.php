<?php
/**
 * Connecting to Business Central: Test connection, tokens, errors and pacing.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\BusinessCentral;

use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeBusinessCentralServer;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class BusinessCentralConnectionTest extends BusinessCentralTestCase {

	public function test_it_says_who_it_is_and_flags_only_what_it_does(): void {
		$this->assertSame( 'business-central', $this->connector->id() );
		$flags = $this->connector->capabilities();
		foreach ( array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::InvoiceReadback, Capability::Reconciliation, Capability::ShadowLookup ) as $on ) {
			$this->assertTrue( $flags[ $on->value ], $on->value );
		}
		foreach ( array( Capability::StockIn, Capability::TrackingIn, Capability::PricesIn, Capability::Webhooks ) as $off ) {
			$this->assertFalse( $flags[ $off->value ], $off->value . ' has no documented source.' );
		}
		$this->assertEquals( array( EntityType::Order, EntityType::Refund, EntityType::Customer ), $this->connector->supportedEntities() );
	}

	public function test_test_connection_shows_the_company_version_permissions_and_tax_groups(): void {
		$result = $this->connector->test( $this->connection );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'CRONUS International Ltd.', $result->company );
		$this->assertSame( 'Business Central 21.2.49946.51685', $result->version );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertSame( FakeBusinessCentralServer::COMPANY_ID, $result->detected['company_id'] );
		$this->assertSame( 2, $result->detected['tax_groups'] );
	}

	public function test_the_token_is_asked_for_once_and_reused(): void {
		$this->connector->test( $this->connection );
		$this->connector->lookupProduct( $this->connection, 'CS-MUG-01' );

		$this->assertCount( 1, $this->bc->entra->requests );
		$request = $this->bc->entra->requests[0];
		$this->assertSame( 'client_credentials', $request['grant_type'] );
		$this->assertSame( 'https://api.businesscentral.dynamics.com/.default', $request['scope'] );
		$this->assertSame( FakeBusinessCentralServer::TENANT, $request['tenant'] );
	}

	public function test_a_token_the_service_refuses_is_replaced_once_and_the_call_goes_on(): void {
		$this->connector->lookupProduct( $this->connection, 'CS-MUG-01' );
		$this->bc->entra->revokeAll();

		$ref = $this->connector->lookupProduct( $this->connection, 'CS-MUG-01' );
		$this->assertNotNull( $ref );
		$this->assertGreaterThanOrEqual( 2, count( $this->bc->entra->requests ) );
	}

	public function test_a_wrong_secret_is_auth_001_and_an_expired_one_is_auth_005(): void {
		$wrong = $this->connection( array(), array( 'client_secret' => 'wrong-secret-value' ), 7 );
		$test  = $this->connector->test( $wrong );
		$this->assertFalse( $test->ok );
		$this->assertSame( ErrorCode::Auth001, $test->error->code );

		$this->bc->entra->failNext = 'expired_secret';
		$expired                   = $this->connector->test( $this->connection( array(), array(), 8 ) );
		$this->assertSame( ErrorCode::Auth005, $expired->error->code );
		$this->assertSame( ErrorClass::NeedsPerson, $expired->error->errorClass() );
	}

	public function test_an_app_that_is_not_enabled_in_business_central_is_auth_007(): void {
		// The token is valid for Microsoft but Business Central rejects it.
		$this->kit->http->route( 'GET', '#/companies$#', FakeHttpClient::json( array( 'error' => array( 'code' => 'Unauthorized', 'message' => 'denied' ) ), 401 ) );
		$test = $this->connector->test( $this->connection() );

		$this->assertFalse( $test->ok );
		$this->assertSame( ErrorCode::Auth007, $test->error->code );
	}

	public function test_a_tenant_that_is_not_a_guid_or_domain_never_reaches_an_address(): void {
		$test = $this->connector->test( $this->connection( array( 'tenant_id' => 'x/../evil' ) ) );
		$this->assertFalse( $test->ok );
		$this->assertSame( ErrorCode::Val002, $test->error->code );
		$this->assertSame( array(), $this->kit->http->matching( '#login\.microsoftonline#' ) );
	}

	public function test_an_environment_name_with_odd_characters_is_refused(): void {
		$test = $this->connector->test( $this->connection( array( 'environment' => 'prod/../x' ) ) );
		$this->assertSame( ErrorCode::Val002, $test->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_several_companies_need_a_choice_and_a_name_or_id_picks_one(): void {
		$this->bc->companies[] = array(
			'id'            => 'bbbbbbbb-2222-3333-4444-555555555555',
			'systemVersion' => '21.2',
			'name'          => 'SECOND',
			'displayName'   => 'Second Company',
		);
		$ambiguous = $this->connector->test( $this->connection() );
		$this->assertFalse( $ambiguous->ok );
		$this->assertSame( ErrorCode::Val002, $ambiguous->error->code );
		$this->assertStringContainsString( 'Second Company', $ambiguous->error->message() );

		$byName = $this->connector->test( $this->connection( array( 'company' => 'cronus' ) ) );
		$this->assertTrue( $byName->ok );
		$byId = $this->connector->test( $this->connection( array( 'company' => 'BBBBBBBB-2222-3333-4444-555555555555' ) ) );
		$this->assertSame( 'Second Company', $byId->company );
		$missing = $this->connector->test( $this->connection( array( 'company' => 'nothing' ) ) );
		$this->assertSame( ErrorCode::Val002, $missing->error->code );
	}

	public function test_a_missing_read_permission_is_named_and_not_a_crash(): void {
		$this->kit->http->route( 'GET', '#/customers\?#', FakeHttpClient::json( array( 'error' => array( 'code' => 'Forbidden', 'message' => 'no' ) ), 403 ) );
		$test = $this->connector->test( $this->connection );

		$this->assertFalse( $test->ok );
		$this->assertSame( array( 'read_customers' ), $test->missingPermissions() );
		$this->assertSame( ErrorCode::Auth004, $test->error->code );
	}

	public function test_an_unknown_environment_is_val_002_with_the_field_named(): void {
		$connection = $this->connection( array( 'environment' => 'nowhere' ) );
		$test       = $this->connector->test( $connection );
		$this->assertSame( ErrorCode::Val002, $test->error->code );
		$this->assertStringContainsString( 'environment', $test->error->message() );
	}

	public function test_a_rate_limit_carries_the_wait_and_the_server_error_retries(): void {
		$this->bc->failNextWrite = 'rate_limit';
		$this->bc->retryAfter    = 42;
		$limited                 = $this->connector->send( $this->connection, $this->orderItem( '2001' ), $this->payload() );
		$this->assertSame( ErrorCode::Rate001, $limited->error->code );
		$this->assertSame( 42, $limited->error->retryAfter );

		$this->bc->failNextWrite = 'conflict';
		$conflict                = $this->connector->send( $this->connection, $this->orderItem( '2002' ), $this->payload() );
		$this->assertSame( ErrorCode::Net002, $conflict->error->code );
		$this->assertSame( 409, $conflict->error->httpStatus );

		$this->bc->failNextWrite = 'server_error';
		$server                  = $this->connector->send( $this->connection, $this->orderItem( '2003' ), $this->payload() );
		$this->assertSame( ErrorCode::Net002, $server->error->code );
	}

	public function test_pacing_keeps_to_the_documented_per_minute_cap(): void {
		$hints = $this->connector->rateLimitHints();
		$this->assertSame( 4.0, $hints['requests_per_second'] );
		$this->assertSame( 1, $hints['concurrency'] );
		$this->connector->lookupProduct( $this->connection, 'CS-MUG-01' );
		$this->connector->lookupProduct( $this->connection, 'CS-TEE-02' );
		$this->assertNotEmpty( $this->kit->slept, 'Calls are spaced by the pacer.' );
		$this->assertGreaterThanOrEqual( 200000, max( $this->kit->slept ), 'At most 300 requests a minute: 0.2 s apart.' );
	}

	public function test_secrets_and_the_tenant_never_appear_in_an_error_or_a_log_text(): void {
		$texts = array();
		foreach ( array( 'timeout', 'server_error', 'rate_limit', 'unknown_fault', 'certificate' ) as $index => $kind ) {
			$this->bc->failNextWrite = $kind;
			$result                  = $this->connector->send( $this->connection, $this->orderItem( (string) ( 2100 + $index ) ), $this->payload() );
			$texts[]                 = $result->error->message() . '|' . $result->error->detail . '|' . wp_json_encode( $result->error->context );
		}
		$wrong   = $this->connector->test( $this->connection( array(), array( 'client_secret' => 'wrong-secret-value' ), 9 ) );
		$texts[] = $wrong->error->message() . '|' . $wrong->error->detail . '|' . wp_json_encode( $wrong->error->context );
		$all     = implode( "\n", $texts );
		foreach ( array( FakeBusinessCentralServer::SECRET, 'wrong-secret-value', FakeBusinessCentralServer::TENANT, 'tok-' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $all, $secret );
		}
		$this->assertStringNotContainsString( FakeBusinessCentralServer::SECRET, print_r( $this->connection, true ) );
	}

	public function test_every_request_is_https_to_the_one_host_and_within_thirty_seconds(): void {
		$this->connector->send( $this->connection, $this->orderItem( '2200' ), $this->payload() );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertMatchesRegularExpression( '#^https://(api\.businesscentral\.dynamics\.com|login\.microsoftonline\.com)/#', $request['url'] );
			$this->assertLessThanOrEqual( 30, $request['timeout'] );
		}
	}

	public function test_the_connect_form_marks_the_secret_and_groups_the_fields(): void {
		$names   = array();
		$secrets = array();
		foreach ( $this->connector->credentialFields() as $field ) {
			$this->assertNotContains( $field->name, $names );
			$names[] = $field->name;
			if ( $field->secret ) {
				$secrets[] = $field->name;
			}
		}
		$this->assertSame( array( 'client_secret' ), $secrets );
		$this->assertContains( 'tenant_id', $names );
		$this->assertContains( 'environment', $names );
		$this->assertContains( 'documents', $names );
		$this->assertNotContains( 'redirect_uri', $names, 'Client credentials need no redirect address.' );
	}

	public function test_ping_answers_yes_and_no_without_throwing(): void {
		$this->assertTrue( $this->connector->ping( $this->connection ) );
		$this->assertFalse( $this->connector->ping( $this->connection( array(), array( 'client_secret' => 'wrong-secret-value' ), 10 ) ) );
	}
}
