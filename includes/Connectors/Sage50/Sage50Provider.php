<?php
/**
 * Registers the Sage 50 export connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage50;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\ConnectorProvider;
use Estasoft\SyncEngine\Contract\ConnectorInterface;

/**
 * Found by bin/generate-connector-index.php.
 */
final class Sage50Provider implements ConnectorProvider {

	/**
	 * The connector id.
	 */
	public function id(): string {
		return 'sage50-export';
	}

	/**
	 * Build the connector.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function create( ConnectorDependencies $deps ): ConnectorInterface {
		return new Sage50Connector( $deps );
	}
}
