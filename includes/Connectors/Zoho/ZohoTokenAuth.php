<?php
/**
 * The Zoho authorization header.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Zoho;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiRequest;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Zoho wants `Authorization: Zoho-oauthtoken <token>`, not Bearer. The token comes from the shared token manager, which refreshes it
 * under a lock; one refused call refreshes once and retries.
 */
final class ZohoTokenAuth implements AuthStrategy {

	/**
	 * Create the object.
	 *
	 * @param OAuthProvider     $provider Sign-in settings.
	 * @param OAuthTokenManager $tokens   Token manager.
	 */
	public function __construct( private readonly OAuthProvider $provider, private readonly OAuthTokenManager $tokens ) {
	}

	/**
	 * Sign a request.
	 *
	 * @param Connection $connection Connection.
	 * @param ApiRequest $request    Request.
	 */
	public function apply( Connection $connection, ApiRequest $request ): ApiRequest {
		return $request->withHeader( 'Authorization', 'Zoho-oauthtoken ' . $this->tokens->accessToken( $connection, $this->provider ) );
	}

	/**
	 * Refresh after a 401.
	 *
	 * @param Connection $connection Connection.
	 */
	public function onUnauthorized( Connection $connection ): bool {
		$this->tokens->refreshNow( $connection, $this->provider );
		return true;
	}
}
