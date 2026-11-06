<?php
/**
 * The Sage 300 Web API behind small methods.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage300;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Lists answer `{"value": [...]}`, 100 rows at a time (`PageSize`), keys are written `ARCustomers('1200')` with doubled quotes, and a
 * body that is not JSON means the address is not the Web API.
 */
final class Sage300Client {

	/**
	 * Rows asked for in one list call.
	 */
	public const PAGE = 100;

	/**
	 * Most rows a list helper collects before it stops.
	 */
	public const MAX_ROWS = 5000;

	/**
	 * Set up with the services it uses.
	 *
	 * @param ApiClient $api    Shared client.
	 * @param string    $system System name.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $system ) {
	}

	/**
	 * A string literal for a `$filter` or a key: single quotes, embedded quotes doubled.
	 *
	 * @param string $value Text.
	 */
	public static function literal( string $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	/**
	 * The path of one record by its key.
	 *
	 * @param string $path Module and resource such as AR/ARCustomers.
	 * @param string $key  Key text.
	 */
	public static function keyed( string $path, string $key ): string {
		return $path . '(' . rawurlencode( self::literal( $key ) ) . ')';
	}

	/**
	 * Rows of a resource, collected page by page.
	 *
	 * @param string $path   Module and resource such as OE/OEOrders.
	 * @param string $filter `$filter` text, or empty.
	 * @param int    $max    Most rows to collect.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function rows( string $path, string $filter = '', int $max = self::MAX_ROWS ): array {
		$rows = array();
		$skip = 0;
		$have = 0;
		while ( $have < $max ) {
			$size  = min( self::PAGE, $max - $have );
			$query = array(
				'$top'  => $size,
				'$skip' => $skip,
			);
			if ( '' !== $filter ) {
				$query['$filter'] = $filter;
			}
			$page = $this->list( $this->api->get( '/' . $path, $query, null, array( 'action' => 'read ' . $path ) ) );
			foreach ( $page as $row ) {
				$rows[] = $row;
			}
			$have = count( $rows );
			if ( count( $page ) < $size ) {
				break;
			}
			$skip += $size;
		}
		return $rows;
	}

	/**
	 * The first row that matches, or null.
	 *
	 * @param string $path   Module and resource.
	 * @param string $filter `$filter` text.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function first( string $path, string $filter = '' ): ?array {
		$query = array( '$top' => 1 );
		if ( '' !== $filter ) {
			$query['$filter'] = $filter;
		}
		$page = $this->list( $this->api->get( '/' . $path, $query, null, array( 'action' => 'read ' . $path ) ) );
		return $page[0] ?? null;
	}

	/**
	 * One record by its path with key, or null when it does not exist.
	 *
	 * @param string $path Path such as AR/ARCustomers('1200') (see keyed()).
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure other than not found.
	 */
	public function find( string $path ): ?array {
		$response = $this->api->get(
			'/' . $path,
			array(),
			null,
			array(
				'allow'  => array( 404 ),
				'action' => 'read ' . strtok( $path, '(' ),
			)
		);
		if ( 404 === $response->status() ) {
			return null;
		}
		$json = $response->jsonValue();
		if ( ! is_array( $json ) ) {
			$this->notTheApi();
		}
		return array() === $json ? null : $json;
	}

	/**
	 * Create a record.
	 *
	 * @param string              $path Module and resource.
	 * @param array<string,mixed> $body Body.
	 * @return array<string,mixed> The inserted record.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( string $path, array $body ): array {
		$response = $this->api->post( '/' . $path, array(), $body, array( 'action' => 'write ' . $path ) );
		$json     = $response->jsonValue();
		if ( ! is_array( $json ) ) {
			$this->notTheApi();
		}
		return $json;
	}

	/**
	 * The `$metadata` document of a module.
	 *
	 * @param string $module Module such as OE.
	 *
	 * @throws ApiException On failure.
	 */
	public function metadata( string $module ): ?\SimpleXMLElement {
		$response = $this->api->get(
			'/' . $module . '/$metadata',
			array(),
			null,
			array(
				'allow'   => array( 404 ),
				'action'  => 'read metadata',
				'headers' => array( 'Accept' => 'application/xml' ),
			)
		);
		return 404 === $response->status() ? null : $response->xml();
	}

	/**
	 * The rows of a list answer.
	 *
	 * @param ApiResponse $response Response.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException When the body is not the Web API's.
	 */
	private function list( ApiResponse $response ): array {
		$data = $response->jsonValue();
		if ( ! is_array( $data ) ) {
			$this->notTheApi();
		}
		$rows = array();
		foreach ( isset( $data['value'] ) && is_array( $data['value'] ) ? $data['value'] : array() as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * The body is not JSON: this address is not the Web API.
	 *
	 * @throws ApiException Always.
	 */
	private function notTheApi(): never {
		$exception = new ApiException( Problems::refused( $this->system, 'address', __( 'This address is not a Sage 300 Web API. Check the address and the company ID.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'not the web api' ) );
		throw $exception;
	}
}
