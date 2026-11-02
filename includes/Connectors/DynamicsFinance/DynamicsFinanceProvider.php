<?php
/**
 * Announces the Dynamics 365 Finance and Supply Chain connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsFinance;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\ConnectorProvider;
use Estasoft\SyncEngine\Contract\ConnectorInterface;

/**
 * Registration through bin/generate-connector-index.php; the connector is created when it is first needed.
 */
final class DynamicsFinanceProvider implements ConnectorProvider {

	/**
	 * The connector id.
	 */
	public function id(): string {
		return 'dynamics-finance';
	}

	/**
	 * Build the connector.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function create( ConnectorDependencies $deps ): ConnectorInterface {
		return new DynamicsFinanceConnector( $deps );
	}
}
