<?php
/**
 * The contract-based REST API of an Acumatica site, behind small methods.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Acumatica;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Entity routes live under `/entity/Default/<version>/`. Lists are paged with `$top` and `$skip` (there is no total count), a missing
 * record is a normal answer (null), actions are `POST <Entity>/<Action>` and may answer 202 with a `Location` to poll.
 */
final class AcumaticaClient {

	/**
	 * Rows asked for in one list call.
	 */
	public const PAGE = 100;

	/**
	 * Most rows a list helper collects before it stops.
	 */
	public const MAX_ROWS = 5000;

	/**
	 * Times the status of a running action is read before the connector goes on.
	 */
	public const POLLS = 3;

	/**
	 * Set up with the services it uses.
	 *
	 * @param ApiClient $api     Shared client.
	 * @param string    $version Default endpoint version.
	 * @param string    $address Site address (scheme, host, instance folder).
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $version, private readonly string $address ) {
	}

	/**
	 * The shared client.
	 */
	public function api(): ApiClient {
		return $this->api;
	}

	/**
	 * The endpoint version in use.
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * Records of an entity, collected page by page.
	 *
	 * @param string              $entity Entity name such as SalesOrder.
	 * @param array<string,mixed> $params $filter, $select, $expand.
	 * @param int                 $max    Most rows to collect.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function query( string $entity, array $params = array(), int $max = self::MAX_ROWS ): array {
		$rows = array();
		$skip = 0;
		$have = 0;
		while ( $have < $max ) {
			$size     = min( self::PAGE, $max - $have );
			$response = $this->api->get(
				$this->path( $entity ),
				$params + array(
					'$top'  => $size,
					'$skip' => $skip,
				),
				null,
				array( 'action' => 'read ' . $entity )
			);
			$page     = $response->jsonValue();
			if ( ! is_array( $page ) ) {
				break;
			}
			foreach ( $page as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
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
	 * The first record that matches, or null.
	 *
	 * @param string              $entity Entity.
	 * @param array<string,mixed> $params Query options.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function first( string $entity, array $params ): ?array {
		$response = $this->api->get( $this->path( $entity ), $params + array( '$top' => 1 ), null, array( 'action' => 'read ' . $entity ) );
		$page     = $response->jsonValue();
		return is_array( $page ) && isset( $page[0] ) && is_array( $page[0] ) ? $page[0] : null;
	}

	/**
	 * One record by key path, or null when it does not exist.
	 *
	 * @param string              $path   Path under the endpoint, for example SalesInvoice/Invoice/000123.
	 * @param array<string,mixed> $params Query options.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure other than not found.
	 */
	public function get( string $path, array $params = array() ): ?array {
		$response = $this->api->get(
			$this->path( $path ),
			$params,
			null,
			array(
				'allow'  => array( 404 ),
				'action' => 'read ' . strtok( $path, '/' ),
			)
		);
		if ( 404 === $response->status() ) {
			return null;
		}
		$json = $response->json();
		return array() === $json ? null : $json;
	}

	/**
	 * Create or change a record (PUT).
	 *
	 * @param string              $entity Entity.
	 * @param array<string,mixed> $body   Wrapped fields.
	 * @param array<string,mixed> $params Query options such as $expand.
	 * @return array<string,mixed> The stored record.
	 *
	 * @throws ApiException On failure.
	 */
	public function put( string $entity, array $body, array $params = array() ): array {
		return $this->api->put( $this->path( $entity ), $params, $body, array( 'action' => 'write ' . $entity ) )->json();
	}

	/**
	 * Run an action on a record and wait a little for it to finish.
	 *
	 * @param string              $entity     Entity.
	 * @param string              $action     Action name such as ReleaseSalesInvoice.
	 * @param array<string,mixed> $record     The entity block (wrapped key fields).
	 * @param array<string,mixed> $parameters Action parameters.
	 * @return bool True when the action is finished, false when it was still running after the polls.
	 *
	 * @throws ApiException On failure.
	 */
	public function action( string $entity, string $action, array $record, array $parameters = array() ): bool {
		$response = $this->api->post(
			$this->path( $entity . '/' . $action ),
			array(),
			array(
				'entity'     => $record,
				'parameters' => (object) $parameters,
			),
			array( 'action' => 'run ' . $action )
		);
		if ( 202 !== $response->status() ) {
			return true;
		}
		$location = $response->header( 'Location' );
		if ( null === $location || '' === $location ) {
			return false;
		}
		$url = str_starts_with( $location, 'http' ) ? $location : AcumaticaSite::origin( $this->address ) . '/' . ltrim( $location, '/' );
		for ( $i = 0; $i < self::POLLS; $i++ ) {
			$status = $this->api->get( $url, array(), null, array( 'action' => 'read ' . $action ) );
			if ( 202 !== $status->status() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The path of an entity route.
	 *
	 * @param string $route Entity or entity/key path.
	 */
	private function path( string $route ): string {
		return AcumaticaSite::path( $this->version ) . '/' . $route;
	}
}
