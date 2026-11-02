<?php
/**
 * Sign-in settings for Sage 200: Sage ID with the merchant's own client.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage200;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Authorization code through `id.sage.com` with the audience `s200ukipd/sage200`, the client secret in the request body and no PKCE (the
 * authentication guide does not mention it). The access token lives eight hours and the refresh token up to 90 days, as set on the
 * credential request form (docs/connectors/sage200.md).
 */
final class Sage200OAuth implements OAuthProvider {

	/**
	 * Sage ID's sign-in page.
	 */
	public const AUTHORIZE_URL = 'https://id.sage.com/authorize';

	/**
	 * Sage ID's token endpoint.
	 */
	public const TOKEN_URL = 'https://id.sage.com/oauth/token';

	/**
	 * OAuth settings for a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		unset( $connection );
		return new OAuthConfig(
			authorizeUrl: self::AUTHORIZE_URL,
			tokenUrl: self::TOKEN_URL,
			scopes: array( 'openid', 'profile', 'email', 'offline_access' ),
			grant: 'authorization_code',
			usePkce: false,
			clientAuth: 'body',
			extraAuthorizeParams: array( 'audience' => 's200ukipd/sage200' ),
			refreshTokenLifetimeDays: 90,
			accessTokenFallbackSeconds: 28800,
			allowedHosts: array( 'id.sage.com' )
		);
	}
}
