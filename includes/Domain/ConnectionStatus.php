<?php
/**
 * Connection states (spec section 6.2).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Domain;

defined( 'ABSPATH' ) || exit;

enum ConnectionStatus: string {
	case Active         = 'active';
	case Paused         = 'paused';
	case PausedCircuit  = 'paused_circuit';
	case NeedsReconnect = 'needs_reconnect';
	case Disconnected   = 'disconnected';

	/**
	 * Workers claim items only for active connections.
	 */
	public function allowsClaiming(): bool {
		return self::Active === $this;
	}
}
