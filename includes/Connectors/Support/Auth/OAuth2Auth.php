<?php
/**
 * Bearer token from an OAuth 2 token endpoint, refreshed when needed.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Auth;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects and fixed texts, not output.

use Estasoft\SyncEngine\Connectors\Support\ApiRequest;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthTokenManager;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * `new OAuth2Auth( $provider )` in authStrategy(); the base gives it the token manager (withTokens) before the first call. A 401 makes the
 * client call onUnauthorized(): the token is refreshed once, the request is sent again, and a second 401 is SYNC-AUTH-002.
 */
final class OAuth2Auth implements AuthStrategy {

	/**
	 * Set up with what it depends on.
	 *
	 * @param OAuthProvider          $provider Provider of the OAuth settings.
	 * @param OAuthTokenManager|null $tokens   Token manager; the base sets it.
	 */
	public function __construct( private readonly OAuthProvider $provider, private readonly ?OAuthTokenManager $tokens = null ) {
	}

	/**
	 * A copy that uses a token manager.
	 *
	 * @param OAuthTokenManager $tokens Token manager.
	 */
	public function withTokens( OAuthTokenManager $tokens ): self {
		return new self( $this->provider, $tokens );
	}

	/**
	 * The provider.
	 */
	public function provider(): OAuthProvider {
		return $this->provider;
	}

	/**
	 * Add `Authorization: Bearer <access token>`, refreshing the token first when less than five minutes remain. The token manager throws an ApiException when no token can be had.
	 *
	 * @param Connection $connection Connection.
	 * @param ApiRequest $request    Request.
	 *
	 * @throws \LogicException When no token manager was given.
	 */
	public function apply( Connection $connection, ApiRequest $request ): ApiRequest {
		if ( null === $this->tokens ) {
			throw new \LogicException( 'OAuth2Auth needs a token manager: return it from AbstractApiConnector::authStrategy().' );
		}
		return $request->withHeader( 'Authorization', 'Bearer ' . $this->tokens->accessToken( $connection, $this->provider ) );
	}

	/**
	 * The system answered 401: get a new token once.
	 *
	 * @param Connection $connection Connection.
	 * @return bool True when a new token was obtained and the request should be sent again.
	 */
	public function onUnauthorized( Connection $connection ): bool {
		if ( null === $this->tokens ) {
			return false;
		}
		$this->tokens->refreshNow( $connection, $this->provider );
		return true;
	}
}
