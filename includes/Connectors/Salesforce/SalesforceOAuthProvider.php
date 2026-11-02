<?php
/**
 * Sign-in settings for Salesforce: the merchant's own external client app on their own My Domain.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Salesforce;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Web server flow with PKCE (scopes api and refresh_token), or the JWT bearer flow when the connection says so.
 */
final class SalesforceOAuthProvider implements OAuthProvider {

	/**
	 * The My Domain host the merchant typed, or an empty string when it is not a Salesforce host.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function host( Connection $connection ): string {
		$typed = strtolower( trim( (string) $connection->config( 'domain', '' ) ) );
		$typed = (string) preg_replace( '~^https?://~', '', $typed );
		$typed = (string) strtok( $typed, '/' );
		return 1 === preg_match( '/^[a-z0-9][a-z0-9.-]{0,120}\.salesforce\.com$/', $typed ) ? $typed : '';
	}

	/**
	 * Whether the connection uses the JWT bearer flow.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function usesJwt( Connection $connection ): bool {
		return 'jwt_bearer' === strtolower( trim( (string) $connection->config( 'auth_method', 'web_server' ) ) );
	}

	/**
	 * OAuth settings for a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		$host = self::host( $connection );
		$host = '' !== $host ? $host : 'login.salesforce.com';
		$jwt  = self::usesJwt( $connection );
		return new OAuthConfig(
			authorizeUrl: 'https://' . $host . '/services/oauth2/authorize',
			tokenUrl: 'https://' . $host . '/services/oauth2/token',
			scopes: array( 'api', 'refresh_token' ),
			grant: $jwt ? 'jwt_bearer' : 'authorization_code',
			usePkce: true,
			clientAuth: 'body',
			jwtSubject: $jwt ? (string) $connection->config( 'jwt_username', '' ) : null,
			jwtAudience: 'https://' . $host,
			allowedHosts: array( $host )
		);
	}
}
