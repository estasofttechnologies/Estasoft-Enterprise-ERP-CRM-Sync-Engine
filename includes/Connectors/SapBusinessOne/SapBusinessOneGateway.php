<?php
/**
 * The calls to the Service Layer the connector makes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapBusinessOne;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Entity reads with OData paging, creates and actions. A record that does not exist answers 404 and comes back as null. Values that go
 * into a filter are quoted by literal(), which doubles single quotes; entity names and property names are fixed strings of the connector.
 */
final class SapBusinessOneGateway {

	/**
	 * Page size asked for with `Prefer: odata.maxpagesize`.
	 */
	public const PAGE = 200;

	/**
	 * Most pages read for one list.
	 */
	public const MAX_PAGES = 50;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $api     Client with the base address `https://host:port/b1s/v2`.
	 * @param string    $version v1 or v2.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $version ) {
	}

	/**
	 * A text value as an OData string literal.
	 *
	 * @param string $value Value.
	 */
	public function literal( string $value ): string {
		return "'" . str_replace( "'", "''", (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ) . "'";
	}

	/**
	 * A date as an OData literal for the service version.
	 *
	 * @param string $date Y-m-d.
	 */
	public function dateLiteral( string $date ): string {
		return 'v1' === $this->version ? "'" . $date . "'" : $date;
	}

	/**
	 * Read a list, following the next-page links.
	 *
	 * @param string              $entity Entity name, for example Orders.
	 * @param array<string,mixed> $query  OData options without the dollar sign: filter, select, orderby, top.
	 * @param int                 $max    Most rows to return.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function list( string $entity, array $query = array(), int $max = 1000 ): array {
		$params = array();
		foreach ( $query as $name => $value ) {
			$params[ '$' . $name ] = $value;
		}
		$rows    = array();
		$path    = $entity;
		$options = array( 'headers' => array( 'Prefer' => 'odata.maxpagesize=' . self::PAGE ) );
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$response = $this->api->get( $path, 0 === $page ? $params : array(), null, $options );
			$json     = $response->json();
			foreach ( (array) ( $json['value'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			$next = (string) ( $json['@odata.nextLink'] ?? ( $json['odata.nextLink'] ?? '' ) );
			if ( '' === $next || count( $rows ) >= $max ) {
				break;
			}
			$path = $this->relative( $next );
		}
		return array_slice( $rows, 0, $max );
	}

	/**
	 * One entity by its key, or null when it does not exist.
	 *
	 * @param string              $entity Entity name.
	 * @param string              $key    Key as it goes between the brackets (a number, or a quoted string made by literal()).
	 * @param array<string,mixed> $query  OData options without the dollar sign.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failure other than 404.
	 */
	public function one( string $entity, string $key, array $query = array() ): ?array {
		$params = array();
		foreach ( $query as $name => $value ) {
			$params[ '$' . $name ] = $value;
		}
		$response = $this->api->get( $entity . '(' . rawurlencode( $key ) . ')', $params, null, array( 'allow' => array( 404 ) ) );
		return 404 === $response->status() ? null : $response->json();
	}

	/**
	 * Create an entity.
	 *
	 * @param string              $entity Entity name.
	 * @param array<string,mixed> $body   Body.
	 * @return array<string,mixed> The new entity.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( string $entity, array $body ): array {
		return $this->api->post( $entity, array(), $body )->json();
	}

	/**
	 * Whether a collection can be read (a permission probe).
	 *
	 * @param string $entity Entity name.
	 * @param string $select A property to select.
	 * @return bool False on 403 and 404.
	 *
	 * @throws ApiException On a failure other than 403 and 404.
	 */
	public function readable( string $entity, string $select ): bool {
		$response = $this->api->get(
			$entity,
			array(
				'$top'    => 1,
				'$select' => $select,
			),
			null,
			array( 'allow' => array( 403, 404 ) )
		);
		return $response->isSuccess();
	}

	/**
	 * The raw answer of a request (for the session tests and the login).
	 *
	 * @param string              $path Path.
	 * @param array<string,mixed> $body Body.
	 *
	 * @throws ApiException On failure.
	 */
	public function post( string $path, array $body ): ApiResponse {
		return $this->api->post( $path, array(), $body );
	}

	/**
	 * A next-page link as a path under the service root.
	 *
	 * @param string $link Link from the answer.
	 */
	private function relative( string $link ): string {
		$position = strpos( $link, '/b1s/' );
		if ( false !== $position ) {
			$link     = substr( $link, $position + 5 );
			$segments = explode( '/', $link, 2 );
			$link     = $segments[1] ?? $segments[0];
		}
		return ltrim( $link, '/' );
	}
}
