<?php
/**
 * Install and smoke checks of the release zip, one phase per process. Called by bin/smoke-install.sh; it can also be run by hand:
 *
 *   SMOKE_CORE=/path/to/wordpress SMOKE_CONTENT=/path/to/wp-content SMOKE_DB=name php bin/smoke-install.php <phase> [argument]
 *
 * The plugin under test is the UNZIPPED release zip in $SMOKE_CONTENT/plugins/estasoft-erp-crm-sync-engine, never the repository. Each phase loads
 * WordPress with WP_DEBUG and a debug.log, prints "ok   ..." or "FAIL ..." lines and exits 1 when a check failed. A phase that changes state
 * (activation, uninstall) runs in its own process, so every phase sees a real WordPress request.
 *
 * Phases: install, guard (old-php or deps), set-plugins (comma list of woocommerce and estasoft), no-woo, activate, runtime, seed, deactivate,
 * uninstall-keep, uninstall-delete, log.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	exit( 2 );
}

const ESTASOFT_SMOKE_SLUG      = 'estasoft-erp-crm-sync-engine';
const ESTASOFT_SMOKE_PLUGIN    = 'estasoft-erp-crm-sync-engine/estasoft-erp-crm-sync-engine.php';
const ESTASOFT_SMOKE_NAMESPACE = 'estasoft-sync/v1';

$estasoft_smoke_failures = 0;
$estasoft_smoke_checks   = 0;

/**
 * Print a passed check.
 *
 * @param string $message What was checked.
 */
function estasoft_smoke_ok( string $message ): void {
	global $estasoft_smoke_checks;
	++$estasoft_smoke_checks;
	echo 'ok   ' . $message . "\n";
}

/**
 * Print a failed check and remember it.
 *
 * @param string $message What went wrong.
 */
function estasoft_smoke_fail( string $message ): void {
	global $estasoft_smoke_failures, $estasoft_smoke_checks;
	++$estasoft_smoke_failures;
	++$estasoft_smoke_checks;
	echo 'FAIL ' . $message . "\n";
}

/**
 * Check a condition.
 *
 * @param bool   $condition Must be true.
 * @param string $message   What is being checked.
 * @param string $detail    Extra words printed when it fails.
 */
function estasoft_smoke_check( bool $condition, string $message, string $detail = '' ): void {
	if ( $condition ) {
		estasoft_smoke_ok( $message );
		return;
	}
	estasoft_smoke_fail( $message . ( '' !== $detail ? ' (' . $detail . ')' : '' ) );
}

/**
 * Print information that is not a pass or a fail.
 *
 * @param string $message Text.
 */
function estasoft_smoke_info( string $message ): void {
	echo 'info ' . $message . "\n";
}

/**
 * A required environment value.
 *
 * @param string $name Variable name.
 */
function estasoft_smoke_env( string $name ): string {
	$value = getenv( $name );
	if ( false === $value || '' === $value ) {
		fwrite( STDERR, "Set {$name} (see the top of bin/smoke-install.php).\n" );
		exit( 2 );
	}
	return $value;
}

/**
 * Names of the tables of this site that belong to the plugin.
 *
 * @return array<int,string>
 */
function estasoft_smoke_tables(): array {
	global $wpdb;
	$like = $wpdb->esc_like( $wpdb->prefix . 'estasoft_sync_' ) . '%';
	return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) ); // phpcs:ignore
}

/**
 * Names of the options (and transients) of the plugin.
 *
 * @return array<int,string>
 */
function estasoft_smoke_options(): array {
	global $wpdb;
	$found = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%estasoft\\_sync%'" ); // phpcs:ignore
	return array_map( 'strval', (array) $found );
}

/**
 * Normalise a route for comparison: path parameters of any name become {}.
 *
 * @param string $route Route in the documentation form (/queue/{id}) or the WordPress form (/queue/(?P<id>\d+)).
 */
function estasoft_smoke_route_shape( string $route ): string {
	$route = (string) preg_replace( '#^/' . preg_quote( ESTASOFT_SMOKE_NAMESPACE, '#' ) . '#', '', $route );
	$route = (string) preg_replace( '/\(\?P<\w+>[^)]*\)/', '{}', $route );
	$route = (string) preg_replace( '/\{\w+\}/', '{}', $route );
	return rtrim( $route, '/' );
}

/**
 * Fill the parameters of a route with a plausible value so it can be dispatched.
 *
 * @param string $route Route in the WordPress form.
 */
function estasoft_smoke_route_url( string $route ): string {
	$route = (string) preg_replace_callback(
		'/\(\?P<(\w+)>[^)]*\)/',
		static function ( array $m ): string {
			return 'type' === $m[1] ? 'orders' : '1';
		},
		$route
	);
	return $route;
}

/**
 * Routes listed in docs/rest-api.md as "METHOD /route".
 *
 * @param string $repo Repository root (the docs are not in the zip).
 * @return array<string,string> Shape of "METHOD /route" => the text as written.
 */
function estasoft_smoke_documented_routes( string $repo ): array {
	$text   = is_readable( $repo . '/docs/rest-api.md' ) ? (string) file_get_contents( $repo . '/docs/rest-api.md' ) : '';
	$routes = array();
	if ( preg_match_all( '/^\|\s*`(GET|POST|PUT|PATCH|DELETE) (\/[^`\s]*)`/m', $text, $found, PREG_SET_ORDER ) > 0 ) {
		foreach ( $found as $match ) {
			$routes[ $match[1] . ' ' . estasoft_smoke_route_shape( $match[2] ) ] = $match[1] . ' ' . $match[2];
		}
	}
	return $routes;
}

