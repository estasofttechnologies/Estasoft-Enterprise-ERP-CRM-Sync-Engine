<?php
/**
 * Money in minor units.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Support;

use Estasoft\SyncEngine\Core\Support\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase {

	/**
	 * @dataProvider exponents
	 */
	public function test_currency_exponents( string $currency, int $expected ): void {
		$this->assertSame( $expected, Money::exponent( $currency ) );
	}

	/**
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function exponents(): array {
		return array(
			'dirham'    => array( 'AED', 2 ),
			'dollar'    => array( 'USD', 2 ),
			'kuwaiti'   => array( 'KWD', 3 ),
			'bahraini'  => array( 'BHD', 3 ),
			'omani'     => array( 'OMR', 3 ),
			'jordanian' => array( 'JOD', 3 ),
			'tunisian'  => array( 'TND', 3 ),
			'yen'       => array( 'JPY', 0 ),
			'won'       => array( 'KRW', 0 ),
			'lowercase' => array( 'kwd', 3 ),
		);
	}

	public function test_decimal_string_to_minor_units_has_no_float_error(): void {
		$this->assertSame( 43800, Money::fromDecimalString( '438.00', 'AED' ) );
		$this->assertSame( 1005, Money::fromDecimalString( '10.05', 'USD' ) );
		$this->assertSame( 29, Money::fromDecimalString( '0.29', 'USD' ) );
		$this->assertSame( 12345, Money::fromDecimalString( '12.345', 'KWD' ) );
		$this->assertSame( 500, Money::fromDecimalString( '500', 'JPY' ) );
		$this->assertSame( -250, Money::fromDecimalString( '-2.5', 'USD' ) );
	}

	public function test_too_many_places_for_the_currency_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::fromDecimalString( '10.005', 'USD' );
	}

	public function test_not_a_number_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::fromDecimalString( '12,50', 'USD' );
	}

	public function test_minor_units_back_to_decimal_string(): void {
		$this->assertSame( '438.00', Money::toDecimalString( 43800, 'AED' ) );
		$this->assertSame( '0.05', Money::toDecimalString( 5, 'USD' ) );
		$this->assertSame( '12.345', Money::toDecimalString( 12345, 'KWD' ) );
		$this->assertSame( '-2.50', Money::toDecimalString( -250, 'USD' ) );
		$this->assertSame( '500', Money::toDecimalString( 500, 'JPY' ) );
	}

	public function test_rescale_rounds_half_away_from_zero(): void {
		$this->assertSame( 12340, Money::rescale( 1234, 2, 3 ) );
		$this->assertSame( 1235, Money::rescale( 12345, 3, 2 ) );
		$this->assertSame( -1235, Money::rescale( -12345, 3, 2 ) );
		$this->assertSame( 1234, Money::rescale( 12344, 3, 2 ) );
		$this->assertSame( 500, Money::rescale( 500, 2, 2 ) );
	}
}
