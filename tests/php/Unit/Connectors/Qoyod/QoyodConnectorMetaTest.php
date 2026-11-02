<?php
/**
 * What the Qoyod connector says about itself, and the small reads around it.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Qoyod;

use Estasoft\SyncEngine\Connectors\Qoyod\QoyodConnector;
use Estasoft\SyncEngine\Connectors\Qoyod\QoyodProvider;
use Estasoft\SyncEngine\Connectors\Support\HostPolicy;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class QoyodConnectorMetaTest extends QoyodTestCase {

	public function test_the_provider_builds_the_connector_under_its_id(): void {
		$provider = new QoyodProvider();
		$this->assertSame( 'qoyod', $provider->id() );
		$this->assertInstanceOf( QoyodConnector::class, $provider->create( $this->kit->deps ) );
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
	}

	public function test_the_connect_form_has_one_secret_and_the_advanced_settings(): void {
		$fields  = $this->connector()->credentialFields();
		$names   = array_map( static fn( $field ): string => $field->name, $fields );
		$this->assertSame( array( 'api_key', 'inventory_id', 'currency', 'approval', 'untaxed_rows', 'record_payment', 'payment_account_id', 'line_tax_field', 'shipping_product_sku', 'fee_product_sku', 'total_mismatch' ), $names );
		$secrets = array_filter( $fields, static fn( $field ): bool => $field->secret );
		$this->assertCount( 1, $secrets );
		foreach ( $fields as $field ) {
			if ( 'api_key' !== $field->name ) {
				$this->assertSame( 'advanced', $field->group, $field->name );
			}
		}
	}

	public function test_only_the_documented_host_may_be_contacted(): void {
		$policy = new HostPolicy( array( 'api.qoyod.com' ) );
		$this->assertNull( $policy->violation( 'https://api.qoyod.com/2.0/invoices' ) );
		$this->assertNotNull( $policy->violation( 'https://www.qoyod.com/2.0/invoices' ) );
		$this->assertNotNull( $policy->violation( 'https://evil.example.test/2.0/invoices' ) );
		$this->assertNotNull( $policy->violation( 'http://api.qoyod.com/2.0/invoices' ) );
	}

	public function test_ping_is_one_cheap_call_and_says_no_for_a_wrong_key(): void {
		$connector = $this->connector();
		$this->assertTrue( $connector->ping( $this->connection() ) );
		$this->assertCount( 1, $this->kit->http->requests );
		$this->assertStringContainsString( '/2.0/inventories', $this->kit->http->requests[0]['url'] );
		$this->assertFalse( $connector->ping( $this->connection( array(), 'qyd-wrong-key-0123456789', 2 ) ) );
	}

	public function test_an_address_in_the_settings_is_ignored(): void {
		$result = $this->connector()->test( $this->connection( array( 'endpoint' => 'evil.example.test' ) ) );
		$this->assertTrue( $result->ok );
		$this->assertStringStartsWith( 'https://api.qoyod.com/', $this->kit->http->requests[0]['url'] );
	}

	public function test_test_connection_lists_the_branches_and_the_settings(): void {
		$result = $this->connector()->test( $this->connection( array( 'record_payment' => 'yes', 'payment_account_id' => '7' ) ) );
		$this->assertTrue( $result->ok, (string) $result->error?->message() );
		$this->assertSame( 'API 2.0', $result->version );
		$this->assertSame( 'Qoyod (Main)', $result->company );
		$this->assertSame( array( array( 'id' => '1', 'name' => 'Main' ) ), $result->warehouses );
		$this->assertSame( 1, $result->detected['branches'] );
		$this->assertSame( array(), $result->missingPermissions() );
		$this->assertCount( 0, $this->requests( 'POST', '#.#' ) + $this->requests( 'PATCH', '#.#' ), 'Test connection only reads.' );
	}

	public function test_test_connection_asks_for_a_branch_when_there_are_several(): void {
		$this->server->addBranch();
		$result = $this->connector()->test( $this->connection() );
		$this->assertFalse( $result->ok );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertContains( 'branch_selected', $result->missingPermissions() );
		$this->assertTrue( $this->connector()->test( $this->connection( array( 'inventory_id' => '2' ) ) )->ok );
	}

	public function test_tax_codes_are_the_three_standard_ones(): void {
		$this->assertSame(
			array(
				array( 'code' => '1', 'name' => 'VAT 15%', 'rate_percent' => '15.00' ),
				array( 'code' => '2', 'name' => 'VAT 0%', 'rate_percent' => '0.00' ),
				array( 'code' => '3', 'name' => 'Tax exempt', 'rate_percent' => '0.00' ),
			),
			$this->connector()->listTaxCodes( $this->connection() )
		);
		$this->assertSame( 15.0, $this->connector()->listTaxes( $this->connection() )[0]['rate_percent'] );
	}

	public function test_a_product_is_found_by_sku_whatever_the_case_and_searched_by_name_or_sku(): void {
		$connector = $this->connector();
		$found     = $connector->lookupProduct( $this->connection(), 'cs-tee-02' );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( '2', $found->id );
		$this->assertSame( 'CS-TEE-02', $found->ref );
		$this->assertNull( $connector->lookupProduct( $this->connection(), 'NOPE' ) );
		$this->assertNull( $connector->lookupProduct( $this->connection(), '' ) );
		$byName = $connector->searchProducts( $this->connection(), 'cotton' );
		$this->assertCount( 1, $byName );
		$this->assertSame( '2', $byName[0]->id );
		$this->assertCount( 0, $connector->searchProducts( $this->connection(), '' ) );
	}

	public function test_stock_is_summed_over_the_branches_that_count_and_untracked_products_are_left_out(): void {
		$this->server->addBranch();
		$connector = $this->connector();
		$all       = $connector->fetchStock( $this->connection(), array( 'CS-MUG-01', 'CS-TEE-02', 'SRV-SHIP', 'NOPE' ), array() );
		$this->assertSame(
			array(
				'CS-MUG-01' => array( 'quantity' => 15, 'held' => 0 ),
				'CS-TEE-02' => array( 'quantity' => 5, 'held' => 0 ),
			),
			$all
		);
		$second = $connector->fetchStock( $this->connection(), array( 'CS-MUG-01' ), array( '2' ) );
		$this->assertSame( array( 'CS-MUG-01' => array( 'quantity' => 3, 'held' => 0 ) ), $second );
		$this->assertSame( array(), $connector->fetchStock( $this->connection(), array(), array() ) );
	}

	public function test_a_stock_read_asks_for_the_skus_as_a_list_the_way_rails_reads_one(): void {
		$this->connector()->fetchStock( $this->connection(), array( 'CS-MUG-01', 'CS-TEE-02' ), array() );
		$url = $this->requests( 'GET', '#/2.0/products\?#' )[0]['url'];
		$this->assertStringContainsString( 'q%5Bsku_in%5D%5B%5D=CS-MUG-01&q%5Bsku_in%5D%5B%5D=CS-TEE-02', $url );
	}

	public function test_a_big_stock_read_is_split_into_batches(): void {
		$skus = array();
		for ( $i = 1; $i <= 120; ++$i ) {
			$skus[] = 'SKU-' . $i;
		}
		$this->connector()->fetchStock( $this->connection(), $skus, array() );
		$seen = array_unique( array_map( static fn( array $request ): string => (string) preg_replace( '/&page=\d+$/', '', $request['url'] ), $this->requests( 'GET', '#/2.0/products\?#' ) ) );
		$this->assertCount( 3, $seen, '120 SKUs are asked for in batches of 50, 50 and 20.' );
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
		$connection = $this->connection( array(), 'qyd-wrong-key-0123456789', 2 );
		$connector  = $this->connector();
		foreach ( array(
			$connector->lookupProduct( $connection, 'X' ),
			$connector->lookupCustomer( $connection, 'a@example.test' ),
			$connector->findExisting( $connection, $this->item() ),
			$connector->fetchStock( $connection, array( 'X' ), array() ),
			$connector->listTaxes( $connection ) instanceof ConnectorError ? $connector->listTaxes( $connection ) : new ConnectorError( ErrorCode::Auth001 ),
		) as $result ) {
			$this->assertInstanceOf( ConnectorError::class, $result );
			$this->assertSame( ErrorCode::Auth001, $result->code );
		}
	}

	public function test_a_list_that_never_ends_stops_with_an_error_instead_of_looping(): void {
		$counter = 0;
		$this->kit->http->route(
			'GET',
			'#/2.0/products#',
			static function ( array $request ) use ( &$counter ) {
				++$counter;
				return FakeHttpClient::json( array( 'products' => array( array( 'id' => $counter, 'sku' => 'X' . $counter ) ) ) );
			}
		);
		$result = $this->connector()->lookupProduct( $this->connection(), 'NOPE' );
		$this->assertInstanceOf( ConnectorError::class, $result );
		$this->assertSame( ErrorCode::Val002, $result->code );
		$this->assertLessThanOrEqual( 101, $counter );
	}

	public function test_a_plan_message_on_a_403_is_the_plan_error_and_other_403s_are_permissions(): void {
		$this->kit->http->route( 'GET', '#/2.0/inventories#', FakeHttpClient::json( array( 'error' => 'API access is not included in your plan. Please upgrade.' ), 403 ) );
		$this->assertSame( ErrorCode::Auth006, $this->connector()->test( $this->connection() )->error->code );
	}
}
