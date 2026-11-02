<?php
/**
 * OAuth settings for the Microsoft client credentials grant.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects, not output.

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthConfig;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthProvider;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * Business Central, Dataverse and Finance and Operations are all reached with a token from the Entra v2.0 endpoint of the merchant's
 * tenant, scope `{resource}/.default`, the client id in the config and the client secret in the connection secrets. Only the resource
 * differs, so each connector hands in a function that returns it. No refresh token exists for this grant; the token manager asks again.
 */
final class EntraTokenProvider implements OAuthProvider {

	/**
	 * Returns the resource address of a connection, for example `https://api.businesscentral.dynamics.com` (no trailing slash).
	 *
	 * @var \Closure
	 */
	private \Closure $resource;

	/**
	 * Takes the objects and settings this class works with.
	 *
	 * @param \Closure $locate   Receives a Connection, returns the resource address.
	 * @param string   $system   System name for messages.
	 */
	public function __construct( \Closure $locate, private readonly string $system = '' ) {
		$this->resource = $locate;
	}

	/**
	 * The settings of the token request.
	 *
	 * @param Connection $connection Connection.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the tenant is not a GUID or a domain name.
	 */
	public function config( Connection $connection ): OAuthConfig {
		$tenant = EntraTenant::normalise( (string) $connection->config( 'tenant_id', '' ) );
		if ( null === $tenant ) {
			throw new ApiException(
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => $this->system,
						'field'  => 'tenant_id',
						'reason' => __( 'The tenant ID must be a GUID or a domain name.', 'estasoft-erp-crm-sync-engine' ),
					),
					null,
					null,
					null,
					'tenant refused'
				)
			);
		}
		$resource = rtrim( ( $this->resource )( $connection ), '/' );
		return new OAuthConfig(
			authorizeUrl: '',
			tokenUrl: EntraTenant::tokenUrl( $tenant ),
			scopes: array( $resource . '/.default' ),
			grant: 'client_credentials',
			usePkce: false,
			clientAuth: 'body',
			allowedHosts: array( EntraTenant::LOGIN_HOST )
		);
	}
}
