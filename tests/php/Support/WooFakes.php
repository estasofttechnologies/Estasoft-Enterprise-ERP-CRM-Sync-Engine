<?php
/**
 * Minimal stand-ins for the WooCommerce classes and functions the plugin calls. Unit tests only.
 *
 * Load with require_once from a test case. Every declaration is guarded, so a second file that declares the same name does not clash.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities {

	if ( ! class_exists( FeaturesUtil::class, false ) ) {
		class FeaturesUtil { // phpcs:ignore
			/**
			 * Declarations made: [feature, file, positive].
			 *
			 * @var array<int,array{0:string,1:string,2:bool}>
			 */
			public static array $declared = array();

			public static function declare_compatibility( string $feature, string $file, bool $positive = true ): bool { // phpcs:ignore
				self::$declared[] = array( $feature, $file, $positive );
				return true;
			}
		}
	}
}

namespace {

	require_once __DIR__ . '/WooFakeMeta.php';
	require_once __DIR__ . '/WooRegistry.php';

	use Estasoft\SyncEngine\Tests\Support\WooFakeMeta;
	use Estasoft\SyncEngine\Tests\Support\WooRegistry;

	if ( ! class_exists( 'WC_Fake_Data', false ) ) {
		/**
		 * Getters from an array: get_billing_email() reads $props['billing_email'].
		 */
		class WC_Fake_Data { // phpcs:ignore
			use WooFakeMeta;

			/**
			 * Properties.
			 *
			 * @var array<string,mixed>
			 */
			public array $props = array();

			public function __construct( array $props = array() ) {
				$this->props = $props;
			}

			public function __call( string $name, array $arguments ) {
				if ( 0 === strpos( $name, 'get_' ) ) {
					return $this->props[ substr( $name, 4 ) ] ?? '';
				}
				throw new BadMethodCallException( $name );
			}

			public function get_id() { // phpcs:ignore
				return (int) ( $this->props['id'] ?? 0 );
			}
		}
	}

	if ( ! class_exists( 'WC_Order', false ) ) {
		class WC_Order extends WC_Fake_Data { // phpcs:ignore
			/**
			 * Items by type.
			 *
			 * @var array<string,array<int,object>>
			 */
			public array $items = array();

			/**
			 * Refunds.
			 *
			 * @var array<int,object>
			 */
			public array $refunds = array();

			public function get_items( $type = 'line_item' ) { // phpcs:ignore
				return $this->items[ $type ] ?? array();
			}

			public function get_refunds() { // phpcs:ignore
				return $this->refunds;
			}

			public function get_edit_order_url() { // phpcs:ignore
				return 'https://example.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->get_id();
			}

			public function get_order_number() { // phpcs:ignore
				return $this->props['order_number'] ?? (string) $this->get_id();
			}

			public function get_date_created() { // phpcs:ignore
				return $this->props['date_created'] ?? null;
			}

			public function get_date_paid() { // phpcs:ignore
				return $this->props['date_paid'] ?? null;
			}
		}
	}

	if ( ! class_exists( 'WC_Order_Refund', false ) ) {
		class WC_Order_Refund extends WC_Fake_Data { // phpcs:ignore
			/**
			 * Items by type.
			 *
			 * @var array<string,array<int,object>>
			 */
			public array $items = array();

			public function get_items( $type = 'line_item' ) { // phpcs:ignore
				return $this->items[ $type ] ?? array();
			}

			public function get_date_created() { // phpcs:ignore
				return $this->props['date_created'] ?? null;
			}
		}
	}

	if ( ! class_exists( 'WC_Order_Item_Product', false ) ) {
		class WC_Order_Item_Product extends WC_Fake_Data { // phpcs:ignore
			public function get_product() { // phpcs:ignore
				return $this->props['product'] ?? false;
			}
		}
	}
	if ( ! class_exists( 'WC_Order_Item_Shipping', false ) ) {
		class WC_Order_Item_Shipping extends WC_Fake_Data { // phpcs:ignore
		}
	}
	if ( ! class_exists( 'WC_Order_Item_Fee', false ) ) {
		class WC_Order_Item_Fee extends WC_Fake_Data { // phpcs:ignore
		}
	}
	if ( ! class_exists( 'WC_Order_Item_Tax', false ) ) {
		class WC_Order_Item_Tax extends WC_Fake_Data { // phpcs:ignore
		}
	}
	if ( ! class_exists( 'WC_Order_Item_Coupon', false ) ) {
		class WC_Order_Item_Coupon extends WC_Fake_Data { // phpcs:ignore
		}
	}

	if ( ! class_exists( 'WC_Product', false ) ) {
		class WC_Product extends WC_Fake_Data { // phpcs:ignore
			public function get_sku( $context = 'view' ) { // phpcs:ignore
				return (string) ( $this->props['sku'] ?? '' );
			}

			public function is_type( $type ) { // phpcs:ignore
				return ( $this->props['type'] ?? 'simple' ) === $type;
			}

			public function get_stock_quantity() { // phpcs:ignore
				return $this->props['stock_quantity'] ?? null;
			}

			public function get_parent_id() { // phpcs:ignore
				return (int) ( $this->props['parent_id'] ?? 0 );
			}
		}
	}

	if ( ! class_exists( 'WC_Product_Variation', false ) ) {
		class WC_Product_Variation extends WC_Product { // phpcs:ignore
		}
	}

	if ( ! class_exists( 'WC_Customer', false ) ) {
		class WC_Customer extends WC_Fake_Data { // phpcs:ignore
			/**
			 * Customers by id; the constructor copies the one asked for.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public static array $known = array();

			public function __construct( int $id = 0 ) {
				parent::__construct( self::$known[ $id ] ?? array() );
			}
		}
	}

	if ( ! function_exists( 'wc_get_order' ) ) {
		function wc_get_order( $id = false ) { // phpcs:ignore
			return WooRegistry::$orders[ (int) $id ] ?? false;
		}
	}
	if ( ! function_exists( 'wc_get_product' ) ) {
		function wc_get_product( $id = false ) { // phpcs:ignore
			return WooRegistry::$products[ (int) $id ] ?? false;
		}
	}
	if ( ! function_exists( 'wc_get_orders' ) ) {
		function wc_get_orders( $args = array() ) { // phpcs:ignore
			WooRegistry::$orderQueries[] = $args;
			return WooRegistry::$ordersResult;
		}
	}
	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		function wc_get_order_statuses() { // phpcs:ignore
			return array( 'wc-pending' => 'Pending', 'wc-processing' => 'Processing', 'wc-completed' => 'Completed' );
		}
	}
	if ( ! function_exists( 'wc_get_page_screen_id' ) ) {
		function wc_get_page_screen_id( $page ) { // phpcs:ignore
			return 'shop-order' === $page ? 'woocommerce_page_wc-orders' : $page;
		}
	}
}
