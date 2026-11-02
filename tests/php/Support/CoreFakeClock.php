<?php
/**
 * A clock the test controls.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Core\Support\Clock;

/**
 * Starts at a fixed UTC moment and only moves when the test says so.
 */
final class CoreFakeClock implements Clock {

	/**
	 * Current moment.
	 */
	private \DateTimeImmutable $now;

	/**
	 * @param string $at Start, UTC, any format DateTimeImmutable accepts.
	 */
	public function __construct( string $at = '2026-11-02 12:00:00' ) {
		$this->now = new \DateTimeImmutable( $at, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * The current moment.
	 */
	public function now(): \DateTimeImmutable {
		return $this->now;
	}

	/**
	 * Move forward.
	 *
	 * @param int $seconds Seconds.
	 */
	public function advance( int $seconds ): void {
		$this->now = $this->now->modify( sprintf( '%+d seconds', $seconds ) );
	}

	/**
	 * Move forward by milliseconds.
	 *
	 * @param int $milliseconds Milliseconds.
	 */
	public function advanceMilliseconds( int $milliseconds ): void {
		$this->now = $this->now->modify( sprintf( '%+d milliseconds', $milliseconds ) );
	}

	/**
	 * Jump to a moment.
	 *
	 * @param string $at UTC moment.
	 */
	public function set( string $at ): void {
		$this->now = new \DateTimeImmutable( $at, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * The current moment as a MySQL datetime.
	 */
	public function sql(): string {
		return $this->now->format( 'Y-m-d H:i:s' );
	}
}
