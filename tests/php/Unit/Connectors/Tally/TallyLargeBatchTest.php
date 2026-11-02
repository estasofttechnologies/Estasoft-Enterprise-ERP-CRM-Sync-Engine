<?php
/**
 * Ten thousand orders: one row at a time on the way in, one piece at a time on the way out.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Tally;

use Estasoft\SyncEngine\Tests\Support\FakeExportKit;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\LedgerExportConnector
 * @covers \Estasoft\SyncEngine\Connectors\Tally\TallyConnector
 */
final class TallyLargeBatchTest extends TallyTestCase {

	private const ORDERS = 10000;

	/**
	 * Sending orders keeps memory flat, the file is produced in pieces, and the whole file reads back with ten thousand vouchers.
	 */
	public function test_ten_thousand_orders(): void {
		$connection = $this->connection();

		gc_collect_cycles();
		$early = 0;
		for ( $i = 1; $i <= self::ORDERS; $i++ ) {
			$result = $this->send( $connection, FakeExportKit::order( (string) ( 100000 + $i ) ) );
			$this->assertTrue( $result->isSuccess() );
			if ( 500 === $i ) {
				gc_collect_cycles();
				$early = memory_get_usage();
			}
		}
		gc_collect_cycles();
		$grown = memory_get_usage() - $early;
		$this->assertLessThan( 2 * 1024 * 1024, $grown, 'Memory grew by ' . $grown . ' bytes while 9,500 more orders were sent.' );

		// The way out: the batch is read from disk one row at a time and the pieces are produced one at a time, so neither the
		// batch nor the file is ever in memory.
		$batch = $this->deps->exports->openBatch( 7 );
		$this->assertSame( self::ORDERS, $batch->count() );
		gc_collect_cycles();
		$base   = memory_get_usage();
		$peak   = 0;
		$pieces = 0;
		$bytes  = 0;
		foreach ( $this->connector->stream( $batch ) as $piece ) {
			++$pieces;
			$bytes += strlen( $piece );
			$peak   = max( $peak, memory_get_usage() - $base );
		}
		$this->assertSame( self::ORDERS + 2, $pieces );
		$this->assertLessThan( 1024 * 1024, $peak, 'Producing the pieces held ' . $peak . ' bytes.' );

		// The file itself: written to a work file in pieces, nothing kept in memory, and read back with a streaming parser.
		gc_collect_cycles();
		$base = memory_get_usage();
		$file = $this->connector->finish( $batch );
		gc_collect_cycles();
		$kept = memory_get_usage() - $base;
		$this->assertLessThan( 1024 * 1024, $kept, 'Making the file kept ' . $kept . ' bytes.' );
		$this->assertSame( '', $file->contents );
		$this->assertFileExists( $file->path );
		$this->assertSame( $bytes, filesize( $file->path ) );
		$this->assertTrue( $file->format->rawBytes );
		$reader  = \XMLReader::open( $file->path );
		$this->assertInstanceOf( \XMLReader::class, $reader );
		$count   = 0;
		$numbers = array();
		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT === $reader->nodeType && 'VOUCHERNUMBER' === $reader->name ) {
				$numbers[ $reader->readString() ] = true;
			}
			if ( \XMLReader::ELEMENT === $reader->nodeType && 'VOUCHER' === $reader->name ) {
				++$count;
			}
		}
		$reader->close();
		$this->assertSame( self::ORDERS, $count );
		$this->assertCount( self::ORDERS, $numbers );
		$this->assertArrayHasKey( '100001', $numbers );
		$this->assertArrayHasKey( (string) ( 100000 + self::ORDERS ), $numbers );
	}
}
