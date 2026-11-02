<?php
/**
 * Registers the Zoho Books connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ZohoBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\ConnectorProvider;
use Estasoft\SyncEngine\Contract\ConnectorInterface;

/**
 * Provider read by the generated connector index.
 */
final class ZohoBooksProvider implements ConnectorProvider {

	/**
	 * The connector id.
	 */
	public function id(): string {
		return 'zoho-books';
	}

	/**
	 * Build the connector.
	 *
	 * @param ConnectorDependencies $deps Shared dependencies.
	 */
	public function create( ConnectorDependencies $deps ): ConnectorInterface {
		return new ZohoBooksConnector( $deps );
	}
}
