<?php
/**
 * Reads typed values out of a mapped payload.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

/**
 * The mapping engine builds nested arrays of mixed scalars. These pure helpers read them without trusting their types.
 */
final class OdooPayloadReader {

	/**
	 * Keys of the partner and delivery arrays.
	 *
	 * @var array<int,string>
	 */
	private const ADDRESS_KEYS = array( 'name', 'email', 'phone', 'company_name', 'street', 'street2', 'city', 'zip', 'state_id', 'country_id', 'vat' );

	/**
	 * Address fields as trimmed text; empty fields are left out.
	 *
	 * @param mixed $raw Array from the payload.
	 * @return array<string,string>
	 */
	public static function address( mixed $raw ): array {
		$address = array();
		if ( is_array( $raw ) ) {
			foreach ( self::ADDRESS_KEYS as $key ) {
				$value = self::text( $raw[ $key ] ?? '' );
				if ( '' !== $value ) {
					$address[ $key ] = $value;
				}
			}
		}
		return $address;
	}

	/**
	 * Tax codes from a string or a list of strings.
	 *
	 * @param mixed $raw Value from the payload.
	 * @return array<int,string> An empty list means "no tax".
	 */
	public static function taxCodes( mixed $raw ): array {
		$values = is_array( $raw ) ? $raw : array( $raw );
		$codes  = array();
		foreach ( $values as $value ) {
			$code = self::text( $value );
			if ( '' !== $code && ! in_array( $code, $codes, true ) ) {
				$codes[] = $code;
			}
		}
		return $codes;
	}

	/**
	 * Integer minor units from an int, a whole float or a digit string.
	 *
	 * @param mixed $value Value.
	 * @return int|null Null when the value is not a whole number.
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
	 * Trimmed text of a scalar; anything else gives an empty string.
	 *
	 * @param mixed $value Value.
	 */
	public static function text( mixed $value ): string {
		return is_scalar( $value ) && ! is_bool( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * A yes or no value.
	 *
	 * @param mixed $value   Value from the payload.
	 * @param bool  $fallback Value when absent or not clear.
	 */
	public static function flag( mixed $value, bool $fallback ): bool {
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
	 * A choice from a fixed list.
	 *
	 * @param mixed             $value   Value from the payload.
	 * @param array<int,string> $allowed Allowed values.
	 * @param string            $fallback Value when absent or not allowed.
	 */
	public static function choice( mixed $value, array $allowed, string $fallback ): string {
		$text = strtolower( self::text( $value ) );
		return in_array( $text, $allowed, true ) ? $text : $fallback;
	}

	/**
	 * A list of arrays from a payload value; anything that is not an array is skipped.
	 *
	 * @param mixed $raw Value from the payload.
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
}
