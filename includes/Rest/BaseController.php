<?php
/**
 * Base class of the REST controllers.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Rest;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\Repositories\Paged;
use Estasoft\SyncEngine\Domain\ErrorClass;
use Estasoft\SyncEngine\Rest\Support\ConnectionResolver;

/**
 * A controller registers the routes of one group. Each callback checks nothing itself: the permission callback runs first, the
 * wrapper then maps every exception to the plugin's error body, replays a repeated Idempotency-Key, and shapes the answer.
 * The callbacks validate nothing either; the argument schemas of the route do. A callback reads its parameters, calls one
 * service, and returns the array that `src/api/types.ts` describes.
 *
 * Services are resolved from the container when a route runs, so a request for one route builds only what it needs.
 */
abstract class BaseController {

	/**
	 * Route namespace.
	 */
	public const REST_NAMESPACE = 'estasoft-sync/v1';

	/**
	 * Route level: view and operate (estasoft_sync_manage).
	 */
	public const MANAGE = 'manage';

	/**
	 * Route level: change configuration (estasoft_sync_configure).
	 */
	public const CONFIGURE = 'configure';

	/**
	 * Route level of the signed inbound route, which checks the signature itself.
	 */
	public const SIGNED = 'signed';

	/**
	 * Route level of the sign-in callback, which is allowed by a valid one-time state instead of the login cookie.
	 */
	public const STATE = 'state';

	/**
	 * Routes registered by this controller, as `METHOD route => level`, for the documentation and the tests.
	 *
	 * @var array<string,string>
	 */
	private array $registered = array();

	/**
	 * Create the controller.
	 *
	 * @param Container          $container   Services, resolved when a route runs.
	 * @param ErrorMapper        $errors      Turns exceptions into answers.
	 * @param IdempotencyCache   $idempotency Replay cache.
	 * @param ConnectionResolver $resolver    Picks the connection for routes that do not name one.
	 */
	public function __construct(
		protected readonly Container $container,
		protected readonly ErrorMapper $errors,
		protected readonly IdempotencyCache $idempotency,
		protected readonly ConnectionResolver $resolver
	) {
	}

	/**
	 * Register the routes of this group with `register_rest_route`.
	 */
	abstract public function registerRoutes(): void;

	/**
	 * The routes this controller registered.
	 *
	 * @return array<string,string> `METHOD route` to level.
	 */
	public function registered(): array {
		return $this->registered;
	}

