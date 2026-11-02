<?php
/**
 * Reads every page of a Qoyod list.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Qoyod's documentation describes searching (Ransack, `q[sku_cont]=acc`) and says nothing about pages. The connector therefore asks for
 * `page=1, 2, 3 ...` and stops at the first page that is empty or adds no record it has not seen yet, which also covers a server that ignores
 * the parameter and answers the same list every time.
 */
final class QoyodPager {

	/**
	 * Most pages one read follows.
	 */
	public const MAX_PAGES = 100;

	/**
	 * All rows of a list.
	 *
	 * @param ApiClient           $api   Client.
	 * @param string              $path  List path such as `invoices`.
	 * @param array<string,mixed> $query Ransack filters (`q`) and other parameters.
	 * @param string              $root  Key that holds the rows in the answer (`invoices`).
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException When a request fails or the list is longer than MAX_PAGES pages.
	 */
	public function all( ApiClient $api, string $path, array $query, string $root ): array {
		$rows = array();
		$seen = array();
		for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
			$json  = $api->get( $path, $query + array( 'page' => $page ) )->json();
			$batch = $json[ $root ] ?? ( array_is_list( $json ) ? $json : array() );
			$fresh = 0;
			foreach ( (array) $batch as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$id = QoyodPayloadReader::text( $row['id'] ?? '' );
				if ( '' !== $id && isset( $seen[ $id ] ) ) {
					continue;
				}
				if ( '' !== $id ) {
					$seen[ $id ] = true;
				}
				$rows[] = $row;
				++$fresh;
			}
			if ( 0 === $fresh ) {
				return $rows;
			}
		}
		$error = new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => QoyodConnector::LABEL,
				'field'  => $path,
				'reason' => __( 'The list is longer than the connector reads in one pass. Narrow the search.', 'estasoft-erp-crm-sync-engine' ),
			),
			null,
			null,
			null,
			'list too long'
		);
		throw new ApiException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
	}
}
