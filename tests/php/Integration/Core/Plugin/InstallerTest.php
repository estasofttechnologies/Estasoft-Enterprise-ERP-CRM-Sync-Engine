<?php
/**
 * Activation, deactivation and uninstall.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Plugin;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\Database\Migrator;
use Estasoft\SyncEngine\Core\Database\Schema;
use Estasoft\SyncEngine\Core\Installer;
use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Queue\Scheduler;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class InstallerTest extends CoreIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		Installer::installSite();
	}

	protected function tearDown(): void {
		unset( $_GET['activate-multi'] );
		wp_set_current_user( 0 );
		delete_transient( Installer::WELCOME_TRANSIENT );
		// Whatever a test did, leave a fully installed site behind.
		Installer::installSite();
		update_option( Settings::OPTION, ( new Settings( new \Estasoft\SyncEngine\Core\Support\WpOptionStore(), 'admin@example.test' ) )->defaults(), false );
		parent::tearDown();
	}

	/**
	 * Whether all nine tables exist.
	 */
	private function tablesExist(): bool {
		foreach ( $this->h->tables->all() as $table ) {
			if ( $table !== $this->h->wpdb->get_var( $this->h->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Turn on "delete all data when the plugin is deleted".
	 */
	private function enableDeletion(): void {
		$settings                        = ( new Settings( new \Estasoft\SyncEngine\Core\Support\WpOptionStore() ) )->defaults();
		$settings['delete_on_uninstall'] = true;
		update_option( Settings::OPTION, $settings, false );
	}

	public function test_activation_creates_everything_the_plugin_needs(): void {
		$this->h->sql( "DROP TABLE `{$this->h->tables->reconRuns()}`" ); // phpcs:ignore
		$this->h->sql( "DROP TABLE `{$this->h->tables->logs()}`" ); // phpcs:ignore
		delete_option( Settings::OPTION );
		delete_option( OnboardingState::OPTION );
		Capabilities::revoke();
		Scheduler::unschedule();

		Installer::activate();

		$this->assertTrue( $this->tablesExist() );
		$this->assertSame( Schema::VERSION, get_option( Migrator::OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::CONFIGURE ) );
		$settings = get_option( Settings::OPTION );
		$this->assertSame( 14, $settings['log_retention_days'] );
		$this->assertFalse( $settings['delete_on_uninstall'], 'off by default, so reinstalling keeps the history' );
		$this->assertSame( array( get_option( 'admin_email' ) ), $settings['notifications']['recipients'] );
		$onboarding = get_option( OnboardingState::OPTION );
		$this->assertSame( 1, $onboarding['step'] );
		$this->assertFalse( $onboarding['completed'] );
		$this->assertNotEmpty( get_option( 'estasoft_sync_installed_at' ) );
		foreach ( array( Scheduler::HOOK_RUN, Scheduler::HOOK_REAP, Scheduler::HOOK_PROBE, Scheduler::HOOK_PRUNE, Scheduler::HOOK_PURGE ) as $hook ) {
			$this->assertNotFalse( wp_next_scheduled( $hook ), $hook );
		}
	}

	public function test_only_the_small_version_option_is_autoloaded(): void {
		$rows = $this->h->wpdb->get_results( "SELECT option_name, autoload FROM {$this->h->wpdb->options} WHERE option_name LIKE 'estasoft\\_sync\\_%'", OBJECT_K ); // phpcs:ignore
		$this->assertContains( $rows[ Migrator::OPTION ]->autoload, array( 'yes', 'on', 'auto-on', 'auto' ) );
		foreach ( array( Settings::OPTION, OnboardingState::OPTION, 'estasoft_sync_installed_at' ) as $name ) {
			$this->assertContains( $rows[ $name ]->autoload, array( 'no', 'off', 'auto-off' ), $name );
		}
	}

	public function test_activating_again_keeps_what_the_person_chose(): void {
		$settings                       = get_option( Settings::OPTION );
		$settings['log_retention_days'] = 30;
		update_option( Settings::OPTION, $settings, false );
		( new OnboardingState( new \Estasoft\SyncEngine\Core\Support\WpOptionStore() ) )->save( array( 'step' => 4, 'connector' => 'odoo' ) );
		$id = $this->h->connection();

		Installer::activate();
		Installer::activate();

		$this->assertSame( 30, get_option( Settings::OPTION )['log_retention_days'] );
		$this->assertSame( 4, get_option( OnboardingState::OPTION )['step'] );
		$this->assertNotNull( $this->h->connections->findPublic( $id ), 'data is untouched' );
	}

	public function test_a_single_interactive_activation_asks_for_the_welcome_screen(): void {
		wp_set_current_user( 1 );
		delete_transient( Installer::WELCOME_TRANSIENT );
		Installer::activate();
		$this->assertNotFalse( get_transient( Installer::WELCOME_TRANSIENT ) );
	}

	public function test_no_welcome_screen_for_network_bulk_or_anonymous_activation(): void {
		wp_set_current_user( 1 );
		delete_transient( Installer::WELCOME_TRANSIENT );
		Installer::activate( true );
		$this->assertFalse( get_transient( Installer::WELCOME_TRANSIENT ), 'network-wide' );

		$_GET['activate-multi'] = '1';
		Installer::activate();
		$this->assertFalse( get_transient( Installer::WELCOME_TRANSIENT ), 'activating several plugins at once' );
		unset( $_GET['activate-multi'] );

		wp_set_current_user( 0 );
		Installer::activate();
		$this->assertFalse( get_transient( Installer::WELCOME_TRANSIENT ), 'nobody who may activate plugins' );

		wp_set_current_user( 1 );
		add_filter( 'wp_doing_cron', '__return_true' );
		Installer::activate();
		remove_all_filters( 'wp_doing_cron' );
		$this->assertFalse( get_transient( Installer::WELCOME_TRANSIENT ), 'cron' );
	}

	public function test_deactivation_stops_the_tasks_and_keeps_every_bit_of_data(): void {
		$id = $this->h->connection();
		$this->h->item( $id, '1' );
		Installer::deactivate();

		foreach ( array( Scheduler::HOOK_RUN, Scheduler::HOOK_REAP, Scheduler::HOOK_PROBE, Scheduler::HOOK_PRUNE, Scheduler::HOOK_PURGE ) as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ), $hook );
		}
		$this->assertTrue( $this->tablesExist() );
		$this->assertSame( 1, $this->h->count( $this->h->tables->queue() ) );
		$this->assertNotFalse( get_option( Settings::OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ), 'capabilities stay too' );
	}

	public function test_uninstall_with_the_setting_off_keeps_the_data_and_stops_the_tasks(): void {
		$id = $this->h->connection();
		Installer::uninstall();
		$this->assertTrue( $this->tablesExist() );
		$this->assertNotNull( $this->h->connections->findPublic( $id ) );
		$this->assertNotFalse( get_option( Settings::OPTION ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_RUN ) );
	}

	public function test_uninstall_with_the_setting_on_removes_everything_the_plugin_stored_and_nothing_else(): void {
		$conn = $this->h->connection();
		$this->h->item( $conn, '10490' );
		$this->h->logger->info( 'hello' );
		$this->enableDeletion();
		set_transient( 'estasoft_sync_status_cache', 1, 600 );
		update_option( 'estasoft_sync_rl_abcdef', '1.0', false );
		update_option( 'other_plugin_option', 'keep', false );
		set_transient( 'other_plugin_transient', 'keep', 600 );

		$product = wp_insert_post( array( 'post_title' => 'Bowl', 'post_type' => 'post', 'post_status' => 'publish' ) );
		update_post_meta( $product, '_estasoft_sync_exclude', 'yes' );
		update_post_meta( $product, '_other_meta', 'keep' );
		$order = wp_insert_post( array( 'post_title' => 'Order 10490', 'post_type' => 'shop_order', 'post_status' => 'publish' ) );
		update_post_meta( $order, '_estasoft_sync_state', 'synced' );
		update_post_meta( $order, '_billing_email', 'noor@example.com' );
		update_user_meta( 1, 'estasoft_sync_dismissed_notices', array( 'welcome' ) );
		update_user_meta( 1, 'some_other_user_meta', 'keep' );

		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] . '/estasoft-sync/exports' );
		file_put_contents( $uploads['basedir'] . '/estasoft-sync/exports/orders.csv', 'x' ); // phpcs:ignore
		file_put_contents( $uploads['basedir'] . '/estasoft-sync/index.php', '<?php // Silence.' ); // phpcs:ignore
		wp_mkdir_p( $uploads['basedir'] . '/keep-me' );
		file_put_contents( $uploads['basedir'] . '/keep-me/file.txt', 'x' ); // phpcs:ignore

		try {
			Installer::uninstall();

			$this->assertFalse( $this->tablesExist(), 'tables are dropped' );
			foreach ( $this->h->tables->all() as $table ) {
				$this->assertNull( $this->h->wpdb->get_var( $this->h->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), $table );
			}
			$leftover = $this->h->wpdb->get_col( "SELECT option_name FROM {$this->h->wpdb->options} WHERE option_name LIKE '%estasoft\\_sync\\_%'" ); // phpcs:ignore
			$this->assertSame( array(), $leftover, 'every option and transient of the plugin is gone' );
			$this->assertSame( 'keep', get_option( 'other_plugin_option' ) );
			$this->assertSame( 'keep', get_transient( 'other_plugin_transient' ) );
			$this->assertFalse( wp_next_scheduled( Scheduler::HOOK_RUN ) );
			$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
			$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::CONFIGURE ) );

			$this->assertSame( '', get_post_meta( $product, '_estasoft_sync_exclude', true ) );
			$this->assertSame( 'keep', get_post_meta( $product, '_other_meta', true ) );
			$this->assertSame( '', get_post_meta( $order, '_estasoft_sync_state', true ) );
			$this->assertSame( 'noor@example.com', get_post_meta( $order, '_billing_email', true ), 'the order itself is untouched' );
			$this->assertNotNull( get_post( $order ), 'the order still exists' );
			$this->assertSame( '', get_user_meta( 1, 'estasoft_sync_dismissed_notices', true ) );
			$this->assertSame( 'keep', get_user_meta( 1, 'some_other_user_meta', true ) );

			$this->assertDirectoryDoesNotExist( $uploads['basedir'] . '/estasoft-sync' );
			$this->assertFileExists( $uploads['basedir'] . '/keep-me/file.txt' );
		} finally {
			wp_delete_post( $product, true );
			wp_delete_post( $order, true );
			delete_option( 'other_plugin_option' );
			delete_transient( 'other_plugin_transient' );
			delete_user_meta( 1, 'some_other_user_meta' );
			@unlink( $uploads['basedir'] . '/keep-me/file.txt' ); // phpcs:ignore
			@rmdir( $uploads['basedir'] . '/keep-me' ); // phpcs:ignore
			Installer::installSite();
		}
		$this->assertTrue( $this->tablesExist(), 'the test restored the site' );
	}

	public function test_a_meta_key_added_by_another_module_is_removed_too(): void {
		$this->enableDeletion();
		add_filter(
			'estasoft_sync_meta_keys',
			static function ( array $keys, string $kind ): array {
				return 'product' === $kind ? array_merge( $keys, array( '_estasoft_sync_added_by_woo_module' ) ) : $keys;
			},
			10,
			2
		);
		$post = wp_insert_post( array( 'post_title' => 'P', 'post_type' => 'post', 'post_status' => 'publish' ) );
		update_post_meta( $post, '_estasoft_sync_added_by_woo_module', '1' );
		try {
			Installer::uninstall();
			$this->assertSame( '', get_post_meta( $post, '_estasoft_sync_added_by_woo_module', true ) );
		} finally {
			wp_delete_post( $post, true );
			Installer::installSite();
		}
	}
}
