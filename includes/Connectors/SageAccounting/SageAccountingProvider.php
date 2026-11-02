<?php
/**
 * Registers the Sage Business Cloud Accounting connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\ConnectorProvider;
use Estasoft\SyncEngine\Contract\ConnectorInterface;

/**
 * Found by bin/generate-connector-index.php; creates the connector when the registry first asks for it.
 */
final class SageAccountingProvider implements ConnectorProvider {

	/**
	 * The connector id.
	 */
	public function id(): string {
		return 'sage-accounting';
	}

	/**
	 * Build the connector.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function create( ConnectorDependencies $deps ): ConnectorInterface {
		return new SageAccountingConnector( $deps );
	}
}
