<?php
/**
 * Creates and upgrades the tables.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Database;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Support\OptionStore;

/**
 * Compares the stored schema version with the code and runs dbDelta when they differ. Safe to call
 * on every request and safe to call twice: dbDelta only changes what differs.
 */
final class Migrator {

	/**
	 * Option that holds the schema version.
	 */
	public const OPTION = 'estasoft_sync_db_version';

	/**
	 * Option used as a short lock so two requests do not migrate at once.
	 */
	private const LOCK = 'estasoft_sync_migrating';

	/**
	 * @param Schema      $schema  Table definitions.
	 * @param Tables      $tables  Table names.
	 * @param OptionStore $options Options.
	 * @param \wpdb       $wpdb    Database.
	 */
	public function __construct(
		private readonly Schema $schema,
		private readonly Tables $tables,
		private readonly OptionStore $options,
		private readonly \wpdb $wpdb
	) {
	}

	/**
	 * The schema version found in the database, or an empty string on a fresh site.
	 */
	public function storedVersion(): string {
		return (string) $this->options->get( self::OPTION, '' );
	}

	/**
	 * Whether the stored version is older than the code.
	 */
	public function needsUpgrade(): bool {
		return version_compare( $this->storedVersion() ?: '0', Schema::VERSION, '<' );
	}

	/**
	 * Run the migration only when the stored version is older.
	 *
	 * @return bool True when a migration ran.
	 */
	public function maybeMigrate(): bool {
		if ( ! $this->needsUpgrade() ) {
			return false;
		}
		return $this->migrate();
	}

	/**
	 * Create or upgrade every table, then store the version. Idempotent.
	 *
	 * @return bool True when all tables exist afterwards.
	 */
	public function migrate(): bool {
		$lock_until = time() + 60;
		$existing   = (int) $this->options->get( self::LOCK, 0 );
		if ( $existing > time() ) {
			return false;
		}
		$this->options->set( self::LOCK, $lock_until );

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			foreach ( $this->schema->statements() as $statement ) {
				dbDelta( $statement );
			}
			$complete = $this->allTablesExist();
			if ( $complete ) {
				$previous = $this->storedVersion();
				$this->options->set( self::OPTION, Schema::VERSION, true );
				/**
				 * Fires after the tables were created or upgraded.
				 *
				 * @param string $previous Version before ('' on a fresh install).
				 * @param string $current  Version now.
				 */
				do_action( 'estasoft_sync_migrated', $previous, Schema::VERSION );
			}
			return $complete;
		} finally {
			$this->options->delete( self::LOCK );
		}
	}

	/**
	 * Whether every table is present.
	 */
	public function allTablesExist(): bool {
		foreach ( $this->tables->all() as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $name ) ) );
			if ( $found !== $name ) {
				return false;
			}
		}
		return true;
	}
}
