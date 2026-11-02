<?php
/**
 * Money and date conversion at the edge.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Connectors\Odoo\OdooAmounts;
use PHPUnit\Framework\TestCase;

final class OdooAmountsTest extends TestCase {

	public function test_exponent_follows_the_currency(): void {
		$amounts = new OdooAmounts();
		$this->assertSame( 2, $amounts->exponent( 'AED' ) );
		$this->assertSame( 3, $amounts->exponent( 'KWD' ) );
		$this->assertSame( 3, $amounts->exponent( 'bhd' ) );
		$this->assertSame( 0, $amounts->exponent( 'JPY' ) );
		$this->assertSame( 2, $amounts->exponent( '' ) );
	}

	/**
	 * @dataProvider provide_amounts
	 *
	 * @param mixed $odoo     Value from Odoo.
	 * @param int   $exponent Decimals.
	 * @param int   $minor    Expected minor units.
	 */
	public function test_odoo_amounts_become_exact_minor_units( mixed $odoo, int $exponent, int $minor ): void {
		$this->assertSame( $minor, ( new OdooAmounts() )->toMinor( $odoo, $exponent ) );
	}

	/**
	 * @return array<string,array{0:mixed,1:int,2:int}>
	 */
	public static function provide_amounts(): array {
		return array(
			'whole'                   => array( 438.0, 2, 43800 ),
			'binary noise'            => array( 437.95, 2, 43795 ),
			'sum noise'               => array( 0.1 + 0.2, 2, 30 ),
			'three decimals'          => array( 12.345, 3, 12345 ),
			'zero decimals'           => array( 1500.0, 0, 1500 ),
			'integer from odoo'       => array( 12, 2, 1200 ),
			'numeric text'            => array( '19.99', 2, 1999 ),
			'negative'                => array( -25.5, 2, -2550 ),
			'half rounds away'        => array( 1.005, 2, 101 ),
			'false means nothing'     => array( false, 2, 0 ),
			'null means nothing'      => array( null, 2, 0 ),
			'garbage means nothing'   => array( 'abc', 2, 0 ),
			'large'                   => array( 123456789.99, 2, 12345678999 ),
		);
	}

	public function test_minor_units_become_decimals_without_float_drift(): void {
		$amounts = new OdooAmounts();
		$this->assertSame( '219.00', $amounts->toDecimalString( 21900, 2 ) );
		$this->assertSame( '0.05', $amounts->toDecimalString( 5, 2 ) );
		$this->assertSame( '-0.005', $amounts->toDecimalString( -5, 3 ) );
		$this->assertSame( '1500', $amounts->toDecimalString( 1500, 0 ) );
		$this->assertSame( 219.0, $amounts->toDecimal( 21900, 2 ) );
		$this->assertSame( 19.99, $amounts->toDecimal( 1999, 2 ) );
		$this->assertSame( 0.001, $amounts->toDecimal( 1, 3 ) );
	}

	public function test_a_round_trip_never_changes_the_amount(): void {
		$amounts = new OdooAmounts();
		foreach ( array( 1, 5, 99, 1999, 43795, 123456789 ) as $minor ) {
			$this->assertSame( $minor, $amounts->toMinor( $amounts->toDecimal( $minor, 2 ), 2 ) );
			$this->assertSame( $minor, $amounts->toMinor( $amounts->toDecimal( $minor, 3 ), 3 ) );
		}
	}

	public function test_dates_convert_between_iso_and_odoo_text(): void {
		$amounts = new OdooAmounts();
		$this->assertSame( '2026-11-02 07:40:00', $amounts->toOdooDatetime( '2026-11-02T07:40:00Z' ) );
		$this->assertSame( '2026-11-02 07:40:00', $amounts->toOdooDatetime( '2026-11-02 07:40:00' ) );
		$this->assertSame( '2026-11-02 05:40:00', $amounts->toOdooDatetime( '2026-11-02T07:40:00+02:00' ) );
		$this->assertSame( '', $amounts->toOdooDatetime( 'not a date' ) );
		$this->assertSame( '', $amounts->toOdooDatetime( '' ) );
		$this->assertSame( '2026-11-02T07:40:00Z', $amounts->fromOdooDatetime( '2026-11-02 07:40:00' ) );
		$this->assertSame( '', $amounts->fromOdooDatetime( false ) );
		$this->assertSame( '', $amounts->fromOdooDatetime( 'nonsense' ) );
	}
}