/**
 * Load WordPress in this process with debugging on. Must be called from the global scope, because WordPress expects it.
 *
 * @param bool $installing Whether to run in install mode (no plugins, no checks for an existing install).
 * @param bool $admin      Whether the request is an admin request (is_admin() true).
 */
function estasoft_smoke_constants( bool $installing, bool $admin ): void {
	$content = rtrim( estasoft_smoke_env( 'SMOKE_CONTENT' ), '/' );
	define( 'ABSPATH', rtrim( estasoft_smoke_env( 'SMOKE_CORE' ), '/' ) . '/' );
	define( 'WP_CONTENT_DIR', $content );
	define( 'WP_PLUGIN_DIR', $content . '/plugins' );
	define( 'WPMU_PLUGIN_DIR', $content . '/mu-plugins' );
	define( 'DB_NAME', estasoft_smoke_env( 'SMOKE_DB' ) );
	define( 'DB_USER', getenv( 'SMOKE_DB_USER' ) ? (string) getenv( 'SMOKE_DB_USER' ) : 'root' );
	define( 'DB_PASSWORD', false !== getenv( 'SMOKE_DB_PASSWORD' ) ? (string) getenv( 'SMOKE_DB_PASSWORD' ) : '' );
	define( 'DB_HOST', getenv( 'SMOKE_DB_HOST' ) ? (string) getenv( 'SMOKE_DB_HOST' ) : 'localhost' );
	define( 'DB_CHARSET', 'utf8mb4' );
	define( 'DB_COLLATE', '' );
	define( 'WP_HOME', 'http://smoke.test' );
	define( 'WP_SITEURL', 'http://smoke.test' );
	define( 'WP_DEBUG', true );
	define( 'WP_DEBUG_LOG', $content . '/debug.log' );
	define( 'WP_DEBUG_DISPLAY', false );
	define( 'DISABLE_WP_CRON', true );
	define( 'AUTOMATIC_UPDATER_DISABLED', true );
	define( 'ESTASOFT_SYNC_KEY', 'smoke-test-key-' . str_repeat( 'k', 32 ) );
	if ( $installing ) {
		define( 'WP_INSTALLING', true );
	}
	if ( $admin ) {
		define( 'WP_ADMIN', true );
	}
	$GLOBALS['table_prefix']          = 'smoke_';
	$_SERVER['HTTP_HOST']             = 'smoke.test';
	$_SERVER['SERVER_NAME']           = 'smoke.test';
	$_SERVER['REQUEST_METHOD']        = 'GET';
	$_SERVER['REQUEST_URI']           = '/wp-admin/admin.php?page=estasoft-sync';
	$_SERVER['REMOTE_ADDR']           = '127.0.0.1';
}

$estasoft_smoke_phase = $argv[1] ?? '';
$estasoft_smoke_arg   = $argv[2] ?? '';
$estasoft_smoke_repo  = dirname( __DIR__ );
if ( ! in_array( $estasoft_smoke_phase, array( 'install', 'guard', 'set-plugins', 'no-woo', 'activate', 'runtime', 'seed', 'deactivate', 'uninstall-keep', 'uninstall-delete', 'log' ), true ) ) {
	fwrite( STDERR, "Unknown phase '{$estasoft_smoke_phase}'. See the top of bin/smoke-install.php.\n" );
	exit( 2 );
}

if ( 'guard' === $estasoft_smoke_phase ) {
	exit( estasoft_smoke_guard( $estasoft_smoke_arg ) );
}

estasoft_smoke_constants( 'install' === $estasoft_smoke_phase, in_array( $estasoft_smoke_phase, array( 'runtime', 'no-woo' ), true ) );
require_once ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$estasoft_smoke_function = 'estasoft_smoke_phase_' . str_replace( '-', '_', $estasoft_smoke_phase );
$estasoft_smoke_function( $estasoft_smoke_arg, $estasoft_smoke_repo );

echo sprintf( "%s: %d checks, %d failed.\n", $estasoft_smoke_phase, $estasoft_smoke_checks, $estasoft_smoke_failures );
exit( $estasoft_smoke_failures > 0 ? 1 : 0 );

/**
 * Phase install: a fresh WordPress, with the WooCommerce stand-in and a shop manager role, and a guard that records every outbound request.
 *
 * @param string $arg  Unused.
 * @param string $repo Repository root.
 */
