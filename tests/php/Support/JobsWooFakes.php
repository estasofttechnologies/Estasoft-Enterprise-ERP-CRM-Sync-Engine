<?php
/**
 * What the Jobs adapters need from WooCommerce beyond tests/php/Support/WooFakes.php (owned by another agent).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support {

	require_once __DIR__ . '/WooFakes.php';

	/**
	 * State behind wc_get_products() and wc_get_held_stock_quantity().
	 */
	final class JobsWooRegistry {

		/**
		 * Result of the next wc_get_products() call, by page (an object with products and max_num_pages).
		 *
		 * @var array<int,object>
		 */
		public static array $productPages = array();

		/**
		 * Arguments of every wc_get_products() call.
		 *
		 * @var array<int,array<string,mixed>>
		 */
		public static array $productQueries = array();

		/**
		 * Held units by product id.
		 *
		 * @var array<int,int>
		 */
		public static array $held = array();

		/**
		 * Forget everything.
		 */
		public static function reset(): void {
			self::$productPages   = array();
			self::$productQueries = array();
			self::$held           = array();
		}
	}
}

namespace {

	use Estasoft\SyncEngine\Tests\Support\JobsWooRegistry;

	if ( ! class_exists( 'Jobs_Fake_Wc_Order', false ) ) {
		/**
		 * An order that can change status and take notes.
		 */
		class Jobs_Fake_Wc_Order extends WC_Order { // phpcs:ignore
			/**
			 * Notes: array( text, is customer note ).
			 *
			 * @var array<int,array{0:string,1:mixed}>
			 */
			public array $notes = array();

			public function update_status( $status, $note = '' ) { // phpcs:ignore
				$this->props['status'] = $status;
				$this->notes[]         = array( (string) $note, 'status' );
				++$this->saves;
				return true;
			}

			public function add_order_note( $note, $customer = 0, $by_user = false ) { // phpcs:ignore
				$this->notes[] = array( (string) $note, $customer );
				return 1;
			}

			public function get_status( $context = 'view' ) { // phpcs:ignore
				return (string) ( $this->props['status'] ?? '' );
			}
		}
	}
	if ( ! class_exists( 'Jobs_Fake_Wc_Product', false ) ) {
		/**
		 * A product whose stock can be set.
		 */
		class Jobs_Fake_Wc_Product extends WC_Product { // phpcs:ignore
			public function set_stock_quantity( $quantity ) { // phpcs:ignore
				$this->props['stock_quantity'] = $quantity;
			}

			public function get_manage_stock( $context = 'view' ) { // phpcs:ignore
				return $this->props['manage_stock'] ?? false;
			}
		}
	}
	if ( ! function_exists( 'wc_get_products' ) ) {
		function wc_get_products( $args = array() ) { // phpcs:ignore
			JobsWooRegistry::$productQueries[] = $args;
			return JobsWooRegistry::$productPages[ (int) ( $args['page'] ?? 1 ) ] ?? (object) array(
				'products'      => array(),
				'max_num_pages' => 1,
			);
		}
	}
	if ( ! function_exists( 'wc_get_held_stock_quantity' ) ) {
		function wc_get_held_stock_quantity( $product, $exclude = 0 ) { // phpcs:ignore
			return JobsWooRegistry::$held[ (int) $product->get_id() ] ?? 0;
		}
	}
}
