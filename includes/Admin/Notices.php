<?php
/**
 * Admin notices on WordPress screens outside the plugin's own page (UI-9).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Capabilities;

/**
 * WordPress' own notice style, a coloured edge, plain words and one action. Three notices show, and only on the screens where a store
 * owner is working (the Dashboard, the Plugins list and the WooCommerce screens, see onNoticeScreen()), never on the rest of the admin: the welcome notice
 * after activation (the only one a person can dismiss for good, with a nonce and a user-meta flag), the missing encryption key
 * with a fresh line to paste, and a connection that needs the person to sign in again. The notices about failed orders, the queue
 * backlog and the end of shadow mode show inside the app, from the boot data, so the person is not told twice. The message about
 * missing requirements (WooCommerce or PHP) is printed by includes/requirements.php, because it must work before the plugin boots.
 */
final class Notices {

	/**
	 * Action name of the dismiss request.
	 */
	public const DISMISS_ACTION = 'estasoft_sync_dismiss_notice';

	/**
	 * Ends the request after the dismiss redirect.
	 *
	 * @var \Closure():void
	 */
	private \Closure $terminate;

	/**
	 * Create the notices.
	 *
	 * @param NoticeFacts   $facts        Facts the notices depend on.
	 * @param NoticeRules   $rules        When each notice shows.
	 * @param Urls          $urls         Addresses of the app.
	 * @param \Closure      $onPluginPage Returns whether the current screen is the plugin's own page.
	 * @param \Closure|null $terminate    Ends the request after a redirect; `exit` by default (allowed for this file in
	 *                                    bin/red-flags-allowlist.txt, as core ends its own redirects). Tests pass their own.
	 *
	 * @phpstan-param \Closure():bool $onPluginPage
	 */
	public function __construct(
		private readonly NoticeFacts $facts,
		private readonly NoticeRules $rules,
		private readonly Urls $urls,
		private readonly \Closure $onPluginPage,
		?\Closure $terminate = null
	) {
		$this->terminate = $terminate ?? static function (): void {
			exit;
		};
	}

	/**
	 * Add the hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'dismiss' ) );
	}

	/**
	 * Print the notices that apply. Runs on `admin_notices`.
	 */
	public function render(): void {
		if ( ! Capabilities::userCanManage() || ( $this->onPluginPage )() || ! $this->onNoticeScreen() ) {
			return;
		}
		foreach ( $this->rules->forScreens( $this->facts->gather( (int) get_current_user_id() ) ) as $notice ) {
			$this->print( $notice );
		}
	}

	/**
	 * Whether the current screen is one where a notice of this plugin may show: the Dashboard, the Plugins list, and the WooCommerce
	 * screens (its own pages, the orders and the products). Every other admin screen stays free of them.
	 */
	private function onNoticeScreen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}
		$id    = (string) $screen->id;
		$shop  = array( 'shop_order', 'shop_order_placehold', 'product', 'edit-shop_order', 'edit-shop_order_placehold', 'edit-product' );
		$known = 'dashboard' === $id
			|| 'plugins' === $id
			|| str_contains( $id, 'woocommerce' )
			|| in_array( $id, $shop, true )
			|| in_array( (string) $screen->post_type, $shop, true );
		/**
		 * Whether the notices of ESTASOFT SYNC show on the current admin screen.
		 *
		 * @param bool       $show   True on the Dashboard, the Plugins list and the WooCommerce screens.
		 * @param \WP_Screen $screen The current screen.
		 */
		return (bool) apply_filters( 'estasoft_sync_show_notices_on_screen', $known, $screen );
	}

	/**
	 * Save that a person dismissed the welcome notice, then go back. Runs on `admin_post_estasoft_sync_dismiss_notice`.
	 */
	public function dismiss(): void {
		$id = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked on the next line.
		check_admin_referer( self::DISMISS_ACTION . '_' . $id );
		if ( 'welcome' === $id && Capabilities::userCanManage() ) {
			$this->facts->dismiss( (int) get_current_user_id(), $id );
		}
		$back = wp_get_referer();
		wp_safe_redirect( false === $back ? admin_url() : $back );
		( $this->terminate )();
	}

	/**
	 * The address that dismisses a notice for good.
	 *
	 * @param string $id Notice id.
	 */
	public function dismissUrl( string $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DISMISS_ACTION,
					'notice' => $id,
				),
				admin_url( 'admin-post.php' )
			),
			self::DISMISS_ACTION . '_' . $id
		);
	}

	/**
	 * Print one notice.
	 *
	 * @param array{id:string,kind:string,text:string,action_label:string,action_route:string,dismissible:bool,scope:string,key_line?:string} $notice Notice.
	 */
	private function print( array $notice ): void {
		$kind = in_array( $notice['kind'], array( 'info', 'warning', 'error', 'success' ), true ) ? $notice['kind'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $kind ) . ' estasoft-sync-notice" data-estasoft-sync-notice="' . esc_attr( $notice['id'] ) . '">';
		echo '<p>' . esc_html( $notice['text'] ) . '</p>';
		if ( isset( $notice['key_line'] ) ) {
			echo '<p>' . esc_html__( 'Add this line to wp-config.php, above the line that says “That’s all, stop editing!”, then reload the page.', 'estasoft-enterprise-erpcrm-sync-engine' ) . '</p>';
			echo '<p><code>' . esc_html( $notice['key_line'] ) . '</code></p>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( $this->urls->route( $notice['action_route'] ) ) . '">' . esc_html( $notice['action_label'] ) . '</a>';
		if ( $notice['dismissible'] ) {
			echo ' <a class="button" href="' . esc_url( $this->dismissUrl( $notice['id'] ) ) . '">' . esc_html__( 'Not now', 'estasoft-enterprise-erpcrm-sync-engine' ) . '</a>';
		}
		echo '</p></div>';
	}
}
