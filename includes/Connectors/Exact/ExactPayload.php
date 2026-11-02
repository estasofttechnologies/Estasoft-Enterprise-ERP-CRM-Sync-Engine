<?php
/**
 * Reads typed values out of a mapped payload.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Exact;

defined( 'ABSPATH' ) || exit;

/**
 * The mapping engine builds nested arrays of mixed scalars. These pure helpers read them without trusting their types.
 */
final class ExactPayload {

	/**
	 * Trimmed text of a scalar; anything else gives an empty string.
	 *
	 * @param mixed $value Value.
	 */
	public static function text( mixed $value ): string {
		return is_scalar( $value ) && ! is_bool( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Integer minor units from an int, a whole float or a digit string; null when the value is not a whole number.
	 *
	 * @param mixed $value Value.
	 */
	public static function minor( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && floor( $value ) === $value && abs( $value ) < 9.0e15 ) {
			return (int) $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^-?\d{1,15}$/', trim( $value ) ) ) {
			return (int) trim( $value );
		}
		return null;
	}

	/**
	 * A yes or no value.
	 *
	 * @param mixed $value    Value.
	 * @param bool  $fallback Value when absent or unclear.
	 */
	public static function flag( mixed $value, bool $fallback = false ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			$text = strtolower( trim( (string) $value ) );
			if ( in_array( $text, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return true;
			}
			if ( in_array( $text, array( '0', 'false', 'no', 'off', '' ), true ) ) {
				return false;
			}
		}
		return $fallback;
	}

	/**
	 * A list of arrays from a payload value; anything else is skipped.
	 *
	 * @param mixed $raw Value.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( mixed $raw ): array {
		$rows = array();
		if ( is_array( $raw ) ) {
			foreach ( array_values( $raw ) as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
		}
		return $rows;
	}

	/**
	 * A quantity as decimal text above zero; null otherwise.
	 *
	 * @param mixed $value Value.
	 */
	public static function quantity( mixed $value ): ?string {
		if ( is_int( $value ) ) {
			$text = (string) $value;
		} elseif ( is_float( $value ) ) {
			$text = rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
		} elseif ( is_string( $value ) ) {
			$text = trim( $value );
		} else {
			return null;
		}
		if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $text ) || (float) $text <= 0 ) {
			return null;
		}
		return $text;
	}

	/**
	 * A quantity as a JSON number.
	 *
	 * @param string $quantity Decimal text.
	 */
	public static function quantityNumber( string $quantity ): int|float {
		return ctype_digit( $quantity ) ? (int) $quantity : (float) $quantity;
	}

	/**
	 * The first of one or more tax codes.
	 *
	 * @param mixed $raw Code or list of codes.
	 */
	public static function taxCode( mixed $raw ): string {
		$values = is_array( $raw ) ? $raw : array( $raw );
		foreach ( $values as $value ) {
			$code = self::text( $value );
			if ( '' !== $code ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * A date as Y-m-d, or the fallback.
	 *
	 * @param mixed  $value    Value.
	 * @param string $fallback Date when absent.
	 */
	public static function date( mixed $value, string $fallback ): string {
		return 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', self::text( $value ), $parts ) ? $parts[1] : $fallback;
	}
}