function estasoft_smoke_phase_install( string $arg, string $repo ): void {
	unset( $arg, $repo );
	// A canary: debug.log must contain it at the end, or "no line from the plugin" would be true of a log that never works.
	trigger_error( 'estasoft-smoke canary', E_USER_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	wp_install( 'Smoke Test', 'admin', 'admin@smoke.test', true, '', 'password' );
	estasoft_smoke_check( is_blog_installed(), 'WordPress ' . get_bloginfo( 'version' ) . ' installed into a fresh database' );
	add_role( 'shop_manager', 'Shop manager', array( 'read' => true ) );
	estasoft_smoke_check( null !== get_role( 'shop_manager' ), 'the shop_manager role exists (WooCommerce adds it on a real site)' );
	estasoft_smoke_check( is_readable( WP_PLUGIN_DIR . '/' . ESTASOFT_SMOKE_PLUGIN ), 'the unzipped plugin is in plugins/' );
	estasoft_smoke_check( ! is_link( WP_PLUGIN_DIR . '/' . ESTASOFT_SMOKE_SLUG ), 'the plugin folder is a real folder, not a symlink to the repository' );
	estasoft_smoke_check( is_readable( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ), 'the WooCommerce stand-in is in plugins/' );
	update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
	// wp_install() itself asks the new site for its first post to test pretty permalinks; that is WordPress, not the plugin, so forget it.
	$requests = WP_CONTENT_DIR . '/http-requests.log';
	if ( is_file( $requests ) ) {
		unlink( $requests );
	}
	estasoft_smoke_info( 'wp_version=' . get_bloginfo( 'version' ) );
	estasoft_smoke_info( 'php_version=' . PHP_VERSION );
}

/**
 * Phase set-plugins: choose which plugins are active before the next process loads WordPress.
 *
 * @param string $arg  Comma list: woocommerce, estasoft, both (or none).
 * @param string $repo Repository root.
 */
function estasoft_smoke_phase_set_plugins( string $arg, string $repo ): void {
	unset( $repo );
	$map    = array(
		'woocommerce' => 'woocommerce/woocommerce.php',
		'estasoft'    => ESTASOFT_SMOKE_PLUGIN,
	);
	$active = array();
	foreach ( array_filter( explode( ',', $arg ) ) as $name ) {
		if ( isset( $map[ $name ] ) ) {
			$active[] = $map[ $name ];
		}
	}
	update_option( 'active_plugins', $active );
	estasoft_smoke_ok( 'active plugins set to: ' . ( array() === $active ? 'none' : implode( ', ', $active ) ) );
}

/**
 * Phase guard: the requirements guard, run as a function without WordPress.
 *
 * "old-php" declares a minimum PHP that this server cannot meet; "deps" checks the WordPress and WooCommerce answers.
 *
 * @param string $scenario old-php or deps.
 */
function estasoft_smoke_guard( string $scenario ): int {
	$core = rtrim( estasoft_smoke_env( 'SMOKE_CONTENT' ), '/' ) . '/plugins/' . ESTASOFT_SMOKE_SLUG;
	$file = $core . '/includes/requirements.php';

	define( 'ABSPATH', sys_get_temp_dir() . '/estasoft-smoke-abspath/' );
	define( 'ESTASOFT_SYNC_MIN_PHP', 'old-php' === $scenario ? '99.0' : '8.1' );
	define( 'ESTASOFT_SYNC_MIN_WP', '6.4' );
	define( 'ESTASOFT_SYNC_MIN_WC', '8.2' );
	$GLOBALS['estasoft_smoke_actions'] = array();
	// The guard only needs these four WordPress functions; plain stand-ins keep this phase free of WordPress.
	if ( ! function_exists( '__' ) ) {
		/**
		 * Stand-in for the translation function.
		 *
		 * @param string $text Text.
		 */
		function __( $text ) { // phpcs:ignore
			return $text;
		}
	}
	if ( ! function_exists( 'esc_html__' ) ) {
		/**
		 * Stand-in.
		 *
		 * @param string $text Text.
		 */
		function esc_html__( $text ) { // phpcs:ignore
			return htmlspecialchars( $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'esc_html' ) ) {
		/**
		 * Stand-in.
		 *
		 * @param string $text Text.
		 */
		function esc_html( $text ) { // phpcs:ignore
			return htmlspecialchars( (string) $text, ENT_QUOTES );
		}
	}
	if ( ! function_exists( 'get_bloginfo' ) ) {
		/**
		 * Stand-in: the WordPress version comes from the environment so each case can differ.
		 *
		 * @param string $what Field.
		 */
		function get_bloginfo( $what = '' ) { // phpcs:ignore
			unset( $what );
			return (string) getenv( 'SMOKE_FAKE_WP' );
		}
	}
	if ( ! function_exists( 'current_user_can' ) ) {
		/**
		 * Stand-in: an administrator.
		 *
		 * @param string $cap Capability.
		 */
		function current_user_can( $cap ) { // phpcs:ignore
			unset( $cap );
			return true;
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		/**
		 * Stand-in that remembers the callback instead of hooking it.
		 *
		 * @param string   $hook     Hook.
		 * @param callable $callback Callback.
		 */
		function add_action( $hook, $callback ) { // phpcs:ignore
			$GLOBALS['estasoft_smoke_actions'][ $hook ][] = $callback;
		}
	}

	if ( ! is_readable( $file ) ) {
		estasoft_smoke_fail( "the guard file {$file} is not in the unzipped plugin" );
		return 1;
	}
	require_once $file;
	estasoft_smoke_check( function_exists( 'estasoft_sync_missing_requirement' ) && function_exists( 'estasoft_sync_requirement_notice' ), 'the guard functions load from the release zip' );

	if ( 'old-php' === $scenario ) {
		putenv( 'SMOKE_FAKE_WP=6.9' );
		$message = estasoft_sync_missing_requirement( true );
		estasoft_smoke_check( 0 === strpos( $message, 'PHP 99.0 or newer is required.' ), 'a PHP that is too old gives the PHP message, not a fatal error', $message );
		estasoft_sync_requirement_notice( true );
		$callbacks = $GLOBALS['estasoft_smoke_actions']['admin_notices'] ?? array();
		estasoft_smoke_check( 1 === count( $callbacks ), 'the guard adds one admin notice' );
		ob_start();
		foreach ( $callbacks as $callback ) {
			$callback();
		}
		$html = (string) ob_get_clean();
		estasoft_smoke_check( false !== strpos( $html, 'notice-error' ) && false !== strpos( $html, 'PHP 99.0 or newer' ) && false !== strpos( $html, 'is not running' ), 'the notice says the plugin is not running and why' );
		return $GLOBALS['estasoft_smoke_failures'] > 0 ? 1 : 0;
	}

	putenv( 'SMOKE_FAKE_WP=6.3.2' );
	estasoft_smoke_check( 0 === strpos( estasoft_sync_missing_requirement( false ), 'WordPress 6.4 or newer' ), 'WordPress 6.3 gives the WordPress message' );
	putenv( 'SMOKE_FAKE_WP=6.4.3' );
	estasoft_smoke_check( '' === estasoft_sync_missing_requirement( false ), 'PHP, WordPress and sodium pass without WooCommerce when WooCommerce is not asked for' );
	estasoft_smoke_check( 'WooCommerce must be installed and active.' === estasoft_sync_missing_requirement( true ), 'no WooCommerce gives the WooCommerce message' );
	define( 'WC_VERSION', '8.0.0' );
	if ( ! class_exists( 'WooCommerce' ) ) {
		// A bare class so class_exists() answers yes.
		class WooCommerce {} // phpcs:ignore
	}
	estasoft_smoke_check( 0 === strpos( estasoft_sync_missing_requirement( true ), 'WooCommerce 8.2 or newer' ), 'WooCommerce 8.0 gives the version message' );
	return $GLOBALS['estasoft_smoke_failures'] > 0 ? 1 : 0;
}

/**
 * Phase no-woo: the plugin is active and WooCommerce is not. A notice must appear and the plugin must not start.
 *
 * @param string $arg  Unused.
 * @param string $repo Repository root.
 */
function estasoft_smoke_phase_no_woo( string $arg, string $repo ): void {
	unset( $arg, $repo );
	estasoft_smoke_check( ! class_exists( 'WooCommerce' ), 'WooCommerce is not loaded in this request' );
	estasoft_smoke_check( defined( 'ESTASOFT_SYNC_VERSION' ), 'the plugin file loaded without a fatal error' );
	$admin = get_user_by( 'login', 'admin' );
	wp_set_current_user( $admin ? (int) $admin->ID : 1 );
	ob_start();
	do_action( 'admin_notices' );
	$html = (string) ob_get_clean();
	estasoft_smoke_check( false !== strpos( $html, 'is not running' ) && false !== strpos( $html, 'WooCommerce must be installed and active.' ), 'an administrator sees "is not running" and "WooCommerce must be installed and active."' );
	$routes = array_filter(
		array_keys( rest_get_server()->get_routes() ),
		static fn( $route ): bool => 0 === strpos( (string) $route, '/' . ESTASOFT_SMOKE_NAMESPACE )
	);
	estasoft_smoke_check( array() === $routes, 'the plugin did not boot: no REST route is registered without WooCommerce' );
	wp_set_current_user( 0 );
	ob_start();
	do_action( 'admin_notices' );
	$anonymous = (string) ob_get_clean();
	estasoft_smoke_check( '' === $anonymous, 'a visitor without activate_plugins sees nothing' );
}

/**
 * Phase activate: activation with WooCommerce active, as WordPress does it, and what it must leave behind.
 *
 * @param string $arg  Unused.
 * @param string $repo Repository root.
 */
function estasoft_smoke_phase_activate( string $arg, string $repo ): void {
	unset( $arg, $repo );
	global $wpdb;
	estasoft_smoke_check( class_exists( 'WooCommerce' ), 'WooCommerce (the stand-in) is loaded' );
	wp_set_current_user( 1 );
	$result = activate_plugin( ESTASOFT_SMOKE_PLUGIN );
	estasoft_smoke_check( ! is_wp_error( $result ), 'activate_plugin() returns without an error or unexpected output', is_wp_error( $result ) ? $result->get_error_message() : '' );
	estasoft_smoke_check( is_plugin_active( ESTASOFT_SMOKE_PLUGIN ), 'the plugin is active' );

	$tables = estasoft_smoke_tables();
	estasoft_smoke_check( 9 === count( $tables ), 'nine tables were created', count( $tables ) . ': ' . implode( ', ', $tables ) );
	foreach ( array( 'connections', 'queue', 'logs', 'mappings', 'id_map', 'state', 'idempotency', 'recon_runs', 'recon_diffs' ) as $name ) {
		estasoft_smoke_check( in_array( $wpdb->prefix . 'estasoft_sync_' . $name, $tables, true ), "table {$name} exists" );
	}

	$administrator = get_role( 'administrator' );
	$shop_manager  = get_role( 'shop_manager' );
	estasoft_smoke_check( null !== $administrator && $administrator->has_cap( 'estasoft_sync_manage' ) && $administrator->has_cap( 'estasoft_sync_configure' ), 'administrators got estasoft_sync_manage and estasoft_sync_configure' );
	estasoft_smoke_check( null !== $shop_manager && $shop_manager->has_cap( 'estasoft_sync_manage' ) && ! $shop_manager->has_cap( 'estasoft_sync_configure' ), 'shop managers got estasoft_sync_manage only' );
	foreach ( array( 'subscriber', 'editor', 'author' ) as $role_name ) {
		$role = get_role( $role_name );
		estasoft_smoke_check( null !== $role && ! $role->has_cap( 'estasoft_sync_manage' ) && ! $role->has_cap( 'estasoft_sync_configure' ), "the {$role_name} role got neither capability" );
	}

	$options = estasoft_smoke_options();
	estasoft_smoke_check( in_array( 'estasoft_sync_settings', $options, true ), 'the settings option was stored' );
	$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'estasoft_sync_settings' ) ); // phpcs:ignore
	estasoft_smoke_check( 'no' === $autoload || 'off' === $autoload, 'the settings option is not autoloaded', $autoload );

	$cron  = _get_cron_array();
	$found = array();
	foreach ( (array) $cron as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( 0 === strpos( (string) $hook, 'estasoft_sync_' ) ) {
				$found[ (string) $hook ] = true;
			}
		}
	}
	estasoft_smoke_check( array() !== $found, 'background tasks were scheduled (WP-Cron, because the Action Scheduler of WooCommerce is not in the stand-in)', 'found ' . count( $found ) );
	estasoft_smoke_info( 'scheduled_tasks=' . implode( ',', array_keys( $found ) ) );
}

