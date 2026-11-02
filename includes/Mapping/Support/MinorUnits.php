<?php
/**
 * Money in integer minor units.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Mapping\Support;

use Estasoft\SyncEngine\Core\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Reads decimal amounts written as text (rounding half away from zero, accepting thousands separators and Arabic digits)
 * into integer minor units (fils, cents, yen). Only integer arithmetic is used, so 19.99 is exactly 1999 and 12.345 KWD is
 * exactly 12345 fils. Core\Support\Money is the strict counterpart used for amounts the plugin writes itself.
 */
final class MinorUnits {

	/**
	 * Number of decimal places of a currency, from the one table the whole plugin uses (3 for KWD, BHD, OMR, JOD and TND,
	 * 0 for JPY and KRW, otherwise 2).
	 *
	 * @param string $currency ISO 4217 code, any case.
	 */
	public static function exponent( string $currency ): int {
		return Money::exponent( trim( $currency ) );
	}

	/**
	 * Parse an amount written in major units ("219", "219.5", "-12.345", "1,234.50", "٢١٩٫٥٠") into minor units.
	 * More decimals than the exponent are rounded half away from zero. Returns null for text that is not a number.
	 *
	 * @param mixed $amount   Integer, float or numeric string in major units.
	 * @param int   $exponent Decimal places of the currency.
	 */
	public static function parse( mixed $amount, int $exponent ): ?int {
		$text = self::normalise( $amount );
		if ( null === $text || ! preg_match( '/^([+-]?)(\d+)(?:\.(\d*))?$/', $text, $parts ) ) {
			return null;
		}
		$negative = '-' === $parts[1];
		$whole    = ltrim( $parts[2], '0' );
		$fraction = $parts[3] ?? '';

		$kept   = substr( str_pad( $fraction, $exponent, '0' ), 0, $exponent );
		$next   = strlen( $fraction ) > $exponent ? (int) $fraction[ $exponent ] : 0;
		$digits = ltrim( $whole . $kept, '0' );
		$minor  = '' === $digits ? 0 : (int) $digits;
		$minor += $next >= 5 ? 1 : 0;

		return $negative ? -$minor : $minor;
	}

	/**
	 * Write minor units as a decimal string with exactly the currency's number of decimals ("21900" with exponent 2 is "219.00").
	 *
	 * @param int $minor    Amount in minor units.
	 * @param int $exponent Decimal places of the currency.
	 */
	public static function format( int $minor, int $exponent ): string {
		$sign   = $minor < 0 ? '-' : '';
		$digits = (string) abs( $minor );
		if ( 0 === $exponent ) {
			return $sign . $digits;
		}
		$digits = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}

	/**
	 * A tax rate in percent as a stable text key: "5", "7.5", "15". Up to four decimals are kept.
	 * Returns null when the value is not a number.
	 *
	 * @param mixed $rate Integer, float or numeric string.
	 */
	public static function rateKey( mixed $rate ): ?string {
		$basis = self::parse( $rate, 4 );
		if ( null === $basis ) {
			return null;
		}
		$text = self::format( abs( $basis ), 4 );
		$text = rtrim( rtrim( $text, '0' ), '.' );
		return ( $basis < 0 ? '-' : '' ) . ( '' === $text ? '0' : $text );
	}

	/**
	 * Reduce the accepted spellings of a number to "[+-]digits[.digits]", or null when it is not a number.
	 *
	 * @param mixed $amount Value to normalise.
	 */
	private static function normalise( mixed $amount ): ?string {
		if ( is_int( $amount ) ) {
			return (string) $amount;
		}
		if ( is_float( $amount ) ) {
			if ( ! is_finite( $amount ) ) {
				return null;
			}
			return sprintf( '%.10F', $amount );
		}
		if ( ! is_string( $amount ) ) {
			return null;
		}
		$text = Text::asciiDigits( Text::trim( $amount ) );
		$text = str_replace( array( ' ', "\u{00A0}", '٬', '’', "'" ), '', $text );
		if ( '' === $text ) {
			return null;
		}
		if ( false !== strpos( $text, '.' ) ) {
			$text = str_replace( ',', '', $text );
		} elseif ( preg_match( '/^[+-]?\d{1,3}(,\d{3})+$/', $text ) ) {
			$text = str_replace( ',', '', $text );
		} elseif ( preg_match( '/^[+-]?\d+,\d{1,2}$/', $text ) ) {
			$text = str_replace( ',', '.', $text );
		}
		return $text;
	}
}
