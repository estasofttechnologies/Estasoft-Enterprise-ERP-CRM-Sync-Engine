<?php
/**
 * Money, percentages and the exact split of a line.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Wafeq;

use Estasoft\SyncEngine\Connectors\Wafeq\WafeqAmounts;
use Estasoft\SyncEngine\Connectors\Wafeq\WafeqLines;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class WafeqAmountsTest extends TestCase {

	private WafeqAmounts $amounts;

	protected function setUp(): void {
		$this->amounts = new WafeqAmounts();
	}

	public function test_exponents_follow_the_currency(): void {
		$this->assertSame( 2, $this->amounts->exponent( 'sar' ) );
		$this->assertSame( 3, $this->amounts->exponent( 'KWD' ) );
		$this->assertSame( 3, $this->amounts->exponent( 'BHD' ) );
		$this->assertSame( 0, $this->amounts->exponent( 'JPY' ) );
		$this->assertSame( 2, $this->amounts->exponent( '' ) );
	}

	/**
	 * @return array<string,array{0:mixed,1:int,2:?int}>
	 */
	public static function provide_wafeq_amounts(): array {
		return array(
			'plain string'          => array( '219.00', 2, 21900 ),
			'long fraction rounds'  => array( '55.000000', 2, 5500 ),
			'half up'               => array( '10.005', 2, 1001 ),
			'below half'            => array( '10.004', 2, 1000 ),
			'integer number'        => array( 12, 2, 1200 ),
			'float number'          => array( 19.99, 2, 1999 ),
			'three decimals'        => array( '12.345', 3, 12345 ),
			'negative'              => array( '-4.50', 2, -450 ),
			'no decimals currency'  => array( '1500', 0, 1500 ),
			'not a number'          => array( 'abc', 2, null ),
			'null'                  => array( null, 2, null ),
		);
	}

	/**
	 * @dataProvider provide_wafeq_amounts
	 *
	 * @param mixed    $value    Wafeq value.
	 * @param int      $exponent Exponent.
	 * @param int|null $expected Minor units.
	 */
	public function test_wafeq_amounts_become_minor_units_without_float_drift( mixed $value, int $exponent, ?int $expected ): void {
		$this->assertSame( $expected, $this->amounts->toMinor( $value, $exponent ) );
	}

	public function test_minor_units_become_the_number_wafeq_expects(): void {
		$this->assertSame( 19.99, $this->amounts->number( 1999, 2 ) );
		$this->assertSame( 12.345, $this->amounts->number( 12345, 3 ) );
		$this->assertSame( 1500, $this->amounts->number( 1500, 0 ) );
		$this->assertSame( 0.05, $this->amounts->number( 5, 2 ) );
		$this->assertSame( '-0.50', $this->amounts->decimalString( -50, 2 ) );
		$this->assertSame( '0.001', $this->amounts->decimalString( 1, 3 ) );
	}

	public function test_rates_are_basis_points_and_tax_is_rounded_half_away_from_zero(): void {
		$this->assertSame( 1500, $this->amounts->basisPoints( '15' ) );
		$this->assertSame( 550, $this->amounts->basisPoints( '5.5%' ) );
		$this->assertSame( 500, $this->amounts->basisPoints( 5 ) );
		$this->assertNull( $this->amounts->basisPoints( 'n/a' ) );
		$this->assertSame( 1200, $this->amounts->taxOf( 7998, 1500 ) );
		$this->assertSame( -1200, $this->amounts->taxOf( -7998, 1500 ) );
		$this->assertSame( 7998, $this->amounts->netFromGross( 9198, 1500 ) );
		$this->assertSame( 100, $this->amounts->netFromGross( 105, 500 ) );
	}

	public function test_a_line_that_does_not_divide_is_split_so_the_sum_is_exact(): void {
		$lines  = new WafeqLines( $this->amounts );
		$rates  = array();
		$built  = $lines->build( array( array( 'name' => 'Mug', 'quantity' => 3.0, 'total_minor' => 1000, 'tax_code' => '', 'item' => '' ) ), 'SAR', false, $rates, 'acc_rev' );
		$this->assertIsArray( $built );
		$this->assertSame( 1000, $built['net_minor'] );
		$this->assertCount( 2, $built['lines'] );

		$exact = $lines->build( array( array( 'name' => 'Mug', 'quantity' => 4.0, 'total_minor' => 1000, 'tax_code' => '', 'item' => '' ) ), 'SAR', false, $rates, 'acc_rev' );
		$this->assertCount( 1, $exact['lines'] );
		$this->assertSame( 2.5, $exact['lines'][0]['unit_amount'] );

		$many = $lines->build( array( array( 'name' => 'Pin', 'quantity' => 7.0, 'total_minor' => 1 , 'tax_code' => '', 'item' => '' ) ), 'SAR', false, $rates, 'acc_rev' );
		$this->assertSame( 1, $many['net_minor'] );
		$this->assertSame( array( 6, 0.0 ), array( $many['lines'][0]['quantity'], $many['lines'][0]['unit_amount'] ) );
	}

	public function test_a_fractional_quantity_is_one_line_that_keeps_the_quantity_in_its_text(): void {
		$lines = new WafeqLines( $this->amounts );
		$built = $lines->build( array( array( 'name' => 'Fabric', 'quantity' => 1.5, 'total_minor' => 1500, 'tax_code' => '', 'item' => '' ) ), 'SAR', false, array(), 'acc_rev' );
		$this->assertCount( 1, $built['lines'] );
		$this->assertSame( 1, $built['lines'][0]['quantity'] );
		$this->assertSame( 'Fabric (× 1.5)', $built['lines'][0]['description'] );
		$this->assertSame( 15.0, $built['lines'][0]['unit_amount'] );
	}

	public function test_three_decimal_currencies_split_in_thousandths(): void {
		$lines = new WafeqLines( $this->amounts );
		$built = $lines->build( array( array( 'name' => 'Oud', 'quantity' => 3.0, 'total_minor' => 10000, 'tax_code' => '', 'item' => '' ) ), 'KWD', false, array(), 'acc_rev' );
		$this->assertSame( 10000, $built['net_minor'] );
		$this->assertSame( 3.333, $built['lines'][0]['unit_amount'] );
		$this->assertSame( 3.334, $built['lines'][1]['unit_amount'] );
	}

	public function test_a_tax_rate_whose_percentage_is_not_known_cannot_convert_inclusive_prices(): void {
		$lines = new WafeqLines( $this->amounts );
		$rates = array( 'tax_x' => array( 'id' => 'tax_x', 'name' => 'Odd tax', 'type' => 'SALES', 'rate_bp' => null ) );
		$built = $lines->build( array( array( 'name' => 'Mug', 'quantity' => 1.0, 'total_minor' => 1150, 'tax_code' => 'tax_x', 'item' => '' ) ), 'SAR', true, $rates, 'acc_rev' );
		$this->assertInstanceOf( ConnectorError::class, $built );
		$this->assertSame( ErrorCode::Val002, $built->code );
		$this->assertStringContainsString( 'Odd tax', $built->context['reason'] );
	}

	public function test_the_rate_that_explains_a_tax_amount_is_found_within_one_minor_unit(): void {
		$lines = new WafeqLines( $this->amounts );
		$rates = array(
			'tax_15' => array( 'id' => 'tax_15', 'name' => 'VAT 15%', 'type' => 'SALES', 'rate_bp' => 1500 ),
			'tax_5'  => array( 'id' => 'tax_5', 'name' => 'VAT 5%', 'type' => 'SALES', 'rate_bp' => 500 ),
			'tax_0'  => array( 'id' => 'tax_0', 'name' => 'Zero', 'type' => 'SALES', 'rate_bp' => 0 ),
		);
		$this->assertSame( 'tax_15', $lines->rateFor( 3999, 600, $rates ) );
		$this->assertSame( 'tax_15', $lines->rateFor( 3999, 599, $rates ) );
		$this->assertSame( 'tax_5', $lines->rateFor( 3999, 200, $rates ) );
		$this->assertSame( '', $lines->rateFor( 3999, 400, $rates ) );
	}
}
