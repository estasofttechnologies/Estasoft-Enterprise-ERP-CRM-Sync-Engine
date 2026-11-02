<?php
/**
 * Turns the error answers of an Acumatica site into connector errors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Acumatica;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * 422 carries field errors: the submitted `value` and an `error` text on the field that was refused. 500 carries `message`,
 * `exceptionMessage` and `exceptionType` (a business exception such as PXSetPropertyException is not a server fault and is not retried).
 * 401, 403 and 429 are left to the base mapping (a refresh and a sign-in, a missing right, a wait). Only a short reason is kept; the
 * shared client removes addresses, tenant names and secrets from it.
 */
final class AcumaticaErrorMapper {

	/**
	 * Map an answer that is not a success.
	 *
	 * @param ApiResponse $response Response.
	 * @param string      $system   System name.
	 * @return ConnectorError|null Null to use the base mapping.
	 */
	public function map( ApiResponse $response, string $system ): ?ConnectorError {
		$status = $response->status();
		if ( in_array( $status, array( 401, 403, 429 ), true ) ) {
			return null;
		}
		$json = $response->json();
		$text = $this->message( $json );
		if ( 1 === preg_match( '/API Login Limit|maximum number of API (users|sessions)|concurrent API (users|sessions)/i', $text . ' ' . $response->body() ) ) {
			return new ConnectorError(
				ErrorCode::Rate001,
				array(
					'system' => $system,
					'n'      => 60,
				),
				$status,
				60,
				null,
				'api login limit'
			);
		}
		if ( 404 === $status && $this->isHtml( $response->body() ) ) {
			return $this->refused( $system, 'address', __( 'This address did not answer like an Acumatica site, or the endpoint version is not offered. Check the address and the endpoint version.', 'estasoft-erp-crm-sync-engine' ), $status );
		}
		if ( 404 === $status ) {
			return $this->refused( $system, 'endpoint_version', '' !== $text ? $text : __( 'Acumatica does not offer this entity or endpoint version.', 'estasoft-erp-crm-sync-engine' ), $status );
		}
		if ( 422 === $status || 400 === $status ) {
			return $this->refused( $system, 'request', '' !== $text ? $text : __( 'Acumatica refused the data.', 'estasoft-erp-crm-sync-engine' ), $status );
		}
		if ( 500 === $status && $this->isBusinessError( $json, $text ) ) {
			return $this->refused( $system, 'request', $text, $status );
		}
		return null;
	}

	/**
	 * The most useful short message of an answer.
	 *
	 * @param array<string,mixed> $json Decoded body.
	 */
	public function message( array $json ): string {
		$field = AcumaticaValue::firstError( $json );
		if ( '' !== $field ) {
			return $this->plain( $field );
		}
		foreach ( array( 'exceptionMessage', 'message', 'Message' ) as $key ) {
			if ( isset( $json[ $key ] ) && is_string( $json[ $key ] ) && '' !== trim( $json[ $key ] ) && 'An error has occurred.' !== trim( $json[ $key ] ) ) {
				return $this->plain( $json[ $key ] );
			}
		}
		if ( isset( $json['innerException'] ) && is_array( $json['innerException'] ) ) {
			return $this->message( $json['innerException'] );
		}
		return '';
	}

	/**
	 * Whether a 500 is a business refusal rather than a server fault.
	 *
	 * @param array<string,mixed> $json Decoded body.
	 * @param string              $text Message.
	 */
	private function isBusinessError( array $json, string $text ): bool {
		if ( '' === $text || 1 === preg_match( '/time-?out|deadlock|connection|unavailable|temporarily/i', $text ) ) {
			return false;
		}
		$type = isset( $json['exceptionType'] ) && is_string( $json['exceptionType'] ) ? $json['exceptionType'] : '';
		return 1 === preg_match( '/PXSetPropertyException|PXOuterException|PXRowPersistingException|PXException|PXRestrictionViolation|PXLockViolation/', $type )
			|| 1 === preg_match( '/cannot be (found|empty)|is required|does not exist|already exists|Error:/i', $text );
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
	 * Whether a body is an HTML page.
	 *
	 * @param string $body Body.
	 */
	private function isHtml( string $body ): bool {
		return 1 === preg_match( '/^\s*(<!doctype|<html|<head|<body)/i', $body );
	}

	/**
	 * A SYNC-VAL-002 error.
	 *
	 * @param string $system System name.
	 * @param string $field  Field.
	 * @param string $reason Reason.
	 * @param int    $status HTTP status.
	 */
	private function refused( string $system, string $field, string $reason, int $status ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => $system,
				'field'  => $field,
				'reason' => $reason,
			),
			$status,
			null,
			null,
			'acumatica ' . $status
		);
	}
}
