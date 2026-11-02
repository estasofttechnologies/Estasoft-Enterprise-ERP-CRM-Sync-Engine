<?php
/**
 * How MYOB's errors become the engine's error codes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * MYOB reports a problem as {"Errors":[{"Name","Message","AdditionalDetails","ErrorCode","Severity"}]} (some answers hold one error at the top level).
 * The name decides. The one that is easy to miss: when a key is over its limit MYOB answers 403 with the name RateLimitError, not 429.
 */
final class MyobErrors {

	/**
	 * The system name used in messages.
	 */
	public const SYSTEM = 'MYOB';

	/**
	 * Seconds to wait after a per-second limit.
	 */
	private const SECOND_WAIT = 5;

	/**
	 * Most seconds to wait after a daily limit.
	 */
	private const DAY_WAIT = 86400;

	/**
	 * The errors of an answer.
	 *
	 * @param array<mixed> $data Decoded answer.
	 * @return array<int,array{name:string,message:string,details:string,code:string}>
	 */
	public static function entries( array $data ): array {
		$list = isset( $data['Errors'] ) && is_array( $data['Errors'] ) ? $data['Errors'] : ( isset( $data['Name'] ) || isset( $data['Message'] ) ? array( $data ) : array() );
		$out  = array();
		foreach ( $list as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}
			$out[] = array(
				'name'    => MyobPayloadReader::text( $error['Name'] ?? '' ),
				'message' => MyobPayloadReader::text( $error['Message'] ?? '' ),
				'details' => MyobPayloadReader::text( $error['AdditionalDetails'] ?? '' ),
				'code'    => MyobPayloadReader::text( $error['ErrorCode'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Whether an answer carries an error with a name.
	 *
	 * @param ApiResponse|null $response Response.
	 * @param string           $name     Error name such as NotFound.
	 */
	public static function has( ?ApiResponse $response, string $name ): bool {
		if ( null === $response ) {
			return false;
		}
		foreach ( self::entries( $response->json() ) as $entry ) {
			if ( 0 === strcasecmp( $entry['name'], $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Map an answer that is not a success. Returns null for the shapes the base already handles well.
	 *
	 * @param ApiResponse $response Response.
	 * @param int         $now      Epoch seconds now (to work out the wait for a daily limit).
	 */
	public static function fromResponse( ApiResponse $response, int $now ): ?ConnectorError {
		$status  = $response->status();
		$entries = self::entries( $response->json() );
		if ( 504 === $status ) {
			return new ConnectorError( ErrorCode::Net001, array( 'system' => self::SYSTEM ), $status, null, null, 'gateway timeout' );
		}
		if ( 429 === $status || $status >= 500 || array() === $entries ) {
			return null;
		}
		$first = $entries[0];
		$name  = strtolower( $first['name'] );
		if ( 403 === $status && 'ratelimiterror' === $name ) {
			return self::rateLimited( $first['message'] . ' ' . $first['details'], $now );
		}
		if ( 403 === $status && 'developerinactive' === $name ) {
			return new ConnectorError( ErrorCode::Auth001, array( 'system' => self::SYSTEM ), $status, null, null, 'developer key inactive' );
		}
		if ( 403 === $status ) {
			return new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => self::SYSTEM,
					'action' => __( 'do this in MYOB (the signed-in user needs access to the company file, and the company file login must be right if the file asks for one)', 'estasoft-erp-crm-sync-engine' ),
				),
				$status,
				null,
				null,
				'access denied'
			);
		}
		if ( 401 === $status ) {
			return null;
		}
		if ( 409 === $status && ( 'incorrectrowversionsupplied' === $name || '111' === $first['code'] ) ) {
			return new ConnectorError(
				ErrorCode::Net002,
				array(
					'system' => self::SYSTEM,
					'status' => $status,
				),
				$status,
				null,
				ErrorClass::Retry,
				'row version'
			);
		}
		$text = '' !== $first['details'] ? $first['message'] . ' ' . $first['details'] : $first['message'];
		return self::refused( '' !== $first['name'] ? $first['name'] : 'request', trim( $text ), $status );
	}

	/**
	 * SYNC-RATE-001 with the wait the text asks for.
	 *
	 * @param string $text Message and details of the error.
	 * @param int    $now  Epoch seconds now.
	 */
	public static function rateLimited( string $text, int $now ): ConnectorError {
		$wait = self::SECOND_WAIT;
		if ( 1 === preg_match( '/\b(daily|per day|a day)\b/i', $text ) && 1 !== preg_match( '/\bsecond\b/i', $text ) ) {
			$wait = max( 60, min( self::DAY_WAIT, self::DAY_WAIT - ( $now % self::DAY_WAIT ) ) );
		}
		return new ConnectorError(
			ErrorCode::Rate001,
			array(
				'system' => self::SYSTEM,
				'n'      => $wait,
			),
			403,
			$wait,
			null,
			'rate limited'
		);
	}

	/**
	 * SYNC-VAL-002 for something MYOB or the payload rejected.
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
	 * SYNC-MAP-001: a product the file does not have.
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
	 * SYNC-VAL-002: a tax code the file does not have.
	 *
	 * @param string $code Tax code.
	 */
	public static function unknownTax( string $code ): ConnectorError {
		return self::refused(
			'tax',
			sprintf(
				/* translators: %s: a MYOB tax code such as GST. */
				__( 'MYOB has no tax code "%s". Choose a code from the list in Tax mapping, then retry.', 'estasoft-erp-crm-sync-engine' ),
				$code
			)
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
