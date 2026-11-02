<?php
/**
 * Ten thousand orders into one DATEV file.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Datev;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\LedgerExportConnector
 * @covers \Estasoft\SyncEngine\Connectors\Datev\DatevConnector
 */
final class DatevLargeBatchTest extends DatevTestCase {

	private const ORDERS = 10000;

	/**
	 * Orders go in one row at a time with flat memory, come out in pieces, and the file reads back with every posting once.
	 */
	public function test_ten_thousand_orders(): void {
		$connection = $this->connection();
		gc_collect_cycles();
		$early = 0;
		for ( $i = 1; $i <= self::ORDERS; $i++ ) {
			$payload                       = $this->order( (string) ( 100000 + $i ) );
			$payload['customer']['name']   = 'Kunde Ä ' . $i;
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
		$this->assertSame( self::ORDERS + 1, $pieces );
		$this->assertLessThan( 1024 * 1024, $peak, 'Producing the pieces held ' . $peak . ' bytes.' );

		gc_collect_cycles();
		$base = memory_get_usage();
		$file = $this->connector->finish( $batch );
		gc_collect_cycles();
		$kept = memory_get_usage() - $base;
		$this->assertLessThan( 1024 * 1024, $kept, 'Making the file kept ' . $kept . ' bytes.' );
		$this->assertSame( '', $file->contents );
		$this->assertTrue( $file->format->rawBytes );

		// Read back line by line with PHP's own functions.
		$handle = fopen( $file->path, 'rb' );
		$this->assertNotFalse( $handle );
		$count = 0;
		$seen  = array();
		$first = '';
		while ( false !== ( $raw = fgets( $handle ) ) ) {
			$line = rtrim( mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' ), "\r\n" );
			++$count;
			if ( $count <= 2 ) {
				continue;
			}
			$fields = str_getcsv( $line, ';', '"', '' );
			$this->assertCount( 125, $fields );
			$seen[ $fields[10] ] = true;
			if ( '' === $first ) {
				$first = $fields[13];
			}
		}
		fclose( $handle );
		$this->assertSame( self::ORDERS + 2, $count );
		$this->assertCount( self::ORDERS, $seen );
		$this->assertSame( 'WC 100001 Kunde Ä 1', $first );
	}
}
