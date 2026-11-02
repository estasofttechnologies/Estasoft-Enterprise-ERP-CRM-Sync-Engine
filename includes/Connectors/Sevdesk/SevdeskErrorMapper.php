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
 * missing field) can arrive as HTTP 500 [third-party]. A 500 whose body carries a message other than the generic "Server Error" is therefore a
 * rejected request that needs a person, not a server fault to retry. There is no documented plan-gate answer, so SYNC-AUTH-006 is never produced.
 */
final class SevdeskErrorMapper {

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
		if ( '' === $message || 0 === strcasecmp( $message, 'Server Error' ) ) {
			return null;
		}
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => SevdeskConnector::LABEL,
				'field'  => __( 'the request', 'estasoft-erp-crm-sync-engine' ),
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
