<?php
/**
 * Routes: go live and shadow mode.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Jobs\Shadow\GoLiveService;
use Estasoft\SyncEngine\Jobs\Shadow\ShadowService;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * `POST /go-live`, `POST /shadow/start`, `GET /shadow/report` and `POST /shadow/end`. In shadow mode orders are built and compared with the
 * system but nothing is sent, and stock and tracking from the system change nothing in WooCommerce. Starting, ending and going
 * live need `estasoft_sync_configure`; the report needs `estasoft_sync_manage`.
 */
final class ShadowController extends BaseController {

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add( '/go-live', 'POST', self::CONFIGURE, $this->goLive( ... ), array( 'mode' => self::enumArg( array( 'shadow', 'live' ), true ) ) );
		$this->add(
			'/shadow/start',
			'POST',
			self::CONFIGURE,
			$this->start( ... ),
			array(
				'days'          => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 30,
					'default' => 7,
				),
				'connection_id' => self::connectionArg(),
			)
		);
		$this->add( '/shadow/report', 'GET', self::MANAGE, $this->report( ... ), array( 'connection_id' => self::connectionArg() ) );
		$this->add( '/shadow/end', 'POST', self::CONFIGURE, $this->end( ... ) );
	}

	/**
	 * `POST /go-live`: start shadow mode, or go live. Items queued before going live are skipped, not sent.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed> The `Onboarding` state.
	 */
	public function goLive( \WP_REST_Request $request ): array {
		$goLive = $this->serviceAs( JobServices::GO_LIVE, GoLiveService::class );
		return $goLive->goLive( (string) $request->get_param( 'mode' ), $this->actorId() );
	}

	/**
	 * `POST /shadow/start`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed> The `Onboarding` state.
	 */
	public function start( \WP_REST_Request $request ): array {
		$shadow = $this->serviceAs( JobServices::SHADOW, ShadowService::class );
		return $shadow->start( $this->connectionId( $request ), max( 1, (int) ( $request->get_param( 'days' ) ?? 7 ) ) );
	}

	/**
	 * `GET /shadow/report`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed> The `ShadowReport`.
	 */
	public function report( \WP_REST_Request $request ): array {
		$shadow = $this->serviceAs( JobServices::SHADOW, ShadowService::class );
		$id     = $request->get_param( 'connection_id' );
		return $shadow->report( is_numeric( $id ) ? (int) $id : null );
	}

	/**
	 * `POST /shadow/end`.
	 *
	 * @return array<string,mixed> The `Onboarding` state.
	 */
	public function end(): array {
		$shadow = $this->serviceAs( JobServices::SHADOW, ShadowService::class );
		return $shadow->end( $this->actorId() );
	}
}
