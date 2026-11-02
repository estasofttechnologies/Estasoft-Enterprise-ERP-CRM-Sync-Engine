<?php
/**
 * The OAuth 2.0 settings of an S/4HANA connection (client credentials from a communication arrangement).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapS4Hana;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * The token address, client id, secret and scope are shown on the communication arrangement and typed by the merchant. The client
 * authenticates with a Basic header; no refresh token exists, a new token is requested when the old one ends.
 */
final class SapS4HanaOAuth implements OAuthProvider {

	/**
	 * The settings of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		$scopes = preg_split( '/[\s,]+/', trim( (string) $connection->config( 'scope', '' ) ) );
		return new OAuthConfig(
			'',
			trim( (string) $connection->config( 'token_url', '' ) ),
			array_values( array_filter( is_array( $scopes ) ? $scopes : array(), static fn( string $scope ): bool => '' !== $scope ) ),
			'client_credentials',
			false,
			'basic',
			merchantHost: true
		);
	}
}
