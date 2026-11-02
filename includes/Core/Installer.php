<?php
/**
 * Activation, deactivation and uninstall.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Database\Migrator;
use Estasoft\SyncEngine\Core\Database\Schema;
use Estasoft\SyncEngine\Core\Database\Tables;
use Estasoft\SyncEngine\Core\Database\WpdbProvider;
use Estasoft\SyncEngine\Core\Queue\Scheduler;
use Estasoft\SyncEngine\Core\Support\Time;
use Estasoft\SyncEngine\Core\Support\WpOptionStore;

/**
 * Static entry points, because they run before the plugin has booted (activation) or when it is not loaded at all
 * (uninstall). Each one works on the current site, and loops over all sites on multisite where that is what the
 * request means.
 */
final class Installer {

	/**
	 * Transient that asks the admin to open the welcome screen once after activation.
	 */
	public const WELCOME_TRANSIENT = 'estasoft_sync_welcome';

	/**
	 * Create the tables, grant the capabilities, store default options and schedule the background tasks.
	 *
	 * @param bool $networkWide True for a network-wide activation on multisite.
	 */
	public static function activate( bool $networkWide = false ): void {
		if ( $networkWide && is_multisite() ) {
			self::forEachSite( array( self::class, 'installSite' ) );
		} else {
			self::installSite();
		}
		if ( self::welcomeAllowed( $networkWide ) ) {
			set_transient( self::WELCOME_TRANSIENT, 1, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Unschedule the plugin's background tasks. Data and capabilities stay.
	 *
	 * @param bool $networkWide True for a network-wide deactivation on multisite.
	 */
	public static function deactivate( bool $networkWide = false ): void {
		if ( ( $networkWide || is_network_admin() ) && is_multisite() ) {
			self::forEachSite( array( Scheduler::class, 'unschedule' ) );
		} else {
			Scheduler::unschedule();
		}
	}

	/**
	 * Remove the plugin's data when the person chose "Delete all plugin data when the plugin is deleted". On multisite every
	 * site is checked on its own setting. The background tasks are always unscheduled.
	 */
	public static function uninstall(): void {
		if ( is_multisite() ) {
			// User meta belongs to the whole network: it goes only when every site chose to delete its data.
			$all = true;
			self::forEachSite(
				static function () use ( &$all ): void {
					if ( ! self::uninstallSite( false ) ) {
						$all = false;
					}
				}
			);
			if ( $all ) {
				$wpdb = ( new WpdbProvider() )->get();
				( new DataEraser( $wpdb, new Tables( $wpdb->base_prefix ) ) )->eraseUserMeta();
			}
			return;
		}
		self::uninstallSite();
	}

	/**
	 * Set up the current site: tables, capabilities, default options, background tasks. Safe to call again.
	 */
	public static function installSite(): void {
		$wpdb    = ( new WpdbProvider() )->get();
		$tables  = new Tables( $wpdb->prefix );
		$schema  = new Schema( $tables, $wpdb->get_charset_collate() );
		$options = new WpOptionStore();

		( new Migrator( $schema, $tables, $options, $wpdb ) )->migrate();
		Capabilities::grant();

		$settings = new Settings( $options, (string) get_option( 'admin_email', '' ) );
		add_option( Settings::OPTION, $settings->defaults(), '', false );
		add_option( OnboardingState::OPTION, ( new OnboardingState( $options ) )->defaults(), '', false );
		add_option( 'estasoft_sync_installed_at', Time::sql( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) ), '', false );

		Scheduler::schedule();
	}

	/**
	 * Set up a site that was just created on a network where the plugin is network-active.
	 *
	 * @param \WP_Site $site The new site.
	 */
	public static function installNewSite( \WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active_for_network( plugin_basename( ESTASOFT_SYNC_FILE ) ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::installSite();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Uninstall the current site.
	 *
	 * @param bool|null $deleteUserMeta Whether to remove the plugin's user meta too; null: yes on a single site, no on multisite.
	 * @return bool Whether the site's data was deleted (false when the site chose to keep it).
	 */
	public static function uninstallSite( ?bool $deleteUserMeta = null ): bool {
		$options  = new WpOptionStore();
		$settings = new Settings( $options );
		if ( ! $settings->deleteOnUninstall() ) {
			Scheduler::unschedule();
			return false;
		}
		$wpdb = ( new WpdbProvider() )->get();
		( new DataEraser( $wpdb, new Tables( $wpdb->prefix ) ) )->erase( true, $deleteUserMeta );
		return true;
	}

	/**
	 * Run work once for every site of the network, with that site's tables and options in force.
	 *
	 * @param callable $work Receives no arguments.
	 */
	private static function forEachSite( callable $work ): void {
		$ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( (array) $ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			try {
				$work();
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Whether to send the person to the welcome screen after this activation: a single interactive activation, not a
	 * network, bulk, AJAX, cron or command-line one.
	 *
	 * @param bool $networkWide Whether the activation is network-wide.
	 */
	private static function welcomeAllowed( bool $networkWide ): bool {
		if ( $networkWide || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks whether WordPress added its own activate-multi flag to the redirect; nothing is read or changed.
		if ( isset( $_GET['activate-multi'] ) ) {
			return false;
		}
		return current_user_can( 'activate_plugins' );
	}
}
