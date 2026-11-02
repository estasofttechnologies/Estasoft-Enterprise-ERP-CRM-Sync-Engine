<?php
/**
 * Maps Daftra's own error answers to catalog codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\HttpErrorMapper;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Daftra documents 400, 401, 402 (on client creation), 403 and 404 and shows no error body in text. The mapper reads the usual shapes
 * (`message`, `errors`, `validation`, a list or a map of messages), keeps a short reason, and leaves 401, 429 and 5xx to the shared mapper.
 * A 403 is a key-role or permission problem (SYNC-AUTH-004); a 402 is the one documented sign of a plan that does not allow the action
 * (SYNC-AUTH-006).
 */
final class DaftraErrorMapper {

	/**
	 * Map an answer that is not a success.
	 *
	 * @param ApiResponse $response Response.
	 * @return ConnectorError|null Null to use the shared mapping.
	 */
	public function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		if ( 402 === $status ) {
			return new ConnectorError( ErrorCode::Auth006, array( 'system' => DaftraConnector::LABEL ), 402, null, null, 'payment required' );
		}
		if ( 403 === $status ) {
			return new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => DaftraConnector::LABEL,
					'action' => __( 'do this (check the roles of the API key)', 'estasoft-erp-crm-sync-engine' ),
				),
				403,
				null,
				null,
				'forbidden'
			);
		}
		if ( 404 === $status ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => DaftraConnector::LABEL,
					'field'  => __( 'a related record', 'estasoft-erp-crm-sync-engine' ),
					'reason' => __( 'Daftra does not have this record.', 'estasoft-erp-crm-sync-engine' ),
				),
				404,
				null,
				null,
				'not found'
			);
		}
		if ( 400 === $status || 422 === $status ) {
			$found = $this->describe( $response );
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => DaftraConnector::LABEL,
					'field'  => '' !== $found['field'] ? $found['field'] : __( 'the request', 'estasoft-erp-crm-sync-engine' ),
					'reason' => '' !== $found['reason'] ? $found['reason'] : __( 'Daftra could not validate the request.', 'estasoft-erp-crm-sync-engine' ),
				),
				$status,
				null,
				null,
				'validation'
			);
		}
		return null;
	}

	/**
	 * The first field and message of a validation answer.
	 *
	 * @param ApiResponse $response Response.
	 * @return array{field:string,reason:string}
	 */
	public function describe( ApiResponse $response ): array {
		$json = $response->json();
		foreach ( array( 'validation', 'validationErrors', 'errors', 'error' ) as $wrapper ) {
			if ( isset( $json[ $wrapper ] ) ) {
				$inner = $json[ $wrapper ];
				if ( is_string( $inner ) && '' !== $inner ) {
					return array(
						'field'  => '',
						'reason' => $inner,
					);
				}
				if ( is_array( $inner ) && array() !== $inner ) {
					foreach ( $inner as $key => $value ) {
						$text = is_array( $value ) ? DaftraPayloadReader::text( reset( $value ) ) : DaftraPayloadReader::text( $value );
						if ( '' !== $text ) {
							return array(
								'field'  => is_string( $key ) ? $key : '',
								'reason' => $text,
							);
						}
					}
				}
			}
		}
		$message = DaftraPayloadReader::text( $json['message'] ?? '' );
		return array(
			'field'  => '',
			'reason' => '' !== $message ? $message : HttpErrorMapper::message( $response ),
		);
	}
}
