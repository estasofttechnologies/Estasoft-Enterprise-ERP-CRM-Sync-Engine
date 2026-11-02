<?php
/**
 * Registers the Sage 200 connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage200;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\ConnectorProvider;
use Estasoft\SyncEngine\Contract\ConnectorInterface;

/**
 * Provider of the `sage200` connector (docs/connectors/sage200.md).
 */
final class Sage200Provider implements ConnectorProvider {

	/**
	 * The connector id.
	 */
	public function id(): string {
		return 'sage200';
	}

	/**
	 * Build the connector.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function create( ConnectorDependencies $deps ): ConnectorInterface {
		return new Sage200Connector( $deps );
	}
}
