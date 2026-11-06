<?php
/**
 * NetSuite's error shapes as catalog codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * REST errors carry `o:errorDetails[]` with `detail`, `o:errorCode` and `o:errorPath`; the token endpoint answers `{"error": "invalid_client"}`.
 * Only the shapes that need more than the default mapping are handled here; null leaves the rest to the base.
 */
final class NetSuiteErrors {

	/**
	 * Token endpoint errors that mean the credentials, certificate or key are wrong.
	 *
	 * @var array<int,string>
	 */
	private const TOKEN_ERRORS = array( 'invalid_client', 'invalid_request', 'invalid_grant', 'unauthorized_client', 'invalid_scope', 'unsupported_grant_type' );

	/**
	 * Wait in seconds when the concurrency limit answer carries no Retry-After.
	 */
	public const DEFAULT_WAIT = 10;

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $system System name for messages.
	 */
	public function __construct( private readonly string $system ) {
	}

	/**
	 * Map an answer that is not a success.
	 *
	 * @param ApiResponse $response Response.
	 * @return ConnectorError|null Null for the base's default mapping.
	 */
	public function map( ApiResponse $response ): ?ConnectorError {
		$json   = $response->json();
		$status = $response->status();
		$base   = array( 'system' => $this->system );
		$token  = isset( $json['error'] ) && is_string( $json['error'] ) ? strtolower( $json['error'] ) : '';
		if ( '' !== $token && in_array( $token, self::TOKEN_ERRORS, true ) && ! isset( $json['o:errorDetails'] ) ) {
			return new ConnectorError( ErrorCode::Auth001, $base, $status, null, null, 'token endpoint: ' . $token );
		}
		$details = isset( $json['o:errorDetails'][0] ) && is_array( $json['o:errorDetails'][0] ) ? $json['o:errorDetails'][0] : array();
		$code    = strtoupper( (string) ( $details['o:errorCode'] ?? ( $json['o:errorCode'] ?? '' ) ) );
		if ( 'CONCURRENCY_LIMIT_EXCEEDED' === $code ) {
			$wait = $response->retryAfterSeconds() ?? self::DEFAULT_WAIT;
			return new ConnectorError( ErrorCode::Rate001, $base + array( 'n' => $wait ), 429, $wait, null, 'concurrency limit' );
		}
		if ( 'INSUFFICIENT_PERMISSION' === $code ) {
			return new ConnectorError( ErrorCode::Auth004, $base + array( 'action' => __( 'do this', 'estasoft-enterprise-erpcrm-sync-engine' ) ), $status, null, null, 'insufficient permission' );
		}
		if ( in_array( $status, array( 400, 409, 422 ), true ) && '' !== (string) ( $details['detail'] ?? '' ) ) {
			return new ConnectorError(
				ErrorCode::Val002,
				$base + array(
					'field'  => '' !== (string) ( $details['o:errorPath'] ?? '' ) ? (string) $details['o:errorPath'] : __( 'the request', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'reason' => (string) $details['detail'],
				),
				$status,
				null,
				null,
				'status ' . $status . ( '' !== $code ? ' ' . strtolower( $code ) : '' )
			);
		}
		return null;
	}
}
