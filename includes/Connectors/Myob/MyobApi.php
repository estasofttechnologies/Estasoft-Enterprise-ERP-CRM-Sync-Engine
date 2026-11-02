<?php
/**
 * The MYOB Business API calls the connector makes: lists with paging, creates that answer with a Location header, and OData values.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Wraps the shared client of one company file (the base address already holds /accountright/{file id}). A list answer is
 * {"Items":[...],"NextPageLink":...,"Count":n}; this class reads every page with $top and $skip. A create answers 201 with the address of the new
 * record in the Location header and no body, so the record is read with a second call. Values in a $filter are quoted here and nowhere else.
 */
final class MyobApi {

	/**
	 * Rows asked for per page (MYOB allows up to 1,000 for most lists).
	 */
	public const PAGE_SIZE = 1000;

	/**
	 * Pages read at most (a safety stop: 100,000 rows).
	 */
	public const MAX_PAGES = 100;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $client Client for the company file.
	 */
	public function __construct( private readonly ApiClient $client ) {
	}

	/**
	 * GET.
	 *
	 * @param string              $path    Path under the company file.
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
	 * @param string              $path Path under the company file.
	 * @param array<string,mixed> $body Body.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function post( string $path, array $body ): ApiResponse {
		return $this->client->post( $path, array(), $body );
	}

	/**
	 * DELETE.
	 *
	 * @param string $path Path under the company file.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function delete( string $path ): ApiResponse {
		return $this->client->delete( $path );
	}

	/**
	 * The address of any company file's list under the host, for the one call that has no company file yet.
	 *
	 * @param string $url Absolute address.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function getAbsolute( string $url ): ApiResponse {
		return $this->client->get( $url );
	}

	/**
	 * All rows of a list.
	 *
	 * @param string $path     Path under the company file, such as Sale/Invoice/Item.
	 * @param string $filter   A $filter value (already quoted with quote()), or empty.
	 * @param int    $maxPages Most pages to read.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function select( string $path, string $filter = '', int $maxPages = self::MAX_PAGES ): array {
		$rows = array();
		for ( $page = 0; $page < $maxPages; ++$page ) {
			$query = array(
				'$top'  => self::PAGE_SIZE,
				'$skip' => $page * self::PAGE_SIZE,
			);
			if ( '' !== $filter ) {
				$query['$filter'] = $filter;
			}
			$batch = self::items( $this->get( $path, $query ) );
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
		$list = is_array( $data ) && isset( $data['Items'] ) && is_array( $data['Items'] ) ? $data['Items'] : ( is_array( $data ) && \Estasoft\SyncEngine\Core\Support\Lists::isList( $data ) ? $data : array() );
		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * The id of a new record from the Location header (the last part of the address).
	 *
	 * @param ApiResponse $response The answer to a create.
	 */
	public static function uidFromLocation( ApiResponse $response ): string {
		$location = (string) $response->header( 'Location' );
		$path     = (string) wp_parse_url( $location, PHP_URL_PATH );
		$last     = '' === $path ? '' : (string) substr( strrchr( '/' . trim( $path, '/' ), '/' ), 1 );
		return 1 === preg_match( '/^[0-9a-fA-F]{8}(-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/', $last ) ? strtolower( $last ) : '';
	}

	/**
	 * A text as a quoted OData string: single quotes are doubled, control characters become spaces.
	 *
	 * @param string $value Text.
	 */
	public static function quote( string $value ): string {
		$clean = preg_replace( '/[\x00-\x1F]/', ' ', $value ) ?? '';
		return "'" . str_replace( "'", "''", $clean ) . "'";
	}
}
