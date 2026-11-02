<?php
/**
 * The small pure parts of the MYOB connector: amounts, payload reading, filter values, error shapes and the document bodies.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Myob;

use Estasoft\SyncEngine\Connectors\Myob\MyobAmounts;
use Estasoft\SyncEngine\Connectors\Myob\MyobApi;
use Estasoft\SyncEngine\Connectors\Myob\MyobCompany;
use Estasoft\SyncEngine\Connectors\Myob\MyobCustomers;
use Estasoft\SyncEngine\Connectors\Myob\MyobErrors;
use Estasoft\SyncEngine\Connectors\Myob\MyobInvoiceBuilder;
use Estasoft\SyncEngine\Connectors\Myob\MyobInvoices;
use Estasoft\SyncEngine\Connectors\Myob\MyobOptions;
use Estasoft\SyncEngine\Connectors\Myob\MyobOrderPayload;
use Estasoft\SyncEngine\Connectors\Myob\MyobPayloadReader;
use Estasoft\SyncEngine\Connectors\Myob\MyobRefundPayload;
use Estasoft\SyncEngine\Connectors\Myob\MyobTotals;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

final class MyobUnitTest extends TestCase {

	public function test_amounts_use_the_currency_exponent_and_never_float_arithmetic(): void {
		$amounts = new MyobAmounts();
		$this->assertSame( 19.99, $amounts->number( 1999, 'AUD' ) );
		$this->assertSame( 12.345, $amounts->number( 12345, 'KWD' ) );
		$this->assertSame( -8.0, $amounts->number( -800, 'AUD' ) );
		$this->assertSame( 5997, $amounts->fromNumber( 59.97, 'AUD' ) );
		$this->assertSame( -5997, $amounts->fromNumber( -59.97, 'AUD' ) );
		$this->assertSame( 1, $amounts->fromNumber( '0.01', 'NZD' ) );
		$this->assertNull( $amounts->fromNumber( 'abc', 'AUD' ) );
		$this->assertNull( $amounts->fromNumber( null, 'AUD' ) );
		$this->assertTrue( $amounts->agree( 1000, 1001 ) );
		$this->assertFalse( $amounts->agree( 1000, 1002 ) );
		$this->assertSame( '120.50 AUD', $amounts->show( 12050, 'AUD' ) );
	}

	public function test_a_line_total_is_whole_minor_units_rounded_half_away_from_zero_and_keeps_the_sign(): void {
		$amounts = new MyobAmounts();
		$this->assertSame( 5997, $amounts->lineTotal( '3', 1999 ) );
		$this->assertSame( 333, $amounts->lineTotal( '0.333', 1000 ) );
		$this->assertSame( -1999, $amounts->lineTotal( '-1', 1999 ) );
		$this->assertSame( -500, $amounts->lineTotal( '-0.5', 1000 ) );
		$this->assertSame( -2, $amounts->lineTotal( '-0.5', 3 ) );
		$this->assertSame( 0, $amounts->lineTotal( '1', 0 ) );
	}

	public function test_a_filter_value_is_quoted_by_doubling_every_quote(): void {
		$this->assertSame( "'WC-1042'", MyobApi::quote( 'WC-1042' ) );
		$this->assertSame( "'O''Brien'", MyobApi::quote( "O'Brien" ) );
		$this->assertSame( "''' or 1 eq 1'", MyobApi::quote( "' or 1 eq 1" ) );
		$this->assertSame( "'x y'", MyobApi::quote( "x\ny" ), 'A control character cannot end the value.' );
	}

	public function test_the_id_of_a_new_record_is_the_last_part_of_the_location_header(): void {
		$uid = '5b3c7a52-1d3e-4f6a-9b8c-0a1b2c3d4e5f';
		$this->assertSame( $uid, MyobApi::uidFromLocation( new ApiResponse( 201, '', array( 'Location' => 'https://api.myob.com/accountright/f/Sale/Invoice/Item/' . strtoupper( $uid ) ) ) ) );
		$this->assertSame( '', MyobApi::uidFromLocation( new ApiResponse( 201, '' ) ) );
		$this->assertSame( '', MyobApi::uidFromLocation( new ApiResponse( 201, '', array( 'Location' => 'https://api.myob.com/accountright/f/Sale/Invoice/Item/not-a-guid' ) ) ) );
	}

	public function test_a_list_answer_is_read_from_items_or_as_a_plain_list(): void {
		$this->assertSame( array( array( 'a' => 1 ) ), MyobApi::items( new ApiResponse( 200, '{"Items":[{"a":1},"x"],"NextPageLink":null,"Count":1}' ) ) );
		$this->assertSame( array( array( 'Id' => 'x' ) ), MyobApi::items( new ApiResponse( 200, '[{"Id":"x"}]' ) ) );
		$this->assertSame( array(), MyobApi::items( new ApiResponse( 200, '{}' ) ) );
		$this->assertSame( array(), MyobApi::items( new ApiResponse( 200, 'nonsense' ) ) );
	}

	public function test_the_company_file_comes_from_the_config_then_the_callback_and_must_be_a_guid(): void {
		$kit = \Estasoft\SyncEngine\Tests\Support\ApiTestKit::make();
		try {
			$file = '5b3c7a52-1d3e-4f6a-9b8c-0a1b2c3d4e5f';
			$this->assertSame( $file, MyobCompany::fileOf( $kit->unsaved( 'myob', array( 'company_file_id' => strtoupper( $file ) ), array() ) ) );
			$this->assertSame( $file, MyobCompany::fileOf( $kit->unsaved( 'myob', array( 'oauth_callback' => array( 'businessId' => $file ) ), array() ) ) );
			$this->assertSame( '', MyobCompany::fileOf( $kit->unsaved( 'myob', array( 'company_file_id' => 'Demo company' ), array() ) ) );
			$this->assertSame( 'arl2.api.myob.com', MyobCompany::hostOf( $kit->unsaved( 'myob', array( 'api_host' => 'arl2.api.myob.com' ), array() ) ) );
			$this->assertSame( 'api.myob.com', MyobCompany::hostOf( $kit->unsaved( 'myob', array( 'api_host' => 'evil.example.test' ), array() ) ), 'Only MYOB\'s own hosts.' );
			$this->assertSame( 'NZD', MyobCompany::currencyOf( $kit->unsaved( 'myob', array( 'currency' => 'nzd' ), array() ) ) );
			$this->assertSame( 'AUD', MyobCompany::currencyOf( $kit->unsaved( 'myob', array( 'currency' => 'dollars' ), array() ) ) );
		} finally {
			$kit->cleanup();
		}
	}

	public function test_the_reader_does_not_trust_types(): void {
		$this->assertSame( 2100, MyobPayloadReader::minor( '2100' ) );
		$this->assertNull( MyobPayloadReader::minor( '21.5' ) );
		$this->assertSame( '0.5', MyobPayloadReader::quantity( '0.50' ) );
		$this->assertNull( MyobPayloadReader::quantity( 0 ) );
		$this->assertSame( '2026-11-02', MyobPayloadReader::day( '2026-11-02T07:34:00' ) );
		$this->assertSame( '', MyobPayloadReader::day( '2026-13-45' ) );
		$this->assertSame( array( 'A', 'B' ), MyobPayloadReader::taxCodes( array( 'A', ' B ', 'A', '' ) ) );
	}

	public function test_options_come_from_the_options_array_or_the_config_with_safe_defaults(): void {
		$options = new MyobOptions( array( 'documents' => 'invoice_paid', 'due_days' => '9999', 'fee_item' => ' WC-FEE ', 'generic_item' => 'WC-GENERAL' ) );
		$this->assertTrue( $options->pays() );
		$this->assertSame( 365, $options->integer( 'due_days', 0, 0, 365 ) );
		$this->assertSame( 'WC-FEE', $options->itemFor( 'fee' ) );
		$this->assertSame( 'WC-GENERAL', $options->itemFor( 'discount' ), 'A kind with no item of its own uses the generic item.' );
		$this->assertSame( 'invoice', ( new MyobOptions( array( 'documents' => 'nonsense' ) ) )->documents() );
		$this->assertFalse( ( new MyobOptions() )->pays() );
	}

	private function item(): Item {
		return new Item( 9, 1, EntityType::Order, '9', 'created', 'order:9', 'k', ItemState::Syncing );
	}

	public function test_the_order_payload_names_the_first_thing_that_is_wrong(): void {
		$base = array(
			'date_order' => '2026-11-01',
			'partner'    => array( 'name' => 'A', 'email' => 'a@example.test' ),
			'order_line' => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => 100 ) ),
		);
		$this->assertInstanceOf( MyobOrderPayload::class, MyobOrderPayload::parse( $base, $this->item() ) );

		$cases = array(
			'no email'      => array( array( 'partner' => array( 'name' => 'A' ) ) + $base, ErrorCode::Map003 ),
			'no name'       => array( array( 'partner' => array( 'email' => 'a@example.test' ) ) + $base, ErrorCode::Val002 ),
			'no date'       => array( array( 'date_order' => '' ) + $base, ErrorCode::Val002 ),
			'no lines'      => array( array( 'order_line' => array() ) + $base, ErrorCode::Val002 ),
			'no product'    => array( array( 'order_line' => array( array( 'name' => 'Free text', 'quantity' => 1, 'price_unit' => 100 ) ) ) + $base, ErrorCode::Map001 ),
			'no quantity'   => array( array( 'order_line' => array( array( 'product' => 'X', 'price_unit' => 100 ) ) ) + $base, ErrorCode::Val002 ),
			'decimal price' => array( array( 'order_line' => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => '1.50' ) ) ) + $base, ErrorCode::Val002 ),
			'two freight'   => array( array( 'shipping' => array( 'total' => 100, 'tax' => array( 'A', 'B' ) ) ) + $base, ErrorCode::Val002 ),
		);
		foreach ( $cases as $name => $case ) {
			$result = MyobOrderPayload::parse( $case[0], $this->item() );
			$this->assertInstanceOf( ConnectorError::class, $result, $name );
			$this->assertSame( $case[1], $result->code, $name );
		}
	}

	public function test_the_client_reference_always_comes_from_the_queue_item_and_the_excluded_total_is_taken_off(): void {
		$payload = MyobOrderPayload::parse(
			array(
				'client_order_ref' => 'WC-EVIL',
				'date_order'       => '2026-11-01',
				'partner'          => array( 'name' => 'A', 'email' => 'a@example.test' ),
				'order_line'       => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => 100 ) ),
				'shipping'         => array( 'total' => 250, 'tax' => '' ),
				'total'            => 5000,
				'excluded_total'   => 1200,
			),
			$this->item()
		);
		$this->assertSame( 'WC-9', $payload->clientRef );
		$this->assertSame( 3800, $payload->totalMinor );
		$this->assertSame( 250, $payload->freightMinor );
		$this->assertSame( '', $payload->freightTax );
	}

	public function test_a_refund_payload_needs_the_order_it_belongs_to(): void {
		$item = new Item( 77, 1, EntityType::Refund, '77', 'refunded', 'order:9', 'k', ItemState::Syncing );
		$this->assertInstanceOf( ConnectorError::class, MyobRefundPayload::parse( array( 'amount' => 100 ), $item ) );
		$refund = MyobRefundPayload::parse( array( 'order_ref' => 'WC-9', 'amount' => 100 ), $item );
		$this->assertInstanceOf( MyobRefundPayload::class, $refund );
		$this->assertSame( 'WC-77', $refund->clientRef );
	}

	public function test_errors_are_read_from_both_shapes_and_mapped_by_their_name(): void {
		$error = static fn( int $status, string $name, string $message = 'm', string $details = '', int $code = 0 ): ApiResponse => new ApiResponse( $status, (string) json_encode( array( 'Errors' => array( array( 'Name' => $name, 'Message' => $message, 'AdditionalDetails' => $details, 'ErrorCode' => $code ) ) ) ) );

		$this->assertSame( array( array( 'name' => 'NotFound', 'message' => 'x', 'details' => 'y', 'code' => '150' ) ), MyobErrors::entries( $error( 400, 'NotFound', 'x', 'y', 150 )->json() ) );
		$this->assertSame( array( array( 'name' => 'Required', 'message' => 'z', 'details' => '', 'code' => '' ) ), MyobErrors::entries( array( 'Name' => 'Required', 'Message' => 'z' ) ), 'An error at the top level.' );
		$this->assertTrue( MyobErrors::has( $error( 400, 'NotFound' ), 'notfound' ) );
		$this->assertFalse( MyobErrors::has( null, 'NotFound' ) );

		$now = 1_700_000_000;
		$this->assertSame( ErrorCode::Auth001, MyobErrors::fromResponse( $error( 403, 'DeveloperInactive' ), $now )->code );
		$this->assertSame( ErrorCode::Auth004, MyobErrors::fromResponse( $error( 403, 'AccessDenied' ), $now )->code );
		$limit = MyobErrors::fromResponse( $error( 403, 'RateLimitError', 'Limit', 'per-second' ), $now );
		$this->assertSame( ErrorCode::Rate001, $limit->code );
		$this->assertSame( 5, $limit->retryAfter );
		$daily = MyobErrors::fromResponse( $error( 403, 'RateLimitError', 'Daily limit reached' ), $now );
		$this->assertSame( 86400 - ( $now % 86400 ), $daily->retryAfter, 'Until the next UTC day.' );
		$row = MyobErrors::fromResponse( $error( 409, 'IncorrectRowVersionSupplied', 'Changed', '', 111 ), $now );
		$this->assertSame( ErrorCode::Net002, $row->code );
		$this->assertSame( ErrorClass::Retry, $row->classOverride );
		$this->assertSame( ErrorCode::Net001, MyobErrors::fromResponse( $error( 504, 'GatewayTimeout' ), $now )->code );
		$business = MyobErrors::fromResponse( $error( 400, 'UnitPriceNotValid', 'Bad price', 'Line 1', 12101 ), $now );
		$this->assertSame( ErrorCode::Val002, $business->code );
		$this->assertSame( 'UnitPriceNotValid', $business->context['field'] );
		$this->assertSame( 'Bad price Line 1', $business->context['reason'] );
		$this->assertNull( MyobErrors::fromResponse( new ApiResponse( 500, '{"Errors":[{"Name":"x"}]}' ), $now ), 'A server error is left to the base.' );
		$this->assertNull( MyobErrors::fromResponse( new ApiResponse( 429, '{}' ), $now ), 'A plain 429 is left to the base.' );
		$this->assertNull( MyobErrors::fromResponse( new ApiResponse( 401, '{"Errors":[{"Name":"Unauthorized"}]}' ), $now ), 'A 401 is left to the sign-in refresh.' );
		$this->assertNull( MyobErrors::fromResponse( new ApiResponse( 400, 'not json' ), $now ) );
	}

	public function test_the_total_check_names_a_foreign_currency_compares_the_size_and_tolerates_one_minor_unit(): void {
		$totals = new MyobTotals( new MyobAmounts() );
		$this->assertNull( $totals->check( array( 'TotalAmount' => 10.01 ), 'AUD', 1000 ) );
		$this->assertNull( $totals->check( array( 'TotalAmount' => -10.0 ), 'AUD', 1000 ), 'A credit may be reported with a negative total.' );
		$this->assertNull( $totals->check( array( 'TotalAmount' => 99.0 ), 'AUD', null ), 'No expected total, nothing to compare.' );
		$foreign = $totals->check( array( 'ForeignCurrency' => array( 'CurrencyCode' => 'USD' ), 'TotalAmount' => 10.0 ), 'AUD', 1000 );
		$this->assertSame( ErrorCode::Val002, $foreign->code );
		$this->assertStringContainsString( 'USD', (string) $foreign->context['reason'] );
		$this->assertNotNull( $totals->check( array( 'TotalAmount' => 10.02 ), 'AUD', 1000 ) );
		$this->assertNotNull( $totals->check( array(), 'AUD', 1000 ), 'A missing total is reported, not skipped.' );
	}

	public function test_credit_and_paid_states_are_read_from_status_and_balance(): void {
		$this->assertTrue( MyobInvoices::isCredit( array( 'Status' => 'Credit' ) ) );
		$this->assertFalse( MyobInvoices::isCredit( array( 'Status' => 'Open' ) ) );
		$this->assertTrue( MyobInvoices::isPaid( array( 'Status' => 'Closed', 'BalanceDueAmount' => 0.0 ) ) );
		$this->assertTrue( MyobInvoices::isPaid( array( 'Status' => 'Open', 'BalanceDueAmount' => 0.0 ) ) );
		$this->assertFalse( MyobInvoices::isPaid( array( 'Status' => 'Open', 'BalanceDueAmount' => 5.0 ) ) );
		$this->assertFalse( MyobInvoices::isPaid( array() ) );
	}

	public function test_an_address_is_built_from_the_fields_it_has_and_the_first_one_carries_the_contact(): void {
		$cut = false;
		$this->assertSame( array(), MyobCustomers::address( array(), 1, $cut ) );
		$one = MyobCustomers::address( array( 'street' => '1 Demo Street', 'street2' => 'Unit 4', 'zip' => str_repeat( '9', 20 ), 'email' => 'a@example.test', 'name' => 'A' ), 1, $cut );
		$this->assertSame( 1, $one['Location'] );
		$this->assertSame( "1 Demo Street\nUnit 4", $one['Street'] );
		$this->assertSame( 11, mb_strlen( $one['PostCode'] ) );
		$this->assertSame( 'a@example.test', $one['Email'] );
		$this->assertTrue( $cut );
		$two = MyobCustomers::address( array( 'street' => '9 Other Road', 'email' => 'a@example.test' ), 2, $cut );
		$this->assertArrayNotHasKey( 'Email', $two );
		$onlyEmail = MyobCustomers::address( array( 'email' => 'a@example.test' ), 1, $cut );
		$this->assertSame( array( 'Location' => 1, 'Email' => 'a@example.test' ), $onlyEmail, 'The email is kept even with no street.' );
	}

	public function test_the_builder_sends_prices_as_they_are_and_works_the_discount_in_whole_cents(): void {
		$builder = new MyobInvoiceBuilder( new MyobOptions( array( 'due_days' => 14 ) ), new MyobAmounts() );
		$parsed  = MyobOrderPayload::parse(
			array(
				'date_order' => '2026-11-01',
				'partner'    => array( 'name' => 'A', 'email' => 'a@example.test' ),
				'order_line' => array( array( 'product' => 'X', 'quantity' => 3, 'price_unit' => 1999, 'tax' => 'GST', 'discount' => 10 ) ),
				'note'       => 'Hello',
			),
			$this->item()
		);
		$items    = array( 'x' => array( 'UID' => 'i-1', 'Name' => 'Thing' ) );
		$warnings = array();

		$body = $builder->invoice( $parsed, 'c-1', 'AUD', $items, array(), array( 'gst' => 't-1' ), '', $warnings );

		$this->assertSame( '2026-11-01 00:00:00', $body['Date'] );
		$line = $body['Lines'][0];
		$this->assertSame( 19.99, $line['UnitPrice'] );
		$this->assertSame( 10.0, $line['DiscountPercent'] );
		$this->assertSame( 53.97, $line['Total'], '3 x 19.99 = 59.97 less 10 percent = 53.973, rounded to the cent.' );
		$this->assertSame( 'Thing', $line['Description'], 'The item name stands in when the line has none.' );
		$this->assertSame( 14, $body['Terms']['BalanceDueDate'] );
		$this->assertSame( 'Hello', $body['Comment'] );

		$unknown = $builder->invoice( $parsed, 'c-1', 'AUD', array(), array(), array(), '', $warnings );
		$this->assertSame( ErrorCode::Map001, $unknown->code );
		$this->assertSame( array( 'GST' ), MyobInvoiceBuilder::taxCodesUsed( $parsed->lines, $parsed->extras, 'GST' ) );
		$this->assertSame( array(), MyobInvoiceBuilder::taxCodesUsed( array( array( 'tax' => '' ), array( 'tax' => null ) ), array(), '' ) );
	}
}
