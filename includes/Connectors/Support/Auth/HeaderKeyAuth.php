<?php
/**
 * A key sent in a request header.
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
 * HeaderKeyAuth( 'X-Api-Key', 'api_key' ) sends `X-Api-Key: <secret>`; HeaderKeyAuth( 'Authorization', 'token', 'Token ' ) sends `Authorization: Token <secret>`.
 */
final class HeaderKeyAuth implements AuthStrategy {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $headerName  Header name.
	 * @param string $secretField Name of the secret on the connection.
	 * @param string $prefix      Text put before the secret (for example `Token `).
	 */
	public function __construct( private readonly string $headerName, private readonly string $secretField, private readonly string $prefix = '' ) {
	}

	/**
	 * Add the header.
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
		return $request->withHeader( $this->headerName, $this->prefix . ApiRequest::singleLine( trim( $secret ) ) );
	}
}
