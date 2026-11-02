<?php
/**
 * The key that makes an event safe to repeat.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Domain\EntityType;

/**
 * `sha256("v1:" . connectionId . ":" . entityType . ":" . wcId . ":" . event . ":" . ordinal)`. The same event for the
 * same object always gives the same key, so a hook that fires twice cannot add a second item. Events that can
 * happen many times (an order edited again and again) take a growing ordinal.
 */
final class IdempotencyKey {

	/**
	 * Events that may legitimately happen more than once for the same object.
	 */
	public const REPEATABLE_EVENTS = array( 'updated' );

	/**
	 * Build the key.
	 *
	 * @param int        $connectionId Connection.
	 * @param EntityType $type         Entity type.
	 * @param string     $wcId         WooCommerce id.
	 * @param string     $event        Event.
	 * @param int        $ordinal      0 for events that happen once; the count of earlier keys for repeatable events.
	 * @return string 64 hex characters.
	 */
	public static function make( int $connectionId, EntityType $type, string $wcId, string $event, int $ordinal = 0 ): string {
		return hash( 'sha256', 'v1:' . $connectionId . ':' . $type->value . ':' . $wcId . ':' . $event . ':' . $ordinal );
	}

	/**
	 * Whether an event can happen more than once for the same object.
	 *
	 * @param string $event Event.
	 */
	public static function isRepeatable( string $event ): bool {
		return in_array( $event, self::REPEATABLE_EVENTS, true );
	}
}
