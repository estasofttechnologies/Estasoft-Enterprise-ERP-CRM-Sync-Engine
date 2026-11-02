<?php
/**
 * Money between the plugin's integer minor units and the decimals Microsoft services use.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Inside the plugin an amount is an integer in the currency's minor unit. At the edge it becomes the decimal number a Microsoft service
 * expects (a JSON number) using the currency's exponent: 2 for most currencies, 3 for KWD, BHD, OMR, 0 for JPY. The conversion goes through
 * a decimal string, never through float arithmetic. Numbers read back are rounded at the currency's precision before they become integers.
 */
final class Amounts {

	/**
	 * Decimal places of the minor unit.
	 *
	 * @param string $currency ISO 4217 code.
	 */
	public static function exponent( string $currency ): int {
		return Money::exponent( $currency );
	}

	/**
	 * The decimal number for a JSON body: 12345 KWD minor units give 12.345.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO 4217 code.
	 */
	public static function decimal( int $minor, string $currency ): float {
		return (float) Money::toDecimalString( $minor, $currency );
	}

	/**
	 * The plain decimal text, for messages and comparisons: "37.035".
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO 4217 code.
	 */
	public static function text( int $minor, string $currency ): string {
		return Money::toDecimalString( $minor, $currency );
	}

	/**
	 * Minor units from a number or numeric text read from the service. Anything that is not a number gives 0.
	 *
	 * @param mixed  $value    Number, numeric text or null.
	 * @param string $currency ISO 4217 code.
	 */
	public static function minor( mixed $value, string $currency ): int {
		$exponent = self::exponent( $currency );
		if ( is_int( $value ) ) {
			return $value * ( 10 ** $exponent );
		}
		if ( is_string( $value ) && 1 === preg_match( '/^-?\d+(\.\d+)?$/', trim( $value ) ) ) {
			$value = (float) trim( $value );
		}
		if ( ! is_float( $value ) ) {
			return 0;
		}
		// number_format() rounds at the requested precision, which removes binary noise such as 437.9499999.
		return Money::fromDecimalString( number_format( $value, $exponent, '.', '' ), $currency );
	}

	/**
	 * Whether two amounts are the same within a number of minor units.
	 *
	 * @param int $a         First amount.
	 * @param int $b         Second amount.
	 * @param int $tolerance Allowed difference in minor units.
	 */
	public static function near( int $a, int $b, int $tolerance = 1 ): bool {
		return abs( $a - $b ) <= $tolerance;
	}
}
