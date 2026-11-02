<?php
/**
 * Route: the dashboard status.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Dashboard\DashboardService;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * `GET /status`: counts, oldest waiting, hourly chart, latest events, connection summary and shadow information.
 */
final class StatusController extends BaseController {

	/**
	 * Register the route.
	 */
	public function registerRoutes(): void {
		$this->add( '/status', 'GET', self::MANAGE, $this->status( ... ), array( 'connection_id' => self::connectionArg() ) );
	}

	/**
	 * The `Status` of src/api/types.ts.
	 *
	 * @param \WP_REST_Request $request Request; optional `connection_id`.
	 * @return array<string,mixed>
	 */
	public function status( \WP_REST_Request $request ): array {
		/** @var DashboardService $dashboard */
		$dashboard = $this->service( JobServices::DASHBOARD );
		$id        = $request->get_param( 'connection_id' );
		return $dashboard->status( is_numeric( $id ) ? (int) $id : null );
	}
}