/**
 * Phase runtime: the REST namespace, the admin page and its script, the catalog and every connector class.
 *
 * @param string $arg  Unused.
 * @param string $repo Repository root (for docs/rest-api.md).
 */
function estasoft_smoke_phase_runtime( string $arg, string $repo ): void {
	unset( $arg );
	estasoft_smoke_check( is_plugin_active( ESTASOFT_SMOKE_PLUGIN ), 'the plugin is still active in a new request' );
	estasoft_smoke_check( class_exists( 'Estasoft\SyncEngine\Plugin' ), 'the plugin class autoloads' );

	// REST: every documented route is registered, and an anonymous request is refused.
	$server      = rest_get_server();
	$registered  = array();
	$raw_routes  = array();
	foreach ( $server->get_routes( ESTASOFT_SMOKE_NAMESPACE ) as $route => $handlers ) {
		foreach ( $handlers as $handler ) {
			foreach ( array_keys( (array) ( $handler['methods'] ?? array() ) ) as $method ) {
				$registered[ $method . ' ' . estasoft_smoke_route_shape( (string) $route ) ] = $method . ' ' . $route;
				$raw_routes[]                                                                = array( $method, (string) $route );
			}
		}
	}
	estasoft_smoke_check( array() !== $registered, 'the REST namespace ' . ESTASOFT_SMOKE_NAMESPACE . ' is registered', count( $registered ) . ' routes' );

	// Every route has its own permission callback, and only the signed inbound route may accept everyone (it checks an HMAC instead).
	$open     = array();
	$changing = 0;
	foreach ( $server->get_routes( ESTASOFT_SMOKE_NAMESPACE ) as $route => $handlers ) {
		foreach ( $handlers as $handler ) {
			$methods = array_keys( (array) ( $handler['methods'] ?? array() ) );
			if ( '/' . ESTASOFT_SMOKE_NAMESPACE === $route ) {
				continue;
			}
			$changing += count( array_intersect( $methods, array( 'POST', 'PUT', 'PATCH', 'DELETE' ) ) );
			$callback  = $handler['permission_callback'] ?? null;
			if ( null === $callback || '__return_true' === $callback ) {
				$open[] = implode( ',', $methods ) . ' ' . $route;
			}
		}
	}
	$unexpected_open = array_filter( $open, static fn( string $line ): bool => false === strpos( $line, '/inbound/' ) );
	estasoft_smoke_check( array() === $unexpected_open, 'no route is open to everyone except the signed inbound route (' . count( $open ) . ' open: ' . implode( '; ', $open ) . ')', implode( '; ', $unexpected_open ) );
	estasoft_smoke_info( 'state_changing_route_handlers=' . $changing );
	$namespaces = $server->get_namespaces();
	estasoft_smoke_check( in_array( ESTASOFT_SMOKE_NAMESPACE, $namespaces, true ), 'the namespace is listed by the REST index' );

	$documented = estasoft_smoke_documented_routes( $repo );
	estasoft_smoke_check( count( $documented ) >= 60, 'docs/rest-api.md lists its routes', count( $documented ) . ' routes read' );
	$missing = array();
	foreach ( $documented as $shape => $text ) {
		if ( ! isset( $registered[ $shape ] ) ) {
			$missing[] = $text;
		}
	}
	estasoft_smoke_check( array() === $missing, 'every route in docs/rest-api.md is registered', implode( '; ', $missing ) );
	$undocumented = array();
	foreach ( $registered as $shape => $text ) {
		if ( ! isset( $documented[ $shape ] ) ) {
			$undocumented[] = $text;
		}
	}
	if ( array() !== $undocumented ) {
		echo 'warn registered but not in docs/rest-api.md: ' . implode( '; ', $undocumented ) . "\n";
	}

	wp_set_current_user( 0 );
	$refused  = 0;
	$bad      = array();
	foreach ( $raw_routes as $pair ) {
		list( $method, $route ) = $pair;
		if ( '/' . ESTASOFT_SMOKE_NAMESPACE === $route ) {
			continue; // The namespace index that WordPress adds itself; it lists routes and takes no input.
		}
		$request = new WP_REST_Request( $method, estasoft_smoke_route_url( $route ) );
		$request->set_param( 'state', 'x' );
		$request->set_body( '' ); // What WP_REST_Server::serve_request() does for a real request: the raw body, empty here.
		$response = rest_do_request( $request );
		$status   = $response->get_status();
		$signed   = 'POST' === $method && 0 === strpos( $route, '/' . ESTASOFT_SMOKE_NAMESPACE . '/inbound/' );
		if ( 401 === $status || 403 === $status || ( $signed && $status >= 400 && $status < 500 ) ) {
			++$refused;
			continue;
		}
		$data  = (array) $response->get_data();
		$bad[] = $method . ' ' . $route . ' -> ' . $status . ' ' . (string) ( $data['code'] ?? '' );
		if ( $status >= 500 ) {
			global $wpdb;
			$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}estasoft_sync_logs ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore
			estasoft_smoke_info( 'last log row after the 500: ' . wp_json_encode( $row ) );
		}
	}
	estasoft_smoke_check( array() === $bad, 'an anonymous request to each of ' . ( count( $raw_routes ) - 1 ) . ' routes is refused (401 or 403; a 4xx for the signed inbound route)', implode( '; ', $bad ) );

	// REST as an administrator: the catalog lists every system and every connector.
	wp_set_current_user( 1 );
	$catalog_response = rest_do_request( new WP_REST_Request( 'GET', '/' . ESTASOFT_SMOKE_NAMESPACE . '/catalog' ) );
	estasoft_smoke_check( 200 === $catalog_response->get_status(), 'GET /catalog answers 200 for an administrator', (string) $catalog_response->get_status() );
	$catalog = (array) $catalog_response->get_data();
	estasoft_smoke_check( 34 === count( $catalog ), 'the catalog route lists all 34 systems', count( $catalog ) . ' listed' );
	$listed = array();
	foreach ( $catalog as $entry ) {
		$entry = (array) $entry;
		if ( ! empty( $entry['connector'] ) ) {
			$listed[] = (string) $entry['connector'];
		}
		foreach ( (array) ( $entry['variants'] ?? array() ) as $variant ) {
			$listed[] = (string) ( (array) $variant )['id'];
		}
	}
	$providers = require ESTASOFT_SYNC_DIR . 'includes/Connectors/providers.php';
	$ids       = array_keys( $providers );
	$unlisted  = array_diff( $ids, $listed );
	estasoft_smoke_check( array() === $unlisted, 'every connector in providers.php appears in the catalog', implode( ', ', $unlisted ) );
	$unavailable = array();
	foreach ( $catalog as $entry ) {
		if ( empty( ( (array) $entry )['available'] ) ) {
			$unavailable[] = (string) ( (array) $entry )['name'];
		}
	}
	estasoft_smoke_info( 'systems_not_available=' . implode( ',', $unavailable ) );

	foreach ( array( '/status', '/settings', '/readiness', '/onboarding' ) as $path ) {
		$status = rest_do_request( new WP_REST_Request( 'GET', '/' . ESTASOFT_SMOKE_NAMESPACE . $path ) )->get_status();
		estasoft_smoke_check( 200 === $status, "GET {$path} answers 200 for an administrator on a new site", (string) $status );
	}

	// A shop manager may manage but not configure.
	$manager_id = wp_create_user( 'smokemanager', 'password', 'manager@smoke.test' );
	$manager    = get_user_by( 'id', (int) $manager_id );
	if ( $manager instanceof WP_User ) {
		$manager->set_role( 'shop_manager' );
		wp_set_current_user( (int) $manager->ID );
		$read  = rest_do_request( new WP_REST_Request( 'GET', '/' . ESTASOFT_SMOKE_NAMESPACE . '/status' ) )->get_status();
		$write = rest_do_request( new WP_REST_Request( 'PUT', '/' . ESTASOFT_SMOKE_NAMESPACE . '/settings' ) )->get_status();
		estasoft_smoke_check( 200 === $read && 403 === $write, 'a shop manager can read /status (200) and is refused on PUT /settings (403)', "{$read} / {$write}" );
		wp_set_current_user( 1 );
	}

	// The provider index loads every connector class, each one through the registry.
	$registry = Estasoft\SyncEngine\Plugin::instance()->container()->get( Estasoft\SyncEngine\Core\Services::CONNECTOR_REGISTRY );
	estasoft_smoke_check( count( $ids ) === count( $registry->ids() ), 'the registry knows every provider', count( $registry->ids() ) . ' of ' . count( $ids ) );
	$built  = 0;
	$errors = array();
	foreach ( $ids as $id ) {
		try {
			$connector = $registry->get( (string) $id );
			if ( null === $connector || $connector->id() !== $id || '' === $connector->label() ) {
				$errors[] = $id . ': not built or wrong id';
				continue;
			}
			++$built;
		} catch ( Throwable $error ) {
			$errors[] = $id . ': ' . get_class( $error );
		}
	}
	estasoft_smoke_check( array() === $errors && count( $ids ) === $built, 'every connector class loads and is built through the registry', $built . ' of ' . count( $ids ) . ( array() !== $errors ? '; ' . implode( '; ', $errors ) : '' ) );
	estasoft_smoke_check( array() === $registry->rejected(), 'the registry rejected no connector', implode( ', ', array_keys( $registry->rejected() ) ) );
	$missing_classes = array();
	foreach ( $providers as $id => $class ) {
		if ( ! class_exists( (string) $class ) ) {
			$missing_classes[] = (string) $id;
		}
	}
	estasoft_smoke_check( array() === $missing_classes, 'every provider class in providers.php exists', implode( ', ', $missing_classes ) );

	// The admin page renders and enqueues the compiled app with its dependency list.
	$_GET['page'] = 'estasoft-sync';
	do_action( 'admin_menu' );
	$menu = Estasoft\SyncEngine\Plugin::instance()->container()->get( Estasoft\SyncEngine\Admin\AdminModule::MENU );
	$hook = $menu->hookSuffix();
	estasoft_smoke_check( '' !== $hook, 'the ESTASOFT SYNC menu page is registered', $hook );
	ob_start();
	do_action( $hook );
	$page = (string) ob_get_clean();
	estasoft_smoke_check( false !== strpos( $page, 'id="estasoft-sync-root"' ) || false !== strpos( $page, 'class="wrap estasoft-sync"' ), 'the admin page prints its mount point' );
	estasoft_smoke_check( false === strpos( $page, 'has not been built' ), 'the page does not say the app is missing (the zip carries build/)' );
	// WordPress core hooks (the session check) ask for the current screen on admin_enqueue_scripts.
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( $hook );
	do_action( 'admin_enqueue_scripts', $hook );
	$handle  = Estasoft\SyncEngine\Admin\Assets::HANDLE;
	$scripts = wp_scripts();
	estasoft_smoke_check( wp_script_is( $handle, 'enqueued' ), 'the admin app script is enqueued on its page' );
	$registered_script = $scripts->registered[ $handle ] ?? null;
	estasoft_smoke_check( null !== $registered_script && false !== strpos( (string) $registered_script->src, '/build/index.js' ), 'the script is build/index.js from the plugin folder', null !== $registered_script ? (string) $registered_script->src : 'not registered' );
	$asset = include ESTASOFT_SYNC_DIR . 'build/index.asset.php';
	if ( null !== $registered_script && is_array( $asset ) ) {
		estasoft_smoke_check( (array) $registered_script->deps === (array) $asset['dependencies'], 'its dependency list is the one in build/index.asset.php', implode( ',', (array) $registered_script->deps ) );
		estasoft_smoke_check( (string) $registered_script->ver === (string) $asset['version'], 'its version is the build hash from build/index.asset.php' );
		$unknown = array();
		foreach ( (array) $registered_script->deps as $dependency ) {
			if ( ! isset( $scripts->registered[ $dependency ] ) ) {
				$unknown[] = (string) $dependency;
			}
		}
		estasoft_smoke_check( array() === $unknown, 'every dependency handle exists in WordPress ' . get_bloginfo( 'version' ), implode( ', ', $unknown ) );
		$external = false;
		foreach ( $scripts->registered as $script ) {
			if ( in_array( $script->handle, array_merge( array( $handle ), (array) $registered_script->deps ), true ) && is_string( $script->src ) && 1 === preg_match( '#^(https?:)?//(?!smoke\.test)#', $script->src ) ) {
				$external = true;
			}
		}
		estasoft_smoke_check( ! $external, 'the script and its dependencies are all served by this site (no CDN)' );
	}
	$before = $scripts->get_data( $handle, 'before' );
	$inline = is_array( $before ) ? implode( "\n", array_map( 'strval', $before ) ) : '';
	estasoft_smoke_check( false !== strpos( $inline, 'window.estasoftSync = ' ), 'the boot data is handed over as window.estasoftSync', 'before: ' . substr( $inline, 0, 80 ) );
	estasoft_smoke_check( false === strpos( $inline, 'ESTASOFT_SYNC_KEY' ) && false === strpos( $inline, 'smoke-test-key' ), 'the boot data holds no encryption key' );
	$styles = wp_styles();
	estasoft_smoke_check( isset( $styles->registered[ $handle ] ) && false !== strpos( (string) $styles->registered[ $handle ]->src, '/build/index.css' ), 'the stylesheet is build/index.css' );
}

