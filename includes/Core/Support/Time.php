<?php
/**
 * Conversions between PHP dates, MySQL datetime strings and ISO 8601.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Storage is always UTC in `Y-m-d H:i:s`. The REST contract uses `Y-m-d\TH:i:s\Z`.
 */
final class Time {

	/**
	 * MySQL datetime format.
	 */
	public const SQL = 'Y-m-d H:i:s';

	/**
	 * MySQL datetime(3) format (with milliseconds), used by the log table.
	 */
	public const SQL_MS = 'Y-m-d H:i:s.v';

	/**
	 * ISO 8601 UTC format used by the REST contract.
	 */
	public const ISO = 'Y-m-d\TH:i:s\Z';

	/**
	 * ISO 8601 UTC format with milliseconds, used for log entries.
	 */
	public const ISO_MS = 'Y-m-d\TH:i:s.v\Z';

	/**
	 * Format a moment as a MySQL datetime in UTC.
	 *
	 * @param \DateTimeInterface $moment Any moment.
	 */
	public static function sql( \DateTimeInterface $moment ): string {
		return \DateTimeImmutable::createFromInterface( $moment )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::SQL );
	}

	/**
	 * Format a moment as a MySQL datetime(3) in UTC, milliseconds included.
	 *
	 * @param \DateTimeInterface $moment Any moment.
	 */
	public static function sqlMs( \DateTimeInterface $moment ): string {
		return \DateTimeImmutable::createFromInterface( $moment )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::SQL_MS );
	}

	/**
	 * Parse a MySQL datetime (UTC), with or without a fraction of a second.
	 *
	 * @param string $value Datetime string.
	 * @return \DateTimeImmutable|null Null when the value is empty or invalid.
	 */
	public static function fromSql( ?string $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}
		$utc    = new \DateTimeZone( 'UTC' );
		$parsed = \DateTimeImmutable::createFromFormat( self::SQL, $value, $utc );
		if ( false === $parsed ) {
			$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s.u', $value, $utc );
		}
		return false === $parsed ? null : $parsed;
	}

	/**
	 * Convert a MySQL datetime to the REST format.
	 *
	 * @param string|null $value MySQL datetime in UTC.
	 * @return string|null ISO string, or null when empty.
	 */
	public static function iso( ?string $value ): ?string {
		$parsed = self::fromSql( $value );
		return null === $parsed ? null : $parsed->format( self::ISO );
	}

	/**
	 * Convert a MySQL datetime to the REST format with milliseconds.
	 *
	 * @param string|null $value MySQL datetime in UTC, with or without a fraction.
	 * @return string|null ISO string, or null when empty.
	 */
	public static function isoMs( ?string $value ): ?string {
		$parsed = self::fromSql( $value );
		return null === $parsed ? null : $parsed->format( self::ISO_MS );
	}

	/**
	 * Add seconds to a moment.
	 *
	 * @param \DateTimeImmutable $moment  Start.
	 * @param int                $seconds Seconds (may be negative).
	 */
	public static function plus( \DateTimeImmutable $moment, int $seconds ): \DateTimeImmutable {
		return $moment->modify( sprintf( '%+d seconds', $seconds ) );
	}

	/**
	 * Milliseconds between two moments.
	 *
	 * @param \DateTimeImmutable $from Start.
	 * @param \DateTimeImmutable $to   End.
	 */
	public static function millisecondsBetween( \DateTimeImmutable $from, \DateTimeImmutable $to ): int {
		$delta = (float) $to->format( 'U.u' ) - (float) $from->format( 'U.u' );
		return max( 0, (int) round( $delta * 1000 ) );
	}
}
