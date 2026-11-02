<?php
/**
 * Refresh lock kept in the options table.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\OAuth;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\Clock;

/**
 * `INSERT IGNORE` on the unique option name is atomic on every database WordPress supports, so exactly one caller wins. A lock whose time ran out
 * is taken over with a compare-and-set `UPDATE`. The rows are named `estasoft_sync_lock_*` and are removed with the plugin's data.
 */
final class WpdbRefreshLock implements RefreshLock {

	/**
	 * Prefix of the option names.
	 */
	public const PREFIX = 'estasoft_sync_lock_';

	/**
	 * Set up with what it depends on.
	 *
	 * @param \wpdb $wpdb  Database.
	 * @param Clock $clock Time source.
	 */
	public function __construct( private readonly \wpdb $wpdb, private readonly Clock $clock ) {
	}

	/**
	 * Try to take the lock.
	 *
	 * @param string $key Lock name.
	 * @param int    $ttl Seconds until the lock frees itself.
	 * @return string|null An owner token, or null when somebody else holds the lock.
	 */
	public function acquire( string $key, int $ttl ): ?string {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- the options table name comes from $wpdb; every value is a placeholder.
		$name  = $this->name( $key );
		$token = bin2hex( random_bytes( 8 ) );
		$value = ( $this->clock->now()->getTimestamp() + max( 1, $ttl ) ) . ':' . $token;

		$inserted = $this->wpdb->query( $this->wpdb->prepare( "INSERT IGNORE INTO {$this->wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
		if ( 1 === $inserted ) {
			return $token;
		}
		$current = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s", $name ) );
		if ( ! is_string( $current ) ) {
			return null;
		}
		if ( (int) explode( ':', $current )[0] >= $this->clock->now()->getTimestamp() ) {
			return null;
		}
		$took = $this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $name, $current ) );
		return 1 === $took ? $token : null;
		// phpcs:enable
	}

	/**
	 * Free a lock, only when the owner token is still the owner.
	 *
	 * @param string $key   Lock name.
	 * @param string $token Token from acquire().
	 */
	public function release( string $key, string $token ): void {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- the options table name comes from $wpdb; every value is a placeholder.
		$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $this->name( $key ), '%:' . $this->wpdb->esc_like( $token ) ) );
		// phpcs:enable
	}

	/**
	 * The option name of a lock.
	 *
	 * @param string $key Lock name.
	 */
	private function name( string $key ): string {
		return self::PREFIX . substr( hash( 'sha256', $key ), 0, 40 );
	}
}
