<?php
/**
 * What the Holded connector says about itself, and the small reads around it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Holded;

use Estasoft\SyncEngine\Connectors\Holded\HoldedConnector;
use Estasoft\SyncEngine\Connectors\Holded\HoldedProvider;
use Estasoft\SyncEngine\Connectors\Support\HostPolicy;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHoldedServer;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class HoldedConnectorMetaTest extends HoldedTestCase {

	public function test_the_provider_builds_the_connector_under_its_id(): void {
		$provider = new HoldedProvider();
		$this->assertSame( 'holded', $provider->id() );
		$this->assertInstanceOf( HoldedConnector::class, $provider->create( $this->kit->deps ) );
	}

	public function test_only_the_flags_the_connector_really_supports_are_true(): void {
		$connector = $this->connector();
		$this->assertSame( ConnectorType::Erp, $connector->type() );
		$flags = array_keys( array_filter( $connector->capabilities() ) );
		sort( $flags );
		$this->assertSame( array( 'customers', 'orders', 'refunds' ), $flags );
		$entities = array_map( static fn( EntityType $type ): string => $type->value, $connector->supportedEntities() );
		sort( $entities );
		$this->assertSame( array( 'customer', 'order', 'refund' ), $entities );
		$hints = $connector->rateLimitHints();
		$this->assertSame( 1.0, $hints['requests_per_second'] );
		$this->assertSame( 1, $hints['concurrency'] );
	}

	public function test_the_connect_form_has_one_secret_and_the_advanced_settings(): void {
		$fields = $this->connector()->credentialFields();
		$names  = array_map( static fn( $field ): string => $field->name, $fields );
		$this->assertSame( array( 'api_key', 'document_type', 'warehouse_id', 'sales_channel_id', 'payment_method_id', 'unknown_sku', 'total_mismatch' ), $names );
		$secrets = array_filter( $fields, static fn( $field ): bool => $field->secret );
		$this->assertCount( 1, $secrets );
		$this->assertSame( 'api_key', array_values( $secrets )[0]->name );
		foreach ( $fields as $field ) {
			if ( 'api_key' !== $field->name ) {
				$this->assertSame( 'advanced', $field->group, $field->name );
				$this->assertFalse( $field->required, $field->name );
			}
		}
	}

	public function test_only_the_holded_host_may_be_contacted(): void {
		$policy = new HostPolicy( array( 'api.holded.com' ) );
		$this->assertNull( $policy->violation( 'https://api.holded.com/api/invoicing/v1/contacts' ) );
		$this->assertNotNull( $policy->violation( 'https://evil.example.test/api/invoicing/v1/contacts' ) );
		$this->assertNotNull( $policy->violation( 'http://api.holded.com/api/invoicing/v1/contacts' ) );
	}

	public function test_test_connection_reports_the_warehouses_and_the_call_quota_with_two_reads_and_no_writes(): void {
		$result = $this->connector()->test( $this->connection( array( 'warehouse_id' => 'w2' ) ) );
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'API v1', $result->version );
		$this->assertSame( 'Holded', $result->company );
		$this->assertSame( array( array( 'id' => 'w1', 'name' => 'Main warehouse' ), array( 'id' => 'w2', 'name' => 'Shop' ) ), $result->warehouses );
		$this->assertSame( 1999, $result->detected['calls_left'] );
		$this->assertSame( 'month', $result->detected['calls_window'] );
		$this->assertTrue( $result->permissions['warehouse_id'] );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertCount( 2, $this->kit->http->requests );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );
	}

	public function test_test_connection_says_when_the_warehouse_is_unknown(): void {
		$result = $this->connector()->test( $this->connection( array( 'warehouse_id' => 'nowhere' ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertContains( 'warehouse_id', $result->missingPermissions() );
	}

	public function test_test_connection_with_a_plan_without_the_api_is_the_plan_error(): void {
		$this->server->apiDisabled = true;
		$result                    = $this->connector()->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth006, $result->error->code );
	}

	public function test_test_connection_with_a_wrong_key_is_auth_001_and_the_key_is_not_in_the_message(): void {
		$result = $this->connector()->test( $this->connection( array(), 'hld-wrong-key-0123456789', 2 ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
		$this->assertStringNotContainsString( 'hld-wrong-key-0123456789', (string) $result->error->message() );
	}

	public function test_tax_codes_are_the_usual_spanish_rates_as_suggestions(): void {
		$this->assertSame(
			array(
				array( 'code' => '21', 'name' => 'VAT 21%', 'rate_percent' => '21.00' ),
				array( 'code' => '10', 'name' => 'VAT 10%', 'rate_percent' => '10.00' ),
				array( 'code' => '4', 'name' => 'VAT 4%', 'rate_percent' => '4.00' ),
				array( 'code' => '0', 'name' => 'VAT 0%', 'rate_percent' => '0.00' ),
			),
			$this->connector()->listTaxCodes( $this->connection() )
		);
		$this->assertCount( 0, $this->kit->http->requests, 'Holded v1 has no tax list to read.' );
	}

	public function test_a_product_is_found_by_sku_whatever_the_case_and_searched_by_name_or_sku(): void {
		$connector = $this->connector();
		$found     = $connector->lookupProduct( $this->connection(), 'cs-tee-02' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'p2', $found->id );
		$this->assertSame( 'CS-TEE-02', $found->ref );
		$this->assertSame( 'p1', $connector->lookupProduct( $this->connection(), 'CS-MUG-01' )->id, 'The longer SKU that contains this one is not taken.' );
		$this->assertNull( $connector->lookupProduct( $this->connection(), 'NOPE' ) );
		$this->assertNull( $connector->lookupProduct( $this->connection(), '' ) );
		$byName = $connector->searchProducts( $this->connection(), 'قميص' );
		$this->assertCount( 1, $byName );
		$this->assertSame( 'p2', $byName[0]->id );
		$this->assertCount( 2, $connector->searchProducts( $this->connection(), 'mug-01' ) );
		$this->assertCount( 0, $connector->searchProducts( $this->connection(), '' ) );
		$this->assertCount( 1, $this->requests( 'GET', '#/products\?page=1#' ), 'One read of the product list for all of this.' );
	}

	public function test_the_customer_lookup_returns_the_name_and_the_email(): void {
		$connector = $this->connector();
		$this->assertNull( $connector->lookupCustomer( $this->connection(), 'nobody@example.test' ) );
		$connector->send( $this->connection(), $this->item(), $this->payload() );
		$found = $connector->lookupCustomer( $this->connection(), 'NURIA@example.com' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'Núria Ibáñez', $found->ref );
		$this->assertSame( 'nuria@example.com', $found->extra['email'] );
	}

	public function test_a_wrong_key_is_auth_001_everywhere(): void {
		$connection = $this->connection( array(), 'hld-wrong-key-0123456789', 2 );
		$connector  = $this->connector();
		foreach ( array(
			$connector->lookupProduct( $connection, 'X' ),
			$connector->lookupCustomer( $connection, 'a@example.test' ),
			$connector->findExisting( $connection, $this->item() ),
		) as $result ) {
			$this->assertInstanceOf( ConnectorError::class, $result );
			$this->assertSame( ErrorCode::Auth001, $result->code );
		}
	}

	public function test_a_list_that_never_ends_stops_with_an_error_instead_of_looping(): void {
		$this->kit->http->route(
			'GET',
			'#/products\?#',
			static function ( array $request ) {
				preg_match( '/page=(\d+)/', $request['url'], $match );
				$page = (int) ( $match[1] ?? 1 );
				return FakeHttpClient::json( array( array( 'id' => 'p' . $page, 'sku' => 'A' . $page, 'name' => 'A' ) ) );
			}
		);
		$result = $this->connector()->lookupProduct( $this->connection(), 'X' );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Val002, $result->code );
		$this->assertLessThanOrEqual( 101, count( $this->kit->http->requests ) );
	}

	public function test_a_server_that_ignores_the_page_number_is_read_once_more_and_then_left(): void {
		$this->server->ignorePage = true;
		$this->server->pageSize   = 2;
		$found                    = $this->connector()->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertCount( 2, $this->kit->http->requests );
	}

	public function test_a_refusal_inside_a_200_stops_a_list_read_with_its_text(): void {
		$this->kit->http->route( 'GET', '#/products\?#', static fn( array $request ) => FakeHttpClient::json( array( 'status' => 0, 'info' => 'No access to products' ) ) );
		$result = $this->connector()->lookupProduct( $this->connection(), 'X' );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( 'No access to products', $result->context['reason'] );
	}

	public function test_the_key_is_never_in_the_url_or_a_message(): void {
		$this->connector()->lookupProduct( $this->connection(), 'CS-MUG-01' );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( FakeHoldedServer::KEY, $request['url'] );
		}
	}
}
