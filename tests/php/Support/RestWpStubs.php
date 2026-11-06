<?php
/**
 * WordPress classes and functions that the REST and Admin classes call, for unit tests. Every definition is guarded.
 *
 * These are global, and other test areas define their own versions of some of them, so the tests that rely on this file run in a
 * separate PHP process each (RestStubCase). Everything reads and writes Support\RestRegistry.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

use Estasoft\SyncEngine\Tests\Support\RestRegistry;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { // phpcs:ignore
		public $code;
		public $message;
		public $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}
if ( ! class_exists( 'WP_HTTP_Response' ) ) {
	class WP_HTTP_Response { // phpcs:ignore
		public $data;
		public $headers = array();
		public $status;

		public function __construct( $data = null, $status = 200, $headers = array() ) {
			$this->data    = $data;
			$this->status  = $status;
			$this->headers = $headers;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}

		public function set_status( $code ) {
			$this->status = $code;
		}

		public function get_headers() {
			return $this->headers;
		}

		public function header( $key, $value, $replace = true ) {
			$this->headers[ $key ] = $value;
		}
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response extends WP_HTTP_Response { // phpcs:ignore
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request implements ArrayAccess { // phpcs:ignore
		private $method;
		private $route;
		private $params  = array();
		private $headers = array();
		private $body    = '';
		private $query   = array();
		private $url     = array();

		public function __construct( $method = 'GET', $route = '', $attributes = array() ) {
			$this->method = strtoupper( (string) $method );
			$this->route  = (string) $route;
		}

		public function get_method() {
			return $this->method;
		}

		public function get_route() {
			return $this->route;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function has_param( $key ) {
			return array_key_exists( $key, $this->params );
		}

		public function get_params() {
			return $this->params;
		}

		public function set_query_params( $params ) {
			$this->query = (array) $params;
		}

		public function get_query_params() {
			return $this->query;
		}

		public function set_url_params( $params ) {
			$this->url = (array) $params;
		}

		public function get_url_params() {
			return $this->url;
		}

		public function set_header( $key, $value ) {
			$this->headers[ strtolower( str_replace( '_', '-', $key ) ) ] = $value;
		}

		public function get_header( $key ) {
			return $this->headers[ strtolower( str_replace( '_', '-', $key ) ) ] ?? null;
		}

		public function set_body( $body ) {
			$this->body = $body;
		}

		public function get_body() {
			return $this->body;
		}

		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		public function offsetGet( $offset ): mixed {
			return $this->params[ $offset ] ?? null;
		}

		public function offsetSet( $offset, $value ): void {
			$this->params[ $offset ] = $value;
		}

		public function offsetUnset( $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}
}
if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	class WP_Admin_Bar { // phpcs:ignore
		public $nodes = array();

		public function add_node( $args ) {
			$this->nodes[ $args['id'] ] = $args;
		}
	}
}
if ( ! class_exists( 'WP_Screen' ) ) {
	class WP_Screen { // phpcs:ignore
		public $id = '';
		public $tabs = array();
		public $sidebar = '';

		public function add_help_tab( $args ) {
			$this->tabs[ $args['id'] ] = $args;
		}

		public function set_help_sidebar( $content ) {
			$this->sidebar = $content;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { // phpcs:ignore
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $namespace, $route, $args = array() ) { // phpcs:ignore
		RestRegistry::$routes[] = array(
			'namespace' => (string) $namespace,
			'route'     => (string) $route,
			'args'      => (array) $args,
		);
		return true;
	}
}
if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	function rest_authorization_required_code() { // phpcs:ignore
		return RestRegistry::$loggedIn ? 403 : 401;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) { // phpcs:ignore
		return in_array( (string) $capability, RestRegistry::$caps, true );
	}
}
if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, $capability ) { // phpcs:ignore
		return in_array( (string) $capability, RestRegistry::$userCaps[ (int) $user ] ?? array(), true );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { // phpcs:ignore
		return RestRegistry::$loggedIn ? RestRegistry::$userId : 0;
	}
}
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() { // phpcs:ignore
		return (object) array(
			'ID'           => RestRegistry::$userId,
			'display_name' => 'Layla Haddad',
			'user_login'   => 'layla',
		);
	}
}
if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	function rest_sanitize_boolean( $value ) { // phpcs:ignore
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( $value ), array( 'false', '0', '' ), true );
		}
		return (bool) $value;
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) { // phpcs:ignore
		return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { // phpcs:ignore
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) { // phpcs:ignore
		return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $text ) ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $text ) { // phpcs:ignore
		return trim( strip_tags( (string) $text ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) { // phpcs:ignore
		return (string) preg_replace( '/[^a-zA-Z0-9@._+\-]/', '', (string) $email );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { // phpcs:ignore
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { // phpcs:ignore
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { // phpcs:ignore
		return preg_match( '#^(https?://|/|\#|\?)#', (string) $url ) ? htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ) : '';
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $text, $domain = 'default' ) { // phpcs:ignore
		echo esc_html( $text ); // phpcs:ignore
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) { // phpcs:ignore
		return number_format( (float) $number, (int) $decimals );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { // phpcs:ignore
		return 'test-salt-' . $scheme;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) { // phpcs:ignore
		return RestRegistry::$transients[ $name ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $ttl = 0 ) { // phpcs:ignore
		RestRegistry::$transients[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) { // phpcs:ignore
		unset( RestRegistry::$transients[ $name ] );
		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { // phpcs:ignore
		return RestRegistry::$options[ $name ] ?? $default;
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user, $key, $single = false ) { // phpcs:ignore
		return RestRegistry::$userMeta[ $user . ':' . $key ] ?? '';
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user, $key, $value ) { // phpcs:ignore
		RestRegistry::$userMeta[ $user . ':' . $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_user_option' ) ) {
	function get_user_option( $option, $user = 0 ) { // phpcs:ignore
		return RestRegistry::$userMeta[ $user . ':' . $option ] ?? false;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $name, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore
		RestRegistry::$hooks[] = array( 'action', (string) $name, $callback, (int) $priority );
		return true;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $name, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore
		RestRegistry::$hooks[] = array( 'filter', (string) $name, $callback, (int) $priority );
		return true;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { // phpcs:ignore
		return RestRegistry::$request['admin'];
	}
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() { // phpcs:ignore
		return RestRegistry::$request['ajax'];
	}
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() { // phpcs:ignore
		return RestRegistry::$request['cron'];
	}
}
if ( ! function_exists( 'is_network_admin' ) ) {
	function is_network_admin() { // phpcs:ignore
		return RestRegistry::$request['network'];
	}
}
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( $location, $status = 302, $by = 'WordPress' ) { // phpcs:ignore
		RestRegistry::$redirects[] = (string) $location;
		return true;
	}
}
if ( ! function_exists( 'wp_get_referer' ) ) {
	function wp_get_referer() { // phpcs:ignore
		return false;
	}
}
if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $arg = '_wpnonce' ) { // phpcs:ignore
		if ( ( $_GET[ $arg ] ?? '' ) !== 'nonce-for-' . $action ) {
			throw new RuntimeException( 'nonce failed' );
		}
		return 1;
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) { // phpcs:ignore
		return 'nonce-for-' . $action;
	}
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) { // phpcs:ignore
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $name . '=nonce-for-' . $action;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( ...$args ) { // phpcs:ignore
		$params = is_array( $args[0] ) ? $args[0] : array( $args[0] => $args[1] );
		$url    = (string) ( is_array( $args[0] ) ? ( $args[1] ?? '' ) : ( $args[2] ?? '' ) );
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $params );
	}
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) { // phpcs:ignore
		RestRegistry::$assets[] = compact( 'handle', 'src', 'deps', 'ver', 'in_footer' ) + array( 'kind' => 'script' );
		return true;
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) { // phpcs:ignore
		RestRegistry::$assets[] = array( 'kind' => 'enqueue-script', 'handle' => $handle, 'src' => $src );
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) { // phpcs:ignore
		RestRegistry::$assets[] = array( 'kind' => 'style', 'handle' => $handle, 'src' => $src, 'deps' => $deps, 'ver' => $ver );
	}
}
if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $data, $position = 'after' ) { // phpcs:ignore
		RestRegistry::$assets[] = array( 'kind' => 'inline-script', 'handle' => $handle, 'data' => $data, 'position' => $position );
		return true;
	}
}
if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( $handle, $domain = 'default', $path = '' ) { // phpcs:ignore
		RestRegistry::$assets[] = array( 'kind' => 'translations', 'handle' => $handle, 'domain' => $domain, 'path' => $path );
		return true;
	}
}
if ( ! function_exists( 'wp_style_add_data' ) ) {
	function wp_style_add_data( $handle, $key, $value ) { // phpcs:ignore
		RestRegistry::$assets[] = array( 'kind' => 'style-data', 'handle' => $handle, 'key' => $key, 'value' => $value );
		return true;
	}
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( $parent, $title, $menu_title, $cap, $slug, $callback = '' ) { // phpcs:ignore
		RestRegistry::$submenus[] = compact( 'parent', 'title', 'menu_title', 'cap', 'slug', 'callback' );
		return $parent . '_page_' . $slug;
	}
}
if ( ! function_exists( 'add_screen_option' ) ) {
	function add_screen_option( $option, $args = array() ) { // phpcs:ignore
		RestRegistry::$screenOptions[] = array( 'option' => $option, 'args' => $args );
	}
}
if ( ! function_exists( 'wp_add_dashboard_widget' ) ) {
	function wp_add_dashboard_widget( $id, $name, $callback ) { // phpcs:ignore
		RestRegistry::$widgets[] = compact( 'id', 'name', 'callback' );
	}
}
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() { // phpcs:ignore
		if ( array() === RestRegistry::$screenId ) {
			return null;
		}
		$screen     = new WP_Screen();
		$screen->id = (string) RestRegistry::$screenId['id'];
		return $screen;
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) { // phpcs:ignore
		return 'estasoft-enterprise-erpcrm-sync-engine/' . basename( (string) $file );
	}
}
