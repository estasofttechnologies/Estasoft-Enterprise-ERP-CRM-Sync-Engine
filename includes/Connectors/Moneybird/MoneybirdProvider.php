<?php
/**
 * Registers the Moneybird connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Moneybird;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\ConnectorProvider;
use Estasoft\SyncEngine\Contract\ConnectorInterface;

/**
 * The one entry point the connector index finds.
 */
final class MoneybirdProvider implements ConnectorProvider {

	/**
	 * The connector id.
	 */
	public function id(): string {
		return MoneybirdConnector::ID;
	}

	/**
	 * Build the connector.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function create( ConnectorDependencies $deps ): ConnectorInterface {
		return new MoneybirdConnector( $deps );
	}
}