/**
 * Phase seed: put data where the uninstall tests can look for it.
 *
 * @param string $arg  Unused.
 * @param string $repo Repository root.
 */
function estasoft_smoke_phase_seed( string $arg, string $repo ): void {
	unset( $arg, $repo );
	wp_set_current_user( 1 );
	add_option( 'smoke_unrelated_option', 'keep me' );
	foreach ( Estasoft\SyncEngine\Core\MetaKeys::user() as $key ) {
		update_user_meta( 1, $key, 'seed' );
	}
	set_transient( 'estasoft_sync_smoke_transient', 'seed', HOUR_IN_SECONDS );
	$uploads = wp_upload_dir( null, false );
	$dir     = $uploads['basedir'] . '/estasoft-sync/exports/abc123/files';
	wp_mkdir_p( $dir );
	file_put_contents( $dir . '/seed.csv', "a;b\n1;2\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a seed file for a test.
	estasoft_smoke_check( is_file( $dir . '/seed.csv' ), 'seeded an export file, user meta, a transient and an unrelated option' );
	estasoft_smoke_info( 'tables_before=' . count( estasoft_smoke_tables() ) . ' options_before=' . implode( ',', estasoft_smoke_options() ) );
}

/**
 * Phase deactivate: data stays.
 *
 * @param string $arg  Unused.
 * @param string $repo Repository root.
 */
function estasoft_smoke_phase_deactivate( string $arg, string $repo ): void {
	unset( $arg, $repo );
	wp_set_current_user( 1 );
	$tables_before  = count( estasoft_smoke_tables() );
	$options_before = estasoft_smoke_options();
	deactivate_plugins( ESTASOFT_SMOKE_PLUGIN );
	estasoft_smoke_check( ! is_plugin_active( ESTASOFT_SMOKE_PLUGIN ), 'the plugin is deactivated' );
	estasoft_smoke_check( $tables_before === count( estasoft_smoke_tables() ) && 9 === $tables_before, 'deactivation keeps the nine tables' );
	// Transients are a cache: deactivation clears the "tasks are scheduled" flag on purpose.
	$options_lost = array_filter(
		array_diff( $options_before, estasoft_smoke_options() ),
		static fn( string $name ): bool => 0 !== strpos( $name, '_transient_' ) && 0 !== strpos( $name, '_site_transient_' )
	);
	estasoft_smoke_check( array() === $options_lost, 'deactivation keeps the options', implode( ', ', $options_lost ) );
	$administrator = get_role( 'administrator' );
	estasoft_smoke_check( null !== $administrator && $administrator->has_cap( 'estasoft_sync_manage' ), 'deactivation keeps the capabilities' );
	$left = 0;
	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( 0 === strpos( (string) $hook, 'estasoft_sync_' ) ) {
				++$left;
			}
		}
	}
	estasoft_smoke_check( 0 === $left, 'deactivation unscheduled the background tasks', (string) $left );
	$uploads = wp_upload_dir( null, false );
	estasoft_smoke_check( is_file( $uploads['basedir'] . '/estasoft-sync/exports/abc123/files/seed.csv' ), 'deactivation keeps the exports folder' );
}

