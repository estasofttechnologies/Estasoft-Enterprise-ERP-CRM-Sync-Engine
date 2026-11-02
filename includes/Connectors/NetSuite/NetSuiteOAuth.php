<?php
/**
 * The OAuth 2.0 settings of a NetSuite connection (machine-to-machine client credentials with a signed assertion).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Token endpoint `https://<account>.suitetalk.api.netsuite.com/services/rest/auth/oauth2/v1/token`; form fields grant_type=client_credentials,
 * client_assertion_type and client_assertion; the assertion has the header `typ`, `alg` (ES256 or PS256) and `kid` (the certificate id) and the
 * claims iss (client id), scope, aud (the token endpoint), iat and exp. No client secret exists. The access token lasts 3600 seconds.
 */
final class NetSuiteOAuth implements OAuthProvider {

	/**
	 * Algorithms offered on the connect form (NetSuite lists PS and ES algorithms only).
	 *
	 * @var array<int,string>
	 */
	public const ALGORITHMS = array( 'ES256', 'PS256' );

	/**
	 * The settings of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public function config( Connection $connection ): OAuthConfig {
		$host      = NetSuiteAccount::host( $connection );
		$algorithm = strtoupper( (string) $connection->config( 'algorithm', 'ES256' ) );
		return new OAuthConfig(
			'',
			'' === $host ? '' : 'https://' . $host . '/services/rest/auth/oauth2/v1/token',
			array( 'rest_webservices' ),
			'jwt_bearer',
			false,
			'body',
			array(),
			array(),
			null,
			null,
			null,
			3600,
			',',
			'client_id',
			'client_secret',
			'private_key',
			null,
			null,
			null,
			array(),
			array( 'kid' => trim( (string) $connection->config( 'certificate_id', '' ) ) ),
			3000,
			in_array( $algorithm, self::ALGORITHMS, true ) ? $algorithm : 'ES256',
			'client_assertion',
			'username',
			'password',
			array( '*.suitetalk.api.netsuite.com' )
		);
	}
}
