<?php
/**
 * Finds the OAuth side of a connector through the registry.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * The token manager is built before the connectors exist; when a caller does not pass the provider, this finds it through the registry,
 * creating only the one connector the connection uses.
 */
final class OAuthResolver {

	/**
	 * Set up with what it depends on.
	 *
	 * @param \Closure $registry Returns the ConnectorRegistry.
	 */
	public function __construct( private readonly \Closure $registry ) {
	}

	/**
	 * The OAuth connector of a connection, or null when its connector does not sign in with OAuth.
	 *
	 * @param Connection $connection Connection.
	 */
	public function connectorFor( Connection $connection ): ?OAuthConnector {
		$registry = ( $this->registry )();
		$found    = $registry instanceof ConnectorRegistry ? $registry->get( $connection->connector ) : null;
		$found    = $found instanceof GuardedConnector ? $found->inner() : $found;
		return $found instanceof OAuthConnector ? $found : null;
	}

	/**
	 * The provider of a connection's OAuth settings, or null.
	 *
	 * @param Connection $connection Connection.
	 */
	public function providerFor( Connection $connection ): ?OAuthProvider {
		return $this->connectorFor( $connection )?->oauthProvider();
	}
}
