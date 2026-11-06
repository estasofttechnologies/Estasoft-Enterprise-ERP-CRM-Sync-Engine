<?php
/**
 * Errors of the QuickBooks connector: builders for the cases the connector raises itself, and the mapping of Intuit's faults.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Intuit reports a problem as {"Fault":{"Error":[{"Message","Detail","code","element"}],"type":"ValidationFault"},"time":"..."} (the IPPFault and IPPError
 * classes of Intuit's own SDK). The code decides: 6000 and its neighbours are business validation, 6140 a duplicate document number, 6240 a duplicate
 * name, 5010 a stale object, 3200 an expired token, 3100 an application that is not authorised, 120 a refused permission.
 */
final class QuickBooksErrors {

	/**
	 * The system name used in messages.
	 */
	public const SYSTEM = 'QuickBooks';

	/**
	 * The faults of an answer.
	 *
	 * @param array<mixed> $data Decoded answer.
	 * @return array<int,array{code:string,message:string,detail:string,element:string}>
	 */
	public static function faults( array $data ): array {
		$faults = array();
		$errors = isset( $data['Fault']['Error'] ) && is_array( $data['Fault']['Error'] ) ? $data['Fault']['Error'] : array();
		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}
			$faults[] = array(
				'code'    => QuickBooksPayloadReader::text( $error['code'] ?? '' ),
				'message' => QuickBooksPayloadReader::text( $error['Message'] ?? '' ),
				'detail'  => QuickBooksPayloadReader::text( $error['Detail'] ?? '' ),
				'element' => QuickBooksPayloadReader::text( $error['element'] ?? '' ),
			);
		}
		return $faults;
	}

	/**
	 * Whether an answer carries a fault with a code.
	 *
	 * @param ApiResponse|null $response Response.
	 * @param string           $code     Fault code.
	 */
	public static function has( ?ApiResponse $response, string $code ): bool {
		if ( null === $response ) {
			return false;
		}
		foreach ( self::faults( $response->json() ) as $fault ) {
			if ( $fault['code'] === $code ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Map an answer that is not a success. Returns null for the shapes the base already handles well.
	 *
	 * @param ApiResponse $response Response.
	 */
	public static function fromResponse( ApiResponse $response ): ?ConnectorError {
		$status = $response->status();
		$faults = self::faults( $response->json() );
		if ( array() === $faults || $status >= 500 || 429 === $status ) {
			return null;
		}
		$first = $faults[0];
		if ( '3100' === $first['code'] ) {
			return new ConnectorError( ErrorCode::Auth007, array( 'system' => self::SYSTEM ), $status, null, null, 'application not authorised' );
		}
		if ( '120' === $first['code'] || 403 === $status ) {
			return new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => self::SYSTEM,
					'action' => __( 'do this in QuickBooks (the signed-in user needs access to sales forms)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				$status,
				null,
				null,
				'permission denied'
			);
		}
		if ( 401 === $status ) {
			return null;
		}
		if ( '5010' === $first['code'] ) {
			return new ConnectorError(
				ErrorCode::Net002,
				array(
					'system' => self::SYSTEM,
					'status' => $status,
				),
				$status,
				null,
				ErrorClass::Retry,
				'stale object'
			);
		}
		$text = '' !== $first['detail'] ? $first['message'] . ': ' . $first['detail'] : $first['message'];
		return self::refused( '' !== $first['element'] ? $first['element'] : 'request', $text, $status );
	}

	/**
	 * SYNC-VAL-002 for something QuickBooks or the payload rejected.
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
}
