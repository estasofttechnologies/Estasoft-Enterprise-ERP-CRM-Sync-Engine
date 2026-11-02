<?php
/**
 * Orders, customers, refunds and the optional steps of the Fortnox connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Fortnox;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;

/**
 * Everything above the wire, against the fake Fortnox.
 */
final class FortnoxOrdersTest extends FortnoxTestCase {

	public function test_an_order_becomes_an_invoice_with_the_reference_the_customer_and_the_rows(): void {
		list( $item, $payload ) = $this->order( '1001' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertCount( 1, $this->server->invoices );
		$invoice = array_values( $this->server->invoices )[0];
		$this->assertSame( 'WC-1001', $invoice['ExternalInvoiceReference1'] );
		$this->assertSame( 'WooCommerce', $invoice['ExternalInvoiceReference2'], 'The pending marker is gone.' );
		$this->assertSame( '2026-10-30', $invoice['InvoiceDate'], 'The shop\'s order date.' );
		$this->assertSame( '2026-10-30', $invoice['DueDate'] );
		$this->assertFalse( $invoice['VATIncluded'] );
		$posted = array_values( array_filter( $this->server->posted, static fn( array $p ): bool => 'invoices' === $p['path'] ) )[0]['body'];
		$this->assertArrayNotHasKey( 'Currency', $posted, 'SEK is not sent.' );
		$this->assertSame( 'Leave at the door', $invoice['Comments'] );
		$this->assertEquals( array( 'ArticleNumber' => 'CS-TEE-02', 'Description' => 'Tee', 'DeliveredQuantity' => 1, 'Price' => 100.0, 'VAT' => 25 ), $invoice['InvoiceRows'][0] );
		$this->assertSame( 145.0, $invoice['Total'] );
		$this->assertSame( 14500, $result->ref->extra['total_minor'] );
		$customer = array_values( $this->server->customers )[0];
		$this->assertSame( 'layla@example.test', $customer['Email'] );
		$this->assertSame( 'PRIVATE', $customer['Type'] );
		$this->assertSame( 'SE', $customer['CountryCode'] );
	}

	public function test_the_second_send_finds_the_invoice_and_makes_no_second_one(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '1002' );
		$first  = $this->connector->send( $connection, $item, $payload );
		$second = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->server->invoices );
		$this->assertCount( 1, $this->server->customers );
		$found = $this->connector->findExisting( $connection, $item );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $first->ref->id, $found->id );
	}

	public function test_the_filter_of_fortnox_may_match_by_contains_so_the_reference_is_compared_exactly(): void {
		$connection = $this->connection();
		list( $long, $payload ) = $this->order( '12' );
		$this->connector->send( $connection, $long, $payload );
		list( $short ) = $this->order( '1' );
		$this->assertNull( $this->connector->findExisting( $connection, $short ), 'WC-1 must not match WC-12.' );
	}

	public function test_a_customer_is_found_by_the_exact_email_not_by_a_longer_one_that_contains_it(): void {
		$this->server->addCustomer( 'xlayla@example.test.se', 'Someone else' );
		$wanted = $this->server->addCustomer( 'Layla@Example.test', 'Layla Haddad' );
		list( $item, $payload ) = $this->order( '1003' );
		$this->assertTrue( $this->connector->send( $this->connection(), $item, $payload )->isSuccess() );
		$this->assertCount( 2, $this->server->customers );
		$this->assertSame( $wanted, array_values( $this->server->invoices )[0]['CustomerNumber'] );
	}

	public function test_customers_and_documents_on_later_pages_are_found(): void {
		$this->server->pageSize = 1;
		$this->server->addCustomer( 'a1@example.test' );
		$this->server->addCustomer( 'layla@example.test.se' );
		$this->server->addCustomer( 'layla@example.test' );
		$connection = $this->connection();
		$this->assertInstanceOf( ExternalRef::class, $this->connector->lookupCustomer( $connection, 'layla@example.test' ) );
		list( $a, $pa ) = $this->order( '2001' );
		list( $b, $pb ) = $this->order( '2002' );
		$this->connector->send( $connection, $a, $pa );
		$this->connector->send( $connection, $b, $pb );
		$this->assertTrue( $this->connector->send( $connection, $b, $pb )->alreadyExisted );
		$this->assertCount( 2, $this->server->invoices );
	}

	public function test_an_unknown_article_stops_the_order_before_anything_is_created(): void {
		list( $item, $payload ) = $this->order( '1004' );
		$payload['order_line'][0]['product'] = 'NO-SUCH';
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertSame( array(), $this->server->customers );
		$this->assertSame( array(), $this->server->invoices );
	}

	public function test_free_text_makes_a_row_without_an_article_number_for_an_unknown_sku(): void {
		list( $item, $payload ) = $this->order( '1005' );
		$payload['order_line'][0]['product'] = 'NO-SUCH';
		$result = $this->connector->send( $this->connection( array( 'options' => array( 'unknown_sku' => 'free_text' ) ) ), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$row = array_values( $this->server->invoices )[0]['InvoiceRows'][0];
		$this->assertArrayNotHasKey( 'ArticleNumber', $row );
		$this->assertSame( 'Tee', $row['Description'] );
	}

	public function test_a_missing_email_is_map_003_and_match_only_refuses_an_unknown_buyer(): void {
		list( $item, $payload ) = $this->order( '1006' );
		$payload['partner']['email'] = '';
		$this->assertSame( ErrorCode::Map003, $this->connector->send( $this->connection(), $item, $payload )->error->code );
		list( $item2, $payload2 ) = $this->order( '1007' );
		$result = $this->connector->send( $this->connection( array( 'options' => array( 'customer_policy' => 'match_only' ) ), array(), 'fx-access-1' ), $item2, $payload2 );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->server->customers );
	}

	public function test_shipping_fees_and_discount_are_rows_of_text(): void {
		list( $item, $payload ) = $this->order( '1008', array(
			'shipping' => array( 'name' => 'Shipping', 'total' => 500, 'tax' => '25' ),
			'fee'      => array( array( 'name' => 'Gift wrap', 'total' => 100, 'tax' => '0' ) ),
			'discount' => array( 'name' => 'SAVE5', 'total' => 500, 'tax' => '0' ),
			'total'    => 14500 + 625 + 100 - 500,
		) );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$rows = array_values( $this->server->invoices )[0]['InvoiceRows'];
		$this->assertCount( 5, $rows );
		$this->assertArrayNotHasKey( 'ArticleNumber', $rows[2] );
		$this->assertSame( -5.0, $rows[4]['Price'] );
	}

	public function test_prices_with_vat_are_sent_as_the_shop_shows_them_with_the_vat_included_flag(): void {
		list( $item, $payload ) = $this->order( '1009', array( 'prices_include_tax' => true, 'total' => 14100, 'order_line' => array(
			array( 'product' => 'CS-TEE-02', 'name' => 'Tee', 'quantity' => 1, 'price_unit' => 12100, 'tax' => '25' ),
			array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'price_unit' => 2000, 'tax' => '0' ),
		) ) );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$invoice = array_values( $this->server->invoices )[0];
		$this->assertTrue( $invoice['VATIncluded'] );
		$this->assertSame( 121.0, $invoice['InvoiceRows'][0]['Price'] );
	}

	public function test_a_total_that_fortnox_computes_differently_fails_loudly_and_the_pending_invoice_is_not_linked_or_duplicated(): void {
		$this->server->totalFactor = 1.1;
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '1010' );
		$first = $this->connector->send( $connection, $item, $payload );
		$this->assertSame( ErrorCode::Val002, $first->error->code );
		$this->assertSame( 'total', $first->error->context['field'] );
		$this->assertStringContainsString( '145.00', $first->error->context['reason'] );
		$this->assertCount( 1, $this->server->invoices );
		$this->assertSame( 'sync-pending', array_values( $this->server->invoices )[0]['ExternalInvoiceReference2'] );
		$this->assertNull( $this->connector->findExisting( $connection, $item ), 'Never linked unchecked.' );
		$again = $this->connector->send( $connection, $item, $payload );
		$this->assertSame( ErrorCode::Val002, $again->error->code );
		$this->assertCount( 1, $this->server->invoices, 'The retry did not make a second invoice.' );
	}

	public function test_a_cancelled_pending_invoice_lets_the_retry_make_a_new_one(): void {
		$this->server->totalFactor = 1.1;
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '1011' );
		$this->connector->send( $connection, $item, $payload );
		$number = (int) array_key_first( $this->server->invoices );
		$this->server->invoices[ $number ]['Cancelled'] = true;
		$this->server->totalFactor = 1.0;
		$result = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 2, $this->server->invoices );
	}

	public function test_a_difference_is_accepted_with_a_warning_when_the_option_says_warn(): void {
		$this->server->totalFactor = 1.1;
		list( $item, $payload ) = $this->order( '1012' );
		$result = $this->connector->send( $this->connection( array( 'options' => array( 'total_mismatch' => 'warn' ) ) ), $item, $payload );
		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_a_foreign_currency_is_sent_and_checked_in_that_currency(): void {
		list( $item, $payload ) = $this->order( '1013', array( 'currency' => 'EUR' ) );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'EUR', array_values( $this->server->invoices )[0]['Currency'] );
		$this->assertSame( 'EUR', $result->ref->extra['currency'] );
	}

	public function test_arabic_text_is_unchanged_and_a_long_row_text_is_cut_with_a_warning(): void {
		$name = 'نور المنصوري';
		list( $item, $payload ) = $this->order( '1014', array( 'partner' => array( 'name' => $name, 'email' => 'noor@example.test' ) ) );
		$payload['order_line'][0]['name'] = str_repeat( 'ا', 80 );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( $name, array_values( $this->server->customers )[0]['Name'] );
		$text = (string) array_values( $this->server->invoices )[0]['InvoiceRows'][0]['Description'];
		$this->assertSame( 50, mb_strlen( $text ) );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_a_company_buyer_becomes_a_company_customer(): void {
		list( $item, $payload ) = $this->order( '1015', array( 'partner' => array( 'name' => 'Layla', 'company_name' => 'Haddad Trading AB', 'email' => 'co@example.test', 'vat' => 'SE556000016701' ) ) );
		$this->connector->send( $this->connection(), $item, $payload );
		$customer = array_values( $this->server->customers )[0];
		$this->assertSame( 'COMPANY', $customer['Type'] );
		$this->assertSame( 'Haddad Trading AB', $customer['Name'] );
		$this->assertSame( 'SE556000016701', $customer['VATNumber'] );
	}

	public function test_a_customer_item_finds_or_creates_the_customer(): void {
		$connection = $this->connection();
		$item       = new Item( 5, 1, EntityType::Customer, '5', 'created', 'customer:5', 'c-5', ItemState::Syncing );
		$payload    = array( 'partner' => array( 'name' => 'Sara Ali', 'email' => 'sara@example.test' ) );
		$first      = $this->connector->send( $connection, $item, $payload );
		$second     = $this->connector->send( $connection, $item, $payload );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->server->customers );
	}

	public function test_an_order_in_order_mode_makes_a_fortnox_order_with_ordered_quantities(): void {
		list( $item, $payload ) = $this->order( '1016' );
		$result = $this->connector->send( $this->connection( array( 'options' => array( 'document' => 'order' ) ) ), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( array(), $this->server->invoices );
		$order = array_values( $this->server->orders )[0];
		$this->assertSame( 1, $order['OrderRows'][0]['OrderedQuantity'] );
		$this->assertSame( 'order', $result->ref->extra['document'] );
	}

	public function test_bookkeeping_and_payment_run_once_and_a_replay_repeats_neither(): void {
		$connection = $this->connection( array( 'options' => array( 'bookkeep' => 'yes', 'record_payment' => 'yes', 'payment_mode' => 'KORT' ) ) );
		list( $item, $payload ) = $this->order( '1017' );
		$result = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$invoice = array_values( $this->server->invoices )[0];
		$this->assertTrue( $invoice['Booked'] );
		$this->assertSame( 0.0, $invoice['Balance'] );
		$this->assertCount( 1, $this->server->payments );
		$this->assertSame( array( 'InvoiceNumber' => $invoice['DocumentNumber'], 'Amount' => 145.0, 'PaymentDate' => '2026-10-30', 'ModeOfPayment' => 'KORT' ), $this->server->payments[0] );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $connection, $item ) );
		$again = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->server->payments, 'Never paid twice.' );
		$this->assertCount( 1, $this->logged( '#PUT /3/invoices/\d+/bookkeep#' ), 'Never booked twice.' );
	}

	public function test_a_lost_answer_after_bookkeeping_is_picked_up_and_only_the_payment_is_made(): void {
		$connection = $this->connection( array( 'options' => array( 'bookkeep' => 'yes', 'record_payment' => 'yes', 'payment_mode' => 'KORT' ) ) );
		list( $item, $payload ) = $this->order( '1018' );
		$this->connector->send( $connection, $item, $payload );
		$number = (int) array_key_first( $this->server->invoices );
		$this->server->invoices[ $number ]['Balance'] = 145.0;
		$this->server->payments = array();
		$this->assertNull( $this->connector->findExisting( $connection, $item ), 'Booked but not paid: not finished.' );
		$result = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertCount( 1, $this->server->payments );
		$this->assertCount( 1, $this->logged( '#PUT /3/invoices/\d+/bookkeep#' ), 'The booked invoice was not booked again.' );
	}

	public function test_an_unpaid_order_is_booked_but_not_paid(): void {
		$connection = $this->connection( array( 'options' => array( 'bookkeep' => 'yes', 'record_payment' => 'yes', 'payment_mode' => 'KORT' ) ) );
		list( $item, $payload ) = $this->order( '1019', array( 'paid_at' => '' ) );
		$this->assertTrue( $this->connector->send( $connection, $item, $payload )->isSuccess() );
		$this->assertSame( array(), $this->server->payments );
	}

	public function test_payment_needs_a_mode_and_a_swedish_krona_invoice(): void {
		list( $item, $payload ) = $this->order( '1020' );
		$noMode = $this->connection( array( 'options' => array( 'bookkeep' => 'yes', 'record_payment' => 'yes' ) ) );
		$this->assertSame( 'payment_mode', $this->connector->send( $noMode, $item, $payload )->error->context['field'] );
		list( $item2, $payload2 ) = $this->order( '1021', array( 'currency' => 'EUR' ) );
		$eur = $this->connection( array( 'options' => array( 'bookkeep' => 'yes', 'record_payment' => 'yes', 'payment_mode' => 'KORT' ) ), array(), 'fx-access-2' );
		$this->assertSame( 'currency', $this->connector->send( $eur, $item2, $payload2 )->error->context['field'] );
		$this->assertSame( array(), $this->server->payments );
	}

	public function test_the_due_date_follows_the_due_days_option(): void {
		list( $item, $payload ) = $this->order( '1022' );
		$this->connector->send( $this->connection( array( 'options' => array( 'due_days' => '14' ) ) ), $item, $payload );
		$this->assertSame( '2026-11-13', array_values( $this->server->invoices )[0]['DueDate'] );
	}

	public function test_a_refund_becomes_a_credit_invoice_with_negative_rows_and_both_references(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '3001' );
		$this->connector->send( $connection, $item, $payload );
		list( $refund, $refundPayload ) = $this->refund( '3002', '3001' );
		$result = $this->connector->send( $connection, $refund, $refundPayload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$credit = array_values( $this->server->invoices )[1];
		$this->assertSame( 'WC-3002', $credit['ExternalInvoiceReference1'] );
		$this->assertSame( 'WC-3001', $credit['ExternalInvoiceReference2'] );
		$this->assertSame( -1, $credit['InvoiceRows'][0]['DeliveredQuantity'] );
		$this->assertSame( 20.0, $credit['InvoiceRows'][0]['Price'] );
		$this->assertSame( -20.0, $credit['Total'] );
		$this->assertSame( array_values( $this->server->invoices )[0]['CustomerNumber'], $credit['CustomerNumber'] );
		$this->assertSame( 2000, $result->ref->extra['total_minor'] );
		$again = $this->connector->send( $connection, $refund, $refundPayload );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 2, $this->server->invoices );
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $connection, $refund ) );
	}

	public function test_a_refund_without_its_order_fails_before_anything_is_created_and_a_wrong_amount_fails_loudly(): void {
		$connection = $this->connection();
		list( $refund, $payload ) = $this->refund( '3004', '3003' );
		$this->assertSame( 'order_ref', $this->connector->send( $connection, $refund, $payload )->error->context['field'] );
		$this->assertSame( array(), $this->server->invoices );
		list( $item, $orderPayload ) = $this->order( '3005' );
		$this->connector->send( $connection, $item, $orderPayload );
		list( $wrong, $wrongPayload ) = $this->refund( '3006', '3005', array( 'amount' => 2500 ) );
		$this->assertSame( 'total', $this->connector->send( $connection, $wrong, $wrongPayload )->error->context['field'] );
	}

	public function test_a_refund_with_vat_matches_when_the_amount_includes_the_vat(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '3007' );
		$this->connector->send( $connection, $item, $payload );
		list( $refund, $refundPayload ) = $this->refund( '3008', '3007', array( 'amount' => 6250, 'refund_line' => array( array( 'product' => 'CS-TEE-02', 'name' => 'Tee', 'quantity' => 1, 'amount' => 5000, 'tax' => '25' ) ) ) );
		$result = $this->connector->send( $connection, $refund, $refundPayload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 6250, $result->ref->extra['total_minor'] );
	}
}
