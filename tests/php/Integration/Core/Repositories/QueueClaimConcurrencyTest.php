<?php
/**
 * Several processes claim from the same queue at once.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Repositories;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;
use Estasoft\SyncEngine\Tests\Support\CoreProcessBarrier;

final class QueueClaimConcurrencyTest extends CoreIntegrationTestCase {

	/**
	 * Start a worker process.
	 *
	 * @param string $worker Worker id.
	 * @param string $start  The barrier directory the process waits in.
	 * @param int    $conn   Connection id.
	 * @return array{0:resource,1:array<int,resource>}
	 */
	private function start( string $worker, string $start, int $conn ): array {
		$script = dirname( __DIR__, 3 ) . '/Support/CoreClaimWorkerProcess.php';
		$pipes  = array();
		$proc   = proc_open( array( PHP_BINARY, $script, $worker, '7', $start, (string) $conn ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $proc );
		return array( $proc, $pipes );
	}

	public function test_two_workers_never_claim_the_same_row(): void {
		$conn  = $this->h->connection();
		$total = 240;
		$this->h->sql( 'SET autocommit = 1' );
		for ( $i = 1; $i <= $total; ++$i ) {
			$this->h->item( $conn, (string) ( 50000 + $i ) );
		}

		// Every process loads WordPress first and waits; they are let go together once all three are ready.
		$barrier = new CoreProcessBarrier();
		$workers = array();
		foreach ( array( 'worker-a', 'worker-b', 'worker-c' ) as $name ) {
			$workers[ $name ] = $this->start( $name, $barrier->dir(), $conn );
		}
		$this->assertTrue( $barrier->release( 3 ), 'all three processes got ready' );

		$claimed = array();
		foreach ( $workers as $name => $parts ) {
			list( $proc, $pipes ) = $parts;
			$out = (string) stream_get_contents( $pipes[1] );
			$err = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $proc );
			$ids = json_decode( $out, true );
			$this->assertIsArray( $ids, "$name printed: $out $err" );
			$claimed[ $name ] = array_map( 'intval', $ids );
		}

		$all = array_merge( ...array_values( $claimed ) );
		$this->assertSame( count( $all ), count( array_unique( $all ) ), 'no row was claimed by two workers' );
		$this->assertCount( $total, $all, 'every row was claimed by someone' );
		$this->assertGreaterThan( 1, count( array_filter( $claimed ) ), 'more than one worker took part' );

		// The database agrees with each worker about what it holds.
		foreach ( $claimed as $name => $ids ) {
			$held = (int) $this->h->wpdb->get_var( $this->h->wpdb->prepare( "SELECT COUNT(*) FROM `{$this->h->tables->queue()}` WHERE locked_by = %s AND state = 'syncing'", $name ) ); // phpcs:ignore
			$this->assertSame( count( $ids ), $held, $name );
		}
	}

	public function test_workers_racing_over_one_order_run_its_steps_in_order(): void {
		$conn = $this->h->connection();
		$ids  = array();
		foreach ( array( 'created', 'paid', 'updated', 'refunded' ) as $event ) {
			$ids[] = $this->h->item( $conn, '777', $event );
		}
		$barrier = new CoreProcessBarrier();
		$workers = array( $this->start( 'a', $barrier->dir(), $conn ), $this->start( 'b', $barrier->dir(), $conn ) );
		$this->assertTrue( $barrier->release( 2 ) );
		$claimed = array();
		foreach ( $workers as $parts ) {
			list( $proc, $pipes ) = $parts;
			$claimed = array_merge( $claimed, (array) json_decode( (string) stream_get_contents( $pipes[1] ), true ) );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $proc );
		}
		$this->assertSame( array( $ids[0] ), array_map( 'intval', $claimed ), 'only the first step is claimable while it is unfinished, by whichever worker is first' );
	}

	/**
	 * Run several cap-checking processes at once and return what each claimed.
	 *
	 * @param int  $processes How many processes.
	 * @param int  $global    The global limit.
	 * @param bool $locked    Whether they use the claim lock.
	 * @return array<string,array<int,int>>
	 */
	private function runCapProcesses( int $processes, int $global, bool $locked ): array {
		$script  = dirname( __DIR__, 3 ) . '/Support/CoreCapWorkerProcess.php';
		$barrier = new CoreProcessBarrier();
		$procs   = array();
		for ( $i = 1; $i <= $processes; ++$i ) {
			$pipes = array();
			$proc  = proc_open( array( PHP_BINARY, $script, "cap-$i", (string) $global, $barrier->dir(), $locked ? '1' : '0', '6' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			$this->assertIsResource( $proc );
			$procs[ "cap-$i" ] = array( $proc, $pipes );
		}
		$this->assertTrue( $barrier->release( $processes ), 'every process got ready' );
		$claimed = array();
		foreach ( $procs as $name => $parts ) {
			list( $proc, $pipes ) = $parts;
			$out = (string) stream_get_contents( $pipes[1] );
			$err = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $proc );
			$ids = json_decode( $out, true );
			$this->assertIsArray( $ids, "$name printed: $out $err" );
			$claimed[ $name ] = array_map( 'intval', $ids );
		}
		return $claimed;
	}

	public function test_the_global_limit_holds_when_four_processes_claim_at_once(): void {
		$conn = $this->h->connection( array( 'concurrency' => 10 ) );
		$this->h->sql( 'SET autocommit = 1' );
		for ( $i = 1; $i <= 40; ++$i ) {
			$this->h->item( $conn, (string) ( 60000 + $i ) );
		}

		$claimed = $this->runCapProcesses( 4, 3, true );

		$all = array_merge( ...array_values( $claimed ) );
		$this->assertCount( 3, $all, 'together they never run more than the global limit of 3' );
		$this->assertSame( 3, count( array_unique( $all ) ) );
		$syncing = (int) $this->h->wpdb->get_var( "SELECT COUNT(*) FROM `{$this->h->tables->queue()}` WHERE state = 'syncing'" ); // phpcs:ignore
		$this->assertSame( 3, $syncing, 'and the database agrees' );
	}

	public function test_the_per_connection_limit_holds_across_processes_too(): void {
		$a = $this->h->connection( array( 'concurrency' => 2 ) );
		$b = $this->h->connection( array( 'concurrency' => 1 ) );
		$this->h->sql( 'SET autocommit = 1' );
		for ( $i = 1; $i <= 20; ++$i ) {
			$this->h->item( $a, (string) ( 61000 + $i ) );
			$this->h->item( $b, (string) ( 62000 + $i ) );
		}

		$this->runCapProcesses( 3, 10, true );

		foreach ( array( $a => 2, $b => 1 ) as $conn => $limit ) {
			$syncing = (int) $this->h->wpdb->get_var( $this->h->wpdb->prepare( "SELECT COUNT(*) FROM `{$this->h->tables->queue()}` WHERE state = 'syncing' AND connection_id = %d", $conn ) ); // phpcs:ignore
			$this->assertSame( $limit, $syncing, "connection $conn" );
		}
	}

	public function test_without_the_lock_the_same_processes_would_overshoot(): void {
		// A control for the test above: it shows the processes are slow and close enough to collide. Without the claim lock
		// they see the same free slots, so the total goes above the limit.
		$conn = $this->h->connection( array( 'concurrency' => 10 ) );
		$this->h->sql( 'SET autocommit = 1' );
		for ( $i = 1; $i <= 40; ++$i ) {
			$this->h->item( $conn, (string) ( 63000 + $i ) );
		}
		$claimed = $this->runCapProcesses( 4, 3, false );
		$this->assertGreaterThan( 3, count( array_merge( ...array_values( $claimed ) ) ), 'the race exists, and the lock is what closes it' );
	}

	public function test_two_reapers_never_report_or_change_the_same_item(): void {
		$conn  = $this->h->connection();
		$total = 120;
		$this->h->sql( 'SET autocommit = 1' );
		$ids = array();
		for ( $i = 1; $i <= $total; ++$i ) {
			$ids[] = $this->h->item( $conn, (string) ( 64000 + $i ) );
		}
		$this->h->sql( "UPDATE `{$this->h->tables->queue()}` SET state = 'syncing', locked_by = 'dead', locked_until = '2020-01-01 00:00:00' WHERE connection_id = {$conn}" ); // phpcs:ignore

		$script  = dirname( __DIR__, 3 ) . '/Support/CoreReapProcess.php';
		$barrier = new CoreProcessBarrier();
		$procs   = array();
		for ( $i = 0; $i < 3; ++$i ) {
			$pipes = array();
			$proc  = proc_open( array( PHP_BINARY, $script, $barrier->dir() ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			$this->assertIsResource( $proc );
			$procs[] = array( $proc, $pipes );
		}
		$this->assertTrue( $barrier->release( 3 ) );
		$reported = array();
		foreach ( $procs as $parts ) {
			list( $proc, $pipes ) = $parts;
			$out = (string) stream_get_contents( $pipes[1] );
			$err = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $proc );
			$list = json_decode( $out, true );
			$this->assertIsArray( $list, "printed: $out $err" );
			$reported = array_merge( $reported, array_map( 'intval', $list ) );
		}

		$this->assertSame( count( $reported ), count( array_unique( $reported ) ), 'no item was reported by two reapers' );
		sort( $reported );
		$this->assertSame( $ids, $reported, 'and every item was reported by one of them' );
		$this->assertSame( $total, (int) $this->h->wpdb->get_var( "SELECT COUNT(*) FROM `{$this->h->tables->queue()}` WHERE state = 'queued' AND reaps = 1" ) ); // phpcs:ignore
		$this->assertSame( 0, (int) $this->h->wpdb->get_var( "SELECT COUNT(*) FROM `{$this->h->tables->queue()}` WHERE reaps > 1" ), 'none was taken back twice' ); // phpcs:ignore
	}
}
