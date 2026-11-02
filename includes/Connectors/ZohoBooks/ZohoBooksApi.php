<?php
/**
 * The Zoho Books calls the connector needs.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ZohoBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Every call under /books/v3 carries the organisation id; a body with a non-zero `code` is a failure even when the status says 200.
 */
final class ZohoBooksApi {

	/**
	 * Largest page Zoho Books gives.
	 */
	private const PAGE = 200;

	/**
	 * Most pages read in one list, a guard against a loop.
	 */
	private const MAX_PAGES = 100;

	/**
	 * Create the object.
	 *
	 * @param ApiClient $api            Client for the data centre's API host.
	 * @param string    $organizationId Organisation id; empty when none was chosen.
	 * @param string    $system         Name of the system for messages.
	 */
	public function __construct( private readonly ApiClient $api, private readonly string $organizationId, private readonly string $system = 'Zoho Books' ) {
	}

	/**
	 * The organisations the user belongs to (the one call that needs no organisation id).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function organizations(): array {
		$rows = $this->api->get( '/books/v3/organizations', array(), null, array( 'action' => __( 'read organisations', 'estasoft-erp-crm-sync-engine' ) ) )->json()['organizations'] ?? array();
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * Every row of a list, across pages.
	 *
	 * @param string              $path   Path below /books/v3.
	 * @param string              $key    Key of the rows in the answer.
	 * @param array<string,mixed> $filter Filters.
	 * @return array<int,array<string,mixed>>
	 * @throws ApiException When Zoho refuses a call.
	 */
	public function all( string $path, string $key, array $filter = array() ): array {
		$rows = array();
		for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
			$json = $this->call(
				'GET',
				$path,
				$filter + array(
					'page'     => $page,
					'per_page' => self::PAGE,
				)
			);
			foreach ( (array) ( $json[ $key ] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			if ( empty( $json['page_context']['has_more_page'] ) ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * One call; returns the decoded body.
	 *
	 * @param string              $method GET, POST, PUT or DELETE.
	 * @param string              $path   Path below /books/v3.
	 * @param array<string,mixed> $query  Query parameters.
	 * @param array<string,mixed> $body   JSON body.
	 * @param bool                $missingOk Return an empty answer for a 404 instead of failing.
	 * @return array<string,mixed>
	 * @throws ApiException When Zoho refuses the call.
	 */
	public function call( string $method, string $path, array $query = array(), array $body = array(), bool $missingOk = false ): array {
		if ( '' === $this->organizationId ) {
			$error = new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => $this->system,
					'field'  => 'organization_id',
					'reason' => __( 'Choose the Zoho Books organisation in the connection settings.', 'estasoft-erp-crm-sync-engine' ),
				),
				null,
				null,
				null,
				'no organisation'
			);
			throw new ApiException( $error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$query = array( 'organization_id' => $this->organizationId ) + $query;
		$url   = '/books/v3/' . ltrim( $path, '/' );
		$opt   = array(
			'action' => __( 'use this data', 'estasoft-erp-crm-sync-engine' ),
			'allow'  => $missingOk ? array( 404 ) : array(),
		);
		$resp  = match ( $method ) {
			'POST'   => $this->api->post( $url, $query, array() === $body ? null : $body, $opt ),
			'PUT'    => $this->api->put( $url, $query, array() === $body ? null : $body, $opt ),
			'DELETE' => $this->api->delete( $url, $query, null, $opt ),
			default  => $this->api->get( $url, $query, null, $opt ),
		};
		return $missingOk && 404 === $resp->status() ? array() : $this->checked( $resp );
	}

	/**
	 * Decode a successful answer and fail on a non-zero Zoho code.
	 *
	 * @param ApiResponse $response Answer.
	 * @return array<string,mixed>
	 * @throws ApiException When the body says the call failed.
	 */
	private function checked( ApiResponse $response ): array {
		$json = $response->json();
		$code = (int) ( $json['code'] ?? 0 );
		if ( 0 !== $code ) {
			throw new ApiException( ZohoBooksErrorMapper::map( $response ) ?? ZohoBooksErrorMapper::failure( $code, (string) ( $json['message'] ?? '' ), $response->status() ), $response ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return $json;
	}
}
