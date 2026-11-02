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
	 * ISO 8601 UTC format used by the REST contract.
	 */
	public const ISO = 'Y-m-d\TH:i:s\Z';

	/**
	 * Format a moment as a MySQL datetime in UTC.
	 *
	 * @param \DateTimeInterface $moment Any moment.
	 */
	public static function sql( \DateTimeInterface $moment ): string {
		return \DateTimeImmutable::createFromInterface( $moment )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::SQL );
	}

	/**
	 * Parse a MySQL datetime (UTC).
	 *
	 * @param string $value Datetime string.
	 * @return \DateTimeImmutable|null Null when the value is empty or invalid.
	 */
	public static function fromSql( ?string $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat( self::SQL, $value, new \DateTimeZone( 'UTC' ) );
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
