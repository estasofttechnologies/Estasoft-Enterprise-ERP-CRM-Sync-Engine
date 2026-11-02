<?php
/**
 * Order dates in the accounting system's calendar.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EuAccountingCommon;

defined( 'ABSPATH' ) || exit;

/**
 * The engine stores UTC. An accounting system books a document on a calendar day of the country it works in, so the day of an order that was
 * placed at 23:30 UTC is already the next day in Berlin. This class converts with a named time zone and never with a fixed offset.
 */
final class LocalDate {

	/**
	 * The moment in a time zone.
	 *
	 * @param string $utc      A UTC text such as "2026-11-02 23:30:00", or a bare date "2026-11-02" (read as midnight UTC).
	 * @param string $timezone PHP time zone name; an unknown name falls back to UTC.
	 * @return \DateTimeImmutable|null Null when the text is not a date.
	 */
	public static function at( string $utc, string $timezone ): ?\DateTimeImmutable {
		$utc = trim( $utc );
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $utc, $parts ) ) {
			return null;
		}
		$moment = new \DateTimeImmutable(
			sprintf( '%s-%s-%s %s:%s:%s', $parts[1], $parts[2], $parts[3], $parts[4] ?? '00', $parts[5] ?? '00', $parts[6] ?? '00' ),
			new \DateTimeZone( 'UTC' )
		);
		try {
			$zone = new \DateTimeZone( '' === $timezone ? 'UTC' : $timezone );
		} catch ( \Exception $e ) {
			unset( $e );
			$zone = new \DateTimeZone( 'UTC' );
		}
		return $moment->setTimezone( $zone );
	}

	/**
	 * The calendar day as Y-m-d.
	 *
	 * @param string $utc      UTC text.
	 * @param string $timezone Time zone.
	 */
	public static function ymd( string $utc, string $timezone ): string {
		$moment = self::at( $utc, $timezone );
		return null === $moment ? '' : $moment->format( 'Y-m-d' );
	}

	/**
	 * The calendar day as dd.mm.yyyy.
	 *
	 * @param string $utc      UTC text.
	 * @param string $timezone Time zone.
	 */
	public static function dmy( string $utc, string $timezone ): string {
		$moment = self::at( $utc, $timezone );
		return null === $moment ? '' : $moment->format( 'd.m.Y' );
	}

	/**
	 * Midnight of the calendar day with its UTC offset, in the form yyyy-MM-ddTHH:mm:ss.SSSXXX.
	 *
	 * @param string $utc      UTC text.
	 * @param string $timezone Time zone.
	 */
	public static function midnightWithOffset( string $utc, string $timezone ): string {
		$moment = self::at( $utc, $timezone );
		if ( null === $moment ) {
			return '';
		}
		return $moment->setTime( 0, 0, 0 )->format( 'Y-m-d\TH:i:s.000P' );
	}

	/**
	 * First and last second of the calendar day as Unix timestamps.
	 *
	 * @param string $utc      UTC text.
	 * @param string $timezone Time zone.
	 * @return array{0:int,1:int}|null
	 */
	public static function dayBounds( string $utc, string $timezone ): ?array {
		$moment = self::at( $utc, $timezone );
		if ( null === $moment ) {
			return null;
		}
		return array( $moment->setTime( 0, 0, 0 )->getTimestamp(), $moment->setTime( 23, 59, 59 )->getTimestamp() );
	}

	/**
	 * The calendar day as the whole number yyyymmdd (for example 20261103), or null when the text is not a date.
	 *
	 * @param string $utc      UTC text.
	 * @param string $timezone Time zone.
	 */
	public static function ymdNumber( string $utc, string $timezone ): ?int {
		$moment = self::at( $utc, $timezone );
		return null === $moment ? null : (int) $moment->format( 'Ymd' );
	}

	/**
	 * Midnight of the calendar day as a Unix timestamp, or null when the text is not a date.
	 *
	 * @param string $utc      UTC text.
	 * @param string $timezone Time zone.
	 */
	public static function midnightTimestamp( string $utc, string $timezone ): ?int {
		$moment = self::at( $utc, $timezone );
		return null === $moment ? null : $moment->setTime( 0, 0, 0 )->getTimestamp();
	}

	/**
	 * Whether the date a vendor shows for a document is another calendar day than the one the connector sent. A vendor value that cannot be read
	 * (empty, not starting with yyyy-mm-dd) is not a difference, because nothing can be said about it.
	 *
	 * @param mixed  $vendorValue The date field of the document as read back.
	 * @param string $utc         The order date (UTC text) the connector converted.
	 * @param string $timezone    Time zone the connector used.
	 */
	public static function differs( mixed $vendorValue, string $utc, string $timezone ): bool {
		$expected = self::ymd( $utc, $timezone );
		if ( '' === $expected || ! is_string( $vendorValue ) ) {
			return false;
		}
		$value = trim( $vendorValue );
		if ( 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2})(?:[T ]\d{2}:\d{2}.*?(Z|[+-]\d{2}:?\d{2})?)?$/', $value, $parts ) ) {
			return false;
		}
		$seen = $parts[1];
		if ( isset( $parts[2] ) && '' !== $parts[2] ) {
			try {
				$seen = ( new \DateTimeImmutable( $value ) )->setTimezone( new \DateTimeZone( '' === $timezone ? 'UTC' : $timezone ) )->format( 'Y-m-d' );
			} catch ( \Exception $e ) {
				unset( $e );
			}
		}
		return $seen !== $expected;
	}
}
