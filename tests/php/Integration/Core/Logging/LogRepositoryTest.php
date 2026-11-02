<?php
/**
 * Log storage and listing.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Logging;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\Repositories\LogQuery;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class LogRepositoryTest extends CoreIntegrationTestCase {

	public function test_an_entry_is_stored_and_read_back_in_the_rest_shape(): void {
		$id = $this->h->logs->write(
			array(
				'level'            => 'error',
				'message'          => 'Product CS-BOWL-04 was not found in Odoo.',
				'connection_id'    => 3,
				'queue_id'         => 9,
				'entity_ref'       => 'order:10485',
				'code'             => 'SYNC-MAP-001',
				'http_status'      => 200,
				'duration_ms'      => 840,
				'attempt'          => 1,
				'mapping_version'  => 7,
				'request_excerpt'  => '{"ref":"WC-10485"}',
				'response_excerpt' => '{"error":"no product"}',
			)
		);
		$this->assertGreaterThan( 0, $id );

		$detail = $this->h->logs->find( $id );
		$this->assertSame(
			array( 'id', 'ts', 'level', 'connection_id', 'queue_id', 'entity_ref', 'code', 'message', 'http_status', 'duration_ms', 'request_excerpt', 'response_excerpt', 'attempt', 'mapping_version' ),
			array_keys( $detail )
		);
		$this->assertSame( 'error', $detail['level'] );
		$this->assertSame( '2026-11-02T12:00:00Z', $detail['ts'] );
		$this->assertSame( 840, $detail['duration_ms'] );
		$this->assertSame( 7, $detail['mapping_version'] );
		$this->assertSame( '{"ref":"WC-10485"}', $detail['request_excerpt'] );
		$this->assertNull( $this->h->logs->find( $id + 99 ) );
	}

	public function test_missing_values_are_stored_as_null(): void {
		$id    = $this->h->logs->write( array( 'level' => 'info', 'message' => 'Hello' ) );
		$entry = $this->h->logs->find( $id );
		foreach ( array( 'connection_id', 'queue_id', 'entity_ref', 'code', 'http_status', 'duration_ms', 'request_excerpt', 'response_excerpt' ) as $key ) {
			$this->assertNull( $entry[ $key ], $key );
		}
	}

	public function test_an_unknown_level_becomes_info(): void {
		$id = $this->h->logs->write( array( 'level' => 'critical', 'message' => 'x' ) );
		$this->assertSame( 'info', $this->h->logs->find( $id )['level'] );
	}

	public function test_listing_filters_and_pages(): void {
		for ( $i = 1; $i <= 12; ++$i ) {
			$this->h->logs->write( array( 'level' => 0 === $i % 3 ? 'error' : 'info', 'message' => "Entry number $i", 'connection_id' => $i <= 6 ? 1 : 2, 'code' => 0 === $i % 3 ? 'SYNC-NET-001' : null, 'entity_ref' => 'order:' . ( 100 + $i ) ) );
		}
		$all = $this->h->logs->query( LogQuery::fromArray( array( 'per_page' => 10 ) ) );
		$this->assertSame( 12, $all->total );
		$this->assertCount( 10, $all->items );
		$this->assertSame( 'Entry number 12', $all->items[0]['message'], 'newest first' );
		$this->assertCount( 2, $this->h->logs->query( LogQuery::fromArray( array( 'per_page' => 10, 'page' => 2 ) ) )->items );

		$this->assertSame( 4, $this->h->logs->query( LogQuery::fromArray( array( 'level' => 'error' ) ) )->total );
		$this->assertSame( 6, $this->h->logs->query( LogQuery::fromArray( array( 'connection_id' => 2 ) ) )->total );
		$this->assertSame( 4, $this->h->logs->query( LogQuery::fromArray( array( 'code' => 'SYNC-NET-001' ) ) )->total );
		$this->assertSame( 1, $this->h->logs->query( LogQuery::fromArray( array( 'search' => 'number 7' ) ) )->total );
		$this->assertSame( 1, $this->h->logs->query( LogQuery::fromArray( array( 'search' => 'order:105' ) ) )->total );
		$this->assertSame( 4, $this->h->logs->query( LogQuery::fromArray( array( 'search' => 'NET-001' ) ) )->total );
		$this->assertSame( 0, $this->h->logs->query( LogQuery::fromArray( array( 'search' => '%' ) ) )->total );
	}

	public function test_the_dashboard_events_are_the_newest_few(): void {
		for ( $i = 1; $i <= 5; ++$i ) {
			$this->h->logs->write( array( 'level' => 'info', 'message' => "E$i" ) );
		}
		$this->assertSame( array( 'E5', 'E4', 'E3' ), array_column( $this->h->logs->latest( 3 ), 'message' ) );
	}

	public function test_the_timeline_of_one_item_is_oldest_first(): void {
		$this->h->logs->write( array( 'level' => 'info', 'message' => 'other item', 'queue_id' => 2 ) );
		$this->h->logs->write( array( 'level' => 'warning', 'message' => 'Try 1 failed', 'queue_id' => 1, 'code' => 'SYNC-NET-001' ) );
		$this->h->clock->advance( 60 );
		$this->h->logs->write( array( 'level' => 'info', 'message' => 'Sent', 'queue_id' => 1 ) );
		$timeline = $this->h->logs->timeline( 1 );
		$this->assertSame( array( 'Try 1 failed', 'Sent' ), array_column( $timeline, 'message' ) );
		$this->assertSame( array( 'at' => '2026-11-02T12:00:00Z', 'level' => 'warning', 'code' => 'SYNC-NET-001', 'message' => 'Try 1 failed' ), $timeline[0] );
	}

	public function test_entries_older_than_the_retention_time_are_deleted(): void {
		$this->h->logs->write( array( 'level' => 'info', 'message' => 'old' ) );
		$this->h->clock->advance( 10 * 86400 );
		$this->h->logs->write( array( 'level' => 'info', 'message' => 'middle' ) );
		$this->h->clock->advance( 5 * 86400 );
		$this->h->logs->write( array( 'level' => 'info', 'message' => 'new' ) );
		$this->assertSame( 1, $this->h->logs->prune( 14 ), 'the entry from 15 days ago goes' );
		$this->assertSame( array( 'new', 'middle' ), array_column( $this->h->logs->latest( 10 ), 'message' ) );
		$this->assertSame( 0, $this->h->logs->prune( 14 ) );
	}

	public function test_clear_removes_everything(): void {
		$this->h->logs->write( array( 'level' => 'info', 'message' => 'a' ) );
		$this->h->logs->write( array( 'level' => 'info', 'message' => 'b' ) );
		$this->assertSame( 2, $this->h->logs->clear() );
		$this->assertSame( 0, $this->h->logs->query( LogQuery::fromArray( array() ) )->total );
	}

	public function test_csv_has_a_header_escapes_quotes_and_defuses_formulas(): void {
		$this->h->logs->write( array( 'level' => 'error', 'message' => 'He said "no", then left', 'code' => 'SYNC-VAL-002', 'queue_id' => 4 ) );
		$this->h->logs->write( array( 'level' => 'info', 'message' => '=HYPERLINK("http://evil.example","x")' ) );
		$this->h->logs->write( array( 'level' => 'info', 'message' => '+1 and -2 and @here' ) );
		$csv   = $this->h->logs->csv( LogQuery::fromArray( array() ) );
		$lines = explode( "\r\n", trim( $csv ) );
		$this->assertSame( '"time","level","code","message","connection_id","queue_id","item","http_status","duration_ms"', $lines[0] );
		$this->assertStringContainsString( '"\'+1 and -2 and @here"', $csv );
		$this->assertStringContainsString( '"\'=HYPERLINK(""http://evil.example"",""x"")"', $csv );
		$this->assertStringContainsString( '"He said ""no"", then left"', $csv );
		$this->assertCount( 4, $lines );
		$this->assertStringContainsString( '"error"', $csv );
		$errors = $this->h->logs->csv( LogQuery::fromArray( array( 'level' => 'error' ) ) );
		$this->assertCount( 2, explode( "\r\n", trim( $errors ) ) );
	}
}
