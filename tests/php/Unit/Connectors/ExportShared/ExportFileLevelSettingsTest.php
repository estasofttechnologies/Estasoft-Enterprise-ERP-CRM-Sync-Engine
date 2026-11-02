<?php
/**
 * The settings that belong to the file (company, consultant number, character set, heading row) are read from the connection when the
 * file is made; everything an order's rows were built with stays as it was when the order was queued. A second send of the same
 * queue item does not add a second row.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ExportShared;

use Estasoft\SyncEngine\Connectors\Datev\DatevConnector;
use Estasoft\SyncEngine\Connectors\Sage50\Sage50Connector;
use Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportBatch;
use Estasoft\SyncEngine\Connectors\Tally\TallyConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\LedgerExportConnector
 */
final class ExportFileLevelSettingsTest extends TestCase {

	/**
	 * Temporary folder.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Services.
	 *
	 * @var ConnectorDependencies
	 */
	private ConnectorDependencies $deps;

	/**
	 * Fresh folder.
	 */
	protected function setUp(): void {
		$this->dir  = FakeExportKit::folder( 'filelevel' );
		$this->deps = FakeExportKit::deps( $this->dir );
	}

	/**
	 * Remove the folder.
	 */
	protected function tearDown(): void {
		FakeExportKit::remove( $this->dir );
	}

	/**
	 * An order that suits the connector: the currency of the books and a tax code the connector accepts.
	 *
	 * @param string $currency Currency.
	 * @param string $code     Tax code.
	 * @return array<string,mixed>
	 */
	private function order( string $currency, string $code ): array {
		$payload                         = FakeExportKit::order( '1001' );
		$payload['document']['currency'] = $currency;
		foreach ( array_keys( $payload['line'] ) as $i ) {
			$payload['line'][ $i ]['tax_code'] = $code;
		}
		$payload['shipping'][0]['tax_code'] = $code;
		return $payload;
	}

	/**
	 * The open batch, told which connection the file is made for (the engine does this before it calls finish()).
	 *
	 * @param Connection $connection Connection as it is now.
	 */
	private function batchFor( Connection $connection ): ExportBatch {
		$batch = $this->deps->exports->openBatch( 7 );
		$batch->forConnection( $connection );
		return $batch;
	}

	/**
	 * TallyPrime: the company and the encoding are taken from the connection now; the ledgers stay as they were queued.
	 */
	public function test_tally_company_and_encoding_follow_the_connection(): void {
		$connector = new TallyConnector( $this->deps );
		$queued    = FakeExportKit::connection(
			'tallyprime-export',
			array(
				'company'      => 'Old Trading LLC',
				'currency'     => 'AED',
				'sales_ledger' => 'Old Sales',
			)
		);
		$this->assertTrue( $connector->send( $queued, FakeExportKit::item( 1, '1001' ), $this->order( 'AED', 'Output VAT 5%' ) )->isSuccess() );

		$now   = FakeExportKit::connection(
			'tallyprime-export',
			array(
				'company'      => 'New Trading LLC',
				'currency'     => 'AED',
				'sales_ledger' => 'New Sales',
				'encoding'     => 'utf-8',
			)
		);
		$batch = $this->batchFor( $now );
		$file  = $connector->finish( $batch );
		$bytes = FakeExportKit::bytes( $file );

		$this->assertStringStartsWith( '<?xml version="1.0" encoding="UTF-8"?>', $bytes, 'UTF-8 now: no UTF-16 byte order mark.' );
		$this->assertStringContainsString( 'charset=utf-8', $file->format->mime );
		$this->assertSame( 'utf-8', $file->format->charset );
		$dom = new \DOMDocument();
		$this->assertTrue( $dom->loadXML( $bytes ) );
		$xp = new \DOMXPath( $dom );
		$this->assertSame( 'New Trading LLC', $xp->evaluate( 'string(//SVCURRENTCOMPANY)' ) );
		$this->assertStringContainsString( 'Old Sales', $bytes );
		$this->assertStringNotContainsString( 'New Sales', $bytes );

		$this->assertStringContainsString( 'for the company "New Trading LLC"', $connector->previewText( $this->batchFor( $now ) ) );
		$this->assertStringContainsString( 'Old Sales', $connector->mastersList( $this->batchFor( $now ) ) );
	}

