<?php
/**
 * WP-CLI output.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Cli;

defined( 'ABSPATH' ) || exit;

/**
 * Prints through the WP_CLI class. It is only created while WP-CLI is running, because that is the only time the class exists.
 */
final class WpCliOutput implements CliOutput {

	/**
	 * A progress line.
	 *
	 * @param string $message Text.
	 */
	public function log( string $message ): void {
		\WP_CLI::log( $message );
	}

	/**
	 * A success line.
	 *
	 * @param string $message Text.
	 */
	public function success( string $message ): void {
		\WP_CLI::success( $message );
	}

	/**
	 * A warning line.
	 *
	 * @param string $message Text.
	 */
	public function warning( string $message ): void {
		\WP_CLI::warning( $message );
	}

	/**
	 * Print an error and exit.
	 *
	 * @param string $message Text.
	 * @param int    $code    Exit code.
	 *
	 * @throws \RuntimeException Never in practice: WP_CLI::error() ends the process first.
	 */
	public function fail( string $message, int $code = 1 ): never {
		\WP_CLI::error( $message, $code );
		// WP_CLI::error() exits; this line only satisfies the return type if it ever returned.
		throw new \RuntimeException( esc_html( $message ) ); // @phpstan-ignore deadCode.unreachable
	}

	/**
	 * Print rows.
	 *
	 * @param string                         $format Output format.
	 * @param array<int,array<string,mixed>> $rows   Rows.
	 * @param array<int,string>              $fields Columns.
	 */
	public function items( string $format, array $rows, array $fields ): void {
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Print one value.
	 *
	 * @param string $format json or yaml.
	 * @param mixed  $data   Value.
	 */
	public function value( string $format, mixed $data ): void {
		\WP_CLI::print_value( $data, array( 'format' => $format ) );
	}
}
