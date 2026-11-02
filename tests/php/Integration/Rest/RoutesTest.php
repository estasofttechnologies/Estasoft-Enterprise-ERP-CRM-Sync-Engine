<?php
/**
 * Every route is registered, has a permission check, and refuses the people it should (AS-25).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Rest;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Rest\BaseController;
use Estasoft\SyncEngine\Rest\RestModule;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class RoutesTest extends RestIntegrationCase {

	/**
	 * The level each route needs, written down independently of the code (spec 7.2 and the review-2 list).
	 */
	private const LEVELS = array(
		'GET /status'                                        => 'manage',
		'GET /queue'                                         => 'manage',
		'GET /queue/attention'                               => 'manage',
		'GET /queue/{n}'                                     => 'manage',
		'POST /queue/{n}/retry'                              => 'manage',
		'POST /queue/{n}/skip'                               => 'manage',
		'POST /queue/{n}/restore'                            => 'manage',
		'POST /queue/retry'                                  => 'manage',
		'POST /queue/bulk'                                   => 'manage',
		'POST /sync/run'                                     => 'manage',
		'POST /pause'                                        => 'manage',
		'POST /resume'                                       => 'manage',
		'POST /connections/{n}/pause'                        => 'manage',
		'POST /connections/{n}/resume'                       => 'manage',
		'GET /connections'                                   => 'manage',
		'POST /connections'                                  => 'configure',
		'GET /connections/{n}'                               => 'manage',
		'POST /connections/{n}'                              => 'configure',
		'DELETE /connections/{n}'                            => 'configure',
		'POST /connections/{n}/test'                         => 'configure',
		'POST /connections/test'                             => 'configure',
		'POST /connections/{n}/inbound'                      => 'configure',
		'POST /connections/{n}/oauth/start'                  => 'configure',
		'GET /connections/{n}/oauth/organisations'           => 'configure',
		'GET /connections/{n}/oauth/callback'                => 'state',
		'POST /connections/{n}/oauth/organisation'           => 'configure',
		'GET /oauth/callback'                                => 'state',
		'GET /exports'                                       => 'manage',
		'POST /exports'                                      => 'configure',
		'GET /exports/{n}/download'                          => 'manage',
		'DELETE /exports/{n}'                                => 'configure',
		'GET /catalog'                                       => 'manage',
		'GET /mappings/{t}'                                  => 'manage',
		'PUT /mappings/{t}'                                  => 'configure',
		'GET /mappings/{t}/versions/{n}'                     => 'manage',
		'POST /mappings/{t}/publish'                         => 'configure',
		'POST /mappings/{t}/discard'                         => 'configure',
		'POST /mappings/{t}/rollback'                        => 'configure',
		'POST /mappings/preview'                             => 'configure',
		'GET /tax-map'                                       => 'manage',
		'PUT /tax-map'                                       => 'configure',
		'GET /products/unmatched'                            => 'manage',
		'GET /products/search'                               => 'configure',
		'POST /products/map'                                 => 'configure',
		'GET /stock/rules'                                   => 'manage',
		'PUT /stock/rules'                                   => 'configure',
		'GET /stock/overview'                                => 'manage',
		'POST /stock/conflicts/{n}/accept'                   => 'manage',
		'POST /stock/conflicts/{n}/hold'                     => 'manage',
		'GET /reconciliation'                                => 'manage',
		'POST /reconciliation/run'                           => 'manage',
		'POST /reconciliation/differences/{n}/repair'        => 'manage',
		'POST /reconciliation/differences/{n}/ignore'        => 'manage',
		'PUT /reconciliation/settings'                       => 'configure',
		'GET /invoices'                                      => 'manage',
		'GET /logs'                                          => 'manage',
		'GET /logs/{n}'                                      => 'manage',
		'GET /readiness'                                     => 'manage',
		'POST /readiness/run'                                => 'manage',
		'GET /support-report'                                => 'manage',
		'GET /settings'                                      => 'manage',
		'PUT /settings'                                      => 'configure',
		'POST /tools/export'                                 => 'configure',
		'POST /tools/import'                                 => 'configure',
		'POST /tools/clear-logs'                             => 'configure',
		'POST /tools/delete-data'                            => 'configure',
		'POST /notifications/test'                           => 'configure',
		'POST /capacity-test'                                => 'configure',
		'GET /onboarding'                                    => 'manage',
		'PUT /onboarding'                                    => 'configure',
		'POST /go-live'                                      => 'configure',
		'POST /shadow/start'                                 => 'configure',
		'GET /shadow/report'                                 => 'manage',
		'POST /shadow/end'                                   => 'configure',
		'GET /orders/sample'                                 => 'manage',
		'POST /inbound/{n}'                                  => 'signed',
	);

	/**
	 * Valid parameters, so that a parameter error never hides the answer being tested.
	 */
	private const BODIES = array(
		'POST /queue/{n}/skip'                        => array( 'reason' => 'test' ),
		'POST /queue/bulk'                            => array(
			'ids'    => array( 1 ),
			'action' => 'retry',
		),
		'POST /connections'                           => array( 'connector' => 'odoo' ),
		'POST /connections/test'                      => array( 'connector' => 'odoo' ),
		'POST /connections/{n}/inbound'               => array( 'enabled' => false ),
		'PUT /mappings/{t}'                           => array( 'rules' => array() ),
		'POST /mappings/{t}/rollback'                 => array( 'version' => 1 ),
		'POST /mappings/preview'                      => array(
			'type'     => 'orders',
			'order_id' => 1,
		),
		'PUT /tax-map'                                => array( 'rows' => array() ),
		'GET /products/search'                        => array( 'q' => 'lamp' ),
		'POST /products/map'                          => array( 'product_id' => 1 ),
		'POST /reconciliation/run'                    => array( 'window_days' => 7 ),
		'POST /reconciliation/differences/{n}/repair' => array( 'direction' => 'wc_to_system' ),
		'POST /reconciliation/differences/{n}/ignore' => array( 'reason' => 'test' ),
		'POST /tools/import'                          => array( 'json' => '{}' ),
		'POST /tools/delete-data'                     => array( 'word' => 'not the word' ),
		'POST /go-live'                               => array( 'mode' => 'shadow' ),
		'GET /connections/{n}/oauth/callback'         => array( 'state' => 'x' ),
		'GET /oauth/callback'                         => array( 'state' => 'x' ),
		'POST /connections/{n}/oauth/organisation'    => array( 'id' => 'org-1' ),
		'POST /exports'                               => array( 'connection_id' => 1 ),
	);

	/**
	 * Every route of the live REST server: key like "GET /queue/{n}" to its pattern.
	 *
	 * @return array<string,string>
	 */
	private function registered(): array {
		$keys = array();
		foreach ( rest_get_server()->get_routes( 'estasoft-sync/v1' ) as $pattern => $handlers ) {
			$route = substr( (string) $pattern, strlen( '/estasoft-sync/v1' ) );
			if ( '' === $route ) {
				continue; // The namespace index that WordPress adds itself.
			}
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( $handler['methods'] ) as $method ) {
					$keys[ $method . ' ' . self::normal( $route ) ] = $route;
				}
			}
		}
		return $keys;
	}

	/**
	 * A route pattern with its groups replaced: {n} for a number, {t} for a mapping type.
	 *
	 * @param string $route Pattern such as /queue/(?P<id>\d+)/retry.
	 */
	private static function normal( string $route ): string {
		$route = (string) preg_replace( '/\(\?P<type>[^)]+\)/', '{t}', $route );
		$route = (string) preg_replace( '/\(\?P<file>[^)]+\)\)?/', '{n}', str_replace( '(?P<file>[a-f0-9]{1,32})', '(?P<file>x)', $route ) );
		return (string) preg_replace( '/\(\?P<\w+>\\\\d\+\)/', '{n}', $route );
	}

	/**
	 * The routes listed in the endpoint comment of src/api/types.ts, as normalised keys.
	 *
	 * @return array<int,string>
	 */
	private function declaredInTypes(): array {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/src/api/types.ts' );
		$keys   = array();
		foreach ( explode( "\n", $source ) as $line ) {
			if ( 1 !== preg_match( '/^\s*\*\s+(GET|POST|PUT|DELETE)\s+(\/\S*)/', $line, $match ) || str_contains( $line, 'not in Phase 1' ) ) {
				continue;
			}
			$path   = (string) preg_replace( '/\?.*$/', '', $match[2] );
			$path   = (string) preg_replace( '/\{type\}/', '{t}', $path );
			$path   = (string) preg_replace( '/\{(id|version|connection)\}/', '{n}', $path );
			$keys[] = $match[1] . ' ' . $path;
		}
		return $keys;
	}

	public function test_every_route_in_the_endpoint_list_of_types_ts_is_registered(): void {
		$registered = $this->registered();
		$declared   = $this->declaredInTypes();
		$this->assertGreaterThan( 55, count( $declared ), 'the endpoint list was read' );
		foreach ( $declared as $key ) {
			$this->assertArrayHasKey( $key, $registered, "$key is in src/api/types.ts but not registered" );
		}
	}

	public function test_no_route_exists_that_is_not_in_the_documented_list(): void {
		$registered = $this->registered();
		foreach ( array_keys( self::LEVELS ) as $key ) {
			$this->assertArrayHasKey( $key, $registered, "$key is documented but not registered" );
		}
		foreach ( array_keys( $registered ) as $key ) {
			$this->assertArrayHasKey( $key, self::LEVELS, "$key is registered but not in the documented list" );
		}
	}

	public function test_every_route_in_the_live_server_has_a_permission_callback_and_only_the_signed_one_allows_everyone(): void {
		$routes = rest_get_server()->get_routes( 'estasoft-sync/v1' );
		$this->assertNotEmpty( $routes );
		$open = array();
		foreach ( $routes as $route => $handlers ) {
			if ( '/estasoft-sync/v1' === $route ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$this->assertArrayHasKey( 'permission_callback', $handler, "permission_callback of $route" );
				$this->assertNotEmpty( $handler['permission_callback'], "permission_callback of $route" );
				if ( '__return_true' === $handler['permission_callback'] ) {
					$open[] = $route;
				}
			}
		}
		$this->assertCount( 1, $open );
		$this->assertStringContainsString( '/inbound/', $open[0] );
	}

	/**
	 * A concrete request for a documented key.
	 *
	 * @param string $key Key such as "POST /queue/{n}/retry".
	 * @return array{0:string,1:string,2:array<string,mixed>}
	 */
	private function concrete( string $key ): array {
		list( $method, $path ) = explode( ' ', $key, 2 );
		$path                  = str_replace( array( '{n}', '{t}' ), array( '1', 'orders' ), $path );
		return array( $method, $path, self::BODIES[ $key ] ?? array() );
	}

	public function test_a_visitor_who_is_not_signed_in_gets_401_everywhere_except_the_signed_route_and_the_callback(): void {
		$this->actAs( 'anonymous' );
		foreach ( self::LEVELS as $key => $level ) {
			list( $method, $path, $body ) = $this->concrete( $key );
			$status                       = $this->call( $method, $path, $body )->get_status();
			if ( 'manage' === $level || 'configure' === $level ) {
				$this->assertSame( 401, $status, "$key for a visitor" );
			}
		}
	}

	public function test_a_visitor_with_wrong_parameters_still_gets_401_not_a_parameter_error(): void {
		$this->actAs( 'anonymous' );
		$this->assertSame( 401, $this->call( 'POST', '/queue/bulk', array( 'action' => 'nonsense' ) )->get_status() );
		$this->assertSame( 401, $this->call( 'PUT', '/settings', array( 'log_retention_days' => 999 ) )->get_status() );
		$this->assertSame( 401, $this->call( 'GET', '/queue', array( 'per_page' => 7 ) )->get_status() );
	}

	public function test_a_subscriber_gets_403_on_every_route_that_needs_a_capability(): void {
		$this->actAs( 'subscriber' );
		foreach ( self::LEVELS as $key => $level ) {
			if ( 'manage' !== $level && 'configure' !== $level ) {
				continue;
			}
			list( $method, $path, $body ) = $this->concrete( $key );
			$this->assertSame( 403, $this->call( $method, $path, $body )->get_status(), "$key for a subscriber" );
		}
	}

	public function test_a_shop_manager_is_refused_on_configure_routes_and_not_refused_on_manage_routes(): void {
		$this->actAs( 'shop_manager' );
		foreach ( self::LEVELS as $key => $level ) {
			list( $method, $path, $body ) = $this->concrete( $key );
			$status                       = $this->call( $method, $path, $body )->get_status();
			if ( 'configure' === $level ) {
				$this->assertSame( 403, $status, "$key for a shop manager (AS-25)" );
			} elseif ( 'manage' === $level ) {
				$this->assertNotContains( $status, array( 401, 403 ), "$key for a shop manager" );
			}
		}
	}

	public function test_an_administrator_is_not_refused_anywhere(): void {
		$this->actAs( 'administrator' );
		foreach ( self::LEVELS as $key => $level ) {
			if ( 'manage' !== $level && 'configure' !== $level ) {
				continue;
			}
			list( $method, $path, $body ) = $this->concrete( $key );
			$this->assertNotContains( $this->call( $method, $path, $body )->get_status(), array( 401, 403 ), "$key for an administrator" );
		}
	}

	public function test_a_shop_manager_cannot_change_mapping_or_read_credentials_by_route(): void {
		$this->actAs( 'shop_manager' );
		$this->assertSame( 403, $this->call( 'PUT', '/mappings/orders', array( 'rules' => array() ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/mappings/orders/publish' )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/connections/1', array( 'secrets' => array( 'api_key' => 'x' ) ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/connections/1/test' )->get_status() );
		$this->assertSame( 403, $this->call( 'PUT', '/settings', array( 'redaction' => false ) )->get_status() );
	}

	public function test_a_signed_in_request_without_the_rest_nonce_is_treated_as_signed_out(): void {
		$this->actAs( 'administrator' );
		$_COOKIE[ LOGGED_IN_COOKIE ]    = 'x';
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );

		rest_cookie_check_errors( null );
		$this->assertSame( 0, get_current_user_id(), 'WordPress drops the user when the nonce is missing' );
		$this->assertSame( 401, $this->call( 'GET', '/status' )->get_status() );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ], $GLOBALS['wp_rest_auth_cookie'] );
	}
}
