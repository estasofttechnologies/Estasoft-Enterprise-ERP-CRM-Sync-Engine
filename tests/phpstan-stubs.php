<?php
/**
 * Declarations of code that other plugins and tools provide, for static analysis only.
 *
 * WP-CLI and Action Scheduler are present when the plugin runs (the plugin checks for them first), but no Composer package
 * of stubs for them is a dependency here. Only the parts the plugin calls are declared. This file is never loaded at run time.
 *
 * @package Estasoft\SyncEngine
 */

namespace {

	class WP_CLI {
		/**
		 * @param string $message Text.
		 */
		public static function log( $message ) {}

		/**
		 * @param string $message Text.
		 */
		public static function success( $message ) {}

		/**
		 * @param string $message Text.
		 */
		public static function warning( $message ) {}

		/**
		 * @param string|\WP_Error $message Text.
		 * @param bool|int         $exit    Whether to stop, or the exit code.
		 * @return void
		 */
		public static function error( $message, $exit = true ) {}

		/**
		 * @param mixed                $value Value.
		 * @param array<string,string> $assoc_args Options such as format.
		 */
		public static function print_value( $value, $assoc_args = array() ) {}

		/**
		 * @param string          $name Command name.
		 * @param callable|object $callable Implementation.
		 * @param array<mixed>    $args Options.
		 * @return bool
		 */
		public static function add_command( $name, $callable, $args = array() ) {}
	}

	class ActionScheduler_Store {
		/**
		 * @param int|string $action_id Action id.
		 * @return void
		 */
		public function delete_action( $action_id ) {}
	}

	class ActionScheduler {
		/**
		 * @param string $function_name Calling function.
		 * @return bool
		 */
		public static function is_initialized( $function_name = null ) {}

		/**
		 * @return ActionScheduler_Store
		 */
		public static function store() {}
	}

	/**
	 * @param string               $hook  Hook.
	 * @param array<int,mixed>     $args  Arguments.
	 * @param string               $group Group.
	 * @return int|bool
	 */
	function as_next_scheduled_action( $hook, $args = null, $group = '' ) {}

	/**
	 * @param int                  $timestamp           First run.
	 * @param int                  $interval_in_seconds Interval.
	 * @param string               $hook                Hook.
	 * @param array<int,mixed>     $args                Arguments.
	 * @param string               $group               Group.
	 * @return int
	 */
	function as_schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args = array(), $group = '' ) {}
}

namespace WP_CLI\Utils {

	/**
	 * @param string                         $format Output format.
	 * @param array<int,array<string,mixed>> $items  Rows.
	 * @param array<int,string>|string       $fields Columns.
	 * @return void
	 */
	function format_items( $format, $items, $fields ) {}
}
