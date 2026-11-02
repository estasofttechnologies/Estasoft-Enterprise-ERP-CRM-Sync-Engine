<?php
/**
 * The order box: every state, escaping, and the buttons.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Woo;

use Estasoft\SyncEngine\Tests\Support\WooFixedClock;
use Estasoft\SyncEngine\Tests\Support\WooFixedGoLive;
use Estasoft\SyncEngine\Tests\Support\WooRecordingQueue;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\RecordingLog;
use Estasoft\SyncEngine\Woo\GoLive;
use Estasoft\SyncEngine\Woo\OrderActions;
use Estasoft\SyncEngine\Woo\OrderBox;
use Estasoft\SyncEngine\Woo\OrderEligibility;
use Estasoft\SyncEngine\Woo\OrderMeta;
use Estasoft\SyncEngine\Woo\Ports\ConnectionInfo;
use Estasoft\SyncEngine\Woo\ProductMeta;
use Estasoft\SyncEngine\Woo\StateChip;
use Estasoft\SyncEngine\Woo\StateMirror;
use Estasoft\SyncEngine\Woo\TimeLabel;

final class OrderBoxTest extends WooTestCase {

	private WooRecordingQueue $queue;
	private OrderBox $box;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new WooRecordingQueue();
		$orders      = new OrderMeta();
		$info        = new class() implements ConnectionInfo {
			public function activeId(): ?int {
				return 1;
			}
			public function systemName( ?int $connectionId = null ): string {
				return 'Odoo';
			}
		};
		$eligibility = new OrderEligibility( new ProductMeta() );
		$this->box   = new OrderBox(
			$this->queue,
			$orders,
			$eligibility,
			new GoLive( new WooFixedGoLive( '2026-11-01 08:00:00' ) ),
			new OrderActions( $this->queue, new StateMirror( $orders, $this->queue, new RecordingLog() ), $eligibility ),
			$info,
			new StateChip(),
			new TimeLabel( new WooFixedClock( '2026-11-02 11:45:00' ) ),
			'estasoft_sync_manage',
			'https://example.test/wp-admin/admin.php?page=estasoft-sync'
		);
	}

	/**
	 * The box for an order in a state.
	 *
	 * @param string              $state Stored state.
	 * @param array<string,mixed> $meta  More meta.
	 * @param array<string,mixed> $item  Queue item fields; null for no item.
	 */
	private function html( string $state, array $meta = array(), ?array $item = array() ): string {
		$order = $this->order( 10490, array(), array(), array( OrderMeta::STATE => $state ) + $meta );
		if ( null !== $item ) {
			$this->queue->addOrderItem( 7, 10490, $state, $item );
		}
		return $this->box->html( $order );
	}

	public function test_synced(): void {
		$html = $this->html(
			'synced',
			array(
				OrderMeta::EXTERNAL_REF    => 'S00490',
				OrderMeta::EXTERNAL_URL    => 'https://odoo.example/web#id=490',
				OrderMeta::INVOICE_NUMBER  => 'INV/2026/1201',
				OrderMeta::INVOICE_STATUS  => 'Not required for this sale',
				OrderMeta::LAST_SYNC       => '2026-11-02 07:39:00',
				OrderMeta::MAPPING_VERSION => '7',
			)
		);
		$this->assertStringContainsString( 'estasoft-sync-chip--synced', $html );
		$this->assertStringContainsString( 'Synced', $html );
		$this->assertStringContainsString( '<dt>Odoo order</dt><dd dir="auto"><a href="https://odoo.example/web#id=490" rel="noopener noreferrer">S00490</a></dd>', $html );
		$this->assertStringContainsString( 'INV/2026/1201', $html );
		$this->assertStringContainsString( 'Not required for this sale', $html );
		$this->assertStringContainsString( 'Today 07:39', $html );
		$this->assertStringContainsString( 'Version 7', $html );
		$this->assertStringContainsString( 'If you change this order, an update is sent to Odoo.', $html );
		$this->assertStringContainsString( '>Sync again<', $html );
		$this->assertStringContainsString( '>View log<', $html );
	}

	public function test_queued(): void {
		$html = $this->html( 'queued' );
		$this->assertStringContainsString( 'estasoft-sync-chip--queued', $html );
		$this->assertStringContainsString( 'Added', $html );
		$this->assertStringContainsString( 'Checkout was not delayed.', $html );
		$this->assertStringContainsString( '>Sync now<', $html );
	}

	public function test_retrying(): void {
		$html = $this->html( 'retrying', array(), array( 'attempt' => 1, 'max_attempts' => 5, 'error_message' => 'Odoo answered with a server error (503).', 'next_attempt_at' => '2026-11-02T11:46:00Z' ) );
		$this->assertStringContainsString( 'Retrying', $html );
		$this->assertStringContainsString( 'Odoo answered with a server error (503).', $html );
		$this->assertStringContainsString( '1 of 5', $html );
		$this->assertStringContainsString( 'Today 11:46', $html );
		$this->assertStringContainsString( 'Nothing to do. It will try again by itself.', $html );
		$this->assertStringContainsString( '>Retry now<', $html );
	}

	public function test_failed(): void {
		$html = $this->html( 'failed', array(), array( 'error_code' => 'SYNC-MAP-001', 'error_message' => 'Product CS-BOWL-04 was not found in Odoo.' ) );
		$this->assertStringContainsString( 'estasoft-sync-chip--failed', $html );
		$this->assertStringContainsString( 'SYNC-MAP-001', $html );
		$this->assertStringContainsString( 'Product CS-BOWL-04 was not found in Odoo.', $html );
		$this->assertStringContainsString( 'Waits. Later steps for this order wait too.', $html );
		$this->assertStringContainsString( 'Create it in the system or map the SKU, then retry the group.', $html );
		$this->assertStringContainsString( '>Retry<', $html );
		$this->assertStringContainsString( '>Skip<', $html );
	}

	public function test_skipped(): void {
		$html = $this->html( 'skipped', array( OrderMeta::SKIP => '{"reason":"Test order","by":"Layla Haddad","at":"2026-11-02 10:05:00"}' ) );
		$this->assertStringContainsString( 'Skipped', $html );
		$this->assertStringContainsString( 'by Layla Haddad, Today 10:05', $html );
		$this->assertStringContainsString( 'Test order', $html );
		$this->assertStringContainsString( 'It is not sent to Odoo. You can restore it.', $html );
		$this->assertStringContainsString( '>Restore<', $html );
	}

	public function test_shadow_mode(): void {
		$html = $this->html( 'shadow', array(), array( 'mode' => 'shadow', 'finished_at' => '2026-11-02T07:34:00Z' ) );
		$this->assertStringContainsString( 'Shadow mode', $html );
		$this->assertStringContainsString( 'Nothing was sent to Odoo.', $html );
		$this->assertStringContainsString( '>See the comparison<', $html );
		$this->assertStringNotContainsString( '>Sync again<', $html );
	}

	public function test_an_order_placed_before_go_live_is_not_sent_and_can_be_sent_by_hand(): void {
		$order = $this->order( 10490, array( 'date_created' => new \DateTimeImmutable( '2026-10-20 07:34:00', new \DateTimeZone( 'UTC' ) ) ) );
		$html  = $this->box->html( $order );
		$this->assertStringContainsString( 'Not sent', $html );
		$this->assertStringContainsString( 'Placed before syncing went live', $html );
		$this->assertStringContainsString( '>Send this order<', $html );
		$this->assertStringNotContainsString( 'View log', $html );
	}

	public function test_an_order_placed_after_go_live_that_is_not_queued_yet_says_so_and_can_be_sent(): void {
		$html = $this->html( '', array(), null );
		$this->assertStringContainsString( 'Not added to the queue yet', $html );
		$this->assertStringNotContainsString( 'Placed before syncing went live', $html );
		$this->assertStringContainsString( '>Send this order<', $html );
	}

	public function test_a_draft_order_has_no_send_button(): void {
		foreach ( array( 'auto-draft', 'checkout-draft', 'trash' ) as $status ) {
			$html = $this->box->html( $this->order( 10490, array( 'status' => $status ) ) );
			$this->assertStringContainsString( 'Save the order first', $html, $status );
			$this->assertStringNotContainsString( 'Send this order', $html, $status );
			$this->assertStringNotContainsString( 'admin-post.php', $html, $status );
		}
	}

	public function test_a_failed_order_whose_queue_item_is_missing_says_where_to_look(): void {
		$html = $this->html( 'failed', array(), null );
		$this->assertStringContainsString( 'estasoft-sync-chip--failed', $html );
		$this->assertStringContainsString( 'The details are in the log.', $html );
		$this->assertStringContainsString( '>Retry<', $html );
	}

	public function test_an_order_with_only_excluded_products_is_marked_excluded(): void {
		$gift  = $this->product( 700, array( 'sku' => 'GIFT' ), array( ProductMeta::EXCLUDE => 'yes' ) );
		$order = $this->order( 10490, array(), array( 'line_item' => array( $this->line( $gift, array( 'name' => 'Gift card' ) ) ) ) );
		$html  = $this->box->html( $order );
		$this->assertStringContainsString( 'Excluded', $html );
		$this->assertStringContainsString( 'Every product on this order is marked Exclude from syncing', $html );
		$this->assertStringNotContainsString( 'Send this order', $html );
	}

	public function test_an_order_with_some_excluded_products_says_which(): void {
		$gift  = $this->product( 700, array( 'sku' => 'GIFT' ), array( ProductMeta::EXCLUDE => 'yes' ) );
		$bowl  = $this->product( 701, array( 'sku' => 'BOWL' ) );
		$order = $this->order( 10490, array(), array( 'line_item' => array( $this->line( $gift, array( 'name' => 'Gift card' ) ), $this->line( $bowl ) ) ), array( OrderMeta::STATE => 'synced' ) );
		$this->queue->addOrderItem( 7, 10490, 'synced' );
		$this->assertStringContainsString( 'Contains a product marked Exclude from syncing: Gift card. The order is sent without that line.', $this->box->html( $order ) );
	}

	public function test_everything_that_comes_from_data_is_escaped(): void {
		$evil = '<img src=x onerror=alert(1)>';
		$html = $this->html(
			'failed',
			array( OrderMeta::EXTERNAL_REF => $evil, OrderMeta::EXTERNAL_URL => 'javascript:alert(1)' ),
			array( 'error_code' => $evil, 'error_message' => $evil )
		);
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );

		$synced = $this->html( 'synced', array( OrderMeta::EXTERNAL_REF => $evil, OrderMeta::EXTERNAL_URL => 'javascript:alert(1)', OrderMeta::INVOICE_NUMBER => '"><script>alert(1)</script>' ) );
		$this->assertStringNotContainsString( '<script>', $synced );
		$this->assertStringNotContainsString( 'javascript:', $synced, 'A bad address is dropped by esc_url.' );
	}

	public function test_buttons_are_links_with_a_nonce_for_that_action_and_order(): void {
		$html = $this->html( 'failed', array(), array( 'error_code' => 'SYNC-MAP-001', 'error_message' => 'x' ) );
		$this->assertMatchesRegularExpression( '#href="https://example\.test/wp-admin/admin-post\.php\?action=estasoft_sync_order_action&amp;do=retry&amp;order=10490&amp;_wpnonce=nonce-for-estasoft_sync_order_retry_10490"#', $html );
		$this->assertStringContainsString( 'nonce-for-estasoft_sync_order_skip_10490', $html );
		$this->assertStringContainsString( 'href="https://example.test/wp-admin/admin.php?page=estasoft-sync#/queue/7"', $html );
	}

	public function test_the_box_has_no_form_because_it_sits_inside_the_order_form(): void {
		foreach ( array( 'synced', 'failed', 'skipped', 'queued' ) as $state ) {
			$this->assertStringNotContainsString( '<form', $this->html( $state, array(), array() ) );
		}
	}

	public function test_arabic_values_are_shown_with_automatic_direction(): void {
		$html = $this->html( 'synced', array( OrderMeta::EXTERNAL_REF => 'طلب-٤٩٠' ) );
		$this->assertStringContainsString( 'dir="auto"', $html );
		$this->assertStringContainsString( 'طلب-٤٩٠', $html );
	}

	public function test_the_state_word_is_always_there_not_only_a_colour(): void {
		foreach ( array( 'queued' => 'Queued', 'retrying' => 'Retrying', 'failed' => 'Failed', 'skipped' => 'Skipped', 'synced' => 'Synced', 'shadow' => 'Shadow mode' ) as $state => $word ) {
			$this->assertStringContainsString( '<span class="estasoft-sync-chip__word">' . $word . '</span>', $this->html( $state ), $state );
		}
	}

	public function test_the_box_prints_nothing_for_something_that_is_not_an_order(): void {
		ob_start();
		$this->box->render( null );
		$this->box->render( (object) array( 'ID' => 424242 ) );
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_render_prints_the_box_for_an_order_object_and_for_a_post(): void {
		$this->order( 10490, array(), array(), array( OrderMeta::STATE => 'queued' ) );
		$this->queue->addOrderItem( 7, 10490, 'queued' );
		ob_start();
		$this->box->render( \Estasoft\SyncEngine\Tests\Support\WooRegistry::$orders[10490] );
		$fromOrder = ob_get_clean();
		ob_start();
		$this->box->render( (object) array( 'ID' => 10490 ) );
		$fromPost = ob_get_clean();
		$this->assertStringContainsString( 'estasoft-sync-box', (string) $fromOrder );
		$this->assertSame( $fromOrder, $fromPost );
	}

	public function test_a_person_without_the_capability_sees_nothing(): void {
		\Estasoft\SyncEngine\Tests\Support\WooRegistry::$can = false;
		$this->order( 10490, array(), array(), array( OrderMeta::STATE => 'queued' ) );
		ob_start();
		$this->box->render( \Estasoft\SyncEngine\Tests\Support\WooRegistry::$orders[10490] );
		$this->assertSame( '', ob_get_clean() );
		$this->assertContains( 'estasoft_sync_manage', \Estasoft\SyncEngine\Tests\Support\WooRegistry::$capsAsked );
	}
}
