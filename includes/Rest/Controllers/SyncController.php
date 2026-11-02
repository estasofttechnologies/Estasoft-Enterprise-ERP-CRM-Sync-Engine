<?php
/**
 * Routes: sync now, pause and resume.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Queue\PauseController;
use Estasoft\SyncEngine\Core\Queue\QueueService;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Jobs\Connections\ConnectionService;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * `POST /sync/run`, `POST /pause` and `POST /resume` (all connections, or one by `connection_id`), and the same for one
 * connection at `/connections/{id}/pause` and `/connections/{id}/resume`. Pause and resume answer with the connection's summary.
 */
final class SyncController extends BaseController {

	/**
	 * Longest pause in minutes (30 days).
	 */
	private const MAX_MINUTES = 43200;

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$minutes = array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => self::MAX_MINUTES,
		);
		$this->add( '/sync/run', 'POST', self::MANAGE, $this->run( ... ) );
		$this->add(
			'/pause',
			'POST',
			self::MANAGE,
			$this->pause( ... ),
			array(
				'connection_id' => self::connectionArg(),
				'minutes'       => $minutes,
			)
		);
		$this->add( '/resume', 'POST', self::MANAGE, $this->resume( ... ), array( 'connection_id' => self::connectionArg() ) );
		$this->add(
			'/connections/(?P<id>\d+)/pause',
			'POST',
			self::MANAGE,
			$this->pauseOne( ... ),
			array(
				'id'      => self::idArg(),
				'minutes' => $minutes,
			)
		);
		$this->add( '/connections/(?P<id>\d+)/resume', 'POST', self::MANAGE, $this->resumeOne( ... ), array( 'id' => self::idArg() ) );
	}

	/**
	 * `POST /sync/run`: ask for a queue run as soon as possible.
	 *
	 * @return array{started:true}
	 *
	 * @throws ApiException 503 when the run could not be scheduled.
	 */
	public function run(): array {
		$queue = $this->serviceAs( Services::QUEUE_SERVICE, QueueService::class );
		if ( ! $queue->syncNow() ) {
			throw new ApiException( 'estasoft_sync_run_failed', esc_html__( 'The run could not be started. Background tasks may be switched off on this site.', 'estasoft-erp-crm-sync-engine' ), 503, 'retry' );
		}
		return array( 'started' => true );
	}

	/**
	 * `POST /pause`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function pause( \WP_REST_Request $request ): array {
		return $this->doPause( $this->optionalInt( $request, 'connection_id' ), $this->optionalInt( $request, 'minutes' ) );
	}

	/**
	 * `POST /resume`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function resume( \WP_REST_Request $request ): array {
		return $this->doResume( $this->optionalInt( $request, 'connection_id' ) );
	}

	/**
	 * `POST /connections/{id}/pause`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function pauseOne( \WP_REST_Request $request ): array {
		return $this->doPause( (int) $request['id'], $this->optionalInt( $request, 'minutes' ) );
	}

	/**
	 * `POST /connections/{id}/resume`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	public function resumeOne( \WP_REST_Request $request ): array {
		return $this->doResume( (int) $request['id'] );
	}

	/**
	 * Pause one connection, or all of them.
	 *
	 * @param int|null $connectionId Connection, or null for all.
	 * @param int|null $minutes      Minutes, or null until resumed.
	 * @return array<string,mixed> The connection's summary.
	 *
	 * @throws ApiException 409 when nothing could be paused.
	 */
	private function doPause( ?int $connectionId, ?int $minutes ): array {
		$pause = $this->serviceAs( Services::PAUSE_CONTROLLER, PauseController::class );
		$done  = null === $connectionId ? $pause->pauseAll( $minutes ) > 0 : $pause->pause( $connectionId, $minutes );
		if ( ! $done ) {
			throw ApiException::conflict( esc_html__( 'There is no connection that can be paused.', 'estasoft-erp-crm-sync-engine' ), 'estasoft_sync_cannot_pause' );
		}
		return $this->summary( $connectionId );
	}

	/**
	 * Resume one connection, or all of them.
	 *
	 * @param int|null $connectionId Connection, or null for all.
	 * @return array<string,mixed> The connection's summary.
	 *
	 * @throws ApiException 409 when nothing needed resuming.
	 */
	private function doResume( ?int $connectionId ): array {
		$pause = $this->serviceAs( Services::PAUSE_CONTROLLER, PauseController::class );
		$done  = null === $connectionId ? $pause->resumeAll() > 0 : $pause->resume( $connectionId );
		if ( ! $done ) {
			throw ApiException::conflict( esc_html__( 'There is no paused connection to resume.', 'estasoft-erp-crm-sync-engine' ), 'estasoft_sync_cannot_resume' );
		}
		return $this->summary( $connectionId );
	}

	/**
	 * The summary of a connection after the change.
	 *
	 * @param int|null $connectionId Connection, or null for the first one that is not disconnected.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException 404 when the connection does not exist.
	 */
	private function summary( ?int $connectionId ): array {
		$connections = $this->serviceAs( JobServices::CONNECTIONS, ConnectionService::class );
		foreach ( $connections->summaries() as $summary ) {
			if ( null === $connectionId ? 'disconnected' !== ( $summary['status'] ?? '' ) : (int) ( $summary['id'] ?? 0 ) === $connectionId ) {
				return $summary;
			}
		}
		throw ApiException::notFound( esc_html__( 'That connection does not exist.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * An optional whole number parameter.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $name    Parameter.
	 */
	private function optionalInt( \WP_REST_Request $request, string $name ): ?int {
		$value = $request->get_param( $name );
		return is_numeric( $value ) ? (int) $value : null;
	}
}
