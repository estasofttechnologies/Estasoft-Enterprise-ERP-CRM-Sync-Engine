<?php
/**
 * The Salesforce REST calls the connector needs.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Salesforce;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Paths under /services/data/{version}. SOQL answers are followed through nextRecordsUrl until done.
 */
final class SalesforceApi {

	/**
	 * Most query pages read in one search, a guard against a loop.
	 */
	private const MAX_PAGES = 100;

	/**
	 * Calls left in the 24-hour allocation, from the last Sforce-Limit-Info header.
	 *
	 * @var int|null
	 */
	private ?int $remaining = null;

	/**
	 * Fields of described objects, by object.
	 *
	 * @var array<string,array<int,string>>
	 */
	private array $described = array();

	/**
	 * Create the object.
	 *
	 * @param ApiClient $api     Client for the My Domain host.
	 * @param string    $version API version such as v66.0.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $version ) {
	}

	/**
	 * Calls left today according to Salesforce, when it said.
	 */
	public function remaining(): ?int {
		return $this->remaining;
	}

	/**
	 * Rows of a SOQL query, across pages.
	 *
	 * @param string $soql SOQL text, values already escaped.
	 * @return array<int,array<string,mixed>>
	 */
	public function query( string $soql ): array {
		$rows     = array();
		$response = $this->api->get( "/services/data/{$this->version}/query", array( 'q' => $soql ), null, array( 'action' => __( 'read records', 'estasoft-erp-crm-sync-engine' ) ) );
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$this->note( $response );
			$json = $response->json();
			foreach ( (array) ( $json['records'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			$next = $json['nextRecordsUrl'] ?? '';
			if ( ! empty( $json['done'] ) || ! is_string( $next ) || '' === $next ) {
				break;
			}
			$response = $this->api->get( $next, array(), null, array( 'action' => __( 'read records', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		return $rows;
	}

	/**
	 * Create a record and return its id.
	 *
	 * @param string               $type    Object API name.
	 * @param array<string,mixed>  $fields  Field values.
	 * @param array<string,string> $headers Extra request headers.
	 */
	public function create( string $type, array $fields, array $headers = array() ): string {
		$response = $this->api->post(
			"/services/data/{$this->version}/sobjects/{$type}",
			array(),
			$fields,
			array(
				'headers' => $headers,
				'action'  => __( 'create records', 'estasoft-erp-crm-sync-engine' ),
			)
		);
		$this->note( $response );
		return (string) ( $response->json()['id'] ?? '' );
	}

	/**
	 * Change fields of a record.
	 *
	 * @param string              $type   Object API name.
	 * @param string              $id     Record id.
	 * @param array<string,mixed> $fields Field values.
	 */
	public function update( string $type, string $id, array $fields ): void {
		$this->note( $this->api->patch( "/services/data/{$this->version}/sobjects/{$type}/" . rawurlencode( $id ), array(), $fields, array( 'action' => __( 'change records', 'estasoft-erp-crm-sync-engine' ) ) ) );
	}

	/**
	 * Delete a record.
	 *
	 * @param string $type Object API name.
	 * @param string $id   Record id.
	 */
	public function delete( string $type, string $id ): void {
		$this->note( $this->api->delete( "/services/data/{$this->version}/sobjects/{$type}/" . rawurlencode( $id ), array(), null, array( 'action' => __( 'delete records', 'estasoft-erp-crm-sync-engine' ) ) ) );
	}

	/**
	 * One record with all its fields, or null when it is gone.
	 *
	 * @param string $type Object API name.
	 * @param string $id   Record id.
	 * @return array<string,mixed>|null
	 */
	public function record( string $type, string $id ): ?array {
		$response = $this->api->get( "/services/data/{$this->version}/sobjects/{$type}/" . rawurlencode( $id ), array(), null, array( 'allow' => array( 404 ) ) );
		$this->note( $response );
		$json = $response->json();
		return $response->isSuccess() && array() !== $json ? $json : null;
	}

	/**
	 * What the user may do with an object and the names of its fields.
	 *
	 * @param string $type Object API name.
	 * @return array{createable:bool,fields:array<int,string>}
	 */
	public function describe( string $type ): array {
		$response = $this->api->get( "/services/data/{$this->version}/sobjects/{$type}/describe", array(), null, array( 'action' => __( 'read the object definition', 'estasoft-erp-crm-sync-engine' ) ) );
		$this->note( $response );
		$json   = $response->json();
		$fields = array();
		foreach ( (array) ( $json['fields'] ?? array() ) as $field ) {
			if ( is_array( $field ) && isset( $field['name'] ) ) {
				$fields[] = (string) $field['name'];
			}
		}
		$this->described[ $type ] = $fields;
		return array(
			'createable' => ! empty( $json['createable'] ),
			'fields'     => $fields,
		);
	}

	/**
	 * Whether an object has a field (multi-currency adds CurrencyIsoCode); the definition is read once.
	 *
	 * @param string $type  Object API name.
	 * @param string $field Field API name.
	 */
	public function hasField( string $type, string $field ): bool {
		if ( ! isset( $this->described[ $type ] ) ) {
			$this->describe( $type );
		}
		return in_array( $field, $this->described[ $type ], true );
	}

	/**
	 * The API versions the org serves, such as v66.0.
	 *
	 * @return array<int,string>
	 */
	public function versions(): array {
		$rows = $this->api->get( '/services/data/', array(), null, array( 'action' => __( 'read the API versions', 'estasoft-erp-crm-sync-engine' ) ) )->json();
		$out  = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['version'] ) ) {
				$out[] = 'v' . (string) $row['version'];
			}
		}
		return $out;
	}

	/**
	 * Remember the allocation header: api-usage=used/max.
	 *
	 * @param ApiResponse $response Answer.
	 */
	private function note( ApiResponse $response ): void {
		$info = $response->header( 'Sforce-Limit-Info' );
		if ( null !== $info && 1 === preg_match( '/api-usage=(\d+)\/(\d+)/', $info, $parts ) ) {
			$this->remaining = max( 0, (int) $parts[2] - (int) $parts[1] );
		}
	}
}
