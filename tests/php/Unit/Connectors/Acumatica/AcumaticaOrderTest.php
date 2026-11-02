<?php
/**
 * Sending orders to Acumatica: the Sales Order, the checks and the replays.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Acumatica;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeHttpClient;

final class AcumaticaOrderTest extends AcumaticaTestCase {

	public function test_an_order_becomes_an_open_sales_order_with_the_shops_reference(): void {
		$result = $this->sendOrder( array(), array( 'total' => 43800 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertFalse( $result->alreadyExisted );
		$orders = $this->server->all( 'SalesOrder' );
		$this->assertCount( 1, $orders );
		$order = $orders[0];
		$this->assertSame( 'WC-100', $order['CustomerOrder'] );
		$this->assertSame( 'SO', $order['OrderType'] );
		$this->assertSame( '2026-11-02T00:00:00', $order['Date'] );
		$this->assertSame( 'AED', $order['CurrencyID'] );
		$this->assertSame( 'Gift wrap please', $order['Description'] );
		$this->assertSame( 'Open', $order['Status'], 'The hold is removed after the total is checked.' );
		$this->assertSame( 'CS-BOWL-02', $order['Details'][0]['InventoryID'] );
		$this->assertSame( 2.0, $order['Details'][0]['OrderQty'] );
		$this->assertSame( 219.0, $order['Details'][0]['UnitPrice'] );
		$this->assertSame( 'SO/000001', $result->ref->id );
		$this->assertSame( '000001', $result->ref->ref );
		$this->assertSame( 43800, $result->ref->extra['total_minor'] );
		$this->assertSame( 'AED', $result->ref->extra['currency'] );
		$this->assertStringContainsString( 'ScreenId=SO301000&OrderType=SO&OrderNbr=000001', (string) $result->ref->url );
	}

	public function test_the_order_is_created_on_hold_and_released_in_a_second_call(): void {
		$this->sendOrder();

		$puts = array_values( array_filter( $this->server->calls, static fn( array $call ): bool => 'PUT' === $call['method'] && str_ends_with( (string) $call['path'], '/SalesOrder' ) ) );
		$this->assertCount( 2, $puts );
		$this->assertTrue( $puts[0]['body']['Hold']['value'] );
		$this->assertSame( 'WC-100', $puts[0]['body']['CustomerOrder']['value'] );
		$this->assertFalse( $puts[1]['body']['Hold']['value'] );
		$this->assertSame( '000001', $puts[1]['body']['OrderNbr']['value'] );
	}

	public function test_the_buyer_becomes_a_customer_with_a_main_contact(): void {
		$this->sendOrder();

		$customers = $this->server->all( 'Customer' );
		$this->assertCount( 1, $customers );
		$customer = $customers[0];
		$this->assertSame( 'C000001', $customer['CustomerID'], 'Acumatica numbers the customer itself.' );
		$this->assertSame( 'Noor Al-Mansouri', $customer['CustomerName'] );
		$this->assertSame( 'noor@example.test', $customer['MainContact']['Email'] );
		$this->assertSame( '+971501234567', $customer['MainContact']['Phone1'] );
		$this->assertSame( 'Sheikh Zayed Road', $customer['MainContact']['Address']['AddressLine1'] );
		$this->assertSame( 'AE', $customer['MainContact']['Address']['Country'] );
		$create = array_values( array_filter( $this->server->calls, static fn( array $call ): bool => 'PUT' === $call['method'] && str_ends_with( (string) $call['path'], '/Customer' ) ) )[0];
		$this->assertArrayNotHasKey( 'CustomerID', $create['body'] );
	}

	public function test_a_company_name_becomes_the_customer_name(): void {
		$this->sendOrder( array(), array( 'partner' => array( 'company_name' => 'Sand and Co' ) ) );

		$customer = $this->server->all( 'Customer' )[0];
		$this->assertSame( 'Sand and Co', $customer['CustomerName'] );
		$this->assertSame( 'Noor', $customer['MainContact']['FirstName'] );
		$this->assertSame( 'Al-Mansouri', $customer['MainContact']['LastName'] );
	}

	public function test_a_customer_with_the_same_email_is_used_whatever_the_case(): void {
		$this->server->addCustomer( 'ALPHA', 'Noor existing', 'NOOR@Example.test' );
		$result = $this->sendOrder();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertCount( 1, $this->server->all( 'Customer' ) );
		$this->assertSame( 'ALPHA', $this->server->all( 'SalesOrder' )[0]['CustomerID'] );
	}

	public function test_an_inactive_customer_is_not_used(): void {
		$this->server->addCustomer( 'ALPHA', 'Noor existing', 'noor@example.test', array( 'Status' => 'Inactive' ) );
		$this->sendOrder();

		$this->assertCount( 2, $this->server->all( 'Customer' ) );
		$this->assertNotSame( 'ALPHA', $this->server->all( 'SalesOrder' )[0]['CustomerID'] );
	}

	public function test_match_only_refuses_an_unknown_buyer_and_creates_nothing(): void {
		$result = $this->sendOrder( array( 'customer_policy' => 'match_only' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'Customer' ) );
		$this->assertCount( 0, $this->server->all( 'SalesOrder' ) );
	}

	public function test_always_create_makes_a_customer_even_when_the_email_exists(): void {
		$this->server->addCustomer( 'ALPHA', 'Noor existing', 'noor@example.test' );
		$this->sendOrder( array( 'customer_policy' => 'always_create' ) );

		$this->assertCount( 2, $this->server->all( 'Customer' ) );
	}

	public function test_the_customer_class_option_goes_to_new_customers(): void {
		$this->sendOrder( array( 'customer_class' => 'ONLINE' ) );

		$this->assertSame( 'ONLINE', $this->server->all( 'Customer' )[0]['CustomerClass'] );
	}

	public function test_a_different_delivery_address_overrides_the_ship_to_address(): void {
		$this->sendOrder( array(), array( 'delivery' => array( 'name' => 'Layla', 'street' => 'Corniche Road', 'city' => 'Abu Dhabi', 'country' => 'AE' ) ) );

		$order = $this->server->all( 'SalesOrder' )[0];
		$this->assertTrue( $order['ShipToAddressOverride'] );
		$this->assertSame( 'Corniche Road', $order['ShipToAddress']['AddressLine1'] );
		$this->assertSame( 'Abu Dhabi', $order['ShipToAddress']['City'] );
	}

	public function test_the_same_delivery_address_sends_no_override(): void {
		$this->sendOrder( array(), array( 'delivery' => array( 'street' => 'Sheikh Zayed Road', 'city' => 'Dubai', 'zip' => '00000', 'country' => 'AE' ) ) );

		$this->assertArrayNotHasKey( 'ShipToAddressOverride', $this->server->all( 'SalesOrder' )[0] );
	}

	public function test_the_same_order_is_never_made_twice_and_the_second_send_links_to_the_first(): void {
		$first  = $this->sendOrder();
		$second = $this->sendOrder();

		$this->assertTrue( $second->isSuccess() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->server->all( 'SalesOrder' ) );
		$this->assertCount( 1, $this->server->all( 'Customer' ) );
	}

	public function test_a_lost_answer_after_the_create_is_continued_not_repeated(): void {
		$connection = $this->connection();
		$connector  = $this->connector();
		$item       = $this->item( EntityType::Order, '100' );
		$this->server->loseAnswerOf( 'PUT', '#/SalesOrder$#' );

		$first = $connector->send( $connection, $item, $this->order() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertSame( 'Hold', $this->server->all( 'SalesOrder' )[0]['Status'], 'The order exists, on hold.' );

		$again = $connector->send( $connection, $item, $this->order() );
		$this->assertTrue( $again->isSuccess(), $this->why( $again ) );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( 'Open', $this->server->all( 'SalesOrder' )[0]['Status'] );
		$this->assertCount( 1, $this->server->all( 'SalesOrder' ) );
		$this->assertCount( 1, $this->server->all( 'Customer' ) );
	}

	public function test_a_lost_answer_after_the_release_finds_the_open_order_next_time(): void {
		$connection = $this->connection();
		$connector  = $this->connector();
		$item       = $this->item( EntityType::Order, '100' );
		$this->server->loseAnswerOf( 'PUT', '#/SalesOrder$#', 1 );

		$first = $connector->send( $connection, $item, $this->order() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( 'Open', $this->server->all( 'SalesOrder' )[0]['Status'] );

		$found = $connector->findExisting( $connection, $item );
		$this->assertSame( 'SO/000001', $found->id );
		$again = $connector->send( $connection, $item, $this->order() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'SalesOrder' ) );
	}

	public function test_findExisting_answers_only_for_an_order_that_is_not_on_hold(): void {
		$connection = $this->connection();
		$connector  = $this->connector();
		$item       = $this->item( EntityType::Order, '100' );
		$this->assertNull( $connector->findExisting( $connection, $item ) );

		$connector->send( $connection, $item, $this->order() );
		$found = $connector->findExisting( $connection, $item );
		$this->assertSame( 'SO/000001', $found->id );

		$this->server->records['SalesOrder']['SO/000001']['Status'] = 'Hold';
		$this->assertNull( $connector->findExisting( $connection, $item ), 'An order on hold still needs its check.' );
		$this->server->records['SalesOrder']['SO/000001']['Status'] = 'Cancelled';
		$this->assertNull( $connector->findExisting( $connection, $item ) );
	}

	public function test_a_cancelled_order_is_not_a_match_and_a_new_one_is_made(): void {
		$this->sendOrder();
		$this->server->records['SalesOrder']['SO/000001']['Status'] = 'Cancelled';
		$result = $this->sendOrder();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertCount( 2, $this->server->all( 'SalesOrder' ) );
	}

	public function test_an_unknown_sku_is_map_001_and_nothing_is_created(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'NO-SUCH', 'quantity' => 1, 'price_unit' => 100 ) ) ) );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'Customer' ) );
		$this->assertCount( 0, $this->server->all( 'SalesOrder' ) );
	}

	public function test_an_inactive_item_counts_as_missing(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'OLD-ITEM', 'quantity' => 1, 'price_unit' => 100 ) ) ) );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
	}

	public function test_a_sku_is_matched_without_regard_to_case(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'cs-bowl-02', 'quantity' => 1, 'price_unit' => 21900 ) ) ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 'CS-BOWL-02', $this->server->all( 'SalesOrder' )[0]['Details'][0]['InventoryID'], 'The Inventory ID as Acumatica spells it.' );
	}

	public function test_a_non_stock_item_is_found_too(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'FEE', 'quantity' => 1, 'price_unit' => 500 ) ) ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
	}

	public function test_a_buyer_without_an_email_is_map_003(): void {
		$result = $this->sendOrder( array(), array( 'partner' => array( 'email' => '' ) ) );

		$this->assertSame( ErrorCode::Map003, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'SalesOrder' ) );
	}

	public function test_a_tax_category_is_sent_per_line_and_the_total_is_checked_against_the_shop(): void {
		$result = $this->sendOrder(
			array(),
			array(
				'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => 'VAT5' ) ),
				'total'      => 45990,
			)
		);

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 'VAT5', $this->server->all( 'SalesOrder' )[0]['Details'][0]['TaxCategory'] );
		$this->assertSame( 45990, $result->ref->extra['total_minor'] );
	}

	public function test_a_tax_category_is_matched_without_regard_to_case(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 21900, 'tax' => 'vat5' ) ) ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 'VAT5', $this->server->all( 'SalesOrder' )[0]['Details'][0]['TaxCategory'] );
	}

	public function test_an_unknown_or_inactive_tax_category_is_map_002(): void {
		foreach ( array( 'NOPE', 'OLDTAX' ) as $code ) {
			$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 21900, 'tax' => $code ) ) ) );
			$this->assertSame( ErrorCode::Map002, $result->error->code, $code );
		}
		$this->assertCount( 0, $this->server->all( 'SalesOrder' ) );
	}

	public function test_a_line_with_two_tax_codes_is_refused(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 21900, 'tax' => array( 'VAT5', 'EXEMPT' ) ) ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'one tax category', $this->why( $result ) );
	}

	public function test_prices_with_tax_are_refused_naming_the_setting(): void {
		$result = $this->sendOrder( array(), array( 'prices_include_tax' => true ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'prices_include_tax', $result->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'SalesOrder' ) );
	}

	public function test_a_total_that_differs_stops_the_order_on_hold_and_the_next_pass_does_not_make_a_second(): void {
		$payload = array(
			'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => 'VAT5' ) ),
			'total'      => 43800,
		);
		$first   = $this->sendOrder( array(), $payload );
		$again   = $this->sendOrder( array(), $payload );

		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $first->error->code );
		$this->assertSame( 'total', $first->error->context['field'] );
		$this->assertStringContainsString( '459.90', $this->why( $first ) );
		$this->assertStringContainsString( 'on hold', $this->why( $first ) );
		$this->assertFalse( $again->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'SalesOrder' ), 'The held order is checked again, never made again.' );
		$this->assertSame( 'Hold', $this->server->all( 'SalesOrder' )[0]['Status'] );
	}

	public function test_a_total_that_differs_can_be_accepted_with_a_warning(): void {
		$result = $this->sendOrder(
			array( 'total_mismatch' => 'warn' ),
			array(
				'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 2, 'price_unit' => 21900, 'tax' => 'VAT5' ) ),
				'total'      => 43800,
			)
		);

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertCount( 1, $result->ref->extra['warnings'] );
		$this->assertSame( 'Open', $this->server->all( 'SalesOrder' )[0]['Status'] );
	}

	public function test_a_line_discount_lowers_the_unit_price_in_whole_numbers(): void {
		$result = $this->sendOrder( array(), array( 'order_line' => array( array( 'product' => 'CS-BOWL-02', 'quantity' => 1, 'price_unit' => 21900, 'discount' => 10 ) ) ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 197.1, $this->server->all( 'SalesOrder' )[0]['Details'][0]['UnitPrice'] );
	}

	public function test_shipping_needs_its_item_and_then_becomes_a_line(): void {
		$payload = array( 'shipping' => array( 'total' => 2500 ), 'total' => 46300 );
		$refused = $this->sendOrder( array(), $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertSame( 'shipping_item', $refused->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'SalesOrder' ) );

		$sent = $this->sendOrder( array( 'shipping_item' => 'shipping' ), $payload );
		$this->assertTrue( $sent->isSuccess(), $this->why( $sent ) );
		$details = $this->server->all( 'SalesOrder' )[0]['Details'];
		$this->assertCount( 2, $details );
		$this->assertSame( 'SHIPPING', $details[1]['InventoryID'] );
		$this->assertSame( 25.0, $details[1]['UnitPrice'] );
	}

	public function test_fees_and_a_discount_become_lines_and_the_discount_is_negative(): void {
		$result = $this->sendOrder(
			array( 'fee_item' => 'FEE', 'discount_item' => 'DISCOUNT' ),
			array(
				'fee'      => array( array( 'name' => 'COD fee', 'total' => 1000 ) ),
				'discount' => array( 'total' => 2000 ),
				'total'    => 42800,
			)
		);

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$details = $this->server->all( 'SalesOrder' )[0]['Details'];
		$this->assertSame( 10.0, $details[1]['UnitPrice'] );
		$this->assertSame( -20.0, $details[2]['UnitPrice'] );
		$this->assertSame( 42800, $result->ref->extra['total_minor'] );
	}

	public function test_a_discount_without_its_item_is_refused(): void {
		$result = $this->sendOrder( array(), array( 'discount' => array( 'total' => 2000 ) ) );

		$this->assertSame( 'discount_item', $result->error->context['field'] );
	}

	public function test_the_warehouse_option_goes_on_every_line(): void {
		$this->sendOrder( array( 'warehouse' => 'MAIN' ) );

		$this->assertSame( 'MAIN', $this->server->all( 'SalesOrder' )[0]['Details'][0]['WarehouseID'] );
	}

	public function test_another_order_type_can_be_chosen(): void {
		$this->sendOrder( array( 'order_type' => 'so' ) );
		$this->assertSame( 'SO', $this->server->all( 'SalesOrder' )[0]['OrderType'] );
	}

	public function test_the_branch_is_sent_as_a_header_on_every_call(): void {
		$this->sendOrder( array( 'branch' => 'DUBAI' ) );

		foreach ( $this->server->calls as $call ) {
			$this->assertSame( 'DUBAI', $call['headers']['PX-CbApiBranch'] ?? null, $call['method'] . ' ' . $call['path'] );
		}
	}

	public function test_a_customer_that_takes_one_currency_refuses_another_with_the_servers_words(): void {
		$this->server->addCustomer( 'ALPHA', 'Noor existing', 'noor@example.test', array( '_strictCurrency' => true ) );
		$result = $this->sendOrder( array(), array( 'currency' => 'USD' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'currency of the customer', $this->why( $result ) );
		$this->assertCount( 0, $this->server->all( 'SalesOrder' ) );
	}

	public function test_a_currency_that_comes_back_different_stops_the_order_on_hold(): void {
		$this->server->forceCurrency = 'USD';
		$result                      = $this->sendOrder();

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( 'currency', $result->error->context['field'] );
		$this->assertSame( 'Hold', $this->server->all( 'SalesOrder' )[0]['Status'] );
	}

	public function test_a_refused_field_comes_back_as_val_002_with_the_servers_message(): void {
		$this->server->failNext( 'PUT', '#/SalesOrder$#', FakeHttpClient::json( array( 'Details' => array( array( 'TaxCategory' => array( 'value' => 'X', 'error' => 'Error: Tax category X cannot be found.' ) ) ) ), 422 ) );
		$result = $this->sendOrder();

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Tax category X cannot be found', $this->why( $result ) );
	}

	public function test_the_api_login_limit_is_a_rate_limit_not_a_failure_of_the_data(): void {
		$this->server->failNext( 'PUT', '#/SalesOrder$#', FakeHttpClient::json( array( 'message' => 'An error has occurred.', 'exceptionMessage' => 'API Login Limit', 'exceptionType' => 'PX.Api.PXApiException' ), 500 ) );
		$result = $this->sendOrder();

		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 60, $result->error->retryAfter );
	}

	public function test_a_server_fault_without_a_business_message_is_retried_later(): void {
		$this->server->failNext( 'PUT', '#/SalesOrder$#', FakeHttpClient::json( array( 'message' => 'An error has occurred.', 'exceptionMessage' => 'Timeout expired.', 'exceptionType' => 'System.Data.SqlClient.SqlException' ), 500 ) );
		$result = $this->sendOrder();

		$this->assertSame( ErrorCode::Net002, $result->error->code );
	}

	public function test_an_order_reference_longer_than_the_field_is_refused_before_any_request_for_it(): void {
		$item   = $this->item( EntityType::Order, str_repeat( '9', 45 ) );
		$result = $this->connector()->send( $this->connection(), $item, $this->order() );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertCount( 0, $this->server->all( 'SalesOrder' ) );
	}

	public function test_an_order_without_a_currency_is_refused(): void {
		$result = $this->sendOrder( array(), array( 'currency' => '' ) );

		$this->assertSame( 'currency', $result->error->context['field'] );
	}

	public function test_a_customer_item_creates_or_matches_a_customer(): void {
		$connector = $this->connector();
		$connection = $this->connection();
		$item       = $this->item( EntityType::Customer, '5' );
		$payload    = array( 'partner' => array( 'name' => 'Layla Haddad', 'email' => 'layla@example.test', 'country' => 'AE' ) );

		$first = $connector->send( $connection, $item, $payload );
		$this->assertTrue( $first->isSuccess(), $this->why( $first ) );
		$this->assertFalse( $first->alreadyExisted );
		$second = $connector->send( $connection, $item, $payload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->server->all( 'Customer' ) );
		$this->assertSame( ErrorCode::Map003, $connector->send( $connection, $item, array( 'partner' => array( 'name' => 'No Email' ) ) )->error->code );
	}
}
