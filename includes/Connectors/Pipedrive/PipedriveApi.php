<?php
/**
 * The few Pipedrive calls the connector needs.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Pipedrive;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;

/**
 * API v2 for deals, persons, organizations, products and search; API v1 for users and notes (notes are not in the v2 list).
 */
final class PipedriveApi {

	/**
	 * Largest page the connector asks for in a list.
	 */
	private const PAGE = 100;

	/**
	 * Most pages read in one search or list, a guard against a loop.
	 */
	private const MAX_PAGES = 200;

	/**
	 * Create the object.
	 *
	 * @param ApiClient $api Client for the company's Pipedrive host.
	 */
	public function __construct( private readonly ApiClient $api ) {
	}

	/**
	 * Items of one type found by a search over the named fields; cursor paged.
	 *
	 * @param string $type  deal, person, organization or product.
	 * @param string $fields Comma separated fields to search (title, email, name, code).
	 * @param string $term  Search text.
	 * @param bool   $exact Whole-value, case-insensitive match.
	 * @return array<int,array<string,mixed>>
	 */
	public function search( string $type, string $fields, string $term, bool $exact = true ): array {
		$items  = array();
		$cursor = null;
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$json = $this->api->get(
				'/api/v2/itemSearch',
				array(
					'term'        => $term,
					'item_types'  => $type,
					'fields'      => $fields,
					'exact_match' => $exact,
					'limit'       => self::PAGE,
					'cursor'      => $cursor,
				),
				null,
				array( 'action' => __( 'search records', 'estasoft-erp-crm-sync-engine' ) )
			)->json();
			foreach ( (array) ( $json['data']['items'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$items[] = (array) ( $row['item'] ?? $row );
				}
			}
			$cursor = $json['additional_data']['next_cursor'] ?? null;
			if ( ! is_string( $cursor ) || '' === $cursor ) {
				break;
			}
		}
		return $items;
	}

