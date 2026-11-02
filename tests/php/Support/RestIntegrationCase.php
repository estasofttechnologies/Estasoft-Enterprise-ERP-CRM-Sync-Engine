<?php
/**
 * Base class of the REST, Admin and CLI integration tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

require_once __DIR__ . '/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Plugin;

// Screen, menu and help functions are not loaded in a command-line run of WordPress.
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/dashboard.php';
require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

/**
 * Runs requests through the real WordPress REST server (`rest_do_request`) against the running plugin, with a real database. Services
 * of the Jobs module that a test does not need for real are replaced in the container with RestFake objects, and put back afterwards.
 * People are real users with the roles administrator, shop manager and subscriber, created once per test class.
 */
abstract class RestIntegrationCase extends CoreIntegrationTestCase {

	/**
	 * Users by kind: administrator, shop_manager, subscriber.
	 *
	 * @var array<string,int>
	 */
	private static array $users = array();

	/**
	 * Whether this class added the shop manager role.
	 *
	 * @var bool
	 */
	private static bool $addedRole = false;

	/**
	 * The plugin's container.
	 */
	protected Container $container;

	/**
	 * Container instances before the test, to put back.
	 *
	 * @var array<string,mixed>
	 */
	private array $savedInstances = array();

	/**
	 * Create the users.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( null === get_role( 'shop_manager' ) ) {
			add_role( 'shop_manager', 'Shop manager', array( 'read' => true ) );
			self::$addedRole = true;
		}
		Capabilities::grant();
		add_filter( 'pre_wp_mail', '__return_true' ); // Creating users must not try to send email.
		foreach ( array( 'administrator', 'shop_manager', 'subscriber' ) as $role ) {
			$id = wp_insert_user(
				array(
					'user_login' => 'rest_' . $role . '_' . wp_generate_password( 6, false ),
					'user_pass'  => wp_generate_password(),
					'user_email' => $role . '-' . wp_generate_password( 6, false ) . '@example.test',
					'role'       => $role,
				)
			);
			self::$users[ $role ] = (int) $id;
		}
	}

	/**
	 * Delete the users and the role this class added.
	 */
	public static function tearDownAfterClass(): void {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		foreach ( self::$users as $id ) {
			wp_delete_user( $id );
		}
		self::$users = array();
		remove_filter( 'pre_wp_mail', '__return_true' );
		if ( self::$addedRole ) {
			remove_role( 'shop_manager' );
			self::$addedRole = false;
		}
		parent::tearDownAfterClass();
	}

	/**
	 * Remember the container state and sign out.
	 */
	protected function setUp(): void {
		parent::setUp();
		// The same key as the running plugin (ESTASOFT_SYNC_KEY in the bootstrap), so the harness and the container read each other's secrets.
		$this->h         = new CoreHarness( '2026-11-02 12:00:00', str_repeat( 'k', 32 ) );
		$this->container = Plugin::instance()->container();
		$reflection      = new \ReflectionProperty( Container::class, 'instances' );
		$reflection->setAccessible( true );
		$this->savedInstances = (array) $reflection->getValue( $this->container );
		wp_set_current_user( 0 );
	}

	/**
	 * Put the container back and sign out.
	 */
	protected function tearDown(): void {
		if ( $this->hasFailed() && false !== getenv( 'ESTASOFT_TEST_DEBUG' ) ) {
			// Shows why a route answered with an unexpected failure: the log keeps the class of the exception.
			fwrite( STDERR, "\nLog at failure: " . (string) wp_json_encode( array_column( $this->h->logs->latest( 5 ), 'message' ) ) . "\n" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		$reflection = new \ReflectionProperty( Container::class, 'instances' );
		$reflection->setAccessible( true );
		$reflection->setValue( $this->container, $this->savedInstances );
		wp_set_current_user( 0 );
		unset( $GLOBALS['current_screen'] ); // set_current_screen() makes is_admin() true for the rest of the process.
		parent::tearDown();
	}

	/**
	 * Replace a service in the container for this test.
	 *
	 * @param string $key  Container key.
	 * @param object $fake Stand-in.
	 */
	protected function replace( string $key, object $fake ): void {
		$this->container->instance( $key, $fake );
	}

	/**
	 * Sign in as a kind of person, or sign out.
	 *
	 * @param string $kind anonymous, subscriber, shop_manager or administrator.
	 */
	protected function actAs( string $kind ): void {
		wp_set_current_user( 'anonymous' === $kind ? 0 : self::$users[ $kind ] );
	}

	/**
	 * Id of a test user.
	 *
	 * @param string $kind administrator, shop_manager or subscriber.
	 */
	protected function userId( string $kind ): int {
		return self::$users[ $kind ];
	}

	/**
	 * Send a request through the REST server.
	 *
	 * @param string               $method  GET, POST, PUT or DELETE.
	 * @param string               $route   Route under the plugin namespace, for example /queue/1/retry.
	 * @param array<string,mixed>  $params  Query arguments for GET, a JSON body otherwise.
	 * @param array<string,string> $headers Request headers.
	 */
	protected function call( string $method, string $route, array $params = array(), array $headers = array() ): \WP_REST_Response {
		if ( 'GET' === $method ) {
			$request = new \WP_REST_Request( $method, '/estasoft-sync/v1' . $route );
			$request->set_query_params( $params );
			foreach ( $headers as $name => $value ) {
				$request->set_header( $name, $value );
			}
			return rest_do_request( $request );
		}
		return $this->callRaw( $method, $route, (string) wp_json_encode( $params ), $headers );
	}

	/**
	 * Send a request with a body exactly as given (for the signed inbound route, whose signature covers the raw body).
	 *
	 * @param string               $method  Method.
	 * @param string               $route   Route under the plugin namespace.
	 * @param string               $body    Raw body.
	 * @param array<string,string> $headers Request headers.
	 */
	protected function callRaw( string $method, string $route, string $body, array $headers = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/estasoft-sync/v1' . $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( $body );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_do_request( $request );
	}
}
