<?php
/**
 * Pause and resume.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Queue;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class PauseControllerTest extends CoreIntegrationTestCase {

	public function test_pausing_stops_claiming_but_keeps_accepting_items(): void {
		$conn = $this->h->connection();
		$this->assertTrue( $this->h->pause->pause( $conn ) );
		$public = $this->h->connections->findPublic( $conn );
		$this->assertSame( 'paused', $public['status'] );
		$this->assertSame( 'person', $public['paused_reason'] );
		$this->assertNull( $public['paused_until'] );

		$id = $this->h->enqueuer->enqueue( \Estasoft\SyncEngine\Domain\EntityType::Order, '10490', 'created' );
		$this->assertNotNull( $id, 'items are still accepted' );
		$this->assertSame( array(), $this->h->queue->claim( 10, 60, 'w' ) );
		$this->assertSame( 'SYNC-PAUSE-001', $this->h->logs->latest( 1 )[0]['code'] );
	}

	public function test_a_timed_pause_ends_by_itself(): void {
		$conn = $this->h->connection();
		$id   = $this->h->item( $conn, '1' );
		$this->h->pause->pause( $conn, 30 );
		$this->assertSame( '2026-11-02T12:30:00Z', $this->h->connections->findPublic( $conn )['paused_until'] );

		$this->h->clock->advance( 29 * 60 );
		$this->assertSame( 0, $this->h->pause->resumeDue() );
		$this->assertSame( array(), $this->h->queue->claim( 10, 60, 'w' ) );

		$this->h->clock->advance( 2 * 60 );
		$resumed = $this->listen( 'estasoft_sync_connection_resumed', 2 );
		$this->assertSame( 1, $this->h->pause->resumeDue() );
		$this->assertSame( 'active', $this->h->connections->findPublic( $conn )['status'] );
		$this->assertSame( array( array( $conn, 'timer' ) ), $resumed->getArrayCopy() );
		$this->assertSame( array( $id ), array_map( static fn( $i ) => $i->id, $this->h->queue->claim( 10, 60, 'w' ) ) );
	}

	public function test_resume_clears_every_kind_of_stop(): void {
		$conn = $this->h->connection();
		$resumed = $this->listen( 'estasoft_sync_connection_resumed', 2 );
		foreach ( array( ConnectionStatus::Paused, ConnectionStatus::PausedCircuit, ConnectionStatus::NeedsReconnect ) as $status ) {
			$this->h->connections->setStatus( $conn, $status, null, 'x' );
			$this->assertTrue( $this->h->pause->resume( $conn ), $status->value );
			$this->assertSame( 'active', $this->h->connections->findPublic( $conn )['status'] );
		}
		$this->assertCount( 3, $resumed );
		$this->assertSame( 'person', $resumed[0][1] );
	}

	public function test_resuming_an_active_or_disconnected_connection_is_a_no_op(): void {
		$conn = $this->h->connection();
		$this->assertFalse( $this->h->pause->resume( $conn ) );
		$this->h->connections->delete( $conn );
		$this->assertFalse( $this->h->pause->resume( $conn ) );
		$this->assertFalse( $this->h->pause->pause( $conn ), 'a disconnected connection cannot be paused' );
		$this->assertFalse( $this->h->pause->pause( $conn + 100 ) );
	}

	public function test_pause_and_resume_everything(): void {
		$a    = $this->h->connection();
		$b    = $this->h->connection();
		$gone = $this->h->connection();
		$this->h->connections->delete( $gone );
		$this->assertSame( 2, $this->h->pause->pauseAll() );
		$this->assertSame( 'paused', $this->h->connections->findPublic( $a )['status'] );
		$this->assertSame( 'paused', $this->h->connections->findPublic( $b )['status'] );
		$this->assertSame( 'disconnected', $this->h->connections->findPublic( $gone )['status'] );
		$this->assertSame( 2, $this->h->pause->resumeAll() );
		$this->assertSame( 'active', $this->h->connections->findPublic( $a )['status'] );
	}

	public function test_only_a_manual_pause_ends_on_the_timer_not_a_circuit_pause(): void {
		$timed   = $this->h->connection();
		$circuit = $this->h->connection();
		$this->h->pause->pause( $timed, 1 );
		$this->h->connections->tripCircuit( $circuit, '2026-11-02 12:00:30' );
		$this->h->clock->advance( 120 );
		$this->assertSame( 1, $this->h->pause->resumeDue() );
		$this->assertSame( 'active', $this->h->connections->findPublic( $timed )['status'] );
		$this->assertSame( 'paused_circuit', $this->h->connections->findPublic( $circuit )['status'] );
	}

	public function test_holding_for_a_person_applies_only_to_an_active_connection(): void {
		$conn   = $this->h->connection();
		$paused = $this->listen( 'estasoft_sync_connection_paused', 2 );
		$this->assertTrue( $this->h->pause->pauseForRefusedSignIn( $conn, 'Odoo' ) );
		$this->assertFalse( $this->h->pause->pauseForRefusedSignIn( $conn, 'Odoo' ), 'already stopped' );
		$this->assertFalse( $this->h->pause->markNeedsReconnect( $conn, ErrorCode::Auth002, 'Odoo' ) );
		$this->assertCount( 1, $paused );
		$this->assertSame( 'Odoo refused the sign-in. Check the user and the API key.', $this->h->logs->latest( 1 )[0]['message'] );
	}

	public function test_expired_sign_ins_set_needs_reconnect(): void {
		$conn = $this->h->connection();
		$this->assertTrue( $this->h->pause->markNeedsReconnect( $conn, ErrorCode::Auth005, 'Xero' ) );
		$this->assertSame( 'needs_reconnect', $this->h->connections->findPublic( $conn )['status'] );
		$this->assertSame( 'SYNC-AUTH-005', $this->h->logs->latest( 1 )[0]['code'] );
		$this->assertStringContainsString( 'Xero', $this->h->logs->latest( 1 )[0]['message'] );
	}
}
