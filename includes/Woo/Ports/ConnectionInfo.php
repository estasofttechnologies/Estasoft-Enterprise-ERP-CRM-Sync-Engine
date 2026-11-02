<?php
/**
 * Facts about the connection, for labels on WooCommerce screens.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo\Ports;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented over Core\Repositories\ConnectionRepository and the connector registry. Never exposes a secret.
 */
interface ConnectionInfo {

	/**
	 * The connection screens act on: the first active one, or null when none is connected.
	 */
	public function activeId(): ?int;

	/**
	 * Name of the connected system, for example Odoo.
	 *
	 * @param int|null $connectionId Connection, or null for the active one.
	 */
	public function systemName( ?int $connectionId = null ): string;
}
