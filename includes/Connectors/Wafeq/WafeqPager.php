<?php
/**
 * Reads every page of a Wafeq list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Wafeq lists answer `{count, next, previous, results}` and say to follow `next` until it is null and not to build page addresses
 * yourself [documented]. A search is never answered from the first page alone.
 */
final class WafeqPager {

	/**
	 * Most pages one read follows; a longer list stops the read with an error instead of looping for ever.
	 */
	public const MAX_PAGES = 200;

	/**
	 * Rows per page asked for.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * One page of a list: the first page of a path, or the page a `next` address names.
	 *
	 * @param ApiClient           $api   Client.
	 * @param string              $path  List path such as `items/`.
	 * @param string|null         $next  The `next` address of the previous page, or null for the first page.
	 * @param array<string,mixed> $query Filters of the first page.
	 * @return array{rows:array<int,array<string,mixed>>,next:string} `next` is empty on the last page.
	 *
	 * @throws ApiException When the request fails.
	 */
	public function page( ApiClient $api, string $path, ?string $next = null, array $query = array() ): array {
		$response = null === $next ? $api->get( $path, $query + array( 'page_size' => self::PAGE_SIZE ) ) : $api->get( $next );
		$json     = $response->json();
		$rows     = array();
		foreach ( (array) ( $json['results'] ?? array() ) as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		$after = $json['next'] ?? null;
		return array(
			'rows' => $rows,
			'next' => is_string( $after ) ? $after : '',
		);
	}

	/**
	 * All rows of a list.
	 *
	 * @param ApiClient           $api   Client.
	 * @param string              $path  List path such as `invoices/`.
	 * @param array<string,mixed> $query Filters.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException When a request fails or the list is longer than MAX_PAGES pages.
	 */
	public function all( ApiClient $api, string $path, array $query = array() ): array {
		$rows     = array();
		$query    = $query + array( 'page_size' => self::PAGE_SIZE );
		$response = $api->get( $path, $query );
		for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
			$json = $response->json();
			foreach ( (array) ( $json['results'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			$next = $json['next'] ?? null;
			if ( ! is_string( $next ) || '' === $next ) {
				return $rows;
			}
			$response = $api->get( $next );
		}
		$error = new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => WafeqConnector::LABEL,
				'field'  => $path,
				'reason' => __( 'The list is longer than the connector reads in one pass. Narrow the search.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			null,
			null,
			null,
			'list too long'
		);
		throw new ApiException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
	}
}
