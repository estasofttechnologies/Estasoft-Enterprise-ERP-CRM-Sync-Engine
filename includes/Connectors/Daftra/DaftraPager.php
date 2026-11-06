<?php
/**
 * Reads every page of a Daftra list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Daftra lists answer `{result, code, data: [{"Client": {...}}], pagination: {page, page_count, total_results, next}}` and take `page` and `limit`
 * [documented]. The pager asks for page 1, 2, 3 ... and stops after the last page the answer names, at an empty page, or at a page that adds no
 * record it has not seen (a server that ignores the parameter).
 */
final class DaftraPager {

	/**
	 * Most pages one read follows.
	 */
	public const MAX_PAGES = 100;

	/**
	 * Rows per page asked for.
	 */
	public const LIMIT = 100;

	/**
	 * All rows of a list.
	 *
	 * @param ApiClient           $api   Client.
	 * @param string              $path  List path such as `clients.json`.
	 * @param array<string,mixed> $query Filters.
	 * @param string              $key   Record name each row is wrapped in (Client).
	 * @return array<int,array<string,mixed>> The inner records.
	 *
	 * @throws ApiException When a request fails or the list is longer than MAX_PAGES pages.
	 */
	public function all( ApiClient $api, string $path, array $query, string $key ): array {
		$rows = array();
		$seen = array();
		for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
			$json  = $api->get(
				$path,
				$query + array(
					'limit' => self::LIMIT,
					'page'  => $page,
				)
			)->json();
			$data  = $json['data'] ?? array();
			$fresh = 0;
			foreach ( is_array( $data ) ? $data : array() as $entry ) {
				$row = is_array( $entry ) ? ( $entry[ $key ] ?? $entry ) : null;
				if ( ! is_array( $row ) ) {
					continue;
				}
				$id = DaftraPayloadReader::text( $row['id'] ?? '' );
				if ( '' !== $id && isset( $seen[ $id ] ) ) {
					continue;
				}
				if ( '' !== $id ) {
					$seen[ $id ] = true;
				}
				$rows[] = $row;
				++$fresh;
			}
			$last = (int) ( is_array( $json['pagination'] ?? null ) ? ( $json['pagination']['page_count'] ?? 0 ) : 0 );
			if ( 0 === $fresh || ( $last > 0 && $page >= $last ) ) {
				return $rows;
			}
		}
		$error = new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => DaftraConnector::LABEL,
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
