<?php
/**
 * JSON helpers that never throw.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Encoding and decoding used for the JSON columns, plus a canonical form for hashing.
 */
final class Json {

	/**
	 * Encode a value. Unicode and slashes stay readable; invalid UTF-8 is substituted, not fatal.
	 *
	 * @param mixed $value Any value.
	 */
	public static function encode( mixed $value ): string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return false === $encoded ? '' : $encoded;
	}

	/**
	 * Decode a JSON column into an array.
	 *
	 * @param string|null $json Stored JSON.
	 * @return array<string|int,mixed> Empty array when the value is empty or not a JSON object or list.
	 */
	public static function decode( ?string $json ): array {
		if ( null === $json || '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * A stable encoding for hashing: object keys sorted recursively, lists kept in order.
	 *
	 * @param mixed $value Any value.
	 */
	public static function canonical( mixed $value ): string {
		return self::encode( self::sortKeys( $value ) );
	}

	/**
	 * Recursively sort associative arrays by key.
	 *
	 * @param mixed $value Any value.
	 * @return mixed
	 */
	private static function sortKeys( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$sorted = array();
		foreach ( $value as $key => $item ) {
			$sorted[ $key ] = self::sortKeys( $item );
		}
		if ( ! array_is_list( $sorted ) ) {
			ksort( $sorted, SORT_STRING );
		}
		return $sorted;
	}
}
