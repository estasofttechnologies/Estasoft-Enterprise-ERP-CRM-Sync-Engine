<?php
/**
 * Connection facts backed by the core connection repository and the connector registry.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo\Adapters;

use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Woo\Ports\ConnectionInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Reads connections without their secrets.
 */
final class CoreConnectionInfo implements ConnectionInfo {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ConnectionRepository $connections Connections.
	 * @param \Closure             $registry    Returns the connector registry (resolved when needed).
	 */
	public function __construct(
		private readonly ConnectionRepository $connections,
		private readonly \Closure $registry
	) {
	}

	/**
	 * The first connection that may send now.
	 */
	public function activeId(): ?int {
		$ids = $this->connections->activeIds();
		return array() === $ids ? null : (int) reset( $ids );
	}

	/**
	 * Name of the connected system.
	 *
	 * @param int|null $connectionId Connection, or null for the active one.
	 */
	public function systemName( ?int $connectionId = null ): string {
		$id         = $connectionId ?? $this->activeId();
		$connection = null === $id || $id <= 0 ? null : $this->connections->findWithoutSecrets( $id );
		if ( null === $connection ) {
			return __( 'the connected system', 'estasoft-erp-crm-sync-engine' );
		}
		$registry  = ( $this->registry )();
		$connector = $registry->get( $connection->connector );
		return null === $connector ? $connection->label : $connector->label();
	}
}
