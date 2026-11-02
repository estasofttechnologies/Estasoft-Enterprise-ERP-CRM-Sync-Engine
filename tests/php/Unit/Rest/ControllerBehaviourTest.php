<?php
/**
 * What the base controller does around every route: permission, error body, replay, pagination headers.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Rest;

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Core\Repositories\Paged;
use Estasoft\SyncEngine\Rest\ApiException;
use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\ErrorMapper;
use Estasoft\SyncEngine\Rest\IdempotencyCache;
use Estasoft\SyncEngine\Rest\RestModule;
use Estasoft\SyncEngine\Rest\Support\ConnectionResolver;
use Estasoft\SyncEngine\Tests\Support\RestArrayStore;
use Estasoft\SyncEngine\Tests\Support\RestRegistry;
use Estasoft\SyncEngine\Tests\Support\RestStubCase;

/**
 * What the base controller does around every route: permission, error body, replay, pagination headers.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ControllerBehaviourTest extends RestStubCase {

	private RestArrayStore $store;

	/**
	 * What the error mapper reported.
	 *
	 * @var array<int,string>
	 */
	private array $reported = array();

	protected function setUp(): void {
		parent::setUp();
		$this->store    = new RestArrayStore();
		$this->reported = array();
	}

	/**
	 * A controller with one route of each kind, so the wrapper can be tried.
	 *
	 * @param callable $handler What the route does.
	 */
	private function controller( callable $handler ): BaseController {
		$container = new Container();
		return new class( $container, new ErrorMapper( function ( string $d ): void {
			$this->reported[] = $d;
		} ), new IdempotencyCache( $this->store, 'salt' ), new ConnectionResolver( static fn(): array => array() ), $handler ) extends BaseController {
			public function __construct( Container $c, ErrorMapper $e, IdempotencyCache $i, ConnectionResolver $r, private $handler ) {
				parent::__construct( $c, $e, $i, $r );
			}

			public function registerRoutes(): void {
				$this->add( '/thing', 'POST', self::MANAGE, $this->handler );
				$this->add( '/thing', 'GET', self::CONFIGURE, $this->handler );
				$this->add( '/signed', 'POST', self::SIGNED, $this->handler );
				$this->add( '/other', 'POST', self::MANAGE, $this->handler, array(), false );
			}

			public function pagedPublic( Paged|array $page ): \WP_REST_Response {
				return $this->paged( $page );
			}
		};
	}

	private function request( string $method = 'POST', string $key = '', string $body = '{"a":1}' ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, '/estasoft-sync/v1/thing' );
		$request->set_body( $body );
		if ( '' !== $key ) {
			$request->set_header( 'Idempotency-Key', $key );
		}
		return $request;
	}

	public function test_a_person_with_the_capability_is_allowed_and_one_without_is_refused_with_401_or_403(): void {
		$controller = $this->controller( static fn(): array => array() );
		$this->can( 'estasoft_sync_manage' );
		$this->assertTrue( $controller->authorize( BaseController::MANAGE ) );
		$refused = $controller->authorize( BaseController::CONFIGURE );
		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 'rest_forbidden', $refused->get_error_code() );
		$this->assertSame( 403, $refused->get_error_data()['status'] );

		RestRegistry::$loggedIn = false;
		$this->can();
		$this->assertSame( 401, $controller->authorize( BaseController::MANAGE )->get_error_data()['status'] );
	}

	public function test_the_configure_capability_includes_viewing(): void {
		$this->can( 'estasoft_sync_configure' );
		$controller = $this->controller( static fn(): array => array() );
		$this->assertTrue( $controller->authorize( BaseController::MANAGE ) );
		$this->assertTrue( $controller->authorize( BaseController::CONFIGURE ) );
	}

	public function test_every_route_gets_a_permission_callback_and_only_the_signed_one_allows_everyone(): void {
		$this->controller( static fn(): array => array() )->registerRoutes();
		$routes = array_column( RestRegistry::$routes, 'args', 'route' );
		$this->assertSame( '__return_true', $routes['/signed']['permission_callback'] );
		$this->assertIsCallable( $routes['/thing']['permission_callback'] );
		$this->assertNotSame( '__return_true', $routes['/thing']['permission_callback'] );
		foreach ( RestRegistry::$routes as $route ) {
			$this->assertSame( 'estasoft-sync/v1', $route['namespace'] );
			$this->assertNotEmpty( $route['args']['permission_callback'] );
		}
	}

	public function test_the_levels_of_registered_routes_are_kept_for_documentation(): void {
		$controller = $this->controller( static fn(): array => array() );
		$controller->registerRoutes();
		$this->assertSame(
			array( 'POST /thing' => 'manage', 'GET /thing' => 'configure', 'POST /signed' => 'signed', 'POST /other' => 'manage' ),
			$controller->registered()
		);
	}

	public function test_an_array_becomes_a_200_answer(): void {
		$controller = $this->controller( static fn(): array => array( 'ok' => true ) );
		$response   = $controller->handle( static fn(): array => array( 'ok' => true ), $this->request(), false );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'ok' => true ), $response->get_data() );
	}

	public function test_an_answer_that_is_already_a_response_keeps_its_status_and_headers(): void {
		$controller = $this->controller( static fn(): array => array() );
		$made       = new \WP_REST_Response( array( 'id' => 5 ), 201 );
		$made->header( 'X-Test', 'yes' );
		$response = $controller->handle( static fn(): \WP_REST_Response => $made, $this->request(), false );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'yes', $response->get_headers()['X-Test'] );
	}

	public function test_a_failure_becomes_the_documented_error_body(): void {
		$controller = $this->controller( static fn(): array => array() );
		$error      = $controller->handle(
			static function (): never {
				throw new ApiException( 'SYNC-MAP-001', 'Product A was not found.', 422, 'needs_person', array( 'sku' => 'A' ) );
			},
			$this->request(),
			false
		);
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'SYNC-MAP-001', $error->get_error_code() );
		$this->assertSame( 'Product A was not found.', $error->get_error_message() );
		$this->assertSame( array( 'status' => 422, 'class' => 'needs_person', 'context' => array( 'sku' => 'A' ) ), $error->get_error_data() );
	}

	public function test_an_unexpected_failure_is_generic_and_reported_without_its_message(): void {
		$controller = $this->controller( static fn(): array => array() );
		$error      = $controller->handle(
			static function (): never {
				throw new \RuntimeException( 'secret=abc123' );
			},
			$this->request(),
			false
		);
		$this->assertSame( 'SYNC-REST-001', $error->get_error_code() );
		$this->assertStringNotContainsString( 'abc123', $error->get_error_message() );
		$this->assertSame( array( 'RuntimeException (code 0)' ), $this->reported );
	}

	public function test_a_repeated_key_replays_the_first_answer_and_runs_the_handler_once(): void {
		$runs       = 0;
		$handler    = static function () use ( &$runs ): array {
			++$runs;
			return array( 'run' => $runs );
		};
		$controller = $this->controller( $handler );
		$first      = $controller->handle( $handler, $this->request( 'POST', 'k1' ), true );
		$second     = $controller->handle( $handler, $this->request( 'POST', 'k1' ), true );
		$this->assertSame( 1, $runs );
		$this->assertSame( array( 'run' => 1 ), $second->get_data() );
		$this->assertSame( 'true', $second->get_headers()['Idempotent-Replayed'] );
		$this->assertArrayNotHasKey( 'Idempotent-Replayed', $first->get_headers() );

		$controller->handle( $handler, $this->request( 'POST', 'k2' ), true );
		$this->assertSame( 2, $runs, 'another key runs again' );
		$controller->handle( $handler, $this->request( 'POST' ), true );
		$controller->handle( $handler, $this->request( 'POST' ), true );
		$this->assertSame( 4, $runs, 'no key, no replay' );
	}

	public function test_a_key_is_ignored_on_a_route_that_does_not_ask_for_replay(): void {
		$runs    = 0;
		$handler = static function () use ( &$runs ): array {
			++$runs;
			return array();
		};
		$controller = $this->controller( $handler );
		$controller->handle( $handler, $this->request( 'POST', 'k1' ), false );
		$controller->handle( $handler, $this->request( 'POST', 'k1' ), false );
		$this->assertSame( 2, $runs );
		$this->assertSame( array(), $this->store->values );
	}

	public function test_a_bad_key_and_a_key_with_another_body_are_errors(): void {
		$controller = $this->controller( static fn(): array => array() );
		$handler    = static fn(): array => array();
		$bad        = $controller->handle( $handler, $this->request( 'POST', 'not valid' ), true );
		$this->assertSame( array( 'estasoft_sync_bad_idempotency_key', 400 ), array( $bad->get_error_code(), $bad->get_error_data()['status'] ) );

		$controller->handle( $handler, $this->request( 'POST', 'k1', '{"a":1}' ), true );
		$conflict = $controller->handle( $handler, $this->request( 'POST', 'k1', '{"a":2}' ), true );
		$this->assertSame( array( 'estasoft_sync_idempotency_conflict', 409 ), array( $conflict->get_error_code(), $conflict->get_error_data()['status'] ) );
	}

	public function test_a_failed_first_try_is_not_replayed(): void {
		$attempts   = 0;
		$handler    = static function () use ( &$attempts ): array {
			++$attempts;
			if ( 1 === $attempts ) {
				throw ApiException::conflict( 'not yet' );
			}
			return array( 'ok' => true );
		};
		$controller = $this->controller( $handler );
		$this->assertInstanceOf( \WP_Error::class, $controller->handle( $handler, $this->request( 'POST', 'k1' ), true ) );
		$this->assertSame( array( 'ok' => true ), $controller->handle( $handler, $this->request( 'POST', 'k1' ), true )->get_data() );
	}

	public function test_a_list_answer_carries_the_wordpress_pagination_headers_from_a_paged_object_or_an_array(): void {
		$controller = $this->controller( static fn(): array => array() );
		$paged      = $controller->pagedPublic( new Paged( array( 1, 2 ), 52, 1, 25 ) );
		$this->assertSame( array( 'X-WP-Total' => '52', 'X-WP-TotalPages' => '3' ), $paged->get_headers() );
		$this->assertSame( array( 'items' => array( 1, 2 ), 'total' => 52, 'total_pages' => 3, 'page' => 1, 'per_page' => 25 ), $paged->get_data() );
		$array = $controller->pagedPublic( array( 'items' => array(), 'total' => 0, 'total_pages' => 0 ) );
		$this->assertSame( array( 'X-WP-Total' => '0', 'X-WP-TotalPages' => '0' ), $array->get_headers() );
	}

	public function test_the_permission_comes_before_a_complaint_about_parameters_on_this_plugins_routes_only(): void {
		$module = new RestModule();
		$error  = new \WP_Error( 'rest_invalid_param', 'Invalid parameter(s): action', array( 'status' => 400 ) );
		$ours   = new \WP_REST_Request( 'POST', '/estasoft-sync/v1/queue/bulk' );
		$others = new \WP_REST_Request( 'POST', '/wp/v2/posts' );

		RestRegistry::$loggedIn = false;
		$handler                = array( 'permission_callback' => static fn(): bool => false );
		$this->assertSame( 401, $module->permissionFirst( $error, $handler, $ours )->get_error_data()['status'], 'not signed in: 401, not 400' );

		RestRegistry::$loggedIn = true;
		$this->assertSame( 403, $module->permissionFirst( $error, $handler, $ours )->get_error_data()['status'] );
		$denied = new \WP_Error( 'rest_forbidden', 'No.', array( 'status' => 403 ) );
		$this->assertSame( $denied, $module->permissionFirst( $error, array( 'permission_callback' => static fn(): \WP_Error => $denied ), $ours ) );

		$this->assertSame( $error, $module->permissionFirst( $error, array( 'permission_callback' => static fn(): bool => true ), $ours ), 'allowed: the parameter error stands' );
		$this->assertSame( $error, $module->permissionFirst( $error, array( 'permission_callback' => '__return_true' ), $ours ), 'the signed route allows everyone' );
		$this->assertSame( $error, $module->permissionFirst( $error, $handler, $others ), 'other plugins are left alone' );
		$ok = array( 'fine' => true );
		$this->assertSame( $ok, $module->permissionFirst( $ok, $handler, $ours ), 'only a failed check is replaced' );
		$this->assertSame( $error, $module->permissionFirst( $error, array(), $ours ), 'no permission callback to ask' );
	}
}
