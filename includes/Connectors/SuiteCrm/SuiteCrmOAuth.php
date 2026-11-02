<?php
/**
 * The OAuth settings of a SuiteCRM connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SuiteCrm;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * The client credentials grant (the client acts as the user it was made for) or the password grant (a named sync user), against the
 * token endpoint of the merchant's own server. SuiteCRM implements no scopes, so none are sent. The access token lives one hour.
 */
final class SuiteCrmOAuth implements OAuthProvider {

	/**
	 * The settings for a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		$grant = 'password' === (string) $connection->config( 'grant', 'client_credentials' ) ? 'password' : 'client_credentials';
		return new OAuthConfig(
			authorizeUrl: '',
			tokenUrl: SuiteCrmAddress::base( $connection ) . '/access_token',
			scopes: array(),
			grant: $grant,
			usePkce: false,
			clientAuth: 'body',
			accessTokenFallbackSeconds: 3600,
			usernameKey: 'username',
			passwordKey: 'password',
			merchantHost: true
		);
	}
}
