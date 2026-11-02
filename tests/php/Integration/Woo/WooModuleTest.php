<?php
/**
 * The Woo module inside a real WordPress: hooks, compatibility declaration, capabilities and a missing WooCommerce.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Woo;

require_once dirname( __DIR__, 2 ) . '/Support/CoreIntegrationTestCase.php';

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Estasoft\SyncEngine\Core\Capabilities;
use Estasoft\SyncEngine\Core\MetaKeys;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Plugin;
use Estasoft\SyncEngine\Tests\Support\IntegrationTestCase;
use Estasoft\SyncEngine\Tests\Support\WooRecordingQueue;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\RecordingLog;
use Estasoft\SyncEngine\Woo\ActionNotices;
use Estasoft\SyncEngine\Woo\OrderBox;
use Estasoft\SyncEngine\Woo\OrderMeta;
use Estasoft\SyncEngine\Woo\OrdersList;
use Estasoft\SyncEngine\Woo\ProductBox;
use Estasoft\SyncEngine\Woo\ProductMeta;

final class WooModuleTest extends IntegrationTestCase {

	/**
	 * Hooks the module adds in every request, with the method that handles each.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function frontHooks(): array {
		return array(
			'classic checkout'  => array( 'woocommerce_checkout_order_processed', 'onClassicCheckout' ),
			'block checkout'    => array( 'woocommerce_store_api_checkout_order_processed', 'onBlocksCheckout' ),
			'payment'           => array( 'woocommerce_payment_complete', 'onPaymentComplete' ),
			'status change'     => array( 'woocommerce_order_status_changed', 'onStatusChanged' ),
			'order saved'       => array( 'woocommerce_update_order', 'onOrderUpdated' ),
			'refund'            => array( 'woocommerce_refund_created', 'onRefundCreated' ),
			'new customer'      => array( 'woocommerce_created_customer', 'onCustomerCreated' ),
			'end of request'    => array( 'shutdown', 'flushUpdates' ),
		);
	}

	/**
	 * @dataProvider frontHooks
	 */
	public function test_the_woocommerce_hooks_are_registered( string $hook, string $method ): void {
		$found = false;
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && $function[0] === Plugin::instance()->container()->get( 'woo.order_hooks' ) && $function[1] === $method ) {
					$found = true;
				}
			}
		}
		$this->assertTrue( $found, "$hook should call OrderHooks::$method" );
	}

	public function test_the_woocommerce_object_cache_hooks_are_not_used_twice_for_refunds(): void {
		$this->assertFalse( has_action( 'woocommerce_order_refunded' ), 'woocommerce_refund_created is the one hook used, so a refund is never queued twice.' );
	}

	public function test_the_compatibility_declaration_runs_on_before_woocommerce_init(): void {
		require_once dirname( __DIR__, 2 ) . '/Support/WooFakes.php';
		FeaturesUtil::$declared = array();
		do_action( 'before_woocommerce_init' );
		$declared = array_column( FeaturesUtil::$declared, 0 );
		$this->assertContains( 'custom_order_tables', $declared );
		$this->assertContains( 'cart_checkout_blocks', $declared );
		$this->assertSame( ESTASOFT_SYNC_FILE, FeaturesUtil::$declared[0][1] );
	}

	public function test_the_product_match_key_is_added_to_the_keys_removed_on_uninstall(): void {
		$this->assertContains( '_estasoft_sync_match', MetaKeys::product() );
		$this->assertNotContains( '_estasoft_sync_match', MetaKeys::order() );
		$this->assertSame( array( 'a' ), apply_filters( 'estasoft_sync_meta_keys', array( 'a' ), 'order' ), 'Other lists are untouched.' );
	}

	public function test_the_queue_display_and_state_filters_are_registered(): void {
		$this->assertNotFalse( has_filter( 'estasoft_sync_queue_display' ) );
		foreach ( array( 'estasoft_sync_item_synced', 'estasoft_sync_item_failed', 'estasoft_sync_shadow_processed', 'estasoft_sync_item_state_changed' ) as $hook ) {
			$this->assertNotFalse( has_action( $hook ), $hook );
		}
		$this->assertNotFalse( has_action( 'estasoft_sync_product_match' ) );
	}

	public function test_the_services_other_modules_need_are_there(): void {
		$container = Plugin::instance()->container();
		$this->assertInstanceOf( \Estasoft\SyncEngine\Mapping\Ports\CanonicalSource::class, $container->get( 'woo.order_reader' ) );
		$this->assertInstanceOf( \Estasoft\SyncEngine\Sync\PayloadBuilderInterface::class, $container->get( 'payload_builder' ) );
		foreach ( array( 'mapping.service', 'mapping.previewer', 'mapping.tax_map', 'mapping.sku_matcher', 'mapping.rule_engine' ) as $key ) {
			$this->assertTrue( $container->has( $key ), $key );
			$this->assertNotNull( $container->get( $key ), $key );
		}
	}

	/**
	 * Load the admin helpers that add_meta_box() needs; WordPress loads them itself on admin screens.
	 */
	private function loadAdminFunctions(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}

	/**
	 * A user with a role.
	 */
	private function userWithRole( string $role ): int {
		$id = wp_insert_user( array( 'user_login' => 'u' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password(), 'user_email' => wp_generate_password( 8, false ) . '@example.test', 'role' => $role ) );
		$this->assertIsInt( $id );
		return $id;
	}

	public function test_only_people_who_may_manage_syncing_get_the_boxes_and_the_column(): void {
		global $wp_meta_boxes;
		$this->loadAdminFunctions();
		$queue = new WooRecordingQueue();
		$box   = new OrderBox(
			$queue,
			new OrderMeta(),
			new ProductMeta(),
			Plugin::instance()->container()->get( 'woo.order_actions' ),
			Plugin::instance()->container()->get( 'woo.connection_info' ),
			Plugin::instance()->container()->get( 'woo.chips' ),
			Plugin::instance()->container()->get( 'woo.times' ),
			Capabilities::MANAGE
		);

		wp_set_current_user( $this->userWithRole( 'subscriber' ) );
		$box->addBox( 'shop_order' );
		$this->assertArrayNotHasKey( 'estasoft-sync-order-box', $wp_meta_boxes['shop_order']['side']['default'] ?? array() );

		wp_set_current_user( $this->userWithRole( 'administrator' ) );
		$box->addBox( 'shop_order' );
		$this->assertArrayHasKey( 'estasoft-sync-order-box', $wp_meta_boxes['shop_order']['side']['default'] );

		$box->addBox( 'page' );
		$this->assertArrayNotHasKey( 'estasoft-sync-order-box', $wp_meta_boxes['page']['side']['default'] ?? array(), 'Only on order screens.' );
	}

	public function test_the_column_is_only_added_for_people_who_may_manage_syncing(): void {
		$list = new OrdersList(
			new WooRecordingQueue(),
			new OrderMeta(),
			Plugin::instance()->container()->get( 'woo.order_actions' ),
			Plugin::instance()->container()->get( 'woo.chips' ),
			Plugin::instance()->container()->get( 'woo.times' ),
			Capabilities::MANAGE
		);
		wp_set_current_user( $this->userWithRole( 'subscriber' ) );
		$this->assertArrayNotHasKey( 'estasoft_sync', $list->addColumn( array( 'order_status' => 'Status' ) ) );
		$this->assertSame( array(), $list->addBulkActions( array() ) );
		wp_set_current_user( $this->userWithRole( 'administrator' ) );
		$this->assertArrayHasKey( 'estasoft_sync', $list->addColumn( array( 'order_status' => 'Status' ) ) );
		$this->assertCount( 3, $list->addBulkActions( array() ) );
	}

	public function test_the_result_notice_is_for_managers_only_and_prints_escaped_text(): void {
		$notices = new ActionNotices( Capabilities::MANAGE );
		$_GET['estasoft_sync_notice'] = 'retried';
		$_GET['estasoft_sync_count']  = '3';

		wp_set_current_user( $this->userWithRole( 'subscriber' ) );
		ob_start();
		$notices->show();
		$this->assertSame( '', ob_get_clean() );

		wp_set_current_user( $this->userWithRole( 'administrator' ) );
		ob_start();
		$notices->show();
		$this->assertStringContainsString( '3 orders were put back in the queue.', (string) ob_get_clean() );
		unset( $_GET['estasoft_sync_notice'], $_GET['estasoft_sync_count'] );
	}

	public function test_the_product_box_is_for_managers_only(): void {
		global $wp_meta_boxes;
		$this->loadAdminFunctions();
		$box = new ProductBox(
			new ProductMeta(),
			Plugin::instance()->container()->get( 'woo.connection_info' ),
			Plugin::instance()->container()->get( 'woo.chips' ),
			Plugin::instance()->container()->get( 'woo.times' ),
			Capabilities::MANAGE
		);
		wp_set_current_user( $this->userWithRole( 'subscriber' ) );
		$box->addBox();
		$this->assertArrayNotHasKey( 'estasoft-sync-product-box', $wp_meta_boxes['product']['side']['default'] ?? array() );
		wp_set_current_user( $this->userWithRole( 'administrator' ) );
		$box->addBox();
		$this->assertArrayHasKey( 'estasoft-sync-product-box', $wp_meta_boxes['product']['side']['default'] );
	}

	/**
	 * With WooCommerce missing, nothing in the module may fail. This runs in its own process because other tests define the WooCommerce functions.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_hook_does_nothing_when_woocommerce_functions_are_missing(): void {
		$this->assertFalse( function_exists( 'wc_get_order' ), 'This test needs a site without the WooCommerce functions.' );

		$hooks = Plugin::instance()->container()->get( 'woo.order_hooks' );
		$hooks->onClassicCheckout( 10490 );
		$hooks->onBlocksCheckout( null );
		$hooks->onPaymentComplete( 10490 );
		$hooks->onStatusChanged( 10490, 'pending', 'processing' );
		$hooks->onOrderUpdated( 10490 );
		$hooks->onRefundCreated( 10500 );
		$hooks->flushUpdates();

		$mirror = Plugin::instance()->container()->get( 'woo.state_mirror' );
		$mirror->mirrorById( 1 );
		$display = apply_filters( 'estasoft_sync_queue_display', array( 'label' => 'x' ), EntityType::Order, '10490', 'created' );
		$this->assertSame( array( 'label' => 'x' ), $display );

		$this->expectException( \Estasoft\SyncEngine\Mapping\Ports\SourceNotFoundException::class );
		Plugin::instance()->container()->get( 'woo.order_reader' )->read( EntityType::Order, '10490' );
	}
}
