<?php
/**
 * Money, percentages and quantities at the edge of the Holded connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Money;

/**
 * The engine keeps money as integer minor units. Holded sends and accepts decimal numbers. Conversion happens here and nowhere else, with
 * string and integer arithmetic, so no total is ever decided by a float comparison. Percentages are integer basis points (15 percent is 1500).
 */
final class HoldedAmounts {

	/**
	 * Decimal places of a currency's minor unit.
	 *
	 * @param string $currency ISO 4217 code; empty gives 2.
	 */
	public function exponent( string $currency ): int {
		return Money::exponent( strtoupper( trim( $currency ) ) );
	}

	/**
	 * A minor-unit amount as the JSON number Holded expects. The decimal string is built with integers; only its final conversion to a number
	 * touches a float, and json_encode writes the shortest text that reads back as the same number.
	 *
	 * @param int $minor    Amount in minor units.
	 * @param int $exponent Decimal places of the currency.
	 * @return int|float
	 */
	public function number( int $minor, int $exponent ): int|float {
		$text = $this->decimalString( $minor, $exponent );
		return 0 === $exponent ? (int) $text : (float) $text;
	}

	/**
	 * Integer minor units as a plain decimal string such as "219.00".
	 *
	 * @param int $minor    Amount in minor units.
	 * @param int $exponent Decimal places of the currency.
	 */
	public function decimalString( int $minor, int $exponent ): string {
		$sign   = $minor < 0 ? '-' : '';
		$digits = (string) abs( $minor );
		if ( $exponent <= 0 ) {
			return $sign . $digits;
		}
		$digits = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}

	/**
	 * Holded's decimal (a number or a numeric string, any number of decimals) as integer minor units, rounded half away from zero.
	 *
	 * @param mixed $value    Number or numeric text.
	 * @param int   $exponent Decimal places of the currency.
	 * @return int|null Null when the value is not a number.
	 */
	public function toMinor( mixed $value, int $exponent ): ?int {
		if ( is_int( $value ) ) {
			return $value * ( 10 ** $exponent );
		}
		if ( is_float( $value ) ) {
			$value = number_format( $value, max( 0, $exponent ) + 4, '.', '' );
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', trim( $value ), $parts ) ) {
			return null;
		}
		$fraction = $parts[3] ?? '';
		$kept     = str_pad( substr( $fraction, 0, $exponent ), $exponent, '0' );
		$minor    = (int) ( $parts[2] . $kept );
		if ( strlen( $fraction ) > $exponent && (int) $fraction[ $exponent ] >= 5 ) {
			++$minor;
		}
		return '-' === $parts[1] ? -$minor : $minor;
	}

	/**
	 * A tax rate in percent (a number or text such as "15" or "5.5") as basis points.
	 *
	 * @param mixed $percent Percent.
	 * @return int|null Null when it is not a number.
	 */
	public function basisPoints( mixed $percent ): ?int {
		$minor = $this->toMinor( is_string( $percent ) ? trim( rtrim( $percent, '%' ) ) : $percent, 2 );
		return $minor;
	}

	/**
	 * The tax on a net amount at a rate, rounded half away from zero.
	 *
	 * @param int $net Net amount in minor units.
	 * @param int $bp  Rate in basis points.
	 */
	public function taxOf( int $net, int $bp ): int {
		$product = abs( $net ) * $bp;
		$rounded = intdiv( $product + 5000, 10000 );
		return $net < 0 ? -$rounded : $rounded;
	}

	/**
	 * The net amount inside a gross amount at a rate, rounded half away from zero.
	 *
	 * @param int $gross Gross amount in minor units.
	 * @param int $bp    Rate in basis points.
	 */
	public function netFromGross( int $gross, int $bp ): int {
		$divisor = 10000 + $bp;
		$value   = abs( $gross ) * 10000;
		$rounded = intdiv( $value + intdiv( $divisor, 2 ), $divisor );
		return $gross < 0 ? -$rounded : $rounded;
	}
}
