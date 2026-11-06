<?php
/**
 * Turns the error body of a Frappe site into a connector error.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Erpnext;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Frappe answers errors with the HTTP status of the exception class (frappe/exceptions.py) and a JSON body that carries `exc_type` and,
 * when the server raised a message, `_server_messages` (a JSON string holding a list of JSON strings, each with a `message`). The
 * mapper keeps the class of the exception and one short, clean message. It is used as the vendor mapper of the shared client; the
 * defaults of the base (401, 403, 429, 5xx, transport) apply to everything this returns null for.
 */
final class ErpnextErrorMapper {

	/**
	 * Exception classes that mean "try again later".
	 *
	 * @var array<int,string>
	 */
	private const RETRY = array( 'TimestampMismatchError', 'DocumentLockedError', 'QueryDeadlockError', 'QueryTimeoutError', 'QueueOverloaded', 'InReadOnlyMode', 'RetryBackgroundJobError', 'TooManyWritesError' );

	/**
	 * Exception classes that mean "the key or secret is wrong".
	 *
	 * @var array<int,string>
	 */
	private const AUTH = array( 'AuthenticationError', 'InvalidAuthorizationHeader', 'InvalidAuthorizationPrefix', 'InvalidAuthorizationToken', 'SessionExpired', 'SessionStopped' );

	/**
	 * Map an answer that is not a success.
	 *
	 * @param ApiResponse $response Response.
	 * @param string      $system   System name.
	 * @return ConnectorError|null Null to use the base mapping.
	 */
	public function map( ApiResponse $response, string $system ): ?ConnectorError {
		$status = $response->status();
		$json   = $response->json();
		$type   = isset( $json['exc_type'] ) && is_string( $json['exc_type'] ) ? $json['exc_type'] : '';

		if ( 429 === $status ) {
			return null;
		}
		if ( '' === $type && $status >= 200 && $status < 500 && $this->looksLikeHtml( $response->body() ) ) {
			return $this->notFrappe( $system, $status );
		}
		if ( in_array( $type, self::AUTH, true ) ) {
			return new ConnectorError( ErrorCode::Auth001, array( 'system' => $system ), $status, null, null, 'frappe ' . $type );
		}
		if ( in_array( $type, self::RETRY, true ) || 408 === $status || 508 === $status ) {
			return new ConnectorError(
				ErrorCode::Net002,
				array(
					'system' => $system,
					'status' => $status,
				),
				$status,
				null,
				null,
				'' !== $type ? 'frappe ' . $type : 'busy'
			);
		}
		if ( 'PermissionError' === $type || 403 === $status ) {
			return null;
		}
		if ( $status >= 500 ) {
			return null;
		}
		if ( $status >= 400 ) {
			$message = $this->message( $json );
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => $system,
					'field'  => '' !== $type ? $type : 'request',
					'reason' => '' !== $message ? $message : __( 'The site refused the request.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				$status,
				null,
				null,
				'' !== $type ? 'frappe ' . $type : 'frappe ' . $status
			);
		}
		return null;
	}

	/**
	 * The first server message of an answer, as plain text.
	 *
	 * @param array<string,mixed> $json Decoded body.
	 */
	public function message( array $json ): string {
		$raw = $json['_server_messages'] ?? null;
		if ( is_string( $raw ) ) {
			$list = json_decode( $raw, true );
			if ( is_array( $list ) ) {
				foreach ( $list as $entry ) {
					$decoded = is_string( $entry ) ? json_decode( $entry, true ) : $entry;
					if ( is_array( $decoded ) && isset( $decoded['message'] ) && is_string( $decoded['message'] ) && '' !== trim( $decoded['message'] ) ) {
						return $this->plain( $decoded['message'] );
					}
				}
			}
		}
		foreach ( array( 'message', 'exception', '_error_message' ) as $key ) {
			if ( isset( $json[ $key ] ) && is_string( $json[ $key ] ) && '' !== trim( $json[ $key ] ) ) {
				return $this->plain( $json[ $key ] );
			}
		}
		return '';
	}

	/**
	 * Text without tags, entities and runs of white space.
	 *
	 * @param string $text Server text.
	 */
	private function plain( string $text ): string {
		$text = html_entity_decode( (string) preg_replace( '/<[^>]*>/', ' ', $text ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Whether a body is an HTML page rather than an API answer.
	 *
	 * @param string $body Body.
	 */
	private function looksLikeHtml( string $body ): bool {
		return 1 === preg_match( '/^\s*(<!doctype|<html|<head|<body)/i', $body );
	}

	/**
	 * The error for an address that answers with a web page instead of the API.
	 *
	 * @param string $system System name.
	 * @param int    $status HTTP status.
	 */
	private function notFrappe( string $system, int $status ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => $system,
				'field'  => 'address',
				'reason' => __( 'This address did not answer like an ERPNext site. Check the address (no /app or /desk at the end).', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			$status,
			null,
			null,
			'not a frappe site'
		);
	}
}
