<?php
/**
 * How a connector announces itself.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorInterface;

/**
 * One provider per connector (includes/Connectors/{Vendor}/{Vendor}Provider.php). bin/generate-connector-index.php lists the
 * providers in includes/Connectors/providers.php; ConnectorsModule creates the connector lazily, the first time it is needed.
 */
interface ConnectorProvider {

	/**
	 * The connector id, lower case [a-z0-9-].
	 */
	public function id(): string;

	/**
	 * Build the connector.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function create( ConnectorDependencies $deps ): ConnectorInterface;
}
