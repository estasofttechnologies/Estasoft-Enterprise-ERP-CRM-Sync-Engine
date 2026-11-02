<?php
/**
 * The small pure classes of the Jobs module.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Jobs;


use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Jobs\Invoices\InvoiceStatus;
use Estasoft\SyncEngine\Jobs\Onboarding\OnboardingView;
use Estasoft\SyncEngine\Jobs\Reconciliation\ReconSettings;
use Estasoft\SyncEngine\Jobs\Stock\StockStore;
use Estasoft\SyncEngine\Jobs\Support\OptionList;
use Estasoft\SyncEngine\Jobs\Support\StatusRank;
use Estasoft\SyncEngine\Jobs\Support\StoreTime;
use Estasoft\SyncEngine\Jobs\Urls;
use Estasoft\SyncEngine\Tests\Support\CoreArrayOptionStore;
use Estasoft\SyncEngine\Tests\Support\JobsFakeEnvironment;
use PHPUnit\Framework\TestCase;

final class PureClassesTest extends TestCase {

	/**
	 * Moves up the ladder and the ones that are refused.
	 *
	 * @return array<string,array{0:string,1:string,2:bool}>
	 */
	public static function moves(): array {
		return array(
			'processing to completed'    => array( 'processing', 'completed', true ),
			'on-hold to processing'      => array( 'on-hold', 'processing', true ),
			'prefix is ignored'          => array( 'wc-processing', 'wc-completed', true ),
			'completed to processing'    => array( 'completed', 'processing', false ),
			'same status'                => array( 'completed', 'completed', false ),
			'out of cancelled'           => array( 'cancelled', 'completed', false ),
			'into cancelled'             => array( 'processing', 'cancelled', false ),
			'a custom status'            => array( 'shipped', 'completed', false ),
			'case does not matter'       => array( 'Processing', 'COMPLETED', true ),
		);
	}

	/**
	 * @dataProvider moves
	 *
	 * @param string $from     From.
	 * @param string $to       To.
	 * @param bool   $expected Forward.
	 */
	public function test_orders_only_move_forward( string $from, string $to, bool $expected ): void {
		$this->assertSame( $expected, StatusRank::isForward( $from, $to ) );
	}

	public function test_store_time_questions(): void {
		$zone = new \DateTimeZone( 'Asia/Dubai' );
		$now  = new \DateTimeImmutable( '2026-11-02 21:30:00', new \DateTimeZone( 'UTC' ) );

		$this->assertSame( '2026-11-03', StoreTime::date( $now, $zone ), 'already tomorrow in Dubai' );
		$this->assertTrue( StoreTime::reached( $now, $zone, '01:30' ) );
		$this->assertFalse( StoreTime::reached( $now, $zone, '01:31' ) );
		$this->assertTrue( StoreTime::reached( $now, $zone, '00:00' ) );
		$this->assertFalse( StoreTime::reached( $now, $zone, '25:00' ) );
		$this->assertFalse( StoreTime::reached( $now, $zone, 'tea time' ) );
	}

	public function test_invoice_statuses_are_translated_and_final_ones_are_known(): void {
		$this->assertSame( 'reported', InvoiceStatus::from( 'Cleared', 'INV/1' ) );
		$this->assertSame( 'waiting', InvoiceStatus::from( 'to send', 'INV/1' ) );
		$this->assertSame( 'rejected', InvoiceStatus::from( 'REJECTED', 'INV/1' ) );
		$this->assertSame( 'not_required', InvoiceStatus::from( '', 'INV/1' ) );
		$this->assertSame( 'waiting', InvoiceStatus::from( 'something new', 'INV/1' ), 'an unknown word on a numbered invoice is waiting' );
		$this->assertSame( 'not_required', InvoiceStatus::from( 'something new', '' ), 'a draft has no invoice to report yet' );
		$this->assertTrue( InvoiceStatus::isFinal( 'reported', 'INV/1' ) );
		$this->assertTrue( InvoiceStatus::isFinal( 'not_required', 'INV/1' ) );
		$this->assertFalse( InvoiceStatus::isFinal( 'not_required', '' ), 'a draft may still become an invoice' );
		$this->assertFalse( InvoiceStatus::isFinal( 'rejected', 'INV/1' ) );
		$this->assertFalse( InvoiceStatus::isFinal( 'waiting', 'INV/1' ) );
	}

	public function test_a_capped_list_keeps_the_newest_and_numbers_its_entries(): void {
		$options = new CoreArrayOptionStore();
		$list    = new OptionList( $options, 'estasoft_sync_demo', 3 );

		$ids = array();
		for ( $i = 1; $i <= 5; ++$i ) {
			$ids[] = $list->prepend( array( 'n' => $i ) );
		}

		$this->assertSame( array( 1, 2, 3, 4, 5 ), $ids );
		$this->assertSame( array( 5, 4, 3 ), array_column( $list->all(), 'n' ) );
		$this->assertSame( array( 5, 4, 3 ), array_column( $list->all(), 'id' ) );
		$this->assertSame( 6, $list->prepend( array( 'n' => 6 ) ), 'the counter does not go back' );
		$options->values['estasoft_sync_demo'] = 'damaged';
		$this->assertSame( array(), $list->all() );
	}

	public function test_the_stock_store_caps_conflicts_and_remembers_decisions(): void {
		$options = new CoreArrayOptionStore();
		$store   = new StockStore( $options );

		$first = $store->openConflict( 1, 10, 'A', 'Lamp A', 1, 3, '2026-11-02T12:00:00Z' );
		$again = $store->openConflict( 1, 10, 'A', 'Lamp A', 2, 4, '2026-11-02T12:05:00Z' );
		$this->assertSame( $first, $again, 'one open conflict per product' );
		$this->assertSame( 2, $store->conflict( $first )['system_quantity'] );

		$closed = $store->decide( $first, 'hold', '2026-11-02T12:10:00Z' );
		$this->assertSame( 'A', $closed['sku'] );
		$this->assertNull( $store->conflict( $first ) );
		$this->assertSame( array( 'decision' => 'hold', 'quantity' => 2, 'at' => '2026-11-02T12:10:00Z' ), $store->decision( 1, 10 ) );
		$store->closeConflictFor( 1, 10 );
		$this->assertNull( $store->decision( 1, 10 ) );

		for ( $p = 1; $p <= StockStore::CONFLICT_CAP + 20; ++$p ) {
			$store->openConflict( 1, 1000 + $p, 'S' . $p, 'P', 0, 1, '2026-11-02T12:00:00Z' );
		}
		$this->assertCount( StockStore::CONFLICT_CAP, $store->conflicts() );
		$this->assertCount( 0, $store->conflicts( 2 ), 'filtered by connection' );
	}

	public function test_the_stock_store_keeps_a_pass_per_connection(): void {
		$store = new StockStore( new CoreArrayOptionStore() );
		$store->saveLast( 3, array( 'at' => 'x', 'matched' => 4 ) );
		$store->saveLast( 4, array( 'at' => 'y', 'matched' => 9 ) );
		$this->assertSame( 4, $store->last( 3 )['matched'] );
		$this->assertSame( array( 3, 4 ), array_keys( $store->last() ) );
		$this->assertSame( array(), $store->last( 99 ) );
	}

	public function test_reconciliation_settings_have_defaults_and_ranges(): void {
		$settings = new ReconSettings( new CoreArrayOptionStore() );

		$this->assertSame( '02:00', $settings->get()['schedule_time'] );
		$this->assertSame( 7, $settings->get()['window_days'] );
		$this->assertSame( 1, $settings->get()['tolerance_minor'] );
		$this->assertFalse( $settings->get()['auto_repair_missing'] );

		$saved = $settings->save( array( 'window_days' => '14', 'tolerance_minor' => 0, 'auto_repair_missing' => 'true', 'last_tick_date' => 'ignored' ) );
		$this->assertSame( 14, $saved['window_days'] );
		$this->assertTrue( $saved['auto_repair_missing'] );
		$this->assertSame( '', $saved['last_tick_date'], 'the date of the last run is not editable' );

		$settings->markTick( '2026-11-02' );
		$this->assertSame( '2026-11-02', $settings->get()['last_tick_date'] );
		$this->assertSame( 14, $settings->get()['window_days'] );

		$this->expectException( ValidationException::class );
		$settings->save( array( 'window_days' => 31 ) );
	}

	public function test_the_onboarding_view_works_out_the_planned_end_and_hides_reserved_answers(): void {
		$view = OnboardingView::present(
			array(
				'step'              => 9,
				'completed'         => 1,
				'connector'         => 'odoo',
				'answers'           => array( 'sync_stock' => true, 'went_live_at' => 'x', 'shadow_days' => 3, 'live_mode' => 'shadow' ),
				'shadow_started_at' => '2026-11-02T07:00:00Z',
				'shadow_ends_at'    => null,
			)
		);

		$this->assertSame( 5, $view['step'] );
		$this->assertTrue( $view['completed'] );
		$this->assertSame( array( 'sync_stock' => true ), $view['answers'] );
		$this->assertSame( '2026-11-02T07:00:00Z', $view['shadow_started_at'] );
		$this->assertSame( '2026-11-05T07:00:00Z', $view['shadow_ends_at'] );

		$none = OnboardingView::present( array() );
		$this->assertNull( $none['shadow_started_at'] );
		$this->assertNull( $none['shadow_ends_at'] );
		$this->assertNull( $none['connector'] );
	}

	public function test_links_into_the_app_and_wordpress(): void {
		$urls = new Urls( new JobsFakeEnvironment() );

		$this->assertSame( '/settings/performance', $urls->route( 'settings/performance' ) );
		$this->assertSame( 'https://shop.example.test/wp-admin/admin.php?page=estasoft-sync#/queue?state=failed', $urls->app( '/queue?state=failed' ) );
		$this->assertSame( 'https://shop.example.test/wp-admin/admin.php?page=estasoft-sync', $urls->app() );
		$this->assertSame( 'https://shop.example.test/wp-admin/edit.php?post_type=product', $urls->products() );
	}
}
