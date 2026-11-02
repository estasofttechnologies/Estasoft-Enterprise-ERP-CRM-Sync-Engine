<?php
/**
 * Routes: logs.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Repositories\LogQuery;
use Estasoft\SyncEngine\Core\Repositories\LogRepository;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\BaseController;

/**
 * `GET /logs` (JSON, or a CSV download when the request accepts `text/csv`) and `GET /logs/{id}`. Entries are redacted when they are
 * written, so the CSV matches the screen.
 */
final class LogsController extends BaseController {

	/**
	 * Content type of the download.
	 */
	public const CSV_TYPE = 'text/csv';

	/**
	 * Register the routes and the filter that sends the CSV as it is.
	 */
	public function registerRoutes(): void {
		add_filter( 'rest_pre_serve_request', array( $this, 'serveCsv' ), 10, 2 );

		$this->add(
			'/logs',
			'GET',
			self::MANAGE,
			$this->index( ... ),
			array(
				'level'         => self::enumArg( LogQuery::LEVELS ),
				'search'        => self::lineArg( 100 ),
				'connection_id' => self::connectionArg(),
				'queue_id'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'code'          => array(
					'type'    => 'string',
					'pattern' => '^SYNC-[A-Z]+-[0-9]{3}$',
				),
				'range'         => self::enumArg( array( 'hour', 'today', '7d', '14d' ) ),
				'page'          => self::pageArg(),
				'per_page'      => array(
					'type'    => 'integer',
					'minimum' => 10,
					'maximum' => 200,
					'default' => 25,
				),
			)
		);
		$this->add( '/logs/(?P<id>\d+)', 'GET', self::MANAGE, $this->show( ... ), array( 'id' => self::idArg() ) );
	}

	/**
	 * `GET /logs`.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var LogRepository $logs */
		$logs  = $this->service( Services::LOG_REPOSITORY );
		$query = LogQuery::fromArray( $this->params( $request ) );
		if ( $this->wantsCsv( $request ) ) {
			$response = new \WP_REST_Response( $logs->csv( $query ), 200 );
			$response->header( 'Content-Type', self::CSV_TYPE . '; charset=utf-8' );
			$response->header( 'Content-Disposition', 'attachment; filename="estasoft-sync-logs-' . gmdate( 'Ymd-His' ) . '.csv"' );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
		return $this->paged( $logs->query( $query ) );
	}

	/**
	 * `GET /logs/{id}`: the `LogEntryDetail`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException 404 when there is no such entry.
	 */
	public function show( \WP_REST_Request $request ): array {
		/** @var LogRepository $logs */
		$logs  = $this->service( Services::LOG_REPOSITORY );
		$entry = $logs->find( (int) $request['id'] );
		if ( null === $entry ) {
			throw ApiException::notFound( __( 'That log entry does not exist. It may have been removed by the retention setting.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $entry;
	}

	/**
	 * Send a CSV answer as plain text instead of JSON. Filter `rest_pre_serve_request`.
	 *
	 * @param bool                  $served Whether something else already sent the answer.
	 * @param \WP_HTTP_Response|mixed $result The answer.
	 * @return bool
	 */
	public function serveCsv( mixed $served, mixed $result = null ): bool {
		if ( $served || ! $result instanceof \WP_HTTP_Response ) {
			return (bool) $served;
		}
		$type = (string) ( $result->get_headers()['Content-Type'] ?? '' );
		if ( ! str_starts_with( $type, self::CSV_TYPE ) || ! is_string( $result->get_data() ) ) {
			return false;
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV text for download, cells are neutralised by LogRepository::csv().
		return true;
	}

	/**
	 * Whether the request asks for a CSV.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function wantsCsv( \WP_REST_Request $request ): bool {
		return str_contains( strtolower( (string) $request->get_header( 'accept' ) ), self::CSV_TYPE );
	}
}
