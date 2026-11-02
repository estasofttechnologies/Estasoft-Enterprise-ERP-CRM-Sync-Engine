<?php
/**
 * Ten thousand orders into one Sage 50 file.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage50;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\LedgerExportConnector
 * @covers \Estasoft\SyncEngine\Connectors\Sage50\Sage50Connector
 */
final class Sage50LargeBatchTest extends Sage50TestCase {

	private const ORDERS = 10000;

	/**
	 * Orders go in one row at a time with flat memory, come out in pieces, and every order is in the file once.
	 */
	public function test_ten_thousand_orders(): void {
		$connection = $this->uk();
		gc_collect_cycles();
		$early = 0;
		for ( $i = 1; $i <= self::ORDERS; $i++ ) {
			$payload = $this->order( (string) ( 100000 + $i ) );
			$this->assertTrue( $this->send( $connection, $payload )->isSuccess() );
			if ( 500 === $i ) {
				gc_collect_cycles();
				$early = memory_get_usage();
			}
		}
		gc_collect_cycles();
		$grown = memory_get_usage() - $early;
		$this->assertLessThan( 2 * 1024 * 1024, $grown, 'Memory grew by ' . $grown . ' bytes while 9,500 more orders were sent.' );

		$batch = $this->deps->exports->openBatch( 7 );
		$this->assertSame( self::ORDERS, $batch->count() );
		gc_collect_cycles();
		$base   = memory_get_usage();
		$peak   = 0;
		$pieces = 0;
		foreach ( $this->connector->stream( $batch ) as $piece ) {
			++$pieces;
			$peak = max( $peak, memory_get_usage() - $base );
		}
		$this->assertSame( self::ORDERS, $pieces );
		$this->assertLessThan( 1024 * 1024, $peak, 'Producing the pieces held ' . $peak . ' bytes.' );

		gc_collect_cycles();
		$base = memory_get_usage();
		$file = $this->connector->finish( $batch );
		gc_collect_cycles();
		$kept = memory_get_usage() - $base;
		$this->assertLessThan( 1024 * 1024, $kept, 'Making the file kept ' . $kept . ' bytes.' );
		$this->assertSame( '', $file->contents );
		$this->assertTrue( $file->format->rawBytes );

		// Read back row by row with PHP's own CSV reader.
		$handle = fopen( $file->path, 'rb' );
		$this->assertNotFalse( $handle );
		$rows = 0;
		$seen = array();
		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			++$rows;
			if ( 1 === $rows ) {
				continue;
			}
			$this->assertCount( 13, $row );
			$seen[ $row[5] ] = ( $seen[ $row[5] ] ?? 0 ) + 1;
		}
		fclose( $handle );
		$this->assertSame( 1 + ( 3 * self::ORDERS ), $rows );
		$this->assertCount( self::ORDERS, $seen );
		$this->assertSame( array( 3 ), array_values( array_unique( $seen ) ) );
	}
}
