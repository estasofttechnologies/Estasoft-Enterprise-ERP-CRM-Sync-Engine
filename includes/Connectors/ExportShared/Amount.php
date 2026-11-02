<?php
/**
 * Money, quantity and percentage text for export files.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Money;

/**
 * Money is integer minor units until the moment it is written; this class does that last step and the few sums files need
 * (a gross amount split into net and tax, a unit price). Everything is integer arithmetic. There are no floats in any amount.
 */
final class Amount {

	/**
	 * A tax rate is held in millionths of a whole (7.5 percent is 75000), so four decimals of a percent are exact.
	 */
	private const RATE_SCALE = 1000000;

	/**
	 * Minor units as a decimal string.
	 *
	 * @param int    $minor    Amount in minor units.
	 * @param string $currency ISO code (sets the number of decimals).
	 * @param string $point    Decimal separator, "." or ",".
	 */
	public static function decimal( int $minor, string $currency, string $point = '.' ): string {
		return str_replace( '.', $point, Money::toDecimalString( $minor, $currency ) );
	}

	/**
	 * A whole-number minor-unit amount from a payload value.
	 *
	 * @param mixed $value Integer, integral float or digit string.
	 * @return int|null Null when the value is empty or not a whole number.
	 */
	public static function minor( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value ) {
			return (int) $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\s*(-?\d{1,18})\s*$/', $value, $found ) ) {
			return (int) $found[1];
		}
		return null;
	}

	/**
	 * A percentage in millionths, or null when the value is empty or not a number ("7.5" gives 75000).
	 *
	 * @param mixed $value Integer, float or numeric string in percent.
	 */
	public static function rate( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value * 10000;
		}
		$text = is_float( $value ) && is_finite( $value ) ? sprintf( '%.4F', $value ) : ( is_string( $value ) ? trim( $value ) : '' );
		if ( 1 !== preg_match( '/^(-?)(\d{1,6})(?:[.,](\d{0,6}))?$/', $text, $found ) ) {
			return null;
		}
		$fraction = substr( str_pad( $found[3] ?? '', 4, '0' ), 0, 4 );
		$scaled   = ( (int) $found[2] * 10000 ) + (int) $fraction;
		return '-' === $found[1] ? -$scaled : $scaled;
	}

	/**
	 * A percentage as text without trailing zeros ("5", "7.5").
	 *
	 * @param int $rate Millionths from rate().
	 */
	public static function rateText( int $rate ): string {
		$whole    = intdiv( abs( $rate ), 10000 );
		$fraction = rtrim( str_pad( (string) ( abs( $rate ) % 10000 ), 4, '0', STR_PAD_LEFT ), '0' );
		return ( $rate < 0 ? '-' : '' ) . $whole . ( '' === $fraction ? '' : '.' . $fraction );
	}

	/**
	 * Split a gross amount into net and tax for a rate, rounding the net half away from zero.
	 *
	 * @param int $gross Amount with tax, minor units.
	 * @param int $rate  Rate in millionths from rate().
	 * @return array{net:int,tax:int}
	 */
	public static function split( int $gross, int $rate ): array {
		if ( $rate <= 0 || 0 === $gross ) {
			return array(
				'net' => $gross,
				'tax' => 0,
			);
		}
		$sign   = $gross < 0 ? -1 : 1;
		$abs    = abs( $gross );
		$base   = self::RATE_SCALE + $rate;
		$net    = intdiv( ( 2 * $abs * self::RATE_SCALE ) + $base, 2 * $base );
		$result = array(
			'net' => $sign * $net,
			'tax' => $sign * ( $abs - $net ),
		);
		return $result;
	}

	/**
	 * A rate worked out from two amounts, in percent with at most two decimals, or an empty string when either is not above zero.
	 *
	 * @param int $net Net amount, minor units.
	 * @param int $tax Tax amount, minor units.
	 */
	public static function observedRate( int $net, int $tax ): string {
		if ( $net <= 0 || $tax <= 0 ) {
			return '';
		}
		return self::rateText( intdiv( ( 2 * $tax * 10000 ) + $net, 2 * $net ) * 100 );
	}

	/**
	 * A quantity as a decimal string with at most four decimals, or null when it is not a positive number.
	 *
	 * @param mixed $value Integer, float or numeric string.
	 */
	public static function quantity( mixed $value ): ?string {
		if ( is_int( $value ) ) {
			return $value > 0 ? (string) $value : null;
		}
		if ( is_float( $value ) && is_finite( $value ) ) {
			$text = sprintf( '%.4F', $value );
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\s*\d+(?:\.\d+)?\s*$/', $value ) ) {
			$text = sprintf( '%.4F', (float) trim( $value ) );
		} else {
			return null;
		}
		$text = rtrim( rtrim( $text, '0' ), '.' );
		return '' === $text || '0' === $text ? null : $text;
	}

	/**
	 * A quantity in ten-thousandths (1.5 is 15000).
	 *
	 * @param string $quantity From quantity().
	 */
	public static function quantityUnits( string $quantity ): int {
		$parts    = explode( '.', $quantity );
		$fraction = substr( str_pad( $parts[1] ?? '', 4, '0' ), 0, 4 );
		return ( (int) $parts[0] * 10000 ) + (int) $fraction;
	}

	/**
	 * The price of one unit written for a line: the amount divided by the quantity with two decimals more than the currency has,
	 * trailing zeros removed down to the currency's own number of decimals.
	 *
	 * @param int    $minor    Line amount in minor units.
	 * @param string $quantity Quantity from quantity().
	 * @param string $currency ISO code.
	 * @param string $point    Decimal separator.
	 */
	public static function unitPrice( int $minor, string $quantity, string $currency, string $point = '.' ): string {
		$exponent = Money::exponent( $currency );
		$units    = max( 1, self::quantityUnits( $quantity ) );
		$extra    = 2;
		$sign     = $minor < 0 ? -1 : 1;
		$scaled   = intdiv( ( 2 * abs( $minor ) * 10000 * ( 10 ** $extra ) ) + $units, 2 * $units );
		$digits   = str_pad( (string) $scaled, $exponent + $extra + 1, '0', STR_PAD_LEFT );
		$whole    = substr( $digits, 0, -( $exponent + $extra ) );
		$fraction = substr( $digits, -( $exponent + $extra ) );
		$fraction = rtrim( $fraction, '0' );
		$fraction = str_pad( $fraction, $exponent, '0' );
		$text     = ( $sign < 0 && $scaled > 0 ? '-' : '' ) . $whole . ( '' === $fraction ? '' : $point . $fraction );
		return $text;
	}

	/**
	 * A line amount from a price and a quantity: price times quantity, rounded half up.
	 *
	 * @param int    $unit     Unit price in minor units.
	 * @param string $quantity Quantity from quantity().
	 */
	public static function times( int $unit, string $quantity ): int {
		$product = $unit * self::quantityUnits( $quantity );
		$sign    = $product < 0 ? -1 : 1;
		return $sign * intdiv( ( 2 * abs( $product ) ) + 10000, 20000 );
	}
}
