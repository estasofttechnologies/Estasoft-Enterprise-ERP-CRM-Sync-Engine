<?php
/**
 * Routes: reconciliation.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Jobs\Reconciliation\ReconService;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * The nightly check: the last runs and open differences, a run now, repair or ignore a difference, and the schedule settings.
 * Repairing and ignoring need only `estasoft_sync_manage`; the schedule needs `estasoft_sync_configure`.
 */
final class ReconciliationController extends BaseController {

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add( '/reconciliation', 'GET', self::MANAGE, $this->overview( ... ), array( 'connection_id' => self::connectionArg() ) );
		$this->add(
			'/reconciliation/run',
			'POST',
			self::MANAGE,
			$this->run( ... ),
			array(
				'window_days'   => array(
					'type'     => 'integer',
					'minimum'  => 1,
					'maximum'  => 30,
					'required' => true,
				),
				'connection_id' => self::connectionArg(),
			)
		);
		$this->add(
			'/reconciliation/differences/(?P<id>\d+)/repair',
			'POST',
			self::MANAGE,
			$this->repair( ... ),
			array(
				'id'        => self::idArg(),
				'direction' => self::enumArg( array( 'wc_to_system', 'system_to_wc' ), true ),
			)
		);
		$this->add(
			'/reconciliation/differences/(?P<id>\d+)/ignore',
			'POST',
			self::MANAGE,
			$this->ignore( ... ),
			array(
				'id'     => self::idArg(),
				'reason' => self::lineArg( 500, true ),
			)
		);
		$this->add(
			'/reconciliation/settings',
			'PUT',
			self::CONFIGURE,
			$this->saveSettings( ... ),
			array(
				'schedule_time'       => array(
					'type'    => 'string',
					'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$',
				),
				'window_days'         => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 30,
				),
				'tolerance_minor'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 1000,
				),
				'auto_repair_missing' => self::boolArg(),
			)
		);
	}

	/**
	 * `GET /reconciliation`: the `ReconOverview`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function overview( \WP_REST_Request $request ): array {
		$id = $request->get_param( 'connection_id' );
		return $this->recon()->overview( is_numeric( $id ) ? (int) $id : null );
	}

	/**
	 * `POST /reconciliation/run`: compare a window of days (bounded work; the answer says how far it got).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed> The `ReconRun`.
	 */
	public function run( \WP_REST_Request $request ): array {
		$id = $request->get_param( 'connection_id' );
		return $this->recon()->run( (int) $request->get_param( 'window_days' ), is_numeric( $id ) ? (int) $id : null, 'manual' );
	}

	/**
	 * `POST /reconciliation/differences/{id}/repair`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed> The `ReconDiff`.
	 */
	public function repair( \WP_REST_Request $request ): array {
		return $this->recon()->repair( (int) $request['id'], (string) $request->get_param( 'direction' ), $this->actorId() );
	}

	/**
	 * `POST /reconciliation/differences/{id}/ignore`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed> The `ReconDiff`.
	 */
	public function ignore( \WP_REST_Request $request ): array {
		return $this->recon()->ignore( (int) $request['id'], (string) $request->get_param( 'reason' ), $this->actorId() );
	}

	/**
	 * `PUT /reconciliation/settings`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed> The `ReconOverview`.
	 */
	public function saveSettings( \WP_REST_Request $request ): array {
		$input = array();
		foreach ( array( 'schedule_time', 'window_days', 'tolerance_minor', 'auto_repair_missing' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$input[ $field ] = $request->get_param( $field );
			}
		}
		return $this->recon()->saveSettings( $input );
	}

	/**
	 * The reconciliation service.
	 *
	 * @return ReconService
	 */
	private function recon(): object {
		return $this->service( JobServices::RECONCILIATION );
	}
}
