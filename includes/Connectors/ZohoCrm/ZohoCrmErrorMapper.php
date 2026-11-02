<?php
/**
 * Turns Zoho CRM error answers into catalog errors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ZohoCrm;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Zoho CRM answers with {code, message, status, details} or, for records, {data: [{code, details, message, status}]} (insert records page).
 */
final class ZohoCrmErrorMapper {

	/**
	 * Wait after TOO_MANY_REQUESTS, seconds (Zoho documents no Retry-After).
	 */
	private const WAIT = 60;

	/**
	 * Map a failed answer; null leaves it to the default HTTP mapping (5xx, 408).
	 *
	 * @param ApiResponse $response Failed answer.
	 */
	public static function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		$body   = $response->json();
		$row    = isset( $body['data'][0] ) && is_array( $body['data'][0] ) ? $body['data'][0] : $body;
		$code   = strtoupper( (string) ( $row['code'] ?? '' ) );
		if ( 429 === $status || 'TOO_MANY_REQUESTS' === $code ) {
			$wait = $response->retryAfterSeconds() ?? self::WAIT;
			return new ConnectorError( ErrorCode::Rate001, array( 'n' => $wait ), 429, $wait, null, 'rate limited' );
		}
		if ( 401 === $status && 'OAUTH_SCOPE_MISMATCH' === $code ) {
			return new ConnectorError( ErrorCode::Auth004, array( 'action' => __( 'use the access this connection asks for', 'estasoft-erp-crm-sync-engine' ) ), 401, null, null, 'scope mismatch' );
		}
		if ( 401 === $status ) {
			return new ConnectorError( ErrorCode::Auth002, array(), 401, null, null, 'unauthorized' );
		}
		if ( 403 === $status || 'NO_PERMISSION' === $code ) {
			return new ConnectorError( ErrorCode::Auth004, array( 'action' => __( 'do this', 'estasoft-erp-crm-sync-engine' ) ), 403, null, null, 'forbidden' );
		}
		if ( $status >= 400 && $status < 500 && 408 !== $status ) {
			return self::record( $row, $status );
		}
		return null;
	}

	/**
	 * The error for one failed record row.
	 *
	 * @param array<string,mixed> $row    Row with code, message, details.
	 * @param int                 $status HTTP status.
	 */
	public static function record( array $row, int $status ): ConnectorError {
		$details = (array) ( $row['details'] ?? array() );
		$field   = (string) ( $details['api_name'] ?? $details['field'] ?? '' );
		$text    = trim( (string) ( $row['code'] ?? '' ) . ' ' . (string) ( $row['message'] ?? '' ) );
		if ( 'Pipeline' === $field && 'MANDATORY_NOT_FOUND' === strtoupper( (string) ( $row['code'] ?? '' ) ) ) {
			// The Deals module has pipelines on, which makes the Pipeline field mandatory (insert records page).
			$text = __( 'Zoho CRM needs a pipeline on every deal. Enter the pipeline name in the connection settings and test the connection.', 'estasoft-erp-crm-sync-engine' );
		}
		if ( '' === $text ) {
			/* translators: 1: HTTP status */
			$text = sprintf( __( 'The system answered with status %d.', 'estasoft-erp-crm-sync-engine' ), $status );
		}
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'field'  => '' !== $field ? $field : 'request',
				'reason' => $text,
			),
			$status,
			null,
			null,
			'record ' . strtolower( (string) ( $row['code'] ?? 'error' ) )
		);
	}
}
