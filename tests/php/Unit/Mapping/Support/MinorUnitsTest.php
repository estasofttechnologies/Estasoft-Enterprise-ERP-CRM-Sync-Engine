<?php
/**
 * Money in minor units: AS-18 (KWD with three decimals, no float error).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Support;

use Estasoft\SyncEngine\Mapping\Support\MinorUnits;
use PHPUnit\Framework\TestCase;

final class MinorUnitsTest extends TestCase {

	/**
	 * @dataProvider exponents
	 */
	public function test_currency_exponents( string $currency, int $expected ): void {
		$this->assertSame( $expected, MinorUnits::exponent( $currency ) );
	}

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function exponents(): array {
		return array(
			'kuwaiti dinar'  => array( 'KWD', 3 ),
			'bahraini dinar' => array( 'BHD', 3 ),
			'omani rial'     => array( 'OMR', 3 ),
			'jordanian dinar' => array( 'JOD', 3 ),
			'tunisian dinar' => array( 'TND', 3 ),
			'yen'            => array( 'JPY', 0 ),
			'won'            => array( 'KRW', 0 ),
			'dirham'         => array( 'AED', 2 ),
			'dollar'         => array( 'USD', 2 ),
			'lower case'     => array( 'kwd', 3 ),
		);
	}

	public function test_kuwaiti_dinar_has_three_decimals_and_no_float_error(): void {
		$this->assertSame( 12345, MinorUnits::parse( '12.345', 3 ) );
		$this->assertSame( 12345, MinorUnits::parse( 12.345, 3 ) );
		$this->assertSame( 1, MinorUnits::parse( '0.001', 3 ) );
		$this->assertSame( 999999999, MinorUnits::parse( '999999.999', 3 ) );
	}

	public function test_classic_float_traps_come_out_exact(): void {
		$this->assertSame( 1999, MinorUnits::parse( 19.99, 2 ) );
		$this->assertSame( 30, MinorUnits::parse( 0.1 + 0.2, 2 ) );
		$this->assertSame( 1005, MinorUnits::parse( '10.05', 2 ) );
		$this->assertSame( 29, MinorUnits::parse( 0.29, 2 ), '0.29 * 100 is 28.999999999999996 in floating point.' );
		$this->assertSame( 57, MinorUnits::parse( 0.57, 2 ), '0.57 * 100 is 56.99999999999999 in floating point.' );
	}

	public function test_whole_numbers_negative_amounts_and_zero_decimal_currencies(): void {
		$this->assertSame( 21900, MinorUnits::parse( 219, 2 ) );
		$this->assertSame( 21900, MinorUnits::parse( '219', 2 ) );
		$this->assertSame( -1250, MinorUnits::parse( '-12.5', 2 ) );
		$this->assertSame( 1500, MinorUnits::parse( '1500', 0 ) );
		$this->assertSame( 0, MinorUnits::parse( '0', 2 ) );
		$this->assertSame( 0, MinorUnits::parse( '0.00', 2 ) );
	}

	public function test_extra_decimals_round_half_away_from_zero(): void {
		$this->assertSame( 101, MinorUnits::parse( '1.005', 2 ) );
		$this->assertSame( 100, MinorUnits::parse( '1.004', 2 ) );
		$this->assertSame( -101, MinorUnits::parse( '-1.005', 2 ) );
		$this->assertSame( 2, MinorUnits::parse( '1.5', 0 ) );
	}

	public function test_thousands_separators_and_arabic_digits(): void {
		$this->assertSame( 123450, MinorUnits::parse( '1,234.50', 2 ) );
		$this->assertSame( 125000, MinorUnits::parse( '1,250', 2 ) );
		$this->assertSame( 1250, MinorUnits::parse( '12,50', 2 ) );
		$this->assertSame( 21950, MinorUnits::parse( '٢١٩٫٥٠', 2 ) );
	}

	public function test_text_that_is_not_a_number_is_null(): void {
		foreach ( array( '', 'abc', '12.3.4', '1e5', null, array(), '--5', 'NaN' ) as $value ) {
			$this->assertNull( MinorUnits::parse( $value, 2 ), var_export( $value, true ) );
		}
		$this->assertNull( MinorUnits::parse( NAN, 2 ) );
		$this->assertNull( MinorUnits::parse( INF, 2 ) );
	}

	public function test_format_writes_exact_decimals(): void {
		$this->assertSame( '219.00', MinorUnits::format( 21900, 2 ) );
		$this->assertSame( '12.345', MinorUnits::format( 12345, 3 ) );
		$this->assertSame( '0.05', MinorUnits::format( 5, 2 ) );
		$this->assertSame( '-0.001', MinorUnits::format( -1, 3 ) );
		$this->assertSame( '1500', MinorUnits::format( 1500, 0 ) );
	}

	public function test_rate_key_is_stable(): void {
		$this->assertSame( '5', MinorUnits::rateKey( 5 ) );
		$this->assertSame( '5', MinorUnits::rateKey( '5.0000' ) );
		$this->assertSame( '5', MinorUnits::rateKey( 5.0 ) );
		$this->assertSame( '7.5', MinorUnits::rateKey( '7.50' ) );
		$this->assertSame( '15', MinorUnits::rateKey( 15 ) );
		$this->assertSame( '0', MinorUnits::rateKey( 0 ) );
		$this->assertSame( '0', MinorUnits::rateKey( '0.00' ) );
		$this->assertSame( '8.875', MinorUnits::rateKey( '8.875' ) );
		$this->assertNull( MinorUnits::rateKey( 'five' ) );
	}
}
