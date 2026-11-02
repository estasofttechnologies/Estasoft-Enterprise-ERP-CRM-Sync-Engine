<?php
/**
 * Calls the Dataverse Web API.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsSales;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Microsoft\ODataPages;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Lists page by page, one record, create (asking for the new row back, `Prefer: return=representation`, so the id comes in the answer and
 * not from a header) and update. The address of every call is under `{environment}/api/data/v9.2/`. Failures are ApiException.
 */
final class DvClient {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param ApiClient $api    Client of the connection.
	 * @param string    $system System name for messages.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $system = '' ) {
	}

	/**
	 * All rows of a list.
	 *
	 * @param string              $path   Entity set or function, without query.
	 * @param array<string,mixed> $params Query options.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function rows( string $path, array $params = array() ): array {
		return ( new ODataPages( $this->api, $this->system ) )->all( OData::path( $path, $params ) );
	}

	/**
	 * The first row of a list, or null.
	 *
	 * @param string              $path   Entity set.
	 * @param array<string,mixed> $params Query options.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function first( string $path, array $params = array() ): ?array {
		return ( new ODataPages( $this->api, $this->system ) )->first( OData::path( $path, $params ) );
	}

	/**
	 * One record or the answer of a function, or null when it is not there.
	 *
	 * @param string              $path   Path.
	 * @param array<string,mixed> $params Query options.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On any failure but 404.
	 */
	public function one( string $path, array $params = array() ): ?array {
		$response = $this->api->get( OData::path( $path, $params ), array(), null, array( 'allow' => array( 404 ) ) );
		return 404 === $response->status() ? null : $response->json();
	}

	/**
	 * Create a row and get it back.
	 *
	 * @param string              $set     Entity set, for example opportunities.
	 * @param array<string,mixed> $body    Columns and `@odata.bind` bindings.
	 * @param string              $idField Name of the primary key column.
	 * @param string              $select  Columns to return, comma separated.
	 * @return array<string,mixed> The new row (at least its id).
	 *
	 * @throws ApiException On failure.
	 */
	public function create( string $set, array $body, string $idField, string $select = '' ): array {
		$response = $this->api->post(
			OData::path( $set, array( '$select' => '' !== $select ? $select : $idField ) ),
			array(),
			$body,
			array( 'headers' => array( 'Prefer' => 'return=representation' ) )
		);
		$row      = $response->json();
		if ( array() === $row ) {
			$guid = OData::guidIn( $response->header( 'OData-EntityId' ) );
			return null === $guid ? array() : array( $idField => $guid );
		}
		return $row;
	}

	/**
	 * Change a row that exists (`If-Match: *` makes it an update, never a create).
	 *
	 * @param string              $set  Entity set.
	 * @param string              $id   Row GUID.
	 * @param array<string,mixed> $body Columns.
	 *
	 * @throws ApiException On failure.
	 */
	public function update( string $set, string $id, array $body ): void {
		$this->api->patch( $set . '(' . ( OData::guid( $id ) ?? 'invalid' ) . ')', array(), $body, array( 'headers' => array( 'If-Match' => '*' ) ) );
	}

	/**
	 * The underlying client.
	 */
	public function api(): ApiClient {
		return $this->api;
	}
}
