<?php
/**
 * Calendar dates for documents, in the time of the store.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Microsoft;

defined( 'ABSPATH' ) || exit;

/**
 * The date written on an invoice, an order or a deal is the date in the shop, not in UTC: an order placed at 01:30 in Dubai on the first of
 * a month is an order of that month, and its UTC date is still the last day of the month before (a wrong tax period at month end). A date
 * that already is a plain calendar date is kept as it is; a point in time (with or without a time zone, UTC when it has none) is moved to
 * the time zone of the site, which is the time zone of the shop.
 */
final class Dates {

	/**
	 * A function that returns the zone, used instead of the site setting (the unit tests have no site).
	 *
	 * @var \Closure|null
	 */
	private static ?\Closure $resolver = null;

	/**
	 * Take the zone from a function instead of the site setting; null goes back to the site setting.
	 *
	 * @param \Closure|null $resolver Returns a DateTimeZone.
	 */
	public static function resolveWith( ?\Closure $resolver ): void {
		self::$resolver = $resolver;
	}

	/**
	 * The time zone of the store: the site's setting, UTC when WordPress cannot say.
	 */
	public static function storeZone(): \DateTimeZone {
		if ( null !== self::$resolver ) {
			$zone = ( self::$resolver )();
			return $zone instanceof \DateTimeZone ? $zone : new \DateTimeZone( 'UTC' );
		}
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
	}

	/**
	 * The calendar date of a date or date-time text, or an empty string when the text is not one.
	 *
	 * @param mixed $value Date text.
	 */
	public static function calendarDate( mixed $value ): string {
		$text = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $text ) {
			return '';
		}
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ) {
			return checkdate( (int) substr( $text, 5, 2 ), (int) substr( $text, 8, 2 ), (int) substr( $text, 0, 4 ) ) ? $text : '';
		}
		try {
			return ( new \DateTimeImmutable( $text, new \DateTimeZone( 'UTC' ) ) )->setTimezone( self::storeZone() )->format( 'Y-m-d' );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return '';
		}
	}

	/**
	 * Today's date in the store.
	 */
	public static function today(): string {
		return ( new \DateTimeImmutable( 'now', self::storeZone() ) )->format( 'Y-m-d' );
	}
}
