<?php
/**
 * Turns Holded's own error answers into the engine's errors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Connectors\Support\HttpErrorMapper;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Holded answers a success as `{"status":1,"info":"Created","id":"..."}` and a failure as `{"status":0,"info":"..."}`, sometimes inside a 200
 * [third-party]. The mapper reads both, keeps a short reason, and leaves 401, 429 and 5xx to the shared mapper. A 403 is a permission problem
 * (SYNC-AUTH-004) unless its text names the plan and the API together, which is the one sign of a plan that does not include the API
 * (SYNC-AUTH-006).
 */
final class HoldedErrorMapper {

	/**
	 * Map an answer that is not a success.
	 *
	 * @param ApiResponse $response Response.
	 * @return ConnectorError|null Null to use the shared mapping.
	 */
	public function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		if ( 402 === $status ) {
			return new ConnectorError( ErrorCode::Auth006, array( 'system' => HoldedConnector::LABEL ), 402, null, null, 'payment required' );
		}
		if ( 403 === $status ) {
			if ( $this->planProblem( $this->text( $response ) ) ) {
				return new ConnectorError( ErrorCode::Auth006, array( 'system' => HoldedConnector::LABEL ), 403, null, null, 'plan' );
			}
			return new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => HoldedConnector::LABEL,
					'action' => __( 'do this (check the permissions of the API key)', 'estasoft-erp-crm-sync-engine' ),
				),
				403,
				null,
				null,
				'forbidden'
			);
		}
		if ( 404 === $status ) {
			return $this->refused( __( 'a related record', 'estasoft-erp-crm-sync-engine' ), __( 'Holded does not have this record.', 'estasoft-erp-crm-sync-engine' ), 404, 'not found' );
		}
		if ( 400 === $status || 422 === $status ) {
			$reason = $this->reason( $response->json() );
			return $this->refused(
				__( 'the request', 'estasoft-erp-crm-sync-engine' ),
				'' !== $reason ? $reason : HttpErrorMapper::message( $response ),
				$status,
				'validation'
			);
		}
		return null;
	}

	/**
	 * The error inside a 200 answer (`status` 0), or null when the answer is not one.
	 *
	 * @param array<mixed> $json Decoded answer.
	 */
	public function answer( array $json ): ?ConnectorError {
		if ( array_is_list( $json ) || ! array_key_exists( 'status', $json ) || ! is_numeric( $json['status'] ) || 0 !== (int) $json['status'] ) {
			return null;
		}
		$reason = $this->reason( $json );
		return $this->refused(
			__( 'the request', 'estasoft-erp-crm-sync-engine' ),
			'' !== $reason ? $reason : __( 'Holded refused the request.', 'estasoft-erp-crm-sync-engine' ),
			null,
			'status 0'
		);
	}

	/**
	 * The first message of an answer.
	 *
	 * @param array<mixed> $json Decoded answer.
	 */
	public function reason( array $json ): string {
		foreach ( array( 'info', 'message', 'error', 'errors' ) as $key ) {
			$value = $json[ $key ] ?? null;
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return HoldedPayloadReader::shorten( trim( $value ), 300 );
			}
			if ( is_array( $value ) ) {
				foreach ( $value as $inner ) {
					$text = HoldedPayloadReader::text( is_array( $inner ) ? reset( $inner ) : $inner );
					if ( '' !== $text ) {
						return HoldedPayloadReader::shorten( $text, 300 );
					}
				}
			}
		}
		return '';
	}

	/**
	 * Whether the text of a 403 says that the plan does not include the API.
	 *
	 * @param string $text Lower-case text of the answer.
	 */
	private function planProblem( string $text ): bool {
		return 1 === preg_match( '/\b(plan|upgrade|subscription)\b/', $text ) && 1 === preg_match( '/\bapi\b/', $text );
	}

	/**
	 * The text of an answer, in lower case, for matching.
	 *
	 * @param ApiResponse $response Response.
	 */
	private function text( ApiResponse $response ): string {
		$json = $response->json();
		return strtolower( '' !== $this->reason( $json ) ? $this->reason( $json ) : $response->body() );
	}

	/**
	 * A SYNC-VAL-002.
	 *
	 * @param string   $field  Field.
	 * @param string   $reason Reason.
	 * @param int|null $status HTTP status.
	 * @param string   $detail Internal detail.
	 */
	private function refused( string $field, string $reason, ?int $status, string $detail ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => HoldedConnector::LABEL,
				'field'  => $field,
				'reason' => $reason,
			),
			$status,
			null,
			null,
			$detail
		);
	}
}
