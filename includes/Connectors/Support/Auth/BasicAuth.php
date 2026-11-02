<?php
/**
 * HTTP Basic authentication.
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
 * Sends `Authorization: Basic base64(user:secret)`. The user is read from the secrets first and from the connection's config second
 * (a user name is usually saved as plain config); the password or key is read from the secrets.
 */
final class BasicAuth implements AuthStrategy {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $userField   Name of the user field (secret or config).
	 * @param string $secretField Name of the secret that holds the password or key.
	 */
	public function __construct( private readonly string $userField, private readonly string $secretField ) {
	}

	/**
	 * Add the header.
	 *
	 * @param Connection $connection Connection.
	 * @param ApiRequest $request    Request.
	 *
	 * @throws ApiException With SYNC-AUTH-001 when the secret, or the user the connector asks for, is missing.
	 */
	public function apply( Connection $connection, ApiRequest $request ): ApiRequest {
		$user   = '' !== $connection->secret( $this->userField ) ? $connection->secret( $this->userField ) : (string) $connection->config( $this->userField, '' );
		$secret = $connection->secret( $this->secretField );
		// A configured user field with no value is the error case; a connector that names no user field sends the key alone.
		if ( '' === $secret || ( '' !== $this->userField && '' === $user ) ) {
			throw new ApiException( MissingSecret::error( $this->secretField ) );
		}
		return $request->withHeader( 'Authorization', 'Basic ' . base64_encode( $user . ':' . $secret ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials, not code.
	}
}
