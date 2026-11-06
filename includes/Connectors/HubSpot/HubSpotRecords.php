<?php
/**
 * The few HubSpot CRM calls the connector needs.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\HubSpot;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;

/**
 * Date-versioned object paths (/crm/objects/{version}/{object}/...), search paging, the properties of an object and one cheap probe. HubSpot
 * sends its rate-limit headers on every answer except those of the search endpoints ("Responses from the search API endpoints will not
 * include any of the rate limit headers", usage guidelines), and the daily ones not for OAuth requests, so nothing here depends on them.
 */
final class HubSpotRecords {

	/**
	 * Search results HubSpot returns for one query at most; paging past it is an error.
	 */
	public const SEARCH_CAP = 10000;

	/**
	 * Page size for searches (HubSpot allows 200).
	 */
	private const PAGE = 100;

	/**
	 * Create the object.
	 *
	 * @param ApiClient $api     Client for api.hubapi.com.
	 * @param string    $version API version such as 2026-03.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $version ) {
	}

	/**
	 * Every record matching all filters, oldest first, across pages.
	 *
	 * @param string                         $type       contacts, deals, products, notes.
	 * @param array<int,array<string,mixed>> $filters    Filters, ANDed.
	 * @param array<int,string>              $properties Properties to return.
	 * @param int                            $limit      Most records to return.
	 * @param string                         $query      Free text for the object's default search properties.
	 * @return array<int,array<string,mixed>>
	 */
	public function searchAll( string $type, array $filters, array $properties, int $limit = 2000, string $query = '' ): array {
		$found = array();
		$after = null;
		$pages = (int) ceil( self::SEARCH_CAP / self::PAGE );
		for ( $page = 0; $page < $pages; ++$page ) {
			$body = array(
				'properties' => $properties,
				'sorts'      => array(
					array(
						'propertyName' => 'createdate',
						'direction'    => 'ASCENDING',
					),
				),
				'limit'      => self::PAGE,
			);
			if ( array() !== $filters ) {
				$body['filterGroups'] = array( array( 'filters' => $filters ) );
			}
			if ( '' !== $query ) {
				$body['query'] = $query;
			}
			if ( null !== $after ) {
				$body['after'] = $after;
			}
			$response = $this->api->post( "/crm/objects/{$this->version}/{$type}/search", array(), $body, array( 'action' => __( 'search records', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
			$json     = $response->json();
			foreach ( (array) ( $json['results'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$found[] = $row;
				}
			}
			$after = isset( $json['paging']['next']['after'] ) ? (string) $json['paging']['next']['after'] : null;
			if ( null === $after || count( $found ) >= $limit ) {
				break;
			}
		}
		return $found;
	}

	/**
	 * Create a record.
	 *
	 * @param string                         $type         Object type.
	 * @param array<string,mixed>            $properties   Properties.
	 * @param array<int,array<string,mixed>> $associations Associations created in the same call.
	 * @param array<int,int>                 $allow        Statuses that are returned instead of thrown.
	 */
	public function create( string $type, array $properties, array $associations = array(), array $allow = array() ): ApiResponse {
		$body = array( 'properties' => $properties );
		if ( array() !== $associations ) {
			$body['associations'] = $associations;
		}
		$response = $this->api->post(
			"/crm/objects/{$this->version}/{$type}",
			array(),
			$body,
			array(
				'allow'  => $allow,
				'action' => __( 'create records', 'estasoft-enterprise-erpcrm-sync-engine' ),
			)
		);
		return $response;
	}

	/**
	 * Change properties of a record.
	 *
	 * @param string              $type       Object type.
	 * @param string              $id         Record id.
	 * @param array<string,mixed> $properties Properties.
	 */
	public function update( string $type, string $id, array $properties ): ApiResponse {
		return $this->api->patch( "/crm/objects/{$this->version}/{$type}/" . rawurlencode( $id ), array(), array( 'properties' => $properties ), array( 'action' => __( 'change records', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
	}

	/**
	 * Archive (delete) a record.
	 *
	 * @param string $type Object type.
	 * @param string $id     Record id.
	 */
	public function archive( string $type, string $id ): void {
		$this->api->delete( "/crm/objects/{$this->version}/{$type}/" . rawurlencode( $id ), array(), null, array( 'action' => __( 'delete records', 'estasoft-enterprise-erpcrm-sync-engine' ) ) );
	}

	/**
	 * Deal pipelines with their stages (needs the schema scope; returns null when HubSpot refuses).
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	public function pipelines(): ?array {
		$response = $this->api->get( "/crm/pipelines/{$this->version}/deals", array(), null, array( 'allow' => array( 403, 404 ) ) );
		if ( ! $response->isSuccess() ) {
			return null;
		}
		$list = $response->json()['results'] ?? array();
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : null;
	}

	/**
	 * One property definition of an object. A 403 (no schema scope) and a 404 (no such property) come back as answers to read.
	 *
	 * @param string $type Object type.
	 * @param string $name Internal property name.
	 */
	public function property( string $type, string $name ): ApiResponse {
		return $this->api->get(
			"/crm/properties/{$this->version}/{$type}/" . rawurlencode( $name ),
			array(),
			null,
			array(
				'allow'  => array( 403, 404 ),
				'action' => __( 'read the property definitions', 'estasoft-enterprise-erpcrm-sync-engine' ),
			)
		);
	}

	/**
	 * Create a property on an object. A refusal (400, 403) and a name already in use (409) come back as answers to read.
	 *
	 * @param string              $type       Object type.
	 * @param array<string,mixed> $definition name, label, type, fieldType, groupName, hasUniqueValue.
	 */
	public function createProperty( string $type, array $definition ): ApiResponse {
		return $this->api->post(
			"/crm/properties/{$this->version}/{$type}",
			array(),
			$definition,
			array(
				'allow'  => array( 400, 403, 409 ),
				'action' => __( 'create properties', 'estasoft-enterprise-erpcrm-sync-engine' ),
			)
		);
	}

	/**
	 * One cheap read that proves the key works and may read deals (the circuit-breaker probe).
	 */
	public function probe(): bool {
		return $this->api->get( "/crm/objects/{$this->version}/deals", array( 'limit' => 1 ), null, array( 'action' => __( 'read records', 'estasoft-enterprise-erpcrm-sync-engine' ) ) )->isSuccess();
	}
}
