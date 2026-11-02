<?php
/**
 * Request helpers: the JSON filter parameter and the cursor.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Pennylane;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * List endpoints take `filter`, a JSON array of `{field, operator, value}` objects, and `limit` (1 to 100) and `cursor`; they answer `items`,
 * `has_more` and `next_cursor` [documented]. The cursor does not hold the filter, so the same filter is sent with every page.
 */
final class PennylaneApi {

	/**
	 * Rows per page.
	 */
	public const LIMIT = 100;

	/**
	 * One page of a list.
	 *
	 * @param ApiClient                                   $api     Client.
	 * @param string                                      $path    List path such as customer_invoices.
	 * @param array<int,array{0:string,1:string,2:mixed}> $filters Filters as field, operator, value.
	 * @param string|null                                 $cursor  Cursor from the previous page, or null for the first.
	 * @return array{items:array<int,array<string,mixed>>,next:?string}
	 *
	 * @throws ApiException On failure.
	 */
	public function page( ApiClient $api, string $path, array $filters, ?string $cursor ): array {
		$query = array( 'limit' => self::LIMIT );
		if ( array() !== $filters ) {
			$query['filter'] = $this->filter( $filters );
		}
		if ( null !== $cursor && '' !== $cursor ) {
			$query['cursor'] = $cursor;
		}
		$json  = $api->get( $path, $query )->json();
		$items = array();
		foreach ( (array) ( $json['items'] ?? array() ) as $item ) {
			if ( is_array( $item ) ) {
				$items[] = $item;
			}
		}
		$next = ! empty( $json['has_more'] ) && is_string( $json['next_cursor'] ?? null ) && '' !== $json['next_cursor'] ? $json['next_cursor'] : null;
		return array(
			'items' => $items,
			'next'  => $next,
		);
	}

	/**
	 * Every row of a list, page by page.
	 *
	 * @param ApiClient                                   $api      Client.
	 * @param string                                      $path     List path.
	 * @param array<int,array{0:string,1:string,2:mixed}> $filters Filters.
	 * @param int                                         $maxPages Most pages read.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function all( ApiClient $api, string $path, array $filters, int $maxPages = 30 ): array {
		$rows   = array();
		$cursor = null;
		for ( $i = 0; $i < $maxPages; $i++ ) {
			$page   = $this->page( $api, $path, $filters, $cursor );
			$rows   = array_merge( $rows, $page['items'] );
			$cursor = $page['next'];
			if ( null === $cursor ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * The filter parameter as JSON text.
	 *
	 * @param array<int,array{0:string,1:string,2:mixed}> $filters Filters as field, operator, value.
	 */
	public function filter( array $filters ): string {
		$list = array();
		foreach ( $filters as $filter ) {
			$list[] = array(
				'field'    => $filter[0],
				'operator' => $filter[1],
				'value'    => $filter[2],
			);
		}
		return (string) wp_json_encode( $list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
