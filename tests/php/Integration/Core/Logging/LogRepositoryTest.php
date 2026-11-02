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
		$this->assertSame( '2026-11-02T12:00:00.000Z', $detail['ts'] );
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

	public function test_timestamps_are_stored_and_read_in_milliseconds(): void {
		$this->h->clock->advanceMilliseconds( 250 );
		$first = $this->h->logs->write( array( 'level' => 'info', 'message' => 'one' ) );
		$this->h->clock->advanceMilliseconds( 600 );
		$second = $this->h->logs->write( array( 'level' => 'info', 'message' => 'two' ) );

		$this->assertSame( '2026-11-02T12:00:00.250Z', $this->h->logs->find( $first )['ts'] );
		$this->assertSame( '2026-11-02T12:00:00.850Z', $this->h->logs->find( $second )['ts'] );
		$this->assertSame( '2026-11-02 12:00:00.250', $this->h->wpdb->get_var( $this->h->wpdb->prepare( "SELECT ts FROM {$this->h->tables->logs()} WHERE id = %d", $first ) ) ); // phpcs:ignore

		$csv = $this->h->logs->csv( LogQuery::fromArray( array() ) );
		$this->assertStringContainsString( '2026-11-02T12:00:00.850Z', $csv, 'The download shows the same time as the screen.' );
	}

	public function test_a_message_is_cut_to_500_characters(): void {
		$id = $this->h->logs->write( array( 'level' => 'info', 'message' => str_repeat( 'é', 800 ) ) );
		$this->assertSame( 500, mb_strlen( $this->h->logs->find( $id )['message'] ) );
	}

	public function test_the_timeline_of_one_item_is_oldest_first(): void {
		$this->h->logs->write( array( 'level' => 'info', 'message' => 'other item', 'queue_id' => 2 ) );
		$this->h->logs->write( array( 'level' => 'warning', 'message' => 'Try 1 failed', 'queue_id' => 1, 'code' => 'SYNC-NET-001' ) );
		$this->h->clock->advance( 60 );
		$this->h->logs->write( array( 'level' => 'info', 'message' => 'Sent', 'queue_id' => 1 ) );
		$timeline = $this->h->logs->timeline( 1 );
		$this->assertSame( array( 'Try 1 failed', 'Sent' ), array_column( $timeline, 'message' ) );
		$this->assertSame( array( 'at' => '2026-11-02T12:00:00.000Z', 'level' => 'warning', 'code' => 'SYNC-NET-001', 'message' => 'Try 1 failed' ), $timeline[0] );
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

	/**
	 * Write entries at different ages: 30 minutes, 5 hours, 3 days, 20 days and 60 days old.
	 *
	 * @return array<string,int> Entry ids by age label.
	 */
	private function entriesOfDifferentAges(): array {
		$ids = array();
		foreach ( array( '60d' => 60 * 86400, '20d' => 20 * 86400, '3d' => 3 * 86400, '5h' => 5 * 3600, '30m' => 1800 ) as $label => $age ) {
			$this->h->clock->set( '2026-11-02 12:00:00' );
			$this->h->clock->advance( -$age );
			$ids[ $label ] = $this->h->logs->write( array( 'level' => 'info', 'message' => "age $label", 'queue_id' => 7, 'code' => 'SYNC-NET-001' ) );
		}
		$this->h->clock->set( '2026-11-02 12:00:00' );
		return $ids;
	}

	/**
	 * @dataProvider ranges
	 *
	 * @param array<int,string> $expected Age labels that stay, newest first.
	 */
	public function test_the_range_keeps_only_recent_entries( string $range, array $expected ): void {
		$ids   = $this->entriesOfDifferentAges();
		$found = $this->h->logs->query( LogQuery::fromArray( array( 'range' => $range ) ) );
		$this->assertSame( array_map( static fn( string $label ) => $ids[ $label ], $expected ), array_column( $found->items, 'id' ), $range );
		$this->assertSame( count( $expected ), $found->total );
	}

	/**
	 * @return array<string,array{0:string,1:array<int,string>}>
	 */
	public static function ranges(): array {
		return array(
			'one hour'     => array( '1h', array( '30m' ) ),
			'24 hours'     => array( '24h', array( '30m', '5h' ) ),
			'seven days'   => array( '7d', array( '30m', '5h', '3d' ) ),
			'thirty days'  => array( '30d', array( '30m', '5h', '3d', '20d' ) ),
			'all time'     => array( 'all', array( '30m', '5h', '3d', '20d', '60d' ) ),
		);
	}

	public function test_without_a_range_or_with_an_unknown_one_everything_is_shown(): void {
		$this->entriesOfDifferentAges();
		$this->assertSame( 'all', LogQuery::fromArray( array() )->range );
		$this->assertSame( 'all', LogQuery::fromArray( array( 'range' => 'forever' ) )->range );
		$this->assertSame( 'all', LogQuery::fromArray( array( 'range' => '' ) )->range );
		$this->assertSame( '7d', LogQuery::fromArray( array( 'range' => ' 7D ' ) )->range );
		$this->assertNull( LogQuery::fromArray( array( 'range' => 'all' ) )->rangeSeconds() );
		$this->assertSame( 3600, LogQuery::fromArray( array( 'range' => '1h' ) )->rangeSeconds() );
		$this->assertSame( 5, $this->h->logs->query( LogQuery::fromArray( array( 'range' => 'forever' ) ) )->total );
		$this->assertSame( 5, $this->h->logs->query( new LogQuery() )->total, 'the constructor default is all time as well' );
	}

	public function test_the_range_follows_the_clock_not_the_wall(): void {
		$this->entriesOfDifferentAges();
		$this->h->clock->advance( 7200 );
		$this->assertSame( 0, $this->h->logs->query( LogQuery::fromArray( array( 'range' => '1h' ) ) )->total, 'two hours later the 30 minute old entry is outside one hour' );
		$this->assertSame( 2, $this->h->logs->query( LogQuery::fromArray( array( 'range' => '24h' ) ) )->total, 'the 30 minute and the 5 hour old entries are now 2.5 and 7 hours old' );
	}

	public function test_queue_id_and_code_filters_match_exactly_and_combine_with_the_range(): void {
		$this->entriesOfDifferentAges();
		$this->h->logs->write( array( 'level' => 'error', 'message' => 'other item, other code', 'queue_id' => 8, 'code' => 'SYNC-NET-002' ) );
		$this->h->logs->write( array( 'level' => 'error', 'message' => 'same item, other code', 'queue_id' => 7, 'code' => 'SYNC-NET-002' ) );
		$this->h->logs->write( array( 'level' => 'error', 'message' => 'longer code', 'queue_id' => 7, 'code' => 'SYNC-NET-0011' ) );

		$this->assertSame( 7, $this->h->logs->query( LogQuery::fromArray( array( 'queue_id' => 7 ) ) )->total, 'queue 7: five old entries, one with another code, one with a longer code' );
		$this->assertSame( 1, $this->h->logs->query( LogQuery::fromArray( array( 'queue_id' => 8 ) ) )->total );
		$this->assertSame( 2, $this->h->logs->query( LogQuery::fromArray( array( 'code' => 'SYNC-NET-002' ) ) )->total, 'exact: not SYNC-NET-0011 and not SYNC-NET-001' );
		$this->assertSame( 5, $this->h->logs->query( LogQuery::fromArray( array( 'code' => 'SYNC-NET-001' ) ) )->total );
		$this->assertSame( 1, $this->h->logs->query( LogQuery::fromArray( array( 'queue_id' => 7, 'code' => 'SYNC-NET-002' ) ) )->total );
		$this->assertSame( 3, $this->h->logs->query( LogQuery::fromArray( array( 'queue_id' => 7, 'code' => 'SYNC-NET-001', 'range' => '7d' ) ) )->total, 'item 7, that code, last 7 days: 30m, 5h, 3d' );
		$this->assertSame( 0, $this->h->logs->query( LogQuery::fromArray( array( 'queue_id' => 7, 'code' => 'SYNC-NET-002', 'range' => '1h', 'level' => 'info' ) ) )->total );
		$this->assertNull( LogQuery::fromArray( array( 'queue_id' => 0, 'code' => '  ' ) )->queueId );
		$this->assertNull( LogQuery::fromArray( array( 'queue_id' => 'x', 'code' => '  ' ) )->code );
		$this->assertSame( 20, mb_strlen( (string) LogQuery::fromArray( array( 'code' => str_repeat( 'A', 40 ) ) )->code ), 'a code is at most 20 characters, as stored' );
	}

	public function test_the_csv_applies_the_same_filters_as_the_screen(): void {
		$ids = $this->entriesOfDifferentAges();
		$this->h->logs->write( array( 'level' => 'error', 'message' => 'other item', 'queue_id' => 8, 'code' => 'SYNC-NET-002' ) );
		foreach ( array(
			array( 'range' => '24h' ),
			array( 'queue_id' => 7, 'range' => '7d' ),
			array( 'code' => 'SYNC-NET-002' ),
			array( 'queue_id' => 7, 'code' => 'SYNC-NET-001', 'range' => '30d', 'level' => 'info' ),
			array(),
		) as $filters ) {
			$query  = LogQuery::fromArray( $filters );
			$screen = array_column( $this->h->logs->query( $query )->items, 'message' );
			$lines  = array_values( array_filter( explode( "\r\n", trim( $this->h->logs->csv( $query ) ) ) ) );
			array_shift( $lines );
			$this->assertSame( count( $screen ), count( $lines ), json_encode( $filters ) );
			foreach ( $screen as $message ) {
				$this->assertStringContainsString( '"' . $message . '"', implode( "\n", $lines ), json_encode( $filters ) . ' ' . $message );
			}
		}
		$csv = $this->h->logs->csv( LogQuery::fromArray( array( 'range' => '1h' ) ) );
		$this->assertStringContainsString( 'age 30m', $csv );
		$this->assertStringNotContainsString( 'age 5h', $csv );
		$this->assertGreaterThan( 0, $ids['30m'] );
	}
}
