<?php
/**
 * Route: invoices.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\Invoices\InvoiceService;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\Support\Sanitize;

/**
 * `GET /invoices`: invoices read back from the system, with the time left to report each one. The plugin never signs or sends them.
 */
final class InvoicesController extends BaseController {

	/**
	 * Register the route.
	 */
	public function registerRoutes(): void {
		$this->add(
			'/invoices',
			'GET',
			self::MANAGE,
			$this->index( ... ),
			array(
				'status'   => self::enumArg( array( 'waiting', 'rejected', 'reported', 'not_required' ) ),
				'search'   => self::lineArg( 100 ),
				'page'     => self::pageArg(),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 25,
				),
			)
		);
	}

	/**
	 * `GET /invoices`: `Paged<InvoiceRow>`.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var InvoiceService $invoices */
		$invoices = $this->service( JobServices::INVOICES );
		return $this->paged(
			$invoices->list(
				array(
					'status'   => (string) $request->get_param( 'status' ),
					'search'   => Sanitize::line( $request->get_param( 'search' ), 100 ),
					'page'     => max( 1, (int) $request->get_param( 'page' ) ),
					'per_page' => max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?? 25 ) ) ),
				)
			)
		);
	}
}
