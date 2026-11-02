<?php
/**
 * Reads the error answers of Fortnox.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Fortnox;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The error body is `{"ErrorInformation":{"error":1,"message":"…","code":2000310}}`. Fortnox answers an invalid or expired token with 403 as
 * well as 401, so the code decides. The licence codes are the only ones read as a plan gate (SYNC-AUTH-006). Everything not named here keeps
 * the shared mapping.
 */
final class FortnoxErrors {

	/**
	 * Codes that mean the token or login is not valid.
	 *
	 * @var array<int,int>
	 */
	private const SIGN_IN = array( 2000310, 2000311, 2003275 );

	/**
	 * Codes that mean the company has no licence for the area or the API.
	 *
	 * @var array<int,int>
	 */
	private const LICENCE = array( 2001101, 2001103 );

	/**
	 * Code for "no access to the scope".
	 */
	private const SCOPE = 2000663;

	/**
	 * Code for "system exception".
	 */
	private const SYSTEM = 1000003;

	/**
	 * Seconds to wait after a 429 when no Retry-After comes (the length of the sliding window).
	 */
	public const DEFAULT_WAIT = 5;

	/**
	 * Map an answer that is not a success; null keeps the shared mapping.
	 *
	 * @param ApiResponse $response Response.
	 */
	public static function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		$base   = array( 'system' => FortnoxConnector::LABEL );
		if ( 429 === $status ) {
			$wait = max( 1, $response->retryAfterSeconds() ?? self::DEFAULT_WAIT );
			return new ConnectorError( ErrorCode::Rate001, $base + array( 'n' => $wait ), 429, $wait, null, 'rate limited' );
		}
		$info = $response->json()['ErrorInformation'] ?? null;
		$code = is_array( $info ) ? (int) ( $info['code'] ?? 0 ) : 0;
		$text = is_array( $info ) && is_string( $info['message'] ?? null ) ? trim( $info['message'] ) : '';
		if ( in_array( $code, self::SIGN_IN, true ) ) {
			return new ConnectorError( ErrorCode::Auth002, $base, $status, null, null, 'token refused' );
		}
		if ( in_array( $code, self::LICENCE, true ) || str_contains( $response->body(), 'error_missing_license' ) || str_contains( $response->body(), 'error_missing_app_license' ) ) {
			return new ConnectorError( ErrorCode::Auth006, $base, $status, null, null, 'licence missing' );
		}
		if ( self::SCOPE === $code ) {
			return new ConnectorError(
				ErrorCode::Auth004,
				$base + array( 'action' => __( 'use this area of Fortnox (the integration was not given this scope: change the connection options and sign in again)', 'estasoft-erp-crm-sync-engine' ) ),
				403,
				null,
				null,
				'scope missing'
			);
		}
		if ( self::SYSTEM === $code ) {
			return new ConnectorError( ErrorCode::Net002, $base + array( 'status' => $status ), $status, null, null, 'system exception' );
		}
		if ( $code > 0 && $status < 500 ) {
			return new ConnectorError(
				ErrorCode::Val002,
				$base + array(
					'field'  => __( 'the request', 'estasoft-erp-crm-sync-engine' ),
					'reason' => '' !== $text ? $text : __( 'Fortnox refused the request.', 'estasoft-erp-crm-sync-engine' ),
				),
				$status,
				null,
				null,
				'fortnox error ' . $code
			);
		}
		return null;
	}
}
