<?php
/**
 * Reads values out of Odoo records.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

/**
 * Odoo sends many2one fields as an id or as [id, name], empty values as false, and quantities as floats.
 * These pure helpers turn those shapes into plain PHP values.
 */
final class OdooRecord {

	/**
	 * The id in a many2one value.
	 *
	 * @param mixed $value int, [id, name] or false.
	 * @return int 0 when empty.
	 */
	public static function id( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) && isset( $value[0] ) && is_int( $value[0] ) ) {
			return $value[0];
		}
		return 0;
	}

	/**
	 * The ids in a one2many or many2many value.
	 *
	 * @param mixed $value List of ids.
	 * @return array<int,int>
	 */
	public static function ids( mixed $value ): array {
		$ids = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $entry ) {
				$id = self::id( $entry );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Text of a field; false and null become an empty string.
	 *
	 * @param mixed $value Field value.
	 */
	public static function text( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) && isset( $value[1] ) && is_string( $value[1] ) ) {
			return $value[1];
		}
		return '';
	}

	/**
	 * Number of a field; false and null become 0.
	 *
	 * @param mixed $value Field value.
	 */
	public static function number( mixed $value ): float {
		return is_int( $value ) || is_float( $value ) ? (float) $value : ( is_string( $value ) && is_numeric( $value ) ? (float) $value : 0.0 );
	}

	/**
	 * The display name in a many2one value.
	 *
	 * @param mixed $value [id, name] or anything else.
	 */
	public static function name( mixed $value ): string {
		return is_array( $value ) && isset( $value[1] ) && is_string( $value[1] ) ? $value[1] : '';
	}
}
