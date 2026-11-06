<?php
/**
 * Maps Qoyod's own error answers to catalog codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\HttpErrorMapper;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Qoyod documents status codes (200, 201, 400, 404, 500 and more) and not the body of an error. The mapper reads the shapes a Rails API
 * usually sends (`{"errors": {"field": ["message"]}}`, `{"errors": ["message"]}`, `{"error": "message"}`, `{"message": "..."}`), keeps a short
 * reason, and leaves 401, 429 and 5xx to the shared mapper. A reference that "has already been taken" is a duplicate, which the senders
 * resolve by searching again. A 403 is a plan problem only when the body says so in specific words.
 */
final class QoyodErrorMapper {

	/**
	 * Words that, together with "api", mean the plan does not include the API.
	 */
	private const GATE_WORDS = array( 'upgrade', 'your plan', 'subscription', 'not available on', 'not included' );

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
		if ( 404 === $status ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => QoyodConnector::LABEL,
					'field'  => __( 'a related record', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'reason' => __( 'Qoyod does not have this record in the connected company.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				404,
				null,
				null,
				'not found'
			);
		}
		if ( 400 === $status || 422 === $status ) {
			$found = $this->describe( $response );
			if ( 'reference' === $found['field'] && str_contains( strtolower( $found['reason'] ), 'already been taken' ) ) {
				return new ConnectorError(
					ErrorCode::Dup001,
					array(
						'system' => QoyodConnector::LABEL,
						'ref'    => '',
					),
					$status,
					null,
					null,
					'reference taken'
				);
			}
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => QoyodConnector::LABEL,
					'field'  => '' !== $found['field'] ? $found['field'] : __( 'the request', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'reason' => '' !== $found['reason'] ? $found['reason'] : __( 'Qoyod could not validate the request.', 'estasoft-enterprise-erpcrm-sync-engine' ),
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
		foreach ( array( 'errors', 'error' ) as $wrapper ) {
			if ( isset( $json[ $wrapper ] ) ) {
				$inner = $json[ $wrapper ];
				if ( is_string( $inner ) ) {
					return array(
						'field'  => '',
						'reason' => $inner,
					);
				}
				if ( is_array( $inner ) ) {
					$json = $inner;
					break;
				}
			}
		}
		foreach ( $json as $key => $value ) {
			if ( is_array( $value ) && array() !== $value ) {
				$first = reset( $value );
				return array(
					'field'  => is_string( $key ) ? $key : '',
					'reason' => QoyodPayloadReader::text( is_array( $first ) ? (string) wp_json_encode( $first ) : $first ),
				);
			}
			if ( is_string( $value ) && '' !== $value ) {
				return array(
					'field'  => is_string( $key ) && ! in_array( $key, array( 'message', 'error', 'detail' ), true ) ? $key : '',
					'reason' => $value,
				);
			}
		}
		return array(
			'field'  => '',
			'reason' => HttpErrorMapper::message( $response ),
		);
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
					return new ConnectorError( ErrorCode::Auth006, array( 'system' => QoyodConnector::LABEL ), 403, null, null, 'api not in plan' );
				}
			}
		}
		return new ConnectorError(
			ErrorCode::Auth004,
			array(
				'system' => QoyodConnector::LABEL,
				'action' => __( 'do this', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			403,
			null,
			null,
			'forbidden'
		);
	}
}
