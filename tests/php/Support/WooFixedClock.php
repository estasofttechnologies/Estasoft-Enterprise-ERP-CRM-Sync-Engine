<?php
/**
 * A clock that stands still.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Core\Support\Clock;

final class WooFixedClock implements Clock {

	private \DateTimeImmutable $now;

	/**
	 * @param string $utc Moment, for example "2026-11-02 11:40:00".
	 */
	public function __construct( string $utc = '2026-11-02 11:40:00' ) {
		$this->now = new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
	}

	public function now(): \DateTimeImmutable {
		return $this->now;
	}
}
