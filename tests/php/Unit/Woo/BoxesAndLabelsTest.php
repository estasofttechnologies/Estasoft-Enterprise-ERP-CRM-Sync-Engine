<?php
/**
 * The product box, the customer link, queue display values, state chips, time labels and action notices.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Woo;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\WooFixedClock;
use Estasoft\SyncEngine\Tests\Support\WooRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\InMemoryIdMap;
use Estasoft\SyncEngine\Woo\ActionNotices;
use Estasoft\SyncEngine\Woo\CustomerLink;
use Estasoft\SyncEngine\Woo\Ports\ConnectionInfo;
use Estasoft\SyncEngine\Woo\ProductBox;
use Estasoft\SyncEngine\Woo\ProductMatchRecorder;
use Estasoft\SyncEngine\Woo\ProductMeta;
use Estasoft\SyncEngine\Woo\QueueDisplay;
use Estasoft\SyncEngine\Woo\StateChip;
use Estasoft\SyncEngine\Woo\TimeLabel;

final class BoxesAndLabelsTest extends WooTestCase {

	private function info( ?int $active = 1 ): ConnectionInfo {
		return new class( $active ) implements ConnectionInfo {
			public function __construct( private ?int $active ) {
			}
			public function activeId(): ?int {
				return $this->active;
			}
			public function systemName( ?int $connectionId = null ): string {
				return 'Odoo';
			}
		};
	}

	private function productBox( ?\Closure $buffer = null ): ProductBox {
		return new ProductBox( new ProductMeta(), $this->info(), new StateChip(), new TimeLabel( new WooFixedClock( '2026-11-02 11:45:00' ) ), 'estasoft_sync_manage', $buffer );
	}

	public function test_a_matched_product_shows_the_systems_reference_and_stock(): void {
		$product = $this->product(
			1,
			array( 'sku' => 'CS-BOWL-04L', 'stock_quantity' => 12 ),
			array(
				ProductMeta::MATCH         => 'matched',
				ProductMeta::EXTERNAL_REF  => 'CS-BOWL-04L',
				ProductMeta::LAST_STOCK    => '14',
				ProductMeta::LAST_STOCK_AT => '2026-11-02 11:35:00',
			)
		);
		$html = $this->productBox( static fn(): int => 2 )->html( $product );
		$this->assertStringContainsString( 'estasoft-sync-chip--synced', $html );
		$this->assertStringContainsString( 'Matched', $html );
		$this->assertStringContainsString( '<dt>Odoo reference</dt><dd dir="auto">CS-BOWL-04L</dd>', $html );
		$this->assertStringContainsString( '<dt>Odoo stock</dt><dd dir="auto">14</dd>', $html );
		$this->assertStringContainsString( '12 (buffer 2)', $html );
		$this->assertStringContainsString( 'Today 11:35', $html );
		$this->assertStringContainsString( 'Stock comes from Odoo. Changing the quantity here is replaced at the next stock run.', $html );
		$this->assertStringContainsString( 'Exclude this product from syncing', $html );
		$this->assertStringContainsString( 'name="estasoft_sync_product_box_nonce" value="nonce-for-estasoft_sync_product_box"', $html );
	}

	public function test_a_product_the_system_does_not_know_is_not_matched(): void {
		$product = $this->product( 2, array( 'sku' => 'CS-BOWL-04' ), array( ProductMeta::MATCH => 'unmatched' ) );
		$html    = $this->productBox()->html( $product );
		$this->assertStringContainsString( 'Not matched', $html );
		$this->assertStringContainsString( 'SKU CS-BOWL-04 was not found in Odoo.', $html );
		$this->assertStringContainsString( 'Map to a Odoo product', $html );
	}

	public function test_a_product_without_a_sku(): void {
		$html = $this->productBox()->html( $this->product( 3 ) );
		$this->assertStringContainsString( 'No SKU', $html );
		$this->assertStringContainsString( 'This product has no SKU, so it cannot be matched.', $html );
		$this->assertStringContainsString( 'href="#_sku"', $html );
	}

	public function test_a_product_never_looked_up_is_not_called_unmatched(): void {
		$html = $this->productBox()->html( $this->product( 4, array( 'sku' => 'NEW-1' ) ) );
		$this->assertStringContainsString( 'Not checked yet', $html );
		$this->assertStringNotContainsString( 'was not found', $html );
		$this->assertStringContainsString( 'The SKU is looked up the first time an order contains this product.', $html );
	}

	public function test_a_variable_product_without_a_sku_is_not_called_unmatched(): void {
		$html = $this->productBox()->html( $this->product( 5, array( 'type' => 'variable' ) ) );
		$this->assertStringNotContainsString( 'No SKU', $html );
		$this->assertStringContainsString( 'Each variation is matched by its own SKU.', $html );
	}

	public function test_the_exclude_box_is_checked_for_an_excluded_product_and_escapes_the_sku(): void {
		$product = $this->product( 6, array( 'sku' => '<b>x</b>' ), array( ProductMeta::EXCLUDE => 'yes', ProductMeta::MATCH => 'unmatched' ) );
		$html    = $this->productBox()->html( $product );
		$this->assertStringContainsString( 'checked="checked"', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
	}

	public function test_saving_the_box_excludes_or_includes_the_product(): void {
		$product = $this->product( 7 );
		$box     = $this->productBox();
		$_POST   = array( 'estasoft_sync_product_box_nonce' => 'nonce-for-estasoft_sync_product_box', 'estasoft_sync_exclude' => '1' );
		$box->save( 7 );
		$this->assertSame( 'yes', $product->meta[ ProductMeta::EXCLUDE ] );
		$_POST = array( 'estasoft_sync_product_box_nonce' => 'nonce-for-estasoft_sync_product_box' );
		$box->save( 7 );
		$this->assertArrayNotHasKey( ProductMeta::EXCLUDE, $product->meta );
	}

	public function test_saving_without_the_nonce_or_the_capability_changes_nothing(): void {
		$product = $this->product( 8, array(), array( ProductMeta::EXCLUDE => 'yes' ) );
		$box     = $this->productBox();
		$_POST   = array( 'estasoft_sync_exclude' => '1' );
		$box->save( 8 );
		$_POST = array( 'estasoft_sync_product_box_nonce' => 'forged' );
		$box->save( 8 );
		WooRegistry::$can = false;
		$_POST            = array( 'estasoft_sync_product_box_nonce' => 'nonce-for-estasoft_sync_product_box' );
		$box->save( 8 );
		$this->assertSame( 'yes', $product->meta[ ProductMeta::EXCLUDE ], 'Quick edit, imports and forged requests leave it alone.' );
		$this->assertSame( 0, $product->metaSaves );
	}

	public function test_the_product_verdict_is_recorded_from_the_mapping_event(): void {
		$product  = $this->product( 9 );
		$recorder = new ProductMatchRecorder( new ProductMeta() );
		$recorder->record( 1, 9, 'X', false );
		$this->assertSame( 'unmatched', $product->meta[ ProductMeta::MATCH ] );
		$recorder->record( 1, 9, 'X', true );
		$this->assertSame( 'matched', $product->meta[ ProductMeta::MATCH ] );
		$recorder->record( 1, 0, 'X', true );
		$recorder->record( 1, 404, 'X', true );
		$this->addToAssertionCount( 1 );
	}

	public function test_the_customer_section_links_to_the_contact_in_the_system(): void {
		$idMap = new InMemoryIdMap();
		$idMap->link( 1, 'customer', '42', '312', 'Layla Haddad', 'https://odoo.example/web#id=312', null, false );
		$link = new CustomerLink( $idMap, $this->info(), 'estasoft_sync_manage' );
		$html = $link->html( 42 );
		$this->assertStringContainsString( 'Odoo contact', $html );
		$this->assertStringContainsString( '<a href="https://odoo.example/web#id=312" rel="noopener noreferrer">Layla Haddad</a>', $html );
		$this->assertSame( '', $link->html( 43 ), 'No section for a customer who was never synced.' );
		$this->assertSame( '', ( new CustomerLink( $idMap, $this->info( null ), 'estasoft_sync_manage' ) )->html( 42 ), 'No section without a connection.' );
	}

	public function test_the_customer_section_shows_the_id_when_there_is_no_reference_and_escapes(): void {
		$idMap = new InMemoryIdMap();
		$idMap->link( 1, 'customer', '42', '<i>312</i>', null, null, null, false );
		$html = ( new CustomerLink( $idMap, $this->info(), 'estasoft_sync_manage' ) )->html( 42 );
		$this->assertStringContainsString( '&lt;i&gt;312&lt;/i&gt;', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	public function test_queue_display_values_for_an_order(): void {
		$bowl = $this->product( 501, array( 'sku' => 'CS-BOWL-02' ) );
		$this->order( 10490, array( 'billing_first_name' => 'نور', 'billing_last_name' => 'المنصوري', 'total' => '486.15' ), array( 'line_item' => array( $this->line( $bowl ), $this->line( $this->product( 502, array( 'sku' => 'CS-BOWL-02' ) ) ) ) ) );
		$display = ( new QueueDisplay() )->fill( array(), EntityType::Order, '10490' );
		$this->assertSame( '10490', $display['label'] );
		$this->assertSame( 'نور المنصوري', $display['customer'] );
		$this->assertSame( 48615, $display['total_minor'] );
		$this->assertSame( 'AED', $display['currency'] );
		$this->assertSame( array( 'noor@example.com', 'CS-BOWL-02' ), $display['search'] );
	}

	public function test_queue_display_keeps_what_the_caller_gave(): void {
		$this->order( 10490 );
		$display = ( new QueueDisplay() )->fill( array( 'label' => 'Custom' ), EntityType::Order, '10490' );
		$this->assertSame( 'Custom', $display['label'] );
		$this->assertSame( 'Noor Al-Mansouri', $display['customer'] );
	}

	public function test_queue_display_for_a_refund_uses_the_orders_number_and_the_refunded_amount(): void {
		$this->order( 10490, array( 'order_number' => 'WC-10490' ) );
		WooRegistry::$orders[10500] = new \WC_Order_Refund( array( 'id' => 10500, 'parent_id' => 10490, 'amount' => '219.00' ) );
		$display                    = ( new QueueDisplay() )->fill( array(), EntityType::Refund, '10500' );
		$this->assertSame( 'WC-10490', $display['label'] );
		$this->assertSame( 21900, $display['total_minor'] );
	}

	public function test_queue_display_ignores_what_it_cannot_read(): void {
		$this->assertSame( array( 'x' => 1 ), ( new QueueDisplay() )->fill( array( 'x' => 1 ), EntityType::Order, '404' ) );
		$this->assertSame( array(), ( new QueueDisplay() )->fill( array(), EntityType::Stock, 'SKU' ) );
		$this->assertSame( array(), ( new QueueDisplay() )->fill( 'junk', 'junk', 5 ) );
	}

	public function test_state_chips_have_an_icon_and_a_word_for_every_state(): void {
		$chips = new StateChip();
		foreach ( array( 'queued', 'syncing', 'synced', 'retrying', 'failed', 'skipped', 'shadow', 'excluded', 'matched', 'unmatched', 'no_sku', 'unchecked', 'anything else' ) as $state ) {
			$html = $chips->html( $state );
			$this->assertStringContainsString( 'aria-hidden="true"', $html, $state );
			$this->assertMatchesRegularExpression( '#estasoft-sync-chip__word">[A-Za-z ]+</span>#', $html, $state );
		}
		$this->assertStringContainsString( '<span class="estasoft-sync-chip__extra">&lt;11:41&gt;</span>', $chips->html( 'retrying', '<11:41>' ) );
	}

	public function test_times_are_written_for_people(): void {
		$times = new TimeLabel( new WooFixedClock( '2026-11-02 11:45:00' ) );
		$this->assertSame( 'Today 07:39', $times->format( '2026-11-02 07:39:00' ) );
		$this->assertSame( 'Today 07:39', $times->format( '2026-11-02T07:39:00Z' ) );
		$this->assertSame( 'Yesterday 23:10', $times->format( '2026-11-01 23:10:00' ) );
		$this->assertSame( '28 Oct, 14:05', $times->format( '2026-10-28 14:05:00' ) );
		$this->assertSame( '', $times->format( '' ) );
		$this->assertSame( '', $times->format( 'not a date' ) );
	}

	public function test_action_notices_only_know_their_own_words(): void {
		$notices = new ActionNotices( 'estasoft_sync_manage' );
		$this->assertSame( '3 orders were put back in the queue.', $notices->message( 'retried', 3 ) );
		$this->assertSame( '1 order was put back in the queue.', $notices->message( 'retried', 1 ) );
		$this->assertSame( 'The order was added to the queue.', $notices->message( 'queued', 1 ) );
		$this->assertStringContainsString( '2 orders will not be synced', $notices->message( 'skipped', 2 ) );
		$this->assertSame( '', $notices->message( '<script>alert(1)</script>' ) );
		$this->assertSame( '', $notices->message( 'unknown' ) );
	}

	public function test_the_notice_prints_for_a_known_key_and_never_echoes_the_address(): void {
		$notices                     = new ActionNotices( 'estasoft_sync_manage' );
		$_GET['estasoft_sync_notice'] = 'retried';
		$_GET['estasoft_sync_count']  = '2';
		ob_start();
		$notices->show();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( '2 orders were put back in the queue.', $html );

		$_GET['estasoft_sync_notice'] = '<script>alert(1)</script>';
		ob_start();
		$notices->show();
		$this->assertSame( '', ob_get_clean() );

		$_GET['estasoft_sync_notice'] = 'failed';
		ob_start();
		$notices->show();
		$this->assertStringContainsString( 'notice-error', (string) ob_get_clean() );

		WooRegistry::$can = false;
		ob_start();
		$notices->show();
		$this->assertSame( '', ob_get_clean() );
	}
}
