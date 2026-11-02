<?php
/**
 * Tells WooCommerce which of its features this plugin supports.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Declares compatibility with high-performance order storage (custom order tables) and the Cart and Checkout blocks, so WooCommerce
 * does not show the "incompatible plugin" warning. The plugin reads and writes orders only through the WooCommerce data layer, which is what makes the first claim true.
 */
final class Compatibility {

	/**
	 * Features declared, as named by WooCommerce.
	 */
	public const FEATURES = array( 'custom_order_tables', 'cart_checkout_blocks' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $pluginFile Absolute path of the main plugin file.
	 */
	public function __construct( private readonly string $pluginFile ) {
	}

	/**
	 * Add the hook. WooCommerce fires before_woocommerce_init before it loads its features.
	 */
	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declareCompatibility' ) );
	}

	/**
	 * Declare each feature. Does nothing when WooCommerce is too old to have the declaration class.
	 */
	public function declareCompatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}
		foreach ( self::FEATURES as $feature ) {
			FeaturesUtil::declare_compatibility( $feature, $this->pluginFile, true );
		}
	}
}
