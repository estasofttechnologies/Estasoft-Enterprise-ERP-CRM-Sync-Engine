<?php
/**
 * Oracle Identity Cloud Service / IAM identity domain token request.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\OracleFusion;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * `POST {identity domain}/oauth2/v1/token` with the client id and secret as a Basic header and the scope `urn:opc:resource:fusion:<POD>:erp/`.
 * No refresh token exists; a new token is requested when the old one ends (about 3600 seconds).
 */
final class OracleFusionOAuth implements OAuthProvider {

	/**
	 * The settings of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		$base  = OracleFusionOptions::identityBase( $connection );
		$scope = trim( (string) $connection->config( 'scope', '' ) );
		return new OAuthConfig(
			'',
			'' === $base ? '' : $base . '/oauth2/v1/token',
			'' === $scope ? array() : array( $scope ),
			'client_credentials',
			false,
			'basic',
			merchantHost: true
		);
	}
}
