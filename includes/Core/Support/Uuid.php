<?php
/**
 * Random identifiers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Version 4 UUIDs from the system random source. Used for worker ids.
 */
final class Uuid {

	/**
	 * A new random UUID such as 3f2b8c1e-9d4a-4b7e-8c21-5a6d0e9f1b34.
	 */
	public static function v4(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
