<?php
/**
 * Route: the support report.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Jobs\Support\SupportReportBuilder;
use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\Support\Sanitize;

/**
 * `GET /support-report?order_numbers=0|1`: versions, runtime, the connection summary without secrets, queue counts, the readiness
 * summary and the last errors. Order numbers are masked unless asked for. Nothing is sent anywhere.
 */
final class SupportReportController extends BaseController {

	/**
	 * Register the route.
	 */
	public function registerRoutes(): void {
		$this->add( '/support-report', 'GET', self::MANAGE, $this->show( ... ), array( 'order_numbers' => self::boolArg() ) );
	}

	/**
	 * The `SupportReport`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function show( \WP_REST_Request $request ): array {
		$report = $this->serviceAs( JobServices::SUPPORT_REPORT, SupportReportBuilder::class );
		return $report->build( Sanitize::flag( $request->get_param( 'order_numbers' ) ) );
	}
}
