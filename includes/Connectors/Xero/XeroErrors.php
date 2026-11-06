<?php
/**
 * Errors of the Xero connector: builders for the cases the connector raises itself, and the mapping of Xero's own answers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Xero reports a validation problem as HTTP 400 with {"ErrorNumber":10,"Type":"ValidationException","Message":"...","Elements":[{"ValidationErrors":[{"Message":"..."}]}]}
 * (the Error and Element schemas of Xero's OpenAPI file). A 200 answer can also carry ValidationErrors on an element. A 401 with a
 * WWW-Authenticate header that names insufficient_scope means a granular scope is missing.
 */
final class XeroErrors {

	/**
	 * The system name used in messages.
	 */
	public const SYSTEM = 'Xero';

	/**
	 * Map a Xero answer that is not a success. Returns null for the shapes the base already handles well.
	 *
	 * @param ApiResponse $response Response.
	 */
	public static function fromResponse( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		if ( 401 === $status && false !== stripos( (string) $response->header( 'www-authenticate' ), 'insufficient' ) ) {
			return new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => self::SYSTEM,
					'action' => __( 'use this part of Xero (a permission of the app is missing; add it and sign in again)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				401,
				null,
				null,
				'insufficient scope'
			);
		}
		if ( 400 === $status || 409 === $status || 422 === $status ) {
			$messages = self::validationMessages( $response->json() );
			if ( array() !== $messages ) {
				return self::refused( 'invoice', $messages[0], $status );
			}
		}
		return null;
	}

	/**
	 * The validation messages in a Xero answer: the top message of an exception and every ValidationErrors message of its elements.
	 *
	 * @param array<mixed> $data Decoded answer.
	 * @return array<int,string>
	 */
	public static function validationMessages( array $data ): array {
		$messages = array();
		$elements = isset( $data['Elements'] ) && is_array( $data['Elements'] ) ? $data['Elements'] : array();
		foreach ( self::collect( $data ) as $element ) {
			$elements[] = $element;
		}
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			foreach ( (array) ( $element['ValidationErrors'] ?? array() ) as $problem ) {
				$message = is_array( $problem ) ? XeroPayloadReader::text( $problem['Message'] ?? '' ) : '';
				if ( '' !== $message && ! in_array( $message, $messages, true ) ) {
					$messages[] = $message;
				}
			}
		}
		if ( array() === $messages ) {
			$message = XeroPayloadReader::text( $data['Message'] ?? '' );
			if ( '' !== $message && 'A validation exception occurred' !== $message ) {
				$messages[] = $message;
			}
		}
		return $messages;
	}

	/**
	 * Elements of a list answer that carry validation errors (a 200 answer with summarizeErrors off).
	 *
	 * @param array<mixed> $data Decoded answer.
	 * @return array<int,array<mixed>>
	 */
	private static function collect( array $data ): array {
		$found = array();
		foreach ( array( 'Invoices', 'CreditNotes', 'Contacts', 'Payments', 'Allocations', 'Items' ) as $key ) {
			foreach ( (array) ( $data[ $key ] ?? array() ) as $element ) {
				if ( is_array( $element ) && ! empty( $element['ValidationErrors'] ) ) {
					$found[] = $element;
				}
			}
		}
		return $found;
	}

	/**
	 * SYNC-VAL-002 for something Xero or the payload rejected.
	 *
	 * @param string   $field  Field or object.
	 * @param string   $reason Short reason.
	 * @param int|null $status HTTP status, when there was one.
	 */
	public static function refused( string $field, string $reason, ?int $status = null ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => self::SYSTEM,
				'field'  => $field,
				'reason' => mb_substr( $reason, 0, 240 ),
			),
			$status,
			null,
			null,
			'rejected'
		);
	}

	/**
	 * The exception for a rejection the connector found itself. It carries the ConnectorError; its message is the catalog text, never a body.
	 *
	 * @param ConnectorError   $error    Error.
	 * @param ApiResponse|null $response The answer that caused it, when there was one.
	 */
	public static function exception( ConnectorError $error, ?ApiResponse $response = null ): ApiException {
		return new ApiException( $error, $response );
	}

	/**
	 * SYNC-MAP-001: a product the connection cannot match.
	 *
	 * @param string $sku SKU or a position text.
	 */
	public static function unknownProduct( string $sku ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map001,
			array(
				'sku'    => $sku,
				'system' => self::SYSTEM,
			),
			null,
			null,
			null,
			'unknown sku'
		);
	}

	/**
	 * SYNC-MAP-003: the customer has no email.
	 *
	 * @param string $order Order id or number.
	 */
	public static function noEmail( string $order ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map003,
			array(
				'order'  => $order,
				'system' => self::SYSTEM,
			),
			null,
			null,
			null,
			'email missing'
		);
	}

	/**
	 * Whether Xero's validation text says a contact name is already in use.
	 *
	 * @param array<int,string> $messages Validation messages.
	 */
	public static function nameInUse( array $messages ): bool {
		foreach ( $messages as $message ) {
			if ( false !== stripos( $message, 'already assigned' ) || false !== stripos( $message, 'already exists' ) ) {
				return true;
			}
		}
		return false;
	}
}
