<?php
/**
 * What the Daftra connector says about itself, and the small reads around it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Daftra;

use Estasoft\SyncEngine\Connectors\Daftra\DaftraConnector;
use Estasoft\SyncEngine\Connectors\Daftra\DaftraProvider;
use Estasoft\SyncEngine\Connectors\Support\HostPolicy;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeDaftraServer;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class DaftraConnectorMetaTest extends DaftraTestCase {

	public function test_the_provider_builds_the_connector_under_its_id(): void {
		$provider = new DaftraProvider();
		$this->assertSame( 'daftra', $provider->id() );
		$this->assertInstanceOf( DaftraConnector::class, $provider->create( $this->kit->deps ) );
	}

	public function test_only_the_flags_the_connector_really_supports_are_true(): void {
		$connector = $this->connector();
		$this->assertSame( ConnectorType::Accounting, $connector->type() );
		$flags = array_keys( array_filter( $connector->capabilities() ) );
		sort( $flags );
		$this->assertSame( array( 'customers', 'orders', 'refunds', 'shadow_lookup', 'stock_in' ), $flags );
		$entities = array_map( static fn( EntityType $type ): string => $type->value, $connector->supportedEntities() );
		sort( $entities );
		$this->assertSame( array( 'customer', 'order', 'refund' ), $entities );
		$this->assertSame( 1.0, $connector->rateLimitHints()['requests_per_second'] );
	}

	public function test_the_connect_form_asks_for_the_account_and_one_secret_and_keeps_the_rest_advanced(): void {
		$fields = $this->connector()->credentialFields();
		$names  = array_map( static fn( $field ): string => $field->name, $fields );
		$this->assertSame( array( 'subdomain', 'domain', 'api_key', 'store_id', 'invoice_state', 'record_payment', 'payment_method', 'unknown_sku', 'link_credit_notes', 'total_mismatch' ), $names );
		$secrets = array_filter( $fields, static fn( $field ): bool => $field->secret );
		$this->assertCount( 1, $secrets );
		$this->assertSame( 'api_key', array_values( $secrets )[0]->name );
		foreach ( $fields as $field ) {
			if ( ! in_array( $field->name, array( 'subdomain', 'domain', 'api_key' ), true ) ) {
				$this->assertSame( 'advanced', $field->group, $field->name );
				$this->assertFalse( $field->required, $field->name );
			}
		}
		$this->assertTrue( $fields[0]->required );
	}

	public function test_only_the_two_daftra_domains_may_be_contacted(): void {
		$policy = new HostPolicy( array( '*.daftara.com', '*.daftra.com' ) );
		$this->assertNull( $policy->violation( 'https://acme.daftara.com/api2/invoices.json' ) );
		$this->assertNull( $policy->violation( 'https://acme.daftra.com/api2/invoices.json' ) );
		$this->assertNotNull( $policy->violation( 'https://acme.daftara.com.evil.test/api2/invoices.json' ) );
		$this->assertNotNull( $policy->violation( 'http://acme.daftara.com/api2/invoices.json' ) );
	}

	public function test_test_connection_reports_the_business_the_warehouses_and_the_taxes(): void {
		$result = $this->connector()->test( $this->connection( array( 'record_payment' => 'yes', 'payment_method' => 'bank' ) ) );
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'مؤسسة الاختبار', $result->company );
		$this->assertSame( 'API 2', $result->version );
		$this->assertSame( 'Main Warehouse', $result->warehouses[0]['name'] );
		$this->assertSame( 3, $result->detected['taxes'] );
		$this->assertSame( 'SAR', $result->detected['currency'] );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) );
	}

	public function test_test_connection_says_when_the_payment_method_is_missing(): void {
		$result = $this->connector()->test( $this->connection( array( 'record_payment' => 'yes' ) ) );
		$this->assertContains( 'payment_method', $result->missingPermissions() );
	}

	public function test_test_connection_with_an_expired_subscription_is_not_ok(): void {
		$this->server->expired = true;
		$result                = $this->connector()->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertContains( 'subscription_active', $result->missingPermissions() );
	}

	public function test_test_connection_with_a_bad_account_name_never_leaves_the_server(): void {
		$result = $this->connector()->test( $this->connection( array( 'subdomain' => 'a/b' ) ) );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 0, $this->kit->http->requests );
	}

	public function test_tax_codes_are_the_active_taxes_and_say_which_are_included_in_prices(): void {
		$codes = $this->connector()->listTaxCodes( $this->connection() );
		$this->assertCount( 2, $codes, 'The inactive tax is left out.' );
		$this->assertSame( '1', $codes[0]['code'] );
		$this->assertSame( 'VAT', $codes[0]['name'] );
		$this->assertSame( '15.00', $codes[0]['rate_percent'] );
		$this->assertSame( '2', $codes[1]['code'] );
		$this->assertStringContainsString( 'included in prices', $codes[1]['name'] );
		$this->assertSame( '5.00', $codes[1]['rate_percent'] );
	}

	public function test_the_tax_list_is_read_once_per_run_and_again_after_ten_minutes(): void {
		$connector = $this->connector();
		$connector->listTaxCodes( $this->connection() );
		$connector->listTaxCodes( $this->connection() );
		$this->assertCount( 1, $this->requests( 'GET', '#/api2/taxes\.json#' ) );
		$this->kit->clock->advance( 601 );
		$connector->listTaxCodes( $this->connection() );
		$this->assertCount( 2, $this->requests( 'GET', '#/api2/taxes\.json#' ) );
	}

	public function test_a_product_is_found_by_its_code_exactly_and_searched_by_name_or_code(): void {
		$connector = $this->connector();
		$found     = $connector->lookupProduct( $this->connection(), 'cs-tee-02' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( '12', $found->id );
		$this->assertSame( 'CS-TEE-02', $found->ref );
		$this->assertSame( '11', $connector->lookupProduct( $this->connection(), 'CS-MUG-01' )->id, 'The longer code that contains this one is not taken.' );
		$this->assertNull( $connector->lookupProduct( $this->connection(), 'NOPE' ) );
		$this->assertNull( $connector->lookupProduct( $this->connection(), '' ) );
		$byName = $connector->searchProducts( $this->connection(), 'قميص' );
		$this->assertCount( 1, $byName );
		$this->assertSame( '12', $byName[0]->id );
		$this->assertCount( 0, $connector->searchProducts( $this->connection(), '' ) );
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

	public function test_stock_is_the_balance_of_products_that_track_it_and_held_is_zero(): void {
		$stock = $this->connector()->fetchStock( $this->connection(), array( 'CS-MUG-01', 'cs-tee-02', 'CS-MUG-01-GIFT', 'NOPE' ), array() );
		$this->assertSame(
			array(
				'CS-MUG-01' => array( 'quantity' => 42, 'held' => 0 ),
				'cs-tee-02' => array( 'quantity' => 7, 'held' => 0 ),
			),
			$stock,
			'A fraction is rounded down; a product that does not track stock and an unknown one are left out.'
		);
	}

	public function test_a_few_skus_are_looked_up_one_by_one_and_many_by_reading_the_list_once(): void {
		$connector = $this->connector();
		$connector->fetchStock( $this->connection(), array( 'CS-MUG-01', 'CS-TEE-02' ), array() );
		$this->assertCount( 2, $this->requests( 'GET', '#/api2/products\.json\?.*product_code=#' ) );

		$many = array();
		for ( $i = 1; $i <= 12; ++$i ) {
			$many[] = 'SKU-' . $i;
		}
		$many[] = 'CS-MUG-01';
		$before = count( $this->kit->http->requests );
		$stock  = $connector->fetchStock( $this->connection(), $many, array() );
		$after  = array_slice( $this->kit->http->requests, $before );
		$this->assertCount( 1, $after, 'One read of the whole list, not thirteen.' );
		$this->assertArrayNotHasKey( 'product_code', (array) parse_url( $after[0]['url'] ) );
		$this->assertSame( array( 'CS-MUG-01' => array( 'quantity' => 42, 'held' => 0 ) ), $stock );
	}

	public function test_a_stock_request_for_some_warehouses_is_refused_not_guessed(): void {
		$result = $this->connector()->fetchStock( $this->connection(), array( 'CS-MUG-01' ), array( '1' ) );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Val002, $result->code );
		$this->assertSame( 'warehouses', $result->context['field'] );
		$this->assertCount( 0, $this->kit->http->requests );
	}

	public function test_a_wrong_key_is_auth_001_everywhere(): void {
		$connection = $this->connection( array(), 'dft-wrong-key-0123456789', 2 );
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
			'#/api2/products\.json#',
			static function ( array $request ) {
				preg_match( '/page=(\d+)/', $request['url'], $match );
				$page = (int) ( $match[1] ?? 1 );
				return FakeHttpClient::json( array( 'pagination' => array( 'page' => $page, 'page_count' => 100000 ), 'data' => array( array( 'Product' => array( 'id' => $page, 'product_code' => 'A' . $page ) ) ) ) );
			}
		);
		$result = $this->connector()->lookupProduct( $this->connection(), 'X' );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Val002, $result->code );
		$this->assertLessThanOrEqual( 101, count( $this->kit->http->requests ) );
	}

	public function test_a_server_that_ignores_the_page_number_is_read_once_more_and_then_left(): void {
		$this->kit->http->route(
			'GET',
			'#/api2/products\.json#',
			static fn( array $request ) => FakeHttpClient::json( array( 'pagination' => array( 'page' => 1, 'page_count' => 100000 ), 'data' => array( array( 'Product' => array( 'id' => 1, 'product_code' => 'A' ) ) ) ) )
		);
		$found = $this->connector()->lookupProduct( $this->connection(), 'A' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertCount( 2, $this->kit->http->requests );
	}

	public function test_the_key_is_not_in_any_message_the_connector_builds(): void {
		$result = $this->connector()->test( $this->connection( array(), 'dft-wrong-key-0123456789', 2 ) );
		$this->assertFalse( $result->ok );
		$this->assertStringNotContainsString( 'dft-wrong-key-0123456789', (string) $result->error?->message() );
		$this->assertStringNotContainsString( FakeDaftraServer::KEY, (string) $result->error?->message() );
	}
}
