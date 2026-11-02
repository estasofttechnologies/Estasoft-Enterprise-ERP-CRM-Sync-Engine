<?php
/**
 * The menu entry, the page, the admin bar item, the dashboard box and the Plugins screen links.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Admin;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Admin\AdminBar;
use Estasoft\SyncEngine\Admin\AdminModule;
use Estasoft\SyncEngine\Admin\Assets;
use Estasoft\SyncEngine\Admin\DashboardWidget;
use Estasoft\SyncEngine\Admin\Menu;
use Estasoft\SyncEngine\Admin\PluginLinks;
use Estasoft\SyncEngine\Admin\Snapshot;
use Estasoft\SyncEngine\Admin\Urls;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class AdminChromeTest extends RestIntegrationCase {

	private Urls $urls;

	protected function setUp(): void {
		parent::setUp();
		$this->urls = new Urls( admin_url( 'admin.php?page=estasoft-sync' ) );
		delete_option( Settings::OPTION );
		delete_transient( 'estasoft_sync_admin_snapshot' );
		$this->actAs( 'administrator' );
	}

	protected function tearDown(): void {
		delete_option( Settings::OPTION );
		delete_transient( 'estasoft_sync_admin_snapshot' );
		parent::tearDown();
	}

	/**
	 * A folder that looks like the plugin folder with or without the compiled app.
	 *
	 * @param bool $withBundle Whether build/index.js exists.
	 */
	private function pluginDir( bool $withBundle ): string {
		$dir = sys_get_temp_dir() . '/estasoft-rest-plugin-' . wp_generate_password( 8, false ) . '/';
		mkdir( $dir . 'build', 0777, true );
		if ( $withBundle ) {
			file_put_contents( $dir . 'build/index.js', '// app' );
			file_put_contents( $dir . 'build/index.css', '/* app */' );
			file_put_contents( $dir . 'build/index.asset.php', "<?php return array( 'dependencies' => array( 'wp-element', 'wp-i18n' ), 'version' => 'abc123' );" );
		}
		return $dir;
	}

	private function menu( bool $withBundle, int $failed ): Menu {
		$assets = new Assets( $this->pluginDir( $withBundle ), 'https://example.test/wp-content/plugins/x/', '9.9.9', static fn(): string => '', static fn(): array => array(), static fn(): bool => false );
		return new Menu( $assets, static fn(): int => $failed );
	}

	/**
	 * Capture the output of a callable.
	 *
	 * @param callable $render Prints something.
	 */
	private function output( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	public function test_the_menu_is_a_woocommerce_submenu_with_the_manage_capability_and_a_bubble_for_failed_orders(): void {
		global $submenu;
		add_menu_page( 'WooCommerce', 'WooCommerce', 'manage_woocommerce', 'woocommerce' );
		$menu = $this->menu( true, 5 );
		$menu->addPage();
		$entry = array_values( array_filter( (array) ( $submenu['woocommerce'] ?? array() ), static fn( array $item ): bool => 'estasoft-sync' === $item[2] ) );
		$this->assertCount( 1, $entry );
		$this->assertSame( 'estasoft_sync_manage', $entry[0][1] );
		$this->assertStringContainsString( 'ESTASOFT SYNC', $entry[0][0] );
		$this->assertStringContainsString( 'plugin-count', $entry[0][0] );
		$this->assertStringContainsString( '>5<', $entry[0][0] );
		$this->assertStringContainsString( '5 failed orders', $entry[0][0] );
		$this->assertSame( 'woocommerce_page_estasoft-sync', $menu->hookSuffix() );
		remove_submenu_page( 'woocommerce', 'estasoft-sync' );
		remove_menu_page( 'woocommerce' );
	}

	public function test_the_menu_entry_follows_the_rule_of_the_routes_so_a_person_who_may_only_configure_still_sees_the_page(): void {
		global $submenu;
		$menu = $this->menu( true, 0 );
		$this->assertSame( 'estasoft_sync_manage', $this->capabilityFor( $menu, 'administrator' ), 'administrators hold both' );
		$this->assertSame( 'estasoft_sync_manage', $this->capabilityFor( $menu, 'shop_manager' ) );
		$this->assertSame( 'estasoft_sync_manage', $this->capabilityFor( $menu, 'subscriber' ), 'no access either way; the entry stays hidden' );
		$this->assertSame( 'estasoft_sync_manage', $this->capabilityFor( $menu, 'anonymous' ) );

		// A role that was given only the configure capability, as an agency might build one.
		$user = new \WP_User( $this->userId( 'subscriber' ) );
		$user->add_cap( 'estasoft_sync_configure' );
		try {
			$this->actAs( 'subscriber' );
			$this->assertFalse( current_user_can( 'estasoft_sync_manage' ) );
			$this->assertSame( 'estasoft_sync_configure', $menu->capability() );
			$this->assertSame( 200, $this->call( 'GET', '/status' )->get_status(), 'the routes let this person view' );

			add_menu_page( 'WooCommerce', 'WooCommerce', 'manage_woocommerce', 'woocommerce' );
			$menu->addPage();
			$entry = array_values( array_filter( (array) ( $submenu['woocommerce'] ?? array() ), static fn( array $item ): bool => 'estasoft-sync' === $item[2] ) );
			$this->assertCount( 1, $entry );
			$this->assertTrue( current_user_can( $entry[0][1] ), 'WordPress would show the entry to this person' );
			remove_submenu_page( 'woocommerce', 'estasoft-sync' );
			remove_menu_page( 'woocommerce' );
		} finally {
			$user->remove_cap( 'estasoft_sync_configure' );
		}
	}

	/**
	 * The capability the menu entry would be registered with for a kind of person.
	 *
	 * @param Menu   $menu Menu.
	 * @param string $kind administrator, shop_manager, subscriber or anonymous.
	 */
	private function capabilityFor( Menu $menu, string $kind ): string {
		$this->actAs( $kind );
		return $menu->capability();
	}

	public function test_no_bubble_when_nothing_failed_and_a_singular_label_for_one(): void {
		$this->assertSame( '', $this->menu( true, 0 )->bubble() );
		$this->assertStringContainsString( '1 failed order<', $this->menu( true, 1 )->bubble() );
		$this->assertStringNotContainsString( 'orders', $this->menu( true, 1 )->bubble() );
	}

	public function test_the_page_is_the_mount_point_with_a_message_for_people_without_javascript(): void {
		$html = $this->output( array( $this->menu( true, 0 ), 'render' ) );
		$this->assertStringContainsString( '<div id="estasoft-sync-root" class="wrap estasoft-sync">', $html );
		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertStringContainsString( 'needs JavaScript', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( '<h1 class="screen-reader-text">ESTASOFT SYNC</h1>', $html );
	}

	public function test_a_missing_bundle_shows_the_build_command_to_an_administrator_not_a_blank_page(): void {
		$html = $this->output( array( $this->menu( false, 0 ), 'render' ) );
		$this->assertStringContainsString( 'has not been built', $html );
		$this->assertStringContainsString( '<code>npm install &amp;&amp; npm run build</code>', $html );
		$this->assertStringNotContainsString( '<noscript>', $html );

		$this->actAs( 'shop_manager' );
		$other = $this->output( array( $this->menu( false, 0 ), 'render' ) );
		$this->assertStringContainsString( 'Ask an administrator', $other );
		$this->assertStringNotContainsString( 'npm', $other );
	}

	public function test_the_app_loads_only_on_its_own_page_with_the_boot_data_before_it(): void {
		$dir    = $this->pluginDir( true );
		$boot   = array( 'restUrl' => 'https://example.test/wp-json/estasoft-sync/v1/', 'note' => 'a </script><script>alert(1)</script> & "quote"' );
		$assets = new Assets( $dir, 'https://example.test/p/', '9.9.9', static fn(): string => 'woocommerce_page_estasoft-sync', static fn(): array => $boot, static fn(): bool => false );

		$assets->enqueue( 'plugins.php' );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ), 'not on other screens' );

		$assets->enqueue( 'woocommerce_page_estasoft-sync' );
		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::HANDLE, 'enqueued' ) );
		$script = wp_scripts()->registered[ Assets::HANDLE ];
		$this->assertSame( 'https://example.test/p/build/index.js', $script->src );
		$this->assertSame( array( 'wp-element', 'wp-i18n' ), $script->deps, 'dependencies from index.asset.php' );
		$this->assertSame( 'abc123', $script->ver, 'version from index.asset.php' );
		$this->assertSame( 1, $script->extra['group'], 'in the footer' );
		$this->assertSame( 'estasoft-erp-crm-sync-engine', $script->textdomain );

		$before = wp_scripts()->get_data( Assets::HANDLE, 'before' );
		$inline = implode( '', (array) $before );
		$this->assertStringStartsWith( 'window.estasoftSync = {', $inline );
		$this->assertStringNotContainsString( '</script>', $inline );
		$this->assertStringNotContainsString( '<script>', $inline );
		$json = substr( $inline, strlen( 'window.estasoftSync = ' ), -1 );
		$this->assertSame( $boot, json_decode( $json, true ), 'the escapes decode back to the same text' );

		$this->assertSame( 'replace', wp_styles()->get_data( Assets::HANDLE, 'rtl' ) );
		wp_dequeue_script( Assets::HANDLE );
		wp_dequeue_style( Assets::HANDLE );
		wp_deregister_script( Assets::HANDLE );
	}

	public function test_nothing_is_enqueued_when_the_bundle_is_missing(): void {
		$assets = new Assets( $this->pluginDir( false ), 'https://example.test/p/', '9.9.9', static fn(): string => 'woocommerce_page_estasoft-sync', static fn(): array => array(), static fn(): bool => false );
		$assets->enqueue( 'woocommerce_page_estasoft-sync' );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::HANDLE, 'registered' ) );
	}

	public function test_the_small_stylesheet_loads_where_the_admin_bar_item_or_the_dashboard_box_shows(): void {
		$bar = new Assets( $this->pluginDir( true ), 'https://example.test/p/', '9.9.9', static fn(): string => 'x', static fn(): array => array(), static fn(): bool => true );
		$bar->enqueue( 'edit.php' );
		$this->assertTrue( wp_style_is( Assets::CHROME_HANDLE, 'enqueued' ) );
		$this->assertSame( 'https://example.test/p/assets/css/admin-chrome.css', wp_styles()->registered[ Assets::CHROME_HANDLE ]->src );
		wp_dequeue_style( Assets::CHROME_HANDLE );
		wp_deregister_style( Assets::CHROME_HANDLE );

		$none = new Assets( $this->pluginDir( true ), 'https://example.test/p/', '9.9.9', static fn(): string => 'x', static fn(): array => array(), static fn(): bool => false );
		$none->enqueue( 'edit.php' );
		$this->assertFalse( wp_style_is( Assets::CHROME_HANDLE, 'enqueued' ) );
		$none->enqueue( 'index.php' );
		$this->assertTrue( wp_style_is( Assets::CHROME_HANDLE, 'enqueued' ), 'the WordPress dashboard shows the box' );
		wp_dequeue_style( Assets::CHROME_HANDLE );
		wp_deregister_style( Assets::CHROME_HANDLE );
	}

	public function test_the_stylesheet_file_exists_and_has_the_classes_the_markup_uses(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 4 ) . '/assets/css/admin-chrome.css' );
		foreach ( array( 'estasoft-sync-dot--ok', 'estasoft-sync-dot--waiting', 'estasoft-sync-dot--failed', 'estasoft-sync-dot--paused', 'estasoft-sync-dot--idle', 'estasoft-sync-widget__number', 'estasoft-sync-widget--attention' ) as $class ) {
			$this->assertStringContainsString( $class, $css );
		}
	}

	/**
	 * An admin bar item built over the container's repositories with no caching.
	 */
	private function bar(): AdminBar {
		$snapshot = new Snapshot(
			$this->container->get( Services::QUEUE_REPOSITORY ),
			$this->container->get( Services::CONNECTION_REPOSITORY ),
			$this->container->get( Services::ONBOARDING ),
			$this->container->get( Services::CLOCK ),
			$this->container->get( 'rest.store' ),
			0
		);
		return new AdminBar( $snapshot, $this->container->get( Services::SETTINGS ), $this->urls );
	}

	private function nodeOf( AdminBar $bar ): ?array {
		set_current_screen( 'dashboard' );
		$adminBar = new \WP_Admin_Bar();
		$bar->addNode( $adminBar );
		$node = $adminBar->get_node( AdminBar::NODE_ID );
		return null === $node ? null : (array) $node;
	}

	public function test_the_admin_bar_says_not_connected_in_grey_before_a_system_is_connected(): void {
		$node = $this->nodeOf( $this->bar() );
		$this->assertNotNull( $node );
		$this->assertStringContainsString( 'ESTASOFT SYNC: not connected', $node['title'] );
		$this->assertStringContainsString( 'estasoft-sync-dot--idle', $node['title'] );
		$this->assertStringContainsString( 'Not connected.', $node['title'] );
	}

	public function test_the_admin_bar_counts_waiting_and_failed_orders_with_a_dot_and_a_word(): void {
		$conn = $this->h->connection();
		$this->h->item( $conn, '1' );
		$this->h->item( $conn, '2' );
		$this->assertStringContainsString( 'ESTASOFT SYNC: 2 waiting<', $this->nodeOf( $this->bar() )['title'] );
		$this->assertStringContainsString( 'estasoft-sync-dot--waiting', $this->nodeOf( $this->bar() )['title'] );

		$this->h->queue->markFailed( $this->h->item( $conn, '3' ), 'SYNC-MAP-001', 'x', 'SKU A' );
		$node = $this->nodeOf( $this->bar() );
		$this->assertStringContainsString( 'ESTASOFT SYNC: 2 waiting, 1 failed', $node['title'] );
		$this->assertStringContainsString( 'estasoft-sync-dot--failed', $node['title'] );
		$this->assertStringContainsString( 'Some orders need a person.', $node['title'] );
		$this->assertStringEndsWith( '#/queue?state=failed', $node['href'] );
		$this->assertSame( 'Some orders need a person.', $node['meta']['title'] );
	}

	public function test_the_admin_bar_is_red_and_says_paused_when_a_connection_is_paused(): void {
		$conn = $this->h->connection();
		$this->h->item( $conn, '1' );
		$this->h->connections->setStatus( $conn, ConnectionStatus::Paused );
		$node = $this->nodeOf( $this->bar() );
		$this->assertStringContainsString( 'ESTASOFT SYNC: paused, 1 waiting', $node['title'] );
		$this->assertStringContainsString( 'estasoft-sync-dot--paused', $node['title'] );
		$this->assertStringContainsString( 'Syncing is paused.', $node['title'] );
	}

	public function test_all_clear_is_green(): void {
		$this->h->connection();
		$node = $this->nodeOf( $this->bar() );
		$this->assertStringContainsString( 'ESTASOFT SYNC: 0 waiting', $node['title'] );
		$this->assertStringContainsString( 'estasoft-sync-dot--ok', $node['title'] );
		$this->assertStringEndsWith( '#/queue', $node['href'] );
	}

	public function test_the_admin_bar_can_be_hidden_in_settings_and_is_not_for_people_who_cannot_view_the_queue(): void {
		$this->container->get( Services::SETTINGS )->update( array( 'admin_bar' => false ) );
		$this->assertNull( $this->nodeOf( $this->bar() ) );
		delete_option( Settings::OPTION );

		$this->actAs( 'subscriber' );
		$this->assertNull( $this->nodeOf( $this->bar() ) );
		$this->actAs( 'shop_manager' );
		$this->assertNotNull( $this->nodeOf( $this->bar() ) );
	}

	public function test_the_admin_bar_item_is_not_added_outside_the_admin(): void {
		unset( $GLOBALS['current_screen'] );
		$this->assertFalse( is_admin() );
		$adminBar = new \WP_Admin_Bar();
		$this->bar()->addNode( $adminBar );
		$this->assertNull( $adminBar->get_node( AdminBar::NODE_ID ) );
	}

	public function test_the_admin_bar_reads_the_queue_once_in_30_seconds(): void {
		$conn     = $this->h->connection();
		$snapshot = new Snapshot( $this->container->get( Services::QUEUE_REPOSITORY ), $this->container->get( Services::CONNECTION_REPOSITORY ), $this->container->get( Services::ONBOARDING ), $this->container->get( Services::CLOCK ), $this->container->get( 'rest.store' ) );
		$this->assertSame( 0, $snapshot->current()['waiting'] );
		$this->h->item( $conn, '1' );
		$this->assertSame( 0, $snapshot->current()['waiting'], 'the second read is the kept one' );
		delete_transient( 'estasoft_sync_admin_snapshot' );
		$this->assertSame( 1, $snapshot->current()['waiting'] );
	}

	public function test_the_dashboard_box_is_added_for_people_who_can_view_the_queue_only(): void {
		$widget = new DashboardWidget( static fn(): array => array(), $this->urls, static fn( string $iso ): string => $iso );
		global $wp_meta_boxes;
		set_current_screen( 'dashboard' );
		$wp_meta_boxes = array();
		$this->actAs( 'subscriber' );
		$widget->add();
		$this->assertArrayNotHasKey( DashboardWidget::ID, (array) ( $wp_meta_boxes['dashboard']['normal']['core'] ?? array() ) );
		$this->actAs( 'shop_manager' );
		$widget->add();
		$this->assertArrayHasKey( DashboardWidget::ID, (array) $wp_meta_boxes['dashboard']['normal']['core'] );
		$wp_meta_boxes = array();
	}

	public function test_the_dashboard_box_shows_the_five_states_of_board_a05(): void {
		$time   = static fn( string $iso ): string => gmdate( 'H:i', (int) strtotime( $iso ) );
		$widget = static fn( array $data ): DashboardWidget => new DashboardWidget( static fn(): array => $data, new Urls( 'https://example.test/wp-admin/admin.php?page=estasoft-sync' ), $time );
		$render = fn( array $data ): string => $this->output( array( $widget( $data ), 'render' ) );
		$odoo   = array( 'label' => 'Odoo', 'status' => 'active', 'last_success_at' => '2026-11-02T11:39:00Z', 'paused_until' => null );

		$attention = $render( array( 'mode' => 'normal', 'synced_today' => 312, 'waiting' => 14, 'failed' => 5, 'connection' => $odoo ) );
		$this->assertStringContainsString( 'estasoft-sync-widget--attention', $attention );
		$this->assertStringContainsString( '>312<', $attention );
		$this->assertStringContainsString( '5 orders need a person', $attention );
		$this->assertStringContainsString( 'Odoo connected. Last success 11:39.', $attention );
		$this->assertStringContainsString( 'Review 5 failed orders', $attention );
		$this->assertStringContainsString( '#/queue?state=failed', $attention );

		$clear = $render( array( 'mode' => 'normal', 'synced_today' => 318, 'waiting' => 2, 'failed' => 0, 'connection' => $odoo ) );
		$this->assertStringContainsString( 'estasoft-sync-widget--clear', $clear );
		$this->assertStringContainsString( 'Everything is in step', $clear );
		$this->assertStringContainsString( 'Open ESTASOFT SYNC', $clear );

		$paused = $render( array( 'mode' => 'paused', 'synced_today' => 318, 'waiting' => 41, 'failed' => 5, 'connection' => array( 'label' => 'Odoo', 'status' => 'paused_circuit', 'last_success_at' => '2026-11-02T11:48:00Z', 'paused_until' => '2026-11-02T12:07:00Z' ) ) );
		$this->assertStringContainsString( 'Paused: Odoo is not answering', $paused );
		$this->assertStringContainsString( 'Last answer 11:48. Next check 12:07.', $paused );
		$this->assertStringContainsString( 'Open the dashboard', $paused );

		$shadow = $render( array( 'mode' => 'shadow', 'shadow' => array( 'day' => 3, 'of_days' => 7, 'items' => 912, 'matches' => 886, 'differs' => 14 ), 'connection' => $odoo ) );
		$this->assertStringContainsString( 'Shadow mode, day 3 of 7', $shadow );
		$this->assertStringContainsString( 'Nothing is sent to Odoo.', $shadow );
		$this->assertStringContainsString( '>886<', $shadow );
		$this->assertStringContainsString( 'See the comparison', $shadow );

		$none = $render( array( 'mode' => 'not_connected' ) );
		$this->assertStringContainsString( 'Not connected', $none );
		$this->assertStringContainsString( 'Connect a system to start syncing. Setup takes about ten minutes.', $none );
		$this->assertStringContainsString( '#/setup', $none );
		$this->assertStringNotContainsString( 'estasoft-sync-widget__counts', $none );
	}

	public function test_the_dashboard_box_escapes_what_the_connected_system_calls_itself(): void {
		$html = $this->output(
			array(
				new DashboardWidget(
					static fn(): array => array( 'mode' => 'normal', 'connection' => array( 'label' => '<img src=x onerror=alert(1)>' ) ),
					$this->urls,
					static fn( string $iso ): string => $iso
				),
				'render',
			)
		);
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt; connected.', $html );
	}

	public function test_the_plugins_screen_gets_settings_and_setup_guide_links_before_the_others(): void {
		$links = new PluginLinks( 'estasoft-erp-crm-sync-engine/estasoft-erp-crm-sync-engine.php', $this->urls );
		$out   = $links->links( array( 'deactivate' => '<a>Deactivate</a>' ) );
		$this->assertSame( array( 'estasoft-sync-settings', 'estasoft-sync-setup', 'deactivate' ), array_keys( $out ) );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'admin.php?page=estasoft-sync' ) ) . '#/settings"', $out['estasoft-sync-settings'] );
		$this->assertStringContainsString( '#/setup', $out['estasoft-sync-setup'] );
		$this->assertStringContainsString( '>Setup guide<', $out['estasoft-sync-setup'] );

		$this->actAs( 'shop_manager' );
		$this->assertSame( array( 'estasoft-sync-settings', 'deactivate' ), array_keys( $links->links( array( 'deactivate' => 'x' ) ) ), 'the setup guide is for people who can configure' );
		$this->assertSame( array( 'estasoft-sync-settings' ), array_keys( $links->links( 'not a list' ) ) );
	}

	public function test_the_hook_that_adds_the_links_is_for_this_plugin_file(): void {
		$module = $this->container->get( AdminModule::PLUGIN_LINKS );
		$this->assertInstanceOf( PluginLinks::class, $module );
	}
}
