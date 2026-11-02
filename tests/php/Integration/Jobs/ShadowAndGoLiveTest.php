<?php
/**
 * SHD-1 to SHD-3, ONB-9, AS-22.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Core\Repositories\QueueQuery;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Jobs\Shadow\GoLiveService;
use Estasoft\SyncEngine\Tests\Support\CoreFakeClock;

final class ShadowAndGoLiveTest extends JobsTestCase {

	private int $connection = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->connection = $this->j->connect();
	}

	/**
	 * Put an order in the queue the way the order hooks do.
	 *
	 * @param int $orderId Order id.
	 */
	private function placeOrder( int $orderId ): void {
		$this->j->orders->add( $orderId, array( 'sync_state' => '' ) );
		$this->h->enqueuer->enqueue( EntityType::Order, (string) $orderId, 'created', $this->connection, array( 'label' => (string) $orderId ) );
	}

	public function test_shadow_sends_nothing_reports_matches_differences_and_not_found_and_go_live_sends_only_new_orders(): void {
		$this->j->shadowService->start( $this->connection, 7 );
		foreach ( array( 701, 702, 703 ) as $id ) {
			$this->placeOrder( $id );
		}
		$this->j->connector->documents['WC-701'] = new ExternalRef( 'SO-701', 'S701', null, array( 'total_minor' => 48615, 'currency' => 'AED' ) );
		$this->j->connector->documents['WC-702'] = new ExternalRef( 'SO-702', 'S702', null, array( 'total_minor' => 99999, 'currency' => 'AED' ) );

		$this->h->worker->run();

		$this->assertSame( 0, $this->j->connector->count( 'send' ), 'nothing was sent' );
		$counts = $this->h->queue->countsByState( null, 'shadow' );
		$this->assertSame( 3, $counts['synced'] );

		$report = $this->j->shadowService->report();
		$this->assertSame( 3, $report['info']['items'] );
		$this->assertSame( 1, $report['info']['matches'] );
		$this->assertSame( 1, $report['info']['differs'] );
		$this->assertSame( 1, $report['info']['not_found'] );
		$this->assertSame( 7, $report['info']['of_days'] );
		$this->assertSame( 1, $report['info']['day'] );
		$byOrder = array_column( $report['rows'], null, 'order_id' );
		$this->assertSame( 'differs', $byOrder[702]['result'] );
		$this->assertSame( array( 'total' ), $byOrder[702]['fields'] );
		$this->assertSame( 'not_found', $byOrder[703]['result'] );
		$this->assertSame( 'differs', $report['rows'][0]['result'], 'differences come first' );

		$lookups = $this->j->connector->count( 'findExisting' );
		$this->j->shadowService->report();
		$this->assertSame( $lookups, $this->j->connector->count( 'findExisting' ), 'verdicts are kept, the system is not asked again' );

		$onboarding = $this->j->goLive->goLive( 'live', 1 );
		$this->assertTrue( $onboarding['completed'] );
		$this->placeOrder( 704 );
		$live = $this->h->queue->query( new QueueQuery( 'queued', 'order', null, null, null, null, 'live' ) )->items;
		$this->assertCount( 1, $live, 'only the new order is live' );
		$this->assertSame( '704', $live[0]['entity_id'] );
		$this->h->worker->run();
		$this->assertSame( 1, $this->j->connector->count( 'send' ), 'only new orders are sent after go-live' );
	}

	public function test_go_live_skips_waiting_items_with_the_reason_and_back_fills_nothing(): void {
		$this->placeOrder( 801 );
		$this->placeOrder( 802 );

		$onboarding = $this->j->goLive->goLive( 'live', 1 );

		$this->assertTrue( $onboarding['completed'] );
		$this->assertSame( 5, $onboarding['step'] );
		$skipped = $this->h->queue->query( new QueueQuery( 'skipped' ) )->items;
		$this->assertCount( 2, $skipped );
		$this->assertSame( 'Placed before go-live', $skipped[0]['skip_reason'] );
		$this->assertSame( 0, $this->h->queue->countsByState( null, 'live' )['queued'] );
		$this->h->worker->run();
		$this->assertSame( 0, $this->j->connector->count( 'send' ) );
	}

	public function test_go_live_in_shadow_mode_opens_a_window_that_does_not_close_by_itself(): void {
		$this->placeOrder( 801 );

		$onboarding = $this->j->goLive->goLive( 'shadow', 1 );

		$this->assertTrue( $onboarding['completed'] );
		$this->assertNotNull( $onboarding['shadow_started_at'] );
		$this->assertSame( '2026-11-09T12:00:00Z', $onboarding['shadow_ends_at'] );
		$this->assertTrue( $this->h->onboarding->isShadowActive( $this->h->clock->now() ) );
		$this->h->clock->advance( 30 * 86400 );
		$this->assertTrue( $this->h->onboarding->isShadowActive( $this->h->clock->now() ), 'at day 7 the owner is asked; nothing switches by itself' );

		$ended = $this->j->shadowService->end( 1 );
		$this->assertFalse( $this->h->onboarding->isShadowActive( $this->h->clock->now() ) );
		$this->assertNotNull( $ended['shadow_started_at'] );
		$this->assertArrayNotHasKey( 'shadow_ended_by', $ended['answers'] );
	}

	public function test_going_live_twice_changes_nothing_and_leaves_new_orders_alone(): void {
		$this->placeOrder( 801 );
		$first = $this->j->goLive->goLive( 'live', 1 );
		$this->h->clock->advance( 3600 );
		$this->placeOrder( 802 );
		$before = $this->h->onboarding->get();

		$second = $this->j->goLive->goLive( 'shadow', 1 );

		$this->assertSame( $first, $second, 'the second call returns the state as it is, whatever mode it names' );
		$this->assertSame( $before, $this->h->onboarding->get() );
		$this->assertFalse( $this->h->onboarding->isShadowActive( $this->h->clock->now() ), 'it did not open a shadow window' );
		$live = $this->h->queue->query( new QueueQuery( 'queued', 'order', null, null, null, null, 'live' ) )->items;
		$this->assertCount( 1, $live, 'the order placed after go-live is still waiting' );
		$this->assertSame( '802', $live[0]['entity_id'] );
		$this->assertCount( 1, $this->h->queue->query( new QueueQuery( 'skipped' ) )->items );
	}

	public function test_go_live_skips_only_items_created_before_it_began(): void {
		$this->placeOrder( 801 );
		$this->h->clock->advance( 60 );
		$this->placeOrder( 802 );
		// A service whose clock stands one minute earlier: order 802 is created after "now" for it, like an order placed during the loop.
		$earlier = new GoLiveService( $this->h->onboarding, $this->h->queue, $this->h->connections, $this->j->shadowService, $this->h->logger, new CoreFakeClock( '2026-11-02 12:00:00' ) );

		$earlier->goLive( 'live', 1 );

		$skipped = $this->h->queue->query( new QueueQuery( 'skipped' ) )->items;
		$this->assertSame( array( '801' ), array_column( $skipped, 'entity_id' ) );
		$queued = $this->h->queue->query( new QueueQuery( 'queued' ) )->items;
		$this->assertSame( array( '802' ), array_column( $queued, 'entity_id' ), 'the later order is real and stays waiting' );
	}

	public function test_go_live_skips_more_than_one_page_of_waiting_items(): void {
		foreach ( range( 1, 105 ) as $i ) {
			$this->placeOrder( 9000 + $i );
		}

		$this->j->goLive->goLive( 'live', 1 );

		$counts = $this->h->queue->countsByState( null, 'live' );
		$this->assertSame( 0, $counts['queued'] );
		$this->assertSame( 105, $counts['skipped'] );
	}

	public function test_ending_the_shadow_window_records_who_ended_it_and_why_through_core(): void {
		$this->j->shadowService->start( $this->connection, 7 );
		$this->h->clock->advance( 7200 );

		$this->j->shadowService->end( 3 );

		$state = $this->h->onboarding->get();
		$this->assertSame( '2026-11-02T14:00:00Z', $state['shadow_ended_at'] );
		$this->assertSame( 'person', $state['shadow_end_reason'] );
		$this->assertSame( 'live', $state['mode'] );
		$this->assertArrayNotHasKey( 'shadow_ended_by', $state['answers'], 'the old marker is no longer written' );

		// Going live in live mode closes an open window with its own reason.
		$this->j->shadowService->start( $this->connection, 7 );
		$this->j->goLive->goLive( 'live', 3 );
		$this->assertSame( 'go_live', $this->h->onboarding->get()['shadow_end_reason'] );
	}

	public function test_a_window_ended_by_the_old_marker_is_still_read_as_closed(): void {
		$this->j->shadowService->start( $this->connection, 7 );
		$this->h->onboarding->save( array( 'answers' => array( 'shadow_ended_by' => 4 ) ) );

		$this->assertFalse( $this->h->onboarding->isShadowActive( $this->h->clock->now() ) );
		$ended = $this->j->shadowService->end( 5 );
		$this->assertNull( $this->h->onboarding->get()['shadow_end_reason'], 'ending an ended window changes nothing' );
		$this->assertNotNull( $ended['shadow_started_at'] );
	}

	/**
	 * Place orders in shadow mode and let the worker record them.
	 *
	 * @param array<int,int> $ids Order ids.
	 */
	private function shadowOrders( array $ids ): void {
		$this->j->shadowService->start( $this->connection, 7 );
		foreach ( $ids as $id ) {
			$this->placeOrder( $id );
		}
		$this->h->worker->run();
	}

	public function test_compare_stops_at_its_time_budget_and_keeps_each_verdict_as_it_goes(): void {
		$this->shadowOrders( array( 711, 712, 713, 714, 715, 716 ) );
		$kept = array();
		$this->j->connector->afterCall = function ( string $method ) use ( &$kept ): void {
			if ( 'findExisting' === $method ) {
				// The system is slow, and each answer must already be saved when the next one is asked for.
				$kept[] = count( (array) $this->h->options->get( \Estasoft\SyncEngine\Jobs\Shadow\ShadowService::VERDICTS, array() ) );
				$this->h->clock->advance( 6 );
			}
		};

		$result = $this->j->shadowService->compare( null, 100 );

		$this->assertTrue( $result['timed_out'] );
		$this->assertSame( 3, $result['compared'], 'asked at 0, 6 and 12 seconds; at 18 the 15 second budget was used' );
		$this->assertSame( array( 0, 1, 2 ), $kept, 'each verdict was saved before the next question' );
		$this->assertCount( 3, $this->h->options->get( \Estasoft\SyncEngine\Jobs\Shadow\ShadowService::VERDICTS, array() ) );

		// The next call carries on with the rest.
		$this->j->connector->afterCall = null;
		$next = $this->j->shadowService->compare( null, 100 );
		$this->assertFalse( $next['timed_out'] );
		$this->assertSame( 3, $next['compared'] );
		$this->assertCount( 6, $this->h->options->get( \Estasoft\SyncEngine\Jobs\Shadow\ShadowService::VERDICTS, array() ) );
	}

	public function test_compare_with_no_budget_left_asks_the_system_nothing(): void {
		$this->shadowOrders( array( 711, 712 ) );
		$base = $this->j->connector->count( 'findExisting' );

		$result = $this->j->shadowService->compare( null, 100, 0.0 );

		$this->assertSame( 0, $result['compared'] );
		$this->assertTrue( $result['timed_out'] );
		$this->assertSame( $base, $this->j->connector->count( 'findExisting' ) );
	}

	public function test_verdicts_are_refreshed_while_the_window_is_open_and_final_once_it_is_closed(): void {
		$this->shadowOrders( array( 711, 712 ) );
		$base = $this->j->connector->count( 'findExisting' );
		$this->j->shadowService->compare( null, 100 );
		$this->assertSame( $base + 2, $this->j->connector->count( 'findExisting' ) );

		$this->h->clock->advance( 7 * 3600 );
		$this->j->shadowService->compare( null, 100 );
		$this->assertSame( $base + 4, $this->j->connector->count( 'findExisting' ), 'open window: a verdict older than six hours is checked again' );

		$this->j->shadowService->end( 1 );
		$this->h->clock->advance( 24 * 3600 );
		$this->j->shadowService->compare( null, 100 );
		$this->j->shadowService->report();
		$this->assertSame( $base + 4, $this->j->connector->count( 'findExisting' ), 'closed window: the verdicts are final, the system is not asked again' );
		$this->assertSame( 2, $this->j->shadowService->report()['info']['not_found'] );
	}

	public function test_orders_that_never_got_a_verdict_are_still_compared_after_the_window_closed(): void {
		$this->shadowOrders( array( 711 ) );
		$this->j->shadowService->end( 1 );
		$base = $this->j->connector->count( 'findExisting' );

		$this->j->shadowService->compare( null, 100 );

		$this->assertSame( $base + 1, $this->j->connector->count( 'findExisting' ) );
	}

	public function test_a_wrong_mode_and_a_missing_connection_are_refused(): void {
		try {
			$this->j->goLive->goLive( 'maybe', 1 );
			$this->fail( 'expected a validation error' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'mode', $e->errors() );
		}
		$this->h->connections->delete( $this->connection );
		$this->expectException( JobException::class );
		$this->j->goLive->goLive( 'live', 1 );
	}

	public function test_start_checks_the_connection_and_the_length(): void {
		try {
			$this->j->shadowService->start( $this->connection, 0 );
			$this->fail( 'expected a validation error' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'days', $e->errors() );
		}
		$this->expectException( JobException::class );
		$this->j->shadowService->start( 99999, 7 );
	}

	public function test_the_dashboard_shows_the_shadow_summary_without_asking_the_system(): void {
		$this->j->shadowService->start( $this->connection, 7 );
		$this->placeOrder( 701 );
		$this->h->worker->run();
		$before = $this->j->connector->count( 'findExisting' );

		$status = $this->j->dashboard->status();

		$this->assertSame( 'shadow', $status['mode'] );
		$this->assertSame( 1, $status['shadow']['items'] );
		$this->assertSame( $before, $this->j->connector->count( 'findExisting' ) );
	}
}
