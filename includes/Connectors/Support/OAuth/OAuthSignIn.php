<?php
/**
 * Sign-in routes for any OAuthConnector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\ErrorBridge;
use Estasoft\SyncEngine\Connectors\Support\HostPolicy;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Rest\OAuth\SignInConnector;

/**
 * Adapts an OAuthConnector to the REST layer's SignInConnector: builds the vendor's sign-in address (state, PKCE S256, redirect, scopes,
 * the vendor's extra parameters), trades the code for tokens through the token manager, and lists the organisations.
 */
final class OAuthSignIn implements SignInConnector {

	/**
	 * Set up with what it depends on.
	 *
	 * @param OAuthConnector    $connector Connector.
	 * @param OAuthTokenManager $tokens    Token manager.
	 * @param string            $label     System name for messages.
	 */
	public function __construct( private readonly OAuthConnector $connector, private readonly OAuthTokenManager $tokens, private readonly string $label ) {
	}

	/**
	 * The vendor's sign-in page, with the one-time state and the PKCE challenge.
	 *
	 * @param Connection $connection   Connection with the client id in its config or secrets.
	 * @param string     $state        One-time state.
	 * @param string     $redirectUri  Redirect address (registered in the vendor's app).
	 * @param string     $codeChallenge PKCE challenge.
	 *
	 * @throws ApiException When the vendor address is refused.
	 */
	public function authorizeUrl( Connection $connection, string $state, string $redirectUri, string $codeChallenge ): string {
		$config = $this->connector->oauthProvider()->config( $connection );
		( new HostPolicy( $config->allowedHosts, $config->merchantHost ) )->assert( $config->authorizeUrl, $this->label );
		$params = array(
			'response_type' => 'code',
			'client_id'     => $config->clientId( $connection ),
			'redirect_uri'  => $redirectUri,
			'state'         => $state,
		);
		if ( array() !== $config->scopes ) {
			$params['scope'] = $config->scopeString();
		}
		if ( $config->usePkce && '' !== $codeChallenge ) {
			$params['code_challenge']        = $codeChallenge;
			$params['code_challenge_method'] = 'S256';
		}
		if ( null !== $config->resource ) {
			$params['resource'] = $config->resource;
		}
		if ( null !== $config->audience ) {
			$params['audience'] = $config->audience;
		}
		$params += $config->extraAuthorizeParams;
		return $config->authorizeUrl . ( str_contains( $config->authorizeUrl, '?' ) ? '&' : '?' ) . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Trade the code for tokens and store them.
	 *
	 * @param Connection $connection   Connection.
	 * @param string     $code         Code from the callback.
	 * @param string     $redirectUri  Redirect address used in authorizeUrl().
	 * @param string     $codeVerifier PKCE verifier.
	 * @return bool|ConnectorError True when the tokens were stored.
	 */
	public function completeSignIn( Connection $connection, string $code, string $redirectUri, string $codeVerifier ): bool|ConnectorError {
		try {
			$this->tokens->exchangeCode( $connection, $this->connector->oauthProvider(), $code, $redirectUri, $codeVerifier );
			return true;
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label );
		}
	}

	/**
	 * The organisations the signed-in person can choose between.
	 *
	 * @param Connection $connection Connection with tokens.
	 * @return array<int,array{id:string,name:string}>|ConnectorError
	 */
	public function organisations( Connection $connection ): array|ConnectorError {
		try {
			return $this->connector->organisations( $connection );
		} catch ( \Throwable $throwable ) {
			return ErrorBridge::toError( $throwable, $this->label );
		}
	}
}