/**
 * Phase uninstall-keep: uninstall with "Delete all data" off keeps everything.
 *
 * @param string $arg  Unused.
 * @param string $repo Repository root.
 */
function estasoft_smoke_phase_uninstall_keep( string $arg, string $repo ): void {
	unset( $arg, $repo );
	wp_set_current_user( 1 );
	$settings = (array) get_option( 'estasoft_sync_settings', array() );
	estasoft_smoke_check( empty( $settings['delete_on_uninstall'] ), 'delete_on_uninstall is off by default' );
	uninstall_plugin( ESTASOFT_SMOKE_PLUGIN );
	estasoft_smoke_check( 9 === count( estasoft_smoke_tables() ), 'uninstall with delete_on_uninstall off keeps the nine tables' );
	estasoft_smoke_check( in_array( 'estasoft_sync_settings', estasoft_smoke_options(), true ), 'it keeps the options' );
	$uploads = wp_upload_dir( null, false );
	estasoft_smoke_check( is_file( $uploads['basedir'] . '/estasoft-sync/exports/abc123/files/seed.csv' ), 'it keeps the exports folder' );
	$kept = true;
	foreach ( Estasoft\SyncEngine\Core\MetaKeys::user() as $key ) {
		$kept = $kept && '' !== (string) get_user_meta( 1, $key, true );
	}
	estasoft_smoke_check( $kept, 'it keeps the user meta' );
	$administrator = get_role( 'administrator' );
	estasoft_smoke_check( null !== $administrator && $administrator->has_cap( 'estasoft_sync_configure' ), 'it keeps the capabilities' );
}

