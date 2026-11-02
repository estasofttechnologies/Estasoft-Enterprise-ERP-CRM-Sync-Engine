<?php
/**
 * Small helpers for OData addresses and filter values.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

/**
 * Business Central, Dataverse and Finance and Operations all speak OData v4. A value that goes into a `$filter` is a string literal in
 * single quotes with every single quote doubled; a name that goes into an address is validated first. Query option names (`$filter`)
 * are written as they are and values are percent-encoded, because some OData servers do not decode an encoded `$`.
 */
final class OData {

	/**
	 * A string literal for a filter: 'it''s'.
	 *
	 * @param string $value Raw value.
	 */
	public static function string( string $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	/**
	 * `field eq 'value'`.
	 *
	 * @param string $field Field name.
	 * @param string $value Raw value.
	 */
	public static function eq( string $field, string $value ): string {
		return $field . ' eq ' . self::string( $value );
	}

	/**
	 * `startswith(field,'value')`.
	 *
	 * @param string $field Field name.
	 * @param string $value Raw value.
	 */
	public static function startsWith( string $field, string $value ): string {
		return 'startswith(' . $field . ',' . self::string( $value ) . ')';
	}

	/**
	 * `contains(field,'value')`.
	 *
	 * @param string $field Field name.
	 * @param string $value Raw value.
	 */
	public static function contains( string $field, string $value ): string {
		return 'contains(' . $field . ',' . self::string( $value ) . ')';
	}

	/**
	 * Whether a text is a GUID.
	 *
	 * @param string $value Text.
	 */
	public static function isGuid( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim( $value ) );
	}

	/**
	 * A GUID in lower case, or null when the text is not one. Only a checked GUID may be placed in an address.
	 *
	 * @param mixed $value Text.
	 */
	public static function guid( mixed $value ): ?string {
		return is_string( $value ) && self::isGuid( $value ) ? strtolower( trim( $value ) ) : null;
	}

	/**
	 * The GUID inside an `OData-EntityId` header such as `https://org/api/data/v9.2/accounts(00aa…)`.
	 *
	 * @param string|null $header Header value.
	 */
	public static function guidIn( ?string $header ): ?string {
		if ( null === $header || 1 !== preg_match( '/\(([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\)\s*$/i', trim( $header ), $found ) ) {
			return null;
		}
		return strtolower( $found[1] );
	}

	/**
	 * A path with its query options, for example `customers?$filter=…&$top=5`.
	 *
	 * @param string              $path   Path without query.
	 * @param array<string,mixed> $params Query options; null values are left out, booleans written true/false.
	 */
	public static function path( string $path, array $params ): string {
		$parts = array();
		foreach ( $params as $name => $value ) {
			if ( null === $value ) {
				continue;
			}
			$text    = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			$parts[] = $name . '=' . rawurlencode( $text );
		}
		return array() === $parts ? $path : $path . '?' . implode( '&', $parts );
	}

	/**
	 * Join conditions with `and`.
	 *
	 * @param array<int,string> $conditions Conditions.
	 */
	public static function all( array $conditions ): string {
		return implode( ' and ', array_filter( $conditions, static fn( string $condition ): bool => '' !== $condition ) );
	}

	/**
	 * A date written the OData way, YYYY-MM-DD, in UTC.
	 *
	 * @param \DateTimeImmutable $time Time.
	 */
	public static function date( \DateTimeImmutable $time ): string {
		return $time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d' );
	}
}
