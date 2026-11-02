<?php
/**
 * Routes: sign-in for connectors that use a sign-in page.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\OAuth\OAuthState;
use Estasoft\SyncEngine\Rest\OAuth\SignInConnector;
use Estasoft\SyncEngine\Rest\RestModule;
use Estasoft\SyncEngine\Rest\Support\Sanitize;

/**
 * `POST /connections/{id}/oauth/start`, `GET /connections/{id}/oauth/callback` and `GET /connections/{id}/oauth/organisations`.
 *
 * The first release ships no sign-in connector, so for the Odoo connection all three answer SYNC-CFG-004. The plumbing is complete:
 * a one-time `state` bound to the person and the connection (10 minutes), PKCE, and a callback that checks all of it.
 *
 * The callback is a browser redirect from the provider, which carries the login cookie but no REST nonce, so WordPress treats it as
 * logged out. Its permission callback therefore does not look at the cookie: it accepts a valid, unused `state` that belongs to this
 * connection and to a person who still holds `estasoft_sync_configure`. The state is random, single use and short lived.
 */
final class OAuthController extends BaseController {

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add( '/connections/(?P<id>\d+)/oauth/start', 'POST', self::CONFIGURE, $this->start( ... ), array( 'id' => self::idArg() ), false );
		$this->add( '/connections/(?P<id>\d+)/oauth/organisations', 'GET', self::CONFIGURE, $this->organisations( ... ), array( 'id' => self::idArg() ) );

		$this->add(
			'/connections/(?P<id>\d+)/oauth/callback',
			'GET',
			self::STATE,
			$this->callback( ... ),
			array(
				'id'    => self::idArg(),
				'state' => array(
					'type'     => 'string',
					'required' => true,
				),
				'code'  => array(
					'type'              => 'string',
					'maxLength'         => 2048,
					'sanitize_callback' => array( Sanitize::class, 'document' ),
				),
				'error' => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			),
			false,
			fn( \WP_REST_Request $request ): bool|\WP_Error => $this->authorizeCallback( $request )
		);
	}

	/**
	 * `POST /connections/{id}/oauth/start`: the address of the provider's sign-in page.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{url:string}
	 *
	 * @throws ApiException SYNC-CFG-004 for a connector that does not use sign-in; 404 when the connection does not exist.
	 */
	public function start( \WP_REST_Request $request ): array {
		list( $connection, $connector ) = $this->signInConnector( (int) $request['id'] );
		$begin                          = $this->state()->begin( $this->actorId(), $connection->id, '/connections/' . $connection->id );
		return array( 'url' => $connector->authorizeUrl( $connection, $begin['state'], $this->redirectUri( $connection->id ), $begin['challenge'] ) );
	}

	/**
	 * `GET /connections/{id}/oauth/organisations`: the organisations to choose between after signing in.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException SYNC-CFG-004 for a connector that does not use sign-in; 502 when the system fails.
	 */
	public function organisations( \WP_REST_Request $request ): array {
		list( $connection, $connector ) = $this->signInConnector( (int) $request['id'] );
		$list                           = $connector->organisations( $connection );
		if ( $list instanceof ConnectorError ) {
			throw ApiException::fromCatalog( $list->code, $list->context, 502 );
		}
		return $list;
	}

	/**
	 * `GET /connections/{id}/oauth/callback`: finish the sign-in and send the browser back to the admin screen with a result flag
	 * (`signin=ok`, `signin=cancelled` or `signin=failed`) in the hash route.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function callback( \WP_REST_Request $request ): \WP_REST_Response {
		$id    = (int) $request['id'];
		$saved = $this->state()->consume( (string) $request->get_param( 'state' ), $id );
		$route = null === $saved ? '/connections/' . $id : $saved['route'];
		if ( null === $saved ) {
			return $this->back( $route, 'failed' );
		}
		if ( '' !== (string) $request->get_param( 'error' ) || '' === (string) $request->get_param( 'code' ) ) {
			return $this->back( $route, 'cancelled' );
		}
		try {
			list( $connection, $connector ) = $this->signInConnector( $id );
			$done                           = $connector->completeSignIn( $connection, (string) $request->get_param( 'code' ), $this->redirectUri( $id ), $saved['verifier'] );
		} catch ( \Throwable $error ) {
			$this->errors->map( $error ); // Reports an unexpected failure to the log; the person only sees the result flag.
			return $this->back( $route, 'failed' );
		}
		return $this->back( $route, true === $done ? 'ok' : 'failed' );
	}

	/**
	 * Permission of the callback: a valid state, for this connection, of a person who may still configure.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function authorizeCallback( \WP_REST_Request $request ): bool|\WP_Error {
		$saved = $this->state()->peek( (string) $request->get_param( 'state' ), (int) $request['id'] );
		if ( null !== $saved && user_can( $saved['user'], Capabilities::CONFIGURE ) ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'This sign-in link has expired or was already used. Start the sign-in again from Connections.', 'estasoft-erp-crm-sync-engine' ),
			array(
				'status' => 403,
				'class'  => ErrorClass::NeedsPerson->value,
			)
		);
	}

	/**
	 * The connection and its sign-in connector.
	 *
	 * @param int $id Connection id.
	 * @return array{0:Connection,1:SignInConnector}
	 *
	 * @throws ApiException 404 when the connection does not exist; SYNC-CFG-004 when its connector does not use sign-in.
	 */
	private function signInConnector( int $id ): array {
		/** @var ConnectionRepository $connections */
		$connections = $this->service( Services::CONNECTION_REPOSITORY );
		/** @var ConnectorRegistry $registry */
		$registry   = $this->service( Services::CONNECTOR_REGISTRY );
		$connection = $connections->find( $id );
		if ( null === $connection ) {
			throw ApiException::notFound( __( 'That connection does not exist.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$connector = $registry->get( $connection->connector );
		if ( ! $connector instanceof SignInConnector ) {
			throw new ApiException(
				ApiException::NO_SIGN_IN,
				__( 'This connector does not use sign-in. It connects with a key. Open the connection and enter the key instead.', 'estasoft-erp-crm-sync-engine' ),
				400,
				ErrorClass::NeedsPerson->value
			);
		}
		return array( $connection, $connector );
	}

	/**
	 * The callback address of this site for a connection.
	 *
	 * @param int $id Connection id.
	 */
	private function redirectUri( int $id ): string {
		return rest_url( self::REST_NAMESPACE . '/connections/' . $id . '/oauth/callback' );
	}

	/**
	 * A redirect to the admin screen with the result.
	 *
	 * @param string $route  App route, for example /connections/3.
	 * @param string $result ok, cancelled or failed.
	 */
	private function back( string $route, string $result ): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', admin_url( 'admin.php?page=estasoft-sync' ) . '#' . $route . '?signin=' . $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The state store.
	 *
	 * @return OAuthState
	 */
	private function state(): object {
		return $this->service( RestModule::OAUTH_STATE );
	}
}