/**
 * Phase uninstall-delete: with the setting on, uninstall removes the plugin's tables, options, meta and exports folder, and nothing else.
 *
 * @param string $arg  Unused.
 * @param string $repo Repository root.
 */
function estasoft_smoke_phase_uninstall_delete( string $arg, string $repo ): void {
	unset( $arg, $repo );
	wp_set_current_user( 1 );
	$settings                        = (array) get_option( 'estasoft_sync_settings', array() );
	$settings['delete_on_uninstall'] = true;
	update_option( 'estasoft_sync_settings', $settings );
	uninstall_plugin( ESTASOFT_SMOKE_PLUGIN );
	global $wpdb;
	estasoft_smoke_check( array() === estasoft_smoke_tables(), 'uninstall with delete_on_uninstall on drops all nine tables', implode( ', ', estasoft_smoke_tables() ) );
	estasoft_smoke_check( array() === estasoft_smoke_options(), 'it removes every option and transient of the plugin', implode( ', ', estasoft_smoke_options() ) );
	$uploads = wp_upload_dir( null, false );
	estasoft_smoke_check( ! is_dir( $uploads['basedir'] . '/estasoft-sync' ), 'it removes the exports folder' );
	$left = 0;
	foreach ( Estasoft\SyncEngine\Core\MetaKeys::user() as $key ) {
		$left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $key ) ); // phpcs:ignore
	}
	estasoft_smoke_check( 0 === $left, 'it removes the user meta', (string) $left );
	$administrator = get_role( 'administrator' );
	estasoft_smoke_check( null !== $administrator && ! $administrator->has_cap( 'estasoft_sync_manage' ) && ! $administrator->has_cap( 'estasoft_sync_configure' ), 'it removes the capabilities from every role' );
	estasoft_smoke_check( 'keep me' === get_option( 'smoke_unrelated_option' ), 'it leaves an unrelated option alone' );
	estasoft_smoke_check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = 'admin'" ), 'it leaves the users alone' ); // phpcs:ignore
}

