<?php
/**
 * UI-1, UI-4, UI-11.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ItemMode;

final class DashboardServiceTest extends JobsTestCase {

	public function test_without_a_connection_the_mode_is_not_connected_and_the_counts_are_zero(): void {
		$status = $this->j->dashboard->status();

		$this->assertSame( 'not_connected', $status['mode'] );
		$this->assertNull( $status['connection'] );
		$this->assertSame( 0, $status['waiting'] );
		$this->assertCount( 24, $status['hourly'] );
		$this->assertSame( 'Asia/Dubai', $status['store_timezone'] );
		$this->assertSame( 'AED', $status['currency'] );
		$this->assertFalse( $status['encryption_key_missing'] );
	}

	public function test_counts_come_from_the_live_queue_and_the_oldest_waiting_order_is_named(): void {
		$id = $this->j->connect();
		$a  = $this->h->item( $id, '10484', 'created', null, ItemMode::Live, array( 'label' => '10484' ) );
		$this->h->clock->advance( 60 );
		$this->h->item( $id, '10485', 'created', null, ItemMode::Live, array( 'label' => '10485' ) );
		$failed = $this->h->item( $id, '10486' );
		$this->h->queue->markFailed( $failed, 'SYNC-MAP-001', 'x' );
		$synced = $this->h->item( $id, '10487' );
		$this->h->queue->markSynced( $synced, '1', 'S1' );
		$this->h->item( $id, '10488', 'created', null, ItemMode::Shadow );

		$status = $this->j->dashboard->status();

		$this->assertSame( 2, $status['waiting'] );
		$this->assertSame( 1, $status['needs_attention'] );
		$this->assertSame( 1, $status['synced_today'] );
		$this->assertSame( 2, $status['by_state']['queued'] );
		$this->assertSame( 1, $status['by_state']['failed'] );
		$this->assertSame( '10484', $status['oldest_waiting_order'] );
		$this->assertNotNull( $status['oldest_waiting_at'] );
		$this->assertSame( array_keys( $this->h->queue->countsByState() ), array_keys( $status['by_state'] ) );
		$this->assertSame( $a > 0, true );
	}

	public function test_the_connection_card_has_no_secret_and_reads_capabilities_from_the_connector(): void {
		$id = $this->j->connect();

		$card = $this->j->dashboard->status()['connection'];

		$this->assertSame( $id, $card['id'] );
		$this->assertSame( 'active', $card['status'] );
		$this->assertTrue( $card['capabilities']['stock_in'] );
		$this->assertFalse( $card['capabilities']['webhooks'] );
		$this->assertSame( 'https://erp.example.test', $card['address'] );
		$this->assertSame( 'Fakesystem 18.0', $card['version'] );
		$this->assertStringNotContainsString( 'sk-test', (string) json_encode( $card ) );
		$this->assertSame(
			array( 'id', 'connector', 'label', 'type', 'status', 'version', 'address', 'paused_until', 'paused_reason', 'last_success_at', 'last_failure_at', 'failures_in_a_row', 'typical_response_ms', 'credential_expires_at', 'capabilities' ),
			array_keys( $card )
		);
	}

	public function test_the_mode_follows_the_state_of_the_connection_and_the_setup(): void {
		$id = $this->j->connect();
		$this->assertSame( 'first_run', $this->j->dashboard->status()['mode'] );

		$this->h->onboarding->save( array( 'completed' => true ) );
		$this->assertSame( 'normal', $this->j->dashboard->status()['mode'] );

		$this->j->shadowService->start( $id, 7 );
		$this->assertSame( 'shadow', $this->j->dashboard->status()['mode'] );

		$this->h->connections->setStatus( $id, ConnectionStatus::PausedCircuit, '2026-11-02 12:02:00', 'circuit' );
		$status = $this->j->dashboard->status();
		$this->assertSame( 'paused', $status['mode'], 'a connection that does not answer outranks shadow mode' );
		$this->assertSame( 'circuit', $status['connection']['paused_reason'] );
	}

	public function test_the_failed_count_is_cached_for_a_minute_and_can_be_dropped(): void {
		$id = $this->j->connect();
		$this->assertSame( 0, $this->j->dashboard->failedCount() );

		$one = $this->h->item( $id, '1' );
		$this->h->queue->markFailed( $one, 'SYNC-MAP-001', 'x' );
		$this->assertSame( 0, $this->j->dashboard->failedCount(), 'still cached' );

		$this->h->clock->advance( 61 );
		$this->assertSame( 1, $this->j->dashboard->failedCount() );

		$two = $this->h->item( $id, '2' );
		$this->h->queue->markFailed( $two, 'SYNC-MAP-001', 'x' );
		$this->j->dashboard->bustFailedCount();
		$this->assertSame( 2, $this->j->dashboard->failedCount() );
	}

	public function test_the_widget_has_small_counts(): void {
		$id = $this->j->connect();
		$this->h->item( $id, '1' );
		$failed = $this->h->item( $id, '2' );
		$this->h->queue->markFailed( $failed, 'SYNC-MAP-001', 'x' );

		$widget = $this->j->dashboard->widget();

		$this->assertTrue( $widget['connected'] );
		$this->assertSame( 1, $widget['waiting'] );
		$this->assertSame( 1, $widget['failed'] );
		$this->assertSame( 0, $widget['synced_today'] );
	}

	public function test_a_missing_key_is_reported_and_cron_mode_is_passed_through(): void {
		$this->j->env->visits = true;
		$dashboard            = new \Estasoft\SyncEngine\Jobs\Dashboard\DashboardService(
			$this->h->queue,
			$this->h->connections,
			fn() => $this->h->registry,
			$this->h->logs,
			$this->h->onboarding,
			new \Estasoft\SyncEngine\Core\Crypto\SecretBox( null ),
			$this->j->env,
			$this->j->shadowService,
			$this->j->transients,
			$this->h->clock
		);
		$status = $dashboard->status();
		$this->assertTrue( $status['encryption_key_missing'] );
		$this->assertTrue( $status['cron_on_visits'] );
	}
}
