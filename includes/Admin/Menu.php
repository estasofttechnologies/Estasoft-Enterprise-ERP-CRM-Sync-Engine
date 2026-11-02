<?php
/**
 * The admin menu entry and the page that hosts the app.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Admin;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Capabilities;

/**
 * A submenu of WooCommerce, "ESTASOFT SYNC", with a red bubble that counts failed orders (UI-1). The page itself is only a mount point:
 * WordPress needs a page to show the menu, and the React app renders everything inside it. When the compiled app is missing, the page
 * says how to build it instead of showing a blank screen.
 */
final class Menu {

	/**
	 * Parent menu slug (WooCommerce).
	 */
	public const PARENT = 'woocommerce';

	/**
	 * Id of the element the app mounts in.
	 */
	public const ROOT_ID = 'estasoft-sync-root';

	/**
	 * Hook suffix of the page once it is added, for example woocommerce_page_estasoft-sync.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Callbacks to run on `load-{page}`.
	 *
	 * @var array<int,callable>
	 */
	private array $onLoad = array();

	/**
	 * Create the menu.
	 *
	 * @param Assets   $assets      Knows whether the compiled app exists.
	 * @param \Closure $failedCount Returns the number of failed orders (cached 60 seconds by the dashboard service).
	 *
	 * @phpstan-param \Closure():int $failedCount
	 */
	public function __construct(
		private readonly Assets $assets,
		private readonly \Closure $failedCount
	) {
	}

	/**
	 * Add the menu hook.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPage' ), 60 );
	}

	/**
	 * Ask for a callback when the page is about to load (Help tabs, Screen Options). Call before `admin_menu` runs.
	 *
	 * @param callable $callback Called with no arguments.
	 */
	public function onLoad( callable $callback ): void {
		$this->onLoad[] = $callback;
	}

	/**
	 * The hook suffix of the page, empty before the menu is built.
	 */
	public function hookSuffix(): string {
		return $this->hook;
	}

	/**
	 * Add the submenu page. Runs on `admin_menu`.
	 */
	public function addPage(): void {
		$title = __( 'ESTASOFT SYNC', 'estasoft-erp-crm-sync-engine' );
		$hook  = add_submenu_page(
			self::PARENT,
			$title,
			$title . $this->bubble(),
			$this->capability(),
			Urls::PAGE,
			array( $this, 'render' )
		);
		if ( false === $hook ) {
			return;
		}
		$this->hook = $hook;
		foreach ( $this->onLoad as $callback ) {
			add_action( 'load-' . $hook, $callback );
		}
	}

	/**
	 * The capability the menu entry is registered with. The rule is the one the REST routes use (Core\Capabilities::userCanManage()):
	 * whoever may manage may open the page, and so may whoever may configure. WordPress takes one capability per menu entry, so
	 * a person who holds only `estasoft_sync_configure` gets an entry registered with that one. The entry is built for the current
	 * person on each admin request, which is also when WordPress checks access to the page.
	 */
	public function capability(): string {
		return current_user_can( Capabilities::CONFIGURE ) && ! current_user_can( Capabilities::MANAGE ) ? Capabilities::CONFIGURE : Capabilities::MANAGE;
	}

	/**
	 * The red count after the menu title; empty when no order failed.
	 */
	public function bubble(): string {
		$failed = (int) ( $this->failedCount )();
		if ( $failed < 1 ) {
			return '';
		}
		return sprintf(
			' <span class="update-plugins count-%1$d"><span class="plugin-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
			$failed,
			esc_html( number_format_i18n( $failed ) ),
			/* translators: %s: number of failed orders. */
			esc_html( sprintf( _n( '%s failed order', '%s failed orders', $failed, 'estasoft-erp-crm-sync-engine' ), number_format_i18n( $failed ) ) )
		);
	}

	/**
	 * Print the page: the mount point, a message for people without JavaScript, and a loading line the app replaces.
	 */
	public function render(): void {
		echo '<div id="' . esc_attr( self::ROOT_ID ) . '" class="wrap estasoft-sync">';
		echo '<h1 class="screen-reader-text">' . esc_html__( 'ESTASOFT SYNC', 'estasoft-erp-crm-sync-engine' ) . '</h1>';
		if ( ! $this->assets->bundleAvailable() ) {
			$this->renderMissingBundle();
			echo '</div>';
			return;
		}
		echo '<noscript><div class="notice notice-error"><p>';
		esc_html_e( 'ESTASOFT SYNC needs JavaScript. Turn it on in your browser to use this screen. Your orders keep syncing in the background either way.', 'estasoft-erp-crm-sync-engine' );
		echo '</p></div></noscript>';
		echo '<p class="estasoft-sync-loading" role="status">' . esc_html__( 'Loading ESTASOFT SYNC…', 'estasoft-erp-crm-sync-engine' ) . '</p>';
		echo '</div>';
	}

	/**
	 * The message shown when the compiled app is not there.
	 */
	private function renderMissingBundle(): void {
		echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'The ESTASOFT SYNC screen has not been built.', 'estasoft-erp-crm-sync-engine' ) . '</strong> ';
		if ( Capabilities::userCanConfigure() ) {
			printf(
				/* translators: %s: the command to run, shown as code. */
				esc_html__( 'This copy of the plugin was installed from the source code. Run %s in the plugin folder, then reload this page. Syncing is not affected.', 'estasoft-erp-crm-sync-engine' ),
				'<code>npm install &amp;&amp; npm run build</code>'
			);
		} else {
			esc_html_e( 'Ask an administrator to finish the installation. Syncing is not affected.', 'estasoft-erp-crm-sync-engine' );
		}
		echo '</p></div>';
	}
}
