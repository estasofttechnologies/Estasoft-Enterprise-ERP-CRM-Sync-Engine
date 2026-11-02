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
