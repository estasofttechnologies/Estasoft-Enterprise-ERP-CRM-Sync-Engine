<?php
/**
 * Which way an order status moves.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tracking and delivery information may move an order forward, never backward (TRK-1). Forward means up this ladder:
 * pending, on-hold, processing, completed. A status that is not on the ladder (cancelled, refunded, failed, draft and any
 * custom status) is never moved by the plugin, and nothing is moved into one.
 */
final class StatusRank {

	/**
	 * The ladder, lowest first.
	 */
	private const LADDER = array( 'pending', 'on-hold', 'processing', 'completed' );

	/**
	 * Whether moving from one status to another goes forward.
	 *
	 * @param string $from Current status slug (with or without wc-).
	 * @param string $to   Wanted status slug (with or without wc-).
	 */
	public static function isForward( string $from, string $to ): bool {
		$from = self::position( $from );
		$to   = self::position( $to );
		return null !== $from && null !== $to && $to > $from;
	}

	/**
	 * Place on the ladder.
	 *
	 * @param string $status Status slug.
	 * @return int|null Null when the status is not on the ladder.
	 */
	private static function position( string $status ): ?int {
		$index = array_search( (string) preg_replace( '/^wc-/', '', strtolower( trim( $status ) ) ), self::LADDER, true );
		return false === $index ? null : (int) $index;
	}
}
