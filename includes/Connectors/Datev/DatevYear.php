<?php
/**
 * Financial years for the DATEV file.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Datev;

defined( 'ABSPATH' ) || exit;

/**
 * A DATEV booking date holds only day and month, so a file belongs to one financial year. The year of a date is the year in which
 * its financial year starts.
 */
final class DatevYear {

	/**
	 * First day of the financial year a date belongs to.
	 *
	 * @param string $date  Y-m-d.
	 * @param string $start Month and day the year starts, "MM-DD".
	 * @return string Y-m-d.
	 */
	public static function start( string $date, string $start ): string {
		$year = (int) substr( $date, 0, 4 );
		return ( substr( $date, 5, 5 ) >= $start ? $year : $year - 1 ) . '-' . $start;
	}

	/**
	 * Last day of the financial year that starts on a date.
	 *
	 * @param string $start Y-m-d.
	 */
	public static function end( string $start ): string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $start, new \DateTimeZone( 'UTC' ) );
		return false === $date ? $start : $date->modify( '+1 year -1 day' )->format( 'Y-m-d' );
	}
}
