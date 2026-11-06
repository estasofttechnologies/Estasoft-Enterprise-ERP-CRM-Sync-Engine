<?php
/**
 * Turns the error answers of the Sage 200 API into connector errors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage200;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The gateway answers 401 or 403 with a text that names the subscription key when the key is missing or unknown to Sage (SYNC-AUTH-007).
 * Validation answers carry a list of messages (`$message`, `message`); the first useful one is kept, cleaned. 401, 403, 429 and 5xx are
 * left to the base mapping.
 */
final class Sage200ErrorMapper {

	/**
	 * Map an answer that is not a success.
	 *
	 * @param ApiResponse $response Response.
	 * @param string      $system   System name.
	 * @return ConnectorError|null Null to use the base mapping.
	 */
	public function map( ApiResponse $response, string $system ): ?ConnectorError {
		$status = $response->status();
		if ( in_array( $status, array( 401, 403 ), true ) && 1 === preg_match( '/subscription key/i', $response->body() ) ) {
			return new ConnectorError( ErrorCode::Auth007, array( 'system' => $system ), $status, null, null, 'subscription key' );
		}
		if ( 401 === $status && 1 === preg_match( '/X-Site|X-Company/i', $response->body() ) ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => $system,
					'field'  => 'site_id',
					'reason' => __( 'Sage 200 did not accept the site and company chosen. Sign in again and choose them.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				$status,
				null,
				null,
				'site or company'
			);
		}
		if ( ! in_array( $status, array( 400, 409, 422 ), true ) ) {
			return null;
		}
		$text = $this->message( $response->jsonValue() );
		if ( '' === $text ) {
			$text = __( 'Sage 200 refused the data.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		if ( 1 === preg_match( '/Unit Selling Price|override prices/i', $text ) ) {
			$text .= ' ' . __( 'The Sage 200 user needs the permission to override prices and discounts on order lines.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => $system,
				'field'  => 'request',
				'reason' => $text,
			),
			$status,
			null,
			null,
			'sage200 ' . $status
		);
	}

	/**
	 * The first useful message in an answer.
	 *
	 * @param mixed $node Decoded JSON.
	 */
	public function message( mixed $node ): string {
		if ( ! is_array( $node ) ) {
			return '';
		}
		foreach ( array( '$message', 'message', 'Message', 'error_description', 'detail', 'title' ) as $key ) {
			if ( isset( $node[ $key ] ) && is_string( $node[ $key ] ) && '' !== trim( $node[ $key ] ) ) {
				return $this->plain( $node[ $key ] );
			}
		}
		foreach ( $node as $child ) {
			$found = $this->message( $child );
			if ( '' !== $found ) {
				return $found;
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
}
