<?php
/**
 * Announces the Odoo connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Odoo;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\ConnectorProvider;
use Estasoft\SyncEngine\Contract\ConnectorInterface;

/**
 * Odoo has its own transports and does not use the shared API base; it only needs the engine's HTTP client.
 */
final class OdooProvider implements ConnectorProvider {

	/**
	 * The connector id.
	 */
	public function id(): string {
		return 'odoo';
	}

	/**
	 * Build the connector.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function create( ConnectorDependencies $deps ): ConnectorInterface {
		return new OdooConnector( $deps->http );
	}
}
