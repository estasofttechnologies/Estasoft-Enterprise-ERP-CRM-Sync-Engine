<?php
/**
 * Route: the catalog of systems.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Catalog\Logos;
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
		$entries     = $connections->catalog();
		/**
		 * Whether to show the vendors' logos on the Choose a system screen. Return false to show letter tiles only.
		 *
		 * @param bool $show Default true.
		 */
		$show = (bool) apply_filters( 'estasoft_sync_show_vendor_logos', true );
		foreach ( $entries as $index => $entry ) {
			$connector = isset( $entry['connector'] ) && is_string( $entry['connector'] ) ? $entry['connector'] : '';
			// Always sent, so the screen can rely on it: an empty string means the letter tile.
			$entries[ $index ]['logo'] = $show && '' !== $connector ? Logos::urlFor( $connector, ESTASOFT_SYNC_DIR, ESTASOFT_SYNC_URL ) : '';
		}
		return $entries;
	}
}
