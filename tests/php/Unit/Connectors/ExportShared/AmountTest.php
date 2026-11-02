<?php
/**
 * Money, quantity and percentage text: integer arithmetic only.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ExportShared;

use Estasoft\SyncEngine\Connectors\ExportShared\Amount;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\Amount
 */
final class AmountTest extends TestCase {

	/**
	 * The decimals follow the currency, the separator the format.
	 */
	public function test_decimal(): void {
		$this->assertSame( '157.50', Amount::decimal( 15750, 'AED' ) );
		$this->assertSame( '157,50', Amount::decimal( 15750, 'EUR', ',' ) );
		$this->assertSame( '15.750', Amount::decimal( 15750, 'KWD' ) );
		$this->assertSame( '15,750', Amount::decimal( 15750, 'KWD', ',' ) );
		$this->assertSame( '15750', Amount::decimal( 15750, 'JPY' ) );
		$this->assertSame( '-0.05', Amount::decimal( -5, 'USD' ) );
		$this->assertSame( '0.00', Amount::decimal( 0, 'USD' ) );
		$this->assertSame( '1234567890.12', Amount::decimal( 123456789012, 'USD' ) );
	}

	/**
	 * Minor units from payload values: integers, whole floats and digit strings, nothing else.
	 */
	public function test_minor(): void {
		$this->assertSame( 150, Amount::minor( 150 ) );
		$this->assertSame( 150, Amount::minor( 150.0 ) );
		$this->assertSame( -150, Amount::minor( '-150' ) );
		$this->assertSame( 150, Amount::minor( ' 150 ' ) );
		$this->assertNull( Amount::minor( 1.5 ) );
		$this->assertNull( Amount::minor( '1.50' ) );
		$this->assertNull( Amount::minor( '' ) );
		$this->assertNull( Amount::minor( null ) );
		$this->assertNull( Amount::minor( array() ) );
		$this->assertNull( Amount::minor( NAN ) );
	}

	/**
	 * Rates keep four decimals and print without trailing zeros.
	 */
	public function test_rate(): void {
		$this->assertSame( 50000, Amount::rate( 5 ) );
		$this->assertSame( 75000, Amount::rate( 7.5 ) );
		$this->assertSame( 75000, Amount::rate( '7.5' ) );
		$this->assertSame( 75000, Amount::rate( '7,5' ) );
		$this->assertSame( 88750, Amount::rate( '8.875' ) );
		$this->assertNull( Amount::rate( '' ) );
		$this->assertNull( Amount::rate( 'five' ) );
		$this->assertSame( '5', Amount::rateText( 50000 ) );
		$this->assertSame( '7.5', Amount::rateText( 75000 ) );
		$this->assertSame( '8.875', Amount::rateText( 88750 ) );
		$this->assertSame( '0', Amount::rateText( 0 ) );
		$this->assertSame( '0.05', Amount::rateText( 500 ) );
	}

	/**
	 * A gross amount splits into net and tax that add up to it, with the tax within half a unit of the exact value.
	 */
	public function test_split_is_exact_and_fair(): void {
		$this->assertSame(
			array(
				'net' => 1000,
				'tax' => 50,
			),
			Amount::split( 1050, Amount::rate( 5 ) )
		);
		$this->assertSame(
			array(
				'net' => 1000,
				'tax' => 0,
			),
			Amount::split( 1000, 0 )
		);
		$this->assertSame(
			array(
				'net' => -1000,
				'tax' => -50,
			),
			Amount::split( -1050, Amount::rate( 5 ) )
		);
		$scale = 1000000;
		foreach ( array( 5, 7, 19, 20, 7.5, 8.875, 25 ) as $percent ) {
			$rate = (int) Amount::rate( $percent );
			for ( $gross = 1; $gross <= 3000; $gross += 7 ) {
				$parts = Amount::split( $gross, $rate );
				$this->assertSame( $gross, $parts['net'] + $parts['tax'] );
				// |tax - gross * rate / (scale + rate)| <= 1/2, compared without division.
				$this->assertLessThanOrEqual( $scale + $rate, abs( 2 * $parts['tax'] * ( $scale + $rate ) - 2 * $gross * $rate ), "$percent% of $gross" );
			}
		}
	}

	/**
	 * Quantities: positive numbers with at most four decimals.
	 */
	public function test_quantity(): void {
		$this->assertSame( '2', Amount::quantity( 2 ) );
		$this->assertSame( '1.5', Amount::quantity( 1.5 ) );
		$this->assertSame( '1.5', Amount::quantity( '1.50' ) );
		$this->assertSame( '0.3333', Amount::quantity( 1 / 3 ) );
		$this->assertNull( Amount::quantity( 0 ) );
		$this->assertNull( Amount::quantity( -1 ) );
		$this->assertNull( Amount::quantity( '' ) );
		$this->assertNull( Amount::quantity( 'two' ) );
		$this->assertNull( Amount::quantity( 0.00001 ) );
		$this->assertSame( 15000, Amount::quantityUnits( '1.5' ) );
		$this->assertSame( 20000, Amount::quantityUnits( '2' ) );
	}

	/**
	 * A unit price keeps two decimals more than the currency when the division is not even.
	 */
	public function test_unit_price(): void {
		$this->assertSame( '50.00', Amount::unitPrice( 10000, '2', 'AED' ) );
		$this->assertSame( '6.6633', Amount::unitPrice( 1999, '3', 'USD' ) );
		$this->assertSame( '0.0333', Amount::unitPrice( 10, '3', 'USD' ) );
		$this->assertSame( '5.000', Amount::unitPrice( 10000, '2', 'KWD' ) );
		$this->assertSame( '3,50', Amount::unitPrice( 700, '2', 'EUR', ',' ) );
		$this->assertSame( '100', Amount::unitPrice( 200, '2', 'JPY' ) );
		$this->assertSame( '-10.00', Amount::unitPrice( -1000, '1', 'USD' ) );
		$this->assertSame( '0.00', Amount::unitPrice( 0, '1', 'USD' ) );
	}

	/**
	 * Price times quantity, rounded half up in whole units.
	 */
	public function test_times(): void {
		$this->assertSame( 10000, Amount::times( 5000, '2' ) );
		$this->assertSame( 7500, Amount::times( 5000, '1.5' ) );
		$this->assertSame( 2, Amount::times( 1, '1.5' ) );
		$this->assertSame( 1, Amount::times( 1, '1.4999' ) );
		$this->assertSame( -7500, Amount::times( -5000, '1.5' ) );
	}

	/**
	 * A rate worked out from the amounts has at most two decimals.
	 */
	public function test_observed_rate(): void {
		$this->assertSame( '5', Amount::observedRate( 10000, 500 ) );
		$this->assertSame( '7.5', Amount::observedRate( 10000, 750 ) );
		$this->assertSame( '19', Amount::observedRate( 5000, 950 ) );
		$this->assertSame( '', Amount::observedRate( 0, 10 ) );
		$this->assertSame( '', Amount::observedRate( 100, 0 ) );
	}
}
