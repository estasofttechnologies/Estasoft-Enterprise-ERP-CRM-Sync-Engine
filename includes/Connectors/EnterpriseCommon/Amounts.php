<?php
/**
 * Money and quantity conversion at the edge of the ERP connectors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Money;

/**
 * The engine keeps money as integer minor units. The ERP systems send and expect decimal numbers. Conversion happens here and nowhere
 * else, with string arithmetic and the currency's exponent (KWD, BHD and OMR have three decimals, JPY none), so no total is ever decided
 * by a float comparison. Amounts that arrive as JSON floats are first written out as text with eight decimals, then rounded half away from zero.
 */
final class Amounts {

	/**
	 * Decimal places of a currency's minor unit.
	 *
	 * @param string $currency ISO 4217 code; empty gives 2.
	 */
	public function exponent( string $currency ): int {
		return Money::exponent( strtoupper( trim( $currency ) ) );
	}

	/**
	 * Minor units as a decimal string for the wire.
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO code.
	 */
	public function toDecimal( int $minor, string $currency ): string {
		return Money::toDecimalString( $minor, strtoupper( trim( $currency ) ) );
	}

	/**
	 * Minor units as a JSON number kept exact: a decimal string is turned into an int or a float only by the caller's choice. This returns the
	 * text; callers that need a JSON number use toNumber().
	 *
	 * @param int    $minor    Minor units.
	 * @param string $currency ISO code.
	 * @return int|float A whole number for a currency without decimals, else a float with the exact decimal value.
	 */
	public function toNumber( int $minor, string $currency ): int|float {
		$text = $this->toDecimal( $minor, $currency );
		return false === strpos( $text, '.' ) ? (int) $text : (float) $text;
	}

	/**
	 * Minor units from a number or decimal text the system sent.
	 *
	 * @param mixed  $value    An int, a float or a decimal string.
	 * @param string $currency ISO code.
	 * @return int|null Null when the value is not a number.
	 */
	public function fromWire( mixed $value, string $currency ): ?int {
		if ( is_int( $value ) ) {
			$text = (string) $value;
		} elseif ( is_float( $value ) && is_finite( $value ) ) {
			$text = sprintf( '%.8F', $value );
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\s*-?\d+(\.\d+)?\s*$/', $value ) ) {
			$text = trim( $value );
		} else {
			return null;
		}
		return $this->parse( $text, $this->exponent( $currency ) );
	}

	/**
	 * Whether two amounts agree to within a tolerance.
	 *
	 * @param int $a         Amount in minor units.
	 * @param int $b         Amount in minor units.
	 * @param int $tolerance Largest accepted difference (one minor unit is rounding).
	 */
	public function agree( int $a, int $b, int $tolerance = 1 ): bool {
		return abs( $a - $b ) <= $tolerance;
	}

	/**
	 * Quantity times a unit price, in minor units, rounded half away from zero (six decimals of quantity are honoured).
	 *
	 * @param int              $unitMinor Unit price in minor units.
	 * @param int|float|string $quantity  Quantity.
	 */
	public function lineTotal( int $unitMinor, int|float|string $quantity ): int {
		$scaled  = $this->parse( is_float( $quantity ) ? sprintf( '%.6F', $quantity ) : trim( (string) $quantity ), 6 );
		$product = $unitMinor * $scaled;
		$sign    = $product < 0 ? -1 : 1;
		return $sign * intdiv( abs( $product ) + 500000, 1000000 );
	}

	/**
	 * A line total divided by a quantity, in minor units, rounded half away from zero (the unit price of a refunded line).
	 *
	 * @param int              $totalMinor Line total in minor units.
	 * @param int|float|string $quantity   Quantity.
	 */
	public function unitPrice( int $totalMinor, int|float|string $quantity ): int {
		$scaled = $this->parse( is_float( $quantity ) ? sprintf( '%.6F', $quantity ) : trim( (string) $quantity ), 6 );
		if ( $scaled <= 0 ) {
			return $totalMinor;
		}
		$sign = $totalMinor < 0 ? -1 : 1;
		return $sign * intdiv( abs( $totalMinor ) * 1000000 + intdiv( $scaled, 2 ), $scaled );
	}

	/**
	 * A quantity as plain decimal text without trailing zeros ("2", "1.5").
	 *
	 * @param int|float|string $quantity Quantity.
	 */
	public function quantityText( int|float|string $quantity ): string {
		$text = is_float( $quantity ) ? sprintf( '%.6F', $quantity ) : trim( (string) $quantity );
		if ( false !== strpos( $text, '.' ) ) {
			$text = rtrim( rtrim( $text, '0' ), '.' );
		}
		return '' === $text ? '0' : $text;
	}

	/**
	 * Parse decimal text into an integer with a number of decimals, rounding half away from zero.
	 *
	 * @param string $text     Text such as "-12.345".
	 * @param int    $exponent Wanted decimals.
	 */
	private function parse( string $text, int $exponent ): int {
		$negative = str_starts_with( $text, '-' );
		$text     = ltrim( $text, '-' );
		$parts    = explode( '.', $text, 2 );
		$whole    = ltrim( $parts[0], '0' );
		$fraction = $parts[1] ?? '';
		$kept     = str_pad( substr( $fraction, 0, $exponent ), $exponent, '0' );
		$minor    = (int) ( ( '' === $whole ? '0' : $whole ) . $kept );
		if ( $exponent < strlen( $fraction ) && (int) $fraction[ $exponent ] >= 5 ) {
			++$minor;
		}
		return $negative ? -$minor : $minor;
	}
}
