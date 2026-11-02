<?php
/**
 * The parent side of the start barrier for the Core tests that run several processes at once.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

/**
 * Start the helper processes with `$barrier->dir()` where they expect a start time, then call `release( n )`: it waits until n
 * processes have loaded WordPress and are standing ready, and only then lets them all go at the same moment. Without this, a
 * fixed delay has to guess how long the slowest process needs, and on a busy machine it guesses wrong.
 */
final class CoreProcessBarrier {

	/**
	 * Directory shared with the processes.
	 */
	private string $dir;

	/**
	 * Create the directory.
	 */
	public function __construct() {
		$this->dir = sys_get_temp_dir() . '/estasoft-barrier-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->dir, 0700 ); // phpcs:ignore
	}

	/**
	 * Remove the directory with what is in it.
	 */
	public function __destruct() {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file ); // phpcs:ignore
		}
		rmdir( $this->dir ); // phpcs:ignore
	}

	/**
	 * The directory to hand to the processes.
	 */
	public function dir(): string {
		return $this->dir;
	}

	/**
	 * Wait until the expected number of processes are ready, then let them go.
	 *
	 * @param int   $expected How many processes were started.
	 * @param float $timeout  Seconds to wait for them.
	 * @return bool False when they were not all ready in time (they are let go anyway, so the test can fail with a message).
	 */
	public function release( int $expected, float $timeout = 90.0 ): bool {
		$until = microtime( true ) + $timeout;
		$ready = false;
		while ( microtime( true ) < $until ) {
			if ( count( (array) glob( $this->dir . '/ready-*' ) ) >= $expected ) {
				$ready = true;
				break;
			}
			usleep( 2000 );
		}
		touch( $this->dir . '/go' ); // phpcs:ignore
		return $ready;
	}
}
