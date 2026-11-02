<?php
/**
 * Calls the Exact Online REST API of one division.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Exact;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;

/**
 * Pins the division on every call, unwraps the OData envelope (`d`, `results`, `__next`) and follows every page. Exact returns 60 rows
 * a page and does not support `$skip` on newer endpoints, so paging always follows the `__next` link.
 */
final class ExactApi {

	/**
	 * Most pages one read follows; the daily cap of the API would stop a longer read anyway.
	 */
	public const MAX_PAGES = 200;

	/**
	 * The empty GUID, used to look up an id that cannot be a document's (it never matches, but the call still proves the sign-in).
	 */
	public const NIL_GUID = '00000000-0000-0000-0000-000000000000';

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient $client   Client for the connection.
	 * @param string    $division Division (administration) code.
	 */
	public function __construct( private readonly ApiClient $client, private readonly string $division ) {
	}

	/**
	 * The division code.
	 */
	public function division(): string {
		return $this->division;
	}

	/**
	 * The record of the signed-in user, which needs no division.
	 *
	 * @return array<string,mixed>
	 */
	public function me(): array {
		$rows = self::results( $this->client->get( 'current/Me', array( '$select' => 'CurrentDivision,FullName,UserName,DivisionCustomerName' ) )->json()['d'] ?? array() );
		return $rows[0] ?? array();
	}

	/**
	 * Every row of a list, all pages.
	 *
	 * @param string              $endpoint Resource below the division, for example crm/Accounts.
	 * @param array<string,mixed> $query    OData query ($filter, $select).
	 * @param bool                $scoped   False for a resource outside the division (none today).
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException On failure.
	 * @throws ExactFailure When the list is longer than MAX_PAGES pages.
	 */
	public function rows( string $endpoint, array $query = array(), bool $scoped = true ): array {
		$response = $this->client->get( $scoped ? $this->path( $endpoint ) : $endpoint, $query );
		$rows     = array();
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$data = $response->json()['d'] ?? array();
			foreach ( self::results( $data ) as $row ) {
				$rows[] = $row;
			}
			$next = is_array( $data ) ? ( $data['__next'] ?? '' ) : '';
			if ( ! is_string( $next ) || '' === $next ) {
				return $rows;
			}
			$response = $this->client->get( $next );
		}
		ExactFailure::refuse( 'window', __( 'The list in Exact Online is longer than the plugin reads in one go.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The first row of a list, or null.
	 *
	 * @param string              $endpoint Resource.
	 * @param array<string,mixed> $query    OData query.
	 * @return array<string,mixed>|null
	 */
	public function first( string $endpoint, array $query = array() ): ?array {
		$rows = $this->rows( $endpoint, $query );
		return $rows[0] ?? null;
	}

	/**
	 * One row by key, read through a filter (no key syntax in the path). A key that is not a GUID finds nothing.
	 *
	 * @param string $endpoint Resource.
	 * @param string $keyName  Name of the key field, for example InvoiceID.
	 * @param string $guid     Key value.
	 * @param string $select   Comma separated field names.
	 * @return array<string,mixed>|null
	 */
	public function byKey( string $endpoint, string $keyName, string $guid, string $select ): ?array {
		return $this->first(
			$endpoint,
			array(
				'$filter' => $keyName . ' eq guid' . self::str( self::isGuid( $guid ) ? trim( $guid ) : self::NIL_GUID ),
				'$select' => $select,
			)
		);
	}

	/**
	 * Create a record and return what Exact answers with.
	 *
	 * @param string              $endpoint Resource.
	 * @param array<string,mixed> $body     Record.
	 * @return array<string,mixed>
	 */
	public function create( string $endpoint, array $body ): array {
		$response = $this->client->post( $this->path( $endpoint ), array(), $body, array( 'headers' => array( 'Prefer' => 'return=representation' ) ) );
		$data     = $response->json()['d'] ?? array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Change a record by key.
	 *
	 * @param string              $endpoint Resource.
	 * @param string              $guid     Key value.
	 * @param array<string,mixed> $body     Fields to change.
	 */
	public function update( string $endpoint, string $guid, array $body ): void {
		$this->client->put( $this->path( $endpoint ) . '(guid' . self::str( self::guid( $guid ) ) . ')', array(), $body );
	}

	/**
	 * Delete a record by key. A refusal is not an error here: it answers false.
	 *
	 * @param string $endpoint Resource.
	 * @param string $guid     Key value.
	 */
	public function delete( string $endpoint, string $guid ): bool {
		try {
			$this->client->delete( $this->path( $endpoint ) . '(guid' . self::str( self::guid( $guid ) ) . ')' );
			return true;
		} catch ( \Estasoft\SyncEngine\Connectors\Support\ApiException $exception ) {
			unset( $exception );
			return false;
		}
	}

	/**
	 * The path of a resource below the division.
	 *
	 * @param string $endpoint Resource.
	 */
	public function path( string $endpoint ): string {
		return rawurlencode( $this->division ) . '/' . ltrim( $endpoint, '/' );
	}

	/**
	 * An OData text literal: quoted, with quotes doubled.
	 *
	 * @param string $value Text.
	 */
	public static function str( string $value ): string {
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	/**
	 * Whether a text is a GUID.
	 *
	 * @param string $guid Text.
	 */
	public static function isGuid( string $guid ): bool {
		return 1 === preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', trim( $guid ) );
	}

	/**
	 * A GUID, checked.
	 *
	 * @param string $guid Text.
	 * @throws ExactFailure When it is not a GUID.
	 */
	public static function guid( string $guid ): string {
		$guid = trim( $guid );
		if ( ! self::isGuid( $guid ) ) {
			ExactFailure::refuse( 'id', __( 'Exact Online returned an id that is not a GUID.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $guid;
	}

	/**
	 * The rows inside the `d` envelope: `results`, a plain list, or one record.
	 *
	 * @param mixed $data The `d` value.
	 * @return array<int,array<string,mixed>>
	 */
	public static function results( mixed $data ): array {
		if ( ! is_array( $data ) || array() === $data ) {
			return array();
		}
		if ( isset( $data['results'] ) && is_array( $data['results'] ) ) {
			$data = $data['results'];
		} elseif ( ! \Estasoft\SyncEngine\Core\Support\Lists::isList( $data ) ) {
			return array( $data );
		}
		return array_values( array_filter( $data, 'is_array' ) );
	}

	/**
	 * A date from Exact (`/Date(1609459200000)/` or ISO text) as Y-m-d, or an empty string.
	 *
	 * @param mixed $value Value from Exact.
	 */
	public static function date( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		if ( 1 === preg_match( '#^/Date\((-?\d+)#', $value, $parts ) ) {
			return gmdate( 'Y-m-d', intdiv( (int) $parts[1], 1000 ) );
		}
		return 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $value, $parts ) ? $parts[1] : '';
	}
}
