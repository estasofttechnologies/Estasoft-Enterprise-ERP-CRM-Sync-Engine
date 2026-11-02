<?php
/**
 * Calls the Business Central API for one company.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Microsoft\ODataPages;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Builds the addresses (`companies({id})/salesOrders({id})/salesOrderLines`), reads lists page by page, and sends the writes. The company id is
 * pinned in every address, so a lookup can never see another company. Failures are ApiException; the connector lets them propagate.
 */
final class BcClient {

	/**
	 * Header that lets a change go through whatever version of the record is there (PATCH needs it, documented; actions probably do).
	 */
	private const ANY_VERSION = array( 'If-Match' => '*' );

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param ApiClient $api       Client of the connection.
	 * @param string    $companyId Company GUID, or an empty string for calls that need none (the company list).
	 * @param string    $system    System name for messages.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $companyId, private readonly string $system = '' ) {
	}

	/**
	 * The company GUID.
	 */
	public function companyId(): string {
		return $this->companyId;
	}

	/**
	 * The same API pinned to a company.
	 *
	 * @param string $companyId Company GUID.
	 */
	public function forCompany( string $companyId ): self {
		return new self( $this->api, $companyId, $this->system );
	}

	/**
	 * An address under the company.
	 *
	 * @param string      $collection Collection, for example salesOrders.
	 * @param string|null $id         Record GUID.
	 * @param string      $sub        Part after the record: a line collection or `Microsoft.NAV.post`.
	 */
	public function path( string $collection, ?string $id = null, string $sub = '' ): string {
		$path  = '' === $this->companyId ? '' : 'companies(' . $this->companyId . ')/';
		$path .= $collection;
		if ( null !== $id ) {
			$guid  = OData::guid( $id );
			$path .= '(' . ( $guid ?? 'invalid' ) . ')';
		}
		return '' === $sub ? $path : $path . '/' . $sub;
	}

	/**
	 * All rows of a list.
	 *
	 * @param string              $path   Path without query.
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
	 * @param string              $path   Path without query.
	 * @param array<string,mixed> $params Query options.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function first( string $path, array $params = array() ): ?array {
		return ( new ODataPages( $this->api, $this->system ) )->first( OData::path( $path, $params ) );
	}

	/**
	 * One record, or null when it does not exist.
	 *
	 * @param string              $path   Path of the record.
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
	 * Create a record. BC answers 201 with the new record.
	 *
	 * @param string              $path Path of the collection.
	 * @param array<string,mixed> $body Fields.
	 * @return array<string,mixed> The new record.
	 *
	 * @throws ApiException On failure.
	 */
	public function create( string $path, array $body ): array {
		return $this->api->post( $path, array(), $body )->json();
	}

	/**
	 * Change a record.
	 *
	 * @param string              $path Path of the record.
	 * @param array<string,mixed> $body Fields.
	 * @return array<string,mixed> The changed record.
	 *
	 * @throws ApiException On failure.
	 */
	public function update( string $path, array $body ): array {
		return $this->api->patch( $path, array(), $body, array( 'headers' => self::ANY_VERSION ) )->json();
	}

	/**
	 * Run a bound action such as `post` or `shipAndInvoice` (answer 204).
	 *
	 * @param string $path   Path of the record.
	 * @param string $action Action name.
	 * @param bool   $allow404 Return false instead of failing when the record is gone.
	 * @return bool True when the action ran; false when the record was not there and 404 was allowed.
	 *
	 * @throws ApiException On failure.
	 */
	public function action( string $path, string $action, bool $allow404 = false ): bool {
		$response = $this->api->post( $path . '/Microsoft.NAV.' . $action, array(), null, array( 'headers' => self::ANY_VERSION ) + ( $allow404 ? array( 'allow' => array( 404 ) ) : array() ) );
		return 404 !== $response->status();
	}

	/**
	 * The underlying client.
	 */
	public function api(): ApiClient {
		return $this->api;
	}
}
