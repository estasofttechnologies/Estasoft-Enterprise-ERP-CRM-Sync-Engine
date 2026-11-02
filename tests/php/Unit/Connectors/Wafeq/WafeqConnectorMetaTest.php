<?php
/**
 * What the Wafeq connector says about itself, and the small reads around it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Wafeq;

use Estasoft\SyncEngine\Connectors\Support\HostPolicy;
use Estasoft\SyncEngine\Connectors\Wafeq\WafeqItemIndex;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;
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
		$this->assertSame( array( 'customers', 'invoice_readback', 'orders', 'refunds', 'shadow_lookup' ), $flags );
		$entities = array_map( static fn( EntityType $type ): string => $type->value, $connector->supportedEntities() );
		sort( $entities );
		$this->assertSame( array( 'customer', 'order', 'refund' ), $entities );
		$this->assertSame( 1.0, $connector->rateLimitHints()['requests_per_second'] );
		$this->assertSame( 1, $connector->rateLimitHints()['concurrency'] );
	}

	public function test_the_connect_form_has_one_secret_and_the_advanced_settings(): void {
		$fields = $this->connector()->credentialFields();
		$names  = array_map( static fn( $field ): string => $field->name, $fields );
		$this->assertSame( array( 'api_key', 'sales_account', 'invoice_state', 'record_payment', 'payment_account', 'unknown_sku', 'invoice_number_prefix', 'place_of_supply', 'tax_authority_report', 'language', 'total_mismatch' ), $names );
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

	/**
	 * Fill the fake with items SKU-1 to SKU-N after the three it starts with, so the list has several pages.
	 *
	 * @param int $count How many to add.
	 */
	private function manyItems( int $count ): void {
		$this->server->pageSize = 2;
		for ( $i = 1; $i <= $count; ++$i ) {
			$this->server->tables['items'][ 'itm_' . $i ] = array( 'id' => 'itm_' . $i, 'name' => 'Item ' . $i, 'sku' => 'sku-' . $i, 'external_id' => '', 'is_active' => true );
		}
	}

	public function test_the_item_index_is_built_page_by_page_and_stops_as_soon_as_the_sku_is_found(): void {
		$this->manyItems( 7 );
		$connector = $this->connector();
		$connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$this->assertCount( 1, $this->requests( 'GET', '#/v1/items/#' ), 'The first page holds it.' );
		$found = $connector->lookupProduct( $this->connection(), 'SKU-3' );
		$this->assertSame( 'itm_3', $found->id );
		$this->assertSame( 'sku-3', $found->ref, 'The SKU is shown as Wafeq writes it.' );
		$this->assertCount( 3, $this->requests( 'GET', '#/v1/items/#' ), 'Pages 2 and 3 were read; page 1 was not read again.' );
		$connector->lookupProduct( $this->connection(), 'CS-TEE-02' );
		$connector->lookupProduct( $this->connection(), 'sku-2' );
		$this->assertCount( 3, $this->requests( 'GET', '#/v1/items/#' ), 'SKUs on pages already read cost nothing.' );
	}

	public function test_the_index_survives_a_new_connector_so_a_new_run_reads_nothing(): void {
		$this->manyItems( 3 );
		$this->connector()->lookupProduct( $this->connection(), 'SKU-3' );
		$before = count( $this->requests( 'GET', '#/v1/items/#' ) );
		$this->assertGreaterThan( 0, $before );
		$this->assertGreaterThan( 0, $this->store->writes );
		$this->assertSame( 'itm_3', $this->connector()->lookupProduct( $this->connection(), 'sku-3' )->id );
		$this->assertCount( $before, $this->requests( 'GET', '#/v1/items/#' ) );
	}

	public function test_a_rate_limit_in_the_middle_of_a_read_loses_no_progress(): void {
		$this->manyItems( 7 );
		$limited = false;
		$this->kit->http->route(
			'GET',
			'#/v1/items/.*page=3#',
			function ( array $request ) use ( &$limited ) {
				if ( ! $limited ) {
					$limited = true;
					return FakeHttpClient::tooManyRequests( 5 );
				}
				return $this->server->handle( $request );
			}
		);
		$connector = $this->connector();
		$first     = $connector->lookupProduct( $this->connection(), 'SKU-7' );
		$this->assertInstanceOf( ConnectorError::class, $first );
		$this->assertSame( ErrorCode::Rate001, $first->code );
		$read = count( $this->requests( 'GET', '#/v1/items/#' ) );
		$this->assertSame( 'itm_7', $connector->lookupProduct( $this->connection(), 'SKU-7' )->id );
		$again = count( $this->requests( 'GET', '#/v1/items/#' ) ) - $read;
		$this->assertLessThanOrEqual( 3, $again, 'The second read went on from the page that failed.' );
	}

	public function test_an_sku_missing_from_a_finished_index_is_unknown_for_five_minutes_and_then_looked_for_again(): void {
		$connector = $this->connector();
		$this->assertNull( $connector->lookupProduct( $this->connection(), 'NEW-1' ) );
		$read = count( $this->requests( 'GET', '#/v1/items/#' ) );
		$this->server->tables['items']['itm_new'] = array( 'id' => 'itm_new', 'name' => 'New', 'sku' => 'NEW-1', 'external_id' => '', 'is_active' => true );
		$this->assertNull( $connector->lookupProduct( $this->connection(), 'NEW-1' ) );
		$this->assertCount( $read, $this->requests( 'GET', '#/v1/items/#' ), 'No request within five minutes.' );
		$this->kit->clock->advance( 301 );
		$this->assertSame( 'itm_new', $connector->lookupProduct( $this->connection(), 'NEW-1' )->id );
	}

	public function test_the_index_is_dropped_after_an_hour(): void {
		$connector = $this->connector();
		$connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$read = count( $this->requests( 'GET', '#/v1/items/#' ) );
		$this->kit->clock->advance( 3601 );
		$connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		$this->assertCount( $read + 1, $this->requests( 'GET', '#/v1/items/#' ) );
	}

	public function test_a_corrupt_store_value_is_ignored(): void {
		$this->store->values[1] = array( 'at' => 'yesterday', 'items' => 'none' );
		$this->assertSame( 'itm_mug', $this->connector()->lookupProduct( $this->connection(), 'CS-MUG-01' )->id );
	}

	public function test_an_item_wafeq_refuses_drops_the_index_so_the_next_run_reads_the_list_again(): void {
		$connector = $this->connector();
		$connector->lookupProduct( $this->connection(), 'CS-MUG-01' );
		unset( $this->server->tables['items']['itm_mug'] );
		$result = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertArrayNotHasKey( 1, $this->store->values, 'The index was dropped.' );
		$this->assertNull( $connector->lookupProduct( $this->connection(), 'CS-MUG-01' ), 'Wafeq no longer has the item.' );
	}

	public function test_an_index_over_the_cap_stops_with_an_error_and_keeps_nothing(): void {
		$this->server->pageSize = 100;
		for ( $i = 1; $i <= WafeqItemIndex::MAX_ENTRIES + 5; ++$i ) {
			$this->server->tables['items'][ 'x' . $i ] = array( 'id' => 'x' . $i, 'name' => 'x', 'sku' => 'bulk-' . $i, 'external_id' => '', 'is_active' => true );
		}
		$connector = $this->connector();
		$first     = $connector->lookupProduct( $this->connection(), 'NOPE' );
		$this->assertInstanceOf( ConnectorError::class, $first );
		$this->assertSame( ErrorCode::Val002, $first->code );
		$this->assertArrayHasKey( 1, $this->store->values, 'The pages read are kept, so a later read goes on from there.' );
		$second = $connector->lookupProduct( $this->connection(), 'NOPE' );
		$this->assertInstanceOf( ConnectorError::class, $second );
		$this->assertArrayNotHasKey( 1, $this->store->values, 'Past the cap nothing is kept.' );
	}

	public function test_ping_is_one_cheap_call_and_says_no_for_a_wrong_key(): void {
		$connector = $this->connector();
		$this->assertTrue( $connector->ping( $this->connection() ) );
		$this->assertCount( 1, $this->kit->http->requests );
		$this->assertStringEndsWith( '/v1/organization/', $this->kit->http->requests[0]['url'] );
		$this->assertFalse( $connector->ping( $this->connection( array(), 'wfq-wrong-key-0123456789', 2 ) ) );
	}

	public function test_the_invoice_facts_are_the_number_the_status_and_the_date(): void {
		$connector = $this->connector();
		$sent      = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$facts     = $connector->fetchInvoice( $this->connection(), $sent->ref );
		$this->assertIsArray( $facts );
		$this->assertSame( $sent->ref->ref, $facts['number'] );
		$this->assertSame( 'finalized', $facts['status'] );
		$this->assertSame( '', $facts['authority_status'], 'Nothing says the tax authority has answered.' );
		$this->assertSame( '2026-11-02', $facts['issued_at'] );
		$this->assertContains( 'invoice_readback', array_keys( array_filter( $connector->capabilities() ) ) );
	}

	public function test_a_status_beyond_the_plain_lifecycle_ones_is_shown_as_the_authority_s(): void {
		$connector = $this->connector();
		$sent      = $connector->send( $this->connection(), $this->item(), $this->payload() );
		$this->server->tables['invoices'][ $sent->ref->id ]['status'] = 'REPORTED';
		$facts = $connector->fetchInvoice( $this->connection(), $sent->ref );
		$this->assertSame( 'reported', $facts['authority_status'] );
	}

	public function test_the_invoice_facts_of_a_missing_invoice_are_an_error(): void {
		$result = $this->connector()->fetchInvoice( $this->connection(), new ExternalRef( 'inv_missing', 'WC-1' ) );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Val002, $result->code );
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
