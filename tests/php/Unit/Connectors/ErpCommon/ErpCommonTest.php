<?php
/**
 * The shared helpers of the ERP connectors: money, tax arithmetic, payload reading, addresses.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ErpCommon;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\PayloadReader;
use Estasoft\SyncEngine\Connectors\ErpCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\SiteAddress;
use Estasoft\SyncEngine\Connectors\ErpCommon\TaxPricing;
use Estasoft\SyncEngine\Connectors\ErpCommon\TotalCheck;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use PHPUnit\Framework\TestCase;

final class ErpCommonTest extends TestCase {

	private function item( EntityType $type = EntityType::Order, string $id = '77' ): Item {
		return new Item( 1, 1, $type, $id, 'created', 'x', 'k', ItemState::Syncing );
	}

	public function test_money_is_converted_by_the_currency_exponent_without_float_drift(): void {
		$amounts = new Amounts();
		$this->assertSame( 2, $amounts->exponent( 'AED' ) );
		$this->assertSame( 3, $amounts->exponent( 'kwd' ) );
		$this->assertSame( 0, $amounts->exponent( 'JPY' ) );
		$this->assertSame( 43795, $amounts->toMinor( 437.9499999999999, 2 ), 'Binary noise is rounded away.' );
		$this->assertSame( 37035, $amounts->toMinor( 37.035, 3 ) );
		$this->assertSame( -1050, $amounts->toMinor( '-10.50', 2 ) );
		$this->assertSame( 500, $amounts->toMinor( 5, 2 ) );
		$this->assertSame( 0, $amounts->toMinor( 'abc', 2 ) );
		$this->assertSame( '219.00', $amounts->toDecimalString( 21900, 2 ) );
		$this->assertSame( '-0.05', $amounts->toDecimalString( -5, 2 ) );
		$this->assertSame( '12.345', $amounts->toDecimalString( 12345, 3 ) );
		$this->assertSame( '1200', $amounts->toDecimalString( 1200, 0 ) );
		$this->assertSame( 21.9, $amounts->toDecimal( 2190, 2 ) );
		$this->assertSame( 3333, $amounts->perUnit( 10000, 3.0 ) );
		$this->assertSame( 5, $amounts->perUnit( 10, 2.0 ) );
	}

	public function test_dates_are_plain_days_and_empty_stays_empty(): void {
		$amounts = new Amounts();
		$this->assertSame( '2026-11-02', $amounts->dateOnly( '2026-11-02' ) );
		$this->assertSame( '2026-11-02', $amounts->dateOnly( '2026-11-02 23:59:59' ) );
		$this->assertSame( '2026-11-02', $amounts->dateOnly( '2026-11-02T07:34:00Z' ) );
		$this->assertSame( '', $amounts->dateOnly( '' ), 'No date is not today.' );
		$this->assertSame( '', $amounts->dateOnly( 'not a date' ) );
		$this->assertSame( '2026-11-02T00:00:00Z', $amounts->toIsoUtc( '2026-11-02' ) );
		$this->assertSame( '', $amounts->toIsoUtc( '' ) );
		$this->assertSame( '2026-11-09', $amounts->addDays( '2026-11-02', 7 ) );
		$this->assertSame( '2026-11-02', $amounts->addDays( '2026-11-02', 0 ) );
	}

	public function test_tax_rates_are_read_in_ten_thousandths_of_a_percent(): void {
		$this->assertSame( 50000, TaxPricing::rate( 5 ) );
		$this->assertSame( 50000, TaxPricing::rate( '5.00' ) );
		$this->assertSame( 125000, TaxPricing::rate( '12.5' ) );
		$this->assertSame( 72500, TaxPricing::rate( 7.25 ) );
		$this->assertSame( 0, TaxPricing::rate( 0 ) );
		$this->assertNull( TaxPricing::rate( '5%' ) );
		$this->assertNull( TaxPricing::rate( '-1' ) );
		$this->assertNull( TaxPricing::rate( 'abc' ) );
	}

	public function test_prices_move_between_with_and_without_tax_in_whole_numbers(): void {
		$this->assertSame( 22995, TaxPricing::withTax( 21900, 50000 ) );
		$this->assertSame( 21900, TaxPricing::withoutTax( 22995, 50000 ) );
		$this->assertSame( -22995, TaxPricing::withTax( -21900, 50000 ) );
		$this->assertSame( 1, TaxPricing::withTax( 1, 50000 ), 'Half a unit rounds away from zero only at .5 or more.' );
		$this->assertSame( 22995, TaxPricing::convert( 21900, 50000, false, true ) );
		$this->assertSame( 21900, TaxPricing::convert( 22995, 50000, true, false ) );
		$this->assertSame( 21900, TaxPricing::convert( 21900, 50000, true, true ), 'Both agree: nothing changes.' );
		$this->assertSame( 21900, TaxPricing::convert( 21900, 0, false, true ), 'A zero rate needs no conversion.' );
	}

	public function test_a_payload_is_read_into_typed_values(): void {
		$payload = OrderPayload::parse(
			array(
				'currency'       => 'aed',
				'date_order'     => '2026-11-02 07:34:00',
				'note'           => ' Gift ',
				'total'          => 50000,
				'excluded_total' => '6200',
				'partner'        => array( 'name' => ' Noor ', 'email' => ' a@b.test ', 'phone' => '', 'city' => 'Dubai' ),
				'order_line'     => array( array( 'product' => 'A', 'quantity' => '2', 'price_unit' => 1000, 'tax' => 'VAT' ), 'garbage' ),
				'shipping'       => array( 'total' => 500 ),
				'fee'            => array( array( 'name' => 'Wrap', 'total' => 100, 'tax' => array( 'VAT', 'VAT' ) ) ),
				'discount'       => array( 'total' => -300 ),
				'payment'        => array( 'reference' => ' ch_1 ', 'paid_at' => '2026-11-03' ),
			),
			$this->item(),
			'X',
			new Amounts()
		);

		$this->assertNotInstanceOf( ConnectorError::class, $payload );
		$this->assertSame( 'WC-77', $payload->clientRef );
		$this->assertSame( 'AED', $payload->currency );
		$this->assertSame( '2026-11-02', $payload->date );
		$this->assertSame( 'Gift', $payload->note );
		$this->assertSame( 43800, $payload->totalMinor );
		$this->assertSame( array( 'name' => 'Noor', 'email' => 'a@b.test', 'city' => 'Dubai' ), $payload->partner );
		$this->assertCount( 1, $payload->lines );
		$this->assertSame( 2.0, $payload->lines[0]['quantity'] );
		$this->assertSame( array( 'VAT' ), $payload->lines[0]['tax'] );
		$this->assertSame( array( 'shipping', 'fee', 'discount' ), array_column( $payload->extras, 'kind' ) );
		$this->assertSame( 300, $payload->extras[2]['amount_minor'], 'A discount is a positive amount.' );
		$this->assertSame( array( 'VAT' ), $payload->taxCodes() );
		$this->assertTrue( $payload->isPaid() );
		$this->assertSame( 'ch_1', $payload->paymentReference );
		$this->assertTrue( $payload->taxesMapped );
		$this->assertFalse( $payload->pricesIncludeTax );
	}

	public function test_a_bad_payload_names_the_problem(): void {
		$amounts = new Amounts();
		$base    = array( 'partner' => array( 'name' => 'A', 'email' => 'a@b.test' ), 'order_line' => array( array( 'product' => 'A', 'quantity' => 1, 'price_unit' => 100 ) ) );
		$cases   = array(
			'no email'    => array( array_replace_recursive( $base, array( 'partner' => array( 'email' => '' ) ) ), ErrorCode::Map003 ),
			'no name'     => array( array_replace_recursive( $base, array( 'partner' => array( 'name' => '' ) ) ), ErrorCode::Val002 ),
			'no lines'    => array( array_replace( $base, array( 'order_line' => array() ) ), ErrorCode::Val002 ),
			'no sku'      => array( array_replace( $base, array( 'order_line' => array( array( 'quantity' => 1, 'price_unit' => 1 ) ) ) ), ErrorCode::Map001 ),
			'no quantity' => array( array_replace( $base, array( 'order_line' => array( array( 'product' => 'A', 'quantity' => 0, 'price_unit' => 1 ) ) ) ), ErrorCode::Val002 ),
			'float price' => array( array_replace( $base, array( 'order_line' => array( array( 'product' => 'A', 'quantity' => 1, 'price_unit' => 10.5 ) ) ) ), ErrorCode::Val002 ),
		);
		foreach ( $cases as $name => $case ) {
			$result = OrderPayload::parse( $case[0], $this->item(), 'X', $amounts );
			$this->assertInstanceOf( ConnectorError::class, $result, $name );
			$this->assertSame( $case[1], $result->code, $name );
		}
		$this->assertInstanceOf( OrderPayload::class, OrderPayload::parse( array_replace( $base, array( 'partner' => array() ) ), $this->item(), 'X', $amounts, false ), 'A connector that does not need the buyer does not ask for it.' );
	}

	public function test_a_refund_payload_is_read_and_checked(): void {
		$amounts = new Amounts();
		$refund  = RefundPayload::parse(
			array(
				'order_ref'       => 'WC-12',
				'date'            => '2026-11-03',
				'reason'          => ' Damaged ',
				'amount'          => -21900,
				'shipping_amount' => '2500',
				'refund_line'     => array( array( 'product' => 'A', 'quantity' => '-1', 'amount' => -21900 ) ),
			),
			$this->item( EntityType::Refund, '99' ),
			'X',
			$amounts
		);
		$this->assertNotInstanceOf( ConnectorError::class, $refund );
		$this->assertSame( 'WC-99', $refund->clientRef );
		$this->assertSame( 'WC-12', $refund->orderRef );
		$this->assertSame( 21900, $refund->amountMinor );
		$this->assertSame( 2500, $refund->shippingMinor );
		$this->assertSame( 1.0, $refund->lines[0]['quantity'] );
		$this->assertSame( 21900, $refund->lines[0]['amount_minor'] );
		$this->assertFalse( $refund->isAmountOnly() );

		$this->assertInstanceOf( ConnectorError::class, RefundPayload::parse( array( 'order_ref' => '12' ), $this->item( EntityType::Refund ), 'X', $amounts ) );
		$this->assertInstanceOf( ConnectorError::class, RefundPayload::parse( array( 'order_ref' => 'WC-1', 'refund_line' => array( array( 'product' => '', 'quantity' => 1, 'amount' => 1 ) ) ), $this->item( EntityType::Refund ), 'X', $amounts ) );
		$this->assertInstanceOf( ConnectorError::class, RefundPayload::parse( array( 'order_ref' => 'WC-1', 'refund_line' => array( array( 'product' => 'A', 'quantity' => 0, 'amount' => 1 ) ) ), $this->item( EntityType::Refund ), 'X', $amounts ) );
		$only = RefundPayload::parse( array( 'order_ref' => 'WC-1', 'amount' => 500 ), $this->item( EntityType::Refund ), 'X', $amounts );
		$this->assertTrue( $only->isAmountOnly() );
		$shipping = RefundPayload::parse( array( 'order_ref' => 'WC-1', 'amount' => 500, 'shipping_amount' => 500 ), $this->item( EntityType::Refund ), 'X', $amounts );
		$this->assertFalse( $shipping->isAmountOnly(), 'Refunded shipping is itemised, so it is not an amount without lines.' );
	}

	public function test_the_total_check_accepts_one_minor_unit_and_names_both_totals(): void {
		$check = new TotalCheck( new Options( array() ), new Amounts(), 'ERPNext' );
		$this->assertNull( $check->difference( null, 5, 'AED', 'SO-1' ) );
		$this->assertNull( $check->difference( 43800, 438.01, 'AED', 'SO-1' ) );
		$this->assertNull( $check->difference( 43800, 437.99, 'AED', 'SO-1' ) );
		$diff = $check->difference( 43800, 440.0, 'AED', 'SO-1' );
		$this->assertSame( 44000, $diff['system_minor'] );
		$this->assertStringContainsString( '440.00 AED', $diff['message'] );
		$this->assertStringContainsString( '438.00 AED', $diff['message'] );
		$this->assertTrue( $check->isStrict() );
		$this->assertFalse( ( new TotalCheck( new Options( array( 'total_mismatch' => 'warn' ) ), new Amounts(), 'ERPNext' ) )->isStrict() );
		$this->assertSame( 'total', $check->error( $diff, 'Left.' )->context['field'] );
		$kwd = $check->difference( 12345, 12.346, 'KWD', 'SO-2' );
		$this->assertNull( $kwd, 'One fils is rounding.' );
	}

	public function test_options_are_typed_whatever_was_saved(): void {
		$options = new Options( array( 'a' => ' x ', 'b' => 'POST', 'c' => 'yes', 'd' => '7', 'e' => array( 'bad' ), 'f' => 99 ) );
		$this->assertSame( 'x', $options->text( 'a' ) );
		$this->assertSame( '', $options->text( 'e' ) );
		$this->assertSame( 'post', $options->choice( 'b', array( 'draft', 'post' ), 'draft' ) );
		$this->assertSame( 'draft', $options->choice( 'zz', array( 'draft', 'post' ), 'draft' ) );
		$this->assertTrue( $options->flag( 'c', false ) );
		$this->assertSame( 7, $options->integer( 'd', 0, 10, 1 ) );
		$this->assertSame( 10, $options->integer( 'f', 0, 10, 1 ) );
		$this->assertSame( 1, $options->integer( 'e', 0, 10, 1 ) );
	}

	public function test_a_site_address_is_cleaned_or_refused(): void {
		$this->assertSame( 'https://erp.example.com', SiteAddress::normalise( 'ERP.Example.com/app/home?x=1#y' ) );
		$this->assertSame( 'https://erp.example.com:8443', SiteAddress::normalise( 'https://erp.example.com:8443/' ) );
		$this->assertSame( 'https://sage.example.com/Sage300WebApi', SiteAddress::normalise( 'https://sage.example.com/Sage300WebApi/', true ) );
		$this->assertSame( 'https://acme.example.com/Instance_1', SiteAddress::normalise( 'https://acme.example.com/Instance_1/entity/Default/24.200.001?x', true, array( 'entity', 'identity' ) ) );
		$this->assertSame( 'https://acme.example.com', SiteAddress::normalise( 'https://acme.example.com/entity', true, array( 'entity' ) ) );
		$this->assertNull( SiteAddress::normalise( 'https://user:pass@erp.example.com' ) );
		$this->assertNull( SiteAddress::normalise( 'ftp://erp.example.com' ) );
		$this->assertNull( SiteAddress::normalise( '' ) );
		$this->assertNull( SiteAddress::normalise( 'https://' ) );
		$this->assertSame( 'http://localhost', SiteAddress::normalise( 'http://localhost' ), 'Whether http or a private host may be used is the host policy\'s decision.' );
	}

	public function test_the_payload_reader_does_not_trust_types(): void {
		$this->assertSame( 21900, PayloadReader::minor( '21900' ) );
		$this->assertSame( 5, PayloadReader::minor( 5.0 ) );
		$this->assertNull( PayloadReader::minor( 5.5 ) );
		$this->assertNull( PayloadReader::minor( 'abc' ) );
		$this->assertSame( '', PayloadReader::text( array( 'x' ) ) );
		$this->assertSame( '1', PayloadReader::text( 1 ) );
		$this->assertSame( '', PayloadReader::text( true ) );
		$this->assertTrue( PayloadReader::flag( 'Yes', false ) );
		$this->assertFalse( PayloadReader::flag( 'off', true ) );
		$this->assertTrue( PayloadReader::flag( 'maybe', true ) );
		$this->assertSame( array( 'A', 'B' ), PayloadReader::taxCodes( array( ' A ', 'B', 'A', '' ) ) );
		$this->assertSame( array(), PayloadReader::taxCodes( '' ) );
		$this->assertSame( array(), PayloadReader::rows( 'x' ) );
	}
}
