<?php
/**
 * Time-of-day rules in the store's time zone.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The nightly check and the daily digest run "at a store-time hour". A tick every hour (or 15 minutes) asks these two questions.
 */
final class StoreTime {

	/**
	 * The local date of a moment.
	 *
	 * @param \DateTimeImmutable $now  Any moment.
	 * @param \DateTimeZone      $zone Store time zone.
	 * @return string Y-m-d.
	 */
	public static function date( \DateTimeImmutable $now, \DateTimeZone $zone ): string {
		return $now->setTimezone( $zone )->format( 'Y-m-d' );
	}

	/**
	 * Whether the local time of day has reached a time.
	 *
	 * @param \DateTimeImmutable $now  Any moment.
	 * @param \DateTimeZone      $zone Store time zone.
	 * @param string             $time HH:MM (24 hours).
	 */
	public static function reached( \DateTimeImmutable $now, \DateTimeZone $zone, string $time ): bool {
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts ) ) {
			return false;
		}
		$local   = $now->setTimezone( $zone );
		$minutes = ( (int) $local->format( 'G' ) * 60 ) + (int) $local->format( 'i' );
		return $minutes >= ( (int) $parts[1] * 60 ) + (int) $parts[2];
	}
}