	/**
	 * One deal, or null when Pipedrive has none with the id.
	 *
	 * @param string $id Deal id.
	 * @return array<string,mixed>|null
	 */
	public function deal( string $id ): ?array {
		$response = $this->api->get( '/api/v2/deals/' . rawurlencode( $id ), array(), null, array( 'allow' => array( 404 ) ) );
		$data     = $response->isSuccess() ? ( $response->json()['data'] ?? null ) : null;
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Create a record (persons, organizations, deals, products) and return its data.
	 *
	 * @param string              $collection persons, organizations, deals or products.
	 * @param array<string,mixed> $body       Record.
	 * @return array<string,mixed>
	 */
	public function create( string $collection, array $body ): array {
		$data = $this->api->post( '/api/v2/' . $collection, array(), $body, array( 'action' => __( 'create records', 'estasoft-erp-crm-sync-engine' ) ) )->json()['data'] ?? array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Change a deal.
	 *
	 * @param string              $id      Deal id.
	 * @param array<string,mixed> $changes Fields to change.
	 * @return array<string,mixed>
	 */
	public function patchDeal( string $id, array $changes ): array {
		$data = $this->api->patch( '/api/v2/deals/' . rawurlencode( $id ), array(), $changes, array( 'action' => __( 'change deals', 'estasoft-erp-crm-sync-engine' ) ) )->json()['data'] ?? array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Delete a deal (Pipedrive keeps it for a while).
	 *
	 * @param string $id Deal id.
	 */
	public function deleteDeal( string $id ): void {
		$this->api->delete( '/api/v2/deals/' . rawurlencode( $id ), array(), null, array( 'action' => __( 'delete deals', 'estasoft-erp-crm-sync-engine' ) ) );
	}

	/**
	 * Notes of a deal, oldest page first; v1 paging with start and more_items_in_collection.
	 *
	 * @param string $dealId Deal id.
	 * @return array<int,array<string,mixed>>
	 */
	public function notes( string $dealId ): array {
		$notes = array();
		$start = 0;
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$json = $this->api->get(
				'/api/v1/notes',
				array(
					'deal_id' => $dealId,
					'start'   => $start,
					'limit'   => self::PAGE,
				),
				null,
				array( 'action' => __( 'read notes', 'estasoft-erp-crm-sync-engine' ) )
			)->json();
			foreach ( (array) ( $json['data'] ?? array() ) as $note ) {
				if ( is_array( $note ) ) {
					$notes[] = $note;
				}
			}
			if ( empty( $json['additional_data']['pagination']['more_items_in_collection'] ) ) {
				break;
			}
			$start = (int) ( $json['additional_data']['pagination']['next_start'] ?? ( $start + self::PAGE ) );
		}
		return $notes;
	}

	/**
	 * Add a note to a deal.
	 *
	 * @param string $dealId  Deal id.
	 * @param string $content Note text (HTML).
	 * @return array<string,mixed>
	 */
	public function createNote( string $dealId, string $content ): array {
		$data = $this->api->post(
			'/api/v1/notes',
			array(),
			array(
				'content' => $content,
				'deal_id' => ctype_digit( $dealId ) ? (int) $dealId : $dealId,
			),
			array( 'action' => __( 'create notes', 'estasoft-erp-crm-sync-engine' ) )
		)->json()['data'] ?? array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Products attached to a deal.
	 *
	 * @param string $dealId Deal id.
	 * @return array<int,array<string,mixed>>
	 */
	public function dealProducts( string $dealId ): array {
		$rows   = array();
		$cursor = null;
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$json = $this->api->get(
				'/api/v2/deals/' . rawurlencode( $dealId ) . '/products',
				array(
					'limit'  => self::PAGE,
					'cursor' => $cursor,
				),
				null,
				array( 'action' => __( 'read deal products', 'estasoft-erp-crm-sync-engine' ) )
			)->json();
			foreach ( (array) ( $json['data'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			$cursor = $json['additional_data']['next_cursor'] ?? null;
			if ( ! is_string( $cursor ) || '' === $cursor ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * Attach a product to a deal.
	 *
	 * @param string              $dealId Deal id.
	 * @param array<string,mixed> $body   product_id, item_price, quantity, comments.
	 */
	public function addDealProduct( string $dealId, array $body ): void {
		$this->api->post( '/api/v2/deals/' . rawurlencode( $dealId ) . '/products', array(), $body, array( 'action' => __( 'add deal products', 'estasoft-erp-crm-sync-engine' ) ) );
	}

	/**
	 * Deals changed since a time, cursor paged.
	 *
	 * @param string $since RFC 3339 UTC time.
	 * @return array<int,array<string,mixed>>
	 */
	public function dealsUpdatedSince( string $since ): array {
		$rows   = array();
		$cursor = null;
		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$json = $this->api->get(
				'/api/v2/deals',
				array(
					'updated_since' => $since,
					'limit'         => 500,
					'cursor'        => $cursor,
				),
				null,
				array( 'action' => __( 'read deals', 'estasoft-erp-crm-sync-engine' ) )
			)->json();
			foreach ( (array) ( $json['data'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			$cursor = $json['additional_data']['next_cursor'] ?? null;
			if ( ! is_string( $cursor ) || '' === $cursor ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * The signed-in user and company (v1 users/me).
	 *
	 * @return array<string,mixed>
	 */
	public function me(): array {
		$data = $this->api->get( '/api/v1/users/me', array(), null, array( 'action' => __( 'read the user', 'estasoft-erp-crm-sync-engine' ) ) )->json()['data'] ?? array();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Pipelines (the connection test proves the key can read deals).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function pipelines(): array {
		$rows = $this->api->get( '/api/v2/pipelines', array( 'limit' => self::PAGE ), null, array( 'action' => __( 'read pipelines', 'estasoft-erp-crm-sync-engine' ) ) )->json()['data'] ?? array();
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * Whether the key may read a collection (a 403 means no).
	 *
	 * @param string $path Path such as /api/v2/persons.
	 */
	public function readable( string $path ): bool {
		return $this->api->get( $path, array( 'limit' => 1 ), null, array( 'allow' => array( 403 ) ) )->isSuccess();
	}
}
