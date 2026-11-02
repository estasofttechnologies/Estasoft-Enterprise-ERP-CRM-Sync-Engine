<?php
/**
 * How a request proves who sends it.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Auth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiRequest;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * A strategy adds the credentials of a connection to a request. A strategy may also define the optional method
 * `onUnauthorized( Connection $connection ): bool`, which the client calls once when the system answers 401: refresh the token or sign in
 * again and return true to send the request one more time (the client finds the method with method_exists).
 */
interface AuthStrategy {

	/**
	 * Add the credentials to a request and return the changed copy.
	 *
	 * @param Connection $connection Connection with its secrets.
	 * @param ApiRequest $request    The request.
	 * @return ApiRequest
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException With SYNC-AUTH-001 when a needed secret is missing, or the token error when a token cannot be had.
	 */
	public function apply( Connection $connection, ApiRequest $request ): ApiRequest;
}
