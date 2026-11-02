<?php
/**
 * The failure shapes the three Microsoft services share.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiResponse;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Business Central, Dataverse and Finance and Operations answer with an OData error `{"error":{"code":"…","message":"…"}}`. The code is
 * often empty or a hex number and is not used to decide anything except where a connector says so. The plain HTTP mapping (429 with the wait,
 * 5xx, 403, timeouts) is the engine's; this class only corrects the parts that differ for an app that signs in with client credentials.
 */
final class MicrosoftErrors {

	/**
	 * The message of an OData error, or an empty string.
	 *
	 * @param ApiResponse $response Response.
	 */
	public static function message( ApiResponse $response ): string {
		$error = $response->json()['error'] ?? null;
		if ( is_array( $error ) ) {
			return is_string( $error['message'] ?? null ) ? (string) $error['message'] : '';
		}
		return is_string( $error ) ? $error : '';
	}

	/**
	 * The code of an OData error, or an empty string.
	 *
	 * @param ApiResponse $response Response.
	 */
	public static function code( ApiResponse $response ): string {
		$error = $response->json()['error'] ?? null;
		return is_array( $error ) && is_scalar( $error['code'] ?? null ) ? (string) $error['code'] : '';
	}

	/**
	 * The mapping that is the same for all three services.
	 *
	 * - 401 after a token was refreshed once: the token was accepted by Microsoft but not by the service, which means the app is not
	 *   registered or enabled there (SYNC-AUTH-007). The engine's default would say "sign-in expired, reconnect", which is wrong for an
	 *   app that has no sign-in page.
	 * - 409 (edit conflict) and 412 with a concurrency message: another change got in first; the queue retries (SYNC-NET-002).
	 *
	 * @param ApiResponse $response Response that is not a success.
	 * @param string      $system   System name.
	 */
	public static function common( ApiResponse $response, string $system ): ?ConnectorError {
		$status = $response->status();
		if ( 401 === $status ) {
			return new ConnectorError( ErrorCode::Auth007, array( 'system' => $system ), 401, null, null, 'unauthorized' );
		}
		if ( 409 === $status || ( 412 === $status && 1 === preg_match( '/concurrency|etag|precondition|modified/i', self::message( $response ) ) ) ) {
			return new ConnectorError(
				ErrorCode::Net002,
				array(
					'system' => $system,
					'status' => $status,
				),
				$status,
				$response->retryAfterSeconds(),
				null,
				'conflict'
			);
		}
		return null;
	}
}
