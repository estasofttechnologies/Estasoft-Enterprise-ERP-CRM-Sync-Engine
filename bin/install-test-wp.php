<?php
/**
 * Installs WordPress into the test database and activates the WooCommerce stub and this plugin.
 * Safe to run again: it drops and recreates the test database first.
 *
 *   php bin/install-test-wp.php
 *
 * @package Estasoft\SyncEngine
 */

$core    = rtrim( getenv( 'WP_CORE_DIR' ) ?: '/opt/devenv/wp-deb/usr/share/wordpress', '/' ) . '/';
$content = rtrim( getenv( 'WP_TEST_CONTENT' ) ?: '/opt/devenv/wp-content', '/' );
$root    = dirname( __DIR__ );

$dbName = getenv( 'WP_DB_NAME' ) ?: 'estasoft_test';
$dbUser = getenv( 'WP_DB_USER' ) ?: 'root';
$dbPass = getenv( 'WP_DB_PASSWORD' ) ?: '';
$dbHost = getenv( 'WP_DB_HOST' ) ?: 'localhost';

$link = new mysqli( $dbHost, $dbUser, $dbPass );
if ( $link->connect_errno ) {
	fwrite( STDERR, 'Cannot connect to the database: ' . $link->connect_error . "\n" );
	exit( 1 );
}
$link->query( "DROP DATABASE IF EXISTS `{$dbName}`" );
$link->query( "CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci" );
$link->close();

// A themes folder, so WordPress 6.8 and later find a registered theme directory (without one it logs a notice for every call).
foreach ( array( '', '/plugins', '/mu-plugins', '/themes', '/uploads', '/languages' ) as $dir ) {
	if ( ! is_dir( $content . $dir ) ) {
		mkdir( $content . $dir, 0775, true );
	}
}
$links = array(
	$content . '/plugins/estasoft-erp-crm-sync-engine' => $root,
	$content . '/plugins/woocommerce'                   => $root . '/tests/php/stubs/woocommerce',
);
foreach ( $links as $link_path => $target ) {
	if ( is_link( $link_path ) ) {
		unlink( $link_path );
	}
	symlink( $target, $link_path );
}

define( 'WP_INSTALLING', true );
define( 'ABSPATH', $core );
define( 'WP_CONTENT_DIR', $content );
define( 'WP_PLUGIN_DIR', $content . '/plugins' );
define( 'DB_NAME', $dbName );
define( 'DB_USER', $dbUser );
define( 'DB_PASSWORD', $dbPass );
define( 'DB_HOST', $dbHost );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_HOME', 'http://example.test' );
define( 'WP_SITEURL', 'http://example.test' );
define( 'ESTASOFT_SYNC_KEY', str_repeat( 'k', 32 ) );
$table_prefix = 'wptests_'; // phpcs:ignore
$_SERVER['HTTP_HOST']      = 'example.test';
$_SERVER['SERVER_NAME']    = 'example.test';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';

require_once ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

wp_install( 'Estasoft Tests', 'admin', 'admin@example.test', true, '', 'password' );
update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
$result = activate_plugin( 'estasoft-erp-crm-sync-engine/estasoft-erp-crm-sync-engine.php' );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'Activation failed: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}
echo "WordPress installed and plugin activated in {$dbName}.\n";