/**
 * Phase log: nothing the plugin did reached debug.log, and nothing tried to leave the machine.
 *
 * @param string $arg  Unused.
 * @param string $repo Repository root.
 */
function estasoft_smoke_phase_log( string $arg, string $repo ): void {
	unset( $arg, $repo );
	$log   = WP_CONTENT_DIR . '/debug.log';
	$lines = is_readable( $log ) ? array_values( array_filter( array_map( 'trim', (array) file( $log ) ) ) ) : array();
	$canary = array_values( array_filter( $lines, static fn( string $line ): bool => false !== strpos( $line, 'estasoft-smoke canary' ) ) );
	estasoft_smoke_check( 1 === count( $canary ), 'debug.log works (the canary written at install is in it)', count( $canary ) . ' canary lines' );
	$lines = array_values( array_diff( $lines, $canary ) );
	$ours  = array_values( array_filter( $lines, static fn( string $line ): bool => false !== strpos( $line, ESTASOFT_SMOKE_SLUG ) || false !== stripos( $line, 'estasoft' ) ) );
	$fatal = array_values( array_filter( $lines, static fn( string $line ): bool => 1 === preg_match( '/PHP (Fatal|Parse)/i', $line ) ) );
	estasoft_smoke_check( array() === $ours, 'debug.log has no line that mentions the plugin (no notice, warning or deprecation from its code)', implode( ' | ', array_slice( $ours, 0, 3 ) ) );
	estasoft_smoke_check( array() === $fatal, 'debug.log has no fatal error', implode( ' | ', array_slice( $fatal, 0, 3 ) ) );
	estasoft_smoke_info( 'debug_log_lines_total=' . count( $lines ) . ' debug_log_lines_from_wordpress_core=' . ( count( $lines ) - count( $ours ) ) );
	foreach ( array_slice( array_diff( $lines, $ours ), 0, 5 ) as $line ) {
		estasoft_smoke_info( 'core_log: ' . substr( $line, 0, 200 ) );
	}
	$http  = WP_CONTENT_DIR . '/http-requests.log';
	$calls = is_readable( $http ) ? array_values( array_filter( array_map( 'trim', (array) file( $http ) ) ) ) : array();
	estasoft_smoke_check( array() === $calls, 'the plugin made no outbound HTTP request in any phase (nothing is contacted without a connection)', implode( ' | ', array_slice( $calls, 0, 3 ) ) );
}
