<?php
/**
 * WordPress hook functions for ConnectorsModule tests, declared in the module's own namespace so they do not leak into other tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors;

if ( ! function_exists( __NAMESPACE__ . '\\add_action' ) ) {
	/**
	 * Records the action in $GLOBALS['estasoft_test_actions'].
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	function add_action( $hook, $callback, $priority = 10 ) { // phpcs:ignore
		$GLOBALS['estasoft_test_actions'][ $hook ][ $priority ][] = $callback;
		return true;
	}
}
