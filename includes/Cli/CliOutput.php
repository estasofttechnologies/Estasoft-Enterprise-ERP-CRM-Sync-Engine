<?php
/**
 * What the commands print and how they stop.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * A thin line between the commands and WP-CLI, so the commands can be tested without it.
 */
interface CliOutput {

	/**
	 * A progress line.
	 *
	 * @param string $message Text.
	 */
	public function log( string $message ): void;

	/**
	 * A success line.
	 *
	 * @param string $message Text.
	 */
	public function success( string $message ): void;

	/**
	 * A warning line; the command carries on.
	 *
	 * @param string $message Text.
	 */
	public function warning( string $message ): void;

	/**
	 * Print an error and stop with an exit code. Never returns.
	 *
	 * @param string $message Text.
	 * @param int    $code    Exit code: 1 for a failure, 2 for wrong arguments.
	 */
	public function fail( string $message, int $code = 1 ): never;

	/**
	 * Print rows as a table, CSV, JSON or YAML.
	 *
	 * @param string                          $format table, csv, json, yaml or count.
	 * @param array<int,array<string,mixed>>  $rows   Rows.
	 * @param array<int,string>               $fields Columns, in order.
	 */
	public function items( string $format, array $rows, array $fields ): void;

	/**
	 * Print one value as JSON or YAML.
	 *
	 * @param string $format json or yaml.
	 * @param mixed  $data   Value.
	 */
	public function value( string $format, mixed $data ): void;
}
