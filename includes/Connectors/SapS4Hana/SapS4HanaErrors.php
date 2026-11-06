<?php
/**
 * OData V2 error bodies of S/4HANA as catalog codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapS4Hana;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Errors are `{"error": {"code": "...", "message": {"lang": "en", "value": "..."}, "innererror": {"errordetails": [{"code", "message", "severity"}]}}}`.
 * The base maps 401, 403, 429 and 5xx; this class adds 503 (throttling, reported by third parties instead of 429), the precondition failures
 * of ETags, and the message text of a refused value.
 */
final class SapS4HanaErrors {

	/**
	 * Wait in seconds when a throttled answer carries no Retry-After.
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
		$status = $response->status();
		$base   = array( 'system' => $this->system );
		if ( 503 === $status ) {
			$wait = $response->retryAfterSeconds() ?? self::DEFAULT_WAIT;
			return new ConnectorError( ErrorCode::Rate001, $base + array( 'n' => $wait ), 503, $wait, null, 'service unavailable' );
		}
		if ( 401 === $status || 403 === $status || 429 === $status || $status >= 500 || 404 === $status ) {
			return null;
		}
		if ( in_array( $status, array( 412, 428 ), true ) ) {
			return new ConnectorError(
				ErrorCode::Val002,
				$base + array(
					'field'  => 'If-Match',
					'reason' => __( 'The record changed while it was being processed. Try again.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				$status,
				null,
				null,
				'etag'
			);
		}
		$text = $this->message( $response );
		if ( '' === $text ) {
			return null;
		}
		return new ConnectorError(
			ErrorCode::Val002,
			$base + array(
				'field'  => __( 'the request', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'reason' => $text,
			),
			$status,
			null,
			null,
			'status ' . $status
		);
	}

	/**
	 * The text of an error answer: the first error detail with severity "error", else the main message.
	 *
	 * @param ApiResponse $response Response.
	 */
	public function message( ApiResponse $response ): string {
		$json  = $response->json();
		$error = isset( $json['error'] ) && is_array( $json['error'] ) ? $json['error'] : array();
		foreach ( (array) ( $error['innererror']['errordetails'] ?? array() ) as $detail ) {
			if ( is_array( $detail ) && 'error' === strtolower( (string) ( $detail['severity'] ?? '' ) ) && '' !== trim( (string) ( $detail['message'] ?? '' ) ) ) {
				return trim( (string) $detail['message'] );
			}
		}
		$message = $error['message'] ?? '';
		if ( is_array( $message ) ) {
			$message = $message['value'] ?? '';
		}
		return is_string( $message ) ? trim( $message ) : '';
	}
}
