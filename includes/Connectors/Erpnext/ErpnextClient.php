<?php
/**
 * The REST routes of a Frappe site, behind small methods.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Erpnext;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Wraps the shared ApiClient for `/api/resource/<DocType>` and `/api/method/<path>` (frappe/api/v1.py). The version-neutral v1 routes are
 * used on purpose: they exist from Frappe 14 on. Lists are always paged (the server default is 20 rows), a missing document is a
 * normal answer (null), everything else throws ApiException.
 */
final class ErpnextClient {

	/**
	 * Rows asked for in one list call.
	 */
	public const PAGE = 100;

	/**
	 * Most rows a list helper collects before it stops (a safety limit; callers filter on the server).
	 */
	public const MAX_ROWS = 5000;

	/**
	 * Most rows a read of a whole period collects. It is the cap of the reconciliation service itself; a period with more rows is refused
	 * with a message instead of being cut short.
	 */
	public const LARGE = 20000;

	/**
	 * Set up with the services it uses.
	 *
	 * @param ApiClient $api Shared client with authentication, pacing and redaction.
	 */
	public function __construct( private readonly ApiClient $api ) {
	}

	/**
	 * Rows of a DocType that match filters, collected page by page.
	 *
	 * @param string                      $doctype  DocType name, for example Sales Order.
	 * @param array<int,array<int,mixed>> $filters  Filters as [field, operator, value] or [childDoctype, field, operator, value].
	 * @param array<int,string>           $fields   Fields to return.
	 * @param string                      $orderBy  Order, for example "creation asc".
	 * @param int                         $max      Most rows to collect.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	public function rows( string $doctype, array $filters = array(), array $fields = array( 'name' ), string $orderBy = '', int $max = self::MAX_ROWS ): array {
		$rows  = array();
		$start = 0;
		$have  = 0;
		while ( $have < $max ) {
			$size  = min( self::PAGE, $max - $have );
			$query = array(
				'fields'            => $this->json( $fields ),
				'limit_start'       => $start,
				'limit_page_length' => $size,
			);
			if ( array() !== $filters ) {
				$query['filters'] = $this->json( $filters );
			}
			if ( '' !== $orderBy ) {
				$query['order_by'] = $orderBy;
			}
			$response = $this->api->get( '/api/resource/' . rawurlencode( $doctype ), $query, null, array( 'action' => $this->reading( $doctype ) ) );
			$page     = $response->json()['data'] ?? null;
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
			$start += $size;
		}
		return $rows;
	}

	/**
	 * Every row that matches, however many pages: a read whose result must be complete (the nightly check, shipments).
	 *
	 * @param string                      $doctype DocType name.
	 * @param array<int,array<int,mixed>> $filters Filters.
	 * @param array<int,string>           $fields  Fields to return.
	 * @param string                      $orderBy Order.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure, and when more than LARGE rows match (the list would be incomplete).
	 */
	public function complete( string $doctype, array $filters, array $fields, string $orderBy = '' ): array {
		$rows = $this->rows( $doctype, $filters, $fields, $orderBy, self::LARGE + 1 );
		if ( count( $rows ) > self::LARGE ) {
			$exception = new ApiException(
				Problems::refused(
					ErpnextConnector::LABEL,
					'window',
					sprintf(
						/* translators: 1: row limit, 2: DocType name. */
						__( 'ERPNext has more than %1$d %2$s records in the period asked for, so the list would be incomplete. Ask for a shorter period.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						self::LARGE,
						$doctype
					),
					'too many rows'
				)
			);
			throw $exception;
		}
		return $rows;
	}

	/**
	 * The first row that matches, or null.
	 *
	 * @param string                      $doctype DocType.
	 * @param array<int,array<int,mixed>> $filters Filters.
	 * @param array<int,string>           $fields  Fields.
	 * @param string                      $orderBy Order.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function first( string $doctype, array $filters, array $fields = array( 'name' ), string $orderBy = '' ): ?array {
		$query = array(
			'fields'            => $this->json( $fields ),
			'filters'           => $this->json( $filters ),
			'limit_page_length' => 1,
		);
		if ( '' !== $orderBy ) {
			$query['order_by'] = $orderBy;
		}
		$response = $this->api->get( '/api/resource/' . rawurlencode( $doctype ), $query, null, array( 'action' => $this->reading( $doctype ) ) );
		$page     = $response->json()['data'] ?? null;
		return is_array( $page ) && isset( $page[0] ) && is_array( $page[0] ) ? $page[0] : null;
	}

	/**
	 * One document with its child tables, or null when it does not exist.
	 *
	 * @param string $doctype DocType.
	 * @param string $name    Document name.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure other than not found.
	 */
	public function get( string $doctype, string $name ): ?array {
		$response = $this->api->get(
			'/api/resource/' . rawurlencode( $doctype ) . '/' . rawurlencode( $name ),
			array(),
			null,
			array(
				'allow'  => array( 404 ),
				'action' => $this->reading( $doctype ),
			)
		);
		if ( 404 === $response->status() ) {
			return null;
		}
		$data = $response->json()['data'] ?? null;
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Insert a document (a draft for the submittable DocTypes).
	 *
	 * @param string              $doctype DocType.
	 * @param array<string,mixed> $doc     Fields and child tables.
	 * @return array<string,mixed> The stored document.
	 *
	 * @throws ApiException On failure.
	 */
	public function insert( string $doctype, array $doc ): array {
		$response = $this->api->post( '/api/resource/' . rawurlencode( $doctype ), array(), $doc, array( 'action' => sprintf( 'create %s', $doctype ) ) );
		$data     = $response->json()['data'] ?? null;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Change fields of a document.
	 *
	 * @param string              $doctype DocType.
	 * @param string              $name    Document name.
	 * @param array<string,mixed> $fields  Fields to change.
	 * @return array<string,mixed> The stored document.
	 *
	 * @throws ApiException On failure.
	 */
	public function update( string $doctype, string $name, array $fields ): array {
		$response = $this->api->put( '/api/resource/' . rawurlencode( $doctype ) . '/' . rawurlencode( $name ), array(), $fields, array( 'action' => sprintf( 'change %s', $doctype ) ) );
		$data     = $response->json()['data'] ?? null;
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Submit a draft (docstatus 0 to 1).
	 *
	 * @param string $doctype DocType.
	 * @param string $name    Document name.
	 * @return array<string,mixed> The stored document.
	 *
	 * @throws ApiException On failure.
	 */
	public function submit( string $doctype, string $name ): array {
		return $this->update( $doctype, $name, array( 'docstatus' => 1 ) );
	}

	/**
	 * Call a whitelisted method with GET.
	 *
	 * @param string              $method  Dotted path, for example frappe.auth.get_logged_user.
	 * @param array<string,mixed> $params  Query parameters.
	 * @param array<int,int>      $allow   Statuses returned as a normal answer.
	 * @return mixed The `message` of the answer, or null when the status was allowed and not a success.
	 *
	 * @throws ApiException On failure.
	 */
	public function call( string $method, array $params = array(), array $allow = array() ): mixed {
		$response = $this->api->get( '/api/method/' . $method, $params, null, array( 'allow' => $allow ) );
		return $response->isSuccess() ? ( $response->json()['message'] ?? null ) : null;
	}

	/**
	 * The shared client, for a caller that needs the redaction or the base address.
	 */
	public function api(): ApiClient {
		return $this->api;
	}

	/**
	 * Encode filters or fields as the JSON text Frappe reads.
	 *
	 * @param array<int|string,mixed> $value Value.
	 */
	private function json( array $value ): string {
		$text = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $text ? '[]' : $text;
	}

	/**
	 * What a 403 on a read refused, for the message.
	 *
	 * @param string $doctype DocType.
	 */
	private function reading( string $doctype ): string {
		return sprintf( 'read %s', $doctype );
	}
}
