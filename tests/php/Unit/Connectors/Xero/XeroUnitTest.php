<?php
/**
 * The small pure parts of the Xero connector: amounts, payload reading, error shapes.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Xero;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Xero\XeroAmounts;
use Estasoft\SyncEngine\Connectors\Xero\XeroAnswer;
use Estasoft\SyncEngine\Connectors\Xero\XeroErrors;
use Estasoft\SyncEngine\Connectors\Xero\XeroOptions;
use Estasoft\SyncEngine\Connectors\Xero\XeroOrderPayload;
use Estasoft\SyncEngine\Connectors\Xero\XeroPayloadReader;
use Estasoft\SyncEngine\Connectors\Xero\XeroRefundPayload;
use Estasoft\SyncEngine\Connectors\Xero\XeroTotals;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

final class XeroUnitTest extends TestCase {

	public function test_amounts_use_the_currency_exponent_and_never_float_arithmetic(): void {
		$amounts = new XeroAmounts();
		$this->assertSame( 19.99, $amounts->number( 1999, 'AED' ) );
		$this->assertSame( 12.345, $amounts->number( 12345, 'KWD' ) );
		$this->assertSame( 1500.0, $amounts->number( 1500, 'JPY' ) );
		$this->assertSame( -8.0, $amounts->number( -800, 'AED' ) );
		$this->assertSame( 5997, $amounts->fromNumber( 59.97, 'AED' ) );
		$this->assertSame( 37035, $amounts->fromNumber( 37.035, 'KWD' ) );
		$this->assertSame( 1, $amounts->fromNumber( '0.01', 'USD' ) );
		$this->assertNull( $amounts->fromNumber( 'abc', 'USD' ) );
		$this->assertNull( $amounts->fromNumber( null, 'USD' ) );
		$this->assertTrue( $amounts->agree( 1000, 1001 ) );
		$this->assertFalse( $amounts->agree( 1000, 1002 ) );
		$this->assertSame( '120.50 AED', $amounts->show( 12050, 'AED' ) );
	}

	public function test_a_line_total_is_whole_minor_units_rounded_half_away_from_zero(): void {
		$amounts = new XeroAmounts();
		$this->assertSame( 5997, $amounts->lineTotal( '3', 1999 ) );
		$this->assertSame( 500, $amounts->lineTotal( '0.5', 1000 ) );
		$this->assertSame( 333, $amounts->lineTotal( '0.333', 1000 ) );
		$this->assertSame( 2, $amounts->lineTotal( '0.5', 3 ) );
		$this->assertSame( -2, $amounts->lineTotal( '0.5', -3 ) );
		$this->assertSame( 0, $amounts->lineTotal( '1', 0 ) );
	}

	public function test_the_reader_does_not_trust_types(): void {
		$this->assertSame( 2100, XeroPayloadReader::minor( '2100' ) );
		$this->assertNull( XeroPayloadReader::minor( '21.5' ) );
		$this->assertNull( XeroPayloadReader::minor( 21.5 ) );
		$this->assertSame( 2150, XeroPayloadReader::minor( 2150.0 ) );
		$this->assertSame( '2', XeroPayloadReader::quantity( 2 ) );
		$this->assertSame( '0.5', XeroPayloadReader::quantity( '0.50' ) );
		$this->assertNull( XeroPayloadReader::quantity( 0 ) );
		$this->assertNull( XeroPayloadReader::quantity( 'many' ) );
		$this->assertSame( '2026-11-02', XeroPayloadReader::day( '2026-11-02 07:34:00' ) );
		$this->assertSame( '', XeroPayloadReader::day( '2026-13-45' ) );
		$this->assertSame( '', XeroPayloadReader::day( null ) );
		$this->assertSame( array(), XeroPayloadReader::taxCodes( '' ) );
		$this->assertSame( array( 'A', 'B' ), XeroPayloadReader::taxCodes( array( 'A', ' B ', 'A', '' ) ) );
		$this->assertTrue( XeroPayloadReader::flag( 'yes', false ) );
		$this->assertFalse( XeroPayloadReader::flag( 'banana', false ) );
	}

	public function test_a_long_text_is_cut_with_an_ellipsis_and_the_cut_is_reported(): void {
		$cut  = false;
		$text = XeroPayloadReader::limit( str_repeat( 'ب', 300 ), 255, $cut );
		$this->assertSame( 255, mb_strlen( $text ) );
		$this->assertStringEndsWith( "\u{2026}", $text );
		$this->assertTrue( $cut );
		$cut = false;
		$this->assertSame( 'short', XeroPayloadReader::limit( 'short', 255, $cut ) );
		$this->assertFalse( $cut );
	}

	public function test_options_come_from_the_options_array_or_the_config_with_safe_defaults(): void {
		$options = new XeroOptions( array( 'documents' => 'invoice_paid', 'due_days' => '9999', 'allow_backorders' => 'no' ) );
		$this->assertTrue( $options->pays() );
		$this->assertTrue( $options->approves() );
		$this->assertSame( 365, $options->integer( 'due_days', 0, 0, 365 ) );
		$this->assertFalse( $options->flag( 'allow_backorders', true ) );
		$this->assertSame( 'invoice_approved', ( new XeroOptions( array( 'documents' => 'nonsense' ) ) )->documents() );
		$this->assertFalse( ( new XeroOptions() )->pays() );
	}

	private function item(): Item {
		return new Item( 9, 1, EntityType::Order, '9', 'created', 'order:9', 'k', ItemState::Syncing );
	}

	public function test_the_order_payload_names_the_first_thing_that_is_wrong(): void {
		$base = array(
			'partner'    => array( 'name' => 'A', 'email' => 'a@example.test' ),
			'order_line' => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => 100 ) ),
		);
		$this->assertInstanceOf( XeroOrderPayload::class, XeroOrderPayload::parse( $base, $this->item() ) );

		$cases = array(
			'no email'      => array( array( 'partner' => array( 'name' => 'A' ) ) + $base, ErrorCode::Map003 ),
			'no name'       => array( array( 'partner' => array( 'email' => 'a@example.test' ) ) + $base, ErrorCode::Val002 ),
			'no lines'      => array( array( 'order_line' => array() ) + $base, ErrorCode::Val002 ),
			'no product'    => array( array( 'order_line' => array( array( 'quantity' => 1, 'price_unit' => 100 ) ) ) + $base, ErrorCode::Map001 ),
			'no quantity'   => array( array( 'order_line' => array( array( 'product' => 'X', 'price_unit' => 100 ) ) ) + $base, ErrorCode::Val002 ),
			'decimal price' => array( array( 'order_line' => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => '1.50' ) ) ) + $base, ErrorCode::Val002 ),
		);
		foreach ( $cases as $name => $case ) {
			$result = XeroOrderPayload::parse( $case[0], $this->item() );
			$this->assertInstanceOf( ConnectorError::class, $result, $name );
			$this->assertSame( $case[1], $result->code, $name );
		}
	}

	public function test_the_client_reference_always_comes_from_the_queue_item_and_the_excluded_total_is_taken_off(): void {
		$payload = XeroOrderPayload::parse(
			array(
				'client_order_ref' => 'WC-EVIL',
				'partner'          => array( 'name' => 'A', 'email' => 'a@example.test' ),
				'order_line'       => array( array( 'product' => 'X', 'quantity' => 1, 'price_unit' => 100 ) ),
				'total'            => 5000,
				'excluded_total'   => 1200,
			),
			$this->item()
		);
		$this->assertSame( 'WC-9', $payload->clientRef );
		$this->assertSame( 3800, $payload->totalMinor );
	}

	public function test_a_refund_payload_needs_the_order_it_belongs_to(): void {
		$item = new Item( 77, 1, EntityType::Refund, '77', 'refunded', 'order:9', 'k', ItemState::Syncing );
		$this->assertInstanceOf( ConnectorError::class, XeroRefundPayload::parse( array( 'amount' => 100 ), $item ) );
		$refund = XeroRefundPayload::parse( array( 'order_ref' => 'WC-9', 'amount' => 100 ), $item );
		$this->assertInstanceOf( XeroRefundPayload::class, $refund );
		$this->assertSame( 'WC-77', $refund->clientRef );
	}

	public function test_validation_messages_are_read_from_every_shape_xero_uses(): void {
		$exception = array( 'Type' => 'ValidationException', 'Message' => 'A validation exception occurred', 'Elements' => array( array( 'ValidationErrors' => array( array( 'Message' => 'Account code \'x\' is not a valid code' ) ) ) ) );
		$this->assertSame( array( 'Account code \'x\' is not a valid code' ), XeroErrors::validationMessages( $exception ) );

		$inside = array( 'Invoices' => array( array( 'HasErrors' => true, 'ValidationErrors' => array( array( 'Message' => 'One' ), array( 'Message' => 'Two' ) ) ) ) );
		$this->assertSame( array( 'One', 'Two' ), XeroErrors::validationMessages( $inside ) );

		$plain = array( 'Type' => 'ValidationException', 'Message' => 'Something specific' );
		$this->assertSame( array( 'Something specific' ), XeroErrors::validationMessages( $plain ) );
		$this->assertSame( array(), XeroErrors::validationMessages( array() ) );
	}

	public function test_the_name_conflict_text_is_recognised(): void {
		$this->assertTrue( XeroErrors::nameInUse( array( "The contact name 'A' is already assigned to another contact." ) ) );
		$this->assertFalse( XeroErrors::nameInUse( array( 'Email address must be valid.' ) ) );
	}

	public function test_a_200_answer_with_validation_errors_is_a_rejection_not_a_record(): void {
		$body = (string) json_encode( array( 'Invoices' => array( array( 'HasErrors' => true, 'ValidationErrors' => array( array( 'Message' => 'Tax type does not exist' ) ) ) ) ) );
		try {
			XeroAnswer::element( new ApiResponse( 200, $body ), 'Invoices' );
			$this->fail( 'The record had errors.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( ErrorCode::Val002, $exception->error()->code );
			$this->assertSame( 'Tax type does not exist', $exception->error()->context['reason'] );
		}
		$this->expectException( ApiException::class );
		XeroAnswer::element( new ApiResponse( 200, '{}' ), 'Invoices' );
	}

	public function test_the_total_check_names_a_wrong_currency_and_tolerates_one_minor_unit(): void {
		$totals = new XeroTotals( new XeroAmounts() );
		$this->assertNull( $totals->check( array( 'CurrencyCode' => 'AED', 'Total' => 10.01 ), 'AED', 1000 ) );
		$this->assertNull( $totals->check( array( 'CurrencyCode' => 'AED', 'Total' => 99.0 ), 'AED', null ), 'No expected total, nothing to compare.' );
		$wrongCurrency = $totals->check( array( 'CurrencyCode' => 'USD', 'Total' => 10.0 ), 'AED', 1000 );
		$this->assertSame( ErrorCode::Val002, $wrongCurrency->code );
		$this->assertStringContainsString( 'USD', (string) $wrongCurrency->context['reason'] );
		$this->assertNotNull( $totals->check( array( 'CurrencyCode' => 'AED', 'Total' => 10.02 ), 'AED', 1000 ) );
		$this->assertNotNull( $totals->check( array( 'CurrencyCode' => 'AED' ), 'AED', 1000 ), 'A missing total is reported, not skipped.' );
	}
}
