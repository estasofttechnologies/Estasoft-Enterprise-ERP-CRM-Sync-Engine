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

final class QueueClaimConcurrencyTest extends CoreIntegrationTestCase {

	/**
	 * Start a worker process.
	 *
	 * @param string $worker Worker id.
	 * @param float  $start  Moment to start claiming.
	 * @param int    $conn   Connection id.
	 * @return array{0:resource,1:array<int,resource>}
	 */
	private function start( string $worker, float $start, int $conn ): array {
		$script = dirname( __DIR__, 3 ) . '/Support/CoreClaimWorkerProcess.php';
		$pipes  = array();
		$proc   = proc_open( array( PHP_BINARY, $script, $worker, '7', (string) $start, (string) $conn ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
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

		$start   = microtime( true ) + 2.0;
		$workers = array();
		foreach ( array( 'worker-a', 'worker-b', 'worker-c' ) as $name ) {
			$workers[ $name ] = $this->start( $name, $start, $conn );
		}

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
		$start   = microtime( true ) + 1.5;
		$workers = array( $this->start( 'a', $start, $conn ), $this->start( 'b', $start, $conn ) );
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
}
