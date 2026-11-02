<?php
/**
 * A go-live time that stands still.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Woo\Ports\GoLiveInfo;

final class WooFixedGoLive implements GoLiveInfo {

	private ?\DateTimeImmutable $time;

	/**
	 * @param string|null $utc Moment, for example "2026-11-01 08:00:00", or null while setup is unfinished.
	 */
	public function __construct( ?string $utc = '2026-11-01 08:00:00' ) {
		$this->time = null === $utc ? null : new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) );
	}

	public function wentLiveAt(): ?\DateTimeImmutable {
		return $this->time;
	}
}
