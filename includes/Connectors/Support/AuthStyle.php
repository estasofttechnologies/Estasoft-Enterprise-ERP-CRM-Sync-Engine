<?php
/**
 * How a connector signs in to its system.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The sign-in style of a connector. It decides which connect form is drawn and how the catalog describes the connection.
 */
enum AuthStyle: string {
	case Key                    = 'key';
	case Basic                  = 'basic';
	case OAuthAuthorizationCode = 'oauth_authorization_code';
	case OAuthClientCredentials = 'oauth_client_credentials';
	case OAuthJwtBearer         = 'oauth_jwt_bearer';
	case ServerLogin            = 'server_login';
	case ExportFile             = 'export_file';
	case None                   = 'none';

	/**
	 * The connection method as the catalog and the connector documents name it: key, own_app, cloud_app, server_login, export_file or none.
	 */
	public function connectionMethod(): string {
		return match ( $this ) {
			self::Key, self::Basic                                  => 'key',
			self::OAuthAuthorizationCode                            => 'own_app',
			self::OAuthClientCredentials, self::OAuthJwtBearer      => 'cloud_app',
			self::ServerLogin                                       => 'server_login',
			self::ExportFile                                        => 'export_file',
			self::None                                              => 'none',
		};
	}

	/**
	 * Whether tokens come from an OAuth 2 token endpoint.
	 */
	public function isOAuth(): bool {
		return in_array( $this, array( self::OAuthAuthorizationCode, self::OAuthClientCredentials, self::OAuthJwtBearer ), true );
	}

	/**
	 * Whether a person signs in on the system's page (authorization code grant).
	 */
	public function needsSignIn(): bool {
		return self::OAuthAuthorizationCode === $this;
	}
}
