<?php
/**
 * A stand-in for the WP_CLI class and format_items(), so the commands run in the test process.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\format_items' ) ) {
		/**
		 * Record the rows.
		 *
		 * @param string $format Format.
		 * @param array  $items  Rows.
		 * @param array  $fields Columns.
		 */
		function format_items( $format, $items, $fields ) {
			\WP_CLI::$printed[] = array( 'items', $format, $items, $fields );
		}
	}
}

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI { // phpcs:ignore
			public static $lines   = array();
			public static $printed = array();
			public static $commands = array();

			public static function reset() {
				self::$lines    = array();
				self::$printed  = array();
				self::$commands = array();
			}

			public static function log( $message ) {
				self::$lines[] = array( 'log', $message );
			}

			public static function success( $message ) {
				self::$lines[] = array( 'success', $message );
			}

			public static function warning( $message ) {
				self::$lines[] = array( 'warning', $message );
			}

			public static function error( $message, $exit = 1 ) {
				self::$lines[] = array( 'error', $message );
				throw new \RuntimeException( (string) $message, (int) $exit );
			}

			public static function print_value( $value, $assoc_args = array() ) {
				self::$printed[] = array( 'value', $assoc_args['format'] ?? '', $value );
			}

			public static function add_command( $name, $callable ) {
				self::$commands[ $name ] = $callable;
			}
		}
	}
}
