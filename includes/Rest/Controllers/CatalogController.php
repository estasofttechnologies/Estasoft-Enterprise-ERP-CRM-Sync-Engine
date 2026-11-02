<?php
/**
 * Route: the catalog of systems.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Connections\ConnectionService;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * `GET /catalog`: the 34 systems of the Choose a system screen. Static data from the plugin; nothing is fetched from the network.
 */
final class CatalogController extends BaseController {

	/**
	 * Register the route.
	 */
	public function registerRoutes(): void {
		$this->add( '/catalog', 'GET', self::MANAGE, $this->index( ... ) );
	}

	/**
	 * The `CatalogEntry[]` of src/api/types.ts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function index(): array {
		$connections = $this->serviceAs( JobServices::CONNECTIONS, ConnectionService::class );
		return $connections->catalog();
	}
}
