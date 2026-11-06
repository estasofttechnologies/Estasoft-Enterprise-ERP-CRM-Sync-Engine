<?php
/**
 * Turns HubSpot error answers into catalog errors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\HubSpot;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * HubSpot answers with {status, message, correlationId, category, errors[]} (documented on the usage pages; the category names are third-party).
 */
final class HubSpotErrorMapper {

	/**
	 * Wait after a burst limit, seconds (HubSpot documents no Retry-After).
	 */
	private const BURST_WAIT = 10;

	/**
	 * Wait after the daily limit, seconds.
	 */
	private const DAILY_WAIT = 3600;

	/**
	 * Map a failed answer; null leaves it to the default HTTP mapping (401 and the 5xx range).
	 *
	 * @param ApiResponse $response Failed answer.
	 */
	public static function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		$body   = $response->json();
		if ( 429 === $status ) {
			$wait = $response->retryAfterSeconds() ?? ( 'DAILY' === strtoupper( (string) ( $body['policyName'] ?? '' ) ) ? self::DAILY_WAIT : self::BURST_WAIT );
			return new ConnectorError( ErrorCode::Rate001, array( 'n' => $wait ), 429, $wait, null, 'rate limited' );
		}
		if ( 403 === $status ) {
			$scopes = array();
			foreach ( (array) ( $body['errors'] ?? array() ) as $error ) {
				foreach ( (array) ( $error['context']['requiredGranularScopes'] ?? array() ) as $scope ) {
					if ( is_string( $scope ) ) {
						$scopes[] = $scope;
					}
				}
			}
			$action = array() !== $scopes
				/* translators: 1: scope names */
				? sprintf( __( 'use the scope %s', 'estasoft-enterprise-erpcrm-sync-engine' ), implode( ', ', array_slice( array_unique( $scopes ), 0, 3 ) ) )
				: __( 'do this', 'estasoft-enterprise-erpcrm-sync-engine' );
			return new ConnectorError( ErrorCode::Auth004, array( 'action' => $action ), 403, null, null, 'forbidden' );
		}
		if ( 423 === $status ) {
			return new ConnectorError( ErrorCode::Net002, array( 'status' => 423 ), 423, 2, null, 'locked' );
		}
		if ( $status >= 400 && $status < 500 && 401 !== $status && 408 !== $status ) {
			$first = (array) ( ( (array) ( $body['errors'] ?? array() ) )[0] ?? array() );
			$name  = $first['context']['propertyName'] ?? null;
			$name  = is_array( $name ) ? ( $name[0] ?? '' ) : $name;
			$field = is_string( $name ) && '' !== $name ? $name : 'request';
			$text  = (string) ( $first['message'] ?? $body['message'] ?? '' );
			if ( '' === $text ) {
				/* translators: 1: HTTP status */
				$text = sprintf( __( 'The system answered with status %d.', 'estasoft-enterprise-erpcrm-sync-engine' ), $status );
			}
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'field'  => $field,
					'reason' => $text,
				),
				$status,
				null,
				null,
				'status ' . $status
			);
		}
		return null;
	}
}
