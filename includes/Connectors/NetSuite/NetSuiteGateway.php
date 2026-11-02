<?php
/**
 * The calls to NetSuite REST Web Services the connector makes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Record calls and SuiteQL with paging. Every call is pinned to the account through the client's base address. A record that does not exist
 * answers 404 NONEXISTENT_ID and is returned as null, not thrown.
 */
final class NetSuiteGateway {

	/**
	 * Rows asked for per SuiteQL page.
	 */
	public const PAGE = 200;

	/**
	 * Most pages read for one query (10,000 rows).
	 */
	public const MAX_PAGES = 50;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $api Client with the base address `https://<account>.suitetalk.api.netsuite.com/services/rest`.
	 */
	public function __construct( private readonly ApiClient $api ) {
	}

	/**
	 * A record by its internal id.
	 *
	 * @param string            $type   Record type, for example salesOrder.
	 * @param string            $id     Internal id.
	 * @param array<int,string> $fields Fields to return.
	 * @return array<string,mixed>|null Null when it does not exist.
	 *
	 * @throws ApiException On a failure other than 404.
	 */
	public function record( string $type, string $id, array $fields = array() ): ?array {
		return $this->read( 'record/v1/' . rawurlencode( $type ) . '/' . rawurlencode( $id ), $fields );
	}

	/**
	 * A record by its external id.
	 *
	 * @param string            $type       Record type.
	 * @param string            $externalId External id.
	 * @param array<int,string> $fields     Fields to return.
	 * @return array<string,mixed>|null Null when none has that external id.
	 *
	 * @throws ApiException On a failure other than 404.
	 */
	public function byExternalId( string $type, string $externalId, array $fields = array() ): ?array {
		return $this->read( 'record/v1/' . rawurlencode( $type ) . '/eid:' . rawurlencode( $externalId ), $fields );
	}

	/**
	 * Send a write and give back the answer.
	 *
	 * @param string              $method POST, PUT or PATCH.
	 * @param string              $path   Path under the base address.
	 * @param array<string,mixed> $body   Body.
	 *
	 * @throws ApiException On failure.
	 */
	public function write( string $method, string $path, array $body ): ApiResponse {
		return $this->api->request( $method, $path, array(), array() === $body ? new \stdClass() : $body );
	}

	/**
	 * Whether a collection can be read (a permission or feature probe).
	 *
	 * @param string $path Path such as record/v1/salesOrder.
	 * @return bool False on 403 or 404; other failures are thrown.
	 *
	 * @throws ApiException On a failure other than 403 and 404.
	 */
	public function readable( string $path ): bool {
		$response = $this->api->get( $path, array( 'limit' => 1 ), null, array( 'allow' => array( 403, 404 ) ) );
		return $response->isSuccess();
	}

	/**
	 * Run a SuiteQL query and read every page.
	 *
	 * @param string            $sql    Query; values go in $params as `?`.
	 * @param array<int,string> $params Bound values.
	 * @param int               $max    Most rows to return.
	 * @return array<int,array<string,mixed>> Rows without their `links`.
	 *
	 * @throws ApiException On failure.
	 */
	public function rows( string $sql, array $params = array(), int $max = 2000 ): array {
		$rows   = array();
		$offset = 0;
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$body = array( 'q' => $sql );
			if ( array() !== $params ) {
				$body['params'] = array_values( $params );
			}
			$response = $this->api->post(
				'query/v1/suiteql',
				array(
					'limit'  => self::PAGE,
					'offset' => $offset,
				),
				$body,
				array( 'headers' => array( 'Prefer' => 'transient' ) )
			);
			$json     = $response->json();
			$items    = isset( $json['items'] ) && is_array( $json['items'] ) ? $json['items'] : array();
			foreach ( $items as $item ) {
				if ( is_array( $item ) ) {
					unset( $item['links'] );
					$rows[] = $item;
				}
			}
			$more = ! empty( $json['hasMore'] );
			if ( ! $more || array() === $items || count( $rows ) >= $max ) {
				break;
			}
			$offset += count( $items );
		}
		return array_slice( $rows, 0, $max );
	}

	/**
	 * The internal id from the `Location` header of a create or update (its last path segment).
	 *
	 * @param ApiResponse $response Answer of a write.
	 */
	public function idFromLocation( ApiResponse $response ): string {
		$location = (string) $response->header( 'location' );
		$path     = (string) wp_parse_url( $location, PHP_URL_PATH );
		$segment  = basename( rtrim( $path, '/' ) );
		return 1 === preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $segment ) ? $segment : '';
	}

	/**
	 * Read a path, treating 404 as "none".
	 *
	 * @param string            $path   Path.
	 * @param array<int,string> $fields Fields.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failure other than 404.
	 */
	private function read( string $path, array $fields ): ?array {
		$query    = array() === $fields ? array() : array( 'fields' => implode( ',', $fields ) );
		$response = $this->api->get( $path, $query, null, array( 'allow' => array( 404 ) ) );
		if ( 404 === $response->status() ) {
			return null;
		}
		return $response->json();
	}
}
