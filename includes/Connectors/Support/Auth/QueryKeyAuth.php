<?php
/**
 * A key sent in the query string (only for systems that offer nothing else).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Auth;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects and fixed texts, not output.

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ApiRequest;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * QueryKeyAuth( 'api_key', 'api_key' ) adds `?api_key=<secret>`. Prefer a header when the vendor supports one: URLs end up in server logs.
 * The client removes the value from every message it builds.
 */
final class QueryKeyAuth implements AuthStrategy {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $param       Query parameter name.
	 * @param string $secretField Name of the secret on the connection.
	 */
	public function __construct( private readonly string $param, private readonly string $secretField ) {
	}

	/**
	 * Add the parameter.
	 *
	 * @param Connection $connection Connection.
	 * @param ApiRequest $request    Request.
	 *
	 * @throws ApiException With SYNC-AUTH-001 when the secret is missing.
	 */
	public function apply( Connection $connection, ApiRequest $request ): ApiRequest {
		$secret = $connection->secret( $this->secretField );
		if ( '' === $secret ) {
			throw new ApiException( MissingSecret::error( $this->secretField ) );
		}
		return $request->withQuery( $this->param, $secret );
	}
}
