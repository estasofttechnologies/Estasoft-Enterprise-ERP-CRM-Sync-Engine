<?php
/**
 * The OData V2 calls to the S/4HANA APIs, with CSRF tokens for writes and ETags for deletes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapS4Hana;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Reads use `$filter`, `$select`, `$orderby`, `$top` and `$skip` and unwrap `d.results` (lists) and `d` (one entity). A write first fetches a CSRF
 * token with `GET <service>/` and `X-CSRF-Token: Fetch`, sends the token and the session cookies with the write, and fetches a new token once
 * when the answer is 403 with `x-csrf-token: Required`. A delete sends the entity's ETag as `If-Match`.
 */
final class SapS4HanaGateway {

	/**
	 * Rows asked for per page.
	 */
	public const PAGE = 100;

	/**
	 * Most pages read for one list.
	 */
	public const MAX_PAGES = 50;

	/**
	 * CSRF token and cookies per service, for the length of the request.
	 *
	 * @var array<string,array{token:string,cookie:string}>
	 */
	private array $sessions = array();

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $api Client with the base address `https://host/sap/opu/odata/sap`.
	 */
	public function __construct( private readonly ApiClient $api ) {
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
	 * A date as an OData V2 literal for a filter.
	 *
	 * @param string $date Y-m-d.
	 */
	public function dateLiteral( string $date ): string {
		return "datetime'" . $date . "T00:00:00'";
	}

	/**
	 * A date as the V2 JSON value (`/Date(milliseconds)/`, midnight UTC).
	 *
	 * @param string $date Y-m-d.
	 */
	public function jsonDate( string $date ): string {
		return '/Date(' . ( (int) strtotime( $date . ' 00:00:00 UTC' ) * 1000 ) . ')/';
	}

	/**
	 * The day (Y-m-d) of a V2 JSON date value, or an empty string.
	 *
	 * @param mixed $value Value from an answer.
	 */
	public function readDate( mixed $value ): string {
		if ( is_string( $value ) && 1 === preg_match( '#^/Date\((-?\d+)#', $value, $match ) ) {
			return gmdate( 'Y-m-d', intdiv( (int) $match[1], 1000 ) );
		}
		return is_string( $value ) && 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $match ) ? $match[1] : '';
	}

	/**
	 * Read a list with `$top` and `$skip` until a page comes back short.
	 *
	 * @param string              $service Service name, for example API_SALES_ORDER_SRV.
	 * @param string              $entity  Entity set.
	 * @param array<string,mixed> $query   OData options without the dollar sign: filter, select, orderby.
	 * @param int                 $max     Most rows to return.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function list( string $service, string $entity, array $query = array(), int $max = 1000 ): array {
		$rows = array();
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$params = array();
			foreach ( $query as $name => $value ) {
				$params[ '$' . $name ] = $value;
			}
			$params['$top']  = min( self::PAGE, max( 1, $max - count( $rows ) ) );
			$params['$skip'] = $page * self::PAGE;
			$json            = $this->api->get( $service . '/' . $entity, $params )->json();
			$results         = $json['d']['results'] ?? array();
			foreach ( (array) $results as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			if ( count( (array) $results ) < $params['$top'] || count( $rows ) >= $max ) {
				break;
			}
		}
		return array_slice( $rows, 0, $max );
	}

	/**
	 * One entity by key, with its ETag.
	 *
	 * @param string              $service Service name.
	 * @param string              $entity  Entity set.
	 * @param string              $key     Key value (quoted by this method).
	 * @param array<string,mixed> $query   OData options without the dollar sign.
	 * @return array{data:array<string,mixed>,etag:string}|null Null when it does not exist.
	 *
	 * @throws ApiException On a failure other than 404.
	 */
	public function one( string $service, string $entity, string $key, array $query = array() ): ?array {
		$params = array();
		foreach ( $query as $name => $value ) {
			$params[ '$' . $name ] = $value;
		}
		$response = $this->api->get( $service . '/' . $entity . '(' . rawurlencode( $this->literal( $key ) ) . ')', $params, null, array( 'allow' => array( 404 ) ) );
		if ( 404 === $response->status() ) {
			return null;
		}
		$json = $response->json();
		return array(
			'data' => isset( $json['d'] ) && is_array( $json['d'] ) ? $json['d'] : array(),
			'etag' => (string) $response->header( 'etag' ),
		);
	}

	/**
	 * Create an entity (with a deep insert of its items).
	 *
	 * @param string              $service Service name.
	 * @param string              $entity  Entity set.
	 * @param array<string,mixed> $body    Body.
	 * @return array<string,mixed> The new entity (the content of `d`).
	 *
	 * @throws ApiException On failure.
	 */
	public function create( string $service, string $entity, array $body ): array {
		$json = $this->write( 'POST', $service, $entity, $body )->json();
		return isset( $json['d'] ) && is_array( $json['d'] ) ? $json['d'] : array();
	}

