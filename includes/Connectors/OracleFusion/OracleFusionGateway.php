<?php
/**
 * Calls of the Oracle Fusion Cloud REST API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\OracleFusion;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * The base address is `https://<pod>/fscmRestApi/resources/11.13.18.05`. Lists come back as `items` with `hasMore`, `limit` and `offset`; a
 * list is read page by page with `limit` and `offset` until `hasMore` is false. The filter is `q`, in the syntax of REST framework version 2
 * (`Attribute = 'value' and Other = 'value'`), which the connector asks for with the header `REST-Framework-Version`.
 */
final class OracleFusionGateway {

	/**
	 * Rows asked for per page.
	 */
	public const PAGE = 100;

	/**
	 * Most pages read for one list.
	 */
	public const MAX_PAGES = 100;

	/**
	 * The content type Oracle documents for bodies.
	 */
	public const BODY_TYPE = 'application/vnd.oracle.adf.resourceitem+json';

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $api Client with the base address.
	 */
	public function __construct( private readonly ApiClient $api ) {
	}

	/**
	 * A text value as a literal of the filter language: single quotes, a quote inside doubled, control characters removed.
	 *
	 * @param string $value Value.
	 */
	public function literal( string $value ): string {
		return "'" . str_replace( "'", "''", (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ) . "'";
	}

	/**
	 * Read a list page by page.
	 *
	 * @param string              $collection Resource name, for example receivablesInvoices.
	 * @param array<string,mixed> $query    q, fields, orderBy.
	 * @param int                 $max      Most rows to return.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function list( string $collection, array $query = array(), int $max = 1000 ): array {
		$rows = array();
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$limit = min( self::PAGE, max( 1, $max - count( $rows ) ) );
			$json  = $this->api->get(
				$collection,
				$query + array(
					'limit'    => $limit,
					'offset'   => count( $rows ),
					'onlyData' => 'true',
				)
			)->json();
			$items = isset( $json['items'] ) && is_array( $json['items'] ) ? $json['items'] : array();
			foreach ( $items as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			if ( array() === $items || count( $rows ) >= $max || empty( $json['hasMore'] ) ) {
				break;
			}
		}
		return array_slice( $rows, 0, $max );
	}

	/**
	 * One record by its key.
	 *
	 * @param string $collection Resource name.
	 * @param string $key      Key (a number).
	 * @return array<string,mixed>|null Null when it does not exist.
	 *
	 * @throws ApiException On a failure other than 404.
	 */
	public function one( string $collection, string $key ): ?array {
		$response = $this->api->get( $collection . '/' . rawurlencode( $key ), array( 'onlyData' => 'true' ), null, array( 'allow' => array( 404 ) ) );
		if ( 404 === $response->status() ) {
			return null;
		}
		$json = $response->json();
		return array() === $json ? null : $json;
	}

	/**
	 * Create a record with its child lines.
	 *
	 * @param string              $collection Resource name.
	 * @param array<string,mixed> $body     Body.
	 * @return array<string,mixed> The new record.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( string $collection, array $body ): array {
		return $this->api->post( $collection, array(), $body, array( 'content_type' => self::BODY_TYPE ) )->json();
	}

	/**
	 * Whether a resource can be read (a permission probe).
	 *
	 * @param string $collection Resource name.
	 * @return bool False on 403 and 404.
	 *
	 * @throws ApiException On a failure other than 403 and 404.
	 */
	public function readable( string $collection ): bool {
		return $this->api->get(
			$collection,
			array(
				'limit'    => 1,
				'onlyData' => 'true',
			),
			null,
			array( 'allow' => array( 403, 404 ) )
		)->isSuccess();
	}
}
