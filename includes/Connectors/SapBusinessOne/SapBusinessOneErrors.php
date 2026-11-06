<?php
/**
 * The Service Layer's error shapes as catalog codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapBusinessOne;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Errors are `{"error": {"code": -2028, "message": {"lang": "en-us", "value": "..."}}}`. The base maps 401, 429 and 5xx; this class adds the
 * message text for a refused value, a missing authorisation and a locked object.
 */
final class SapBusinessOneErrors {

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
		if ( 401 === $status || 429 === $status || $status >= 500 || 404 === $status ) {
			return null;
		}
		$base = array( 'system' => $this->system );
		$text = $this->message( $response );
		$low  = strtolower( $text );
		if ( 403 === $status || str_contains( $low, 'not authorized' ) || str_contains( $low, 'no permission' ) || str_contains( $low, 'authorization' ) ) {
			return new ConnectorError( ErrorCode::Auth004, $base + array( 'action' => __( 'do this', 'estasoft-enterprise-erpcrm-sync-engine' ) ), $status, null, null, 'authorisation' );
		}
		if ( str_contains( $low, 'locked' ) ) {
			return new ConnectorError( ErrorCode::Net002, $base + array( 'status' => 409 ), 409, null, null, 'object locked' );
		}
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
	 * The text of an error answer.
	 *
	 * @param ApiResponse $response Response.
	 */
	public function message( ApiResponse $response ): string {
		$json    = $response->json();
		$error   = isset( $json['error'] ) && is_array( $json['error'] ) ? $json['error'] : array();
		$message = $error['message'] ?? '';
		if ( is_array( $message ) ) {
			$message = $message['value'] ?? '';
		}
		return is_string( $message ) ? trim( $message ) : '';
	}
}