	/**
	 * Permission check for a level. Logged-out visitors get 401 and logged-in people without the capability get 403.
	 *
	 * @param string $level MANAGE or CONFIGURE.
	 * @return true|\WP_Error
	 */
	public function authorize( string $level ): bool|\WP_Error {
		$allowed = self::CONFIGURE === $level ? Capabilities::userCanConfigure() : Capabilities::userCanManage();
		if ( $allowed ) {
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
	 * Register one route.
	 *
	 * @param string              $route       Route pattern under the namespace, for example `/queue/(?P<id>\d+)`.
	 * @param string              $method      GET, POST, PUT or DELETE.
	 * @param string              $level       MANAGE, CONFIGURE or SIGNED.
	 * @param callable            $handler     Receives the request, returns an array or a WP_REST_Response.
	 * @param array<string,mixed> $args        Argument schemas.
	 * @param bool                $idempotent  Whether a repeated Idempotency-Key replays the first answer (state-changing routes only).
	 * @param \Closure|null       $permission  Own permission check, for the one route that cannot use the login cookie (the sign-in callback).
	 */
	protected function add( string $route, string $method, string $level, callable $handler, array $args = array(), bool $idempotent = true, ?\Closure $permission = null ): void {
		$this->registered[ $method . ' ' . $route ] = $level;

		if ( null === $permission ) {
			$permission = self::SIGNED === $level
				// The signed inbound route has no login; Jobs\Inbound\InboundHandler checks the HMAC signature and the time.
				? '__return_true'
				: fn(): bool|\WP_Error => $this->authorize( $level );
		}

		register_rest_route(
			self::REST_NAMESPACE,
			$route,
			array(
				'methods'             => $method,
				'callback'            => fn( \WP_REST_Request $request ) => $this->handle( $handler, $request, $idempotent && 'GET' !== $method ),
				'permission_callback' => $permission,
				'args'                => $args,
			)
		);
	}

	/**
	 * Run a route callback: replay, call, shape, and map every failure.
	 *
	 * @param callable         $handler    Route callback.
	 * @param \WP_REST_Request $request    Request.
	 * @param bool             $idempotent Whether to honour an Idempotency-Key.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle( callable $handler, \WP_REST_Request $request, bool $idempotent ): \WP_REST_Response|\WP_Error {
		try {
			$key = null;
			if ( $idempotent ) {
				$key = $this->idempotency->validKey( (string) $request->get_header( 'Idempotency-Key' ) );
				if ( null !== $key ) {
					$replay = $this->idempotency->replay( get_current_user_id(), $key, $request->get_method(), $request->get_route(), $request->get_body(), $this->queryOf( $request ), $this->routeParamsOf( $request ) );
					if ( null !== $replay ) {
						$replayed = new \WP_REST_Response( $replay['data'], $replay['status'] );
						$replayed->header( 'Idempotent-Replayed', 'true' );
						return $replayed;
					}
				}
			}
			$result   = $handler( $request );
			$response = $result instanceof \WP_REST_Response ? $result : new \WP_REST_Response( $result, 200 );
			if ( null !== $key ) {
				$this->idempotency->remember( get_current_user_id(), $key, $request->get_method(), $request->get_route(), $request->get_body(), $response->get_status(), $response->get_data(), $this->queryOf( $request ), $this->routeParamsOf( $request ) );
			}
			return $response;
		} catch ( \Throwable $error ) {
			return $this->error( $this->errors->map( $error ) );
		}
	}

	/**
	 * The query-string parameters of a request, for the idempotency fingerprint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function queryOf( \WP_REST_Request $request ): array {
		return (array) $request->get_query_params();
	}

	/**
	 * The parameters taken from the route (for example the id in `/queue/12/retry`), for the idempotency fingerprint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	private function routeParamsOf( \WP_REST_Request $request ): array {
		return (array) $request->get_url_params();
	}

	/**
	 * The error answer for an exception.
	 *
	 * @param ApiException $error Mapped failure.
	 */
	protected function error( ApiException $error ): \WP_Error {
		$body = $error->toBody();
		return new \WP_Error( $body['code'], $body['message'], $body['data'] );
	}

	/**
	 * A list answer with the pagination headers WordPress lists use.
	 *
	 * @param Paged|array<string,mixed> $page A Paged object or its array form.
	 */
	protected function paged( Paged|array $page ): \WP_REST_Response {
		$data     = $page instanceof Paged ? $page->toArray() : $page;
		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', (string) (int) ( $data['total'] ?? 0 ) );
		$response->header( 'X-WP-TotalPages', (string) (int) ( $data['total_pages'] ?? 0 ) );
		return $response;
	}

	/**
	 * A service from the container.
	 *
	 * @param string $key Container key.
	 *
	 * @throws \LogicException When the container holds something that is not an object.
	 */
	protected function service( string $key ): object {
		$service = $this->container->get( $key );
		return is_object( $service ) ? $service : throw new \LogicException( 'Service is not an object.' );
	}

	/**
	 * A service from the container, for the class the caller uses it as.
	 *
	 * The class is not checked: it only tells static analysis what the service offers, and a test may register a stand-in.
	 *
	 * @template T of object
	 * @param string $key  Container key.
	 * @param string $type Class or interface the caller uses the service as.
	 * @phpstan-param class-string<T> $type
	 * @return object The service.
	 * @phpstan-return T
	 *
	 * @throws \LogicException When the container holds something that is not an object.
	 */
	protected function serviceAs( string $key, string $type ): object {
		unset( $type );
		/**
		 * The service, as the class the caller asked for.
		 *
		 * @var T $service
		 */
		$service = $this->service( $key );
		return $service;
	}

	/**
	 * The connection to work on: the `connection_id` parameter, or the default.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	protected function connectionId( \WP_REST_Request $request ): int {
		$requested = $request->get_param( 'connection_id' );
		return $this->resolver->resolve( is_numeric( $requested ) ? (int) $requested : null );
	}

	/**
	 * Display name of the signed-in person, for "created by" fields.
	 */
	protected function actorName(): string {
		$user = wp_get_current_user();
		return '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login;
	}

	/**
	 * Id of the signed-in person.
	 */
	protected function actorId(): int {
		return (int) get_current_user_id();
	}

	/**
	 * Request body as an array (JSON body, form body or query, merged the way WordPress does).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string,mixed>
	 */
	protected function params( \WP_REST_Request $request ): array {
		$params = $request->get_params();
		return is_array( $params ) ? $params : array();
	}

	/**
	 * Schema for a record id in a route.
	 *
	 * @return array<string,mixed>
	 */
	protected static function idArg(): array {
		return array(
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		);
	}

	/**
	 * Schema for an optional connection id.
	 *
	 * @return array<string,mixed>
	 */
	protected static function connectionArg(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
		);
	}

	/**
	 * Schema for a page number.
	 *
	 * @return array<string,mixed>
	 */
	protected static function pageArg(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
			'default' => 1,
		);
	}

	/**
	 * Schema for plain text on one line.
	 *
	 * @param int  $max      Longest text in characters.
	 * @param bool $required Whether it is required.
	 * @return array<string,mixed>
	 */
	protected static function lineArg( int $max = 500, bool $required = false ): array {
		return array(
			'type'              => 'string',
			'maxLength'         => $max,
			'required'          => $required,
			'sanitize_callback' => static fn( $value ): string => Support\Sanitize::line( $value, $max ),
		);
	}

	/**
	 * Schema for a yes or no.
	 *
	 * @return array<string,mixed>
	 */
	protected static function boolArg(): array {
		return array( 'type' => 'boolean' );
	}

	/**
	 * Schema for a value from a fixed list.
	 *
	 * @param array<int,string> $values   Allowed values.
	 * @param bool              $required Whether it is required.
	 * @return array<string,mixed>
	 */
	protected static function enumArg( array $values, bool $required = false ): array {
		return array(
			'type'     => 'string',
			'enum'     => $values,
			'required' => $required,
		);
	}

	/**
	 * Schema for a list of record ids.
	 *
	 * @param bool $required Whether it is required.
	 * @return array<string,mixed>
	 */
	protected static function idListArg( bool $required = false ): array {
		return array(
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'maxItems'          => 500,
			'required'          => $required,
			'sanitize_callback' => array( Support\Sanitize::class, 'idList' ),
		);
	}

	/**
	 * Schema for a JSON object or list that the service validates in depth.
	 *
	 * @param string $type     object or array.
	 * @param bool   $required Whether it is required.
	 * @return array<string,mixed>
	 */
	protected static function structureArg( string $type = 'object', bool $required = false ): array {
		return array(
			'type'              => $type,
			'required'          => $required,
			'validate_callback' => array( Support\Sanitize::class, 'isStructure' ),
		);
	}
}
