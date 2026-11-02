<?php
/**
 * ERPNext token authentication.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Erpnext;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiRequest;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\MissingSecret;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Frappe reads `Authorization: token <api_key>:<api_secret>` (docs.frappe.io, REST API, Token based authentication). The base class
 * HeaderKeyAuth takes a single secret, and ERPNext needs the pair, so this small strategy joins them.
 */
final class ErpnextTokenAuth implements AuthStrategy {

	/**
	 * Add the header.
	 *
	 * @param Connection $connection Connection with the key and secret.
	 * @param ApiRequest $request    Request.
	 *
	 * @throws ApiException With SYNC-AUTH-001 when the key or the secret is missing.
	 */
	public function apply( Connection $connection, ApiRequest $request ): ApiRequest {
		$key    = '' !== $connection->secret( 'api_key' ) ? $connection->secret( 'api_key' ) : (string) $connection->config( 'api_key', '' );
		$secret = $connection->secret( 'api_secret' );
		if ( '' === $key || '' === $secret ) {
			$exception = new ApiException( MissingSecret::error( '' === $key ? 'api_key' : 'api_secret' ) );
			throw $exception;
		}
		return $request->withHeader( 'Authorization', 'token ' . $key . ':' . $secret );
	}
}
