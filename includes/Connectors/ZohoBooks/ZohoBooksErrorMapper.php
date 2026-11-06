<?php
/**
 * Turns Zoho Books error answers into catalog errors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ZohoBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Zoho Books answers with {code, message}; code 0 is success. Codes 44 (per minute), 45 (per day) and 1070 (concurrency) come with 429
 * and no Retry-After (third-party guides); 6041 is an unknown organisation id (Zoho Deluge page).
 */
final class ZohoBooksErrorMapper {

	/**
	 * Waits in seconds by limit code.
	 */
	private const WAITS = array(
		44   => 60,
		45   => 3600,
		1070 => 10,
	);

	/**
	 * Map a failed answer; null leaves it to the default HTTP mapping (5xx, 408).
	 *
	 * @param ApiResponse $response Failed answer.
	 */
	public static function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		$body   = $response->json();
		$code   = (int) ( $body['code'] ?? 0 );
		$text   = trim( (string) ( $body['message'] ?? '' ) );
		if ( 429 === $status || isset( self::WAITS[ $code ] ) ) {
			$wait = $response->retryAfterSeconds() ?? ( self::WAITS[ $code ] ?? 60 );
			return new ConnectorError( ErrorCode::Rate001, array( 'n' => $wait ), 429, $wait, null, 'rate limited' );
		}
		if ( $status >= 500 ) {
			return null;
		}
		if ( 403 === $status || ( 401 === $status && 1 === preg_match( '/scope|permission/i', $text ) ) ) {
			return new ConnectorError( ErrorCode::Auth004, array( 'action' => __( 'do this', 'estasoft-enterprise-erpcrm-sync-engine' ) ), $status, null, null, 'forbidden' );
		}
		if ( 401 === $status ) {
			return new ConnectorError( ErrorCode::Auth002, array(), 401, null, null, 'unauthorized' );
		}
		if ( 6041 === $code ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'field'  => 'organization_id',
					'reason' => __( 'Zoho Books does not know this organisation.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				$status,
				null,
				null,
				'unknown organisation'
			);
		}
		if ( ( $status >= 400 && 408 !== $status ) || $code > 0 ) {
			return self::failure( $code, $text, $status );
		}
		return null;
	}

	/**
	 * A validation error from a code and message.
	 *
	 * @param int    $code   Zoho code.
	 * @param string $text   Zoho message.
	 * @param int    $status HTTP status.
	 */
	public static function failure( int $code, string $text, int $status ): ConnectorError {
		$field = 1 === preg_match( '/\b(?:for|of|in)\s+([a-z_]+)\b/i', $text, $parts ) ? strtolower( $parts[1] ) : 'request';
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
			$status > 0 ? $status : 400,
			null,
			null,
			'zoho code ' . $code
		);
	}
}
