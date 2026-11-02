<?php
/**
 * Dates as Holded stores them: Unix seconds.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Clock;

/**
 * A document date is written as noon UTC of the order's day, so it shows as the same day in every time zone from Spain to the Americas. The
 * search window around it starts a day before and ends three days after, which also covers a document that Holded dated in another zone.
 */
final class HoldedDates {

	/**
	 * Days back `findExisting` looks when the engine has not linked a document yet.
	 */
	public const LOOK_BACK_DAYS = 30;

	/**
	 * Set up with what it depends on.
	 *
	 * @param Clock $clock Time source.
	 */
	public function __construct( private readonly Clock $clock ) {
	}

	/**
	 * Today in UTC, Y-m-d.
	 */
	public function today(): string {
		return $this->clock->now()->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d' );
	}

	/**
	 * The first second of a day in UTC.
	 *
	 * @param string $date Y-m-d; an empty or invalid date is today.
	 */
	public function dayStart( string $date ): int {
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $this->today(), new \DateTimeZone( 'UTC' ) );
		}
		return false === $parsed ? $this->clock->now()->getTimestamp() : $parsed->getTimestamp();
	}

	/**
	 * The date a document is written with: noon UTC of the day.
	 *
	 * @param string $date Y-m-d.
	 */
	public function stamp( string $date ): int {
		return $this->dayStart( $date ) + 43200;
	}

	/**
	 * The window a document of a day is searched in.
	 *
	 * @param string $date Y-m-d.
	 * @return array{0:int,1:int} First and last second.
	 */
	public function around( string $date ): array {
		$start = $this->dayStart( $date );
		return array( $start - 86400, $start + 3 * 86400 - 1 );
	}

	/**
	 * The window of recent days.
	 *
	 * @return array{0:int,1:int} First and last second.
	 */
	public function recent(): array {
		$now = $this->clock->now()->getTimestamp();
		return array( $now - self::LOOK_BACK_DAYS * 86400, $now + 86400 );
	}
}
