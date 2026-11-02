<?php
/**
 * Day filters in store time, converted to UTC bounds.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * "Today" means today in the store's time zone, not in UTC.
 */
final class DayRange {

	/**
	 * Start of the local day containing a moment, as a UTC moment.
	 *
	 * @param \DateTimeImmutable $now Any moment.
	 * @param \DateTimeZone      $zone Store time zone.
	 */
	public static function startOfDay( \DateTimeImmutable $now, \DateTimeZone $zone ): \DateTimeImmutable {
		return $now->setTimezone( $zone )->setTime( 0, 0, 0 )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Bounds for a day filter.
	 *
	 * @param string             $day  today, yesterday, 7d (today and the 6 days before) or 30d (today and the 29 before).
	 * @param \DateTimeImmutable $now  Current moment.
	 * @param \DateTimeZone      $zone Store time zone.
	 * @return array{0:string,1:?string}|null From (inclusive) and to (exclusive, null for open) as MySQL datetimes in UTC; null for an unknown filter.
	 */
	public static function bounds( string $day, \DateTimeImmutable $now, \DateTimeZone $zone ): ?array {
		$local_today = $now->setTimezone( $zone )->setTime( 0, 0, 0 );
		switch ( $day ) {
			case 'today':
				return array( Time::sql( $local_today ), null );
			case 'yesterday':
				return array( Time::sql( $local_today->modify( '-1 day' ) ), Time::sql( $local_today ) );
			case '7d':
				return array( Time::sql( $local_today->modify( '-6 days' ) ), null );
			case '30d':
				return array( Time::sql( $local_today->modify( '-29 days' ) ), null );
		}
		return null;
	}
}
