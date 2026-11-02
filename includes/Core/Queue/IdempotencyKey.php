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
 * same object always gives the same key, so a hook that fires twice cannot add a second item. Every event can happen more
 * than once for the same object (an order is edited, paid, cancelled and delivered again), so each take a growing
 * ordinal: the first time is 0, and the next free number is the highest ordinal in the ledger plus one (never a count,
 * because the ledger is cleaned from the oldest end and a count would give a number that is already taken).
 */
final class IdempotencyKey {

	/**
	 * Events the plugin knows. Every one of them may happen more than once for the same object.
	 */
	public const REPEATABLE_EVENTS = array( 'created', 'paid', 'updated', 'refunded', 'cancelled', 'delivered' );

	/**
	 * Build the key.
	 *
	 * @param int        $connectionId Connection.
	 * @param EntityType $type         Entity type.
	 * @param string     $wcId         WooCommerce id.
	 * @param string     $event        Event.
	 * @param int        $ordinal      0 the first time; then one more than the highest ordinal used before for this object and event.
	 * @return string 64 hex characters.
	 */
	public static function make( int $connectionId, EntityType $type, string $wcId, string $event, int $ordinal = 0 ): string {
		return hash( 'sha256', 'v1:' . $connectionId . ':' . $type->value . ':' . $wcId . ':' . $event . ':' . $ordinal );
	}

	/**
	 * Whether an event can happen more than once for the same object. Every event can (also one a module added), so
	 * this is true for any event.
	 *
	 * @param string $event Event.
	 */
	public static function isRepeatable( string $event ): bool {
		return '' !== $event;
	}
}
