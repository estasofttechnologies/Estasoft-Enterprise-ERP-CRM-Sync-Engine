<?php
/**
 * Filters and paging values.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Core\Queue;

use Estasoft\SyncEngine\Core\Repositories\LogQuery;
use Estasoft\SyncEngine\Core\Repositories\Paged;
use Estasoft\SyncEngine\Core\Repositories\QueueQuery;
use Estasoft\SyncEngine\Core\Queue\Outcome;
use Estasoft\SyncEngine\Core\Queue\WorkerReport;
use PHPUnit\Framework\TestCase;

final class QueryObjectsTest extends TestCase {

	public function test_queue_query_keeps_valid_input_and_drops_the_rest(): void {
		$query = QueueQuery::fromArray(
			array(
				'state'         => 'failed',
				'entity_type'   => 'order',
				'day'           => '7d',
				'search'        => '  #10490 ',
				'error_code'    => 'SYNC-MAP-001',
				'connection_id' => '4',
				'page'          => '3',
				'per_page'      => '50',
			)
		);
		$this->assertSame( 'failed', $query->state );
		$this->assertSame( 'order', $query->entityType );
		$this->assertSame( '7d', $query->day );
		$this->assertSame( '#10490', $query->search );
		$this->assertSame( 4, $query->connectionId );
		$this->assertSame( 3, $query->page );
		$this->assertSame( 50, $query->perPage );

		$bad = QueueQuery::fromArray( array( 'state' => 'exploded', 'entity_type' => 'spaceship', 'day' => 'forever', 'page' => '-4', 'per_page' => '7', 'search' => '   ' ) );
		$this->assertNull( $bad->state );
		$this->assertNull( $bad->entityType );
		$this->assertNull( $bad->day );
		$this->assertNull( $bad->search );
		$this->assertSame( 1, $bad->page );
		$this->assertSame( 25, $bad->perPage );
	}

	public function test_waiting_and_all_are_valid_states(): void {
		$this->assertSame( 'waiting', QueueQuery::fromArray( array( 'state' => 'waiting' ) )->state );
		$this->assertSame( 'all', QueueQuery::fromArray( array( 'state' => 'all' ) )->state );
	}

	public function test_per_page_offers_25_50_and_100_only(): void {
		foreach ( array( 25, 50, 100 ) as $size ) {
			$this->assertSame( $size, QueueQuery::fromArray( array( 'per_page' => $size ) )->perPage );
		}
		$this->assertSame( 25, QueueQuery::fromArray( array( 'per_page' => 1000 ) )->perPage );
	}

	public function test_log_query_limits(): void {
		$query = LogQuery::fromArray( array( 'level' => 'error', 'per_page' => 5000, 'page' => 0 ) );
		$this->assertSame( 'error', $query->level );
		$this->assertSame( 200, $query->perPage );
		$this->assertSame( 1, $query->page );
		$this->assertNull( LogQuery::fromArray( array( 'level' => 'debug' ) )->level );
	}

	public function test_paged_matches_the_rest_shape(): void {
		$paged = new Paged( array( 'a', 'b' ), 53, 2, 25 );
		$this->assertSame( 3, $paged->totalPages() );
		$this->assertSame(
			array(
				'items'       => array( 'a', 'b' ),
				'total'       => 53,
				'total_pages' => 3,
				'page'        => 2,
				'per_page'    => 25,
			),
			$paged->toArray()
		);
		$this->assertSame( 0, ( new Paged( array(), 0, 1, 25 ) )->totalPages() );
	}

	public function test_worker_report_counts_outcomes(): void {
		$report = new WorkerReport();
		foreach ( array( Outcome::Synced, Outcome::Linked, Outcome::NoChange, Outcome::Shadow, Outcome::Retrying, Outcome::Failed, Outcome::Held, Outcome::Skipped ) as $outcome ) {
			$report->add( $outcome );
		}
		$this->assertSame( 4, $report->synced );
		$this->assertSame( 1, $report->retrying );
		$this->assertSame( 1, $report->failed );
		$this->assertSame( 1, $report->held );
		$this->assertSame( 1, $report->skipped );
		$this->assertSame( 'idle', $report->toArray()['stopped_because'] );
	}
}
