<?php
/**
 * Orders, customers and duplicate protection of the Exact Online connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Exact;

use Estasoft\SyncEngine\Connectors\Exact\ExactDocuments;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Everything above the wire, against the fake Exact Online.
 */
final class ExactOrdersTest extends ExactTestCase {

	public function test_an_order_becomes_an_invoice_with_the_reference_the_customer_and_the_lines(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '1001' );
		$result = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertCount( 1, $this->server->invoices );
		$invoice = array_values( $this->server->invoices )[0];
		$this->assertSame( 'WC-1001', $invoice['YourRef'] );
		$this->assertSame( 'WC-1001 Layla Haddad', $invoice['Description'], 'The pending marker is gone.' );
		$this->assertSame( 'EUR', $invoice['Currency'] );
		$this->assertSame( 141.0, (float) $invoice['AmountFC'], '100.00 + 21 percent VAT + 20.00 without VAT.' );
		$this->assertSame( '2026-10-30T00:00:00', $invoice['InvoiceDate'], 'The shop\'s order date is sent as the invoice date.' );
		$this->assertSame( '70', $invoice['Journal'] );
		$this->assertCount( 2, $invoice['Lines'] );
		$this->assertCount( 1, $this->server->accounts );
		$account = array_values( $this->server->accounts )[0];
		$this->assertSame( 'layla@example.test', $account['Email'] );
		$this->assertSame( 'C', $account['Status'] );
		$this->assertSame( 14100, $result->ref->extra['total_minor'] );
	}

	public function test_the_second_send_finds_the_invoice_and_makes_no_second_one(): void {
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '1002' );
		$first  = $this->connector->send( $connection, $item, $payload );
		$second = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->server->invoices );
		$this->assertCount( 1, $this->server->accounts, 'The account is found by email, not made twice.' );
		$found = $this->connector->findExisting( $connection, $item );
		$this->assertInstanceOf( ExternalRef::class, $found );
		$this->assertSame( $first->ref->id, $found->id );
	}

	public function test_a_reference_is_not_found_by_a_longer_number_that_starts_the_same_way(): void {
		$connection = $this->connection();
		list( $long, $payload ) = $this->order( '12' );
		$this->assertTrue( $this->connector->send( $connection, $long, $payload )->isSuccess() );
		list( $short ) = $this->order( '1' );
		$this->assertNull( $this->connector->findExisting( $connection, $short ), 'WC-1 must not match WC-12.' );
	}

	public function test_an_existing_customer_is_used_whatever_the_letter_case_of_the_email(): void {
		$this->server->addAccount( 'Layla@Example.test', 'Layla Haddad' );
		list( $item, $payload ) = $this->order( '1003' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->server->accounts );
		$this->assertSame( array_key_first( $this->server->accounts ), array_values( $this->server->invoices )[0]['OrderedBy'] );
	}

	public function test_customers_on_a_second_page_are_found(): void {
		$this->server->pageSize = 2;
		$this->server->addAccount( 'x1@example.test' );
		$this->server->addAccount( 'x2@example.test' );
		$this->server->addAccount( 'layla@example.test' );
		$ref = $this->connector->lookupCustomer( $this->connection(), 'layla@example.test' );
		$this->assertInstanceOf( ExternalRef::class, $ref );
		$this->assertSame( 'layla@example.test', $ref->extra['email'] );
	}

	public function test_documents_on_a_second_page_are_found_by_reference_before_a_create(): void {
		$connection = $this->connection();
		$this->server->pageSize = 1;
		list( $a, $pa ) = $this->order( '2001' );
		list( $b, $pb ) = $this->order( '2002' );
		$this->connector->send( $connection, $a, $pa );
		$this->connector->send( $connection, $b, $pb );
		$again = $this->connector->send( $connection, $b, $pb );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 2, $this->server->invoices );
	}

	public function test_an_unknown_sku_stops_the_order_before_anything_is_created(): void {
		list( $item, $payload ) = $this->order( '1004' );
		$payload['order_line'][0]['product'] = 'NO-SUCH-SKU';
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertSame( 'NO-SUCH-SKU', $result->error->context['sku'] );
		$this->assertSame( array(), $this->server->accounts );
		$this->assertSame( array(), $this->server->invoices );
	}

	public function test_an_unknown_vat_code_is_map_002_and_nothing_is_created(): void {
		list( $item, $payload ) = $this->order( '1005' );
		$payload['order_line'][0]['tax'] = '77';
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Map002, $result->error->code );
		$this->assertSame( array(), $this->server->invoices );
		$this->assertSame( array(), $this->server->accounts );
	}

	public function test_a_missing_email_is_map_003(): void {
		list( $item, $payload ) = $this->order( '1006' );
		$payload['partner']['email'] = '';
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Map003, $result->error->code );
		$this->assertSame( array(), $this->server->invoices );
	}

	public function test_match_only_refuses_a_buyer_that_exists_nowhere(): void {
		$connection = $this->connection( array( 'options' => array( 'customer_policy' => 'match_only' ) ) );
		list( $item, $payload ) = $this->order( '1007' );
		$result = $this->connector->send( $connection, $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->server->accounts );
	}

	public function test_shipping_needs_an_item_in_the_options_and_is_sent_as_a_line_when_it_has_one(): void {
		list( $item, $payload ) = $this->order( '1008', array( 'shipping' => array( 'name' => 'Shipping', 'total' => 500, 'tax' => '0' ), 'total' => 14600 ) );
		$refused = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertSame( 'shipping_item', $refused->error->context['field'] );
		$this->assertSame( array(), $this->server->invoices );
		$connection = $this->connection( array( 'options' => array( 'shipping_item' => 'SHIP-01' ) ) );
		$sent       = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$this->assertCount( 3, array_values( $this->server->invoices )[0]['Lines'] );
		$this->assertSame( 14600, $sent->ref->extra['total_minor'] );
	}

	public function test_a_discount_is_a_negative_line(): void {
		$connection = $this->connection( array( 'options' => array( 'discount_item' => 'SHIP-01' ) ) );
		list( $item, $payload ) = $this->order( '1009', array( 'discount' => array( 'name' => 'SAVE5', 'total' => 500, 'tax' => '0' ), 'total' => 13600 ) );
		$sent = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $sent->isSuccess(), (string) $sent->error?->message() );
		$lines = array_values( $this->server->invoices )[0]['Lines'];
		$this->assertSame( -5.0, $lines[2]['UnitPrice'] );
	}

	public function test_a_total_that_exact_computes_differently_fails_loudly_and_the_open_invoice_is_removed(): void {
		$this->server->totalFactor = 1.1;
		list( $item, $payload ) = $this->order( '1010' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'total', $result->error->context['field'] );
		$this->assertStringContainsString( '141.00', $result->error->context['reason'] );
		$this->assertSame( array(), $this->server->invoices, 'The wrong invoice was deleted again.' );
	}

	public function test_when_the_wrong_invoice_cannot_be_deleted_it_stays_pending_and_the_retry_fails_the_same_way_without_a_second_one(): void {
		$this->server->totalFactor  = 1.1;
		$this->server->allowDelete  = false;
		$connection = $this->connection();
		list( $item, $payload ) = $this->order( '1011' );
		$first = $this->connector->send( $connection, $item, $payload );
		$this->assertSame( ErrorCode::Val002, $first->error->code );
		$this->assertStringContainsString( 'deleted there by a person', $first->error->context['reason'] );
		$this->assertCount( 1, $this->server->invoices );
		$this->assertNull( $this->connector->findExisting( $connection, $item ), 'A pending invoice is never linked unchecked.' );
		$again = $this->connector->send( $connection, $item, $payload );
		$this->assertSame( ErrorCode::Val002, $again->error->code );
		$this->assertCount( 1, $this->server->invoices, 'The retry found the invoice and did not make another.' );
	}

	public function test_a_difference_is_accepted_with_a_warning_when_the_option_says_warn(): void {
		$this->server->totalFactor = 1.1;
		$connection = $this->connection( array( 'options' => array( 'total_mismatch' => 'warn' ) ) );
		list( $item, $payload ) = $this->order( '1012' );
		$result = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_a_currency_that_exact_records_differently_is_refused(): void {
		$this->server->forceCurrency = 'USD';
		list( $item, $payload ) = $this->order( '1013' );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'USD', $result->error->context['reason'] );
	}

	public function test_prices_with_vat_are_converted_to_net_with_whole_numbers(): void {
		list( $item, $payload ) = $this->order( '1014', array(
			'prices_include_tax' => true,
			'total'              => 14100,
			'order_line'         => array(
				array( 'product' => 'CS-TEE-02', 'name' => 'Tee', 'quantity' => 1, 'price_unit' => 12100, 'tax' => '2' ),
				array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'price_unit' => 2000, 'tax' => '0' ),
			),
		) );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = array_values( $this->server->invoices )[0]['Lines'];
		$this->assertSame( 100.0, $lines[0]['UnitPrice'], '121.00 with 21 percent is 100.00 net.' );
		$this->assertSame( 20.0, $lines[1]['UnitPrice'] );
	}

	public function test_prices_with_vat_and_a_line_without_a_vat_code_are_refused_naming_the_field(): void {
		list( $item, $payload ) = $this->order( '1015', array( 'prices_include_tax' => true ) );
		unset( $payload['order_line'][0]['tax'] );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( 'order_line.tax', $result->error->context['field'] );
		$this->assertSame( array(), $this->server->invoices );
	}

	public function test_arabic_text_is_sent_unchanged_and_a_long_line_text_is_cut_with_a_warning(): void {
		$name = 'نور المنصوري';
		list( $item, $payload ) = $this->order( '1016', array( 'partner' => array( 'name' => $name, 'email' => 'noor@example.test' ) ) );
		$payload['order_line'][0]['name'] = str_repeat( 'ا', 80 );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( $name, array_values( $this->server->accounts )[0]['Name'] );
		$text = (string) array_values( $this->server->invoices )[0]['Lines'][0]['Description'];
		$this->assertSame( 60, mb_strlen( $text ) );
		$this->assertStringEndsWith( "\u{2026}", $text );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_a_three_decimal_currency_keeps_its_exponent(): void {
		list( $item, $payload ) = $this->order( '1017', array( 'currency' => 'KWD', 'total' => 37035, 'order_line' => array( array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 3, 'price_unit' => 12345, 'tax' => '0' ) ) ) );
		$result = $this->connector->send( $this->connection(), $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 12.345, array_values( $this->server->invoices )[0]['Lines'][0]['UnitPrice'] );
		$this->assertSame( 37035, $result->ref->extra['total_minor'] );
	}

	public function test_a_customer_item_finds_or_creates_the_account(): void {
		$connection = $this->connection();
		$item       = new \Estasoft\SyncEngine\Domain\Item( 5, 1, \Estasoft\SyncEngine\Domain\EntityType::Customer, '5', 'created', 'customer:5', 'c-5', \Estasoft\SyncEngine\Domain\ItemState::Syncing );
		$payload    = array( 'partner' => array( 'name' => 'Sara Ali', 'email' => 'sara@example.test', 'country_id' => 'NL' ) );
		$first      = $this->connector->send( $connection, $item, $payload );
		$second     = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $first->isSuccess() );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertCount( 1, $this->server->accounts );
	}

	public function test_an_order_in_sales_order_mode_makes_a_sales_order_without_a_journal(): void {
		$connection = $this->connection( array( 'options' => array( 'document' => 'order' ) ) );
		list( $item, $payload ) = $this->order( '1018' );
		$result = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( array(), $this->server->invoices );
		$this->assertCount( 1, $this->server->orders );
		$this->assertSame( 'order', $result->ref->extra['document'] );
		$this->assertSame( array(), $this->logged( '#financial/Journals#' ), 'Orders need no journal.' );
	}

	public function test_process_invoice_processes_once_and_a_replay_does_not_process_again(): void {
		$connection = $this->connection( array( 'options' => array( 'process_invoice' => 'yes' ) ) );
		list( $item, $payload ) = $this->order( '1019' );
		$this->connector->send( $connection, $item, $payload );
		$invoice = array_values( $this->server->invoices )[0];
		$this->assertSame( 50, $invoice['Status'] );
		$this->assertCount( 1, $this->logged( '#^POST .*PrintedSalesInvoices#' ) );
		$this->assertSame( '2026-10-30T00:00:00', $invoice['InvoiceDate'], 'The order date is given to the processing step.' );
		$again = $this->connector->send( $connection, $item, $payload );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->logged( '#^POST .*PrintedSalesInvoices#' ), 'Never processed twice.' );
	}

	public function test_a_lost_answer_after_processing_is_picked_up_without_processing_twice(): void {
		$connection = $this->connection( array( 'options' => array( 'process_invoice' => 'yes' ) ) );
		list( $item, $payload ) = $this->order( '1020' );
		$this->connector->send( $connection, $item, $payload );
		$id = (string) array_key_first( $this->server->invoices );
		$this->server->invoices[ $id ]['Status'] = 20;
		$this->assertNull( $this->connector->findExisting( $connection, $item ), 'An open invoice that should be processed is not finished.' );
		$this->server->invoices[ $id ]['Status'] = 50;
		$this->assertInstanceOf( ExternalRef::class, $this->connector->findExisting( $connection, $item ) );
	}

	public function test_the_daily_cap_is_shown_in_the_rate_hints(): void {
		$this->assertSame( array( 'requests_per_second' => 1.0, 'concurrency' => 1 ), $this->connector->rateLimitHints() );
	}

	public function test_documents_class_exposes_the_pending_marker_used_by_the_gate(): void {
		$this->assertSame( ' [sync pending]', ExactDocuments::PENDING );
		$this->assertInstanceOf( ConnectorError::class, $this->connector->findExisting( $this->connection( array( 'division' => '' ) ), $this->order( '1' )[0] ) );
	}
}
