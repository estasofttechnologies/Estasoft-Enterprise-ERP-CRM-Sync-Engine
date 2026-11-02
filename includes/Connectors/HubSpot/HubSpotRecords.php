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
 * Date-versioned object paths (/crm/objects/{version}/{object}/...), search paging and the daily quota header.
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
	 * Daily requests HubSpot said are left, from the last answer that carried the header.
	 *
	 * @var int|null
	 */
	private ?int $dailyRemaining = null;

	/**
	 * Create the object.
	 *
	 * @param ApiClient $api     Client for api.hubapi.com.
	 * @param string    $version API version such as 2026-03.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $version ) {
	}

	/**
	 * Requests left today according to HubSpot, when it said.
	 */
	public function dailyRemaining(): ?int {
		return $this->dailyRemaining;
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
			$response = $this->api->post( "/crm/objects/{$this->version}/{$type}/search", array(), $body, array( 'action' => __( 'search records', 'estasoft-erp-crm-sync-engine' ) ) );
			$this->note( $response );
			$json = $response->json();
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
				'action' => __( 'create records', 'estasoft-erp-crm-sync-engine' ),
			)
		);
		$this->note( $response );
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
		$response = $this->api->patch( "/crm/objects/{$this->version}/{$type}/" . rawurlencode( $id ), array(), array( 'properties' => $properties ), array( 'action' => __( 'change records', 'estasoft-erp-crm-sync-engine' ) ) );
		$this->note( $response );
		return $response;
	}

	/**
	 * Archive (delete) a record.
	 *
	 * @param string $type Object type.
	 * @param string $id     Record id.
	 */
	public function archive( string $type, string $id ): void {
		$this->note( $this->api->delete( "/crm/objects/{$this->version}/{$type}/" . rawurlencode( $id ), array(), null, array( 'action' => __( 'delete records', 'estasoft-erp-crm-sync-engine' ) ) ) );
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
	 * Remember the daily quota header.
	 *
	 * @param ApiResponse $response Answer.
	 */
	private function note( ApiResponse $response ): void {
		$left = $response->header( 'X-HubSpot-RateLimit-Daily-Remaining' );
		if ( null !== $left && ctype_digit( trim( $left ) ) ) {
			$this->dailyRemaining = (int) trim( $left );
		}
	}
}
