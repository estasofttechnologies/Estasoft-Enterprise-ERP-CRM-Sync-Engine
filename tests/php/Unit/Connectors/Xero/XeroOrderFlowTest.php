<?php
/**
 * The order flow of the Xero connector: find, create a draft, check, approve, pay.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Xero;

use Estasoft\SyncEngine\Domain\ErrorCode;

final class XeroOrderFlowTest extends XeroTestCase {

	private function send( string $id, array $merge = array(), ?array $options = null ) {
		$connection = null === $options ? $this->connection : $this->connect( $options );
		return $this->connector->send( $connection, $this->item( $id ), $this->payload( $id, $merge ) );
	}

	/**
	 * @return array<int,\Estasoft\SyncEngine\Tests\Support\ApiFakeRequest>
	 */
	private function writes(): array {
		return array_values( array_filter( $this->xero->server->received, static fn( $r ): bool => in_array( $r->method, array( 'PUT', 'POST' ), true ) && '/connect/token' !== $r->path ) );
	}

	public function test_an_order_becomes_an_approved_invoice_with_the_shops_reference_date_and_total(): void {
		$result = $this->send( '1042' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$invoice = $this->xero->invoiceByReference( 'WC-1042' );
		$this->assertSame( 'AUTHORISED', $invoice['Status'] );
		$this->assertSame( 'ACCREC', $invoice['Type'] );
		$this->assertSame( '2026-11-01T00:00:00', $invoice['DateString'], 'The shop\'s date, not the day of the sync.' );
		$this->assertSame( 'Exclusive', $invoice['LineAmountTypes'] );
		$this->assertSame( $invoice['InvoiceID'], $result->ref->id );
		$this->assertSame( $invoice['InvoiceNumber'], $result->ref->ref );
		$this->assertSame( 8820, $result->ref->extra['total_minor'], '2 x 42.00 plus 5 percent tax.' );
		$this->assertSame( 'AED', $result->ref->extra['currency'] );
	}

	public function test_the_invoice_is_created_as_a_draft_and_approved_in_a_second_call(): void {
		$this->send( '1043' );

		$put = $this->xero->calls( 'PUT', '/Invoices' );
		$this->assertCount( 1, $put );
		$body = $put[0]->json()['Invoices'][0];
		$this->assertSame( 'DRAFT', $body['Status'] );
		$this->assertSame( 'WC-1043', $body['Reference'] );
		$this->assertSame( '2026-11-01', $body['DueDate'] );
		$this->assertSame( 'CS-MUG-01', $body['LineItems'][0]['ItemCode'] );
		$this->assertSame( 'OUTPUT', $body['LineItems'][0]['TaxType'] );
		$this->assertSame( 42.0, $body['LineItems'][0]['UnitAmount'] );
		$this->assertSame( '4', (string) $put[0]->query['unitdp'] );
		$this->assertSame( 'true', $put[0]->query['summarizeErrors'] );
		$this->assertSame( 'true', $put[0]->query['allowBackorders'] );
		$this->assertSame( \Estasoft\SyncEngine\Tests\Support\FakeXeroServer::TENANT, $put[0]->header( 'Xero-tenant-id' ), 'The organisation is pinned on every call.' );
		$this->assertNotEmpty( $put[0]->header( 'Idempotency-Key' ) );
		$this->assertLessThanOrEqual( 128, strlen( (string) $put[0]->header( 'Idempotency-Key' ) ) );
		$approve = $this->xero->calls( 'POST', '/Invoices/[^/]+' );
		$this->assertCount( 1, $approve );
		$this->assertSame( 'AUTHORISED', $approve[0]->json()['Invoices'][0]['Status'] );
	}

	public function test_the_reference_is_searched_before_every_create_and_a_repeat_makes_no_second_invoice(): void {
		$first  = $this->send( '1044' );
		$second = $this->send( '1044' );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->xero->calls( 'PUT', '/Invoices' ) );
		$this->assertCount( 1, $this->xero->invoices );
		$search = $this->xero->calls( 'GET', '/Invoices' )[0];
		$this->assertSame( 'Type=="ACCREC" AND Reference=="WC-1044"', $search->query['where'] );
	}

	public function test_a_lost_answer_is_not_a_second_invoice(): void {
		$this->xero->addContact( 'Layla Haddad', 'layla@example.test' );
		$this->xero->loseNextAnswer = true;
		$lost                       = $this->send( '1045' );
		$this->assertFalse( $lost->isSuccess() );
		$this->assertCount( 1, $this->xero->invoices, 'Xero did make the invoice; only the answer was lost.' );

		$retry = $this->send( '1045' );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted, 'The invoice was found by its reference and finished.' );
		$this->assertCount( 1, $this->xero->invoices );
		$this->assertSame( 'AUTHORISED', $this->xero->invoiceByReference( 'WC-1045' )['Status'] );
	}

	public function test_a_lost_answer_for_the_customer_is_not_a_second_customer(): void {
		$this->xero->loseNextAnswer = true;
		$this->assertFalse( $this->send( '1080' )->isSuccess() );

		$retry = $this->send( '1080' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->xero->contacts, 'The contact made by the first pass was found by its email.' );
	}

	public function test_a_draft_that_a_failed_pass_left_behind_is_checked_and_approved_not_made_again(): void {
		$this->xero->addContact( 'Layla Haddad', 'layla@example.test' );
		$contact = array_values( $this->xero->contacts )[0]['ContactID'];
		$this->connection = $this->connect( array( 'documents' => 'invoice' ) );
		$this->connector->send( $this->connection, $this->item( '1046' ), $this->payload( '1046' ) );
		$this->assertSame( 'DRAFT', $this->xero->invoiceByReference( 'WC-1046' )['Status'] );
		$this->assertSame( $contact, $this->xero->invoiceByReference( 'WC-1046' )['Contact']['ContactID'], 'The contact with that email was used, not a new one.' );

		$this->connection = $this->connect();
		$result           = $this->connector->send( $this->connection, $this->item( '1046' ), $this->payload( '1046' ) );

		$this->assertTrue( $result->isSuccess() );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertSame( 'AUTHORISED', $this->xero->invoiceByReference( 'WC-1046' )['Status'] );
		$this->assertCount( 1, $this->xero->calls( 'PUT', '/Invoices' ) );
	}

	public function test_find_existing_reports_only_an_invoice_that_is_finished_for_the_settings(): void {
		$this->connection = $this->connect( array( 'documents' => 'invoice' ) );
		$this->connector->send( $this->connection, $this->item( '1047' ), $this->payload( '1047' ) );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $this->item( '1047' ) ), 'A draft is enough when the settings ask for a draft.' );

		$approving = $this->connect( array( 'documents' => 'invoice_approved' ) );
		$this->assertNull( $this->connector->findExisting( $approving, $this->item( '1047' ) ), 'A draft is not finished when approval is wanted, so send() finishes it.' );
		$this->connector->send( $approving, $this->item( '1047' ), $this->payload( '1047' ) );
		$this->assertNotNull( $this->connector->findExisting( $approving, $this->item( '1047' ) ) );
	}

	public function test_a_total_that_differs_from_the_shops_deletes_the_draft_and_approves_nothing(): void {
		$result = $this->send( '1048', array( 'order_line' => array( array( 'tax' => 'OUTPUT2' ) ) ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '88.20 AED', (string) $result->error->context['reason'] );
		$this->assertStringContainsString( '96.60 AED', (string) $result->error->context['reason'] );
		$this->assertSame( 'DELETED', $this->xero->invoiceByReference( 'WC-1048' )['Status'] );
		$approvals = array_filter( $this->xero->calls( 'POST', '/Invoices/[^/]+' ), static fn( $r ): bool => 'AUTHORISED' === $r->json()['Invoices'][0]['Status'] );
		$this->assertSame( array(), array_values( $approvals ), 'Nothing was approved.' );
	}

	public function test_a_deleted_draft_is_ignored_and_the_next_pass_makes_a_good_invoice(): void {
		$this->send( '1049', array( 'order_line' => array( array( 'tax' => 'OUTPUT2' ) ) ) );
		$retry = $this->send( '1049' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertFalse( $retry->alreadyExisted );
		$statuses = array_map( static fn( array $i ): string => $i['Status'], array_values( $this->xero->invoices ) );
		$this->assertSame( array( 'DELETED', 'AUTHORISED' ), $statuses );
	}

	public function test_the_next_try_after_a_deleted_draft_gets_a_new_idempotency_key_even_when_the_body_is_the_same(): void {
		$this->send( '1048', array( 'total' => 9999 ) );

		$retry = $this->connector->send( $this->connection, $this->item( '1048', \Estasoft\SyncEngine\Domain\EntityType::Order, 1 ), $this->payload( '1048' ) );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$keys = array_map( static fn( $r ): string => (string) $r->header( 'Idempotency-Key' ), $this->xero->calls( 'PUT', '/Invoices' ) );
		$this->assertCount( 2, $keys );
		$this->assertNotSame( $keys[0], $keys[1], 'Same body, next try: a new key, so Xero does not answer with the deleted draft.' );
	}

	public function test_warn_mode_keeps_the_invoice_and_reports_the_difference(): void {
		$result = $this->send( '1050', array( 'order_line' => array( array( 'tax' => 'OUTPUT2' ) ) ), array( 'total_mismatch' => 'warn' ) );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( 'AUTHORISED', $this->xero->invoiceByReference( 'WC-1050' )['Status'] );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_prices_that_include_tax_are_sent_as_inclusive_and_the_totals_agree(): void {
		$result = $this->send( '1051', array( 'prices_include_tax' => true, 'total' => 8400 ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'Inclusive', $this->xero->invoiceByReference( 'WC-1051' )['LineAmountTypes'] );
		$this->assertSame( 8400, $result->ref->extra['total_minor'] );
	}

	public function test_a_currency_that_is_not_enabled_stops_before_anything_is_created(): void {
		$result = $this->send( '1052', array( 'currency' => 'GBP' ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'GBP', (string) $result->error->context['reason'] );
		$this->assertSame( array(), $this->writes() );
	}

	public function test_a_foreign_currency_that_is_enabled_is_sent_and_read_back(): void {
		$result = $this->send( '1053', array( 'currency' => 'USD' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'USD', $this->xero->calls( 'PUT', '/Invoices' )[0]->json()['Invoices'][0]['CurrencyCode'] );
		$this->assertSame( 'USD', $result->ref->extra['currency'] );
	}

	public function test_a_voided_invoice_stops_the_item_and_a_second_one_is_not_made(): void {
		$first = $this->send( '1054' );
		$this->xero->invoices[ $first->ref->id ]['Status'] = 'VOIDED';

		$result = $this->send( '1054' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'WC-1054', (string) $result->error->context['reason'] );
		$this->assertCount( 1, $this->xero->invoices );
		$this->assertInstanceOf( \Estasoft\SyncEngine\Contract\ConnectorError::class, $this->connector->findExisting( $this->connection, $this->item( '1054' ) ) );
	}

	public function test_an_unknown_sku_is_refused_before_anything_is_sent_unless_free_text_is_chosen(): void {
		$merge  = array( 'order_line' => array( array( 'product' => 'NO-SUCH-SKU' ) ) );
		$result = $this->send( '1055', $merge );
		$this->assertSame( ErrorCode::Map001, $result->error->code );
		$this->assertSame( 'NO-SUCH-SKU', $result->error->context['sku'] );
		$this->assertSame( array(), $this->writes(), 'Not even the customer was created.' );

		$free = $this->send( '1056', $merge, array( 'unknown_sku' => 'free_text', 'sales_account' => '200' ) );
		$this->assertTrue( $free->isSuccess(), (string) $free->error?->message() );
		$line = $this->xero->calls( 'PUT', '/Invoices' )[0]->json()['Invoices'][0]['LineItems'][0];
		$this->assertArrayNotHasKey( 'ItemCode', $line );
		$this->assertSame( '200', $line['AccountCode'] );

		$noAccount = $this->send( '1057', $merge, array( 'unknown_sku' => 'free_text' ) );
		$this->assertSame( ErrorCode::Val002, $noAccount->error->code );
		$this->assertStringContainsString( 'sales_account', (string) $noAccount->error->context['reason'] );
	}

	public function test_an_item_that_is_not_for_sale_is_named(): void {
		$this->xero->addItem( 'CS-OLD', array( 'IsSold' => false ) );
		$result = $this->send( '1058', array( 'order_line' => array( array( 'product' => 'CS-OLD' ) ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'CS-OLD', (string) $result->error->context['reason'] );
	}

	public function test_shipping_fees_and_discounts_become_lines_on_their_accounts(): void {
		$options = array( 'sales_account' => '200', 'shipping_account' => '260' );
		$result  = $this->send(
			'1059',
			array(
				'shipping' => array( 'total' => 1000, 'name' => 'Courier', 'tax' => 'NONE' ),
				'fee'      => array( array( 'name' => 'Gift wrap', 'total' => 500, 'tax' => 'NONE' ) ),
				'discount' => array( 'total' => 800, 'name' => 'SPRING', 'tax' => 'NONE' ),
				'total'    => 8820 + 1000 + 500 - 800,
			),
			$options
		);

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->xero->calls( 'PUT', '/Invoices' )[0]->json()['Invoices'][0]['LineItems'];
		$this->assertCount( 4, $lines );
		$this->assertSame( '260', $lines[1]['AccountCode'] );
		$this->assertSame( '200', $lines[2]['AccountCode'] );
		$this->assertSame( -8.0, $lines[3]['UnitAmount'], 'A discount is a negative line.' );
	}

	public function test_a_shipping_line_without_an_account_option_is_refused_naming_the_option(): void {
		$result = $this->send( '1060', array( 'shipping' => array( 'total' => 1000, 'tax' => 'NONE' ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'shipping_account', (string) $result->error->context['reason'] );
		$this->assertSame( array(), $this->writes() );
	}

	public function test_a_line_with_no_tax_key_uses_the_item_default_and_an_empty_one_uses_the_no_tax_type(): void {
		$line = array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'price_unit' => 1000 );
		$this->send( '1061', array( 'order_line' => array( 0 => $line ), 'total' => 1050 ) );
		$body = $this->xero->calls( 'PUT', '/Invoices' )[0]->json()['Invoices'][0]['LineItems'][0];
		$this->assertSame( 'OUTPUT', $body['TaxType'] ?? 'OUTPUT', 'The item default is used when the line says nothing.' );

		$payload = $this->payload( '1062', array( 'total' => 1000 ) );
		$payload['order_line'][0] = array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'price_unit' => 1000, 'tax' => '' );
		$result = $this->connector->send( $this->connection, $this->item( '1062' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'NONE', $this->xero->calls( 'PUT', '/Invoices' )[1]->json()['Invoices'][0]['LineItems'][0]['TaxType'] );
	}

	public function test_two_tax_codes_on_one_line_are_refused(): void {
		$result = $this->send( '1063', array( 'order_line' => array( array( 'tax' => array( 'OUTPUT', 'OUTPUT2' ) ) ) ) );

		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertSame( array(), $this->writes() );
	}

	public function test_tracked_stock_below_the_quantity_is_refused_only_when_backorders_are_off(): void {
		$merge = array( 'order_line' => array( array( 'quantity' => 50 ) ), 'total' => 220500 );
		$off   = $this->send( '1064', $merge, array( 'allow_backorders' => 'no' ) );
		$this->assertSame( ErrorCode::Val002, $off->error->code );
		$this->assertStringContainsString( 'quantity on hand', (string) $off->error->context['reason'] );

		$on = $this->send( '1065', $merge );
		$this->assertTrue( $on->isSuccess(), (string) $on->error?->message() );
	}

	public function test_a_customer_with_the_email_is_reused_whatever_the_case(): void {
		$existing = $this->xero->addContact( 'Layla H.', 'LAYLA@Example.Test' );
		$result   = $this->send( '1066' );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( array(), $this->xero->calls( 'PUT', '/Contacts' ) );
		$this->assertSame( $existing['ContactID'], $this->xero->invoiceByReference( 'WC-1066' )['Contact']['ContactID'] );
	}

	public function test_a_new_customer_gets_a_billing_address_and_a_different_delivery_address(): void {
		$this->send(
			'1067',
			array(
				'partner'  => array( 'company_name' => 'Haddad Trading', 'zip' => '00000', 'vat' => '100123456700003' ),
				'delivery' => array( 'street' => 'Marina Walk', 'city' => 'Dubai', 'country_id' => 'AE' ),
			)
		);

		$contact = $this->xero->calls( 'PUT', '/Contacts' )[0]->json()['Contacts'][0];
		$this->assertSame( 'Haddad Trading', $contact['Name'] );
		$this->assertSame( 'Layla', $contact['FirstName'] );
		$this->assertSame( 'layla@example.test', $contact['EmailAddress'] );
		$this->assertSame( '100123456700003', $contact['TaxNumber'] );
		$this->assertSame( '+971501234567', $contact['Phones'][0]['PhoneNumber'] );
		$this->assertSame( 'POBOX', $contact['Addresses'][0]['AddressType'] );
		$this->assertSame( 'AE', $contact['Addresses'][0]['Country'] );
		$this->assertSame( 'STREET', $contact['Addresses'][1]['AddressType'] );
		$this->assertSame( 'Marina Walk', $contact['Addresses'][1]['AddressLine1'] );
	}

	public function test_a_name_that_xero_says_is_taken_is_tried_again_with_the_email_after_it(): void {
		$this->xero->addContact( 'Layla Haddad', 'other@example.test' );
		$result = $this->send( '1068' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$puts = $this->xero->calls( 'PUT', '/Contacts' );
		$this->assertCount( 2, $puts );
		$this->assertSame( 'Layla Haddad (layla@example.test)', $puts[1]->json()['Contacts'][0]['Name'] );
	}

	public function test_an_email_with_letters_xero_does_not_accept_is_left_out_with_a_warning(): void {
		$result = $this->send( '1069', array( 'partner' => array( 'email' => 'jürgen@example.test' ) ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertArrayNotHasKey( 'EmailAddress', $this->xero->calls( 'PUT', '/Contacts' )[0]->json()['Contacts'][0] );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_arabic_text_and_long_values_are_kept_and_cut_in_a_documented_way(): void {
		$name = 'نور المنصوري';
		$long = str_repeat( 'ش', 5000 );
		$this->send( '1070', array( 'partner' => array( 'name' => $name, 'first_name' => 'نور', 'last_name' => 'المنصوري', 'email' => 'noor@example.test', 'street' => 'شارع الشيخ زايد، برج ٢٣' ), 'order_line' => array( array( 'name' => $long ) ) ) );

		$contact = $this->xero->calls( 'PUT', '/Contacts' )[0]->json()['Contacts'][0];
		$this->assertSame( $name, $contact['Name'] );
		$this->assertSame( 'شارع الشيخ زايد، برج ٢٣', $contact['Addresses'][0]['AddressLine1'] );
		$line = $this->xero->calls( 'PUT', '/Invoices' )[0]->json()['Invoices'][0]['LineItems'][0];
		$this->assertSame( 4000, mb_strlen( $line['Description'] ) );
		$this->assertStringEndsWith( "\u{2026}", $line['Description'] );
		$this->assertSame( 'WC-1070', $this->xero->invoiceByReference( 'WC-1070' )['Reference'], 'The reference is never cut.' );
	}

	public function test_the_payment_is_recorded_once_for_the_amount_due(): void {
		$options = array( 'documents' => 'invoice_paid', 'payment_account' => '090' );
		$paid    = array( 'payment' => array( 'date' => '2026-11-01', 'reference' => 'ch_3Q9' ) );
		$first   = $this->send( '1071', $paid, $options );

		$this->assertTrue( $first->isSuccess(), (string) $first->error?->message() );
		$this->assertSame( 'PAID', $this->xero->invoiceByReference( 'WC-1071' )['Status'] );
		$payment = $this->xero->calls( 'PUT', '/Payments' );
		$this->assertCount( 1, $payment );
		$body = $payment[0]->json()['Payments'][0];
		$this->assertSame( 88.2, $body['Amount'] );
		$this->assertSame( '090', $body['Account']['Code'] );
		$this->assertSame( 'ch_3Q9', $body['Reference'] );
		$this->assertSame( 'PAID', $first->ref->extra['status'] );

		$again = $this->send( '1071', $paid, $options );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->xero->calls( 'PUT', '/Payments' ), 'Never paid twice.' );
		$this->assertNotNull( $this->connector->findExisting( $this->connect( $options ), $this->item( '1071' ) ) );
	}

	public function test_an_unpaid_order_gets_no_payment_and_a_missing_account_option_is_named(): void {
		$options = array( 'documents' => 'invoice_paid', 'payment_account' => '090' );
		$this->assertTrue( $this->send( '1072', array(), $options )->isSuccess() );
		$this->assertSame( array(), $this->xero->calls( 'PUT', '/Payments' ) );

		$result = $this->send( '1073', array( 'payment' => array( 'date' => '2026-11-01' ) ), array( 'documents' => 'invoice_paid' ) );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'payment_account', (string) $result->error->context['reason'] );
	}

	public function test_a_changed_invoice_date_after_approval_is_reported(): void {
		$this->xero->moveDateOnApprove = true;
		$result                        = $this->send( '1074' );

		$this->assertTrue( $result->isSuccess() );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertStringContainsString( 'date', strtolower( (string) $result->ref->extra['warnings'][0] ) );
	}

	public function test_an_order_without_an_email_is_refused_before_any_request(): void {
		$payload = $this->payload( '1075' );
		unset( $payload['partner']['email'] );
		$result = $this->connector->send( $this->connection, $this->item( '1075' ), $payload );

		$this->assertSame( ErrorCode::Map003, $result->error->code );
		$this->assertCount( 0, $this->kit->http->requests );
	}

	public function test_the_idempotency_key_changes_when_the_body_changes(): void {
		$this->send( '1076' );
		$this->send( '1077' );
		$keys = $this->xero->keys;

		$this->assertSame( count( $keys ), count( array_unique( $keys ) ) );
		$this->assertStringContainsString( 'WC-1076', $keys[1] );
	}

	public function test_a_missing_scope_is_a_permission_problem_not_a_bad_sign_in(): void {
		$this->xero->missingScope = true;
		$result                   = $this->send( '1078' );

		$this->assertSame( ErrorCode::Auth004, $result->error->code );
	}
}
