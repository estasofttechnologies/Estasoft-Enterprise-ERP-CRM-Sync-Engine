<?php
/**
 * Calls the Fortnox REST API v3.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Fortnox;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;

/**
 * Fortnox wraps a single record in its name (`{"Invoice": {…}}`) and a list in its plural plus `MetaInformation`. Lists are read page by
 * page until the last page, so a record is never missed because it sits on a later page.
 */
final class FortnoxApi {

	/**
	 * Most pages one read follows.
	 */
	public const MAX_PAGES = 200;

	/**
	 * Rows a page holds (the API's maximum is 500).
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $client Client for the connection.
	 */
	public function __construct( private readonly ApiClient $client ) {
	}

	/**
	 * Every row of a list.
	 *
	 * @param string              $path     Path such as customers.
	 * @param string              $listKey  Name of the list in the answer, such as Customers.
	 * @param array<string,mixed> $query    Filters.
	 * @param int                 $pageSize Rows a page (1 to 500).
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException On failure.
	 * @throws FortnoxFailure When the list is longer than MAX_PAGES pages.
	 */
	public function rows( string $path, string $listKey, array $query = array(), int $pageSize = self::PAGE_SIZE ): array {
		$rows = array();
		for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
			$data = $this->client->get(
				$path,
				$query + array(
					'limit' => $pageSize,
					'page'  => $page,
				)
			)->json();
			$list = is_array( $data[ $listKey ] ?? null ) ? $data[ $listKey ] : array();
			foreach ( $list as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			$meta  = is_array( $data['MetaInformation'] ?? null ) ? $data['MetaInformation'] : array();
			$total = (int) ( $meta['@TotalPages'] ?? 1 );
			if ( array() === $list || $page >= $total ) {
				return $rows;
			}
		}
		FortnoxFailure::refuse( 'window', __( 'The list in Fortnox is longer than the plugin reads in one go.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * One record by its key.
	 *
	 * @param string $path      Path such as invoices.
	 * @param string $key       Key such as the document number.
	 * @param string $recordKey Name of the record in the answer, such as Invoice.
	 * @return array<string,mixed>|null Null when Fortnox has no such record.
	 */
	public function one( string $path, string $key, string $recordKey ): ?array {
		$response = $this->client->get( $path . '/' . rawurlencode( $key ), array(), null, array( 'allow' => array( 404 ) ) );
		if ( 404 === $response->status() ) {
			return null;
		}
		$data = $response->json()[ $recordKey ] ?? null;
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Create a record.
	 *
	 * @param string              $path      Path.
	 * @param string              $recordKey Name of the record in the body and the answer.
	 * @param array<string,mixed> $record    Record.
	 * @return array<string,mixed> The record as Fortnox answers.
	 */
	public function create( string $path, string $recordKey, array $record ): array {
		$data = $this->client->post( $path, array(), array( $recordKey => $record ) )->json()[ $recordKey ] ?? array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Change a record.
	 *
	 * @param string              $path      Path.
	 * @param string              $key       Key.
	 * @param string              $recordKey Name of the record in the body and the answer.
	 * @param array<string,mixed> $fields    Fields to change.
	 * @return array<string,mixed>
	 */
	public function update( string $path, string $key, string $recordKey, array $fields ): array {
		$data = $this->client->put( $path . '/' . rawurlencode( $key ), array(), array( $recordKey => $fields ) )->json()[ $recordKey ] ?? array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Run an action on a record (`PUT /invoices/{n}/bookkeep`).
	 *
	 * @param string $path      Path.
	 * @param string $key       Key.
	 * @param string $action    Action name.
	 * @param string $recordKey Name of the record in the answer.
	 * @return array<string,mixed>
	 */
	public function action( string $path, string $key, string $action, string $recordKey ): array {
		$data = $this->client->put( $path . '/' . rawurlencode( $key ) . '/' . $action )->json()[ $recordKey ] ?? array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * A single resource without a key (company information).
	 *
	 * @param string $path      Path.
	 * @param string $recordKey Name of the record in the answer.
	 * @return array<string,mixed>
	 */
	public function single( string $path, string $recordKey ): array {
		$data = $this->client->get( $path )->json()[ $recordKey ] ?? array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Read one row of a list to see whether the sign-in may read it.
	 *
	 * @param string $path Path.
	 * @return bool False when Fortnox refuses the scope; a lapsed sign-in or missing licence is thrown.
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException For a lapsed sign-in, a missing licence or any failure but a refused scope.
	 */
	public function canRead( string $path ): bool {
		try {
			$this->client->get( $path, array( 'limit' => 1 ) );
			return true;
		} catch ( \Estasoft\SyncEngine\Connectors\Support\ApiException $exception ) {
			if ( \Estasoft\SyncEngine\Domain\ErrorCode::Auth004 === $exception->error()->code ) {
				return false;
			}
			throw $exception;
		}
	}

	/**
	 * A GET to an address outside the /3 tree (the warehouse API).
	 *
	 * @param string              $url   Absolute https URL.
	 * @param array<string,mixed> $query Query.
	 * @return array<mixed>
	 */
	public function raw( string $url, array $query = array() ): array {
		return $this->client->get( $url, $query )->json();
	}

	/**
	 * The date part of a Fortnox date (`2026-11-02`), or an empty string.
	 *
	 * @param mixed $value Value from Fortnox.
	 */
	public static function date( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $parts ) ? $parts[1] : '';
	}
}
