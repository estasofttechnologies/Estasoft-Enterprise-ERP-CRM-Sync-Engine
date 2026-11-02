<?php
/**
 * Sanitise callbacks for REST arguments.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Small pure functions used as `sanitize_callback` and `validate_callback` of route arguments. Values that fail are reduced to
 * an empty value, which the route's own validation then refuses where a value is required.
 */
final class Sanitize {

	/**
	 * Longest single text value accepted.
	 */
	public const MAX_TEXT = 4096;

	/**
	 * A true or false flag the way WordPress reads one: true, 1, "1", "true" and "on" are true; false, 0, "0", "false", "off",
	 * an empty string, null and anything that is not a plain value are false.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function flag( mixed $value ): bool {
		return is_scalar( $value ) && rest_sanitize_boolean( (string) $value );
	}

	/**
	 * A name to text map, for example the non-secret connection fields. Keys are reduced to letters, digits, dash and underscore;
	 * values are plain text with no tags.
	 *
	 * @param mixed $value Candidate.
	 * @return array<string,string>
	 */
	public static function textMap( mixed $value ): array {
		$clean = array();
		foreach ( is_array( $value ) ? $value : array() as $key => $item ) {
			$name = self::name( $key );
			if ( '' === $name || ! is_scalar( $item ) ) {
				continue;
			}
			$clean[ $name ] = mb_substr( sanitize_text_field( (string) $item ), 0, self::MAX_TEXT );
		}
		return $clean;
	}

	/**
	 * A name to secret map. The values are kept exactly as typed, because a key can contain any character; only the type and
	 * the length are checked.
	 *
	 * @param mixed $value Candidate.
	 * @return array<string,string>
	 */
	public static function secretMap( mixed $value ): array {
		$clean = array();
		foreach ( is_array( $value ) ? $value : array() as $key => $item ) {
			$name = self::name( $key );
			if ( '' === $name || ! is_scalar( $item ) ) {
				continue;
			}
			$clean[ $name ] = mb_substr( (string) $item, 0, self::MAX_TEXT );
		}
		return $clean;
	}

	/**
	 * Whole numbers greater than zero, no duplicates.
	 *
	 * @param mixed $value Candidate.
	 * @return array<int,int>
	 */
	public static function idList( mixed $value ): array {
		$ids = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( is_int( $item ) || ( is_string( $item ) && 1 === preg_match( '/^\d+$/', $item ) ) ) {
				$id = (int) $item;
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}
		return array_values( $ids );
	}

	/**
	 * Plain text on one line, trimmed, up to a length.
	 *
	 * @param mixed $value  Candidate.
	 * @param int   $length Longest result in characters.
	 */
	public static function line( mixed $value, int $length = 500 ): string {
		return is_scalar( $value ) ? mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, $length ) : '';
	}

	/**
	 * Plain text that may span lines, trimmed, up to a length.
	 *
	 * @param mixed $value  Candidate.
	 * @param int   $length Longest result in characters.
	 */
	public static function text( mixed $value, int $length = 1000 ): string {
		return is_scalar( $value ) ? mb_substr( trim( sanitize_textarea_field( (string) $value ) ), 0, $length ) : '';
	}

	/**
	 * A string that must be kept exactly (a JSON document). Anything else becomes an empty string. The consumer parses and
	 * validates it strictly.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function document( mixed $value ): string {
		return is_string( $value ) ? $value : '';
	}

	/**
	 * A key made of lower-case letters, digits, dash and underscore.
	 *
	 * @param mixed $value Candidate.
	 */
	public static function name( mixed $value ): string {
		return is_scalar( $value ) ? substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ), 0, 64 ) : '';
	}

	/**
	 * Whether a value is an object or list (a JSON object or array).
	 *
	 * @param mixed $value Candidate.
	 */
	public static function isStructure( mixed $value ): bool {
		return is_array( $value );
	}
}
