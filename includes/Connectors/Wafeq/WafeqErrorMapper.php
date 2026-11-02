<?php
/**
 * Maps Wafeq's own error answers to catalog codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\HttpErrorMapper;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Wafeq documents the meaning of its status codes (400 field errors, 401 bad key, 403 access, 404 missing object, 429, 5xx) and nothing about
 * the body. The mapper reads the usual shapes (`{"field": ["message"]}`, `{"detail": "message"}`, `{"errors": [...]}`), keeps only a short
 * reason, and leaves 401, 429 and 5xx to the shared mapper. A 403 is a plan or switch problem (SYNC-AUTH-006) only when the body says so in
 * specific words; every other 403 is a permission problem (SYNC-AUTH-004).
 */
final class WafeqErrorMapper {

	/**
	 * Words that, together with "api", mean the API is not switched on or not in the plan.
	 */
	private const GATE_WORDS = array( 'not enabled', 'enable the api', 'enable api', 'upgrade', 'your plan', 'subscription', 'not available on' );

	/**
	 * Map an answer that is not a success.
	 *
	 * @param ApiResponse $response Response.
	 * @return ConnectorError|null Null to use the shared mapping.
	 */
	public function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		if ( 403 === $status ) {
			return $this->forbidden( $response );
		}
		if ( 400 === $status ) {
			$found = $this->describe( $response );
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => WafeqConnector::LABEL,
					'field'  => '' !== $found['field'] ? $found['field'] : __( 'the request', 'estasoft-erp-crm-sync-engine' ),
					'reason' => '' !== $found['reason'] ? $found['reason'] : __( 'Wafeq could not validate the request.', 'estasoft-erp-crm-sync-engine' ),
				),
				400,
				null,
				null,
				'validation'
			);
		}
		if ( 404 === $status ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => WafeqConnector::LABEL,
					'field'  => __( 'a related record', 'estasoft-erp-crm-sync-engine' ),
					'reason' => __( 'Wafeq does not have this record in the connected organisation.', 'estasoft-erp-crm-sync-engine' ),
				),
				404,
				null,
				null,
				'not found'
			);
		}
		if ( 409 === $status ) {
			return new ConnectorError(
				ErrorCode::Net002,
				array(
					'system' => WafeqConnector::LABEL,
					'status' => 409,
				),
				409,
				$response->retryAfterSeconds(),
				null,
				'conflict'
			);
		}
		return null;
	}

	/**
	 * A 403.
	 *
	 * @param ApiResponse $response Response.
	 */
	private function forbidden( ApiResponse $response ): ConnectorError {
		$text = strtolower( HttpErrorMapper::message( $response ) );
		if ( str_contains( $text, 'api' ) ) {
			foreach ( self::GATE_WORDS as $word ) {
				if ( str_contains( $text, $word ) ) {
					return new ConnectorError( ErrorCode::Auth006, array( 'system' => WafeqConnector::LABEL ), 403, null, null, 'api not enabled' );
				}
			}
		}
		return new ConnectorError(
			ErrorCode::Auth004,
			array(
				'system' => WafeqConnector::LABEL,
				'action' => __( 'do this', 'estasoft-erp-crm-sync-engine' ),
			),
			403,
			null,
			null,
			'forbidden'
		);
	}

	/**
	 * The first field and message of a validation answer.
	 *
	 * @param ApiResponse $response Response.
	 * @return array{field:string,reason:string}
	 */
	public function describe( ApiResponse $response ): array {
		$json = $response->json();
		foreach ( array( 'errors', 'error' ) as $wrapper ) {
			if ( isset( $json[ $wrapper ] ) && is_array( $json[ $wrapper ] ) ) {
				$json = $json[ $wrapper ];
				break;
			}
		}
		foreach ( $json as $key => $value ) {
			if ( is_array( $value ) && array_is_list( $value ) && array() !== $value ) {
				$first = $value[0];
				if ( is_array( $first ) ) {
					$inner = $this->describe( new ApiResponse( 400, (string) wp_json_encode( $first ) ) );
					return array(
						'field'  => is_string( $key ) ? $key . ( '' !== $inner['field'] ? '.' . $inner['field'] : '' ) : $inner['field'],
						'reason' => $inner['reason'],
					);
				}
				return array(
					'field'  => is_string( $key ) ? $key : '',
					'reason' => WafeqPayloadReader::text( $first ),
				);
			}
			if ( is_string( $value ) && '' !== $value ) {
				return array(
					'field'  => 'detail' === $key || 'message' === $key ? '' : (string) $key,
					'reason' => $value,
				);
			}
		}
		return array(
			'field'  => '',
			'reason' => HttpErrorMapper::message( $response ),
		);
	}
}
