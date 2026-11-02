<?php
/**
 * The real clock.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the server time and returns it in UTC.
 */
final class SystemClock implements Clock {

	/**
	 * The current moment in UTC, with microseconds.
	 */
	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
