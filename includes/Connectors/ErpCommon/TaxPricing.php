<?php
/**
 * Whole-number tax arithmetic for prices.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

/**
 * A price either holds its tax or does not. When the shop and the ERP disagree about that for a line, the price is converted here with
 * integer arithmetic: minor units, rates in ten-thousandths of a percent (5 % is 50000), rounding half away from zero. No float is ever
 * compared.
 */
final class TaxPricing {

	/**
	 * Largest amount the integer arithmetic handles without overflow (minor units).
	 */
	public const MAX_MINOR = 900000000000;

	/**
	 * A percentage text such as "5", "5.00", "12.5" or "7.2500" to ten-thousandths of a percent.
	 *
	 * @param mixed $percent Number or numeric text.
	 * @return int|null Null when the value is not a plain non-negative percentage.
	 */
	public static function rate( mixed $percent ): ?int {
		if ( is_int( $percent ) || is_float( $percent ) ) {
			$percent = rtrim( rtrim( number_format( (float) $percent, 4, '.', '' ), '0' ), '.' );
		}
		if ( ! is_string( $percent ) || 1 !== preg_match( '/^(\d{1,3})(?:\.(\d{1,4}))?$/', trim( $percent ), $match ) ) {
			return null;
		}
		$fraction = str_pad( $match[2] ?? '', 4, '0' );
		return ( (int) $match[1] ) * 10000 + (int) $fraction;
	}

	/**
	 * Add a percentage to an amount.
	 *
	 * @param int $minor Amount in minor units without tax.
	 * @param int $rate  Percentage in ten-thousandths of a percent.
	 */
	public static function withTax( int $minor, int $rate ): int {
		$sign = $minor < 0 ? -1 : 1;
		$abs  = abs( $minor );
		return $sign * ( $abs + intdiv( $abs * $rate + 500000, 1000000 ) );
	}

	/**
	 * Take a percentage out of an amount that includes it.
	 *
	 * @param int $minor Amount in minor units with tax.
	 * @param int $rate  Percentage in ten-thousandths of a percent.
	 */
	public static function withoutTax( int $minor, int $rate ): int {
		$sign        = $minor < 0 ? -1 : 1;
		$abs         = abs( $minor );
		$denominator = 1000000 + $rate;
		return $sign * intdiv( $abs * 1000000 + intdiv( $denominator, 2 ), $denominator );
	}

	/**
	 * Convert a price from one reading to the other.
	 *
	 * @param int  $minor        Price in minor units as the payload gives it.
	 * @param int  $rate         Tax percentage in ten-thousandths of a percent.
	 * @param bool $payloadHolds True when the payload's price includes the tax.
	 * @param bool $systemHolds  True when the system reads the price as including the tax.
	 */
	public static function convert( int $minor, int $rate, bool $payloadHolds, bool $systemHolds ): int {
		if ( $payloadHolds === $systemHolds || 0 === $rate || 0 === $minor || abs( $minor ) > self::MAX_MINOR ) {
			return $minor;
		}
		return $payloadHolds ? self::withoutTax( $minor, $rate ) : self::withTax( $minor, $rate );
	}
}
