<?php
/**
 * Connecting to Dynamics 365 Sales: Test connection, tokens, errors and pacing.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\DynamicsSales;

use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeDynamicsSalesServer;

final class DynamicsSalesConnectionTest extends DynamicsSalesTestCase {

	public function test_it_is_a_crm_that_flags_only_what_it_does(): void {
		$this->assertSame( 'dynamics-sales', $this->connector->id() );
		$this->assertSame( 'crm', $this->connector->type()->value );
		$flags = $this->connector->capabilities();
		foreach ( array( Capability::Orders, Capability::Customers, Capability::Refunds, Capability::Reconciliation, Capability::ShadowLookup ) as $on ) {
			$this->assertTrue( $flags[ $on->value ], $on->value );
		}
		foreach ( array( Capability::StockIn, Capability::TrackingIn, Capability::PricesIn, Capability::InvoiceReadback, Capability::Webhooks ) as $off ) {
			$this->assertFalse( $flags[ $off->value ], $off->value );
		}
		$this->assertEquals( array( EntityType::Order, EntityType::Refund, EntityType::Customer ), $this->connector->supportedEntities() );
	}

	public function test_test_connection_shows_the_organisation_version_and_what_may_be_read(): void {
		$result = $this->connector->test( $this->connection );

		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'Contoso Trading', $result->company );
		$this->assertSame( 'Dataverse 9.2.24101.00123', $result->version );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertNotSame( '', $result->detected['organization_id'] );
	}

	public function test_the_token_is_for_the_environment_and_every_call_carries_the_documented_headers(): void {
		$this->connector->test( $this->connection );
		$this->assertCount( 1, $this->dv->entra->requests );
		$this->assertSame( 'https://contoso.crm4.dynamics.com/.default', $this->dv->entra->requests[0]['scope'] );
		foreach ( $this->kit->http->requests as $request ) {
			if ( str_contains( $request['url'], '/api/data/v9.2/' ) ) {
				$this->assertSame( '4.0', $request['headers']['OData-Version'] );
				$this->assertSame( '4.0', $request['headers']['OData-MaxVersion'] );
				$this->assertSame( 'application/json', $request['headers']['Accept'] );
				$this->assertSame( 'null', $request['headers']['If-None-Match'] );
			}
		}
	}

	public function test_a_token_the_service_refuses_is_replaced_once(): void {
		$this->connector->lookupProduct( $this->connection, 'CS-MUG-01' );
		$this->dv->entra->revokeAll();
		$this->assertNotNull( $this->connector->lookupProduct( $this->connection, 'CS-MUG-01' ) );
		$this->assertCount( 2, $this->dv->entra->requests );
	}

	public function test_wrong_secret_expired_secret_and_missing_application_user_have_their_own_codes(): void {
		$wrong = $this->connector->test( $this->connection( array(), array( 'client_secret' => 'wrong-secret-value' ), 7 ) );
		$this->assertSame( ErrorCode::Auth001, $wrong->error->code );

		$this->dv->entra->failNext = 'expired_secret';
		$expired                   = $this->connector->test( $this->connection( array(), array(), 8 ) );
		$this->assertSame( ErrorCode::Auth005, $expired->error->code );

		// Microsoft accepts the app, the environment has no application user for it.
		$this->kit->http->route( 'GET', '#/WhoAmI$#', \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json( array( 'error' => array( 'code' => '0x80072560', 'message' => 'The user is not a member of the organization.' ) ), 401 ) );
		$noUser = $this->connector->test( $this->connection( array(), array(), 9 ) );
		$this->assertSame( ErrorCode::Auth007, $noUser->error->code );
		$this->assertSame( ErrorClass::NeedsPerson, $noUser->error->errorClass() );
	}

	public function test_an_environment_address_that_is_not_dynamics_never_gets_a_request(): void {
		foreach ( array( 'https://evil.example.com', 'http://contoso.crm4.dynamics.com', 'https://user:pw@contoso.crm4.dynamics.com', '', 'contoso.crm4.dynamics.com.evil.test' ) as $address ) {
			$test = $this->connector->test( $this->connection( array( 'environment_url' => $address ) ) );
			$this->assertFalse( $test->ok, $address );
			$this->assertSame( ErrorCode::Val002, $test->error->code, $address );
		}
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_path_and_a_trailing_slash_are_dropped_from_the_environment_address(): void {
		$test = $this->connector->test( $this->connection( array( 'environment_url' => 'https://Contoso.CRM4.dynamics.com/main.aspx?x=1/' ) ) );
		$this->assertTrue( $test->ok, (string) $test->error?->message() );
		$this->assertSame( 'https://contoso.crm4.dynamics.com/.default', $this->dv->entra->requests[0]['scope'] );
		$bare = $this->connector->test( $this->connection( array( 'environment_url' => 'contoso.crm4.dynamics.com' ), array(), 3 ) );
		$this->assertTrue( $bare->ok );
	}

	public function test_a_missing_read_privilege_is_named_and_a_missing_sales_app_is_explained(): void {
		$this->dv->denied = array( 'contacts' => 'read' );
		$test             = $this->connector->test( $this->connection );
		$this->assertFalse( $test->ok );
		$this->assertSame( array( 'read_contacts' ), $test->missingPermissions() );
		$this->assertSame( ErrorCode::Auth004, $test->error->code );

		$this->dv->denied         = array();
		$this->dv->salesInstalled = false;
		$none                     = $this->connector->test( $this->connection( array(), array(), 4 ) );
		$this->assertSame( ErrorCode::Val002, $none->error->code );
		$this->assertStringContainsString( 'not seem to be installed', $none->error->message() );
	}

	public function test_a_rate_limit_carries_the_wait_and_server_errors_retry(): void {
		$this->dv->failNextWrite = 'rate_limit';
		$this->dv->retryAfter    = 45;
		$limited                 = $this->connector->send( $this->connection, $this->item( '2001' ), $this->payload() );
		$this->assertSame( ErrorCode::Rate001, $limited->error->code );
		$this->assertSame( 45, $limited->error->retryAfter );
		$this->assertSame( ErrorClass::Retry, $limited->error->errorClass() );

		$this->dv->failNextWrite = 'server_error';
		$server                  = $this->connector->send( $this->connection, $this->item( '2002' ), $this->payload() );
		$this->assertSame( ErrorCode::Net002, $server->error->code );
	}

	public function test_secrets_and_the_tenant_never_appear_in_an_error(): void {
		$texts = array();
		foreach ( array( 'timeout', 'server_error', 'rate_limit', 'unknown_fault', 'certificate' ) as $index => $kind ) {
			$this->dv->failNextWrite = $kind;
			$result                  = $this->connector->send( $this->connection, $this->item( (string) ( 2100 + $index ) ), $this->payload() );
			$texts[]                 = $result->error->message() . '|' . $result->error->detail . '|' . wp_json_encode( $result->error->context );
		}
		$wrong   = $this->connector->test( $this->connection( array(), array( 'client_secret' => 'wrong-secret-value' ), 9 ) );
		$texts[] = $wrong->error->message() . '|' . $wrong->error->detail . '|' . wp_json_encode( $wrong->error->context );
		foreach ( array( FakeDynamicsSalesServer::SECRET, 'wrong-secret-value', FakeDynamicsSalesServer::TENANT, 'tok-', 'contoso' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, implode( "\n", $texts ), $secret );
		}
	}

	public function test_the_connect_form_has_the_environment_and_a_secret_and_no_redirect_address(): void {
		$names   = array();
		$secrets = array();
		foreach ( $this->connector->credentialFields() as $field ) {
			$names[] = $field->name;
			if ( $field->secret ) {
				$secrets[] = $field->name;
			}
		}
		$this->assertSame( array( 'client_secret' ), $secrets );
		$this->assertContains( 'environment_url', $names );
		$this->assertContains( 'tenant_id', $names );
		$this->assertNotContains( 'redirect_uri', $names );
		$this->assertSame( 4.0, $this->connector->rateLimitHints()['requests_per_second'] );
	}

	public function test_ping_answers_yes_and_no(): void {
		$this->assertTrue( $this->connector->ping( $this->connection ) );
		$this->assertFalse( $this->connector->ping( $this->connection( array(), array( 'client_secret' => 'wrong-secret-value' ), 10 ) ) );
	}
}
