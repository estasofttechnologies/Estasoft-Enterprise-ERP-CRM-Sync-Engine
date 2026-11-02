<?php
/**
 * Registers the Sage 300 connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage300;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\ConnectorProvider;
use Estasoft\SyncEngine\Contract\ConnectorInterface;

/**
 * Provider of the `sage300` connector (docs/connectors/sage300.md).
 */
final class Sage300Provider implements ConnectorProvider {

	/**
	 * The connector id.
	 */
	public function id(): string {
		return 'sage300';
	}

	/**
	 * Build the connector.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function create( ConnectorDependencies $deps ): ConnectorInterface {
		return new Sage300Connector( $deps );
	}
}
