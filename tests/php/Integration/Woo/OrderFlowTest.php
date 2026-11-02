<?php
/**
 * The whole chain on a real database: a WooCommerce event, the queue, the mapping, the connector, and the state back on the order.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Woo;

require_once dirname( __DIR__, 2 ) . '/Support/CoreIntegrationTestCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/WooFakes.php';

use Estasoft\SyncEngine\Core\Queue\ItemProcessor;
use Estasoft\SyncEngine\Core\Queue\Outcome;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\Adapters\CoreConnectionSource;
use Estasoft\SyncEngine\Mapping\Adapters\CoreEventLog;
use Estasoft\SyncEngine\Mapping\Adapters\CoreIdMapStore;
use Estasoft\SyncEngine\Mapping\Adapters\CoreMappingStore;
use Estasoft\SyncEngine\Mapping\CanonicalFields;
use Estasoft\SyncEngine\Mapping\PayloadBuilder;
use Estasoft\SyncEngine\Mapping\Presets;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\SkuMatcher;
use Estasoft\SyncEngine\Mapping\TaxMap;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;
use Estasoft\SyncEngine\Tests\Support\WooRegistry;
use Estasoft\SyncEngine\Woo\Adapters\CoreQueueGateway;
use Estasoft\SyncEngine\Woo\OrderHooks;
use Estasoft\SyncEngine\Woo\OrderMeta;
use Estasoft\SyncEngine\Woo\ProductMeta;
use Estasoft\SyncEngine\Woo\QueueDisplay;
use Estasoft\SyncEngine\Woo\StateMirror;
use Estasoft\SyncEngine\Woo\WooOrderReader;

final class OrderFlowTest extends CoreIntegrationTestCase {

	private int $conn;
	private CoreQueueGateway $gateway;
	private OrderHooks $hooks;
	private ItemProcessor $processor;
	private TaxMap $taxes;
	private \WC_Order $order;

	/**
	 * Payloads the fake system received.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $sent = array();

	protected function setUp(): void {
		parent::setUp();
		WooRegistry::reset();

		$this->conn    = $this->h->connection();
		$store         = new CoreMappingStore( $this->h->mappings );
		$log           = new CoreEventLog( $this->h->logger );
		$this->taxes   = new TaxMap( $store, $log );
		$this->taxes->save( $this->conn, array( array( 'country' => 'AE', 'rate_percent' => 5, 'system_code' => 'VAT 5% (AE)' ) ), null, 'Layla Haddad' );
		$this->h->mappings->seedLive( $this->conn, 'orders', Presets::neutralOrders(), 'Layla Haddad' );

		$products = new ProductMeta();
		$builder  = new PayloadBuilder(
			$store,
			new WooOrderReader( $products ),
			new RuleEngine( TransformRegistry::standard() ),
			$this->taxes,
			new SkuMatcher( new CoreIdMapStore( $this->h->idMap ), new CoreConnectionSource( $this->h->connections ), $this->h->registry ),
			$this->h->registry,
			new CanonicalFields(),
			$log
		);
		$this->processor = new ItemProcessor(
			$this->h->connections,
			$this->h->queue,
			$this->h->idMap,
			$this->h->state,
			$this->h->idempotency,
			$this->h->logger,
			$this->h->retry,
			$this->h->classifier,
			$this->h->breaker,
			$this->h->pause,
			$this->h->clock,
			fn() => $this->h->registry,
			fn() => $builder
		);

		$this->gateway = new CoreQueueGateway(
			fn( string $key ): object => match ( $key ) {
				Services::ENQUEUER         => $this->h->enqueuer,
				Services::QUEUE_REPOSITORY => $this->h->queue,
				Services::QUEUE_SERVICE    => $this->h->service,
				default                    => $this->h->state,
			}
		);
		$orders        = new OrderMeta();
		$mirror        = new StateMirror( $orders, $this->gateway, $log );
		$mirror->register();
		( new QueueDisplay() )->register();
		$this->hooks = new OrderHooks( $this->gateway, $orders, $products, $mirror, $log, $this->h->clock );

		$this->h->connector->script[] = function ( $item, array $payload ): void {
			$this->sent[] = $payload;
		};
		$this->order = $this->placeOrder();
	}

	protected function tearDown(): void {
		WooRegistry::reset();
		parent::tearDown();
	}

	/**
	 * The sample order of the design boards: Noor Al-Mansouri, 2 bowls, 5% VAT, cash on delivery.
	 */
	private function placeOrder( int $id = 10490 ): \WC_Order {
		$bowl = new \WC_Product( array( 'id' => 501, 'sku' => 'CS-BOWL-02' ) );
		WooRegistry::$products[501] = $bowl;
		$line = new \WC_Order_Item_Product(
			array(
				'product'    => $bowl,
				'product_id' => 501,
				'name'       => 'Stoneware bowl set',
				'quantity'   => 2,
				'subtotal'   => '438.00',
				'total'      => '438.00',
				'total_tax'  => '21.90',
				'taxes'      => array( 'total' => array( 7 => '21.90' ) ),
			)
		);
		$tax                = new \WC_Order_Item_Tax( array( 'rate_id' => 7, 'label' => 'VAT', 'rate_percent' => 5, 'tax_total' => '21.90', 'shipping_tax_total' => '0' ) );
		$order              = new \WC_Order(
			array(
				'id'                   => $id,
				'status'               => 'processing',
				'currency'             => 'AED',
				'total'                => '459.90',
				'subtotal'             => '438.00',
				'shipping_total'       => '0.00',
				'total_tax'            => '21.90',
				'payment_method'       => 'cod',
				'billing_first_name'   => 'نور',
				'billing_last_name'    => 'المنصوري',
				'billing_email'        => 'Noor@Example.com',
				'billing_phone'        => '050 123 4567',
				'billing_country'      => 'AE',
				'billing_state'        => 'DU',
				'date_created'         => new \DateTimeImmutable( '2026-11-02 12:00:00', new \DateTimeZone( 'UTC' ) ),
			)
		);
		$order->items       = array( 'line_item' => array( $line ), 'tax' => array( $tax ) );
		WooRegistry::$orders[ $id ] = $order;
		return $order;
	}

	/**
	 * Claim and process the next item.
	 */
	private function work(): Outcome {
		$items = $this->h->queue->claim( 5, 60, 'flow-test' );
		$this->assertNotEmpty( $items, 'Something was waiting.' );
		return $this->processor->process( $items[0] );
	}

	public function test_checkout_to_synced_and_back_onto_the_order(): void {
		$this->hooks->onClassicCheckout( 10490, array(), $this->order );

		// The order screen knows at once, and the queue lists have something to show.
		$this->assertSame( 'queued', $this->order->meta[ OrderMeta::STATE ] );
		$row = (array) $this->h->wpdb->get_row( "SELECT * FROM {$this->h->tables->queue()} WHERE entity_id = '10490'", ARRAY_A ); // phpcs:ignore
		$this->assertSame( 'order:10490', $row['partition_key'] );
		$this->assertSame( '10490', $row['entity_label'] );
		$this->assertSame( 'نور المنصوري', $row['customer_name'] );
		$this->assertSame( '45990', (string) $row['total_minor'] );
		$this->assertStringContainsString( 'CS-BOWL-02', (string) $row['search_text'] );
		$this->assertStringContainsString( 'noor@example.com', strtolower( (string) $row['search_text'] ) );

		// The worker builds the payload at send time from the live mapping and the live order.
		$this->assertSame( Outcome::Synced, $this->work() );
		$payload = $this->sent[0];
		$this->assertSame( 'WC-10490', $payload['reference'] );
		$this->assertSame( 'نور المنصوري', $payload['customer']['name'] );
		$this->assertSame( 'noor@example.com', $payload['customer']['email'] );
		$this->assertSame( '+971501234567', $payload['customer']['phone'] );
		$this->assertSame( 'AE-DU', $payload['customer']['state'] );
		$this->assertSame( 'Pay on delivery', $payload['payment_term'] );
		$this->assertSame( array( array( 'sku' => 'CS-BOWL-02', 'quantity' => 2, 'unit_price' => 21900, 'tax_code' => 'VAT 5% (AE)' ) ), $payload['lines'] );
		$this->assertSame( 45990, $payload['total'] );

		// The queue's hook copied the result onto the order.
		$this->assertSame( 'synced', $this->order->meta[ OrderMeta::STATE ] );
		$this->assertNotSame( '', (string) $this->order->meta[ OrderMeta::EXTERNAL_REF ] );
		$this->assertSame( 1, (int) $this->order->meta[ OrderMeta::MAPPING_VERSION ] );
		$this->assertSame( 0, $this->order->saves, 'The order itself was never saved.' );
	}

	public function test_the_payload_is_built_from_the_order_as_it_is_when_sent_not_as_it_was_when_queued(): void {
		$this->hooks->onClassicCheckout( 10490, array(), $this->order );
		$this->order->props['billing_phone'] = '055 999 8888';
		$this->work();
		$this->assertSame( '+971559998888', $this->sent[0]['customer']['phone'] );
	}

	public function test_an_unmapped_tax_rate_fails_the_item_before_anything_is_sent_and_the_order_shows_it(): void {
		$this->taxes->save( $this->conn, array(), null, 'Layla Haddad' );
		$this->hooks->onClassicCheckout( 10490, array(), $this->order );
		$this->assertSame( Outcome::Failed, $this->work() );

		$this->assertSame( array(), $this->sent, 'Nothing reached the system.' );
		$this->assertSame( 'failed', $this->order->meta[ OrderMeta::STATE ] );
		$row = $this->h->queue->findRow( (int) $this->h->wpdb->get_var( "SELECT id FROM {$this->h->tables->queue()} LIMIT 1" ) ); // phpcs:ignore
		$this->assertSame( ErrorCode::Map002->value, $row['error_code'] );
		$this->assertSame( 'Tax code not mapped for United Arab Emirates 5%.', $row['error_message'] );
	}

	public function test_fixing_the_tax_code_and_retrying_sends_the_order(): void {
		$this->taxes->save( $this->conn, array(), null, 'Layla Haddad' );
		$this->hooks->onClassicCheckout( 10490, array(), $this->order );
		$this->work();
		$this->assertSame( 'failed', $this->order->meta[ OrderMeta::STATE ] );

		$this->taxes->save( $this->conn, array( array( 'country' => 'AE', 'rate_percent' => 5, 'system_code' => 'VAT 5% (AE)' ) ), null, 'Layla Haddad' );
		$this->assertTrue( $this->gateway->retry( (int) $this->gateway->latestForOrder( 10490 )['id'] ) );
		$this->h->clock->advance( 120 );
		$this->assertSame( Outcome::Synced, $this->work() );
		$this->assertSame( 'synced', $this->order->meta[ OrderMeta::STATE ] );
	}

	public function test_a_missing_buyer_tax_number_stops_a_saudi_company_order(): void {
		$sa = $this->placeOrder( 10491 );
		$sa->props['billing_country'] = 'SA';
		$sa->props['billing_company'] = 'شركة الأمل';
		$this->hooks->onClassicCheckout( 10491, array(), $sa );
		$this->assertSame( Outcome::Failed, $this->work() );
		$this->assertSame( 'failed', $sa->meta[ OrderMeta::STATE ] );
		$this->assertSame( array(), $this->sent );
	}

	public function test_a_refund_waits_behind_its_order_and_is_built_with_the_refund_section(): void {
		$this->taxes->save( $this->conn, array(), null, 'Layla Haddad' );
		$this->hooks->onClassicCheckout( 10490, array(), $this->order );
		$this->work();

		$this->order->meta[ OrderMeta::STATE ] = 'failed';
		$refund                                = new \WC_Order_Refund( array( 'id' => 10500, 'parent_id' => 10490, 'amount' => '219.00', 'reason' => 'Damaged' ) );
		WooRegistry::$orders[10500]            = $refund;
		$this->hooks->onRefundCreated( 10500 );

		$refundRow = (array) $this->h->wpdb->get_row( "SELECT * FROM {$this->h->tables->queue()} WHERE entity_type = 'refund'", ARRAY_A ); // phpcs:ignore
		$this->assertSame( 'order:10490', $refundRow['partition_key'], 'The refund is ordered after the order that failed.' );
		$this->assertSame( '10490', $refundRow['entity_label'] );
		$this->assertSame( 21900, (int) $refundRow['total_minor'] );
		$this->assertSame( array(), $this->h->queue->claim( 5, 60, 'flow-test' ), 'A failed earlier step blocks the refund.' );
	}

	public function test_an_edited_synced_order_queues_one_update_at_shutdown(): void {
		$this->hooks->onClassicCheckout( 10490, array(), $this->order );
		$this->work();
		$this->sent = array();
		$this->h->connector->script[] = function ( $item, array $payload ): void {
			$this->sent[] = $payload;
		};

		$this->order->props['billing_city'] = 'Abu Dhabi';
		$this->hooks->onOrderUpdated( 10490 );
		$this->hooks->onOrderUpdated( 10490 );
		$this->hooks->flushUpdates();

		$updates = $this->h->wpdb->get_col( "SELECT id FROM {$this->h->tables->queue()} WHERE event = 'updated'" ); // phpcs:ignore
		$this->assertCount( 1, $updates );
		$this->assertSame( 'queued', $this->order->meta[ OrderMeta::STATE ] );
	}

	public function test_sync_again_forces_a_resend_even_when_nothing_changed(): void {
		$this->hooks->onClassicCheckout( 10490, array(), $this->order );
		$this->work();
		$this->sent = array();
		$this->h->connector->script[] = function ( $item, array $payload ): void {
			$this->sent[] = $payload;
		};
		$this->assertNotNull( $this->gateway->syncAgain( 10490 ) );
		$this->work();
		$this->assertCount( 1, $this->sent, 'The system received the order again.' );
	}

	public function test_skipping_from_the_queue_hook_updates_the_order_through_the_generic_hook(): void {
		$this->hooks->onClassicCheckout( 10490, array(), $this->order );
		$id = (int) $this->gateway->latestForOrder( 10490 )['id'];
		$this->assertTrue( $this->gateway->skip( $id, 'Test order' ) );
		do_action( 'estasoft_sync_item_state_changed', (object) array( 'id' => $id ) );
		$this->assertSame( 'skipped', $this->order->meta[ OrderMeta::STATE ] );
		$this->assertSame( 'Test order', ( new OrderMeta() )->read( $this->order )->skip['reason'] );
	}
}
