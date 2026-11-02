<?php
/**
 * Plugin Name: WooCommerce (test stub)
 * Description: Minimal stand-in so the integration tests can run without the real WooCommerce.
 * Version: 9.4.0
 *
 * This stub only defines what the plugin's requirements guard and boot path need. Tests that need
 * orders build them with the helpers in tests/php/Support (added by the tests that need them).
 *
 * @package Estasoft\SyncEngine\Tests
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_VERSION', '9.4.0' );

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce { // phpcs:ignore
		public $version = WC_VERSION;
	}
}
