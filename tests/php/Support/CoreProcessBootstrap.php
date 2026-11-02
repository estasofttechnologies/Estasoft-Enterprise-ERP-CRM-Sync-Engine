<?php
/**
 * Loads WordPress (without plugins and themes) and the plugin's autoloader for the helper processes of the Core tests.
 * Not a test. Needs the same WP_* environment variables as the integration bootstrap.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

$core    = rtrim( getenv( 'WP_CORE_DIR' ) ?: '/opt/devenv/wp-deb/usr/share/wordpress', '/' ) . '/';
$content = rtrim( getenv( 'WP_TEST_CONTENT' ) ?: '/opt/devenv/wp-content', '/' );

define( 'SHORTINIT', true );
define( 'ABSPATH', $core );
define( 'WP_CONTENT_DIR', $content );
define( 'WP_PLUGIN_DIR', $content . '/plugins' );
define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ?: 'estasoft_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wptests_'; // phpcs:ignore

$_SERVER['HTTP_HOST']      = 'example.test';
$_SERVER['SERVER_NAME']    = 'example.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';

require_once ABSPATH . 'wp-settings.php';
if ( ! function_exists( '__' ) ) {
	// SHORTINIT stops before the translation functions are loaded.
	require_once ABSPATH . WPINC . '/l10n.php';
}
if ( empty( $GLOBALS['wp_textdomain_registry'] ) ) {
	require_once ABSPATH . WPINC . '/class-wp-textdomain-registry.php';
	$GLOBALS['wp_textdomain_registry'] = new WP_Textdomain_Registry(); // phpcs:ignore
}
require_once dirname( __DIR__, 3 ) . '/includes/Autoloader.php';

Estasoft\SyncEngine\Autoloader::register( 'Estasoft\\SyncEngine\\', dirname( __DIR__, 3 ) . '/includes/' );

/**
 * Wait for the moment all the helper processes of a test start together.
 *
 * Given a number it waits until that Unix time (the older way). Given a directory it says it is ready (a file `ready-<pid>`)
 * and waits until the test creates `go` there: the test lets everyone go only when every process has finished loading
 * WordPress, so a process that is slow to start (a busy machine) cannot miss the race.
 *
 * @param string $how Unix time with micro seconds, or the path of a barrier directory.
 */
function core_wait_for_start( string $how ): void {
	if ( is_numeric( $how ) ) {
		while ( microtime( true ) < (float) $how ) {
			usleep( 200 );
		}
		return;
	}
	touch( $how . '/ready-' . getmypid() );
	$give_up = microtime( true ) + 120;
	while ( ! file_exists( $how . '/go' ) && microtime( true ) < $give_up ) {
		usleep( 200 );
	}
}
