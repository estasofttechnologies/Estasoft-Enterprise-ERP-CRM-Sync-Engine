<?php
/**
 * A CliOutput that remembers what it was told.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Cli\CliOutput;

/**
 * `fail()` throws RestCliExit with the exit code, as WP_CLI::error() would stop the process.
 */
final class RestFakeCliOutput implements CliOutput {

	/**
	 * Lines: kind and text.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	public array $lines = array();

	/**
	 * Tables printed: format, rows, fields.
	 *
	 * @var array<int,array{0:string,1:array<int,array<string,mixed>>,2:array<int,string>}>
	 */
	public array $tables = array();

	/**
	 * Values printed: format and value.
	 *
	 * @var array<int,array{0:string,1:mixed}>
	 */
	public array $values = array();

	/**
	 * Progress line.
	 *
	 * @param string $message Text.
	 */
	public function log( string $message ): void {
		$this->lines[] = array( 'log', $message );
	}

	/**
	 * Success line.
	 *
	 * @param string $message Text.
	 */
	public function success( string $message ): void {
		$this->lines[] = array( 'success', $message );
	}

	/**
	 * Warning line.
	 *
	 * @param string $message Text.
	 */
	public function warning( string $message ): void {
		$this->lines[] = array( 'warning', $message );
	}

	/**
	 * Error and stop.
	 *
	 * @param string $message Text.
	 * @param int    $code    Exit code.
	 *
	 * @throws RestCliExit Always.
	 */
	public function fail( string $message, int $code = 1 ): never {
		$this->lines[] = array( 'error', $message );
		throw new RestCliExit( $message, $code );
	}

	/**
	 * Rows.
	 *
	 * @param string                         $format Format.
	 * @param array<int,array<string,mixed>> $rows   Rows.
	 * @param array<int,string>              $fields Columns.
	 */
	public function items( string $format, array $rows, array $fields ): void {
		$this->tables[] = array( $format, $rows, $fields );
	}

	/**
	 * One value.
	 *
	 * @param string $format Format.
	 * @param mixed  $data   Value.
	 */
	public function value( string $format, mixed $data ): void {
		$this->values[] = array( $format, $data );
	}

	/**
	 * Texts of one kind of line.
	 *
	 * @param string $kind log, success, warning or error.
	 * @return array<int,string>
	 */
	public function of( string $kind ): array {
		return array_values( array_map( static fn( array $line ): string => $line[1], array_filter( $this->lines, static fn( array $line ): bool => $line[0] === $kind ) ) );
	}
}
