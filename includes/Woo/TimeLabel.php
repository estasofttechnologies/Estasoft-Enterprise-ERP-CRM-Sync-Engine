<?php
/**
 * Times for people: "Today 11:39", "Yesterday 09:12", "2 Nov, 11:39".
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Estasoft\SyncEngine\Core\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Formats stored UTC times in the site's time zone and time format. Storage is never changed.
 */
final class TimeLabel {

	/**
	 * Set up with what it depends on.
	 *
	 * @param Clock $clock Clock, for "today".
	 */
	public function __construct( private readonly Clock $clock ) {
	}

	/**
	 * A UTC time (MySQL "Y-m-d H:i:s" or ISO 8601) as text for the site's time zone. Returns an empty string for an empty or unreadable time.
	 *
	 * @param string $utc Time.
	 */
	public function format( string $utc ): string {
		$stamp = '' === $utc ? false : strtotime( str_contains( $utc, 'T' ) || str_ends_with( $utc, 'Z' ) ? $utc : $utc . ' UTC' );
		if ( false === $stamp ) {
			return '';
		}
		$now  = $this->clock->now()->getTimestamp();
		$time = wp_date( (string) get_option( 'time_format', 'H:i' ), $stamp );
		$day  = wp_date( 'Y-m-d', $stamp );
		if ( wp_date( 'Y-m-d', $now ) === $day ) {
			/* translators: %s: time of day, for example 11:39. */
			return sprintf( __( 'Today %s', 'estasoft-erp-crm-sync-engine' ), $time );
		}
		if ( wp_date( 'Y-m-d', $now - DAY_IN_SECONDS ) === $day ) {
			/* translators: %s: time of day, for example 11:39. */
			return sprintf( __( 'Yesterday %s', 'estasoft-erp-crm-sync-engine' ), $time );
		}
		/* translators: 1: date, for example 2 Nov. 2: time of day. */
		return sprintf( __( '%1$s, %2$s', 'estasoft-erp-crm-sync-engine' ), wp_date( 'j M', $stamp ), $time );
	}
}
