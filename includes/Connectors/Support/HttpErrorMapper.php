<?php
/**
 * The default mapping of an HTTP failure to a catalog code.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\HttpResponse;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * 401 gives SYNC-AUTH-001 (SYNC-AUTH-002 for a sign-in that expired), 403 SYNC-AUTH-004, 408 and transport failures SYNC-NET-001,
 * a certificate failure SYNC-NET-003, 429 SYNC-RATE-001 with the wait, 5xx SYNC-NET-002 and any other status SYNC-VAL-002 with the
 * vendor's message, redacted and cut. A connector refines this in vendorError() for the shapes its vendor documents.
 */
final class HttpErrorMapper {

	/**
	 * Wait in seconds when a 429 carries no Retry-After.
	 */
	public const DEFAULT_WAIT = 30;

	/**
	 * Map a response that is not a success.
	 *
	 * @param ApiResponse $response  Response.
	 * @param string      $system    System name for the message.
	 * @param Redaction   $redaction Secret remover.
	 * @param bool        $signIn    True when a 401 means the sign-in expired (OAuth) and not a wrong key.
	 * @param string      $action    What a 403 refused, for the message ("create invoices").
	 */
	public static function fromResponse( ApiResponse $response, string $system, Redaction $redaction, bool $signIn = false, string $action = '' ): ConnectorError {
		$status = $response->status();
		$base   = array( 'system' => $system );
		if ( 401 === $status ) {
			return new ConnectorError( $signIn ? ErrorCode::Auth002 : ErrorCode::Auth001, $base, 401, null, null, 'unauthorized' );
		}
		if ( 403 === $status ) {
			return new ConnectorError(
				ErrorCode::Auth004,
				$base + array( 'action' => '' !== $action ? $action : __( 'do this', 'estasoft-erp-crm-sync-engine' ) ),
				403,
				null,
				null,
				$redaction->reason( self::message( $response ), 120 )
			);
		}
		if ( 429 === $status ) {
			$wait = $response->retryAfterSeconds() ?? self::DEFAULT_WAIT;
			return new ConnectorError( ErrorCode::Rate001, $base + array( 'n' => $wait ), 429, $wait, null, 'rate limited' );
		}
		if ( 408 === $status ) {
			return new ConnectorError( ErrorCode::Net001, $base, 408, null, null, 'request timeout' );
		}
		if ( $status >= 500 ) {
			return new ConnectorError( ErrorCode::Net002, $base + array( 'status' => $status ), $status, $response->retryAfterSeconds(), null, 'server error' );
		}
		$reason = $redaction->reason( self::message( $response ) );
		return new ConnectorError(
			ErrorCode::Val002,
			$base + array(
				'field'  => __( 'the request', 'estasoft-erp-crm-sync-engine' ),
				'reason' => '' !== $reason ? $reason : sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The system answered with status %d.', 'estasoft-erp-crm-sync-engine' ),
					$status
				),
			),
			$status,
			null,
			null,
			'status ' . $status
		);
	}

	/**
	 * Map a transport failure (status 0).
	 *
	 * @param HttpResponse $response Response with status 0.
	 * @param string       $system   System name.
	 */
	public static function fromTransport( HttpResponse $response, string $system ): ConnectorError {
		$base = array( 'system' => $system );
		if ( $response->timedOut || str_starts_with( $response->error, 'timeout' ) ) {
			return new ConnectorError( ErrorCode::Net001, $base, null, null, null, 'timeout' );
		}
		if ( str_starts_with( $response->error, 'certificate' ) ) {
			return new ConnectorError( ErrorCode::Net003, $base, null, null, null, 'certificate' );
		}
		if ( str_starts_with( $response->error, 'unsafe_url' ) ) {
			return new ConnectorError(
				ErrorCode::Val002,
				$base + array(
					'field'  => 'address',
					'reason' => __( 'The address is not allowed.', 'estasoft-erp-crm-sync-engine' ),
				),
				null,
				null,
				null,
				'unsafe url'
			);
		}
		return new ConnectorError( ErrorCode::Net001, $base, null, null, null, 'connection' );
	}

	/**
	 * The message part of a vendor answer, looked for in the shapes vendors commonly use.
	 *
	 * @param ApiResponse $response Response.
	 */
	public static function message( ApiResponse $response ): string {
		$json = $response->json();
		if ( array() !== $json ) {
			$found = self::findMessage( $json );
			if ( '' !== $found ) {
				return $found;
			}
		}
		$body = trim( $response->body() );
		return mb_substr( $body, 0, 600 );
	}

	/**
	 * Look for a message in a decoded answer.
	 *
	 * @param array<mixed> $data Decoded JSON.
	 */
	private static function findMessage( array $data ): string {
		foreach ( array( 'message', 'Message', 'error_description', 'errorMessage', 'ErrorMessage', 'detail', 'Detail', 'title', 'error', 'Error', 'errors', 'Errors', 'o:errorDetails', 'fault', 'Fault' ) as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$value = $data[ $key ];
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return $value;
			}
			if ( is_array( $value ) ) {
				$nested = self::findMessage( \Estasoft\SyncEngine\Core\Support\Lists::isList( $value ) && isset( $value[0] ) && is_array( $value[0] ) ? $value[0] : $value );
				if ( '' !== $nested ) {
					return $nested;
				}
				if ( \Estasoft\SyncEngine\Core\Support\Lists::isList( $value ) && isset( $value[0] ) && is_string( $value[0] ) ) {
					return $value[0];
				}
			}
		}
		return '';
	}
}
