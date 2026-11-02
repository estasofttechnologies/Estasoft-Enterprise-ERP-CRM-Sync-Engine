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
require_once dirname( __DIR__, 3 ) . '/includes/Autoloader.php';

Estasoft\SyncEngine\Autoloader::register( 'Estasoft\\SyncEngine\\', dirname( __DIR__, 3 ) . '/includes/' );
