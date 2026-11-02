<?php
/**
 * The orders list: column, filter, views and bulk actions, for both storage layouts.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Woo;

use Estasoft\SyncEngine\Tests\Support\WooRecordingQueue;
use Estasoft\SyncEngine\Tests\Support\WooRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\RecordingLog;
use Estasoft\SyncEngine\Woo\ActionNotices;
use Estasoft\SyncEngine\Woo\OrderActions;
use Estasoft\SyncEngine\Woo\OrderEligibility;
use Estasoft\SyncEngine\Woo\OrderMeta;
use Estasoft\SyncEngine\Woo\OrdersList;
use Estasoft\SyncEngine\Woo\ProductMeta;
use Estasoft\SyncEngine\Woo\StateChip;
use Estasoft\SyncEngine\Woo\StateMirror;

final class OrdersListTest extends WooTestCase {

	private WooRecordingQueue $queue;
	private OrdersList $list;

	protected function setUp(): void {
		parent::setUp();
		$this->queue = new WooRecordingQueue();
		$orders      = new OrderMeta();
		$this->list  = new OrdersList(
			$orders,
			new OrderActions( $this->queue, new StateMirror( $orders, $this->queue, new RecordingLog() ), new OrderEligibility( new ProductMeta() ) ),
			new StateChip(),
			'estasoft_sync_manage'
		);
	}

	public function test_the_column_goes_after_status(): void {
		$columns = $this->list->addColumn( array( 'cb' => '', 'order_number' => 'Order', 'order_date' => 'Date', 'order_status' => 'Status', 'order_total' => 'Total' ) );
		$this->assertSame( array( 'cb', 'order_number', 'order_date', 'order_status', 'estasoft_sync', 'order_total' ), array_keys( $columns ) );
		$this->assertSame( 'Sync', $columns['estasoft_sync'] );
	}

	public function test_without_a_status_column_it_goes_last(): void {
		$this->assertSame( array( 'a', 'estasoft_sync' ), array_keys( $this->list->addColumn( array( 'a' => 'A' ) ) ) );
		$this->assertSame( array( 'estasoft_sync' ), array_keys( $this->list->addColumn( 'not an array' ) ) );
	}

	public function test_no_column_for_a_person_who_may_not_manage_syncing(): void {
		WooRegistry::$can = false;
		$this->assertSame( array( 'a' => 'A' ), $this->list->addColumn( array( 'a' => 'A' ) ) );
	}

	public function test_the_cell_shows_the_state_word(): void {
		foreach ( array( 'failed' => 'Failed', 'synced' => 'Synced', 'queued' => 'Queued', 'skipped' => 'Skipped', 'shadow' => 'Shadow mode' ) as $state => $word ) {
			$html = $this->list->cell( $this->order( 1, array(), array(), array( OrderMeta::STATE => $state ) ) );
			$this->assertStringContainsString( '>' . $word . '</span>', $html, $state );
		}
	}

	public function test_the_cell_of_a_retrying_order_says_retrying(): void {
		$order = $this->order( 10489, array(), array(), array( OrderMeta::STATE => 'retrying' ) );
		$html  = $this->list->cell( $order );
		$this->assertStringContainsString( 'Retrying', $html );
	}

	public function test_the_cell_of_an_order_never_queued_is_a_dash_with_a_reading_for_screen_readers(): void {
		$html = $this->list->cell( $this->order( 1 ) );
		$this->assertStringContainsString( '&mdash;', $html );
		$this->assertStringContainsString( 'Not synced', $html );
		$this->assertStringContainsString( 'screen-reader-text', $html );
	}

	public function test_cells_print_for_both_storage_layouts(): void {
		$order = $this->order( 7, array(), array(), array( OrderMeta::STATE => 'failed' ) );
		ob_start();
		$this->list->renderCell( 'estasoft_sync', $order );
		$hpos = ob_get_clean();
		ob_start();
		$this->list->renderCell( 'estasoft_sync', 7 );
		$posts = ob_get_clean();
		ob_start();
		$this->list->renderCell( 'order_total', $order );
		$other = ob_get_clean();
		$this->assertStringContainsString( 'Failed', (string) $hpos );
		$this->assertSame( $hpos, $posts );
		$this->assertSame( '', $other, 'Other columns are left alone.' );
	}

	/**
	 * @dataProvider filters
	 * @param string                         $choice   Filter value.
	 * @param array<int,array<string,mixed>> $expected Meta query added.
	 */
	public function test_the_filter_adds_a_meta_query( string $choice, array $expected ): void {
		$_GET['estasoft_sync_state'] = $choice;
		$args                        = $this->list->filterQueryArgs( array( 'status' => array( 'wc-processing' ), 'meta_query' => array( array( 'key' => '_other', 'value' => '1' ) ) ) );
		$this->assertSame( array( 'wc-processing' ), $args['status'] );
		$this->assertSame( array_merge( array( array( 'key' => '_other', 'value' => '1' ) ), $expected ), $args['meta_query'] );
	}

	/**
	 * @return array<string,array{0:string,1:array<int,array<string,mixed>>}>
	 */
	public static function filters(): array {
		return array(
			'failed'     => array( 'failed', array( array( 'key' => '_estasoft_sync_state', 'value' => array( 'failed' ), 'compare' => 'IN' ) ) ),
			'waiting'    => array( 'waiting', array( array( 'key' => '_estasoft_sync_state', 'value' => array( 'queued', 'syncing', 'retrying' ), 'compare' => 'IN' ) ) ),
			'skipped'    => array( 'skipped', array( array( 'key' => '_estasoft_sync_state', 'value' => array( 'skipped' ), 'compare' => 'IN' ) ) ),
			'not synced' => array( 'not_synced', array( array( 'key' => '_estasoft_sync_state', 'compare' => 'NOT EXISTS' ) ) ),
		);
	}

	public function test_an_unknown_filter_value_changes_nothing(): void {
		$_GET['estasoft_sync_state'] = "failed'; DROP TABLE x;--";
		$args                        = array( 'status' => 'any' );
		$this->assertSame( $args, $this->list->filterQueryArgs( $args ) );
		$_GET['estasoft_sync_state'] = 'exploded';
		$this->assertSame( $args, $this->list->filterQueryArgs( $args ) );
		$this->assertSame( array(), $this->list->filterQueryArgs( 'junk' ) );
	}

	public function test_the_filter_is_not_applied_for_a_person_without_the_capability(): void {
		$_GET['estasoft_sync_state'] = 'failed';
		WooRegistry::$can            = false;
		$this->assertSame( array(), $this->list->filterQueryArgs( array() ) );
	}

	public function test_the_dropdown_lists_the_choices_and_marks_the_chosen_one(): void {
		$_GET['estasoft_sync_state'] = 'waiting';
		ob_start();
		$this->list->renderFilter( 'shop_order', 'top' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<select name="estasoft_sync_state"', $html );
		$this->assertStringContainsString( '<option value="waiting" selected="selected">Waiting</option>', $html );
		$this->assertStringContainsString( '<option value="">All sync states</option>', $html );
		$this->assertStringContainsString( 'screen-reader-text', $html );

		ob_start();
		$this->list->renderFilter( 'shop_order', 'bottom' );
		$this->list->renderLegacyFilter( 'post', 'top' );
		$this->assertSame( '', ob_get_clean(), 'Only once, only on the orders list.' );
	}

	public function test_the_views_show_counts_and_mark_the_current_one(): void {
		WooRegistry::$ordersResult = (object) array( 'total' => 5 );
		$_GET['estasoft_sync_state'] = 'failed';
		$views = $this->list->addViews( array( 'all' => '<a>All (333)</a>' ) );
		$this->assertSame( array( 'all', 'estasoft_sync_failed', 'estasoft_sync_waiting', 'estasoft_sync_skipped', 'estasoft_sync_not_synced' ), array_keys( $views ) );
		$this->assertStringContainsString( 'Failed <span class="count">(5)</span>', $views['estasoft_sync_failed'] );
		$this->assertStringContainsString( 'class="current" aria-current="page"', $views['estasoft_sync_failed'] );
		$this->assertStringNotContainsString( 'current', $views['estasoft_sync_waiting'] );
		$this->assertStringContainsString( 'estasoft_sync_state=waiting', $views['estasoft_sync_waiting'] );
		$this->assertCount( 5, WooRegistry::$orderQueries, 'Failed, waiting, skipped, every order, and orders with any state.' );
		$this->assertSame( 1, WooRegistry::$orderQueries[0]['limit'], 'Counting asks for one row and the total.' );
	}

	public function test_orders_not_synced_yet_are_counted_without_asking_for_a_missing_meta_key(): void {
		WooRegistry::$ordersResult = (object) array( 'total' => 5 );
		$this->list->addViews( array() );
		foreach ( WooRegistry::$orderQueries as $query ) {
			foreach ( $query['meta_query'] ?? array() as $clause ) {
				$this->assertNotSame( 'NOT EXISTS', $clause['compare'], 'NOT EXISTS makes the database look at every order.' );
			}
		}
		$stored = WooRegistry::$transients['estasoft_sync_order_view_counts'];
		$this->assertSame( 0, $stored['not_synced'], 'Every order minus the orders that have a state.' );
	}

	public function test_counts_are_kept_for_five_minutes(): void {
		WooRegistry::$ordersResult = (object) array( 'total' => 5 );
		$this->list->addViews( array() );
		$this->list->addViews( array() );
		$this->assertCount( 5, WooRegistry::$orderQueries, 'The second call used the stored counts.' );
		$this->assertSame( 300, WooRegistry::$transientTtls['estasoft_sync_order_view_counts'] );
	}

	public function test_a_bulk_action_clears_the_stored_counts(): void {
		WooRegistry::$ordersResult = (object) array( 'total' => 5 );
		$this->list->addViews( array() );
		$this->assertArrayHasKey( 'estasoft_sync_order_view_counts', WooRegistry::$transients );
		$this->list->handleBulkActions( 'https://example.test/wp-admin/admin.php?page=wc-orders', 'estasoft_sync_retry', array() );
		$this->assertArrayNotHasKey( 'estasoft_sync_order_view_counts', WooRegistry::$transients );
	}

	public function test_a_page_of_orders_asks_the_queue_nothing(): void {
		foreach ( array( 'queued', 'retrying', 'failed', 'synced' ) as $position => $state ) {
			$this->list->cell( $this->order( 100 + $position, array(), array(), array( OrderMeta::STATE => $state ) ) );
		}
		$this->assertSame( array(), $this->queue->calls );
		$this->assertSame( 0, $this->queue->lookups, 'No queue query per row.' );
	}

	public function test_bulk_actions_are_offered_to_people_who_may_use_them(): void {
		$actions = $this->list->addBulkActions( array( 'trash' => 'Move to trash' ) );
		$this->assertSame( array( 'trash', 'estasoft_sync_retry', 'estasoft_sync_again', 'estasoft_sync_skip' ), array_keys( $actions ) );
		$this->assertSame( 'Retry failed orders', $actions['estasoft_sync_retry'] );
		WooRegistry::$can = false;
		$this->assertSame( array( 'trash' => 'Move to trash' ), $this->list->addBulkActions( array( 'trash' => 'Move to trash' ) ) );
	}

	public function test_bulk_retry_acts_on_orders_with_a_history_and_reports_a_count(): void {
		foreach ( array( 1, 2 ) as $id ) {
			$this->order( $id, array(), array(), array( OrderMeta::STATE => 'failed' ) );
			$this->queue->addOrderItem( 10 + $id, $id, 'failed' );
		}
		$this->order( 3 );
		$redirect = $this->list->handleBulkActions( 'https://example.test/wp-admin/admin.php?page=wc-orders', 'estasoft_sync_retry', array( '1', '2', '3', 'abc', '999' ) );
		$this->assertSame( array( 'retry:11', 'retry:12' ), $this->queue->calls );
		$this->assertStringContainsString( ActionNotices::QUERY . '=retried', $redirect );
		$this->assertStringContainsString( ActionNotices::COUNT . '=2', $redirect );
	}

	public function test_bulk_skip_and_sync_again(): void {
		$order = $this->order( 1, array(), array(), array( OrderMeta::STATE => 'failed' ) );
		$this->queue->addOrderItem( 11, 1, 'failed' );
		$this->assertStringContainsString( 'estasoft_sync_notice=skipped', $this->list->handleBulkActions( 'https://x.test/', 'estasoft_sync_skip', array( 1 ) ) );
		$this->assertSame( 'skipped', $order->meta[ OrderMeta::STATE ] );
		$this->assertStringContainsString( 'estasoft_sync_notice=queued', $this->list->handleBulkActions( 'https://x.test/', 'estasoft_sync_again', array( 1 ) ) );
	}

	public function test_bulk_with_nothing_to_do_says_so_and_unknown_actions_are_left_to_others(): void {
		$this->order( 1 );
		$this->assertStringContainsString( 'estasoft_sync_notice=nothing', $this->list->handleBulkActions( 'https://x.test/', 'estasoft_sync_retry', array( 1 ) ) );
		$this->assertSame( 'https://x.test/', $this->list->handleBulkActions( 'https://x.test/', 'trash', array( 1 ) ) );
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_bulk_by_a_person_without_the_capability_does_nothing(): void {
		$this->order( 1, array(), array(), array( OrderMeta::STATE => 'failed' ) );
		$this->queue->addOrderItem( 11, 1, 'failed' );
		WooRegistry::$can = false;
		$this->assertStringContainsString( 'estasoft_sync_notice=failed', $this->list->handleBulkActions( 'https://x.test/', 'estasoft_sync_retry', array( 1 ) ) );
		$this->assertSame( array(), $this->queue->calls );
	}

	public function test_an_old_result_in_the_address_is_replaced_not_stacked(): void {
		$redirect = $this->list->handleBulkActions( 'https://x.test/?estasoft_sync_notice=retried&estasoft_sync_count=9&paged=2', 'estasoft_sync_retry', array() );
		$this->assertSame( 1, substr_count( $redirect, 'estasoft_sync_notice' ) );
		$this->assertStringContainsString( 'paged=2', $redirect );
	}
}
