<?php
/**
 * Whether an array is a list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The same answer as PHP 8.1's array_is_list(), without calling it. WordPress ships its own copy of that function from
 * version 6.5, and WordPress.org's Plugin Check reads a call to it as code that needs WordPress 6.5, which this plugin does
 * not (it runs on 6.4 and PHP 8.1, where the function exists natively).
 */
final class Lists {

	/**
	 * True for an empty array and for an array whose keys are 0, 1, 2 and so on, in that order.
	 *
	 * @param array<mixed> $value The array.
	 */
	public static function isList( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