	/**
	 * Delete an entity with its current ETag.
	 *
	 * @param string $service Service name.
	 * @param string $entity  Entity set.
	 * @param string $key     Key value.
	 * @param string $keyName Name of the key property (read to get the ETag).
	 * @return bool True when it is gone; false when it could not be deleted.
	 */
	public function delete( string $service, string $entity, string $key, string $keyName = 'SalesOrder' ): bool {
		try {
			$found = $this->one( $service, $entity, $key, array( 'select' => $keyName ) );
			if ( null === $found ) {
				return true;
			}
			$this->write( 'DELETE', $service, $entity . '(' . rawurlencode( $this->literal( $key ) ) . ')', null, '' !== $found['etag'] ? $found['etag'] : '*' );
			return true;
		} catch ( ApiException $exception ) {
			unset( $exception );
			return false;
		}
	}

	/**
	 * Whether an entity set can be read (a permission probe).
	 *
	 * @param string $service Service name.
	 * @param string $entity  Entity set.
	 * @param string $select  A property to select.
	 * @return bool False on 403 and 404.
	 *
	 * @throws ApiException On a failure other than 403 and 404.
	 */
	public function readable( string $service, string $entity, string $select ): bool {
		return $this->api->get(
			$service . '/' . $entity,
			array(
				'$top'    => 1,
				'$select' => $select,
			),
			null,
			array( 'allow' => array( 403, 404 ) )
		)->isSuccess();
	}

	/**
	 * A write with the CSRF token and cookies of the service.
	 *
	 * @param string $method  POST, PATCH or DELETE.
	 * @param string $service Service name.
	 * @param string $path    Path under the service.
	 * @param mixed  $body    Body, or null.
	 * @param string $etag    ETag for If-Match, or empty.
	 *
	 * @throws ApiException On failure.
	 */
	private function write( string $method, string $service, string $path, mixed $body, string $etag = '' ): ApiResponse {
		for ( $attempt = 0; ; ++$attempt ) {
			$session = $this->session( $service, $attempt > 0 );
			$headers = array( 'X-CSRF-Token' => $session['token'] );
			if ( '' !== $session['cookie'] ) {
				$headers['Cookie'] = $session['cookie'];
			}
			if ( '' !== $etag ) {
				$headers['If-Match'] = $etag;
			}
			try {
				return $this->api->request( $method, $service . '/' . $path, array(), $body, array( 'headers' => $headers ) );
			} catch ( ApiException $exception ) {
				$required = 403 === $exception->status() && 'required' === strtolower( (string) $exception->response()?->header( 'x-csrf-token' ) );
				if ( ! $required || $attempt > 0 ) {
					throw $exception;
				}
			}
		}
	}

	/**
	 * The CSRF token and session cookies of a service.
	 *
	 * @param string $service Service name.
	 * @param bool   $renew   True to fetch a new token.
	 * @return array{token:string,cookie:string}
	 *
	 * @throws ApiException When no token comes back.
	 */
	private function session( string $service, bool $renew ): array {
		if ( ! $renew && isset( $this->sessions[ $service ] ) ) {
			return $this->sessions[ $service ];
		}
		$response = $this->api->get( $service . '/', array(), null, array( 'headers' => array( 'X-CSRF-Token' => 'Fetch' ) ) );
		$token    = trim( (string) $response->header( 'x-csrf-token' ) );
		if ( '' === $token || 'required' === strtolower( $token ) ) {
			$problem = Problem::validation( 'SAP S/4HANA', 'X-CSRF-Token', __( 'The system did not give a token for writing. Check the communication arrangement of this service.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$this->api->redaction()->add( $token );
		$this->sessions[ $service ] = array(
			'token'  => $token,
			'cookie' => $this->cookies( (string) $response->header( 'set-cookie' ) ),
		);
		return $this->sessions[ $service ];
	}

	/**
	 * The `name=value` pairs of the cookies in a (possibly combined) Set-Cookie header, as one Cookie header value.
	 *
	 * @param string $header Set-Cookie header text.
	 */
	private function cookies( string $header ): string {
		if ( '' === $header || false === preg_match_all( '/(?:^|,\s*)([A-Za-z0-9_.\-]+)=([^;,\s]*)/', $header, $matches, PREG_SET_ORDER ) ) {
			return '';
		}
		$pairs = array();
		foreach ( $matches as $match ) {
			if ( ! in_array( strtolower( $match[1] ), array( 'expires', 'path', 'domain', 'max-age', 'samesite', 'secure', 'httponly', 'version' ), true ) ) {
				$pairs[] = $match[1] . '=' . $match[2];
			}
		}
		return implode( '; ', $pairs );
	}
}
