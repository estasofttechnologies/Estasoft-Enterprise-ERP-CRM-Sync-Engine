<?php
/**
 * The options the plugin stores, by name.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Uninstall and the Delete data tool remove exactly these options and nothing else. A site can hold options of other
 * plugins or add-ons that happen to start with `estasoft_sync_`; those are not ours to delete. Transients are different:
 * they are disposable by nature, so every transient with the plugin prefix is removed (see DataEraser).
 *
 * The list has three parts: what Core stores, what the other modules of this plugin store (listed here so that uninstall
 * is complete even before a module registers anything), and what a module or add-on adds through the filter
 * `estasoft_sync_option_names`. A name that ends with `*` is a prefix, for options whose name carries an id.
 */
final class OptionNames {

	/**
	 * Options Core stores.
	 *
	 * @var array<int,string>
	 */
	public const CORE = array(
		'estasoft_sync_settings',
		'estasoft_sync_onboarding',
		'estasoft_sync_installed_at',
		'estasoft_sync_db_version',
		'estasoft_sync_migrating',
		'estasoft_sync_last_run',
		'estasoft_sync_rl_*',
	);

	/**
	 * Options the other modules of this plugin store, as of this version.
	 *
	 * @var array<int,string>
	 */
	public const MODULES = array(
		'estasoft_sync_capacity',
		'estasoft_sync_failed_count',
		'estasoft_sync_invoice_settings',
		'estasoft_sync_notifications_state',
		'estasoft_sync_readiness',
		'estasoft_sync_recon_progress',
		'estasoft_sync_recon_settings',
		'estasoft_sync_recon_system',
		'estasoft_sync_shadow_verdicts',
		'estasoft_sync_stock_changes',
		'estasoft_sync_stock_conflicts',
		'estasoft_sync_stock_last',
		'estasoft_sync_tracking_cursor',
		'estasoft_sync_tracking_recent',
		'estasoft_sync_order_view_counts',
		'estasoft_sync_n_paused_*',
		'estasoft_sync_inb_rl_*',
		'estasoft_sync_inb_seen_*',
	);

	/**
	 * Every name, exact and prefix, after the filter. Prefixes keep their trailing `*`.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		$names = array_merge( self::CORE, self::MODULES );
		/**
		 * Filter the options that are removed when the plugin's data is deleted. Add the names your module stores; end a
		 * name with `*` to remove every option that starts with it. Only names that start with `estasoft_sync_` are used.
		 *
		 * @param array<int,string> $names Option names.
		 */
		$filtered = apply_filters( 'estasoft_sync_option_names', $names );
		$clean    = array();
		foreach ( (array) $filtered as $name ) {
			$name = trim( (string) $name );
			if ( 0 === strpos( $name, 'estasoft_sync_' ) && strlen( rtrim( $name, '*' ) ) > strlen( 'estasoft_sync_' ) ) {
				$clean[] = $name;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Exact option names.
	 *
	 * @return array<int,string>
	 */
	public static function exact(): array {
		return array_values( array_filter( self::all(), static fn( string $name ): bool => ! str_ends_with( $name, '*' ) ) );
	}

	/**
	 * Name prefixes (without the trailing `*`).
	 *
	 * @return array<int,string>
	 */
	public static function prefixes(): array {
		$prefixes = array();
		foreach ( self::all() as $name ) {
			if ( str_ends_with( $name, '*' ) ) {
				$prefixes[] = rtrim( $name, '*' );
			}
		}
		return array_values( array_unique( $prefixes ) );
	}
}
