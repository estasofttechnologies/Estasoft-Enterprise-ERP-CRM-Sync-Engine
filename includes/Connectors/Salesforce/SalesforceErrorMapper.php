<?php
/**
 * Turns Salesforce error answers into catalog errors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Salesforce;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Salesforce REST answers errors as an array of {message, errorCode, fields} (REST API status codes page). The codes named here are the
 * ones the page or the Salesforce data model gives; API_DISABLED_FOR_ORG is third-party.
 */
final class SalesforceErrorMapper {

	/**
	 * Wait after the 24-hour allocation is used, seconds.
	 */
	private const LIMIT_WAIT = 3600;

	/**
	 * Codes that mean the edition or org has no API access.
	 */
	private const NO_API = array( 'API_DISABLED_FOR_ORG', 'API_CURRENTLY_DISABLED' );

	/**
	 * Codes that mean the user lacks a right.
	 */
	private const NO_RIGHT = array( 'INSUFFICIENT_ACCESS', 'INSUFFICIENT_ACCESS_OR_READONLY', 'INSUFFICIENT_ACCESS_ON_CROSS_REFERENCE_ENTITY', 'NO_ACCESS', 'INVALID_TYPE_ON_FIELD_IN_RECORD' );

	/**
	 * Codes that mean the row is busy and the call may be repeated.
	 */
	private const BUSY = array( 'UNABLE_TO_LOCK_ROW', 'SERVER_UNAVAILABLE', 'SERVICE_UNAVAILABLE' );

	/**
	 * Map a failed answer; null leaves it to the default HTTP mapping (401 after the refresh, 5xx).
	 *
	 * @param ApiResponse $response Failed answer.
	 */
	public static function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		$body   = $response->json();
		$first  = isset( $body[0] ) && is_array( $body[0] ) ? $body[0] : array();
		$code   = strtoupper( (string) ( $first['errorCode'] ?? '' ) );
		if ( 'REQUEST_LIMIT_EXCEEDED' === $code || 429 === $status ) {
			$wait = $response->retryAfterSeconds() ?? self::LIMIT_WAIT;
			return new ConnectorError( ErrorCode::Rate001, array( 'n' => $wait ), 429, $wait, null, 'request limit' );
		}
		if ( in_array( $code, self::NO_API, true ) ) {
			return new ConnectorError( ErrorCode::Auth006, array(), $status, null, null, 'api disabled' );
		}
		if ( 401 === $status ) {
			return new ConnectorError( ErrorCode::Auth002, array(), 401, null, null, 'invalid session' );
		}
		if ( 403 === $status || in_array( $code, self::NO_RIGHT, true ) ) {
			return new ConnectorError( ErrorCode::Auth004, array( 'action' => __( 'do this', 'estasoft-erp-crm-sync-engine' ) ), $status, null, null, 'forbidden' );
		}
		if ( in_array( $code, self::BUSY, true ) ) {
			return new ConnectorError( ErrorCode::Net002, array( 'status' => $status ), $status, 5, null, 'busy' );
		}
		if ( $status >= 400 && $status < 500 && 408 !== $status ) {
			$field = (string) ( is_array( $first['fields'] ?? null ) ? ( $first['fields'][0] ?? '' ) : '' );
			$text  = trim( (string) ( $first['message'] ?? '' ) );
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
				'status ' . $status . ' ' . strtolower( $code )
			);
		}
		return null;
	}
}
