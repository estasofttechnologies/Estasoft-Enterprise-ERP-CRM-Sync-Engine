<?php
/**
 * Registers the Zoho CRM connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ZohoCrm;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\ConnectorProvider;
use Estasoft\SyncEngine\Contract\ConnectorInterface;

/**
 * Provider read by the generated connector index.
 */
final class ZohoCrmProvider implements ConnectorProvider {

	/**
	 * The connector id.
	 */
	public function id(): string {
		return 'zoho-crm';
	}

	/**
	 * Build the connector.
	 *
	 * @param ConnectorDependencies $deps Shared dependencies.
	 */
	public function create( ConnectorDependencies $deps ): ConnectorInterface {
		return new ZohoCrmConnector( $deps );
	}
}
