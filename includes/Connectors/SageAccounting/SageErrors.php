<?php
/**
 * How Sage's errors become the engine's error codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Sage reports a problem as a list: [{"$severity":"error","$dataCode":"RecordInvalid","$message":"...","$source":"..."}] (the idempotency guide names severity, data
 * code and message; the list may also sit under "$errors"). The list is read in both shapes. A 403 means the signed-in user lacks a role in the business.
 */
final class SageErrors {

	/**
	 * The system name used in messages.
	 */
	public const SYSTEM = 'Sage';

	/**
	 * The errors of an answer.
	 *
	 * @param array<mixed> $data Decoded answer.
	 * @return array<int,array{code:string,message:string,source:string}>
	 */
	public static function entries( array $data ): array {
		$list = isset( $data['$errors'] ) && is_array( $data['$errors'] ) ? $data['$errors'] : ( array_is_list( $data ) ? $data : array() );
		$out  = array();
		foreach ( $list as $error ) {
			if ( ! is_array( $error ) || ! ( isset( $error['$message'] ) || isset( $error['$dataCode'] ) ) ) {
				continue;
			}
			$out[] = array(
				'code'    => SagePayloadReader::text( $error['$dataCode'] ?? '' ),
				'message' => SagePayloadReader::text( $error['$message'] ?? '' ),
				'source'  => SagePayloadReader::text( $error['$source'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Map an answer that is not a success. Returns null for the shapes the base already handles well.
	 *
	 * @param ApiResponse $response Response.
	 */
	public static function fromResponse( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		if ( 403 === $status ) {
			return new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => self::SYSTEM,
					'action' => __( 'do this in Sage (the signed-in user needs a role with access to this part of the business)', 'estasoft-erp-crm-sync-engine' ),
				),
				$status,
				null,
				null,
				'permission denied'
			);
		}
		$entries = self::entries( $response->json() );
		if ( 429 === $status || $status >= 500 || 401 === $status || array() === $entries ) {
			return null;
		}
		$messages = array();
		foreach ( $entries as $entry ) {
			$messages[] = '' !== $entry['message'] ? $entry['message'] : $entry['code'];
		}
		$first = $entries[0];
		return self::refused( '' !== $first['source'] ? $first['source'] : ( '' !== $first['code'] ? $first['code'] : 'request' ), implode( ' ', array_unique( $messages ) ), $status );
	}

	/**
	 * SYNC-VAL-002 for something Sage or the payload rejected.
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
	 * The exception for a rejection the connector found itself.
	 *
	 * @param ConnectorError   $error    Error.
	 * @param ApiResponse|null $response The answer that caused it, when there was one.
	 */
	public static function exception( ConnectorError $error, ?ApiResponse $response = null ): ApiException {
		return new ApiException( $error, $response );
	}

	/**
	 * SYNC-MAP-001: a product Sage does not have.
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
	 * SYNC-MAP-002: a tax rate Sage does not have.
	 *
	 * @param string $country Country of the business, or empty.
	 * @param string $code    Tax rate id.
	 */
	public static function unknownTax( string $country, string $code ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Map002,
			array(
				'country' => $country,
				'rate'    => $code,
				'system'  => self::SYSTEM,
			),
			null,
			null,
			null,
			'unknown tax rate'
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
}
