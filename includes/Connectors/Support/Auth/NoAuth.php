<?php
/**
 * No credentials.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Auth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiRequest;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * For public endpoints and for the login call of a session system.
 */
final class NoAuth implements AuthStrategy {

	/**
	 * Return the request unchanged.
	 *
	 * @param Connection $connection Connection.
	 * @param ApiRequest $request    Request.
	 */
	public function apply( Connection $connection, ApiRequest $request ): ApiRequest {
		return $request;
	}
}
