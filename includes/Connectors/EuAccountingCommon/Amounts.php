<?php
/**
 * Money, percentages and quantities at the edge of the connectors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EuAccountingCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Money;

/**
 * The engine keeps money as integer minor units. The accounting APIs want decimal text or numbers. Conversion happens here and nowhere
 * else, with string and integer arithmetic, so no total is ever decided by a float comparison. Percentages are integer basis points
 * (19 percent is 1900).
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
	 * Integer minor units as a plain decimal string such as "219.00".
	 *
	 * @param int $minor    Amount in minor units.
	 * @param int $exponent Decimal places of the currency.
	 */
	public function decimal( int $minor, int $exponent ): string {
		$sign   = $minor < 0 ? '-' : '';
		$digits = (string) abs( $minor );
		if ( $exponent <= 0 ) {
			return $sign . $digits;
		}
		$digits = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}

	/**
	 * A decimal string as a JSON number. The string is built with integers; only the last step touches a float, and json_encode writes
	 * the shortest text that reads back as the same number.
	 *
	 * @param string $decimal Decimal string from decimal() or unitPrice().
	 * @return int|float
	 */
	public function number( string $decimal ): int|float {
		return str_contains( $decimal, '.' ) ? (float) $decimal : (int) $decimal;
	}

	/**
	 * A decimal (a number or numeric text, any number of decimals) as integer minor units, rounded half away from zero.
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
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', trim( $value ), $parts ) ) {
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
	 * A tax rate in percent (a number or text such as "19" or "5.5%") as basis points.
	 *
	 * @param mixed $percent Percent.
	 * @return int|null Null when it is not a number.
	 */
	public function basisPoints( mixed $percent ): ?int {
		return $this->toMinor( is_string( $percent ) ? trim( rtrim( trim( $percent ), '%' ) ) : $percent, 2 );
	}

	/**
	 * Basis points as percent text: 1900 gives "19", 550 gives "5.5".
	 *
	 * @param int $bp Basis points.
	 */
	public function percent( int $bp ): string {
		$text = $this->decimal( $bp, 2 );
		return str_contains( $text, '.' ) ? rtrim( rtrim( $text, '0' ), '.' ) : $text;
	}

	/**
	 * The tax on a net amount at a rate, rounded half away from zero.
	 *
	 * @param int $net Net amount in minor units.
	 * @param int $bp  Rate in basis points.
	 */
	public function taxOf( int $net, int $bp ): int {
		$rounded = intdiv( abs( $net ) * $bp + 5000, 10000 );
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
		$rounded = intdiv( abs( $gross ) * 10000 + intdiv( $divisor, 2 ), $divisor );
		return $gross < 0 ? -$rounded : $rounded;
	}

	/**
	 * A quantity as text: 3 gives "3", 1.5 gives "1.5", at most four decimals.
	 *
	 * @param float $quantity Quantity.
	 */
	public function quantityText( float $quantity ): string {
		$scaled = (int) round( $quantity * 10000 );
		return $this->trimmed( $scaled, 4 );
	}

	/**
	 * The unit price of a line as a decimal string, when total / quantity can be written with at most `exponent + extra` decimals and
	 * multiplying it back gives exactly the total. Null when it cannot (the caller then sends one line of quantity 1).
	 *
	 * @param int   $totalMinor Line total in minor units (any sign).
	 * @param int   $exponent   Decimal places of the currency.
	 * @param float $quantity   Quantity (at most four decimals are honoured).
	 * @param int   $extra      Decimals allowed beyond the currency's own.
	 */
	public function unitPrice( int $totalMinor, int $exponent, float $quantity, int $extra ): ?string {
		$qs = (int) round( $quantity * 10000 );
		if ( $qs <= 0 ) {
			return null;
		}
		$total = abs( $totalMinor );
		$base  = 10 ** $extra;
		$unit  = intdiv( $total * $base * 20000 + $qs, 2 * $qs );
		$d     = $base * 10000;
		$back  = intdiv( $unit * $qs * 2 + $d, 2 * $d );
		if ( $back !== $total ) {
			return null;
		}
		$text = $this->trimmed( $unit, $exponent + $extra, $exponent );
		return $totalMinor < 0 ? '-' . $text : $text;
	}

	/**
	 * A scaled integer as decimal text, dropping trailing zeros down to a minimum number of decimals.
	 *
	 * @param int $scaled      Value times 10^decimals.
	 * @param int $decimals    Decimals in the scaled value.
	 * @param int $minDecimals Fewest decimals to keep.
	 */
	private function trimmed( int $scaled, int $decimals, int $minDecimals = 0 ): string {
		$text = $this->decimal( $scaled, $decimals );
		if ( ! str_contains( $text, '.' ) ) {
			return $minDecimals > 0 ? $text . '.' . str_repeat( '0', $minDecimals ) : $text;
		}
		list( $whole, $fraction ) = explode( '.', $text, 2 );
		$fraction                 = rtrim( $fraction, '0' );
		$fraction                 = str_pad( $fraction, $minDecimals, '0' );
		return '' === $fraction ? $whole : $whole . '.' . $fraction;
	}
}
