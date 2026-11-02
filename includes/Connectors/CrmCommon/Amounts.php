<?php
/**
 * Money at the edge of a connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\CrmCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Integer minor units inside the plugin; decimals only where a system wants them. No arithmetic on floats.
 */
final class Amounts {

	/**
	 * Largest difference, in minor units, that counts as rounding.
	 */
	public const TOLERANCE = 1;

	/**
	 * The exact decimal text of an amount, by the currency's exponent.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO currency code.
	 */
	public static function decimal( int $minor, string $currency ): string {
		return Money::toDecimalString( $minor, $currency );
	}

	/**
	 * A JSON number built from the exact decimal text (an integer for currencies without decimals).
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO currency code.
	 */
	public static function number( int $minor, string $currency ): int|float {
		$text = self::decimal( $minor, $currency );
		return str_contains( $text, '.' ) ? (float) $text : (int) $text;
	}

	/**
	 * Minor units from a number or numeric text a system returned; null when it is not a number.
	 *
	 * @param mixed  $value    Number, numeric string or null.
	 * @param string $currency ISO currency code.
	 */
	public static function minor( mixed $value, string $currency ): ?int {
		if ( is_int( $value ) ) {
			$text = (string) $value;
		} elseif ( is_float( $value ) ) {
			$text = rtrim( rtrim( number_format( $value, 8, '.', '' ), '0' ), '.' );
		} elseif ( is_string( $value ) ) {
			$text = trim( $value );
		} else {
			return null;
		}
		if ( '' === $text || 1 !== preg_match( '/^-?\d+(\.\d+)?$/', $text ) ) {
			return null;
		}
		try {
			return Money::fromDecimalString( $text, $currency );
		} catch ( \InvalidArgumentException $exception ) {
			unset( $exception );
			return null;
		}
	}

	/**
	 * Whether two amounts agree within rounding.
	 *
	 * @param int $expected Expected minor units.
	 * @param int $actual   Actual minor units.
	 */
	public static function agree( int $expected, int $actual ): bool {
		return abs( $expected - $actual ) <= self::TOLERANCE;
	}
}
