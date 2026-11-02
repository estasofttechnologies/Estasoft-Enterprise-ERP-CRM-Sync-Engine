<?php
/**
 * Registers the connectors that ship with the plugin.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Odoo\OdooConnector;
use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\ModuleInterface;

/**
 * Adds Odoo to the estasoft_sync_register_connectors filter, the same way a third-party connector plugin adds its own.
 * The filter runs at priority 5, before the default 10, so an add-on can replace the Odoo connector by registering one with
 * the same id. The core builds the registry from the filter; this module never touches the registry directly.
 */
final class ConnectorsModule implements ModuleInterface {

	/**
	 * Container key of the Odoo connector.
	 */
	public const ODOO = 'connectors.odoo';

	/**
	 * Container key of the engine's HTTP client (Core\Services::HTTP_CLIENT).
	 */
	private const HTTP_CLIENT = 'core.http_client';

	/**
	 * Register the Odoo connector as a lazy service.
	 *
	 * @param Container $container Service container.
	 */
	public function services( Container $container ): void {
		$container->set(
			self::ODOO,
			static fn( Container $c ): OdooConnector => new OdooConnector( $c->get( self::HTTP_CLIENT ) )
		);
	}

	/**
	 * Add the connector to the registration filter.
	 *
	 * @param Container $container Service container.
	 */
	public function hooks( Container $container ): void {
		add_filter(
			'estasoft_sync_register_connectors',
			static function ( $connectors ) use ( $container ): array {
				$connectors   = is_array( $connectors ) ? $connectors : array();
				$connectors[] = $container->get( self::ODOO );
				return $connectors;
			},
			5
		);
	}
}
