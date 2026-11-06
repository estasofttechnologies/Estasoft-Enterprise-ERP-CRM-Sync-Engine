<?php
/**
 * Turns Pipedrive error answers into catalog errors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Pipedrive;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Pipedrive answers with {success: false, error, error_info, errorCode}; 429 carries x-ratelimit-* headers and no Retry-After (rate limiting page).
 */
final class PipedriveErrorMapper {

	/**
	 * Wait after a burst limit, seconds.
	 */
	private const BURST_WAIT = 5;

	/**
	 * Wait after the daily budget or a Cloudflare block, seconds.
	 */
	private const LONG_WAIT = 900;

	/**
	 * Map a failed answer; null leaves it to the default HTTP mapping (401 and 5xx).
	 *
	 * @param ApiResponse $response Failed answer.
	 */
	public static function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		$body   = $response->json();
		if ( 429 === $status ) {
			$left = $response->header( 'x-ratelimit-remaining' );
			$wait = $response->retryAfterSeconds() ?? ( '0' === trim( (string) $left ) ? self::BURST_WAIT : self::LONG_WAIT );
			return new ConnectorError( ErrorCode::Rate001, array( 'n' => $wait ), 429, $wait, null, 'rate limited' );
		}
		if ( 403 === $status && array() === $body ) {
			return new ConnectorError( ErrorCode::Rate001, array( 'n' => self::LONG_WAIT ), 403, self::LONG_WAIT, null, 'blocked after too many calls' );
		}
		if ( 403 === $status ) {
			return new ConnectorError( ErrorCode::Auth004, array( 'action' => __( 'do this', 'estasoft-enterprise-erpcrm-sync-engine' ) ), 403, null, null, 'forbidden' );
		}
		if ( $status >= 400 && $status < 500 && 401 !== $status && 408 !== $status ) {
			$text = trim( (string) ( $body['error'] ?? '' ) . ' ' . (string) ( $body['error_info'] ?? '' ) );
			if ( '' === $text ) {
				/* translators: 1: HTTP status */
				$text = sprintf( __( 'The system answered with status %d.', 'estasoft-enterprise-erpcrm-sync-engine' ), $status );
			}
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'field'  => 'request',
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
