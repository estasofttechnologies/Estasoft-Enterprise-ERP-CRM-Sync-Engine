<?php
/**
 * Reads every page of a Xero list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Invoices, credit notes and contacts come 100 to a page with a pagination block ({page, pageSize, pageCount, itemCount}, see the examples in
 * Xero's OpenAPI file). This follows `page` until the pages run out. A search never takes the first page and stops.
 */
final class XeroPaging {

	/**
	 * Rows asked for per page (Xero's page size for these lists).
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Pages read at most (a safety stop: 10,000 rows).
	 */
	public const MAX_PAGES = 100;

	/**
	 * All rows of a list.
	 *
	 * @param ApiClient           $api    Client.
	 * @param string              $path   Path under the API root, such as Invoices.
	 * @param array<string,mixed> $query  Query parameters.
	 * @param string              $key    Key of the rows in the answer, such as Invoices.
	 * @param bool                $stop   Set to true when the safety stop was reached.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function all( ApiClient $api, string $path, array $query, string $key, bool &$stop = false ): array {
		$rows = array();
		for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
			$data  = $api->get(
				$path,
				$query + array(
					'page'     => $page,
					'pageSize' => self::PAGE_SIZE,
				)
			)->json();
			$batch = isset( $data[ $key ] ) && is_array( $data[ $key ] ) ? array_values( array_filter( $data[ $key ], 'is_array' ) ) : array();
			foreach ( $batch as $row ) {
				$rows[] = $row;
			}
			$count = isset( $data['pagination']['pageCount'] ) && is_numeric( $data['pagination']['pageCount'] ) ? (int) $data['pagination']['pageCount'] : 0;
			if ( count( $batch ) < self::PAGE_SIZE || ( $count > 0 && $page >= $count ) ) {
				return $rows;
			}
		}
		$stop = true;
		return $rows;
	}
}
