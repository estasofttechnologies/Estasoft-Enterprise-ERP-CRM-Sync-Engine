<?php
/**
 * Event log that remembers what it was told.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes;

use Estasoft\SyncEngine\Mapping\Ports\EventLog;

final class RecordingLog implements EventLog {

	/**
	 * Entries as [level, message, context].
	 *
	 * @var array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	public array $entries = array();

	public function info( string $message, array $context = array() ): void {
		$this->entries[] = array( 'info', $message, $context );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->entries[] = array( 'warning', $message, $context );
	}

	public function error( string $message, array $context = array() ): void {
		$this->entries[] = array( 'error', $message, $context );
	}

	/**
	 * Entries of one level.
	 *
	 * @return array<int,array{0:string,1:string,2:array<string,mixed>}>
	 */
	public function level( string $level ): array {
		return array_values( array_filter( $this->entries, static fn( array $entry ): bool => $level === $entry[0] ) );
	}
}
