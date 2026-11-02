<?php
/**
 * Sign-in settings for Acumatica: the merchant's own connected application on their own site.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Acumatica;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Authorization code with PKCE (S256), the client secret in the request body, scopes `api` and `offline_access`. The authorize and token
 * addresses are under the site's own `/identity/connect/` path. The refresh token chain lasts 30 days unless the administrator turned on
 * sliding expiration, so the credential expiry is set to 30 days after each refresh to warn early (docs/connectors/acumatica.md).
 */
final class AcumaticaOAuth implements OAuthProvider {

	/**
	 * OAuth settings for a connection.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException When the site address is not a web address.
	 */
	public function config( Connection $connection ): OAuthConfig {
		$base = AcumaticaSite::address( $connection, AcumaticaConnector::LABEL );
		$host = (string) wp_parse_url( $base, PHP_URL_HOST );
		return new OAuthConfig(
			authorizeUrl: $base . '/identity/connect/authorize',
			tokenUrl: $base . '/identity/connect/token',
			scopes: array( 'api', 'offline_access' ),
			grant: 'authorization_code',
			usePkce: true,
			clientAuth: 'body',
			refreshTokenLifetimeDays: 30,
			accessTokenFallbackSeconds: 3600,
			allowedHosts: array( $host )
		);
	}
}
