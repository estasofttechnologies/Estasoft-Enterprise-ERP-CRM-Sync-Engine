<?php
/**
 * Maps sevDesk error answers to catalog codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sevdesk;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The sevDesk API answers errors as `{"message": "..."}` or `{"error": {"message": "..."}}`, and a problem of the request (a number that already exists, a
 * missing field) can arrive as HTTP 500 [third-party]. A 500 whose message reads like a complaint about the request (it says something exists
 * already, is missing, invalid, required, too long and so on, see PROBLEM) is a rejected request that needs a person. Any other 500, whatever its
 * text ("Server Error", "Internal error", "Something went wrong"), is a server fault and is retried with the queue's delays. There is no
 * documented plan-gate answer, so SYNC-AUTH-006 is never produced.
 */
final class SevdeskErrorMapper {

	/**
	 * Words that mark a message as a complaint about the request. Only a message that matches is treated as a rejection.
	 */
	private const PROBLEM = '/already exist|not exist|does not exist|not found|required|missing|must be|must not|mandatory|invalid|not valid|too long|too short|too many|cannot be|can\'t be|not allowed|not permitted|unknown|duplicate|empty|unique|out of range|wrong format|unsupported/i';

	/**
	 * Map an answer, or return null to let the base map it.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	public function map( ApiResponse $response ): ?ConnectorError {
		if ( 500 !== $response->status() ) {
			return null;
		}
		$message = $this->message( $response );
		if ( '' === $message || 1 !== preg_match( self::PROBLEM, $message ) ) {
			return null;
		}
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => SevdeskConnector::LABEL,
				'field'  => __( 'the request', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'reason' => $message,
			),
			500,
			null,
			null,
			'rejected with status 500'
		);
	}

	/**
	 * The message of an answer, short.
	 *
	 * @param ApiResponse $response Response.
	 */
	public function message( ApiResponse $response ): string {
		$json = $response->json();
		$text = '';
		if ( isset( $json['error'] ) && is_array( $json['error'] ) && isset( $json['error']['message'] ) ) {
			$text = (string) $json['error']['message'];
		} elseif ( isset( $json['message'] ) && is_string( $json['message'] ) ) {
			$text = $json['message'];
		}
		return mb_substr( trim( $text ), 0, 160 );
	}
}
