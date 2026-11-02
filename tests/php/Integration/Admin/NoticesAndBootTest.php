<?php
/**
 * Notices, the welcome redirect, Help, Screen Options and the boot data.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Admin;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Admin\AdminModule;
use Estasoft\SyncEngine\Admin\BootData;
use Estasoft\SyncEngine\Admin\Help;
use Estasoft\SyncEngine\Admin\NoticeFacts;
use Estasoft\SyncEngine\Admin\NoticeRules;
use Estasoft\SyncEngine\Admin\Notices;
use Estasoft\SyncEngine\Admin\ScreenOptions;
use Estasoft\SyncEngine\Admin\Snapshot;
use Estasoft\SyncEngine\Admin\Urls;
use Estasoft\SyncEngine\Admin\Welcome;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\Installer;
use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class NoticesAndBootTest extends RestIntegrationCase {

	private Urls $urls;

	/**
	 * Filters this test added: name and callback, taken off again in tearDown().
	 *
	 * @var array<int,array{0:string,1:callable}>
	 */
	private array $added = array();

	/**
	 * Add a filter that is removed after the test (and nobody else's).
	 *
	 * @param string   $name     Hook.
	 * @param callable $callback Callback.
	 */
	private function hook( string $name, callable $callback ): void {
		add_filter( $name, $callback );
		$this->added[] = array( $name, $callback );
	}

	/**
	 * Remember the locations that wp_redirect() was asked for, and stop it from sending a header.
	 *
	 * @return \ArrayObject<int,string>
	 */
	private function redirects(): \ArrayObject {
		$seen = new \ArrayObject();
		$this->hook(
			'wp_redirect',
			static function ( $location ) use ( $seen ) {
				$seen[] = $location;
				return false;
			}
		);
		return $seen;
	}

	protected function setUp(): void {
		parent::setUp();
		$this->urls = new Urls( admin_url( 'admin.php?page=estasoft-sync' ) );
		delete_option( Settings::OPTION );
		delete_option( OnboardingState::OPTION );
		delete_transient( 'estasoft_sync_admin_snapshot' );
		delete_transient( Installer::WELCOME_TRANSIENT );
		$this->actAs( 'administrator' );
	}

	protected function tearDown(): void {
		foreach ( $this->added as $filter ) {
			remove_filter( $filter[0], $filter[1] );
		}
		$this->added = array();
		delete_option( Settings::OPTION );
		delete_option( OnboardingState::OPTION );
		delete_transient( 'estasoft_sync_admin_snapshot' );
		delete_transient( Installer::WELCOME_TRANSIENT );
		delete_user_meta( $this->userId( 'administrator' ), NoticeFacts::DISMISSED_META );
		unset( $_GET['notice'], $_GET['activate-multi'], $_REQUEST['_wpnonce'], $_GET['_wpnonce'] );
		parent::tearDown();
	}

	/**
	 * Notices built over the container's services, with or without a key and on or off the plugin page.
	 *
	 * @param bool $onPluginPage Whether the current screen is the plugin's own page.
	 */
	private function notices( bool $onPluginPage = false ): Notices {
		return new Notices( $this->facts(), new NoticeRules(), $this->urls, static fn(): bool => $onPluginPage );
	}

	private function facts(): NoticeFacts {
		$snapshot = new Snapshot( $this->container->get( Services::QUEUE_REPOSITORY ), $this->container->get( Services::CONNECTION_REPOSITORY ), $this->container->get( Services::ONBOARDING ), $this->container->get( Services::CLOCK ), $this->container->get( 'rest.store' ), 0 );
		return new NoticeFacts( $snapshot, $this->container->get( Services::SECRET_BOX ), $this->container->get( Services::SETTINGS ), $this->container->get( Services::ONBOARDING ), $this->container->get( Services::CLOCK ) );
	}

	/**
	 * Print the notices and return the HTML.
	 *
	 * @param Notices $notices Notices.
	 */
	private function printed( Notices $notices ): string {
		ob_start();
		$notices->render();
		return (string) ob_get_clean();
	}

	public function test_the_welcome_notice_shows_to_an_administrator_until_setup_is_done_and_offers_two_buttons(): void {
		$html = $this->printed( $this->notices() );
		$this->assertStringContainsString( 'notice notice-info', $html );
		$this->assertStringContainsString( 'ESTASOFT SYNC is active. Set it up in about ten minutes. Nothing is sent to your system until you say so.', $html );
		$this->assertStringContainsString( 'href="' . esc_url( $this->urls->setup() ) . '"', $html );
		$this->assertStringContainsString( 'Start setup', $html );
		$this->assertStringContainsString( 'Not now', $html );
		$this->assertStringContainsString( 'action=estasoft_sync_dismiss_notice&#038;notice=welcome', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
	}

	public function test_the_welcome_notice_is_for_administrators_not_shop_managers(): void {
		$this->actAs( 'shop_manager' );
		$this->assertStringNotContainsString( 'is active', $this->printed( $this->notices() ) );
		$this->actAs( 'subscriber' );
		$this->assertSame( '', $this->printed( $this->notices() ) );
	}

	public function test_the_welcome_notice_goes_away_when_a_system_is_connected_or_setup_is_finished(): void {
		$this->h->connection();
		$this->assertStringNotContainsString( 'is active', $this->printed( $this->notices() ) );
		$this->h->reset();
		$this->container->get( Services::ONBOARDING )->save( array( 'completed' => true ) );
		$this->assertStringNotContainsString( 'is active', $this->printed( $this->notices() ) );
	}

	public function test_dismissing_the_welcome_notice_needs_the_nonce_and_is_saved_for_the_person_for_good(): void {
		$notices  = $this->notices();
		$captured = $this->redirects();
		$_GET['notice']       = 'welcome';
		$_REQUEST['_wpnonce'] = 'wrong';
		$this->hook(
			'wp_die_handler',
			static fn() => static function (): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		try {
			$notices->dismiss();
			$this->fail( 'a wrong nonce must stop the request' );
		} catch ( \RuntimeException $stopped ) {
			$this->assertSame( 'wp_die', $stopped->getMessage() );
		}
		$this->assertSame( array(), $this->facts()->dismissed( $this->userId( 'administrator' ) ) );

		$_REQUEST['_wpnonce'] = wp_create_nonce( Notices::DISMISS_ACTION . '_welcome' );
		$notices->dismiss();
		$this->assertSame( array( 'welcome' ), $this->facts()->dismissed( $this->userId( 'administrator' ) ) );
		$this->assertCount( 1, $captured, 'the person is sent back' );
		$this->assertStringNotContainsString( 'is active', $this->printed( $this->notices() ), 'gone for good' );
	}

	public function test_no_other_notice_can_be_dismissed_for_good(): void {
		$notices = $this->notices();
		$this->redirects();
		$_GET['notice']       = 'encryption_key';
		$_REQUEST['_wpnonce'] = wp_create_nonce( Notices::DISMISS_ACTION . '_encryption_key' );
		$notices->dismiss();
		$this->assertSame( array(), $this->facts()->dismissed( $this->userId( 'administrator' ) ) );
		foreach ( ( new NoticeRules() )->build( array( 'can_manage' => true, 'can_configure' => true, 'key_missing' => true, 'key_line' => 'x', 'reconnect' => array( array( 'id' => 1, 'label' => 'Odoo' ) ), 'failed' => 3, 'waiting' => 500, 'backlog_threshold' => 200, 'shadow_ends_soon' => true ) ) as $notice ) {
			$this->assertFalse( $notice['dismissible'] && 'welcome' !== $notice['id'], $notice['id'] );
		}
	}

	public function test_a_missing_encryption_key_shows_a_fresh_line_to_paste_to_administrators_only(): void {
		$this->container->instance( Services::SECRET_BOX, new SecretBox( null ) );
		$this->container->get( Services::ONBOARDING )->save( array( 'completed' => true ) ); // No welcome notice, so any dismiss button would belong to the key notice.
		$html = $this->printed( $this->notices() );
		$this->assertStringContainsString( 'The encryption key is missing from wp-config.php.', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertMatchesRegularExpression( "/<code>define\\( &#039;ESTASOFT_SYNC_KEY&#039;, &#039;[0-9a-f]{64}&#039; \\);<\\/code>/", $html );
		$this->assertStringContainsString( 'Show me how', $html );
		$this->assertStringNotContainsString( 'Not now', $html, 'this one cannot be dismissed' );

		$this->actAs( 'shop_manager' );
		$this->assertStringNotContainsString( 'encryption key', $this->printed( $this->notices() ) );
	}

	public function test_a_connection_that_needs_the_sign_in_again_is_shown_to_people_who_view_the_queue_with_its_name_escaped(): void {
		$id = $this->h->connection( array(), array( 'api_key' => 'sk-test-abcdefghijklmnop' ), array( 'label' => '<b>Odoo</b>' ) );
		$this->h->connections->update( $id, array( 'label' => '<b>Odoo</b>' ) );
		$this->h->connections->setStatus( $id, ConnectionStatus::NeedsReconnect );
		$this->actAs( 'shop_manager' );
		$html = $this->printed( $this->notices() );
		$this->assertStringContainsString( 'Syncing to &lt;b&gt;Odoo&lt;/b&gt; is paused because its sign-in no longer works.', $html );
		$this->assertStringContainsString( "#/connections/$id", $html );
		$this->assertStringContainsString( 'Reconnect &lt;b&gt;Odoo&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>Odoo</b>', $html );
	}

	public function test_failed_orders_and_backlog_are_for_the_app_not_for_other_screens(): void {
		$conn = $this->h->connection();
		$this->h->queue->markFailed( $this->h->item( $conn, '1' ), 'SYNC-MAP-001', 'x', 'SKU A' );
		$this->container->get( Services::SETTINGS )->update( array( 'backlog_threshold' => 10 ) );
		for ( $i = 2; $i <= 14; ++$i ) {
			$this->h->item( $conn, (string) $i );
		}
		$this->container->get( Services::ONBOARDING )->save( array( 'completed' => true ) );
		$this->assertSame( '', $this->printed( $this->notices() ), 'not printed by PHP' );

		$app = array_column( ( new NoticeRules() )->forApp( $this->facts()->gather( $this->userId( 'administrator' ) ) ), null, 'id' );
		$this->assertSame( 'warning', $app['failed_orders']['kind'] );
		$this->assertSame( '1 order failed to sync. It needs a person.', $app['failed_orders']['text'] );
		$this->assertSame( '/queue?state=failed', $app['failed_orders']['action_route'] );
		$this->assertStringContainsString( '13 items are waiting. That is more than the limit of 10.', $app['backlog']['text'] );
	}

	public function test_nothing_is_printed_on_the_plugin_page_itself(): void {
		$this->assertSame( '', $this->printed( $this->notices( true ) ) );
	}

	public function test_the_welcome_redirect_goes_to_the_setup_guide_once_for_an_administrator(): void {
		$welcome  = new Welcome( $this->urls );
		$captured = $this->redirects();
		$welcome->maybeRedirect();
		$this->assertCount( 0, $captured, 'no flag, no redirect' );

		set_transient( Installer::WELCOME_TRANSIENT, 1, 60 );
		$welcome->maybeRedirect();
		$this->assertSame( array( $this->urls->setup() ), $captured->getArrayCopy() );
		$welcome->maybeRedirect();
		$this->assertCount( 1, $captured, 'the flag is used up' );
	}

	public function test_the_welcome_redirect_never_happens_for_bulk_ajax_cron_network_or_non_administrators(): void {
		$welcome  = new Welcome( $this->urls );
		$captured = $this->redirects();

		set_transient( Installer::WELCOME_TRANSIENT, 1, 60 );
		$_GET['activate-multi'] = 'true';
		$welcome->maybeRedirect();
		unset( $_GET['activate-multi'] );

		$this->hook( 'wp_doing_ajax', '__return_true' );
		$welcome->maybeRedirect();
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->hook( 'wp_doing_cron', '__return_true' );
		$welcome->maybeRedirect();
		remove_filter( 'wp_doing_cron', '__return_true' );

		$this->actAs( 'shop_manager' );
		$welcome->maybeRedirect();
		$this->actAs( 'anonymous' );
		set_transient( Installer::WELCOME_TRANSIENT, 1, 60 );
		$welcome->maybeRedirect();
		$this->assertCount( 0, $captured );
	}

	public function test_help_has_five_tabs_and_a_sidebar_with_the_four_links(): void {
		set_current_screen( 'woocommerce_page_estasoft-sync' );
		( new Help( $this->urls ) )->load();
		$screen = get_current_screen();
		$tabs   = $screen->get_help_tabs();
		$this->assertSame( array( 'The queue', 'Sync states', 'Fix a failed order', 'Retries and pausing', 'Getting help' ), array_column( $tabs, 'title' ) );
		$this->assertStringContainsString( 'checkout never waits', $tabs['estasoft-sync-queue']['content'] );
		foreach ( array( 'Queued', 'Syncing', 'Synced', 'Retrying', 'Failed', 'Skipped' ) as $state ) {
			$this->assertStringContainsString( "<strong>$state</strong>", $tabs['estasoft-sync-states']['content'] );
		}
		$sidebar = $screen->get_help_sidebar();
		foreach ( array( 'Read the documentation', 'Ask in the support forum', 'Open an issue on GitHub', 'Copy a support report' ) as $link ) {
			$this->assertStringContainsString( $link, $sidebar );
		}
		$this->assertStringContainsString( 'https://wordpress.org/support/plugin/estasoft-erp-crm-sync-engine/', $sidebar );
		$this->assertStringContainsString( '#/logs', $sidebar );
		$this->assertSame( 4, substr_count( $sidebar, 'href=' ) );
		$this->assertSame( 3, substr_count( $sidebar, 'rel="noopener noreferrer"' ), 'outside links open safely' );
	}

	public function test_screen_options_save_the_nearest_allowed_page_size_per_person(): void {
		$options = new ScreenOptions();
		$options->register();
		$this->assertSame( 25, apply_filters( 'set_screen_option_' . ScreenOptions::OPTION, false, ScreenOptions::OPTION, '30' ) );
		$this->assertSame( 50, apply_filters( 'set_screen_option_' . ScreenOptions::OPTION, false, ScreenOptions::OPTION, '60' ) );
		$this->assertSame( 100, apply_filters( 'set_screen_option_' . ScreenOptions::OPTION, false, ScreenOptions::OPTION, '999' ) );
		$this->assertSame( 25, apply_filters( 'set_screen_option_' . ScreenOptions::OPTION, false, ScreenOptions::OPTION, 'abc' ) );
		remove_all_filters( 'set_screen_option_' . ScreenOptions::OPTION );

		$this->assertSame( 25, $options->perPage( $this->userId( 'shop_manager' ) ) );
		update_user_meta( $this->userId( 'shop_manager' ), ScreenOptions::OPTION, 100 );
		$this->assertSame( 100, $options->perPage( $this->userId( 'shop_manager' ) ) );
		update_user_meta( $this->userId( 'shop_manager' ), ScreenOptions::OPTION, 7 );
		$this->assertSame( 25, $options->perPage( $this->userId( 'shop_manager' ) ), 'a stored value that is not offered falls back' );
		delete_user_meta( $this->userId( 'shop_manager' ), ScreenOptions::OPTION );

		set_current_screen( 'woocommerce_page_estasoft-sync' );
		$options->load();
		$this->assertSame( ScreenOptions::OPTION, get_current_screen()->get_option( 'per_page', 'option' ) );
	}

	/**
	 * The boot data of the container's own services.
	 *
	 * @return array<string,mixed>
	 */
	private function boot(): array {
		return $this->container->get( AdminModule::BOOT_DATA )->build();
	}

	public function test_the_boot_data_has_every_field_of_the_contract_with_the_right_types(): void {
		delete_transient( 'estasoft_sync_admin_snapshot' );
		$boot = $this->boot();
		foreach ( array( 'restUrl', 'nonce', 'adminUrl', 'pluginUrl', 'version', 'capabilities', 'storeTimezone', 'currency', 'currencyDecimals', 'locale', 'wpAdminColorScheme', 'routes', 'notices', 'encryptionKeyLine' ) as $key ) {
			$this->assertArrayHasKey( $key, $boot, $key );
		}
		$this->assertSame( rest_url( 'estasoft-sync/v1/' ), $boot['restUrl'] );
		$this->assertSame( 1, wp_verify_nonce( $boot['nonce'], 'wp_rest' ), 'the REST nonce' );
		$this->assertSame( admin_url(), $boot['adminUrl'] );
		$this->assertSame( array( 'manage' => true, 'configure' => true ), $boot['capabilities'] );
		$this->assertIsInt( $boot['currencyDecimals'] );
		$this->assertContains( '/queue', $boot['routes'] );
		$this->assertContains( '/setup', $boot['routes'] );
		$this->assertIsString( $boot['locale'] );
		$this->assertSame( 'fresh', $boot['wpAdminColorScheme'] );
		$this->assertNull( $boot['encryptionKeyLine'], 'the key constant is defined' );
		$this->assertSame( 25, $boot['perPage'] );
	}

	public function test_the_boot_data_reports_what_this_person_may_do(): void {
		delete_transient( 'estasoft_sync_admin_snapshot' );
		$this->actAs( 'shop_manager' );
		$this->assertSame( array( 'manage' => true, 'configure' => false ), $this->boot()['capabilities'] );
	}

	public function test_the_boot_data_carries_the_key_line_when_the_key_is_missing_and_notices_without_the_welcome_one(): void {
		$this->container->instance( Services::SECRET_BOX, new SecretBox( null ) );
		delete_transient( 'estasoft_sync_admin_snapshot' );
		$boot = $this->boot();
		$this->assertMatchesRegularExpression( "/^define\\( 'ESTASOFT_SYNC_KEY', '[0-9a-f]{64}' \\);$/", $boot['encryptionKeyLine'] );
		$ids = array_column( $boot['notices'], 'id' );
		$this->assertContains( 'encryption_key', $ids );
		$this->assertNotContains( 'welcome', $ids, 'the setup guide is the welcome inside the app' );
		foreach ( $boot['notices'] as $notice ) {
			$this->assertSame( array( 'id', 'kind', 'text', 'action_label', 'action_route', 'dismissible' ), array_keys( $notice ) );
			$this->assertStringNotContainsString( 'define(', $notice['text'], 'the line is delivered separately' );
		}
	}

	public function test_the_boot_data_stays_inside_a_script_element(): void {
		delete_transient( 'estasoft_sync_admin_snapshot' );
		$id = $this->h->connection();
		$this->h->connections->update( $id, array( 'label' => '</script><script>alert(1)</script>' ) );
		$this->h->connections->setStatus( $id, ConnectionStatus::NeedsReconnect );
		$encoded = \Estasoft\SyncEngine\Admin\Assets::encode( $this->boot() );
		$this->assertStringNotContainsString( '</script>', $encoded );
		$this->assertStringNotContainsString( '<', $encoded );
		$this->assertStringContainsString( '\u003C', $encoded );
		$decoded = json_decode( $encoded, true );
		$this->assertStringContainsString( '</script><script>alert(1)</script>', $decoded['notices'][0]['text'], 'the text itself is unchanged after decoding' );
	}

	public function test_the_admin_module_adds_every_hook_and_the_menu_calls_back_for_help_and_screen_options(): void {
		global $wp_filter;
		$names = array( 'admin_menu', 'admin_enqueue_scripts', 'admin_bar_menu', 'wp_dashboard_setup', 'admin_notices', 'admin_post_' . Notices::DISMISS_ACTION, 'plugin_action_links_' . plugin_basename( ESTASOFT_SYNC_FILE ), 'admin_init', 'set_screen_option_' . ScreenOptions::OPTION );
		$saved = array();
		foreach ( $names as $name ) {
			$saved[ $name ] = isset( $wp_filter[ $name ] ) ? clone $wp_filter[ $name ] : null;
			unset( $wp_filter[ $name ] );
		}
		( new AdminModule() )->addAdminHooks( $this->container );
		foreach ( $names as $name ) {
			$this->assertTrue( has_filter( $name ), "$name has a callback" );
		}
		foreach ( $saved as $name => $hook ) {
			if ( null === $hook ) {
				unset( $wp_filter[ $name ] );
			} else {
				$wp_filter[ $name ] = $hook;
			}
		}
	}

	public function test_the_module_adds_no_admin_hooks_on_a_front_end_request(): void {
		global $wp_filter;
		$before = isset( $wp_filter['admin_bar_menu'] ) ? clone $wp_filter['admin_bar_menu'] : null;
		unset( $wp_filter['admin_bar_menu'] );
		unset( $GLOBALS['current_screen'] );
		( new AdminModule() )->hooks( $this->container );
		$this->assertFalse( has_filter( 'admin_bar_menu' ) );
		if ( null !== $before ) {
			$wp_filter['admin_bar_menu'] = $before;
		}
	}
}
