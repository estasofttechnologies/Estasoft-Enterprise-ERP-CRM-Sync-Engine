<?php
/**
 * Runs the order hooks against the real WooCommerce, inside a real WordPress (wp eval-file).
 *
 * The unit and integration tests use a small stand-in for WooCommerce. This script is the check that the real one behaves the same:
 * it makes real orders and refunds and looks at what the plugin put in its queue. It sends nothing anywhere: no queue worker runs.
 * It exits with a failure when any check fails. Used by .github/workflows/woocommerce-smoke.yml.
 *
 * @package Estasoft\SyncEngine
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.Security.EscapeOutput

use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Plugin;
use Estasoft\SyncEngine\Woo\OrderMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['estasoft_smoke_failures'] = array();

/**
 * Record a check.
 *
 * @param bool   $ok      Whether it held.
 * @param string $message What was checked.
 */
function estasoft_smoke_check( bool $ok, string $message ): void {
	echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $message . "\n";
	if ( ! $ok ) {
		$GLOBALS['estasoft_smoke_failures'][] = $message;
	}
}

/**
 * Queue rows for an entity, oldest first, as "event:state" text.
 *
 * @param string $type Entity type.
 * @param int    $id   Entity id.
 * @return array<int,string>
 */
function estasoft_smoke_rows( string $type, int $id ): array {
	global $wpdb;
	$table = $wpdb->prefix . 'estasoft_sync_queue';
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT event, state FROM {$table} WHERE entity_type = %s AND entity_id = %s ORDER BY id", $type, (string) $id ), ARRAY_A );
	return array_map( static fn( array $row ): string => $row['event'] . ':' . $row['state'], (array) $rows );
}

/**
 * A simple product.
 *
 * @param string $sku SKU.
 */
function estasoft_smoke_product( string $sku ): \WC_Product_Simple {
	$product = new \WC_Product_Simple();
	$product->set_name( 'Smoke ' . $sku );
	$product->set_sku( $sku );
	$product->set_regular_price( '12.50' );
	$product->save();
	return $product;
}

/**
 * An order with one line, as a customer at checkout leaves it.
 *
 * @param \WC_Product_Simple $product Product.
 * @param string             $method  Payment method id.
 */
function estasoft_smoke_order( \WC_Product_Simple $product, string $method ): \WC_Order {
	$order = wc_create_order();
	$order->add_product( $product, 2 );
	$order->set_billing_first_name( 'Noor' );
	$order->set_billing_last_name( 'Smoke' );
	$order->set_billing_email( 'noor.smoke@example.com' );
	$order->set_billing_country( 'AE' );
	$order->set_payment_method( $method );
	$order->calculate_totals();
	$order->set_status( 'pending' );
	$order->save();
	return $order;
}

echo 'WordPress ' . get_bloginfo( 'version' ) . ', WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'missing' ) . ', PHP ' . PHP_VERSION . "\n";
estasoft_smoke_check( class_exists( 'WooCommerce' ), 'WooCommerce is loaded' );
estasoft_smoke_check( class_exists( Plugin::class ), 'The plugin is loaded' );
if ( array() !== $GLOBALS['estasoft_smoke_failures'] ) {
	exit( 1 );
}

$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
echo 'Order storage in use: ' . ( $hpos ? 'high-performance order storage (custom tables)' : 'posts' ) . "\n";

// What the plugin told WooCommerce about itself.
if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil', 'get_compatible_plugins_for_feature' ) ) {
	$basename = plugin_basename( ESTASOFT_SYNC_FILE );
	foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $feature ) {
		$plugins = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( $feature );
		estasoft_smoke_check( in_array( $basename, (array) ( $plugins['compatible'] ?? array() ), true ), 'WooCommerce lists the plugin as compatible with ' . $feature );
	}
}

