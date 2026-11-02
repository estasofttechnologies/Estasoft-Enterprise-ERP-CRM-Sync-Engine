<?php
/**
 * Every controller's routes, read as they are registered: schemas are sound and no route is open by mistake.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Rest;

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Rest\ErrorMapper;
use Estasoft\SyncEngine\Rest\IdempotencyCache;
use Estasoft\SyncEngine\Rest\RestModule;
use Estasoft\SyncEngine\Rest\Support\ConnectionResolver;
use Estasoft\SyncEngine\Tests\Support\RestArrayStore;
use Estasoft\SyncEngine\Tests\Support\RestRegistry;
use Estasoft\SyncEngine\Tests\Support\RestStubCase;

/**
 * Every controller's routes, read as they are registered: schemas are sound and no route is open by mistake.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RouteSchemasTest extends RestStubCase {

	/**
	 * Register every route into the recorder.
	 *
	 * @return array<int,array{namespace:string,route:string,args:array<string,mixed>}>
	 */
	private function routes(): array {
		$container = new Container();
		$container->instance( RestModule::ERRORS, new ErrorMapper() );
		$container->instance( RestModule::IDEMPOTENCY, new IdempotencyCache( new RestArrayStore(), 'salt' ) );
		$container->instance( RestModule::RESOLVER, new ConnectionResolver( static fn(): array => array() ) );
		foreach ( ( new RestModule() )->controllers( $container ) as $controller ) {
			$controller->registerRoutes();
		}
		return RestRegistry::$routes;
	}

	public function test_there_is_one_controller_per_group_and_each_registers_routes(): void {
		$this->assertCount( 22, RestModule::CONTROLLERS );
		$container = new Container();
		$container->instance( RestModule::ERRORS, new ErrorMapper() );
		$container->instance( RestModule::IDEMPOTENCY, new IdempotencyCache( new RestArrayStore(), 'salt' ) );
		$container->instance( RestModule::RESOLVER, new ConnectionResolver( static fn(): array => array() ) );
		foreach ( ( new RestModule() )->controllers( $container ) as $controller ) {
			RestRegistry::$routes = array();
			$controller->registerRoutes();
			$this->assertNotEmpty( RestRegistry::$routes, get_class( $controller ) );
			$this->assertNotEmpty( $controller->registered(), get_class( $controller ) );
		}
	}

	public function test_exactly_one_route_allows_everyone_and_it_is_the_inbound_route(): void {
		$open = array();
		foreach ( $this->routes() as $route ) {
			$this->assertNotEmpty( $route['args']['permission_callback'] ?? null, $route['route'] );
			if ( '__return_true' === $route['args']['permission_callback'] ) {
				$open[] = $route['route'];
			}
		}
		$this->assertSame( array( '/inbound/(?P<connection>\d+)' ), $open );
	}

	public function test_every_argument_has_a_type_and_the_ids_in_the_url_are_required_positive_integers(): void {
		foreach ( $this->routes() as $route ) {
			foreach ( $route['args']['args'] as $name => $schema ) {
				$this->assertArrayHasKey( 'type', $schema, $route['route'] . ' ' . $name );
			}
			if ( 1 === preg_match_all( '/\(\?P<(\w+)>\\\\d\+\)/', $route['route'], $groups ) ) {
				foreach ( $groups[1] as $group ) {
					$schema = $route['args']['args'][ $group ] ?? array();
					$this->assertSame( 'integer', $schema['type'] ?? '', $route['route'] . ' ' . $group );
					$this->assertSame( 1, $schema['minimum'] ?? 0, $route['route'] . ' ' . $group );
					$this->assertTrue( $schema['required'] ?? false, $route['route'] . ' ' . $group );
				}
			}
		}
	}

	public function test_every_registration_names_one_plain_http_method_and_a_callback(): void {
		foreach ( $this->routes() as $route ) {
			$this->assertContains( $route['args']['methods'], array( 'GET', 'POST', 'PUT', 'DELETE' ), $route['route'] );
			$this->assertIsCallable( $route['args']['callback'], $route['route'] );
		}
	}

	public function test_mapping_routes_name_the_six_types_in_the_route_so_preview_never_clashes_with_a_type(): void {
		$patterns = array_column( $this->routes(), 'route' );
		$type     = '(?P<type>orders|customers|products|refunds|stock|tracking)';
		$this->assertContains( '/mappings/' . $type, $patterns );
		$this->assertContains( '/mappings/preview', $patterns );
		$this->assertSame( 0, preg_match( '#^/mappings/' . preg_quote( $type, '#' ) . '$#', '/mappings/preview' ) );
		$this->assertSame( 1, preg_match( '#^/mappings/(orders|customers|products|refunds|stock|tracking)$#', '/mappings/orders' ) );
		$this->assertSame( 0, preg_match( '#^/mappings/(orders|customers|products|refunds|stock|tracking)$#', '/mappings/preview' ) );
	}

	public function test_number_arguments_carry_the_ranges_of_the_specification(): void {
		$by = array();
		foreach ( $this->routes() as $route ) {
			$by[ $route['args']['methods'] . ' ' . $route['route'] ] = $route['args']['args'];
		}
		$stock = $by['PUT /stock/rules'];
		$this->assertSame( array( 0, 999 ), array( $stock['safety_buffer']['minimum'], $stock['safety_buffer']['maximum'] ), 'safety buffer 0 to 999' );
		$settings = $by['PUT /settings'];
		$this->assertSame( array( 10, 10000 ), array( $settings['backlog_threshold']['minimum'], $settings['backlog_threshold']['maximum'] ), 'queue warning 10 to 10,000' );
		$this->assertSame( array( 1, 10 ), array( $settings['global_concurrency']['minimum'], $settings['global_concurrency']['maximum'] ) );
		$this->assertSame( array( 7, 14, 30 ), $settings['log_retention_days']['enum'] );
		$this->assertSame( 10, $settings['notifications']['properties']['recipients']['maxItems'], 'up to 10 recipients' );
		$this->assertSame( array( 25, 50, 100 ), $by['GET /queue']['per_page']['enum'] );
	}
}
