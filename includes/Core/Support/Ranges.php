<?php
/**
 * The limits for number fields (review-2 changes, board Z05).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Each range is array( min, max, default ). A value outside the range is refused with a message
 * such as "Enter a number from 1 to 60." next to the field.
 */
final class Ranges {

	/**
	 * Pause after this many failures in a row.
	 */
	public const BREAKER_THRESHOLD = array( 3, 20, 5 );

	/**
	 * Check a paused connection again every N minutes.
	 */
	public const PROBE_MINUTES = array( 1, 60, 2 );

	/**
	 * Requests per second, per connection.
	 */
	public const REQUESTS_PER_SECOND = array( 1, 50, 4 );

	/**
	 * Items at once, per connection.
	 */
	public const CONCURRENCY = array( 1, 10, 2 );

	/**
	 * Items at once, all connections together.
	 */
	public const GLOBAL_CONCURRENCY = array( 1, 10, 2 );

	/**
	 * Stock safety buffer in units (used by the stock job).
	 */
	public const SAFETY_BUFFER = array( 0, 999, 2 );

	/**
	 * Queue warning limit in items.
	 */
	public const BACKLOG = array( 10, 10000, 200 );

	/**
	 * Largest request or answer the plugin will keep in memory for hashing and logging.
	 */
	public const PAYLOAD_CAP_BYTES = array( 1024, 10485760, 262144 );

	/**
	 * Most email recipients for alerts.
	 */
	public const MAX_RECIPIENTS = 10;

	/**
	 * Read a whole number inside a range.
	 *
	 * @param mixed                    $value Candidate (int, or a string of digits).
	 * @param array{0:int,1:int,2:int} $range Range.
	 * @return int|null The number, or null when it is not a whole number inside the range.
	 */
	public static function read( mixed $value, array $range ): ?int {
		if ( is_string( $value ) && 1 === preg_match( '/^-?\d+$/', trim( $value ) ) ) {
			$value = (int) trim( $value );
		}
		if ( is_float( $value ) && floor( $value ) === $value ) {
			$value = (int) $value;
		}
		if ( ! is_int( $value ) || $value < $range[0] || $value > $range[1] ) {
			return null;
		}
		return $value;
	}

	/**
	 * The message shown next to a field whose value is out of range.
	 *
	 * @param array{0:int,1:int,2:int} $range Range.
	 */
	public static function message( array $range ): string {
		/* translators: 1: smallest allowed number, 2: largest allowed number. */
		return sprintf( __( 'Enter a number from %1$d to %2$d.', 'estasoft-erp-crm-sync-engine' ), $range[0], $range[1] );
	}
}
