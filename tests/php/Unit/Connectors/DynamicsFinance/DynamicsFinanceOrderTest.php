<?php
/**
 * Sending orders to Finance and Supply Chain as sales orders.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\DynamicsFinance;

use Estasoft\SyncEngine\Domain\ErrorCode;

final class DynamicsFinanceOrderTest extends DynamicsFinanceTestCase {

	public function test_an_order_becomes_an_open_sales_order_with_lines_and_a_marker(): void {
		$result = $this->connector->send( $this->connection, $this->item( '1001' ), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$orders = $this->fo->all( 'SalesOrderHeadersV2' );
		$this->assertCount( 1, $orders );
		$this->assertSame( 'usmf', $orders[0]['dataAreaId'] );
		$this->assertSame( 'WC-1001', $orders[0]['CustomersOrderReference'] );
		$this->assertSame( 'WC-1001 (1 lines)', $orders[0]['SalesOrderName'] );
		$this->assertSame( 'AED', $orders[0]['CurrencyCode'] );
		$this->assertSame( '2026-11-01T12:00:00Z', $orders[0]['RequestedShippingDate'], 'An Edm.DateTimeOffset literal at midday UTC.' );
		$this->assertSame( '2026-11-01T12:00:00Z', $orders[0]['RequestedReceiptDate'] );
		$this->assertSame( 'Jane Doe', $orders[0]['DeliveryAddressName'] );
		$this->assertSame( 'ARE', $orders[0]['DeliveryAddressCountryRegionId'] );
		$this->assertSame( $orders[0]['OrderingCustomerAccountNumber'], $orders[0]['InvoiceCustomerAccountNumber'] );
		$lines = $this->linesOfOrder();
		$this->assertSame( 'usmf', $lines[0]['dataAreaId'] );
		$this->assertSame( 'CS-MUG-01', $lines[0]['ItemNumber'] );
		$this->assertSame( 3, $lines[0]['OrderedSalesQuantity'] );
		$this->assertSame( 19.99, $lines[0]['SalesPrice'] );
		$this->assertSame( 'FULL', $lines[0]['SalesTaxItemGroupCode'] );
		$this->assertSame( '2026-11-01T12:00:00Z', $lines[0]['RequestedShippingDate'] );
		$this->assertSame( 6297, $result->ref->extra['total_minor'] );
		$this->assertSame( $orders[0]['SalesOrderNumber'], $result->ref->ref );
	}

	public function test_the_same_order_twice_makes_one_sales_order(): void {
		$item = $this->item( '1002' );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$first  = $this->connector->send( $this->connection, $item, $this->payload() );
		$second = $this->connector->send( $this->connection, $item, $this->payload() );
		$found  = $this->connector->findExisting( $this->connection, $item );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $first->ref->id, $found->id );
		$this->assertCount( 1, $this->fo->all( 'SalesOrderHeadersV2' ) );
		$this->assertCount( 1, $this->fo->all( 'SalesOrderLines' ) );
	}

	public function test_every_read_is_pinned_to_the_legal_entity_and_asks_for_cross_company(): void {
		$this->connector->send( $this->connection, $this->item( '1003' ), $this->payload() );
		$reads = array_filter( $this->kit->http->requests, static fn( array $r ): bool => 'GET' === $r['method'] && str_contains( $r['url'], '/data/' ) );
		$this->assertNotEmpty( $reads );
		foreach ( $reads as $request ) {
			$this->assertStringContainsString( 'cross-company=true', $request['url'] );
			$this->assertStringContainsString( "dataAreaId eq 'usmf'", rawurldecode( $request['url'] ) );
		}
	}

	public function test_data_of_another_legal_entity_is_never_matched(): void {
		$this->fo->addCustomer( 'jane.doe@example.test', 'Other company customer', 'ussi' );
		$this->fo->addProduct( 'ONLY-IN-SSI', 'ussi' );
		$payload                              = $this->payload();
		$payload['order_line'][0]['product'] = 'ONLY-IN-SSI';
		$this->assertSame( ErrorCode::Map001, $this->connector->send( $this->connection, $this->item( '1004' ), $payload )->error->code );
		$this->connector->send( $this->connection, $this->item( '1005' ), $this->payload() );
		$this->assertCount( 2, $this->fo->all( 'CustomersV3' ), 'A new customer is made in USMF; the SSI customer is not used.' );
		$this->assertSame( 'usmf', $this->fo->all( 'CustomersV3' )[1]['dataAreaId'] );
	}

	public function test_a_cut_off_pass_continues_without_duplicating_lines(): void {
		$payload                  = $this->payload( array( 'total' => 6297 + 1050 ) );
		$payload['order_line'][]  = array( 'product' => 'CS-TEE-02', 'name' => 'Tee', 'quantity' => 1, 'price_unit' => 1000, 'tax' => 'FULL' );
		$item                     = $this->item( '1006' );
		$this->fo->failAfterWrites = array( 'SalesOrderLines' => 1 );
		$first                    = $this->connector->send( $this->connection, $item, $payload );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );

		$second = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertCount( 2, $this->linesOfOrder() );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $item ) );
	}

	public function test_a_lost_answer_to_the_header_is_found_again(): void {
		$this->fo->loseAnswers = array( '/SalesOrderHeadersV2' );
		$item                  = $this->item( '1007' );
		$this->assertFalse( $this->connector->send( $this->connection, $item, $this->payload() )->isSuccess() );
		$this->assertCount( 1, $this->fo->all( 'SalesOrderHeadersV2' ) );
		$this->assertTrue( $this->connector->send( $this->connection, $item, $this->payload() )->isSuccess() );
		$this->assertCount( 1, $this->fo->all( 'SalesOrderHeadersV2' ), 'No second header.' );
		$this->assertCount( 1, $this->fo->all( 'SalesOrderLines' ) );
	}

	public function test_an_unknown_sku_stops_the_order_before_anything_is_created(): void {
		$payload                              = $this->payload();
		$payload['order_line'][0]['product'] = 'NO-SUCH';
		$result                               = $this->connector->send( $this->connection, $this->item( '1008' ), $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertCount( 0, $this->fo->all( 'SalesOrderHeadersV2' ) );
		$this->assertCount( 0, $this->fo->all( 'CustomersV3' ) );
	}

	public function test_a_customer_is_found_by_email_ignoring_case(): void {
		$this->fo->addCustomer( 'JANE.doe@Example.test', 'Jane Existing' );
		$this->connector->send( $this->connection, $this->item( '1009' ), $this->payload() );
		$this->connector->send( $this->connection, $this->item( '1010' ), $this->payload() );
		$this->assertCount( 1, $this->fo->all( 'CustomersV3' ) );
		$this->assertSame( 'US-001', $this->fo->all( 'SalesOrderHeadersV2' )[1]['OrderingCustomerAccountNumber'] );
	}

	public function test_a_new_customer_needs_a_customer_group_and_is_made_with_the_names_as_typed(): void {
		$noGroup = $this->connection( array( 'customer_group' => '' ) );
		$refused = $this->connector->send( $noGroup, $this->item( '1011' ), $this->payload() );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertStringContainsString( 'customer_group', $refused->error->message() );
		$this->assertCount( 0, $this->fo->all( 'SalesOrderHeadersV2' ) );

		$payload                          = $this->payload();
		$payload['partner']['first_name'] = 'نور';
		$payload['partner']['last_name']  = 'المنصوري';
		$payload['partner']['street']     = 'شارع الشيخ زايد، برج ٢٣';
		$this->assertTrue( $this->connector->send( $this->connection, $this->item( '1011' ), $payload )->isSuccess() );
		$customer = $this->fo->all( 'CustomersV3' )[0];
		$this->assertSame( 'Person', $customer['PartyType'] );
		$this->assertSame( 'نور', $customer['PersonFirstName'] );
		$this->assertSame( 'المنصوري', $customer['PersonLastName'] );
		$this->assertSame( 'شارع الشيخ زايد، برج ٢٣', $customer['AddressStreet'] );
		$this->assertSame( '10', $customer['CustomerGroupId'] );
		$this->assertSame( 'AED', $customer['SalesCurrencyCode'] );
	}

	public function test_a_company_buyer_is_an_organisation(): void {
		$payload                            = $this->payload();
		$payload['partner']['company_name'] = 'Acme Trading LLC';
		$this->connector->send( $this->connection, $this->item( '1012' ), $payload );
		$customer = $this->fo->all( 'CustomersV3' )[0];
		$this->assertSame( 'Organization', $customer['PartyType'] );
		$this->assertSame( 'Acme Trading LLC', $customer['OrganizationName'] );
	}

	public function test_match_only_and_fixed_policies(): void {
		$only = $this->connection( array( 'customer_policy' => 'match_only' ) );
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $only, $this->item( '1013' ), $this->payload() )->error->code );
		$this->fo->addCustomer( 'web@example.test', 'Web shop' );
		$fixed   = $this->connection( array( 'customer_policy' => 'fixed', 'fixed_customer' => 'us-001' ) );
		$payload = $this->payload();
		unset( $payload['partner']['email'] );
		$sent = $this->connector->send( $fixed, $this->item( '1014' ), $payload );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( 'US-001', $this->fo->all( 'SalesOrderHeadersV2' )[0]['OrderingCustomerAccountNumber'] );
		unset( $payload['partner'] );
		$noEmail = $this->payload();
		unset( $noEmail['partner']['email'] );
		$this->assertSame( ErrorCode::Map003, $this->connector->send( $this->connection, $this->item( '1015' ), $noEmail )->error->code );
	}

	public function test_shipping_and_fees_need_their_item_and_become_lines_and_the_discount_goes_to_the_header(): void {
		// The fake takes the header discount off the total after tax (what F&O does with it is listed under Not verified).
		$payload             = $this->payload( array( 'total' => 6297 + 525 + 210 - 100 ) );
		$payload['shipping'] = array( 'total' => 500, 'name' => 'Courier' );
		$payload['fee']      = array( array( 'name' => 'Gift wrap', 'total' => 200 ) );
		$payload['discount'] = array( 'total' => 100 );
		$refused             = $this->connector->send( $this->connection, $this->item( '1016' ), $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertStringContainsString( 'shipping_item', $refused->error->message() );
		$this->assertCount( 0, $this->fo->all( 'SalesOrderHeadersV2' ) );

		$connection = $this->connection( array( 'shipping_item' => 'SHIP', 'fee_item' => 'SHIP' ) );
		$result     = $this->connector->send( $connection, $this->item( '1016' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->linesOfOrder();
		$this->assertCount( 3, $lines );
		$this->assertSame( 'Courier', $lines[1]['LineDescription'] );
		$this->assertSame( 1.0, $this->fo->all( 'SalesOrderHeadersV2' )[0]['TotalDiscountAmount'] );
	}

	public function test_prices_including_tax_are_declared_on_the_header(): void {
		$this->connector->send( $this->connection, $this->item( '1017' ), $this->payload( array( 'prices_include_tax' => true, 'total' => 5997 ) ) + array() );
		$this->assertSame( 'Yes', $this->fo->all( 'SalesOrderHeadersV2' )[0]['ArePricesIncludingSalesTax'] ?? '' );
	}

	public function test_a_currency_the_legal_entity_does_not_have_is_refused_with_its_own_reason(): void {
		$result = $this->connector->send( $this->connection, $this->item( '1018' ), $this->payload( array( 'currency' => 'SAR' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'SAR', $result->error->message() );
	}

	public function test_three_decimal_currencies_keep_every_digit(): void {
		$payload                                = $this->payload( array( 'currency' => 'KWD', 'total' => 37035 ) );
		$payload['order_line'][0]['price_unit'] = 12345;
		$payload['order_line'][0]['tax']        = 'ZERO';
		$result                                 = $this->connector->send( $this->connection, $this->item( '1019' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 37035, $result->ref->extra['total_minor'] );
		$this->assertSame( 12.345, $this->linesOfOrder()[0]['SalesPrice'] );
	}

	public function test_a_total_that_differs_stops_the_order_and_a_rounding_unit_does_not(): void {
		$stopped = $this->connector->send( $this->connection, $this->item( '1020' ), $this->payload( array( 'total' => 5997 ) ) );
		$this->assertSame( ErrorCode::Val002, $stopped->error->code );
		$this->assertStringContainsString( '59.97 AED', $stopped->error->message() );
		$this->assertStringContainsString( '62.97 AED', $stopped->error->message() );

		$this->assertTrue( $this->connector->send( $this->connection, $this->item( '1021' ), $this->payload( array( 'total' => 6296 ) ) )->isSuccess() );
		$warn = $this->connection( array( 'total_mismatch' => 'warn' ) );
		$this->assertNotEmpty( $this->connector->send( $warn, $this->item( '1022' ), $this->payload( array( 'total' => 5997 ) ) )->ref->extra['warnings'] );
	}

	public function test_a_total_f_and_o_does_not_give_is_not_a_pass(): void {
		$item                     = $this->item( '1023' );
		$this->fo->computesTotals = false;
		$result                   = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertFalse( $result->isSuccess(), 'No total to compare: the order is not reported as synced.' );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'total_mismatch', $result->error->message() );
		$this->assertStringContainsString( '62.97 AED', $result->error->message() );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ), 'The order is not marked as finished.' );
		$this->assertSame( 'WC-1023 (syncing)', $this->fo->all( 'SalesOrderHeadersV2' )[0]['SalesOrderName'] );
		$this->assertCount( 1, $this->linesOfOrder() );

		// F&O fills in the total later (or a person checked the order): the next try finishes the same order.
		$this->fo->computesTotals = true;
		$done                     = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertTrue( $done->isSuccess(), (string) $done->error?->message() );
		$this->assertCount( 1, $this->fo->all( 'SalesOrderHeadersV2' ) );
		$this->assertCount( 1, $this->linesOfOrder() );
		$this->assertSame( 'WC-1023 (1 lines)', $this->fo->all( 'SalesOrderHeadersV2' )[0]['SalesOrderName'] );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $item ) );
	}

	public function test_warn_mode_finishes_an_order_whose_total_f_and_o_does_not_give(): void {
		$this->fo->computesTotals = false;
		$item                     = $this->item( '1046' );
		$result                   = $this->connector->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $item, $this->payload() );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $item ) );
	}

	public function test_an_order_that_failed_its_total_check_is_never_linked_by_a_retry(): void {
		$item   = $this->item( '1047' );
		$failed = $this->connector->send( $this->connection, $item, $this->payload( array( 'total' => 5997 ) ) );
		$this->assertSame( ErrorCode::Val002, $failed->error->code );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ), 'The name still says syncing.' );
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection, $item, $this->payload( array( 'total' => 5997 ) ) )->error->code );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$this->assertCount( 1, $this->fo->all( 'SalesOrderHeadersV2' ) );
		$this->assertCount( 1, $this->fo->all( 'SalesOrderLines' ) );

		$right = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertTrue( $right->isSuccess(), (string) $right->error?->message() );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $item ) );
	}

	public function test_lines_f_and_o_stored_with_another_price_stop_the_order(): void {
		$this->fo->storedPrice = 25.0;
		$item                  = $this->item( '1048' );
		$result                = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'stored the lines differently', $result->error->message() );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
	}

	public function test_a_manual_number_sequence_is_named_and_the_shop_reference_option_numbers_the_order(): void {
		$this->fo->manualNumbering = true;
		$item                      = $this->item( '1049' );
		$refused                   = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertStringContainsString( 'order_numbering', $refused->error->message() );
		$this->assertCount( 0, $this->fo->all( 'SalesOrderHeadersV2' ) );

		$connection = $this->connection( array( 'order_numbering' => 'shop_reference' ) );
		$sent       = $this->connector->send( $connection, $item, $this->payload() );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertSame( 'WC-1049', $this->fo->all( 'SalesOrderHeadersV2' )[0]['SalesOrderNumber'] );
		$this->assertSame( 'WC-1049', $sent->ref->id );
		$again = $this->connector->send( $connection, $item, $this->payload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->fo->all( 'SalesOrderHeadersV2' ) );
		$this->assertCount( 1, $this->linesOfOrder() );
	}

	public function test_automatic_numbering_sends_no_number(): void {
		$this->connector->send( $this->connection, $this->item( '1050' ), $this->payload() );
		$create = array_values( array_filter( $this->kit->http->requests, static fn( array $r ): bool => 'POST' === $r['method'] && str_contains( $r['url'], '/data/SalesOrderHeadersV2' ) ) );
		$this->assertCount( 1, $create );
		$this->assertArrayNotHasKey( 'SalesOrderNumber', json_decode( (string) $create[0]['body'], true ) );
	}

	public function test_a_date_of_the_store_is_the_date_on_the_order(): void {
		// 22:30 UTC on the last day of October is 02:30 on the first of November in Dubai.
		\Estasoft\SyncEngine\Connectors\Microsoft\Dates::resolveWith( static fn() => new \DateTimeZone( 'Asia/Dubai' ) );
		try {
			$this->connector->send( $this->connection, $this->item( '1051' ), $this->payload( array( 'order_date' => '2026-10-31T22:30:00Z' ) ) );
		} finally {
			\Estasoft\SyncEngine\Connectors\Microsoft\Dates::resolveWith( null );
		}
		$this->assertSame( '2026-11-01T12:00:00Z', $this->fo->all( 'SalesOrderHeadersV2' )[0]['RequestedShippingDate'] );
	}

	public function test_the_requested_shipping_date_follows_the_option(): void {
		$connection = $this->connection( array( 'shipping_date' => 'today' ) );
		$this->connector->send( $connection, $this->item( '1024' ), $this->payload() );
		$this->assertSame( gmdate( 'Y-m-d' ) . 'T12:00:00Z', $this->fo->all( 'SalesOrderHeadersV2' )[0]['RequestedShippingDate'] );
	}

	public function test_an_order_changed_by_a_person_is_not_silently_continued(): void {
		$item = $this->item( '1025' );
		$this->connector->send( $this->connection, $item, $this->payload() );
		$this->fo->data['SalesOrderLines'][0]['OrderedSalesQuantity'] = 5;
		$this->fo->data['SalesOrderHeadersV2'][0]['SalesOrderName']   = 'Renamed by a person';
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection, $item, $this->payload() )->error->code );
	}

	public function test_a_payload_that_is_wrong_on_its_face_sends_nothing(): void {
		$payload                              = $this->payload();
		$payload['order_line'][0]['quantity'] = 0;
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $this->connection, $this->item( '1026' ), $payload )->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}

	public function test_a_refund_is_not_a_kind_of_item_this_connector_sends(): void {
		$result = $this->connector->send( $this->connection, $this->item( '1027', \Estasoft\SyncEngine\Domain\EntityType::Refund ), array( 'order_ref' => 'WC-1', 'amount' => 100 ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count() );
	}
}
