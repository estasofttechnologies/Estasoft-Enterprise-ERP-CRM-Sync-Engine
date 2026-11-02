<?php
/**
 * Registers the Lexware Office connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\LexwareOffice;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\ConnectorProvider;
use Estasoft\SyncEngine\Contract\ConnectorInterface;

/**
 * The one entry point the connector index finds.
 */
final class LexwareOfficeProvider implements ConnectorProvider {

	/**
	 * The connector id.
	 */
	public function id(): string {
		return LexwareOfficeConnector::ID;
	}

	/**
	 * Build the connector.
	 *
	 * @param ConnectorDependencies $deps Shared services.
	 */
	public function create( ConnectorDependencies $deps ): ConnectorInterface {
		return new LexwareOfficeConnector( $deps );
	}
}
