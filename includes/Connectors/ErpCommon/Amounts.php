<?php
/**
 * Money and date conversion at the edge of an ERP connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ErpCommon;

defined( 'ABSPATH' ) || exit;

/**
 * The engine keeps money as integer minor units. ERP systems send and expect decimal numbers. Conversion happens here and nowhere
 * else, with string arithmetic, so no total is ever decided by a float comparison. Shared by the ERPNext, Acumatica and Sage connectors.
 */
final class Amounts {

	/**
	 * Currencies whose minor unit is not 1/100 (the same table as the engine's own money helper).
	 *
	 * @var array<string,int>
	 */
	private const EXPONENTS = array(
		'KWD' => 3,
		'BHD' => 3,
		'OMR' => 3,
		'JOD' => 3,
		'TND' => 3,
		'LYD' => 3,
		'IQD' => 3,
		'JPY' => 0,
		'KRW' => 0,
		'VND' => 0,
		'CLP' => 0,
		'ISK' => 0,
		'UGX' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'KMF' => 0,
		'GNF' => 0,
		'DJF' => 0,
		'BIF' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
	);

	/**
	 * Decimal places of a currency's minor unit.
	 *
	 * @param string $currency ISO 4217 code; empty gives 2.
	 */
	public function exponent( string $currency ): int {
		return self::EXPONENTS[ strtoupper( trim( $currency ) ) ] ?? 2;
	}

	/**
	 * A decimal amount from the ERP to integer minor units (rounds half away from zero at the currency's precision).
	 *
	 * @param mixed $amount   Number or numeric text.
	 * @param int   $exponent Decimal places of the currency.
	 */
	public function toMinor( mixed $amount, int $exponent ): int {
		if ( is_int( $amount ) ) {
			return $amount * ( 10 ** $exponent );
		}
		if ( is_string( $amount ) && 1 === preg_match( '/^-?\d+(\.\d+)?$/', trim( $amount ) ) ) {
			$amount = (float) trim( $amount );
		}
		if ( ! is_float( $amount ) ) {
			return 0;
		}
		// number_format() rounds at the requested precision, which removes binary noise such as 437.9499999.
		$text     = number_format( $amount, $exponent, '.', '' );
		$negative = str_starts_with( $text, '-' );
		$digits   = str_replace( array( '-', '.' ), '', $text );
		$minor    = (int) $digits;
		return $negative ? -$minor : $minor;
	}

	/**
	 * Integer minor units to the number an ERP expects.
	 *
	 * @param int $minor    Amount in minor units.
	 * @param int $exponent Decimal places of the currency.
	 */
	public function toDecimal( int $minor, int $exponent ): float {
		return (float) $this->toDecimalString( $minor, $exponent );
	}

	/**
	 * Integer minor units to a plain decimal string such as "219.00".
	 *
	 * @param int $minor    Amount in minor units.
	 * @param int $exponent Decimal places of the currency.
	 */
	public function toDecimalString( int $minor, int $exponent ): string {
		$sign   = $minor < 0 ? '-' : '';
		$digits = (string) abs( $minor );
		if ( 0 === $exponent ) {
			return $sign . $digits;
		}
		$digits = str_pad( $digits, $exponent + 1, '0', STR_PAD_LEFT );
		return $sign . substr( $digits, 0, -$exponent ) . '.' . substr( $digits, -$exponent );
	}

	/**
	 * The unit price of a line: the line amount spread over the quantity, in minor units, rounded half away from zero.
	 *
	 * @param int   $minor    Amount of the whole line in minor units.
	 * @param float $quantity Quantity (above zero).
	 */
	public function perUnit( int $minor, float $quantity ): int {
		if ( $quantity <= 0.0 ) {
			return $minor;
		}
		$value = $minor / $quantity;
		return (int) ( $value < 0 ? ceil( $value - 0.5 ) : floor( $value + 0.5 ) );
	}

	/**
	 * The date part (Y-m-d) of a date text; the mapping already converts to the shop's day when it should.
	 *
	 * @param string $value Date or date-time text.
	 * @return string Empty when the text is not a date.
	 */
	public function dateOnly( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $match ) ) {
			return $match[1];
		}
		try {
			return ( new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' );
		} catch ( \Exception $exception ) {
			return '';
		}
	}

	/**
	 * A date text to ISO 8601 UTC ("2026-01-02T00:00:00Z") for a date without a time, or the UTC time otherwise.
	 *
	 * @param string $value Date text.
	 * @return string Empty when the text is not a date.
	 */
	public function toIsoUtc( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		try {
			$date = new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $exception ) {
			return '';
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Add days to a Y-m-d date.
	 *
	 * @param string $date Y-m-d.
	 * @param int    $days Days to add (0 or more).
	 */
	public function addDays( string $date, int $days ): string {
		if ( '' === $date || $days <= 0 ) {
			return $date;
		}
		try {
			return ( new \DateTimeImmutable( $date, new \DateTimeZone( 'UTC' ) ) )->modify( '+' . $days . ' days' )->format( 'Y-m-d' );
		} catch ( \Exception $exception ) {
			return $date;
		}
	}
}
