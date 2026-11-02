<?php
/**
 * Routes: sign-in for connectors that use a sign-in page.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest\Controllers;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects and fixed texts, not output.

use Estasoft\SyncEngine\Connectors\Support\GuardedSignInConnector;
use Estasoft\SyncEngine\Connectors\Support\OAuth\OAuthRedirect;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Jobs\Connections\ConnectionService;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\OAuth\OAuthState;
use Estasoft\SyncEngine\Rest\OAuth\SignInConnector;
use Estasoft\SyncEngine\Rest\RestModule;

/**
 * `POST /connections/{id}/oauth/start`, `GET /oauth/callback` (the one stable redirect address of the site), `GET /connections/{id}/oauth/callback`
 * (the older address with the connection id, kept for apps registered with it), `GET /connections/{id}/oauth/organisations` and
 * `POST /connections/{id}/oauth/organisation`.
 *
 * A key-style connector (Odoo) answers SYNC-CFG-004 on the routes that start with a connection. The plumbing is complete: a one-time `state`
 * bound to the person and the connection (10 minutes), PKCE, and a callback that checks all of it. The stable callback finds the connection through the state.
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
	 * Longest extra value of the vendor's redirect that is kept, in characters.
	 */
	public const MAX_PARAM_LENGTH = 128;

	/**
	 * Most extra values of the vendor's redirect that are kept.
	 */
	public const MAX_PARAMS = 10;

	/**
	 * Register the routes.
	 */
	public function registerRoutes(): void {
		$this->add( '/connections/(?P<id>\d+)/oauth/start', 'POST', self::CONFIGURE, $this->start( ... ), array( 'id' => self::idArg() ), false );
		$this->add( '/connections/(?P<id>\d+)/oauth/organisations', 'GET', self::CONFIGURE, $this->organisations( ... ), array( 'id' => self::idArg() ) );
		$this->add(
			'/connections/(?P<connection>\d+)/oauth/organisation',
			'POST',
			self::CONFIGURE,
			$this->selectOrganisation( ... ),
			array(
				'connection' => self::idArg(),
				'id'         => array(
					'type'              => 'string',
					'required'          => true,
					'minLength'         => 1,
					'maxLength'         => 191,
					'sanitize_callback' => static fn( $value ): string => is_scalar( $value ) ? trim( (string) $value ) : '',
				),
			),
			false
		);

		// The stable redirect address of the site: the state says which connection the answer belongs to. Same arguments and same permission as below.
		$callbackArgs = array(
			'state' => array(
				'type'     => 'string',
				'required' => true,
			),
			'code'  => array(),
			'error' => array(),
		);
		$this->add( OAuthRedirect::ROUTE, 'GET', self::STATE, $this->callbackByState( ... ), $callbackArgs, false, fn( \WP_REST_Request $request ): bool|\WP_Error => $this->authorizeCallback( $request ) );

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
		return array( 'url' => $connector->authorizeUrl( $connection, $begin['state'], OAuthRedirect::uri(), $begin['challenge'] ) );
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
	 * `POST /connections/{id}/oauth/organisation`: the person chose an organisation. The connection is tested and, when the test passes, becomes active.
	 *
	 * @param \WP_REST_Request $request Request with `id` (the organisation) in the body.
	 * @return array<string,mixed> The `Connection`.
	 *
	 * @throws ApiException SYNC-CFG-004 for a connector that does not use sign-in.
	 */
	public function selectOrganisation( \WP_REST_Request $request ): array {
		$connection = (int) $request['connection'];
		$this->signInConnector( $connection ); // 404 or SYNC-CFG-004 first, as on the other routes.
		$connections = $this->connectionService();
		return $connections->selectOrganisation( $connection, (string) $request->get_param( 'id' ) );
	}

	/**
	 * `GET /oauth/callback`: the stable redirect address. Like the callback below, but the connection comes from the state.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function callbackByState( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			return $this->finishSignIn( $request, null );
		} catch ( \Throwable $error ) {
			$this->errors->map( $error );
			return $this->back( '/connections', 'failed' );
		}
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
			return $this->finishSignIn( $request, (int) $request['id'] );
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
	 * @param int|null         $id      Connection of the route, or null on the stable address (then the state says which connection).
	 */
	private function finishSignIn( \WP_REST_Request $request, ?int $id ): \WP_REST_Response {
		$state = (string) $request->get_param( 'state' );
		$saved = null === $id ? $this->state()->consumeAny( $state ) : $this->state()->consume( $state, $id );
		if ( null === $saved || ! user_can( $saved['user'], Capabilities::CONFIGURE ) || ! $this->sameBrowserUser( $saved['user'] ) ) {
			return $this->back( '/connections', 'failed' );
		}
		$id    = $saved['connection'];
		$route = $saved['route'];
		$code  = $request->get_param( 'code' );
		$error = $request->get_param( 'error' );
		if ( ( null !== $error && '' !== $error ) || null === $code || '' === $code ) {
			return $this->back( $route, 'cancelled', $id ); // The person said no at the provider, or came back without a code.
		}
		if ( ! is_string( $code ) || strlen( $code ) > self::MAX_CODE_LENGTH ) {
			return $this->back( $route, 'failed', $id );
		}
		$this->keepCallbackParams( $request, $id ); // Before the connection is read, so the connector sees them in its config.
		list( $connection, $connector ) = $this->signInConnector( $id );
		$done                           = $connector->completeSignIn( $connection, $code, OAuthRedirect::uri(), $saved['verifier'] );
		if ( true === $done ) {
			$this->connectionService()->afterSignIn( $id );
		}
		return $this->back( $route, true === $done ? 'ok' : 'failed', $id );
	}

	/**
	 * Whether the browser that comes back is not signed in as somebody else. A callback made with the state of another person's sign-in (a
	 * link an attacker prepared) must not connect the attacker's account to this site: when the browser is signed in at all, it has to be
	 * the person who started the sign-in. A browser that is not signed in is left to the state, which only that person could have.
	 *
	 * @param int $startedBy User who started the sign-in.
	 */
	private function sameBrowserUser( int $startedBy ): bool {
		$current = get_current_user_id();
		if ( $current <= 0 ) {
			// A REST request without the nonce runs as nobody; the cookie still says who is signed in.
			$cookie  = wp_validate_auth_cookie( '', 'logged_in' );
			$current = false === $cookie ? 0 : (int) $cookie;
		}
		return $current <= 0 || $current === $startedBy;
	}

	/**
	 * Keep the extra query parameters of the vendor's redirect (QuickBooks sends the company as `realmId`, MYOB the company file as `businessId`) on the
	 * connection's config under `oauth_callback`, so the connector can read them in `organisations()`. Only short plain values are kept.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param int              $id      Connection.
	 */
	private function keepCallbackParams( \WP_REST_Request $request, int $id ): void {
		$keep = array();
		foreach ( (array) $request->get_query_params() as $name => $value ) {
			$name = (string) $name;
			if ( in_array( $name, array( 'code', 'state' ), true ) || str_starts_with( $name, 'error' ) || 1 !== preg_match( '/^[A-Za-z0-9_]{1,40}$/', $name ) ) {
				continue;
			}
			if ( is_scalar( $value ) && strlen( (string) $value ) <= self::MAX_PARAM_LENGTH && count( $keep ) < self::MAX_PARAMS ) {
				$keep[ $name ] = (string) $value;
			}
		}
		if ( array() === $keep ) {
			return;
		}
		$this->connectionRepository()->update( $id, array( 'config' => array( 'oauth_callback' => $keep ) ) );
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
		$connection = $this->connectionRepository()->find( $id );
		if ( null === $connection ) {
			throw ApiException::notFound( __( 'That connection does not exist.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$connector = $this->connectorRegistry()->get( $connection->connector );
		if ( ! $connector instanceof SignInConnector || ( $connector instanceof GuardedSignInConnector && ! $connector->requiresSignIn( $connection ) ) ) {
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
	 * The connections service.
	 *
	 * @throws \LogicException When the container holds something else.
	 */
	private function connectionService(): ConnectionService {
		$service = $this->service( JobServices::CONNECTIONS );
		return $service instanceof ConnectionService ? $service : throw new \LogicException( 'The connections service is missing.' );
	}

	/**
	 * The connection repository.
	 *
	 * @throws \LogicException When the container holds something else.
	 */
	private function connectionRepository(): ConnectionRepository {
		$repository = $this->service( Services::CONNECTION_REPOSITORY );
		return $repository instanceof ConnectionRepository ? $repository : throw new \LogicException( 'The connection repository is missing.' );
	}

	/**
	 * The connector registry.
	 *
	 * @throws \LogicException When the container holds something else.
	 */
	private function connectorRegistry(): ConnectorRegistry {
		$registry = $this->service( Services::CONNECTOR_REGISTRY );
		return $registry instanceof ConnectorRegistry ? $registry : throw new \LogicException( 'The connector registry is missing.' );
	}

	/**
	 * A redirect to the admin screen with the result.
	 *
	 * @param string   $route  App route, for example /connections/3.
	 * @param string   $result ok, cancelled or failed.
	 * @param int|null $id     The connection, when it is known; added as `connection=<id>` so the screen knows which one to open.
	 */
	private function back( string $route, string $result, ?int $id = null ): \WP_REST_Response {
		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', admin_url( 'admin.php?page=estasoft-sync' ) . '#' . $route . '?signin=' . $result . ( null !== $id && $id > 0 ? '&connection=' . $id : '' ) );
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
