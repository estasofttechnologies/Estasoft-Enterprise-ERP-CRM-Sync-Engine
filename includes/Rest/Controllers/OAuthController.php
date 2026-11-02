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

/**
 * `POST /connections/{id}/oauth/start`, `GET /connections/{id}/oauth/callback` and `GET /connections/{id}/oauth/organisations`.
 *
 * The first release ships no sign-in connector, so for the Odoo connection all three answer SYNC-CFG-004. The plumbing is complete:
 * a one-time `state` bound to the person and the connection (10 minutes), PKCE, and a callback that checks all of it.
 *
 * The callback is a browser redirect from the provider, which carries the login cookie but no REST nonce, so WordPress treats it as
 * logged out. Its permission callback therefore does not look at the cookie: it lets a request through when `state` has the shape of
 * a state this plugin makes. The decision is made inside the callback, which uses the state up (random, single use, ten minutes) and
 * checks that it belongs to this connection and to a person who still holds `estasoft_sync_configure`. Whatever goes wrong there, the
 * browser is sent back to the admin screen with `signin=failed`: it never gets JSON, and a visitor who is not signed in learns nothing
 * (the same redirect for an unknown, used, expired or foreign state, for a connection that does not exist, and for a failed exchange).
 */
final class OAuthController extends BaseController {

	/**
	 * Longest authorisation code accepted from the provider.
	 */
	public const MAX_CODE_LENGTH = 2048;

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add( '/connections/(?P<id>\d+)/oauth/start', 'POST', self::CONFIGURE, $this->start( ... ), array( 'id' => self::idArg() ), false );
		$this->add( '/connections/(?P<id>\d+)/oauth/organisations', 'GET', self::CONFIGURE, $this->organisations( ... ), array( 'id' => self::idArg() ) );

		// Only `state` is checked by the schema. The other values are read in callback(), where a wrong one ends in the failed redirect
		// instead of a JSON complaint that a browser would show as text.
		$this->add(
			'/connections/(?P<id>\d+)/oauth/callback',
			'GET',
			self::STATE,
			$this->callback( ... ),
			array(
				'id'    => array( 'type' => 'integer' ),
				'state' => array(
					'type'     => 'string',
					'required' => true,
				),
				'code'  => array(),
				'error' => array(),
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
	 * The state is used up first, whatever follows, so a link cannot be tried twice. A state that is unknown, used, expired, from another
	 * connection or of a person who may no longer configure ends in `failed`, and so does any error on the way.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			return $this->finishSignIn( $request );
		} catch ( \Throwable $error ) {
			$this->errors->map( $error ); // Reports an unexpected failure to the log; the person only sees the result flag.
			return $this->back( '/connections', 'failed' );
		}
	}

	/**
	 * Permission of the callback: the `state` must look like one this plugin makes. Everything else is decided in callback(), because
	 * a refusal here would be JSON in a browser window.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function authorizeCallback( \WP_REST_Request $request ): bool|\WP_Error {
		if ( OAuthState::wellFormed( $request->get_param( 'state' ) ) ) {
			return true;
		}
		return new \WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to do this.', 'estasoft-erp-crm-sync-engine' ),
			array(
				'status' => rest_authorization_required_code(),
				'class'  => ErrorClass::NeedsPerson->value,
			)
		);
	}

	/**
	 * The work of the callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function finishSignIn( \WP_REST_Request $request ): \WP_REST_Response {
		$id    = (int) $request['id'];
		$saved = $this->state()->consume( (string) $request->get_param( 'state' ), $id );
		if ( null === $saved || ! user_can( $saved['user'], Capabilities::CONFIGURE ) ) {
			return $this->back( '/connections', 'failed' );
		}
		$route = $saved['route'];
		$code  = $request->get_param( 'code' );
		$error = $request->get_param( 'error' );
		if ( ( null !== $error && '' !== $error ) || null === $code || '' === $code ) {
			return $this->back( $route, 'cancelled' ); // The person said no at the provider, or came back without a code.
		}
		if ( ! is_string( $code ) || strlen( $code ) > self::MAX_CODE_LENGTH ) {
			return $this->back( $route, 'failed' );
		}
		list( $connection, $connector ) = $this->signInConnector( $id );
		$done                           = $connector->completeSignIn( $connection, $code, $this->redirectUri( $id ), $saved['verifier'] );
		return $this->back( $route, true === $done ? 'ok' : 'failed' );
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
