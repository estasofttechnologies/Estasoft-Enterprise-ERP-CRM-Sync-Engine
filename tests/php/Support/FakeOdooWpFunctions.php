<?php
/**
 * WordPress functions the Odoo connector tests need, in addition to wp-stubs.php. Guarded, so a real WordPress wins.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore
		return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore
		$GLOBALS['estasoft_test_filters'][ $hook ][ $priority ][] = $callback;
		return true;
	}
}
