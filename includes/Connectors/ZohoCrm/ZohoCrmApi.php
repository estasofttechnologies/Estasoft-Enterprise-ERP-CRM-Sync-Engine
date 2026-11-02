<?php
/**
 * The few Zoho CRM calls the connector needs.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ZohoCrm;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Version 8 paths under /crm/v8. Search and related lists answer 204 with no body when nothing matches.
 */
final class ZohoCrmApi {

	/**
	 * Largest page Zoho gives for records.
	 */
	private const PAGE = 200;

	/**
	 * Most pages read in one search: Zoho's search returns at most 2,000 records.
	 */
	private const MAX_PAGES = 10;

	/**
	 * Credits Zoho said are left, from the last answer that carried the header.
	 *
	 * @var int|null
	 */
	private ?int $credits = null;

	/**
	 * Create the object.
	 *
	 * @param ApiClient $api Client for the data centre's API host.
	 */
	public function __construct( private readonly ApiClient $api ) {
	}

	/**
	 * Credits left today according to Zoho, when it said (it does from half use).
	 */
	public function credits(): ?int {
		return $this->credits;
	}

	/**
	 * Records found by criteria or by email, across pages.
	 *
	 * @param string $module Module API name.
	 * @param string $param  criteria or email.
	 * @param string $value  Criteria text or email address.
	 * @return array<int,array<string,mixed>>
	 */
	public function search( string $module, string $param, string $value ): array {
		$rows = array();
		for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
			$response = $this->api->get(
				"/crm/v8/{$module}/search",
				array(
					$param     => $value,
					'page'     => $page,
					'per_page' => self::PAGE,
				),
				null,
				array( 'action' => __( 'search records', 'estasoft-erp-crm-sync-engine' ) )
			);
			$this->note( $response );
			$json = $response->json();
			foreach ( (array) ( $json['data'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			if ( empty( $json['info']['more_records'] ) ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * One record, or null when there is none.
	 *
	 * @param string $module Module.
	 * @param string $id     Record id.
	 * @param string $fields Comma separated fields to read.
	 * @return array<string,mixed>|null
	 */
	public function record( string $module, string $id, string $fields ): ?array {
		$response = $this->api->get( "/crm/v8/{$module}/" . rawurlencode( $id ), array( 'fields' => $fields ), null, array( 'allow' => array( 204, 404 ) ) );
		$this->note( $response );
		$row = $response->json()['data'][0] ?? null;
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Records of a related list (the notes of a deal), across pages.
	 *
	 * @param string $module Parent module.
	 * @param string $id     Parent id.
	 * @param string $relation Related list API name.
	 * @param string $fields Fields to read.
	 * @return array<int,array<string,mixed>>
	 */
	public function related( string $module, string $id, string $relation, string $fields ): array {
		$rows = array();
		for ( $page = 1; $page <= self::MAX_PAGES * 5; ++$page ) {
			$response = $this->api->get(
				"/crm/v8/{$module}/" . rawurlencode( $id ) . "/{$relation}",
				array(
					'fields'   => $fields,
					'page'     => $page,
					'per_page' => self::PAGE,
				),
				null,
				array( 'action' => __( 'read related records', 'estasoft-erp-crm-sync-engine' ) )
			);
			$this->note( $response );
			$json = $response->json();
			foreach ( (array) ( $json['data'] ?? array() ) as $row ) {
				if ( is_array( $row ) ) {
					$rows[] = $row;
				}
			}
			if ( empty( $json['info']['more_records'] ) ) {
				break;
			}
		}
		return $rows;
	}

	/**
	 * Insert one record. A duplicate answer returns the existing record's id when the caller tolerates it.
	 *
	 * @param string              $module             Module.
	 * @param array<string,mixed> $record             Fields by API name.
	 * @param bool                $tolerateDuplicate  Return the existing id on DUPLICATE_DATA instead of failing.
	 * @return array{id:string,duplicate:bool}
	 * @throws ApiException When Zoho refuses the record.
	 */
	public function insert( string $module, array $record, bool $tolerateDuplicate = false ): array {
		$response = $this->api->post(
			"/crm/v8/{$module}",
			array(),
			array( 'data' => array( $record ) ),
			array(
				'allow'  => array( 400 ),
				'action' => __( 'create records', 'estasoft-erp-crm-sync-engine' ),
			)
		);
		$this->note( $response );
		$row = (array) ( $response->json()['data'][0] ?? array() );
		if ( $response->isSuccess() && 'SUCCESS' === strtoupper( (string) ( $row['code'] ?? '' ) ) ) {
			return array(
				'id'        => (string) ( $row['details']['id'] ?? '' ),
				'duplicate' => false,
			);
		}
		$existing = (string) ( $row['details']['id'] ?? $row['details']['duplicate_record']['id'] ?? '' );
		if ( $tolerateDuplicate && 'DUPLICATE_DATA' === strtoupper( (string) ( $row['code'] ?? '' ) ) && '' !== $existing ) {
			return array(
				'id'        => $existing,
				'duplicate' => true,
			);
		}
		throw new ApiException( ZohoCrmErrorMapper::record( $row, $response->status() ), $response ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
	}

	/**
	 * Change fields of a record.
	 *
	 * @param string              $module Module.
	 * @param string              $id     Record id.
	 * @param array<string,mixed> $record Fields to change.
	 */
	public function update( string $module, string $id, array $record ): void {
		$this->note( $this->api->put( "/crm/v8/{$module}/" . rawurlencode( $id ), array(), array( 'data' => array( $record ) ), array( 'action' => __( 'change records', 'estasoft-erp-crm-sync-engine' ) ) ) );
	}

	/**
	 * Delete a record.
	 *
	 * @param string $module Module.
	 * @param string $id     Record id.
	 */
	public function delete( string $module, string $id ): void {
		$this->note( $this->api->delete( "/crm/v8/{$module}", array( 'ids' => $id ), null, array( 'action' => __( 'delete records', 'estasoft-erp-crm-sync-engine' ) ) ) );
	}

	/**
	 * The organisation (name and currency).
	 *
	 * @return array<string,mixed>
	 */
	public function org(): array {
		$response = $this->api->get( '/crm/v8/org', array(), null, array( 'action' => __( 'read the organisation', 'estasoft-erp-crm-sync-engine' ) ) );
		$this->note( $response );
		$row = $response->json()['org'][0] ?? array();
		return is_array( $row ) ? $row : array();
	}

	/**
	 * The layouts of a module (their ids are needed to read the pipelines).
	 *
	 * @param string $module Module API name.
	 * @return array<int,array<string,mixed>>
	 * @throws ApiException When Zoho refuses the call (scope ZohoCRM.settings.layouts.READ).
	 */
	public function layouts( string $module ): array {
		$response = $this->api->get( '/crm/v8/settings/layouts', array( 'module' => $module ), null, array( 'action' => __( 'read the layouts', 'estasoft-erp-crm-sync-engine' ) ) );
		$this->note( $response );
		return $this->rowsOf( $response->json()['layouts'] ?? array() );
	}

	/**
	 * The pipelines of a layout; layout_id is mandatory (REQUIRED_PARAM_MISSING without it).
	 *
	 * @param string $layoutId Layout id.
	 * @return array<int,array<string,mixed>>
	 * @throws ApiException When Zoho refuses the call (scope ZohoCRM.settings.pipeline.READ).
	 */
	public function pipelines( string $layoutId ): array {
		$response = $this->api->get( '/crm/v8/settings/pipeline', array( 'layout_id' => $layoutId ), null, array( 'action' => __( 'read the pipelines', 'estasoft-erp-crm-sync-engine' ) ) );
		$this->note( $response );
		return $this->rowsOf( $response->json()['pipeline'] ?? array() );
	}

	/**
	 * The fields of a module with their API names.
	 *
	 * @param string $module Module API name.
	 * @return array<int,array<string,mixed>>
	 * @throws ApiException When Zoho refuses the call (scope ZohoCRM.settings.fields.READ).
	 */
	public function fields( string $module ): array {
		$response = $this->api->get( '/crm/v8/settings/fields', array( 'module' => $module ), null, array( 'action' => __( 'read the fields', 'estasoft-erp-crm-sync-engine' ) ) );
		$this->note( $response );
		return $this->rowsOf( $response->json()['fields'] ?? array() );
	}

	/**
	 * Create one custom field in a module.
	 *
	 * @param string              $module Module API name.
	 * @param array<string,mixed> $field  field_label, data_type, length.
	 * @throws ApiException When Zoho refuses the field (scope ZohoCRM.settings.fields.CREATE, the edition's field cap, a name in use).
	 */
	public function createField( string $module, array $field ): void {
		$response = $this->api->post(
			'/crm/v8/settings/fields',
			array( 'module' => $module ),
			array( 'fields' => array( $field ) ),
			array(
				'allow'  => array( 400 ),
				'action' => __( 'create fields', 'estasoft-erp-crm-sync-engine' ),
			)
		);
		$this->note( $response );
		$row = (array) ( $response->json()['fields'][0] ?? array() );
		if ( ! $response->isSuccess() || 'SUCCESS' !== strtoupper( (string) ( $row['code'] ?? '' ) ) ) {
			throw new ApiException( ZohoCrmErrorMapper::record( $row, $response->status() ), $response ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
	}

	/**
	 * Run a COQL query and return its rows and whether more follow.
	 *
	 * @param string $query COQL select.
	 * @return array{rows:array<int,array<string,mixed>>,more:bool}
	 */
	public function coql( string $query ): array {
		$response = $this->api->post( '/crm/v8/coql', array(), array( 'select_query' => $query ), array( 'action' => __( 'read records', 'estasoft-erp-crm-sync-engine' ) ) );
		$this->note( $response );
		$json = $response->json();
		return array(
			'rows' => array_values( array_filter( (array) ( $json['data'] ?? array() ), 'is_array' ) ),
			'more' => ! empty( $json['info']['more_records'] ),
		);
	}

	/**
	 * Whether the token may read a module (a scope or permission refusal means no).
	 *
	 * @param string $module Module.
	 * @param string $field  One field to ask for.
	 * @throws ApiException For any other failure.
	 */
	public function readable( string $module, string $field ): bool {
		try {
			$this->note(
				$this->api->get(
					"/crm/v8/{$module}",
					array(
						'fields'   => $field,
						'per_page' => 1,
					)
				)
			);
			return true;
		} catch ( ApiException $exception ) {
			if ( ErrorCode::Auth004 === $exception->error()->code ) {
				return false;
			}
			throw $exception;
		}
	}

	/**
	 * The array rows of a decoded list.
	 *
	 * @param mixed $decoded Decoded list.
	 * @return array<int,array<string,mixed>>
	 */
	private function rowsOf( mixed $decoded ): array {
		return is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_array' ) ) : array();
	}

	/**
	 * Remember the credits header.
	 *
	 * @param ApiResponse $response Answer.
	 */
	private function note( ApiResponse $response ): void {
		$left = $response->header( 'X-API-CREDITS-REMAINING' );
		if ( null !== $left && ctype_digit( trim( $left ) ) ) {
			$this->credits = (int) trim( $left );
		}
	}
}
