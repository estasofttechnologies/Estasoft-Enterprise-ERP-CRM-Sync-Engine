<?php
/**
 * Pennylane error answers, mapped to catalog codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Pennylane;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The documented statuses [documented, error-handling-status-codes]: 400 payload problem (do not retry), 401 token missing, invalid or expired,
 * 403 "Missing scope: customer_invoices:all", 404, 409, 422 a business rule (totals, VAT mismatch, invalid VAT code, duplicate reference), 429 with
 * `retry-after`, 500. The body is `{"error": "<code>", "message": "...", "details": {...}}`. Only short sanitised reasons are kept.
 */
final class PennylaneErrorMapper {

	/**
	 * Map an answer, or return null to let the base map it.
	 *
	 * @param ApiResponse $response Response that is not a success.
	 */
	public function map( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		$text   = $this->message( $response );
		if ( 401 === $status && 1 === preg_match( '/expired/i', $text ) ) {
			return new ConnectorError(
				ErrorCode::Auth005,
				array(
					'system' => PennylaneConnector::LABEL,
					'date'   => __( 'its end date', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				401,
				null,
				null,
				'token expired'
			);
		}
		if ( 403 === $status ) {
			$scope = 1 === preg_match( '/Missing scope:\s*([A-Za-z0-9_:]+)/', $text, $parts ) ? $parts[1] : '';
			return new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => PennylaneConnector::LABEL,
					'action' => '' !== $scope
						? sprintf(
							/* translators: %s: name of the Pennylane token scope. */
							__( 'use the token scope %s', 'estasoft-enterprise-erpcrm-sync-engine' ),
							$scope
						)
						: __( 'do this', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				403,
				null,
				null,
				'forbidden'
			);
		}
		if ( 422 === $status && 1 === preg_match( '/vat/i', $text . ' ' . $this->details( $response ) ) && 1 === preg_match( '/invalid|unknown|not.*(found|valid)/i', $text . ' ' . $this->details( $response ) ) ) {
			return new ConnectorError(
				ErrorCode::Map002,
				array(
					'country' => 'VAT',
					'rate'    => '',
					'system'  => PennylaneConnector::LABEL,
				),
				422,
				null,
				null,
				'vat code refused'
			);
		}
		if ( 400 === $status || 409 === $status || 422 === $status ) {
			$details = $this->details( $response );
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => PennylaneConnector::LABEL,
					'field'  => __( 'the document', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'reason' => mb_substr( trim( $text . ( '' !== $details ? ' (' . $details . ')' : '' ) ), 0, 180 ),
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
	 * The error text of an answer.
	 *
	 * @param ApiResponse $response Response.
	 */
	public function message( ApiResponse $response ): string {
		$json = $response->json();
		foreach ( array( 'message', 'error' ) as $key ) {
			if ( isset( $json[ $key ] ) && is_string( $json[ $key ] ) && '' !== trim( $json[ $key ] ) ) {
				return trim( $json[ $key ] );
			}
		}
		return '';
	}

	/**
	 * The field and issue of the details, short.
	 *
	 * @param ApiResponse $response Response.
	 */
	private function details( ApiResponse $response ): string {
		$details = $response->json()['details'] ?? null;
		if ( ! is_array( $details ) ) {
			return '';
		}
		$parts = array();
		foreach ( array( 'field', 'issue' ) as $key ) {
			if ( isset( $details[ $key ] ) && is_scalar( $details[ $key ] ) ) {
				$parts[] = (string) $details[ $key ];
			}
		}
		return implode( ': ', $parts );
	}
}
