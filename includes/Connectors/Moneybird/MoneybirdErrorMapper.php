<?php
/**
 * Moneybird error answers, mapped to catalog codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Moneybird;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The documented statuses: 401 missing or invalid authorization, 402 "Account limit reached", 403 "IP blacklisted", 422 validation failed (the body
 * lists the fields), 429 rate limit exceeded [documented]. The body is `{"error": "...", "symbolic": {...}}` or, for validation, field messages
 * (shape [recalled]). Only short sanitised reasons are kept.
 */
final class MoneybirdErrorMapper {

	/**
	 * Map an answer, or return null to let the base map it.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	public function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		if ( 402 === $status ) {
			return new ConnectorError( ErrorCode::Auth006, array( 'system' => MoneybirdConnector::LABEL ), 402, null, null, 'account limit reached' );
		}
		if ( 403 === $status ) {
			return new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => MoneybirdConnector::LABEL,
					'action' => __( 'be used from this server (Moneybird refuses this address, or the token lacks a scope)', 'estasoft-erp-crm-sync-engine' ),
				),
				403,
				null,
				null,
				'forbidden'
			);
		}
		if ( 422 === $status || 400 === $status ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => MoneybirdConnector::LABEL,
					'field'  => __( 'the document', 'estasoft-erp-crm-sync-engine' ),
					'reason' => $this->reason( $response ),
				),
				$status,
				null,
				null,
				'validation failed'
			);
		}
		return null;
	}

	/**
	 * A short reason: the error text, or the fields that have messages.
	 *
	 * @param ApiResponse $response Response.
	 */
	public function reason( ApiResponse $response ): string {
		$json  = $response->json();
		$error = $json['error'] ?? null;
		if ( is_string( $error ) && '' !== trim( $error ) ) {
			return mb_substr( trim( $error ), 0, 160 );
		}
		if ( is_array( $error ) ) {
			$fields = array();
			foreach ( $error as $key => $messages ) {
				$fields[] = is_string( $key ) ? $key . ': ' . implode( ', ', array_map( 'strval', (array) $messages ) ) : implode( ', ', array_map( 'strval', (array) $messages ) );
			}
			return mb_substr( implode( '; ', $fields ), 0, 160 );
		}
		return __( 'Moneybird did not accept the request.', 'estasoft-erp-crm-sync-engine' );
	}
}
