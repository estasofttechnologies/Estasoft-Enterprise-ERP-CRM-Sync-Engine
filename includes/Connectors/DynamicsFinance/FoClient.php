<?php
/**
 * Calls the Finance and Operations OData service for one legal entity.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\DynamicsFinance;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Microsoft\OData;
use Estasoft\SyncEngine\Connectors\Microsoft\ODataPages;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Every read asks for `cross-company=true` together with a filter on `dataAreaId`, so the answer never depends on the default company of the
 * service user (without the option OData returns only that company). Every write carries `dataAreaId` in its body. Records are addressed by
 * their whole key (`SalesOrderHeadersV2(dataAreaId='usmf',SalesOrderNumber='…')`), as the OData page requires. Failures are ApiException.
 */
final class FoClient {

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param ApiClient $api         Client of the connection.
	 * @param string    $legalEntity Legal entity (dataAreaId), lower case.
	 * @param string    $system      System name for messages.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $legalEntity, private readonly string $system = '' ) {
	}

	/**
	 * The legal entity.
	 */
	public function legalEntity(): string {
		return $this->legalEntity;
	}

	/**
	 * All rows of an entity that match conditions, in the legal entity.
	 *
	 * @param string              $entity     Entity collection, for example CustomersV3.
	 * @param array<int,string>   $conditions Filter conditions besides the legal entity.
	 * @param array<string,mixed> $params     More query options ($select, $top).
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function rows( string $entity, array $conditions = array(), array $params = array() ): array {
		return ( new ODataPages( $this->api, $this->system ) )->all( $this->path( $entity, $conditions, $params ) );
	}

	/**
	 * The first row, or null.
	 *
	 * @param string              $entity     Entity collection.
	 * @param array<int,string>   $conditions Conditions.
	 * @param array<string,mixed> $params     More query options.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function first( string $entity, array $conditions = array(), array $params = array() ): ?array {
		return ( new ODataPages( $this->api, $this->system ) )->first( $this->path( $entity, $conditions, $params ) );
	}

	/**
	 * Create a record in the legal entity. The answer is the new record.
	 *
	 * @param string              $entity Entity collection.
	 * @param array<string,mixed> $body   Fields (dataAreaId is added).
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On failure.
	 */
	public function create( string $entity, array $body ): array {
		return $this->api->post( $entity, array(), array( 'dataAreaId' => $this->legalEntity ) + $body )->json();
	}

	/**
	 * Change a record by its whole key.
	 *
	 * @param string               $entity Entity collection.
	 * @param array<string,string> $key    Key fields other than dataAreaId.
	 * @param array<string,mixed>  $body   Fields.
	 *
	 * @throws ApiException On failure.
	 */
	public function update( string $entity, array $key, array $body ): void {
		$parts = array( "dataAreaId='" . rawurlencode( str_replace( "'", "''", $this->legalEntity ) ) . "'" );
		foreach ( $key as $name => $value ) {
			$parts[] = $name . "='" . rawurlencode( str_replace( "'", "''", $value ) ) . "'";
		}
		$this->api->patch( $entity . '(' . implode( ',', $parts ) . ')', array(), $body, array( 'headers' => array( 'If-Match' => '*' ) ) );
	}

	/**
	 * Whether a value may be placed inside a filter literal: in F&O a `*` in a string `eq` is a wildcard.
	 *
	 * @param string $value Value.
	 */
	public static function literalSafe( string $value ): bool {
		return ! str_contains( $value, '*' );
	}

	/**
	 * The path with its filter and options.
	 *
	 * @param string              $entity     Entity.
	 * @param array<int,string>   $conditions Conditions.
	 * @param array<string,mixed> $params     Options.
	 */
	private function path( string $entity, array $conditions, array $params ): string {
		$filter = OData::all( array_merge( array( OData::eq( 'dataAreaId', $this->legalEntity ) ), $conditions ) );
		return OData::path( $entity, array( 'cross-company' => true ) + array( '$filter' => $filter ) + $params );
	}

	/**
	 * The underlying client.
	 */
	public function api(): ApiClient {
		return $this->api;
	}
}
