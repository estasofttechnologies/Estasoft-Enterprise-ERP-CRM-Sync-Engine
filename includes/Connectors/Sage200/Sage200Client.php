<?php
/**
 * The Sage 200 API behind small methods.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage200;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Lists answer with a bare JSON array (some answers wrap it in `value`), 500 rows at most unless `$top` asks for more, and `$skip` works
 * only with `$orderby`, so every list is ordered by `id`. A 404 on a resource means the installed version lacks it and is reported as such.
 */
final class Sage200Client {

	/**
	 * Rows asked for in one list call.
	 */
	public const PAGE = 500;

	/**
	 * Most rows a list helper collects before it stops.
	 */
	public const MAX_ROWS = 20000;

	/**
	 * Set up with the services it uses.
	 *
	 * @param ApiClient $api    Shared client.
	 * @param string    $system System name.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $system ) {
	}

	/**
	 * A string literal for a `$filter`: single quotes, embedded quotes doubled.
	 *
	 * @param string $value Text.
	 */
	public static function literal( string $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	/**
	 * An id for a request body: a whole number when it is one (Sage 200 ids are integers), else the text.
	 *
	 * @param string|int $id Id as read.
	 * @return int|string
	 */
	public static function id( string|int $id ): int|string {
		return is_int( $id ) || ( '' !== $id && ctype_digit( $id ) && strlen( $id ) < 15 ) ? (int) $id : $id;
	}

	/**
	 * Rows of a resource, collected page by page.
	 *
	 * @param string              $endpoint Resource such as sop_orders.
	 * @param string              $filter   `$filter` text, or empty.
	 * @param int                 $max      Most rows to collect.
	 * @param array<string,mixed> $extra    More query options.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function rows( string $endpoint, string $filter = '', int $max = self::MAX_ROWS, array $extra = array() ): array {
		$rows = array();
		$skip = 0;
		$have = 0;
		while ( $have < $max ) {
			$size  = min( self::PAGE, $max - $have );
			$query = $extra + array(
				'$orderby' => 'id',
				'$top'     => $size,
				'$skip'    => $skip,
			);
			if ( '' !== $filter ) {
				$query['$filter'] = $filter;
			}
			$page = $this->list( $this->get( $endpoint, $query ) );
			foreach ( $page as $row ) {
				$rows[] = $row;
			}
			$have = count( $rows );
			if ( count( $page ) < $size ) {
				break;
			}
			$skip += $size;
		}
		return $rows;
	}

	/**
	 * The first row that matches, or null.
	 *
	 * @param string $endpoint Resource.
	 * @param string $filter   `$filter` text.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function first( string $endpoint, string $filter = '' ): ?array {
		$query = array(
			'$orderby' => 'id',
			'$top'     => 1,
		);
		if ( '' !== $filter ) {
			$query['$filter'] = $filter;
		}
		$page = $this->list( $this->get( $endpoint, $query ) );
		return $page[0] ?? null;
	}

	/**
	 * One record by id, or null when it does not exist.
	 *
	 * @param string $endpoint Resource.
	 * @param string $id       Record id.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $endpoint, string $id ): ?array {
		$response = $this->api->get(
			'/' . $endpoint . '/' . rawurlencode( $id ),
			array(),
			null,
			array(
				'allow'  => array( 404 ),
				'action' => 'read ' . $endpoint,
			)
		);
		if ( 404 === $response->status() ) {
			return null;
		}
		$json = $response->json();
		return array() === $json ? null : $json;
	}

	/**
	 * Create a record.
	 *
	 * @param string              $endpoint Resource.
	 * @param array<string,mixed> $body     Body.
	 * @return array<string,mixed> The stored record (may hold only some fields).
	 *
	 * @throws ApiException On failure.
	 */
	public function create( string $endpoint, array $body ): array {
		$response = $this->api->post(
			'/' . $endpoint,
			array(),
			$body,
			array(
				'allow'  => array( 404 ),
				'action' => 'write ' . $endpoint,
			)
		);
		$this->missing( $response, $endpoint );
		return $response->json();
	}

	/**
	 * A list read.
	 *
	 * @param string              $endpoint Resource.
	 * @param array<string,mixed> $query    Query options.
	 *
	 * @throws ApiException On failure.
	 */
	private function get( string $endpoint, array $query ): ApiResponse {
		$response = $this->api->get(
			'/' . $endpoint,
			$query,
			null,
			array(
				'allow'  => array( 404 ),
				'action' => 'read ' . $endpoint,
			)
		);
		$this->missing( $response, $endpoint );
		return $response;
	}

	/**
	 * A 404 on a resource: this version of Sage 200 does not offer it.
	 *
	 * @param ApiResponse $response Response.
	 * @param string      $endpoint Resource.
	 *
	 * @throws ApiException When the status is 404.
	 */
	private function missing( ApiResponse $response, string $endpoint ): void {
		if ( 404 === $response->status() ) {
			$error = Problems::refused(
				$this->system,
				$endpoint,
				sprintf(
					/* translators: %s: resource name. */
					__( 'This Sage 200 version does not offer "%s", or the site and company chosen do not exist.', 'estasoft-erp-crm-sync-engine' ),
					$endpoint
				),
				'resource missing'
			);
			$exception = new ApiException( $error, $response );
			throw $exception;
		}
	}

	/**
	 * The rows of a list answer: a bare array or one wrapped in `value`.
	 *
	 * @param ApiResponse $response Response.
	 * @return array<int,array<string,mixed>>
	 */
	private function list( ApiResponse $response ): array {
		$data = $response->jsonValue();
		if ( is_array( $data ) && isset( $data['value'] ) && is_array( $data['value'] ) ) {
			$data = $data['value'];
		}
		$rows = array();
		foreach ( is_array( $data ) ? $data : array() as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}
}
