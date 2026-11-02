<?php
/**
 * Integration test bootstrap: loads a real WordPress against a real MySQL or MariaDB database.
 *
 * Environment (defaults suit the Estasoft dev container; CI sets its own):
 *   WP_CORE_DIR     Directory containing wp-settings.php.
 *   WP_TEST_CONTENT Writable wp-content directory for the test site.
 *   WP_DB_NAME, WP_DB_USER, WP_DB_PASSWORD, WP_DB_HOST.
 *
 * Run `php bin/install-test-wp.php` once first to install WordPress and activate the plugin.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

$core    = rtrim( getenv( 'WP_CORE_DIR' ) ?: '/opt/devenv/wp-deb/usr/share/wordpress', '/' ) . '/';
$content = rtrim( getenv( 'WP_TEST_CONTENT' ) ?: '/opt/devenv/wp-content', '/' );

define( 'ABSPATH', $core );
define( 'WP_CONTENT_DIR', $content );
define( 'WP_PLUGIN_DIR', $content . '/plugins' );
define( 'WPMU_PLUGIN_DIR', $content . '/mu-plugins' );
define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ?: 'estasoft_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', 'http://example.test' );
define( 'WP_SITEURL', 'http://example.test' );
define( 'ESTASOFT_SYNC_KEY', str_repeat( 'k', 32 ) );
define( 'DISABLE_WP_CRON', true );

$table_prefix = 'wptests_'; // phpcs:ignore

$_SERVER['HTTP_HOST']       = 'example.test';
$_SERVER['SERVER_NAME']     = 'example.test';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = '/';

require_once ABSPATH . 'wp-settings.php';
require_once __DIR__ . '/Support/IntegrationTestCase.php';

// No test needs DNS: every host name resolves to a public address.
if ( class_exists( \Estasoft\SyncEngine\Connectors\Support\HostPolicy::class ) ) {
	\Estasoft\SyncEngine\Connectors\Support\HostPolicy::resolveWith( static fn( string $host ): array => array( '93.184.216.34' ) );
}
