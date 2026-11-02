<?php
/**
 * Sign-in settings for Zoho: the merchant's own client, the chosen data centre.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Zoho;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Authorization code grant with offline access, client id and secret in the body, comma separated scopes, no PKCE.
 */
final class ZohoOAuthProvider implements OAuthProvider {

	/**
	 * Create the object.
	 *
	 * @param \Closure $scopes Receives the Connection and returns the scope names to ask for.
	 */
	public function __construct( private readonly \Closure $scopes ) {
	}

	/**
	 * OAuth settings for a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		$centre = ZohoDataCentre::forConnection( $connection );
		return new OAuthConfig(
			authorizeUrl: 'https://' . $centre->accountsHost() . '/oauth/v2/auth',
			tokenUrl: 'https://' . $centre->accountsHost() . '/oauth/v2/token',
			scopes: array_values( ( $this->scopes )( $connection ) ),
			grant: 'authorization_code',
			usePkce: false,
			clientAuth: 'body',
			extraAuthorizeParams: array(
				'access_type' => 'offline',
				'prompt'      => 'consent',
			),
			scopeSeparator: ',',
			allowedHosts: ZohoDataCentre::accountHosts()
		);
	}
}
