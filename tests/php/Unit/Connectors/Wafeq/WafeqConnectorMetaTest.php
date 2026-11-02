<?php
/**
 * What the Wafeq connector says about itself, and the small reads around it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Wafeq;

use Estasoft\SyncEngine\Connectors\Support\HostPolicy;
use Estasoft\SyncEngine\Connectors\Wafeq\WafeqConnector;
use Estasoft\SyncEngine\Connectors\Wafeq\WafeqProvider;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class WafeqConnectorMetaTest extends WafeqTestCase {

	public function test_the_provider_builds_the_connector_under_its_id(): void {
		$provider = new WafeqProvider();
		$this->assertSame( 'wafeq', $provider->id() );
		$this->assertInstanceOf( WafeqConnector::class, $provider->create( $this->kit->deps ) );
	}

	public function test_only_the_flags_the_connector_really_supports_are_true(): void {
		$connector = $this->connector();
		$this->assertSame( ConnectorType::Accounting, $connector->type() );
		$flags = array_keys( array_filter( $connector->capabilities() ) );
		sort( $flags );
		$this->assertSame( array( 'customers', 'orders', 'refunds', 'shadow_lookup' ), $flags );
		$entities = array_map( static fn( EntityType $type ): string => $type->value, $connector->supportedEntities() );
		sort( $entities );
		$this->assertSame( array( 'customer', 'order', 'refund' ), $entities );
		$this->assertSame( 1.0, $connector->rateLimitHints()['requests_per_second'] );
		$this->assertSame( 1, $connector->rateLimitHints()['concurrency'] );
	}

	public function test_the_connect_form_has_one_secret_and_the_advanced_settings(): void {
		$fields = $this->connector()->credentialFields();
		$names  = array_map( static fn( $field ): string => $field->name, $fields );
		$this->assertSame( array( 'api_key', 'sales_account', 'invoice_state', 'record_payment', 'payment_account', 'unknown_sku', 'invoice_number_prefix', 'place_of_supply', 'language', 'total_mismatch' ), $names );
		$secrets = array_filter( $fields, static fn( $field ): bool => $field->secret );
		$this->assertCount( 1, $secrets );
		$this->assertSame( 'api_key', array_values( $secrets )[0]->name );
		foreach ( $fields as $field ) {
			if ( 'api_key' !== $field->name ) {
				$this->assertSame( 'advanced', $field->group, $field->name );
				$this->assertFalse( $field->required );
			}
		}
	}

	public function test_only_the_wafeq_host_may_be_contacted(): void {
		$policy = new HostPolicy( array( 'api.wafeq.com' ) );
		$this->assertNull( $policy->violation( 'https://api.wafeq.com/v1/invoices/' ) );
		$this->assertNotNull( $policy->violation( 'https://evil.example.test/v1/invoices/' ) );
		$this->assertNotNull( $policy->violation( 'http://api.wafeq.com/v1/invoices/' ) );
	}

	public function test_test_connection_reports_the_organisation_the_rates_and_the_settings(): void {
		$result = $this->connector()->test( $this->connection( array( 'record_payment' => 'yes', 'payment_account' => 'acc_bank' ) ) );
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'مؤسسة الاختبار', $result->company );
		$this->assertSame( 'API v1', $result->version );
		$this->assertSame( 3, $result->detected['tax_rates'] );
		$this->assertSame( 'SAR', $result->detected['base_currency'] );
		$this->assertTrue( $result->permissions['payment_account'] );
		$this->assertSame( array(), $result->missingPermissions() );
	}

	public function test_test_connection_says_what_is_missing_without_failing_the_key(): void {
		$result = $this->connector()->test( $this->connection( array( 'record_payment' => 'yes', 'payment_account' => 'nowhere', 'sales_account' => 'nowhere' ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertContains( 'sales_account', $result->missingPermissions() );
		$this->assertContains( 'payment_account', $result->missingPermissions() );
		$this->assertTrue( $result->permissions['read_organisation'] );
	}

	public function test_test_connection_with_a_switched_off_api_is_the_plan_error(): void {
		$this->server->apiEnabled = false;
		$result                   = $this->connector()->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Auth006, $result->error->code );
	}

	public function test_tax_codes_are_the_sales_rates_with_their_percentages(): void {
		$this->server->tables['tax-rates']['tax_p'] = array( 'id' => 'tax_p', 'name' => 'Purchases VAT', 'rate' => '15.00', 'tax_type' => 'PURCHASES' );
		$codes = $this->connector()->listTaxCodes( $this->connection() );
		$this->assertSame(
			array(
				array( 'code' => 'tax_15', 'name' => 'VAT 15%', 'rate_percent' => '15.00' ),
				array( 'code' => 'tax_5', 'name' => 'VAT 5%', 'rate_percent' => '5.00' ),
				array( 'code' => 'tax_0', 'name' => 'Zero rated', 'rate_percent' => '0.00' ),
			),
			$codes
		);
		$taxes = $this->connector()->listTaxes( $this->connection() );
		$this->assertSame( 15.0, $taxes[0]['rate_percent'] );
	}

	public function test_a_product_is_found_by_sku_whatever_the_case_and_searched_by_name_or_sku(): void {
		$connector = $this->connector();
		$found     = $connector->lookupProduct( $this->connection(), 'cs-tee-02' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'itm_tee', $found->id );
		$this->assertSame( 'CS-TEE-02', $found->ref );
		$this->assertNull( $connector->lookupProduct( $this->connection(), 'NOPE' ) );
		$this->assertNull( $connector->lookupProduct( $this->connection(), '' ) );
		$byName = $connector->searchProducts( $this->connection(), 'قميص' );
		$this->assertCount( 1, $byName );
		$this->assertSame( 'itm_tee', $byName[0]->id );
		$this->assertCount( 0, $connector->searchProducts( $this->connection(), '' ) );
	}

	public function test_the_item_list_is_read_once_per_run_and_again_after_ten_minutes(): void {
		$connector = $this->connector();
		$connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$connector->lookupProduct( $this->connection(), 'CS-TEE-02' );
		$this->assertCount( 1, $this->requests( 'GET', '#/v1/items/#' ) );
		$this->kit->clock->advance( 601 );
		$connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$this->assertCount( 2, $this->requests( 'GET', '#/v1/items/#' ) );
	}

	public function test_the_customer_lookup_returns_the_name_and_the_email(): void {
		$connector = $this->connector();
		$this->assertNull( $connector->lookupCustomer( $this->connection(), 'nobody@example.test' ) );
		$connector->send( $this->connection(), $this->item(), $this->payload() );
		$found = $connector->lookupCustomer( $this->connection(), 'NOOR@example.com' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( 'نور المنصوري', $found->ref );
		$this->assertSame( 'noor@example.com', $found->extra['email'] );
	}

	public function test_a_wrong_key_is_auth_001_everywhere(): void {
		$connection = $this->connection( array(), 'wfq-wrong-key-0123456789', 2 );
		$connector  = $this->connector();
		foreach ( array(
			$connector->lookupProduct( $connection, 'X' ),
			$connector->lookupCustomer( $connection, 'a@example.test' ),
			$connector->findExisting( $connection, $this->item() ),
			$connector->listTaxes( $connection ),
		) as $result ) {
			$this->assertInstanceOf( ConnectorError::class, $result );
			$this->assertSame( ErrorCode::Auth001, $result->code );
		}
	}

	public function test_a_list_that_never_ends_stops_with_an_error_instead_of_looping(): void {
		$this->kit->http->route(
			'GET',
			'#/v1/items/#',
			static fn( array $request ) => \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json( array( 'count' => 1, 'next' => 'https://api.wafeq.com/v1/items/?page=2', 'previous' => null, 'results' => array() ) )
		);
		$result = $this->connector()->lookupProduct( $this->connection(), 'X' );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Val002, $result->code );
		$this->assertLessThanOrEqual( 201, count( $this->kit->http->requests ) );
	}

	public function test_a_next_link_to_another_host_is_never_followed(): void {
		$this->kit->http->route(
			'GET',
			'#/v1/items/#',
			static fn( array $request ) => \Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json( array( 'count' => 1, 'next' => 'https://attacker.example.test/steal?page=2', 'previous' => null, 'results' => array() ) )
		);
		$result = $this->connector()->lookupProduct( $this->connection(), 'X' );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertCount( 0, array_filter( $this->kit->http->requests, static fn( array $request ): bool => str_contains( $request['url'], 'attacker' ) ) );
	}
}