	/**
	 * DATEV: consultant, client, origin and encoding come from the connection now; the account length and the accounts stay as queued.
	 */
	public function test_datev_header_follows_the_connection_and_records_stay(): void {
		$connector = new DatevConnector( $this->deps );
		$queued    = FakeExportKit::connection(
			'datev-export',
			array(
				'consultant_number' => 29098,
				'client_number'     => 55003,
				'account_length'    => 4,
				'currency'          => 'EUR',
			)
		);
		$this->assertTrue( $connector->send( $queued, FakeExportKit::item( 1, '1001' ), $this->order( 'EUR', '8400' ) )->isSuccess() );

		$now   = FakeExportKit::connection(
			'datev-export',
			array(
				'consultant_number' => 1001,
				'client_number'     => 12,
				'account_length'    => 6,
				'currency'          => 'EUR',
				'encoding'          => 'utf-8-bom',
				'exported_by'       => 'Nils Åström',
			)
		);
		$file  = $connector->finish( $this->batchFor( $now ) );
		$bytes = FakeExportKit::bytes( $file );

		$this->assertStringStartsWith( "\xEF\xBB\xBF\"EXTF\";700;", $bytes );
		$this->assertStringContainsString( 'charset=utf-8', $file->format->mime );
		$lines  = explode( "\r\n", rtrim( substr( $bytes, 3 ), "\r\n" ) );
		$header = str_getcsv( $lines[0], ';', '"', '' );
		$this->assertSame( '1001', $header[10] );
		$this->assertSame( '12', $header[11] );
		$this->assertSame( 'Nils Åström', $header[8], 'The name is written as UTF-8 now.' );
		$this->assertSame( '4', $header[13], 'The account length is the one the rows were built with.' );
		$record = str_getcsv( $lines[2], ';', '"', '' );
		$this->assertSame( '10000', $record[6], 'The debtor account has the queued length.' );

		$this->assertStringContainsString( 'Consultant 1001, client 12', $connector->previewText( $this->batchFor( $now ) ) );
	}

	/**
	 * Sage 50: the heading row and the encoding are taken from the connection now.
	 */
	public function test_sage_heading_row_and_encoding_follow_the_connection(): void {
		$connector = new Sage50Connector( $this->deps );
		$queued    = FakeExportKit::connection(
			'sage50-export',
			array(
				'edition'    => 'uk',
				'currency'   => 'GBP',
				'header_row' => 'no',
			)
		);
		$this->assertTrue( $connector->send( $queued, FakeExportKit::item( 1, '1001' ), $this->order( 'GBP', 'T1' ) )->isSuccess() );

		$plain = $connector->finish( $this->batchFor( $queued ) );
		$this->assertStringStartsNotWith( 'Type,', FakeExportKit::bytes( $plain ), 'No heading row as queued.' );

		$now   = FakeExportKit::connection(
			'sage50-export',
			array(
				'edition'    => 'uk',
				'currency'   => 'GBP',
				'header_row' => 'yes',
				'encoding'   => 'utf-8-bom',
			)
		);
		$file  = $connector->finish( $this->batchFor( $now ) );
		$bytes = FakeExportKit::bytes( $file );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $bytes );
		$lines = explode( "\r\n", rtrim( substr( $bytes, 3 ), "\r\n" ) );
		$this->assertStringStartsWith( 'Type,', $lines[0] );
		$this->assertCount( 1 + 3, $lines );
		$this->assertStringContainsString( 'charset=utf-8', $file->format->mime );
	}

	/**
	 * Without a connection on the batch (a batch built by hand) the rows' own settings are used.
	 */
	public function test_a_batch_without_a_connection_uses_the_queued_settings(): void {
		$connector = new TallyConnector( $this->deps );
		$queued    = FakeExportKit::connection( 'tallyprime-export', array( 'company' => 'Old Trading LLC', 'currency' => 'AED' ) );
		$connector->send( $queued, FakeExportKit::item( 1, '1001' ), $this->order( 'AED', 'Output VAT 5%' ) );
		$batch = $this->deps->exports->openBatch( 7 );
		$this->assertNull( $batch->connection() );
		$bytes = FakeExportKit::bytes( $connector->finish( $batch ) );
		$this->assertStringContainsString( 'Old Trading LLC', mb_convert_encoding( $bytes, 'UTF-8', 'UTF-16' ) );
	}

	/**
	 * The same queue item sent twice is found in the batch and not added again, however the connector counts it.
	 */
	public function test_a_second_send_finds_the_first(): void {
		$connector = new DatevConnector( $this->deps );
		$conn      = FakeExportKit::connection( 'datev-export', array( 'consultant_number' => 29098, 'client_number' => 55003 ) );
		$first     = $connector->send( $conn, FakeExportKit::item( 5, '5001' ), $this->order( 'EUR', '8400' ) );
		$second    = $connector->send( $conn, FakeExportKit::item( 5, '5001' ), $this->order( 'EUR', '8400' ) );
		$this->assertTrue( $first->isSuccess() );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertTrue( $second->isSuccess() );
		$this->assertTrue( $second->alreadyExisted );
		$this->assertSame( 1, $this->deps->exports->openBatch( 7 )->count() );
	}
}
