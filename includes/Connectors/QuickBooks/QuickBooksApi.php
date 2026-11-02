<?php
/**
 * The QuickBooks Online calls the connector makes: entities, the query language and the minor version.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Wraps the shared client of one company (the base address already holds /v3/company/{realmId}). Every call carries minorversion. A query
 * reads every page with STARTPOSITION and MAXRESULTS (100 a page here; Intuit allows up to 1,000). Values in a query are quoted and escaped here
 * and nowhere else.
 */
final class QuickBooksApi {

	/**
	 * Rows asked for per page.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Pages read at most (a safety stop: 10,000 rows).
	 */
	public const MAX_PAGES = 100;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $client       Client for the company.
	 * @param int       $minorVersion API minor version.
	 */
	public function __construct( private readonly ApiClient $client, private readonly int $minorVersion ) {
	}

	/**
	 * GET.
	 *
	 * @param string              $path    Path under the company.
	 * @param array<string,mixed> $query   Query parameters.
	 * @param array<string,mixed> $options Client options (allow).
	 *
	 * @throws ApiException On a failed call.
	 */
	public function get( string $path, array $query = array(), array $options = array() ): ApiResponse {
		return $this->client->get( $path, $query + array( 'minorversion' => $this->minorVersion ), null, $options );
	}

	/**
	 * POST a JSON object.
	 *
	 * @param string              $path      Path under the company.
	 * @param array<string,mixed> $body      Body.
	 * @param array<string,mixed> $query     Query parameters (operation=delete).
	 * @param string              $requestId Value for the requestid parameter (a retry with the same value replays the first answer), or empty.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function post( string $path, array $body, array $query = array(), string $requestId = '' ): ApiResponse {
		$query['minorversion'] = $this->minorVersion;
		if ( '' !== $requestId ) {
			$query['requestid'] = $requestId;
		}
		return $this->client->post( $path, $query, $body );
	}

	/**
	 * All rows of an entity that match a condition.
	 *
	 * @param string $entity Entity name such as Invoice.
	 * @param string $where  Condition (already quoted with quote()), or empty.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function select( string $entity, string $where = '' ): array {
		$rows = array();
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$sql   = 'select * from ' . $entity . ( '' !== $where ? ' where ' . $where : '' ) . ' startposition ' . ( 1 + $page * self::PAGE_SIZE ) . ' maxresults ' . self::PAGE_SIZE;
			$data  = $this->get( 'query', array( 'query' => $sql ) )->json();
			$batch = isset( $data['QueryResponse'][ $entity ] ) && is_array( $data['QueryResponse'][ $entity ] ) ? array_values( array_filter( $data['QueryResponse'][ $entity ], 'is_array' ) ) : array();
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
	 * The entity in a create, update or read answer.
	 *
	 * @param ApiResponse $response Response.
	 * @param string      $key      Entity key such as Invoice.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException When the answer holds no such entity.
	 */
	public function entity( ApiResponse $response, string $key ): array {
		$data = $response->json();
		if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
			return $data[ $key ];
		}
		$failure = QuickBooksErrors::exception( QuickBooksErrors::refused( $key, __( 'QuickBooks answered without the record.', 'estasoft-erp-crm-sync-engine' ), $response->status() ), $response );
		throw $failure;
	}

	/**
	 * A text as a quoted query value.
	 *
	 * @param string $value Text.
	 */
	public static function quote( string $value ): string {
		$clean = preg_replace( '/[\x00-\x1F]/', ' ', $value ) ?? '';
		return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $clean ) . "'";
	}
}
