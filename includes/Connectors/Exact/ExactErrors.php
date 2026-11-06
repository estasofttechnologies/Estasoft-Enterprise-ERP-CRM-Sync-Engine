<?php
/**
 * Reads the error answers of Exact Online.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Exact;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * The error body is `{"error":{"code":"","message":{"lang":"","value":"text"}}}`. A 429 carries the counters of the minute and of the day as
 * `X-RateLimit-*` headers; the reset moments are epoch milliseconds. Everything not named here keeps the shared mapping.
 */
final class ExactErrors {

	/**
	 * Longest wait returned, in seconds (one day).
	 */
	private const MAX_WAIT = 86400;

	/**
	 * Wait when a 429 carries no usable header.
	 */
	private const DEFAULT_WAIT = 60;

	/**
	 * Map an answer that is not a success; null keeps the shared mapping.
	 *
	 * @param ApiResponse $response Response.
	 * @param Clock       $clock    Time source.
	 */
	public static function map( ApiResponse $response, Clock $clock ): ?ConnectorError {
		$status = $response->status();
		if ( 429 === $status ) {
			$wait = self::wait( $response, $clock );
			return new ConnectorError(
				ErrorCode::Rate001,
				array(
					'system' => ExactConnector::LABEL,
					'n'      => $wait,
				),
				429,
				$wait,
				null,
				'rate limited'
			);
		}
		if ( 400 !== $status && 403 !== $status ) {
			return null;
		}
		$text = self::message( $response );
		if ( '' !== $text && false !== stripos( $text, 'division' ) ) {
			return new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => ExactConnector::LABEL,
					'field'  => 'division',
					'reason' => __( 'The administration is not available to this sign-in. Reconnect and choose it again.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				$status,
				null,
				null,
				'division refused'
			);
		}
		if ( 403 === $status ) {
			return new ConnectorError(
				ErrorCode::Auth004,
				array(
					'system' => ExactConnector::LABEL,
					'action' => __( 'use this part of Exact Online (check the permissions of the app and that the module is in your licence)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				403,
				null,
				null,
				'forbidden'
			);
		}
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => ExactConnector::LABEL,
				'field'  => __( 'the request', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'reason' => '' !== $text ? $text : __( 'Exact Online refused the request.', 'estasoft-enterprise-erpcrm-sync-engine' ),
			),
			$status,
			null,
			null,
			'bad request'
		);
	}

	/**
	 * The text of an error body.
	 *
	 * @param ApiResponse $response Response.
	 */
	public static function message( ApiResponse $response ): string {
		$error = $response->json()['error'] ?? null;
		if ( ! is_array( $error ) ) {
			return '';
		}
		$message = $error['message'] ?? '';
		if ( is_array( $message ) ) {
			$message = $message['value'] ?? '';
		}
		return is_string( $message ) ? trim( $message ) : '';
	}

	/**
	 * Seconds to wait after a 429: until the minute counter resets when that counter is used up, else until the day counter resets.
	 *
	 * @param ApiResponse $response Response.
	 * @param Clock       $clock    Time source.
	 */
	private static function wait( ApiResponse $response, Clock $clock ): int {
		$now   = $clock->now()->getTimestamp();
		$waits = array();
		foreach ( array(
			array( 'X-RateLimit-Minutely-Remaining', 'X-RateLimit-Minutely-Reset' ),
			array( 'X-RateLimit-Remaining', 'X-RateLimit-Reset' ),
		) as $pair ) {
			$remaining = $response->header( $pair[0] );
			$reset     = $response->header( $pair[1] );
			if ( null !== $remaining && '0' === trim( $remaining ) && null !== $reset && ctype_digit( trim( $reset ) ) ) {
				$waits[] = intdiv( (int) trim( $reset ), 1000 ) - $now + 1;
			}
		}
		$after = $response->retryAfterSeconds();
		if ( null !== $after ) {
			$waits[] = $after;
		}
		$wait = array() === $waits ? self::DEFAULT_WAIT : max( $waits );
		return max( 1, min( self::MAX_WAIT, $wait ) );
	}
}
