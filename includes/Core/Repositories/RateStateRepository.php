<?php
/**
 * Rate counters in the options table, for sites without a persistent object cache.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Repositories;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Queue\RateStore;

/**
 * One option row per counter, never autoloaded. Reads go straight to the database (not through the options cache)
 * and writes are compare-and-set updates, so two workers cannot both take the same slot.
 */
final class RateStateRepository extends Repository implements RateStore {

	/**
	 * Prefix of the option names.
	 */
	private const PREFIX = 'estasoft_sync_rl_';

	/**
	 * Read a value.
	 *
	 * @param string $key Counter name.
	 */
	public function get( string $key ): ?float {
		$value = $this->value( "SELECT option_value FROM {$this->wpdb->options} WHERE option_name = %s", array( $this->name( $key ) ) );
		return null === $value ? null : (float) $value;
	}

	/**
	 * Replace a value only if it is still what the caller read.
	 *
	 * @param string     $key      Counter name.
	 * @param float|null $expected Value read earlier; null when the row did not exist.
	 * @param float      $new      New value.
	 */
	public function compareAndSet( string $key, ?float $expected, float $new ): bool {
		$name = $this->name( $key );
		if ( null === $expected ) {
			return $this->run(
				"INSERT IGNORE INTO {$this->wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				array( $name, sprintf( '%.6F', $new ), 'no' )
			) > 0;
		}
		return $this->run(
			"UPDATE {$this->wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			array( sprintf( '%.6F', $new ), $name, sprintf( '%.6F', $expected ) )
		) > 0;
	}

	/**
	 * Option name for a counter.
	 *
	 * @param string $key Counter name.
	 */
	private function name( string $key ): string {
		return self::PREFIX . substr( hash( 'sha256', $key ), 0, 20 );
	}
}
