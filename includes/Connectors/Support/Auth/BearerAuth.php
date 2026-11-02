<?php
/**
 * A bearer token that never changes (an API token).
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
 * Sends `Authorization: Bearer <secret>`.
 */
final class BearerAuth implements AuthStrategy {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $secretField Name of the secret on the connection.
	 */
	public function __construct( private readonly string $secretField ) {
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
		return $request->withHeader( 'Authorization', 'Bearer ' . ApiRequest::singleLine( trim( $secret ) ) );
	}
}
