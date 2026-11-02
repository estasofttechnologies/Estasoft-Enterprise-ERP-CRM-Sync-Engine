<?php
/**
 * Reads values out of the nested array the mapping engine builds.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

/**
 * The mapping engine writes text, numbers, lists and nested arrays. These helpers read them without warnings and without guessing.
 */
final class WafeqPayloadReader {

	/**
	 * A text value, trimmed. Arrays and objects give an empty string.
	 *
	 * @param mixed $value Value.
	 */
	public static function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Integer minor units from an integer, an integer-valued number or digits in text.
	 *
	 * @param mixed $value Value.
	 * @return int|null Null when the value is missing or not a whole number.
	 */
	public static function minor( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && floor( $value ) === $value ) {
			return (int) $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', trim( $value ) ) ) {
			return (int) trim( $value );
		}
		return null;
	}

	/**
	 * A quantity.
	 *
	 * @param mixed $value Value.
	 */
	public static function quantity( mixed $value ): float {
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * A yes or no value, with a default for a missing one.
	 *
	 * @param mixed $value   Value.
	 * @param bool  $fallback Default.
	 */
	public static function flag( mixed $value, bool $fallback ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		return $fallback;
	}

	/**
	 * A nested block as an array.
	 *
	 * @param mixed $value Value.
	 * @return array<string,mixed>
	 */
	public static function block( mixed $value ): array {
		return is_array( $value ) ? $value : array();
	}

	/**
	 * The rows of a list target. A single row written as an associative array counts as one row.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( mixed $value ): array {
		if ( ! is_array( $value ) || array() === $value ) {
			return array();
		}
		if ( ! \Estasoft\SyncEngine\Core\Support\Lists::isList( $value ) ) {
			return array( $value );
		}
		return array_values( array_filter( $value, 'is_array' ) );
	}

	/**
	 * A tax code: the text, or the first non-empty text of a list.
	 *
	 * @param mixed $value Value.
	 */
	public static function taxCode( mixed $value ): string {
		if ( is_array( $value ) ) {
			foreach ( $value as $candidate ) {
				$text = self::text( $candidate );
				if ( '' !== $text ) {
					return $text;
				}
			}
			return '';
		}
		return self::text( $value );
	}

	/**
	 * Cut a text to a length with an ellipsis; the cut is never silent for reference fields (the caller refuses them instead).
	 *
	 * @param string $text Text.
	 * @param int    $max  Longest result in characters.
	 */
	public static function shorten( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}
}
