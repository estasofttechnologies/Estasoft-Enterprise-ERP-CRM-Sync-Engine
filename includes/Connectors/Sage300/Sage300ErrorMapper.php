<?php
/**
 * Turns the error bodies of the Sage 300 Web API into connector errors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage300;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The body is `{"error": {"code": "...", "message": {"lang": "en", "value": "..."}}}`. The code decides, the HTTP status second. A page of
 * HTML is not the Web API at all. The message kept is cleaned; the shared client removes addresses and secrets from it.
 */
final class Sage300ErrorMapper {

	/**
	 * Map an answer that is not a success.
	 *
	 * @param ApiResponse $response Response.
	 * @param string      $system   System name.
	 */
	public function map( ApiResponse $response, string $system ): ?ConnectorError {
		$status = $response->status();
		$json   = $response->json();
		$error  = isset( $json['error'] ) && is_array( $json['error'] ) ? $json['error'] : array();
		$code   = isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : '';
		if ( '' === $code ) {
			if ( 1 === preg_match( '/^\s*(<!doctype|<html|<head|<body)/i', $response->body() ) && $status >= 400 && $status < 500 && 401 !== $status && 403 !== $status ) {
				return Problems::refused( $system, 'address', __( 'This address is not a Sage 300 Web API. Check the address and the company ID.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'not the web api' );
			}
			return null;
		}
		$text = $this->message( $error );
		switch ( $code ) {
			case 'Unauthorized':
				return new ConnectorError( ErrorCode::Auth001, array( 'system' => $system ), $status, null, null, 'unauthorized' );
			case 'Forbidden':
				return new ConnectorError(
					ErrorCode::Auth004,
					array(
						'system' => $system,
						'action' => __( 'use this part of Sage 300 (check the Web API, Order Entry and Accounts Receivable rights of the user)', 'estasoft-enterprise-erpcrm-sync-engine' ),
					),
					$status,
					null,
					null,
					'forbidden'
				);
			case 'TooManyRequests':
				return new ConnectorError(
					ErrorCode::Rate001,
					array(
						'system' => $system,
						'n'      => 60,
					),
					$status,
					60,
					null,
					'too many requests'
				);
			case 'InternalServerError':
			case 'DBLinkError':
			case 'SessionError':
				return new ConnectorError(
					ErrorCode::Net002,
					array(
						'system' => $system,
						'status' => $status,
					),
					$status,
					null,
					null,
					'sage300 ' . $code
				);
			case 'RecordDuplicate':
				return new ConnectorError(
					ErrorCode::Dup001,
					array(
						'system' => $system,
						'ref'    => '' !== $text ? $text : $code,
					),
					$status,
					null,
					null,
					'duplicate'
				);
			case 'ResourceNotFound':
				$text = __( 'The Web API does not offer this resource, or the company ID is wrong.', 'estasoft-enterprise-erpcrm-sync-engine' );
				break;
			default:
				break;
		}
		return Problems::refused( $system, 'request', '' !== $text ? $text : $code, 'sage300 ' . $code );
	}

	/**
	 * The message text of an error object.
	 *
	 * @param array<string,mixed> $error The `error` member.
	 */
	private function message( array $error ): string {
		$message = $error['message'] ?? '';
		if ( is_array( $message ) ) {
			$message = $message['value'] ?? '';
		}
		if ( ! is_string( $message ) ) {
			return '';
		}
		$text = html_entity_decode( (string) preg_replace( '/<[^>]*>/', ' ', $message ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
