<?php
/**
 * VAT arithmetic in whole numbers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Exact;

defined( 'ABSPATH' ) || exit;

/**
 * Exact keeps a VAT percentage as a fraction (0.21 for 21 percent). Rates are held here in basis points (2100) and amounts in minor
 * units, so no float arithmetic touches money.
 */
final class ExactTax {

	/**
	 * A fraction such as 0.21 (a number or text) as basis points; null when it is not a number or has more than four decimals.
	 *
	 * @param mixed $fraction Value from Exact.
	 */
	public static function basisPoints( mixed $fraction ): ?int {
		if ( is_int( $fraction ) ) {
			$text = (string) $fraction;
		} elseif ( is_float( $fraction ) ) {
			$text = rtrim( rtrim( number_format( $fraction, 6, '.', '' ), '0' ), '.' );
		} elseif ( is_string( $fraction ) ) {
			$text = trim( $fraction );
		} else {
			return null;
		}
		if ( 1 !== preg_match( '/^(\d+)(?:\.(\d+))?$/', $text, $parts ) ) {
			return null;
		}
		$decimals = rtrim( $parts[2] ?? '', '0' );
		if ( strlen( $decimals ) > 4 ) {
			return null;
		}
		return ( (int) $parts[1] * 10000 ) + (int) str_pad( $decimals, 4, '0' );
	}

	/**
	 * The percentage as a decimal string for the Tax mapping screen: 0.21 becomes "21", 0.065 becomes "6.5".
	 *
	 * @param mixed $fraction Value from Exact.
	 */
	public static function percentText( mixed $fraction ): string {
		$points = self::basisPoints( $fraction );
		if ( null === $points ) {
			return '0';
		}
		$whole = intdiv( $points, 100 );
		$rest  = $points % 100;
		return 0 === $rest ? (string) $whole : $whole . '.' . rtrim( str_pad( (string) $rest, 2, '0', STR_PAD_LEFT ), '0' );
	}

	/**
	 * Net amount from a gross amount: gross / (1 + rate), rounded half away from zero.
	 *
	 * @param int $grossMinor  Gross amount, minor units.
	 * @param int $basisPoints Rate in basis points.
	 */
	public static function net( int $grossMinor, int $basisPoints ): int {
		if ( 0 === $basisPoints ) {
			return $grossMinor;
		}
		$divisor = 10000 + $basisPoints;
		$scaled  = abs( $grossMinor ) * 10000;
		$value   = intdiv( $scaled + intdiv( $divisor, 2 ), $divisor );
		return $grossMinor < 0 ? -$value : $value;
	}
}
