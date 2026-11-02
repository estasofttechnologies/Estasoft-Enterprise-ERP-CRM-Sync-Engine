<?php
/**
 * Looking up customers and released products in Finance and Supply Chain.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\DynamicsFinance;

use Estasoft\SyncEngine\Domain\ErrorCode;

final class DynamicsFinanceReadTest extends DynamicsFinanceTestCase {

	public function test_a_customer_is_found_by_email_in_the_legal_entity_only(): void {
		$this->fo->addCustomer( 'buyer@example.test', 'نور المنصوري' );
		$this->fo->addCustomer( 'other@example.test', 'Other company', 'ussi' );

		$found = $this->connector->lookupCustomer( $this->connection, 'BUYER@example.test' );
		$this->assertNotNull( $found );
		$this->assertSame( 'US-001', $found->id );
		$this->assertSame( 'نور المنصوري', $found->ref );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, 'other@example.test' ) );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, 'nobody@example.test' ) );
	}

	public function test_an_email_with_a_wildcard_is_never_sent_as_a_filter(): void {
		$this->fo->addCustomer( 'buyer@example.test', 'Buyer' );
		$before = $this->kit->http->count();
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, '*@example.test' ) );
		$this->assertNull( $this->connector->lookupCustomer( $this->connection, '' ) );
		$this->assertSame( $before, $this->kit->http->count() );
	}

	public function test_a_quote_in_a_value_is_doubled_in_the_filter(): void {
		$this->fo->addCustomer( "o'neil@example.test", "O'Neil" );
		$found = $this->connector->lookupCustomer( $this->connection, "o'neil@example.test" );
		$this->assertNotNull( $found );
		$this->assertSame( "O'Neil", $found->ref );
	}

	public function test_a_product_is_found_by_item_number_ignoring_case_and_not_across_companies(): void {
		$this->fo->addProduct( 'ONLY-SSI', 'ussi' );
		$found = $this->connector->lookupProduct( $this->connection, 'cs-mug-01' );
		$this->assertNotNull( $found );
		$this->assertSame( 'CS-MUG-01', $found->id );
		$this->assertNull( $this->connector->lookupProduct( $this->connection, 'ONLY-SSI' ) );
	}

	public function test_a_product_search_returns_matches_of_the_legal_entity(): void {
		$this->fo->addProduct( 'ONLY-SSI-MUG', 'ussi' );
		$hits = $this->connector->searchProducts( $this->connection, 'MUG' );
		$this->assertIsArray( $hits );
		$this->assertSame( array( 'CS-MUG-01' ), array_map( static fn( $ref ) => $ref->id, $hits ) );
	}

	public function test_more_rows_than_one_page_are_all_read(): void {
		$this->fo->odata->pageSize = 2;
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->fo->addProduct( 'BULK-' . $i );
		}
		$hits = $this->connector->searchProducts( $this->connection, 'BULK' );
		$this->assertCount( 5, $hits );
	}

	public function test_a_next_link_to_another_host_is_never_followed(): void {
		$this->kit->http->route(
			'GET',
			'#/data/ReleasedProductsV2#',
			\Estasoft\SyncEngine\Tests\Support\FakeHttpClient::json(
				array(
					'value'           => array( array( 'dataAreaId' => 'usmf', 'ItemNumber' => 'CS-MUG-01' ) ),
					'@odata.nextLink' => 'https://evil.example.com/data/ReleasedProductsV2?$skiptoken=1',
				)
			)
		);
		$result = $this->connector->send( $this->connection, $this->item( '504' ), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		foreach ( $this->kit->http->requests as $request ) {
			$this->assertStringNotContainsString( 'evil.example.com', $request['url'] );
			$this->assertStringNotContainsString( 'evil.example.com', (string) ( $request['headers']['Authorization'] ?? '' ) );
		}
	}

	public function test_a_customer_sent_from_the_customer_sync_is_created_once(): void {
		$item    = $this->item( '501', \Estasoft\SyncEngine\Domain\EntityType::Customer );
		$payload = array(
			'currency' => 'AED',
			'partner'  => array(
				'email'      => 'new.buyer@example.test',
				'first_name' => 'Layla',
				'last_name'  => 'Haddad',
				'street'     => '3 Test Lane',
				'city'       => 'Sharjah',
				'country_id' => 'ARE',
			),
		);
		$first   = $this->connector->send( $this->connection, $item, $payload );
		$again   = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $first->isSuccess(), (string) $first->error?->message() );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $first->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->fo->all( 'CustomersV3' ) );
		$this->assertSame( 'Layla', $this->fo->all( 'CustomersV3' )[0]['PersonFirstName'] );
	}

	public function test_a_customer_without_a_name_or_email_is_refused_before_any_request(): void {
		$result = $this->connector->send( $this->connection, $this->item( '502', \Estasoft\SyncEngine\Domain\EntityType::Customer ), array( 'partner' => array( 'email' => 'a@example.test' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_released_products_failure_is_the_services_own_message(): void {
		$this->fo->denied = array( 'ReleasedProductsV2' );
		$result           = $this->connector->send( $this->connection, $this->item( '503' ), $this->payload() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertCount( 0, $this->fo->all( 'SalesOrderHeadersV2' ) );
	}
}
