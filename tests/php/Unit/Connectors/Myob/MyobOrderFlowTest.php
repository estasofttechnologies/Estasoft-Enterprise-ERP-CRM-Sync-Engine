<?php
/**
 * The order flow of the MYOB connector: find, resolve, create, read back, check, pay.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Myob;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeMyobServer;

final class MyobOrderFlowTest extends MyobTestCase {

	private function send( string $id, array $merge = array(), ?array $options = null ) {
		$connection = null === $options ? $this->connection : $this->connect( $options );
		return $this->connector->send( $connection, $this->item( $id ), $this->payload( $id, $merge ) );
	}

	/**
	 * The invoice creates the connector made, in order.
	 *
	 * @return array<int,\Estasoft\SyncEngine\Tests\Support\ApiFakeRequest>
	 */
	private function creates(): array {
		return $this->myob->calls( 'POST', '/Sale/Invoice/Item' );
	}

	public function test_an_order_becomes_an_invoice_with_the_shops_reference_date_and_total(): void {
		$result = $this->send( '1042' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$invoice = $this->myob->invoiceByReference( 'WC-1042' );
		$this->assertSame( '2026-11-01T00:00:00', $invoice['Date'], 'The shop\'s date, not the day of the sync.' );
		$this->assertFalse( $invoice['IsTaxInclusive'] );
		$this->assertSame( $invoice['UID'], $result->ref->id );
		$this->assertSame( $invoice['Number'], $result->ref->ref, 'MYOB numbers the invoice.' );
		$this->assertSame( 9240, $result->ref->extra['total_minor'], '2 x 42.00 plus 10 percent GST.' );
		$this->assertSame( 9240, $result->ref->extra['balance_minor'] );
		$this->assertSame( 'AUD', $result->ref->extra['currency'] );
		$this->assertSame( 'Open', $result->ref->extra['status'] );
	}

	public function test_the_body_names_the_customer_item_tax_code_and_whole_unit_amounts(): void {
		$this->send( '1043' );

		$body = $this->creates()[0]->json();
		$this->assertSame( '2026-11-01 00:00:00', $body['Date'] );
		$this->assertSame( 'WC-1043', $body['CustomerPurchaseOrderNumber'] );
		$this->assertArrayNotHasKey( 'Number', $body, 'MYOB numbers invoices itself.' );
		$line = $body['Lines'][0];
		$this->assertSame( 'Transaction', $line['Type'] );
		$this->assertSame( 'Mug', $line['Description'] );
		$this->assertSame( 2.0, $line['ShipQuantity'] );
		$this->assertSame( 42.0, $line['UnitPrice'] );
		$this->assertSame( 84.0, $line['Total'] );
		$this->assertSame( 0.0, $line['DiscountPercent'] );
		$this->assertSame( 'CS-MUG-01', $this->myob->store['Item'][ $line['Item']['UID'] ]['Number'] );
		$this->assertSame( 'GST', $this->myob->store['TaxCode'][ $line['TaxCode']['UID'] ]['Code'] );
		$this->assertSame( array_values( $this->myob->store['Customer'] )[0]['UID'], $body['Customer']['UID'] );
	}

	public function test_every_call_carries_the_key_the_version_and_the_bearer_token(): void {
		$this->send( '1044' );

		foreach ( $this->myob->server->received as $request ) {
			if ( 'secure.myob.com' === parse_url( $request->url, PHP_URL_HOST ) ) {
				continue;
			}
			$this->assertSame( FakeMyobServer::CLIENT_ID, $request->header( 'x-myobapi-key' ), $request->path );
			$this->assertSame( 'v2', $request->header( 'x-myobapi-version' ), $request->path );
			$this->assertStringStartsWith( 'Bearer myob-access-', (string) $request->header( 'Authorization' ), $request->path );
			$this->assertNull( $request->header( 'x-myobapi-cftoken' ), 'This file asks for no login.' );
		}
	}

	public function test_the_reference_is_searched_before_every_create_and_a_repeat_makes_no_second_invoice(): void {
		$first  = $this->send( '1045' );
		$second = $this->send( '1045' );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->creates() );
		$this->assertCount( 1, $this->myob->store['Invoice'] );
		$search = $this->myob->calls( 'GET', '/Sale/Invoice/Item' )[0];
		$this->assertSame( "CustomerPurchaseOrderNumber eq 'WC-1045'", $search->query['$filter'] );
		$this->assertSame( '1000', (string) $search->query['$top'] );
	}

	public function test_a_lost_answer_is_not_a_second_invoice(): void {
		$this->myob->addCustomer( 'Haddad', 'layla@example.test' );
		$this->myob->loseNextAnswer = true;
		$lost                       = $this->send( '1046' );
		$this->assertFalse( $lost->isSuccess() );
		$this->assertCount( 1, $this->myob->store['Invoice'], 'MYOB did make the invoice; only the answer was lost.' );

		$retry = $this->send( '1046' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted, 'The invoice was found by its reference.' );
		$this->assertCount( 1, $this->myob->store['Invoice'] );
	}

	public function test_a_lost_answer_for_the_customer_is_not_a_second_customer(): void {
		$this->myob->loseNextAnswer = true;
		$this->assertFalse( $this->send( '1047' )->isSuccess() );

		$retry = $this->send( '1047' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->myob->store['Customer'], 'The card made by the first pass was found by its email.' );
	}

	public function test_a_create_that_does_not_say_where_the_invoice_is_is_found_by_its_reference(): void {
		$this->myob->noLocation = true;
		$this->myob->addCustomer( 'Haddad', 'layla@example.test' );

		$result = $this->send( '1048' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 9240, $result->ref->extra['total_minor'] );
	}

	public function test_a_total_that_differs_from_the_shops_deletes_the_invoice_it_made(): void {
		$result = $this->send( '1049', array( 'total' => 9999 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '99.99', $result->error->message() );
		$this->assertStringContainsString( '92.40', $result->error->message() );
		$this->assertCount( 0, $this->myob->store['Invoice'], 'The wrong invoice was removed.' );
		$this->assertCount( 1, $this->myob->calls( 'DELETE', '/Sale/Invoice/Item/[^/]+' ) );

		$retry = $this->send( '1049' );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertFalse( $retry->alreadyExisted );
		$this->assertCount( 1, $this->myob->store['Invoice'] );
	}

	public function test_warn_mode_keeps_the_invoice_and_reports_the_difference(): void {
		$result = $this->send( '1050', array( 'total' => 9999 ), array( 'total_mismatch' => 'warn' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->myob->store['Invoice'] );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_prices_that_include_tax_are_sent_as_inclusive_and_the_totals_agree(): void {
		$result = $this->send(
			'1051',
			array(
				'prices_include_tax' => true,
				'order_line'         => array( array( 'price_unit' => 4620 ) ),
			)
		);

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertTrue( $this->myob->invoiceByReference( 'WC-1051' )['IsTaxInclusive'] );
		$this->assertSame( 9240, $result->ref->extra['total_minor'] );
		$this->assertSame( 840, (int) round( $this->myob->invoiceByReference( 'WC-1051' )['TotalTax'] * 100 ), 'The tax is inside the prices.' );
	}

	public function test_a_line_discount_is_sent_as_a_percentage_and_the_total_is_worked_out_to_the_cent(): void {
		$result = $this->send( '1052', array( 'order_line' => array( array( 'discount' => 12.5 ) ), 'total' => 8085 ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$line = $this->creates()[0]->json()['Lines'][0];
		$this->assertSame( 12.5, $line['DiscountPercent'] );
		$this->assertSame( 73.5, $line['Total'], '84.00 less 12.5 percent.' );
		$this->assertSame( 8085, $result->ref->extra['total_minor'] );
	}

	public function test_an_order_in_another_currency_is_stopped_before_any_request(): void {
		$result = $this->send( '1053', array( 'currency' => 'USD' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'AUD', $result->error->message() );
		$this->assertSame( array(), $this->myob->server->received );
	}

	public function test_an_invoice_that_comes_back_in_a_foreign_currency_is_removed(): void {
		$this->myob->foreignCurrency = true;

		$result = $this->send( '1054' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'USD', $result->error->message() );
		$this->assertCount( 0, $this->myob->store['Invoice'] );
	}

	public function test_an_unknown_sku_is_refused_before_anything_is_sent(): void {
		$refused = $this->send( '1056', array( 'order_line' => array( array( 'product' => 'NO-SUCH-SKU' ) ) ) );

		$this->assertFalse( $refused->isSuccess() );
		$this->assertSame( ErrorCode::Map001, $refused->error->code );
		$this->assertSame( array(), $this->myob->writes(), 'No customer and no invoice were made for a product that is not there.' );
	}

	public function test_an_item_that_is_not_sold_is_named(): void {
		$this->myob->addItem( 'CS-OLD-09', array( 'IsSold' => false ) );

		$result = $this->send( '1057', array( 'order_line' => array( array( 'product' => 'CS-OLD-09' ) ) ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'CS-OLD-09', $result->error->message() );
		$this->assertSame( array(), $this->myob->writes() );
	}

	public function test_an_inactive_item_counts_as_unknown(): void {
		$this->myob->addItem( 'CS-GONE-10', array( 'IsActive' => false ) );

		$result = $this->send( '1058', array( 'order_line' => array( array( 'product' => 'CS-GONE-10' ) ) ) );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
	}

	public function test_a_tax_code_the_file_does_not_have_stops_the_order_before_anything_is_created(): void {
		$result = $this->send( '1059', array( 'order_line' => array( array( 'tax' => 'ZZZ' ) ) ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'ZZZ', $result->error->message() );
		$this->assertSame( array(), $this->myob->writes() );
	}

	public function test_a_line_with_no_tax_key_uses_the_items_default_and_an_empty_one_uses_the_no_tax_code(): void {
		$default = $this->connector->send( $this->connection, $this->item( '1060' ), array_replace( $this->payload( '1060' ), array( 'order_line' => array( array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'price_unit' => 4200 ) ), 'total' => null ) ) );
		$this->assertTrue( $default->isSuccess(), (string) $default->error?->message() );
		$this->assertArrayNotHasKey( 'TaxCode', $this->creates()[0]->json()['Lines'][0] );

		$none = $this->send( '1061', array( 'order_line' => array( array( 'tax' => '' ) ), 'total' => 8400 ) );
		$this->assertTrue( $none->isSuccess(), (string) $none->error?->message() );
		$uid = $this->creates()[1]->json()['Lines'][0]['TaxCode']['UID'];
		$this->assertSame( 'N-T', $this->myob->store['TaxCode'][ $uid ]['Code'] );
	}

	public function test_two_tax_codes_on_one_line_are_refused_before_any_request(): void {
		$two = $this->send( '1062', array( 'order_line' => array( array( 'tax' => array( 'GST', 'FRE' ) ) ) ) );

		$this->assertFalse( $two->isSuccess() );
		$this->assertStringContainsString( 'one tax code', $two->error->message() );
		$this->assertSame( array(), $this->myob->server->received );
	}

	public function test_shipping_is_the_freight_and_fees_and_discounts_are_lines_on_their_items(): void {
		$result = $this->send(
			'1063',
			array(
				'shipping' => array( 'total' => 1000, 'tax' => 'GST' ),
				'fee'      => array( array( 'total' => 500, 'tax' => 'FRE', 'name' => 'Gift wrap' ) ),
				'discount' => array( 'total' => 300, 'tax' => 'GST', 'name' => 'Spring code' ),
				'total'    => 9240 + 1100 + 500 - 330,
			),
			array( 'fee_item' => 'WC-FEE', 'discount_item' => 'WC-DISCOUNT' )
		);

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$body = $this->creates()[0]->json();
		$this->assertSame( 10.0, $body['Freight'] );
		$this->assertSame( 'GST', $this->myob->store['TaxCode'][ $body['FreightTaxCode']['UID'] ]['Code'] );
		$this->assertCount( 3, $body['Lines'] );
		$this->assertSame( 'Gift wrap', $body['Lines'][1]['Description'] );
		$this->assertSame( 'WC-FEE', $this->myob->store['Item'][ $body['Lines'][1]['Item']['UID'] ]['Number'] );
		$discount = $body['Lines'][2];
		$this->assertSame( -1.0, $discount['ShipQuantity'], 'A negative quantity with a positive price: MYOB refuses a negative price with a total.' );
		$this->assertSame( 3.0, $discount['UnitPrice'] );
		$this->assertSame( -3.0, $discount['Total'] );
		$this->assertSame( 10510, $result->ref->extra['total_minor'] );
	}

	public function test_a_fee_without_an_item_option_is_refused_naming_the_option(): void {
		$result = $this->send( '1064', array( 'fee' => array( array( 'total' => 500, 'name' => 'Gift wrap' ) ), 'total' => 9740 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'fee_item', $result->error->message() );
		$this->assertSame( array(), $this->myob->writes() );
	}

	public function test_the_generic_item_stands_in_for_a_fee_item_that_is_not_set(): void {
		$result = $this->send( '1065', array( 'fee' => array( array( 'total' => 500, 'tax' => 'FRE', 'name' => 'Gift wrap' ) ), 'total' => 9740 ), array( 'generic_item' => 'WC-GENERAL' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'WC-GENERAL', $this->myob->store['Item'][ $this->creates()[0]->json()['Lines'][1]['Item']['UID'] ]['Number'] );
	}

	public function test_a_customer_with_the_email_is_reused_whatever_the_case(): void {
		$existing = $this->myob->addCustomer( 'Haddad', 'LAYLA@Example.Test' );

		$result = $this->send( '1066' );

		$this->assertTrue( $result->isSuccess() );
		$this->assertCount( 1, $this->myob->store['Customer'] );
		$this->assertSame( $existing['UID'], $this->creates()[0]->json()['Customer']['UID'] );
		$this->assertSame( "Addresses/any(x: x/Email eq 'layla@example.test')", $this->myob->calls( 'GET', '/Contact/Customer' )[0]->query['$filter'], 'The form MYOB shows on its Retrieving data page.' );
		$this->assertCount( 1, $this->myob->calls( 'GET', '/Contact/Customer' ) );
	}

	public function test_when_the_filter_compares_case_the_lower_case_email_is_tried_after_the_one_the_shop_wrote(): void {
		$this->myob->emailCaseSensitive = true;
		$existing                       = $this->myob->addCustomer( 'Haddad', 'layla@example.test' );

		$result = $this->send( '1090', array( 'partner' => array( 'email' => 'Layla@Example.test' ) ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->myob->store['Customer'], 'No second card for the same person.' );
		$this->assertSame( $existing['UID'], $this->creates()[0]->json()['Customer']['UID'] );
		$filters = array_map( static fn( $r ): string => (string) $r->query['$filter'], $this->myob->calls( 'GET', '/Contact/Customer' ) );
		$this->assertSame( array( "Addresses/any(x: x/Email eq 'Layla@Example.test')", "Addresses/any(x: x/Email eq 'layla@example.test')" ), $filters );
	}

	public function test_an_email_with_a_quote_is_quoted_by_doubling_it(): void {
		$this->myob->addCustomer( "O'Neil", "o'neil@example.test" );

		$this->send( '1091', array( 'partner' => array( 'email' => "o'neil@example.test" ) ) );

		$this->assertCount( 1, $this->myob->store['Customer'] );
		$this->assertSame( "Addresses/any(x: x/Email eq 'o''neil@example.test')", $this->myob->calls( 'GET', '/Contact/Customer' )[0]->query['$filter'] );
	}

	public function test_when_the_email_filter_does_not_work_the_list_is_read_and_compared(): void {
		$this->myob->emailFilter = false;
		$existing                = $this->myob->addCustomer( 'Haddad', 'layla@example.test' );
		$this->myob->addCustomer( 'Someone', 'someone@example.test' );

		$result = $this->send( '1067' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 2, $this->myob->store['Customer'], 'The card with the email was found; no new one.' );
		$this->assertSame( $existing['UID'], $this->creates()[0]->json()['Customer']['UID'] );
	}

	public function test_a_refused_filter_is_not_tried_again_in_the_same_request_and_the_scan_stops_at_three_thousand_customers(): void {
		$this->myob->emailFilter = false;
		for ( $i = 0; $i < 3 * 1000 + 1; $i++ ) {
			$uid                                  = sprintf( '00000000-0000-4000-9000-%012d', $i );
			$this->myob->store['Customer'][ $uid ] = array( 'UID' => $uid, 'LastName' => 'C' . $i, 'Addresses' => array( array( 'Email' => 'c' . $i . '@example.test' ) ) );
		}

		$result = $this->send( '1068' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'more than 3,000 customers', $result->error->message() );
		$this->assertSame( array(), $this->myob->writes() );
		$reads = $this->myob->calls( 'GET', '/Contact/Customer' );
		$this->assertCount( 4, $reads, 'One refused filter, then three pages of 1,000; not twenty.' );
		$this->assertArrayHasKey( '$filter', $reads[0]->query );
		$this->assertArrayNotHasKey( '$filter', $reads[1]->query );

		$this->send( '1092' );
		$this->assertCount( 7, $this->myob->calls( 'GET', '/Contact/Customer' ), 'The next order goes straight to the list.' );
	}

	public function test_a_new_person_is_an_individual_with_a_billing_address_and_a_different_delivery_address(): void {
		$this->send( '1069', array( 'delivery' => array( 'street' => '9 Other Road', 'city' => 'Perth', 'zip' => '6000', 'country_id' => 'AU' ) ) );

		$card = array_values( $this->myob->store['Customer'] )[0];
		$this->assertTrue( $card['IsIndividual'] );
		$this->assertSame( 'Haddad', $card['LastName'] );
		$this->assertSame( 'Layla', $card['FirstName'] );
		$this->assertSame( '1 Demo Street', $card['Addresses'][0]['Street'] );
		$this->assertSame( 'layla@example.test', $card['Addresses'][0]['Email'] );
		$this->assertSame( '+61400000123', $card['Addresses'][0]['Phone1'] );
		$this->assertSame( 2, $card['Addresses'][1]['Location'] );
		$this->assertSame( '9 Other Road', $card['Addresses'][1]['Street'] );
		$this->assertSame( "9 Other Road\nPerth 6000\nAU", $this->creates()[0]->json()['ShipToAddress'] );
	}

	public function test_a_company_is_a_company_card_and_a_single_word_name_is_a_last_name(): void {
		$this->send( '1070', array( 'partner' => array( 'company_name' => 'Demo Trading', 'first_name' => '', 'last_name' => '' ) ) );
		$this->send( '1071', array( 'partner' => array( 'name' => 'Madonna', 'first_name' => '', 'last_name' => '', 'email' => 'madonna@example.test' ) ) );

		$cards = array_values( $this->myob->store['Customer'] );
		$this->assertFalse( $cards[0]['IsIndividual'] );
		$this->assertSame( 'Demo Trading', $cards[0]['CompanyName'] );
		$this->assertSame( 'Madonna', $cards[1]['LastName'] );
		$this->assertArrayNotHasKey( 'FirstName', $cards[1] );
	}

	public function test_long_values_are_cut_to_the_limits(): void {
		$result = $this->send( '1072', array( 'partner' => array( 'first_name' => str_repeat( 'A', 40 ), 'last_name' => str_repeat( 'B', 50 ), 'zip' => str_repeat( '9', 20 ) ) ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$card = array_values( $this->myob->store['Customer'] )[0];
		$this->assertLessThanOrEqual( 20, mb_strlen( $card['FirstName'] ) );
		$this->assertLessThanOrEqual( 30, mb_strlen( $card['LastName'] ) );
		$this->assertLessThanOrEqual( 11, mb_strlen( $card['Addresses'][0]['PostCode'] ) );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_the_payment_is_recorded_once_for_the_amount_due(): void {
		$options = array( 'documents' => 'invoice_paid', 'payment_account' => '1-1110' );
		$pay     = array( 'payment' => array( 'reference' => 'ch_3Pq0', 'date' => '2026-11-01' ) );

		$result = $this->send( '1073', $pay, $options );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 0, $result->ref->extra['balance_minor'] );
		$this->assertSame( 'Closed', $result->ref->extra['status'] );
		$payments = array_values( $this->myob->store['Payment'] );
		$this->assertCount( 1, $payments );
		$this->assertSame( 92.4, $payments[0]['AmountReceived'] );
		$this->assertArrayNotHasKey( 'AmountPaid', $payments[0] );
		$this->assertSame( 92.4, $payments[0]['Invoices'][0]['AmountApplied'] );
		$this->assertSame( 'Account', $payments[0]['DepositTo'] );
		$this->assertSame( '1-1110', $this->myob->store['Account'][ $payments[0]['Account']['UID'] ]['DisplayID'] );
		$this->assertSame( 'WC-1073 ch_3Pq0', $payments[0]['Memo'] );
		$this->assertSame( 'Invoice', $payments[0]['Invoices'][0]['Type'] );
		$this->assertSame( '2026-11-01 00:00:00', $payments[0]['Date'] );

		$again = $this->send( '1073', $pay, $options );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->myob->store['Payment'], 'A repeat does not pay twice.' );
	}

	public function test_without_a_payment_account_the_payment_goes_to_undeposited_funds(): void {
		$result = $this->send( '1074', array( 'payment' => array( 'date' => '2026-11-01' ) ), array( 'documents' => 'invoice_paid' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'UndepositedFunds', array_values( $this->myob->store['Payment'] )[0]['DepositTo'] );
	}

	public function test_the_payment_body_has_only_the_fields_of_the_customer_payment_page(): void {
		$this->send( '1093', array( 'payment' => array( 'reference' => 'ch_1', 'date' => '2026-11-01' ) ), array( 'documents' => 'invoice_paid', 'payment_account' => '1-1110' ) );

		$body = $this->myob->calls( 'POST', '/Sale/CustomerPayment' )[0]->json();
		$this->assertEqualsCanonicalizing( array( 'Account', 'AmountReceived', 'Customer', 'Date', 'DepositTo', 'Invoices', 'Memo' ), array_keys( $body ) );
		$this->assertEqualsCanonicalizing( array( 'AmountApplied', 'Type', 'UID' ), array_keys( $body['Invoices'][0] ) );
		$this->assertSame( 'Invoice', $body['Invoices'][0]['Type'] );
	}

	public function test_a_payment_that_leaves_a_balance_fails_the_pass_and_the_next_one_pays_only_what_is_due(): void {
		$options = array( 'documents' => 'invoice_paid' );
		$pay     = array( 'payment' => array( 'date' => '2026-11-01' ) );
		$this->myob->paymentShortfall = 500;

		$result = $this->send( '1094', $pay, $options );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'still shows a balance of 5.00', $result->error->message() );
		$this->assertCount( 1, $this->myob->store['Payment'] );
		$this->assertNull( $this->connector->findExisting( $this->connect( $options ), $this->item( '1094' ) ), 'An invoice with a balance is not finished.' );

		$this->myob->paymentShortfall = 0;
		$again                        = $this->send( '1094', $pay, $options );

		$this->assertTrue( $again->isSuccess(), (string) $again->error?->message() );
		$this->assertSame( 0, $again->ref->extra['balance_minor'] );
		$payments = array_values( $this->myob->store['Payment'] );
		$this->assertCount( 2, $payments );
		$this->assertSame( 5.0, $payments[1]['AmountReceived'], 'Only what was still due, never the whole total again.' );
		$this->assertCount( 1, $this->myob->store['Invoice'], 'No second invoice.' );
	}

	public function test_a_payment_whose_invoice_cannot_be_read_back_is_not_called_a_success(): void {
		$this->myob->invoiceGoneAfterPayment = true;

		$result = $this->send( '1095', array( 'payment' => array( 'date' => '2026-11-01' ) ), array( 'documents' => 'invoice_paid' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'cannot be read back', $result->error->message() );
	}

	public function test_a_payment_account_the_file_does_not_have_is_named_and_the_invoice_stays(): void {
		$result = $this->send( '1075', array( 'payment' => array( 'date' => '2026-11-01' ) ), array( 'documents' => 'invoice_paid', 'payment_account' => '9-9999' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'payment_account', $result->error->message() );
		$this->assertCount( 1, $this->myob->store['Invoice'], 'The invoice was made; only the payment is missing.' );
		$this->assertSame( array(), $this->myob->store['Payment'] );
	}

	public function test_an_unpaid_order_gets_no_payment(): void {
		$result = $this->send( '1076', array(), array( 'documents' => 'invoice_paid' ) );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( array(), $this->myob->store['Payment'] );
		$this->assertSame( 9240, $result->ref->extra['balance_minor'] );
	}

	public function test_find_existing_reports_a_paid_invoice_only_when_the_settings_ask_for_no_payment_or_it_is_paid(): void {
		$this->send( '1077', array( 'payment' => array( 'date' => '2026-11-01' ) ) );

		$this->assertNotNull( $this->connector->findExisting( $this->connection, $this->item( '1077' ) ), 'Invoice only: the invoice is all that is asked for.' );
		$paying = $this->connect( array( 'documents' => 'invoice_paid' ) );
		$this->assertNull( $this->connector->findExisting( $paying, $this->item( '1077' ) ), 'Invoice and payment: the payment is still missing.' );
	}

	public function test_a_payment_that_failed_is_made_on_the_next_pass_without_a_second_invoice(): void {
		$pay = array( 'payment' => array( 'date' => '2026-11-01' ) );
		$this->send( '1078', $pay );
		$this->myob->failNextWrite( 'server_error' );

		$failed = $this->send( '1078', $pay, array( 'documents' => 'invoice_paid' ) );
		$this->assertFalse( $failed->isSuccess() );
		$this->assertSame( array(), $this->myob->store['Payment'] );

		$retry = $this->send( '1078', $pay, array( 'documents' => 'invoice_paid' ) );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->myob->store['Payment'] );
		$this->assertCount( 1, $this->myob->store['Invoice'] );
	}

	public function test_the_payment_terms_are_the_days_in_the_options(): void {
		$this->send( '1079', array(), array( 'due_days' => 30 ) );

		$this->assertSame( array( 'PaymentIsDue' => 'InAGivenNumberOfDays', 'BalanceDueDate' => 30 ), $this->creates()[0]->json()['Terms'] );
	}

	public function test_the_note_becomes_the_invoice_comment(): void {
		$this->send( '1080', array( 'note' => 'Leave with the neighbour' ) );

		$this->assertSame( 'Leave with the neighbour', $this->myob->invoiceByReference( 'WC-1080' )['Comment'] );
	}

	public function test_an_order_without_an_email_or_a_date_is_refused_before_any_request(): void {
		$noEmail = $this->send( '1081', array( 'partner' => array( 'email' => '' ) ) );
		$this->assertSame( ErrorCode::Map003, $noEmail->error->code );

		$noDate = $this->send( '1082', array( 'date_order' => '' ) );
		$this->assertSame( ErrorCode::Val002, $noDate->error->code );
		$this->assertStringContainsString( 'date', $noDate->error->message() );
		$this->assertSame( array(), $this->myob->server->received );
	}

	public function test_a_key_over_its_limit_gets_403_and_that_means_wait_not_denied(): void {
		$this->myob->addCustomer( 'Haddad', 'layla@example.test' );
		$this->myob->failNextWrite( 'myob_rate_limit' );

		$result = $this->send( '1083' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 5, $result->error->retryAfter, 'A per-second limit clears in seconds.' );
		$this->assertCount( 0, $this->myob->store['Invoice'] );
	}

	public function test_a_daily_limit_waits_until_the_next_day_at_most(): void {
		$this->myob->addCustomer( 'Haddad', 'layla@example.test' );
		$this->myob->failNextWrite( 'myob_rate_limit_daily' );

		$result = $this->send( '1084' );

		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertGreaterThan( 60, $result->error->retryAfter );
		$this->assertLessThanOrEqual( 86400, $result->error->retryAfter );
	}

	public function test_a_gateway_timeout_is_a_network_timeout(): void {
		$this->myob->addCustomer( 'Haddad', 'layla@example.test' );
		$this->myob->failNextWrite( 'gateway_timeout' );
		$timeout = $this->send( '1085' );
		$this->assertSame( ErrorCode::Net001, $timeout->error->code );
	}

	public function test_a_company_file_login_is_sent_as_the_cftoken_and_removed_from_messages(): void {
		$this->myob->fileLogin = FakeMyobServer::FILE_USER . ':' . FakeMyobServer::FILE_PASSWORD;
		$connection            = $this->connect( array(), 4, array( 'company_file_user' => FakeMyobServer::FILE_USER ), array( 'company_file_password' => FakeMyobServer::FILE_PASSWORD ) );

		$result = $this->connector->send( $connection, $this->item( '1086' ), $this->payload( '1086' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$request = $this->creates()[0];
		$this->assertSame( base64_encode( FakeMyobServer::FILE_USER . ':' . FakeMyobServer::FILE_PASSWORD ), $request->header( 'x-myobapi-cftoken' ) );
	}

	public function test_a_file_that_asks_for_a_login_the_connection_does_not_have_is_a_permission_problem(): void {
		$this->myob->fileLogin = FakeMyobServer::FILE_USER . ':' . FakeMyobServer::FILE_PASSWORD;

		$result = $this->send( '1087' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
		$this->assertStringContainsString( 'company file login', $result->error->message() );
	}

	public function test_a_developer_key_that_is_not_active_is_a_credentials_problem(): void {
		$connection = $this->connect( array(), 5, array( 'client_id' => 'someone-elses-key' ) );

		$result = $this->connector->send( $connection, $this->item( '1088' ), $this->payload( '1088' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Auth001, $result->error->code );
	}

	public function test_a_connection_that_has_not_chosen_a_company_file_says_so_and_calls_nothing(): void {
		$connection = $this->kit->connection( 'myob', array( 'client_id' => FakeMyobServer::CLIENT_ID ), $this->myob->signedIn( $this->kit->clock->now()->getTimestamp() ), 6 );

		$result = $this->connector->send( $connection, $this->item( '1089' ), $this->payload( '1089' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'choose the company file', $result->error->message() );
		$this->assertSame( array(), $this->myob->server->received );
	}

	public function test_the_other_host_is_used_when_the_connection_asks_for_it(): void {
		$connection = $this->connect( array(), 8, array( 'api_host' => 'arl2.api.myob.com' ) );

		$this->connector->test( $connection );

		$this->assertSame( 'arl2.api.myob.com', parse_url( $this->myob->server->received[0]->url, PHP_URL_HOST ) );
	}
}