$container = Plugin::instance()->container();
$conn      = $container->get( Services::CONNECTION_REPOSITORY )->create(
	array(
		'connector' => 'odoo',
		'type'      => 'erp',
		'label'     => 'Odoo',
		'address'   => 'https://erp.invalid',
	),
	array( 'api_key' => 'smoke-test-key-0123456789' ),
	array()
);
$container->get( Services::ONBOARDING )->save(
	array(
		'step'      => 5,
		'completed' => true,
		'connector' => 'odoo',
		'answers'   => array( 'went_live_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ) ),
	)
);
estasoft_smoke_check( $conn > 0, 'A connection and a finished setup exist' );

$meta = new OrderMeta();

echo "Cash on delivery order, queued at checkout\n";
$cod = estasoft_smoke_order( estasoft_smoke_product( 'SMOKE-COD' ), 'cod' );
do_action( 'woocommerce_checkout_order_processed', $cod->get_id(), array(), $cod );
estasoft_smoke_check( array( 'created:queued' ) === estasoft_smoke_rows( 'order', $cod->get_id() ), 'One created item is queued for a cash on delivery order' );
estasoft_smoke_check( 'queued' === $meta->state( wc_get_order( $cod->get_id() ) ), 'The queue state is copied onto the order (' . ( $hpos ? 'custom table' : 'post' ) . ' storage)' );

echo "Card order, waiting for payment\n";
$card = estasoft_smoke_order( estasoft_smoke_product( 'SMOKE-CARD' ), 'stripe' );
do_action( 'woocommerce_checkout_order_processed', $card->get_id(), array(), $card );
estasoft_smoke_check( array() === estasoft_smoke_rows( 'order', $card->get_id() ), 'An unpaid card order is not queued at checkout (send when: paid)' );
$card->payment_complete( 'smoke-txn-1' );
estasoft_smoke_check( array( 'created:queued' ) === estasoft_smoke_rows( 'order', $card->get_id() ), 'It is queued once when the payment completes' );

echo "Bank transfer order, put on hold then paid\n";
$bank = estasoft_smoke_order( estasoft_smoke_product( 'SMOKE-BACS' ), 'bacs' );
$bank->update_status( 'on-hold' );
estasoft_smoke_check( array() === estasoft_smoke_rows( 'order', $bank->get_id() ), 'An order on hold is not queued' );
$bank->update_status( 'processing' );
estasoft_smoke_check( array( 'created:queued' ) === estasoft_smoke_rows( 'order', $bank->get_id() ), 'It is queued when it is marked as paid' );

echo "Refund and cancellation\n";
$refund = wc_create_refund(
	array(
		'order_id' => $cod->get_id(),
		'amount'   => '5.00',
		'reason'   => 'Smoke test',
	)
);
estasoft_smoke_check( $refund instanceof \WC_Order_Refund, 'WooCommerce made a refund' );
if ( $refund instanceof \WC_Order_Refund ) {
	estasoft_smoke_check( array( 'refunded:queued' ) === estasoft_smoke_rows( 'refund', $refund->get_id() ), 'The refund is queued behind its order' );
}
$cod = wc_get_order( $cod->get_id() );
$cod->update_status( 'cancelled' );
estasoft_smoke_check( in_array( 'cancelled:queued', estasoft_smoke_rows( 'order', $cod->get_id() ), true ), 'A cancelled order queues a cancelled item' );

echo "Setup choice: send orders off\n";
$state = $container->get( Services::ONBOARDING );
$state->save( array( 'answers' => array_replace( $state->get()['answers'], array( 'what' => array( 'send_orders' => false ) ) ) ) );
$off = new \Estasoft\SyncEngine\Woo\Adapters\CoreSendChoices( $state );
estasoft_smoke_check( false === $off->sendOrders(), 'The stored choice is read' );

// Leave nothing behind on a reused database.
$state->save( array( 'completed' => false, 'answers' => array() ) );

if ( array() !== $GLOBALS['estasoft_smoke_failures'] ) {
	echo "\n" . count( $GLOBALS['estasoft_smoke_failures'] ) . " check(s) failed.\n";
	exit( 1 );
}
echo "\nAll checks passed.\n";
