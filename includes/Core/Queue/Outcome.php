<?php
/**
 * How processing one item ended.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

enum Outcome: string {

	/**
	 * The system accepted the item.
	 */
	case Synced = 'synced';

	/**
	 * The system already had the record; it was linked (SYNC-DUP-001).
	 */
	case Linked = 'linked';

	/**
	 * Nothing had changed since the last sync, so nothing was sent.
	 */
	case NoChange = 'no_change';

	/**
	 * Shadow mode: the payload was built and nothing was sent.
	 */
	case Shadow = 'shadow';

	/**
	 * A retryable error: the item will be tried again.
	 */
	case Retrying = 'retrying';

	/**
	 * The item failed and waits for a person.
	 */
	case Failed = 'failed';

	/**
	 * The item went back to the queue without a try: the connection is not active, or the connector is missing.
	 */
	case Held = 'held';

	/**
	 * The item was skipped because its connection no longer exists.
	 */
	case Skipped = 'skipped';
}
