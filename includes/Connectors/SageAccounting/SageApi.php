<?php
/**
 * The Sage Accounting API v3.1 calls the connector makes: lists with paging, creates and the draft release.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Wraps the shared client of one business (the business is chosen by the X-Business header the connector adds to every call). A list answer is
 * {"$items":[...],"$next":...,"$total":...}; this class reads every page with page and items_per_page (200 is Sage's largest). The envelope is inferred
 * from the guides, so a plain list is accepted too.
 */
final class SageApi {

	/**
	 * Rows asked for per page.
	 */
	public const PAGE_SIZE = 200;

	/**
	 * Pages read at most (a safety stop: 20,000 rows).
	 */
	public const MAX_PAGES = 100;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $client Client for the business.
	 */
	public function __construct( private readonly ApiClient $client ) {
	}

	/**
	 * GET.
	 *
	 * @param string              $path    Path under the API root.
	 * @param array<string,mixed> $query   Query parameters.
	 * @param array<string,mixed> $options Client options (allow).
	 *
	 * @throws ApiException On a failed call.
	 */
	public function get( string $path, array $query = array(), array $options = array() ): ApiResponse {
		return $this->client->get( $path, $query, null, $options );
	}

	/**
	 * POST a JSON object.
	 *
	 * @param string              $path Path under the API root.
	 * @param array<string,mixed> $body Body.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function post( string $path, array $body = array() ): ApiResponse {
		return $this->client->post( $path, array(), array() === $body ? null : $body );
	}

	/**
	 * DELETE.
	 *
	 * @param string              $path Path under the API root.
	 * @param array<string,mixed> $body Body (a void reason), or empty.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function delete( string $path, array $body = array() ): ApiResponse {
		return $this->client->delete( $path, array(), array() === $body ? null : $body );
	}

	/**
	 * All rows of a list.
	 *
	 * @param string              $path     Path such as sales_invoices.
	 * @param array<string,mixed> $query    Filters.
	 * @param int                 $maxPages Most pages to read.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function select( string $path, array $query = array(), int $maxPages = self::MAX_PAGES ): array {
		$rows = array();
		for ( $page = 1; $page <= $maxPages; ++$page ) {
			$batch = self::items(
				$this->get(
					$path,
					$query + array(
						'items_per_page' => self::PAGE_SIZE,
						'page'           => $page,
					)
				)
			);
			foreach ( $batch as $row ) {
				$rows[] = $row;
			}
			if ( count( $batch ) < self::PAGE_SIZE ) {
				return $rows;
			}
		}
		return $rows;
	}

	/**
	 * The rows of a list answer.
	 *
	 * @param ApiResponse $response Response.
	 * @return array<int,array<string,mixed>>
	 */
	public static function items( ApiResponse $response ): array {
		$data = $response->jsonValue();
		$list = is_array( $data ) && isset( $data['$items'] ) && is_array( $data['$items'] ) ? $data['$items'] : ( is_array( $data ) && \Estasoft\SyncEngine\Core\Support\Lists::isList( $data ) ? $data : array() );
		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * The object an answer holds.
	 *
	 * @param ApiResponse $response Response to a create, release or read.
	 * @param string      $what     What it should be, for the message.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException When the answer holds no object with an id.
	 */
	public function entity( ApiResponse $response, string $what ): array {
		$data = $response->json();
		if ( isset( $data['id'] ) && is_scalar( $data['id'] ) ) {
			return $data;
		}
		$failure = SageErrors::exception( SageErrors::refused( $what, __( 'Sage answered without the record.', 'estasoft-enterprise-erpcrm-sync-engine' ), $response->status() ), $response );
		throw $failure;
	}
}
