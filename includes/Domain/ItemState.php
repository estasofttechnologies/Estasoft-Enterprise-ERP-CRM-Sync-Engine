<?php
/**
 * Queue item states. Skipped is a terminal tag, not a seventh chip.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Domain;

defined( 'ABSPATH' ) || exit;

enum ItemState: string {
	case Queued   = 'queued';
	case Syncing  = 'syncing';
	case Synced   = 'synced';
	case Retrying = 'retrying';
	case Failed   = 'failed';
	case Skipped  = 'skipped';

	/**
	 * A finished item is never claimed again unless a person acts.
	 */
	public function isTerminal(): bool {
		return match ( $this ) {
			self::Synced, self::Skipped => true,
			default => false,
		};
	}

	/**
	 * Items that block later items of the same order.
	 */
	public function blocksLaterItems(): bool {
		return match ( $this ) {
			self::Queued, self::Syncing, self::Retrying, self::Failed => true,
			default => false,
		};
	}
}
