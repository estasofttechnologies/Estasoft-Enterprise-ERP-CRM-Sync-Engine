<?php
/**
 * The `{"value": ...}` wrapper of the contract-based REST API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Acumatica;

defined( 'ABSPATH' ) || exit;

/**
 * Every field Acumatica reads or writes is an object with a `value` key (and, on a refused write, an `error` key). Detail rows and
 * linked entities are arrays and objects of such fields.
 */
final class AcumaticaValue {

	/**
	 * Wrap a value for a request body.
	 *
	 * @param mixed $value Value.
	 * @return array{value:mixed}
	 */
	public static function wrap( mixed $value ): array {
		return array( 'value' => $value );
	}

	/**
	 * Wrap every non-null field of a flat array.
	 *
	 * @param array<string,mixed> $fields Field name to plain value; null and empty-string values are left out.
	 * @return array<string,array{value:mixed}>
	 */
	public static function fields( array $fields ): array {
		$out = array();
		foreach ( $fields as $name => $value ) {
			if ( null !== $value && '' !== $value ) {
				$out[ $name ] = self::wrap( $value );
			}
		}
		return $out;
	}

	/**
	 * The plain value of a field in a record.
	 *
	 * @param array<string,mixed> $record Record from the API.
	 * @param string              $field  Field name.
	 * @return mixed Null when absent.
	 */
	public static function get( array $record, string $field ): mixed {
		$cell = $record[ $field ] ?? null;
		if ( is_array( $cell ) && array_key_exists( 'value', $cell ) ) {
			return $cell['value'];
		}
		return null;
	}

	/**
	 * The text of a field, trimmed.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $field  Field name.
	 */
	public static function text( array $record, string $field ): string {
		$value = self::get( $record, $field );
		return is_scalar( $value ) && ! is_bool( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * A string literal for a `$filter`: single quotes, embedded quotes doubled.
	 *
	 * @param string $value Text.
	 */
	public static function literal( string $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	/**
	 * A date-time literal for a `$filter`: datetimeoffset'...' in UTC.
	 *
	 * @param \DateTimeImmutable $time Time.
	 */
	public static function dateLiteral( \DateTimeImmutable $time ): string {
		return "datetimeoffset'" . $time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s' ) . "+00:00'";
	}

	/**
	 * The first error text in a record: a field that has `error`, searched through detail rows and linked entities.
	 *
	 * @param mixed $node Decoded JSON.
	 * @return string Empty when none.
	 */
	public static function firstError( mixed $node ): string {
		if ( ! is_array( $node ) ) {
			return '';
		}
		if ( isset( $node['error'] ) && is_string( $node['error'] ) && '' !== trim( $node['error'] ) ) {
			return trim( $node['error'] );
		}
		foreach ( $node as $child ) {
			$found = self::firstError( $child );
			if ( '' !== $found ) {
				return $found;
			}
		}
		return '';
	}
}
