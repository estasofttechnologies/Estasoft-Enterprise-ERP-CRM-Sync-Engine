<?php
/**
 * Reads sevDesk answers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sevdesk;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EuAccountingCommon\Amounts;

/**
 * The sevDesk API wraps every answer in `objects`: a list for a search, a list with one element or the object itself for a read, and for a factory
 * call an object that holds the created records. Many numbers and booleans come back as text ("100.00", "1") [third-party].
 */
final class SevdeskJson {

	/**
	 * The records of an answer, whatever shape `objects` has.
	 *
	 * @param array<string,mixed> $json Decoded answer.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( array $json ): array {
		$objects = $json['objects'] ?? array();
		if ( ! is_array( $objects ) || array() === $objects ) {
			return array();
		}
		if ( array_is_list( $objects ) ) {
			return array_values( array_filter( $objects, 'is_array' ) );
		}
		return array( $objects );
	}

	/**
	 * The first record of an answer, or null.
	 *
	 * @param array<string,mixed> $json Decoded answer.
	 * @return array<string,mixed>|null
	 */
	public static function first( array $json ): ?array {
		$rows = self::rows( $json );
		return $rows[0] ?? null;
	}

	/**
	 * A money value (number or text) as minor units of two decimals.
	 *
	 * @param mixed $value Value from an answer.
	 */
	public static function minor( mixed $value ): ?int {
		return ( new Amounts() )->toMinor( $value, 2 );
	}

	/**
	 * A boolean that may arrive as text or a number.
	 *
	 * @param mixed $value Value.
	 */
	public static function flag( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes' ), true );
	}

	/**
	 * A reference object as sevDesk wants it.
	 *
	 * @param string|int $id     Id.
	 * @param string     $objectName Object name.
	 * @return array{id:int|string,objectName:string}
	 */
	public static function ref( string|int $id, string $objectName ): array {
		return array(
			'id'         => is_string( $id ) && ctype_digit( $id ) ? (int) $id : $id,
			'objectName' => $objectName,
		);
	}
}
