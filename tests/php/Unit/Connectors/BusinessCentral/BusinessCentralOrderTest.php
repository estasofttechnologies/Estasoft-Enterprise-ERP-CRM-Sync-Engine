<?php
/**
 * Sending orders to Business Central.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\BusinessCentral;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeBusinessCentralServer;

final class BusinessCentralOrderTest extends BusinessCentralTestCase {

	public function test_an_order_becomes_a_draft_sales_order_with_its_lines_and_a_marker(): void {
		$result = $this->connector->send( $this->connection, $this->orderItem( '1001' ), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$orders = $this->bc->all( 'salesOrders' );
		$this->assertCount( 1, $orders );
		$this->assertSame( 'WC-1001', $orders[0]['externalDocumentNumber'] );
		$this->assertSame( 'AED', $orders[0]['currencyCode'] );
		$this->assertSame( '2026-11-01', $orders[0]['orderDate'] );
		$this->assertSame( '2026-11-01', $orders[0]['postingDate'], 'The shop date is written to the posting date.' );
		$lines = $this->linesOfOnly( 'salesOrders' );
		$this->assertSame( 'Item', $lines[0]['lineType'] );
		$this->assertSame( 'CS-MUG-01', $lines[0]['lineObjectNumber'] );
		$this->assertSame( 19.99, $lines[0]['unitPrice'] );
		$this->assertSame( 3, $lines[0]['quantity'] );
		$this->assertSame( 'VAT5', $lines[0]['taxCode'] );
		$this->assertSame( 'Comment', $lines[1]['lineType'] );
		$this->assertSame( 'WC-1001 synced, 1 lines', $lines[1]['description'] );
		$this->assertSame( 6297, $result->ref->extra['total_minor'] );
		$this->assertSame( $orders[0]['number'], $result->ref->ref );
	}

	public function test_the_same_order_twice_makes_one_document(): void {
		$item = $this->orderItem( '1002' );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ) );
		$first  = $this->connector->send( $this->connection, $item, $this->payload() );
		$second = $this->connector->send( $this->connection, $item, $this->payload() );
		$found  = $this->connector->findExisting( $this->connection, $item );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertSame( $first->ref->id, $found->id );
		$this->assertCount( 1, $this->bc->all( 'salesOrders' ) );
		$this->assertCount( 2, $this->linesOfOnly( 'salesOrders' ) );
	}

	public function test_a_cut_off_pass_continues_without_duplicating_lines(): void {
		$payload                = $this->payload();
		$payload['order_line'][] = array( 'product' => 'CS-TEE-02', 'name' => 'Tee', 'quantity' => 1, 'price_unit' => 1000, 'tax' => 'VAT5' );
		$payload['total']        = 6297 + 1050;
		$item                    = $this->orderItem( '1003' );
		// The second line POST times out (it is the second write to the lines collection).
		$this->bc->failAfterWrites = array( 'salesOrderLines' => 1 );

		$first = $this->connector->send( $this->connection, $item, $payload );
		$this->assertFalse( $first->isSuccess() );
		$this->assertSame( ErrorCode::Net001, $first->error->code );
		$this->assertNull( $this->connector->findExisting( $this->connection, $item ), 'An unfinished document is not reported as found.' );

		$second = $this->connector->send( $this->connection, $item, $payload );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$lines = $this->linesOfOnly( 'salesOrders' );
		$this->assertCount( 3, $lines, 'Two product lines and the marker, none twice.' );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $item ) );
	}

	public function test_a_lost_answer_to_the_header_is_found_again_on_the_retry(): void {
		$this->bc->loseAnswers = array( '/salesOrders' );
		$item                  = $this->orderItem( '1004' );
		$first                 = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertCount( 1, $this->bc->all( 'salesOrders' ), 'The server did create it.' );

		$second = $this->connector->send( $this->connection, $item, $this->payload() );
		$this->assertTrue( $second->isSuccess() );
		$this->assertCount( 1, $this->bc->all( 'salesOrders' ), 'No second header.' );
	}

	public function test_an_unknown_sku_stops_the_order_before_anything_is_created(): void {
		$payload                          = $this->payload();
		$payload['order_line'][0]['product'] = 'NO-SUCH';
		$result                           = $this->connector->send( $this->connection, $this->orderItem( '1005' ), $payload );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertCount( 0, $this->bc->all( 'salesOrders' ) );
		$this->assertCount( 0, $this->bc->all( 'customers' ), 'No customer is made for an order that cannot be sent.' );
	}

	public function test_the_account_policy_posts_an_unknown_sku_to_a_gl_account(): void {
		$connection                          = $this->connection( array( 'unknown_sku' => 'account', 'unknown_sku_account' => '6100' ) );
		$payload                             = $this->payload( array( 'total' => 6297 ) );
		$payload['order_line'][0]['product'] = 'NO-SUCH';
		$result                              = $this->connector->send( $connection, $this->orderItem( '1006' ), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->linesOfOnly( 'salesOrders' );
		$this->assertSame( 'Account', $lines[0]['lineType'] );
		$this->assertSame( '6100', $lines[0]['lineObjectNumber'] );
	}

	public function test_a_blocked_item_is_refused_by_name(): void {
		$this->bc->addItem( 'BLOCKED-1', array( 'blocked' => true ) );
		$payload                             = $this->payload();
		$payload['order_line'][0]['product'] = 'BLOCKED-1';
		$result                              = $this->connector->send( $this->connection, $this->orderItem( '1007' ), $payload );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'BLOCKED-1', $result->error->message() );
	}

	public function test_the_customer_is_found_by_email_ignoring_case_and_not_made_twice(): void {
		$this->bc->addCustomer( 'Jane.DOE@Example.test', 'Jane Existing' );
		$this->connector->send( $this->connection, $this->orderItem( '1008' ), $this->payload() );
		$this->connector->send( $this->connection, $this->orderItem( '1009' ), $this->payload() );

		$this->assertCount( 1, $this->bc->all( 'customers' ) );
		$this->assertSame( '10000', $this->bc->all( 'salesOrders' )[0]['customerNumber'] );
		$this->assertSame( '10000', $this->bc->all( 'salesOrders' )[1]['customerNumber'] );
	}

	public function test_a_service_that_refuses_tolower_still_matches_the_customer(): void {
		$this->bc->odata->supportsCase = false;
		$this->bc->addCustomer( 'jane.doe@example.test', 'Jane Existing' );
		$result = $this->connector->send( $this->connection, $this->orderItem( '1010' ), $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->bc->all( 'customers' ) );
	}

	public function test_a_missing_customer_is_created_with_the_names_as_typed(): void {
		$payload                     = $this->payload();
		$payload['partner']['name']  = 'نور المنصوري';
		$payload['partner']['street'] = 'شارع الشيخ زايد، برج ٢٣';
		$result                      = $this->connector->send( $this->connection, $this->orderItem( '1011' ), $payload );

		$this->assertTrue( $result->isSuccess() );
		$customers = $this->bc->all( 'customers' );
		$this->assertSame( 'نور المنصوري', $customers[0]['displayName'] );
		$this->assertSame( 'شارع الشيخ زايد، برج ٢٣', $customers[0]['addressLine1'] );
		$this->assertSame( 'Person', $customers[0]['type'] );
	}

	public function test_match_only_refuses_an_unknown_buyer_and_a_fixed_customer_needs_no_email(): void {
		$only = $this->connection( array( 'customer_policy' => 'match_only' ) );
		$this->assertSame( ErrorCode::Val002, $this->connector->send( $only, $this->orderItem( '1012' ), $this->payload() )->error->code );

		$this->bc->addCustomer( 'web@example.test', 'Web shop' );
		$fixed   = $this->connection( array( 'customer_policy' => 'fixed', 'fixed_customer' => '10000' ) );
		$payload = $this->payload();
		unset( $payload['partner']['email'] );
		$result = $this->connector->send( $fixed, $this->orderItem( '1013' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->bc->all( 'customers' ) );
	}

	public function test_a_missing_email_is_map_003(): void {
		$payload = $this->payload();
		unset( $payload['partner']['email'] );
		$result = $this->connector->send( $this->connection, $this->orderItem( '1014' ), $payload );
		$this->assertSame( ErrorCode::Map003, $result->error->code );
		$this->assertCount( 0, $this->bc->all( 'salesOrders' ) );
	}

	public function test_a_currency_business_central_does_not_have_is_refused_with_its_own_reason(): void {
		$result = $this->connector->send( $this->connection, $this->orderItem( '1015' ), $this->payload( array( 'currency' => 'SAR' ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'SAR', $result->error->message() );
	}

	public function test_three_decimal_currencies_keep_every_digit(): void {
		$payload                             = $this->payload( array( 'currency' => 'KWD', 'total' => 37035 ) );
		$payload['order_line'][0]['price_unit'] = 12345;
		$payload['order_line'][0]['tax']        = 'ZERO';
		$result                              = $this->connector->send( $this->connection, $this->orderItem( '1016' ), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 37035, $result->ref->extra['total_minor'] );
		$this->assertSame( 12.345, $this->linesOfOnly( 'salesOrders' )[0]['unitPrice'] );
	}

	public function test_a_total_that_differs_stops_the_order_and_names_both_totals(): void {
		$result = $this->connector->send( $this->connection, $this->orderItem( '1017' ), $this->payload( array( 'total' => 5997 ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '59.97 AED', $result->error->message() );
		$this->assertStringContainsString( '62.97 AED', $result->error->message() );
	}

	public function test_a_difference_of_one_minor_unit_is_rounding(): void {
		$result = $this->connector->send( $this->connection, $this->orderItem( '1018' ), $this->payload( array( 'total' => 6296 ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
	}

	public function test_warn_mode_accepts_a_difference_and_reports_it(): void {
		$connection = $this->connection( array( 'total_mismatch' => 'warn' ) );
		$result     = $this->connector->send( $connection, $this->orderItem( '1019' ), $this->payload( array( 'total' => 5997 ) ) );

		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_prices_with_tax_against_a_customer_without_are_named_in_the_error(): void {
		$result = $this->connector->send( $this->connection, $this->orderItem( '1020' ), $this->payload( array( 'prices_include_tax' => true, 'total' => 5997 ) ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'without tax', $result->error->message() );
	}

	public function test_prices_including_tax_agree_when_the_customer_has_the_same_setting(): void {
		$this->bc->pricesIncludeTax = true;
		$result                     = $this->connector->send( $this->connection, $this->orderItem( '1021' ), $this->payload( array( 'prices_include_tax' => true, 'total' => 5997 ) ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 5997, $result->ref->extra['total_minor'] );
	}

	public function test_shipping_fee_and_discount_need_their_target_and_become_lines(): void {
		$payload             = $this->payload( array( 'total' => 6297 + 525 - 105 ) );
		$payload['shipping'] = array( 'total' => 500, 'name' => 'Courier', 'tax' => 'VAT5' );
		$payload['discount'] = array( 'total' => 100, 'name' => 'SPRING', 'tax' => 'VAT5' );
		$refused             = $this->connector->send( $this->connection, $this->orderItem( '1022' ), $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertStringContainsString( 'shipping_item', $refused->error->message() );
		$this->assertCount( 0, $this->bc->all( 'salesOrders' ) );

		$connection = $this->connection( array( 'shipping_item' => 'SHIP', 'discount_item' => 'SHIP' ) );
		$result     = $this->connector->send( $connection, $this->orderItem( '1022' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->linesOfOnly( 'salesOrders' );
		$this->assertSame( 'Courier', $lines[1]['description'] );
		$this->assertSame( 5.0, $lines[1]['unitPrice'] );
		$this->assertSame( -1.0, $lines[2]['unitPrice'] );
	}

	public function test_a_payload_that_is_wrong_on_its_face_sends_nothing(): void {
		$payload                              = $this->payload();
		$payload['order_line'][0]['price_unit'] = 19.99;
		$result                               = $this->connector->send( $this->connection, $this->orderItem( '1023' ), $payload );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 0, $this->kit->http->count(), 'Nothing is asked of Business Central for a payload that is wrong on its face.' );
	}

	public function test_ship_and_invoice_posts_once_and_the_order_date_is_kept(): void {
		$connection = $this->connection( array( 'documents' => 'order_ship_invoice' ) );
		$item       = $this->orderItem( '1030' );
		$result     = $this->connector->send( $connection, $item, $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 0, $this->bc->all( 'salesOrders' ), 'A shipped and invoiced order is gone.' );
		$invoices = $this->bc->all( 'salesInvoices' );
		$this->assertCount( 1, $invoices );
		$this->assertSame( 'Open', $invoices[0]['status'] );
		$this->assertSame( '2026-11-01', $invoices[0]['invoiceDate'] );
		$this->assertSame( $invoices[0]['id'], $result->ref->id );
		$this->assertSame( array( '*' ), array_unique( array_column( $this->bc->actions, 'if_match' ) ) );

		$again = $this->connector->send( $connection, $item, $this->payload() );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, array_filter( $this->bc->actions, static fn( array $a ): bool => 'salesOrders.shipAndInvoice' === $a['action'] ), 'Never posted twice.' );
		$this->assertNotNull( $this->connector->findExisting( $connection, $item ) );
	}

	public function test_a_lost_answer_to_shipping_is_not_replayed(): void {
		$connection            = $this->connection( array( 'documents' => 'order_ship_invoice' ) );
		$item                  = $this->orderItem( '1031' );
		$this->bc->loseAnswers = array( 'Microsoft.NAV.shipAndInvoice' );
		$first                 = $this->connector->send( $connection, $item, $this->payload() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertNotNull( $this->connector->findExisting( $connection, $item ), 'The posted invoice is there, so the engine links it without sending again.' );

		$second = $this->connector->send( $connection, $item, $this->payload() );
		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertCount( 1, array_filter( $this->bc->actions, static fn( array $a ): bool => 'salesOrders.shipAndInvoice' === $a['action'] ) );
		$this->assertCount( 1, $this->bc->all( 'salesInvoices' ) );
	}

	public function test_invoice_mode_posts_the_draft_and_returns_the_posted_number(): void {
		$connection = $this->connection( array( 'documents' => 'invoice' ) );
		$item       = $this->orderItem( '1032' );
		$result     = $this->connector->send( $connection, $item, $this->payload() );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertStringStartsWith( 'PINV-', $result->ref->ref, 'The posted number, not the draft number.' );
		$this->assertSame( 'Open', $this->bc->all( 'salesInvoices' )[0]['status'] );
		$this->assertCount( 0, $this->bc->all( 'salesOrders' ) );
		$this->assertNotNull( $this->connector->findExisting( $connection, $item ) );
	}

	public function test_a_failure_before_posting_leaves_a_draft_that_the_next_pass_posts(): void {
		$connection                = $this->connection( array( 'documents' => 'invoice' ) );
		$item                      = $this->orderItem( '1033' );
		$this->bc->failAfterWrites = array( 'Microsoft.NAV.post' => 0 );
		$first                     = $this->connector->send( $connection, $item, $this->payload() );
		$this->assertFalse( $first->isSuccess() );
		$this->assertNull( $this->connector->findExisting( $connection, $item ), 'A draft invoice is not finished.' );

		$second = $this->connector->send( $connection, $item, $this->payload() );
		$this->assertTrue( $second->isSuccess() );
		$this->assertCount( 1, $this->bc->all( 'salesInvoices' ) );
	}

	public function test_an_order_invoiced_by_a_person_is_not_created_again(): void {
		$connection = $this->connection( array( 'documents' => 'order' ) );
		$item       = $this->orderItem( '1034' );
		$this->connector->send( $connection, $item, $this->payload() );
		// A person ships and invoices the order in Business Central.
		$this->postByHand( (string) $this->bc->all( 'salesOrders' )[0]['id'] );

		$result = $this->connector->send( $connection, $item, $this->payload() );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertCount( 0, $this->bc->all( 'salesOrders' ) );
	}

	/**
	 * Ship and invoice an order the way a person does in the web client.
	 *
	 * @param string $orderId Order GUID.
	 */
	private function postByHand( string $orderId ): void {
		$this->kit->http->request( 'POST', 'https://api.businesscentral.dynamics.com/v2.0/production/api/v2.0/companies(' . FakeBusinessCentralServer::COMPANY_ID . ')/salesOrders(' . $orderId . ')/Microsoft.NAV.shipAndInvoice', array( 'Authorization' => 'Bearer ' . $this->bc->entra->issued[0], 'If-Match' => '*' ), null, 30 );
	}
}
