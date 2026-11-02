<?php
/**
 * The order flow of the QuickBooks connector: find, create with the unchecked marker, read back, clean the marker, pay.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\QuickBooks;

use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\FakeQuickBooksServer;

final class QuickBooksOrderFlowTest extends QuickBooksTestCase {

	private function send( string $id, array $merge = array(), ?array $options = null, int $attempt = 0 ) {
		$connection = null === $options ? $this->connection : $this->connect( $options );
		return $this->connector->send( $connection, $this->item( $id, \Estasoft\SyncEngine\Domain\EntityType::Order, $attempt ), $this->payload( $id, $merge ) );
	}

	public function test_an_order_becomes_an_invoice_with_the_shops_reference_date_and_total(): void {
		$result = $this->send( '1042' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$invoice = $this->qbo->byNumber( 'Invoice', 'WC-1042' );
		$this->assertSame( '2026-11-01', $invoice['TxnDate'], 'The shop\'s date, not the day of the sync.' );
		$this->assertSame( 'TaxExcluded', $invoice['GlobalTaxCalculation'] );
		$this->assertSame( $invoice['Id'], $result->ref->id );
		$this->assertSame( 'WC-1042', $result->ref->ref );
		$this->assertSame( 10080, $result->ref->extra['total_minor'], '2 x 42.00 plus 20 percent tax.' );
		$this->assertSame( 'GBP', $result->ref->extra['currency'] );
		$this->assertSame( 'WC-1042', $invoice['PrivateNote'], 'The unchecked marker is gone once the total was read back.' );
	}

	public function test_the_invoice_is_made_with_a_marker_and_a_second_call_removes_it(): void {
		$this->send( '1043' );

		$writes = $this->qbo->writes();
		$posts  = array_values( array_filter( $writes, static fn( $r ): bool => '/v3/company/' . FakeQuickBooksServer::REALM . '/invoice' === $r->path ) );
		$this->assertCount( 2, $posts, 'Create, then a sparse update.' );
		$create = $posts[0]->json();
		$this->assertSame( 'WC-1043', $create['DocNumber'] );
		$this->assertSame( 'WC-1043 [unchecked]', $create['PrivateNote'] );
		$this->assertSame( '2026-11-01', $create['DueDate'] );
		$this->assertSame( 'CS-MUG-01', $this->qbo->store['Item'][ $create['Line'][0]['SalesItemLineDetail']['ItemRef']['value'] ]['Sku'] );
		$this->assertSame( 2.0, $create['Line'][0]['SalesItemLineDetail']['Qty'] );
		$this->assertSame( 42.0, $create['Line'][0]['SalesItemLineDetail']['UnitPrice'] );
		$this->assertSame( 84.0, $create['Line'][0]['Amount'] );
		$this->assertSame( '5', $create['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value'] );
		$this->assertSame( 'layla@example.test', $create['BillEmail']['Address'] );
		$this->assertSame( '75', (string) $posts[0]->query['minorversion'] );
		$this->assertMatchesRegularExpression( '/^wc-[0-9a-f]{40}$/', (string) $posts[0]->query['requestid'] );
		$this->assertLessThanOrEqual( 50, strlen( (string) $posts[0]->query['requestid'] ) );
		$this->assertStringStartsWith( 'Bearer qbo-access-', (string) $posts[0]->header( 'Authorization' ) );
		$update = $posts[1]->json();
		$this->assertTrue( $update['sparse'] );
		$this->assertSame( 'WC-1043', $update['PrivateNote'] );
		$this->assertSame( '0', $update['SyncToken'] );
	}

	public function test_the_reference_is_searched_before_every_create_and_a_repeat_makes_no_second_invoice(): void {
		$first  = $this->send( '1044' );
		$second = $this->send( '1044' );

		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->qbo->store['Invoice'] );
		$queries = array_values( array_filter( $this->qbo->calls( 'GET', '/query' ), static fn( $r ): bool => false !== strpos( (string) $r->query['query'], 'from Invoice' ) ) );
		$this->assertSame( "select * from Invoice where DocNumber = 'WC-1044' startposition 1 maxresults 100", $queries[0]->query['query'] );
	}

	public function test_a_lost_answer_is_not_a_second_invoice(): void {
		$this->qbo->addCustomer( 'Layla Haddad', 'layla@example.test' );
		$this->qbo->loseNextAnswer = true;
		$lost                      = $this->send( '1045' );
		$this->assertFalse( $lost->isSuccess() );
		$this->assertCount( 1, $this->qbo->store['Invoice'], 'QuickBooks did make the invoice; only the answer was lost.' );
		$this->assertStringContainsString( '[unchecked]', $this->qbo->byNumber( 'Invoice', 'WC-1045' )['PrivateNote'] );

		$retry = $this->send( '1045' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertTrue( $retry->alreadyExisted, 'The invoice was found by its number and finished.' );
		$this->assertCount( 1, $this->qbo->store['Invoice'] );
		$this->assertSame( 'WC-1045', $this->qbo->byNumber( 'Invoice', 'WC-1045' )['PrivateNote'] );
	}

	public function test_a_lost_answer_for_the_customer_is_not_a_second_customer(): void {
		$this->qbo->loseNextAnswer = true;
		$this->assertFalse( $this->send( '1080' )->isSuccess() );

		$retry = $this->send( '1080' );

		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->qbo->store['Customer'], 'The customer made by the first pass was found by its email.' );
	}

	public function test_an_invoice_with_the_marker_is_not_finished_and_the_next_pass_checks_it(): void {
		$this->qbo->addCustomer( 'Layla Haddad', 'layla@example.test' );
		$this->qbo->loseNextAnswer = true;
		$this->send( '1046' );

		$found = $this->connector->findExisting( $this->connection, $this->item( '1046' ) );
		$this->assertNull( $found, 'An unchecked invoice is never reported as done.' );

		$result = $this->send( '1046' );
		$this->assertTrue( $result->isSuccess() );
		$this->assertNotNull( $this->connector->findExisting( $this->connection, $this->item( '1046' ) ) );
	}

	public function test_a_duplicate_number_answer_is_recovered_by_finding_the_invoice(): void {
		$this->qbo->addCustomer( 'Layla Haddad', 'layla@example.test' );
		$customer                           = array_values( $this->qbo->store['Customer'] )[0];
		$this->qbo->store['Invoice']['901'] = array(
			'Id'          => '901',
			'SyncToken'   => '0',
			'DocNumber'   => 'WC-1047',
			'TxnDate'     => '2026-11-01',
			'CustomerRef' => array( 'value' => $customer['Id'] ),
			'CurrencyRef' => array( 'value' => 'GBP' ),
			'PrivateNote' => 'WC-1047',
			'TotalAmt'    => 100.8,
			'Balance'     => 100.8,
		);
		$this->qbo->hideInvoices = 1;

		$result = $this->send( '1047' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertTrue( $result->alreadyExisted );
		$this->assertSame( '901', $result->ref->id );
		$this->assertCount( 1, $this->qbo->store['Invoice'], 'Error 6140 was answered by finding the invoice, not by making another.' );
	}

	public function test_an_answer_replayed_for_the_same_request_id_is_older_than_the_invoice_and_is_read_again(): void {
		$first = $this->send( '1047' );
		$this->assertTrue( $first->isSuccess() );
		$this->qbo->hideInvoices = 1;

		$second = $this->send( '1047' );

		$this->assertTrue( $second->isSuccess(), (string) $second->error?->message() );
		$this->assertSame( $first->ref->id, $second->ref->id );
		$this->assertCount( 1, $this->qbo->store['Invoice'] );
		$this->assertSame( 'WC-1047', $this->qbo->byNumber( 'Invoice', 'WC-1047' )['PrivateNote'] );
	}

	public function test_a_total_that_differs_from_the_shops_deletes_the_invoice_it_made(): void {
		$result = $this->send( '1048', array( 'total' => 9999 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( '99.99', $result->error->message() );
		$this->assertStringContainsString( '100.80', $result->error->message() );
		$this->assertCount( 0, $this->qbo->store['Invoice'], 'The wrong invoice was removed.' );
		$deletes = array_values( array_filter( $this->qbo->writes(), static fn( $r ): bool => 'delete' === ( $r->query['operation'] ?? '' ) ) );
		$this->assertCount( 1, $deletes );
	}

	public function test_a_deleted_invoice_is_ignored_and_the_next_try_makes_a_good_one_with_a_new_request_id(): void {
		$this->send( '1049', array( 'total' => 9999 ) );

		$result = $this->send( '1049', array(), null, 1 );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertFalse( $result->alreadyExisted );
		$this->assertCount( 1, $this->qbo->store['Invoice'] );
		$ids = array_map( static fn( $r ): string => (string) $r->query['requestid'], array_values( array_filter( $this->qbo->writes(), static fn( $r ): bool => isset( $r->query['requestid'] ) && '/v3/company/' . FakeQuickBooksServer::REALM . '/invoice' === $r->path ) ) );
		$this->assertCount( 2, $ids );
		$this->assertNotSame( $ids[0], $ids[1], 'The second try must not get the answer about the invoice the first try deleted.' );
	}

	public function test_warn_mode_keeps_the_invoice_and_reports_the_difference(): void {
		$result = $this->send( '1050', array( 'total' => 9999 ), array( 'total_mismatch' => 'warn' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertCount( 1, $this->qbo->store['Invoice'] );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
		$this->assertSame( 'WC-1050', $this->qbo->byNumber( 'Invoice', 'WC-1050' )['PrivateNote'] );
	}

	public function test_prices_that_include_tax_are_sent_as_inclusive_and_the_totals_agree(): void {
		$result = $this->send(
			'1051',
			array(
				'prices_include_tax' => true,
				'order_line'         => array( array( 'price_unit' => 5040 ) ),
			)
		);

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'TaxInclusive', $this->qbo->byNumber( 'Invoice', 'WC-1051' )['GlobalTaxCalculation'] );
		$this->assertSame( 10080, $result->ref->extra['total_minor'] );
	}

	public function test_a_us_company_is_never_sent_the_tax_calculation_flag_and_refuses_prices_with_tax(): void {
		$this->qbo->country = 'US';
		$this->qbo->home    = 'USD';
		$connection         = $this->connect();
		$ok                 = $this->connector->send( $connection, $this->item( '1052' ), $this->payload( '1052', array( 'currency' => 'USD', 'order_line' => array( array( 'tax' => 'TAX' ) ), 'total' => 8988 ) ) );
		$this->assertTrue( $ok->isSuccess(), (string) $ok->error?->message() );
		$this->assertArrayNotHasKey( 'GlobalTaxCalculation', $this->qbo->writes()[ count( $this->qbo->writes() ) - 2 ]->json(), 'The US model has no such field.' );
		$this->assertSame( 8988, $ok->ref->extra['total_minor'], '2 x 42.00 plus 7 percent sales tax.' );

		$before  = count( $this->qbo->writes() );
		$refused = $this->connector->send( $connection, $this->item( '1053' ), $this->payload( '1053', array( 'currency' => 'USD', 'prices_include_tax' => true ) ) );
		$this->assertFalse( $refused->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $refused->error->code );
		$this->assertCount( $before, $this->qbo->writes(), 'Nothing was sent.' );
	}

	public function test_a_foreign_currency_is_sent_when_multicurrency_is_on_and_stopped_when_it_is_off(): void {
		$usd = $this->send( '1054', array( 'currency' => 'USD' ) );
		$this->assertTrue( $usd->isSuccess(), (string) $usd->error?->message() );
		$this->assertSame( 'USD', $this->qbo->byNumber( 'Invoice', 'WC-1054' )['CurrencyRef']['value'] );
		$this->assertSame( 'USD', $usd->ref->extra['currency'] );

		$home = $this->send( '1055' );
		$this->assertTrue( $home->isSuccess() );
		$this->assertArrayNotHasKey( 'CurrencyRef', $this->qbo->writes()[ count( $this->qbo->writes() ) - 2 ]->json(), 'The home currency is not named.' );

		$this->qbo->multiCurrency = false;
		$connection               = $this->connect( array(), 9 );
		$before                   = count( $this->qbo->writes() );
		$stopped                  = $this->connector->send( $connection, $this->item( '1056' ), $this->payload( '1056', array( 'currency' => 'AED' ) ) );
		$this->assertFalse( $stopped->isSuccess() );
		$this->assertStringContainsString( 'multicurrency is off', $stopped->error->message() );
		$this->assertCount( $before, $this->qbo->writes() );
	}

	public function test_without_custom_transaction_numbers_nothing_is_sent_and_the_setting_is_named(): void {
		$this->qbo->customNumbers = false;

		$result = $this->send( '1057' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Custom transaction numbers', $result->error->message() );
		$this->assertSame( array(), $this->qbo->writes() );
	}

	public function test_a_voided_invoice_stops_the_item_and_a_second_one_is_not_made(): void {
		$this->qbo->store['Invoice']['900'] = array( 'Id' => '900', 'SyncToken' => '1', 'DocNumber' => 'WC-1058', 'TotalAmt' => 0.0, 'PrivateNote' => 'Voided', 'CurrencyRef' => array( 'value' => 'GBP' ) );

		$result = $this->send( '1058' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'voided', $result->error->message() );
		$this->assertSame( array(), $this->qbo->writes() );
		$this->assertInstanceOf( \Estasoft\SyncEngine\Contract\ConnectorError::class, $this->connector->findExisting( $this->connection, $this->item( '1058' ) ) );
	}

	public function test_an_unknown_sku_is_refused_before_anything_is_sent_unless_free_text_is_chosen(): void {
		$refused = $this->send( '1059', array( 'order_line' => array( array( 'product' => 'NO-SUCH-SKU' ) ) ) );
		$this->assertFalse( $refused->isSuccess() );
		$this->assertSame( ErrorCode::Map001, $refused->error->code );
		$this->assertSame( array(), $this->qbo->writes(), 'No customer and no invoice were made for a product that is not there.' );

		$free = $this->send(
			'1060',
			array( 'order_line' => array( array( 'product' => 'NO-SUCH-SKU', 'name' => 'Made to order frame' ) ) ),
			array( 'unknown_sku' => 'free_text', 'generic_item' => 'WC-SERVICE' )
		);
		$this->assertTrue( $free->isSuccess(), (string) $free->error?->message() );
		$line = $this->qbo->byNumber( 'Invoice', 'WC-1060' )['Line'][0];
		$this->assertSame( 'Made to order frame', $line['Description'] );

		$noItem = $this->send( '1061', array( 'order_line' => array( array( 'product' => 'NO-SUCH-SKU' ) ) ), array( 'unknown_sku' => 'free_text' ) );
		$this->assertFalse( $noItem->isSuccess() );
		$this->assertStringContainsString( 'generic_item', $noItem->error->message() );
	}

	public function test_shipping_fees_and_discounts_become_lines_on_their_items(): void {
		$this->qbo->addItem( 'WC-SHIPPING', array( 'Name' => 'Shipping', 'Type' => 'Service' ) );
		$this->qbo->addItem( 'WC-FEE', array( 'Name' => 'Fees', 'Type' => 'Service' ) );
		$this->qbo->addItem( 'WC-DISCOUNT', array( 'Name' => 'Discounts', 'Type' => 'Service' ) );

		$result = $this->send(
			'1062',
			array(
				'shipping' => array( 'total' => 1000, 'tax' => '5', 'name' => 'Courier' ),
				'fee'      => array( array( 'total' => 500, 'tax' => '6', 'name' => 'Gift wrap' ) ),
				'discount' => array( 'total' => 300, 'tax' => '5', 'name' => 'Spring code' ),
				'total'    => 10080 + 1200 + 500 - 360,
			),
			array( 'shipping_item' => 'WC-SHIPPING', 'fee_item' => 'WC-FEE', 'discount_item' => 'WC-DISCOUNT' )
		);

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$lines = $this->qbo->byNumber( 'Invoice', 'WC-1062' )['Line'];
		$this->assertCount( 4, $lines );
		$this->assertSame( 'Courier', $lines[1]['Description'] );
		$this->assertSame( 10.0, $lines[1]['Amount'] );
		$this->assertSame( 'Gift wrap', $lines[2]['Description'] );
		$this->assertSame( -3.0, $lines[3]['Amount'], 'A discount is a negative line.' );
		$this->assertSame( 11420, $result->ref->extra['total_minor'] );
	}

	public function test_a_shipping_line_without_an_item_option_is_refused_naming_the_option(): void {
		$result = $this->send( '1063', array( 'shipping' => array( 'total' => 1000 ), 'total' => 11280 ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'generic_item', $result->error->message() );
		$this->assertSame( array(), $this->qbo->writes() );
	}

	public function test_a_line_with_no_tax_key_uses_the_items_default_and_an_empty_one_uses_the_no_tax_code(): void {
		$default = $this->connector->send( $this->connection, $this->item( '1064' ), array_replace( $this->payload( '1064' ), array( 'order_line' => array( array( 'product' => 'CS-MUG-01', 'name' => 'Mug', 'quantity' => 1, 'price_unit' => 4200 ) ), 'total' => null ) ) );
		$this->assertTrue( $default->isSuccess(), (string) $default->error?->message() );
		$create = $this->qbo->writes()[ count( $this->qbo->writes() ) - 2 ]->json();
		$this->assertArrayNotHasKey( 'TaxCodeRef', $create['Line'][0]['SalesItemLineDetail'] );

		$none = $this->send( '1065', array( 'order_line' => array( array( 'tax' => '' ) ), 'total' => 8400 ) );
		$this->assertTrue( $none->isSuccess(), (string) $none->error?->message() );
		$create = $this->qbo->writes()[ count( $this->qbo->writes() ) - 2 ]->json();
		$this->assertSame( 'NON', $create['Line'][0]['SalesItemLineDetail']['TaxCodeRef']['value'] );
	}

	public function test_two_tax_codes_on_one_line_and_a_line_discount_are_refused_before_any_request(): void {
		$two = $this->send( '1066', array( 'order_line' => array( array( 'tax' => array( '5', '4' ) ) ) ) );
		$this->assertFalse( $two->isSuccess() );
		$this->assertStringContainsString( 'one tax code', $two->error->message() );

		$discount = $this->send( '1067', array( 'order_line' => array( array( 'discount' => 10 ) ) ) );
		$this->assertFalse( $discount->isSuccess() );
		$this->assertStringContainsString( 'discount', $discount->error->message() );
		$this->assertSame( array(), $this->qbo->writes() );
	}

	public function test_a_tax_code_quickbooks_does_not_have_is_a_validation_error_with_its_reason(): void {
		$result = $this->send( '1068', array( 'order_line' => array( array( 'tax' => '999' ) ) ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'Invalid Tax Code 999', $result->error->message() );
		$this->assertCount( 0, $this->qbo->store['Invoice'] );
	}

	public function test_a_customer_with_the_email_is_reused_whatever_the_case(): void {
		$existing = $this->qbo->addCustomer( 'L. Haddad', 'Layla@Example.Test' );

		$result = $this->send( '1069' );

		$this->assertTrue( $result->isSuccess() );
		$this->assertCount( 1, $this->qbo->store['Customer'] );
		$this->assertSame( $existing['Id'], $this->qbo->byNumber( 'Invoice', 'WC-1069' )['CustomerRef']['value'] );
	}

	public function test_a_new_customer_gets_a_billing_address_and_a_different_delivery_address(): void {
		$this->send(
			'1070',
			array(
				'delivery' => array( 'street' => '9 Other Road', 'city' => 'Leeds', 'zip' => 'LS1 1AA', 'country_id' => 'GB' ),
			)
		);

		$customer = array_values( $this->qbo->store['Customer'] )[0];
		$this->assertSame( 'Layla Haddad', $customer['DisplayName'] );
		$this->assertSame( 'Layla', $customer['GivenName'] );
		$this->assertSame( '1 Demo Street', $customer['BillAddr']['Line1'] );
		$this->assertSame( 'N1 9GU', $customer['BillAddr']['PostalCode'] );
		$this->assertSame( '9 Other Road', $customer['ShipAddr']['Line1'] );
		$create = array_values( array_filter( $this->qbo->writes(), static fn( $r ): bool => '/v3/company/' . FakeQuickBooksServer::REALM . '/invoice' === $r->path ) )[0]->json();
		$this->assertSame( 'Leeds', $create['ShipAddr']['City'] );
		$this->assertSame( '1 Demo Street', $create['BillAddr']['Line1'] );
	}

	public function test_a_name_that_quickbooks_says_is_taken_is_tried_again_with_the_email_after_it(): void {
		$this->qbo->addCustomer( 'Layla Haddad', 'someone.else@example.test' );

		$result = $this->send( '1071' );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$names = array_column( $this->qbo->store['Customer'], 'DisplayName' );
		$this->assertContains( 'Layla Haddad (layla@example.test)', $names );
	}

	public function test_long_values_are_cut_to_the_limits_and_a_colon_in_a_name_is_removed(): void {
		$result = $this->send(
			'1072',
			array(
				'partner' => array(
					'name'       => 'Layla: Haddad',
					'first_name' => str_repeat( 'A', 40 ),
					'last_name'  => str_repeat( 'B', 40 ),
				),
			)
		);

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$customer = array_values( $this->qbo->store['Customer'] )[0];
		$this->assertStringNotContainsString( ':', $customer['DisplayName'] );
		$this->assertLessThanOrEqual( 25, mb_strlen( $customer['GivenName'] ) );
		$this->assertLessThanOrEqual( 15, mb_strlen( $customer['FamilyName'] ) );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_the_payment_is_recorded_once_for_the_amount_due(): void {
		$pay = array( 'payment' => array( 'reference' => 'ch_3Pq0', 'date' => '2026-11-01' ) );

		$result = $this->send( '1073', $pay, array( 'documents' => 'invoice_paid', 'deposit_account' => '35' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 0, $result->ref->extra['balance_minor'] );
		$payments = array_values( $this->qbo->store['Payment'] );
		$this->assertCount( 1, $payments );
		$this->assertSame( 100.8, $payments[0]['TotalAmt'] );
		$this->assertSame( 'WC-1073', $payments[0]['PaymentRefNum'] );
		$body = array_values( array_filter( $this->qbo->writes(), static fn( $r ): bool => '/v3/company/' . FakeQuickBooksServer::REALM . '/payment' === $r->path ) )[0]->json();
		$this->assertSame( '35', $body['DepositToAccountRef']['value'] );
		$this->assertSame( 'Invoice', $body['Line'][0]['LinkedTxn'][0]['TxnType'] );

		$again = $this->send( '1073', $pay, array( 'documents' => 'invoice_paid', 'deposit_account' => '35' ) );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertCount( 1, $this->qbo->store['Payment'], 'A repeat does not pay twice.' );
	}

	public function test_an_unpaid_order_gets_no_payment(): void {
		$result = $this->send( '1074', array(), array( 'documents' => 'invoice_paid' ) );

		$this->assertTrue( $result->isSuccess() );
		$this->assertSame( array(), $this->qbo->store['Payment'] );
		$this->assertSame( 10080, $result->ref->extra['balance_minor'] );
	}

	public function test_find_existing_reports_a_paid_invoice_only_when_the_settings_ask_for_no_payment_or_it_is_paid(): void {
		$this->send( '1075', array( 'payment' => array( 'date' => '2026-11-01' ) ) );

		$this->assertNotNull( $this->connector->findExisting( $this->connection, $this->item( '1075' ) ), 'Invoice only: the invoice is all that is asked for.' );
		$paying = $this->connect( array( 'documents' => 'invoice_paid' ) );
		$this->assertNull( $this->connector->findExisting( $paying, $this->item( '1075' ) ), 'Invoice and payment: the payment is still missing.' );
	}

	public function test_a_payment_that_failed_is_made_on_the_next_pass_without_a_second_invoice(): void {
		$pay = array( 'payment' => array( 'date' => '2026-11-01' ) );
		$this->send( '1076', $pay );
		$this->qbo->failNextWrite( 'server_error' );

		$failed = $this->send( '1076', $pay, array( 'documents' => 'invoice_paid' ) );
		$this->assertFalse( $failed->isSuccess() );
		$this->assertSame( array(), $this->qbo->store['Payment'] );

		$retry = $this->send( '1076', $pay, array( 'documents' => 'invoice_paid' ) );
		$this->assertTrue( $retry->isSuccess(), (string) $retry->error?->message() );
		$this->assertCount( 1, $this->qbo->store['Payment'] );
		$this->assertCount( 1, $this->qbo->store['Invoice'] );
	}

	public function test_the_due_date_is_the_order_date_plus_the_days_in_the_options(): void {
		$this->send( '1077', array(), array( 'due_days' => 30 ) );

		$this->assertSame( '2026-12-01', $this->qbo->byNumber( 'Invoice', 'WC-1077' )['DueDate'] );
	}

	public function test_an_order_without_an_email_is_refused_before_any_request(): void {
		$result = $this->send( '1078', array( 'partner' => array( 'email' => '' ) ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Map003, $result->error->code );
		$this->assertSame( array(), $this->qbo->server->received );
	}

	public function test_the_request_id_is_the_same_for_the_same_body_and_try_and_differs_otherwise(): void {
		$this->send( '1079' );
		$one = array_values( array_filter( $this->qbo->writes(), static fn( $r ): bool => isset( $r->query['requestid'] ) && '/v3/company/' . FakeQuickBooksServer::REALM . '/invoice' === $r->path ) );

		$this->send( '1079' );
		$this->assertCount( 1, $one );
		$this->assertCount( 1, array_filter( $this->qbo->writes(), static fn( $r ): bool => isset( $r->query['requestid'] ) && '/v3/company/' . FakeQuickBooksServer::REALM . '/invoice' === $r->path ), 'The repeat found the invoice and made no second one.' );
		$other = $this->send( '1090' );
		$this->assertTrue( $other->isSuccess() );
		$ids = array_map( static fn( $r ): string => (string) $r->query['requestid'], array_values( array_filter( $this->qbo->writes(), static fn( $r ): bool => isset( $r->query['requestid'] ) && '/v3/company/' . FakeQuickBooksServer::REALM . '/invoice' === $r->path ) ) );
		$this->assertCount( 2, array_unique( $ids ) );
	}

	public function test_a_rate_limit_answer_says_how_long_to_wait(): void {
		$this->qbo->addCustomer( 'Layla Haddad', 'layla@example.test' );
		$this->qbo->failNextWrite( 'rate_limit', 17 );

		$result = $this->send( '1081' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Rate001, $result->error->code );
		$this->assertSame( 17, $result->error->retryAfter );
		$this->assertCount( 0, $this->qbo->store['Invoice'] );
	}

	public function test_a_company_without_access_is_a_permission_problem_not_a_bad_sign_in(): void {
		$this->qbo->noAccess = true;

		$result = $this->send( '1082' );

		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Auth004, $result->error->code );
	}

	public function test_a_connection_that_has_not_chosen_a_company_says_so_and_calls_nothing(): void {
		$connection = $this->kit->connection( 'quickbooks', array( 'client_id' => FakeQuickBooksServer::CLIENT_ID ), $this->qbo->signedIn( $this->kit->clock->now()->getTimestamp() ), 5 );

		$result = $this->connector->send( $connection, $this->item( '1083' ), $this->payload( '1083' ) );

		$this->assertFalse( $result->isSuccess() );
		$this->assertStringContainsString( 'choose the company', $result->error->message() );
		$this->assertSame( array(), $this->qbo->server->received );
	}

	public function test_a_sandbox_connection_uses_the_sandbox_host(): void {
		$connection = $this->connect( array(), 6, array( 'environment' => 'sandbox' ) );

		$this->connector->test( $connection );

		$this->assertStringStartsWith( 'https://sandbox-quickbooks.api.intuit.com/v3/company/' . FakeQuickBooksServer::REALM . '/', $this->qbo->server->received[0]->url );
	}
}
