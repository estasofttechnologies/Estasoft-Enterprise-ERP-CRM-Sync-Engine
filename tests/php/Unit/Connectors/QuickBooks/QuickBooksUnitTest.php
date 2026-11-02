<?php
/**
 * The small pure parts of the QuickBooks connector: amounts, payload reading, query values, error shapes and the document bodies.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\QuickBooks;

use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksAmounts;
use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksApi;
use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksCustomers;
use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksDocumentBuilder;
use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksDocuments;
use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksErrors;
use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksOptions;
use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksOrderPayload;
use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksPayloadReader;
use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksRefundPayload;
use Estasoft\SyncEngine\Connectors\QuickBooks\QuickBooksTotals;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

final class QuickBooksUnitTest extends TestCase {

	public function test_amounts_use_the_currency_exponent_and_never_float_arithmetic(): void {
		$amounts = new QuickBooksAmounts();
		$this->assertSame( 19.99, $amounts->number( 1999, 'GBP' ) );
		$this->assertSame( 12.345, $amounts->number( 12345, 'KWD' ) );
		$this->assertSame( 1500.0, $amounts->number( 1500, 'JPY' ) );
		$this->assertSame( -8.0, $amounts->number( -800, 'GBP' ) );
		$this->assertSame( 5997, $amounts->fromNumber( 59.97, 'GBP' ) );
		$this->assertSame( 37035, $amounts->fromNumber( 37.035, 'KWD' ) );
		$this->assertSame( 1, $amounts->fromNumber( '0.01', 'USD' ) );
		$this->assertNull( $amounts->fromNumber( 'abc', 'USD' ) );
		$this->assertNull( $amounts->fromNumber( null, 'USD' ) );
		$this->assertTrue( $amounts->agree( 1000, 1001 ) );
		$this->assertFalse( $amounts->agree( 1000, 1002 ) );
		$this->assertSame( '120.50 GBP', $amounts->show( 12050, 'GBP' ) );
	}

	public function test_a_line_total_is_whole_minor_units_rounded_half_away_from_zero(): void {
		$amounts = new QuickBooksAmounts();
		$this->assertSame( 5997, $amounts->lineTotal( '3', 1999 ) );
		$this->assertSame( 500, $amounts->lineTotal( '0.5', 1000 ) );
		$this->assertSame( 333, $amounts->lineTotal( '0.333', 1000 ) );
		$this->assertSame( 2, $amounts->lineTotal( '0.5', 3 ) );
		$this->assertSame( -2, $amounts->lineTotal( '0.5', -3 ) );
		$this->assertSame( 0, $amounts->lineTotal( '1', 0 ) );
	}

	public function test_the_reader_does_not_trust_types(): void {
		$this->assertSame( 2100, QuickBooksPayloadReader::minor( '2100' ) );
		$this->assertNull( QuickBooksPayloadReader::minor( '21.5' ) );
		$this->assertNull( QuickBooksPayloadReader::minor( 21.5 ) );
		$this->assertSame( 2150, QuickBooksPayloadReader::minor( 2150.0 ) );
		$this->assertSame( '2', QuickBooksPayloadReader::quantity( 2 ) );
		$this->assertSame( '0.5', QuickBooksPayloadReader::quantity( '0.50' ) );
		$this->assertNull( QuickBooksPayloadReader::quantity( 0 ) );
		$this->assertNull( QuickBooksPayloadReader::quantity( 'many' ) );
		$this->assertSame( '2026-11-02', QuickBooksPayloadReader::day( '2026-11-02 07:34:00' ) );
		$this->assertSame( '', QuickBooksPayloadReader::day( '2026-13-45' ) );
		$this->assertSame( '', QuickBooksPayloadReader::day( null ) );
		$this->assertSame( array(), QuickBooksPayloadReader::taxCodes( '' ) );
		$this->assertSame( array( 'A', 'B' ), QuickBooksPayloadReader::taxCodes( array( 'A', ' B ', 'A', '' ) ) );
		$this->assertTrue( QuickBooksPayloadReader::flag( 'yes', false ) );
		$this->assertFalse( QuickBooksPayloadReader::flag( 'banana', false ) );
	}

	public function test_a_long_text_is_cut_with_an_ellipsis_and_the_cut_is_reported(): void {
		$cut  = false;
		$text = QuickBooksPayloadReader::limit( str_repeat( 'ب', 300 ), 255, $cut );
		$this->assertSame( 255, mb_strlen( $text ) );
		$this->assertStringEndsWith( "\u{2026}", $text );
		$this->assertTrue( $cut );
		$cut = false;
		$this->assertSame( 'short', QuickBooksPayloadReader::limit( 'short', 255, $cut ) );
		$this->assertFalse( $cut );
	}

	public function test_a_query_value_is_quoted_and_every_quote_and_backslash_is_escaped(): void {
		$this->assertSame( "'WC-1042'", QuickBooksApi::quote( 'WC-1042' ) );
		$this->assertSame( "'O\\'Brien'", QuickBooksApi::quote( "O'Brien" ) );
		$this->assertSame( "'a\\\\b'", QuickBooksApi::quote( 'a\\b' ) );
		$this->assertSame( "'x y'", QuickBooksApi::quote( "x\ny" ), 'A control character cannot end the value.' );
		$this->assertSame( "'\\' or 1 = 1 --'", QuickBooksApi::quote( "' or 1 = 1 --" ) );
	}

	public function test_options_come_from_the_options_array_or_the_config_with_safe_defaults(): void {
		$options = new QuickBooksOptions( array( 'documents' => 'invoice_paid', 'due_days' => '9999', 'minor_version' => '12', 'shipping_item' => ' Delivery ', 'generic_item' => 'General' ) );
		$this->assertTrue( $options->pays() );
		$this->assertSame( 365, $options->integer( 'due_days', 0, 0, 365 ) );
		$this->assertSame( 75, $options->minorVersion(), 'Intuit treats anything below 75 as 75.' );
		$this->assertSame( 'Delivery', $options->itemFor( 'shipping' ) );
		$this->assertSame( 'General', $options->itemFor( 'fee' ), 'A kind with no item of its own uses the generic item.' );
		$this->assertSame( 'invoice', ( new QuickBooksOptions( array( 'documents' => 'nonsense' ) ) )->documents() );
		$this->assertFalse( ( new QuickBooksOptions() )->pays() );
		$this->assertSame( 80, ( new QuickBooksOptions( array( 'minor_version' => 80 ) ) )->minorVersion() );
	}

	private function item(): Item {
		return new Item( 9, 1, EntityType::Order, '9', 'created', 'order:9', 'k', ItemState::Syncing );
	}

	public function test_the_order_payload_names_the_first_thing_that_is_wrong(): void {
		$base = array(
			'partner'    => array( 'name' => 'A', 'email' => 'a@example.test' ),
			'order_line' => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => 100 ) ),
		);
		$this->assertInstanceOf( QuickBooksOrderPayload::class, QuickBooksOrderPayload::parse( $base, $this->item() ) );

		$cases = array(
			'no email'      => array( array( 'partner' => array( 'name' => 'A' ) ) + $base, ErrorCode::Map003 ),
			'no name'       => array( array( 'partner' => array( 'email' => 'a@example.test' ) ) + $base, ErrorCode::Val002 ),
			'no lines'      => array( array( 'order_line' => array() ) + $base, ErrorCode::Val002 ),
			'no product'    => array( array( 'order_line' => array( array( 'quantity' => 1, 'price_unit' => 100 ) ) ) + $base, ErrorCode::Map001 ),
			'no quantity'   => array( array( 'order_line' => array( array( 'product' => 'X', 'price_unit' => 100 ) ) ) + $base, ErrorCode::Val002 ),
			'decimal price' => array( array( 'order_line' => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => '1.50' ) ) ) + $base, ErrorCode::Val002 ),
		);
		foreach ( $cases as $name => $case ) {
			$result = QuickBooksOrderPayload::parse( $case[0], $this->item() );
			$this->assertInstanceOf( ConnectorError::class, $result, $name );
			$this->assertSame( $case[1], $result->code, $name );
		}
	}

	public function test_the_client_reference_always_comes_from_the_queue_item_and_the_excluded_total_is_taken_off(): void {
		$item    = new Item( 9, 1, EntityType::Order, '9', 'created', 'order:9', 'k', ItemState::Syncing );
		$payload = QuickBooksOrderPayload::parse(
			array(
				'client_order_ref' => 'WC-EVIL',
				'partner'          => array( 'name' => 'A', 'email' => 'a@example.test' ),
				'order_line'       => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => 100 ) ),
				'total'            => 5000,
				'excluded_total'   => 1200,
			),
			$item
		);
		$this->assertSame( 'WC-9', $payload->clientRef );
		$this->assertSame( 3800, $payload->totalMinor );
	}

	public function test_a_refund_payload_needs_the_order_it_belongs_to(): void {
		$item = new Item( 77, 1, EntityType::Refund, '77', 'refunded', 'order:9', 'k', ItemState::Syncing );
		$this->assertInstanceOf( ConnectorError::class, QuickBooksRefundPayload::parse( array( 'amount' => 100 ), $item ) );
		$refund = QuickBooksRefundPayload::parse( array( 'order_ref' => 'WC-9', 'amount' => 100 ), $item );
		$this->assertInstanceOf( QuickBooksRefundPayload::class, $refund );
		$this->assertSame( 'WC-77', $refund->clientRef );
	}

	public function test_faults_are_read_and_mapped_by_their_code(): void {
		$fault = static fn( string $code, string $message = 'm', string $detail = 'd', string $element = '' ): ApiResponse => new ApiResponse( 400, (string) json_encode( array( 'Fault' => array( 'Error' => array( array( 'Message' => $message, 'Detail' => $detail, 'code' => $code, 'element' => $element ) ), 'type' => 'ValidationFault' ) ) ) );

		$this->assertSame( array( array( 'code' => '6140', 'message' => 'Dup', 'detail' => 'Number used', 'element' => 'DocNumber' ) ), QuickBooksErrors::faults( $fault( '6140', 'Dup', 'Number used', 'DocNumber' )->json() ) );
		$this->assertTrue( QuickBooksErrors::has( $fault( '6240' ), '6240' ) );
		$this->assertFalse( QuickBooksErrors::has( $fault( '6240' ), '6140' ) );
		$this->assertFalse( QuickBooksErrors::has( null, '6140' ) );

		$this->assertSame( ErrorCode::Auth007, QuickBooksErrors::fromResponse( $fault( '3100' ) )->code );
		$this->assertSame( ErrorCode::Auth004, QuickBooksErrors::fromResponse( $fault( '120' ) )->code );
		$stale = QuickBooksErrors::fromResponse( $fault( '5010' ) );
		$this->assertSame( ErrorCode::Net002, $stale->code );
		$this->assertSame( ErrorClass::Retry, $stale->classOverride );
		$business = QuickBooksErrors::fromResponse( $fault( '6000', 'Business Validation Error', 'Amount is wrong', 'Line' ) );
		$this->assertSame( ErrorCode::Val002, $business->code );
		$this->assertSame( 'Line', $business->context['field'] );
		$this->assertSame( 'Business Validation Error: Amount is wrong', $business->context['reason'] );
		$this->assertNull( QuickBooksErrors::fromResponse( new ApiResponse( 500, '{"Fault":{"Error":[{"Message":"x","code":"10000"}]}}' ) ), 'A server error is left to the base.' );
		$this->assertNull( QuickBooksErrors::fromResponse( new ApiResponse( 400, 'not json' ) ) );
	}

	public function test_the_total_check_names_a_wrong_currency_and_tolerates_one_minor_unit(): void {
		$totals = new QuickBooksTotals( new QuickBooksAmounts() );
		$this->assertNull( $totals->check( array( 'CurrencyRef' => array( 'value' => 'GBP' ), 'TotalAmt' => 10.01 ), 'GBP', 'GBP', 1000 ) );
		$this->assertNull( $totals->check( array( 'TotalAmt' => 10.0 ), 'GBP', 'GBP', 1000 ), 'No CurrencyRef means the home currency.' );
		$this->assertNull( $totals->check( array( 'TotalAmt' => 99.0 ), 'GBP', 'GBP', null ), 'No expected total, nothing to compare.' );
		$wrongCurrency = $totals->check( array( 'CurrencyRef' => array( 'value' => 'USD' ), 'TotalAmt' => 10.0 ), 'GBP', 'GBP', 1000 );
		$this->assertSame( ErrorCode::Val002, $wrongCurrency->code );
		$this->assertStringContainsString( 'USD', (string) $wrongCurrency->context['reason'] );
		$this->assertNotNull( $totals->check( array( 'TotalAmt' => 10.02 ), 'GBP', 'GBP', 1000 ) );
		$this->assertNotNull( $totals->check( array(), 'GBP', 'GBP', 1000 ), 'A missing total is reported, not skipped.' );
	}

	public function test_the_unchecked_marker_is_found_in_the_private_note_only(): void {
		$this->assertTrue( QuickBooksDocuments::isUnchecked( array( 'PrivateNote' => 'WC-1' . QuickBooksDocumentBuilder::UNCHECKED ) ) );
		$this->assertFalse( QuickBooksDocuments::isUnchecked( array( 'PrivateNote' => 'WC-1' ) ) );
		$this->assertFalse( QuickBooksDocuments::isUnchecked( array() ) );
	}

	public function test_an_address_is_built_from_the_fields_it_has_and_long_values_are_cut(): void {
		$cut = false;
		$this->assertNull( QuickBooksCustomers::address( array(), $cut ) );
		$address = QuickBooksCustomers::address( array( 'street' => '1 Demo Street', 'zip' => str_repeat( '9', 40 ), 'country_id' => 'GB' ), $cut );
		$this->assertSame( '1 Demo Street', $address['Line1'] );
		$this->assertSame( 30, mb_strlen( $address['PostalCode'] ) );
		$this->assertSame( 'GB', $address['Country'] );
		$this->assertTrue( $cut );
	}

	public function test_the_document_builder_sends_prices_as_they_are_and_names_what_is_missing(): void {
		$builder = new QuickBooksDocumentBuilder( new QuickBooksOptions( array( 'unknown_sku' => 'free_text' ) ), new QuickBooksAmounts() );
		$parsed  = QuickBooksOrderPayload::parse(
			array(
				'partner'    => array( 'name' => 'A', 'email' => 'a@example.test' ),
				'currency'   => 'GBP',
				'order_line' => array( array( 'product' => 'X', 'quantity' => 3, 'price_unit' => 1999, 'tax' => '5' ) ),
			),
			$this->item()
		);
		$warnings = array();

		$noItem = $builder->invoice( $parsed, '12', 'GBP', 'GBP', false, false, array(), array(), $warnings );
		$this->assertInstanceOf( ConnectorError::class, $noItem );
		$this->assertStringContainsString( 'generic_item', (string) $noItem->context['reason'] );

		$body = $builder->invoice( $parsed, '12', 'GBP', 'GBP', false, false, array(), array( 'generic' => array( 'Id' => '77', 'Name' => 'General' ) ), $warnings );
		$this->assertSame( 'WC-9', $body['DocNumber'] );
		$this->assertSame( 'WC-9 [unchecked]', $body['PrivateNote'] );
		$this->assertSame( 19.99, $body['Line'][0]['SalesItemLineDetail']['UnitPrice'] );
		$this->assertSame( 59.97, $body['Line'][0]['Amount'] );
		$this->assertSame( 'TaxExcluded', $body['GlobalTaxCalculation'] );
		$this->assertArrayNotHasKey( 'CurrencyRef', $body, 'One currency in the company: nothing to name.' );
		$multi = $builder->invoice( $parsed, '12', 'GBP', 'GBP', true, false, array(), array( 'generic' => array( 'Id' => '77', 'Name' => 'General' ) ), $warnings );
		$this->assertSame( 'GBP', $multi['CurrencyRef']['value'], 'With more than one currency a document names its currency, the home one too.' );
		$this->assertArrayNotHasKey( 'TxnDate', $body, 'No date in the payload, no date sent: QuickBooks uses today.' );

		$us = $builder->invoice( $parsed, '12', 'USD', 'USD', false, true, array(), array( 'generic' => array( 'Id' => '77', 'Name' => 'General' ) ), $warnings );
		$this->assertArrayNotHasKey( 'GlobalTaxCalculation', $us );
		$foreign = $builder->invoice( $parsed, '12', 'EUR', 'GBP', true, false, array(), array( 'generic' => array( 'Id' => '77', 'Name' => 'General' ) ), $warnings );
		$this->assertSame( 'EUR', $foreign['CurrencyRef']['value'] );
	}
}
