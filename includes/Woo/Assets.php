<?php
/**
 * Loads the small stylesheet of the server-rendered boxes, on the screens that show them.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

defined( 'ABSPATH' ) || exit;

/**
 * The order screen, the orders list, the product screen, the products list and the user profile. Nowhere else.
 */
final class Assets {

	/**
	 * Stylesheet handle.
	 */
	public const HANDLE = 'estasoft-sync-woo-boxes';

	/**
	 * Screen ids that show a box, a column or a section. The high-performance order storage screen is woocommerce_page_wc-orders.
	 */
	private const SCREENS = array( 'woocommerce_page_wc-orders', 'shop_order', 'edit-shop_order', 'product', 'edit-product', 'profile', 'user-edit' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $url     URL of the plugin directory, with a trailing slash.
	 * @param string $version Plugin version, for cache busting.
	 */
	public function __construct( private readonly string $url, private readonly string $version ) {
	}

	/**
	 * Add the hook.
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Load the stylesheet on the screens above.
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null !== $screen && in_array( $screen->id, self::SCREENS, true ) ) {
			wp_enqueue_style( self::HANDLE, $this->url . 'assets/css/woo-boxes.css', array(), $this->version );
		}
	}
}
