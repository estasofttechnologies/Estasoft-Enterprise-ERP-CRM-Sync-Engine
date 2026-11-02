<?php
/**
 * WordPress functions the shared-base tests need in addition to wp-stubs.php. Guarded, so a real WordPress or another stub file wins.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { // phpcs:ignore
		$GLOBALS['estasoft_test_filters'][ $hook ][ $priority ][] = $callback;
		return true;
	}
}
