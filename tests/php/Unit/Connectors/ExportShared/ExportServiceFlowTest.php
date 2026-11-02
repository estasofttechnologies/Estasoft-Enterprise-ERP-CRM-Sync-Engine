<?php
/**
 * The three ledger connectors through the engine's export service: orders in, one file on disk, items marked, left-out orders named.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\ExportShared;

use Estasoft\SyncEngine\Connectors\Datev\DatevConnector;
use Estasoft\SyncEngine\Connectors\Sage50\Sage50Connector;
use Estasoft\SyncEngine\Connectors\Support\Export\AbstractExportConnector;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportException;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportQueue;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportService;
use Estasoft\SyncEngine\Connectors\Tally\TallyConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\LedgerExportConnector
 */
final class ExportServiceFlowTest extends TestCase {

	/**
	 * Temporary folder.
	 *
	 * @var string
	 */
	private string $dir = '';

	/**
	 * Fresh folder.
	 */
	protected function setUp(): void {
		$this->dir = FakeExportKit::folder( 'flow' );
	}

	/**
	 * Remove the folder.
	 */
	protected function tearDown(): void {
		FakeExportKit::remove( $this->dir );
	}

	/**
	 * A recording stand-in for the queue.
	 *
	 * @return ExportQueue
	 */
	private function queue(): ExportQueue {
		return new class() implements ExportQueue {
			/**
			 * Items marked exported: item id => file name.
			 *
			 * @var array<int,string>
			 */
			public array $exported = array();

			/**
			 * Items left out: item id => message.
			 *
			 * @var array<int,string>
			 */
			public array $left = array();

			/**
			 * Record an exported item.
			 *
			 * @param int    $itemId   Item.
			 * @param string $fileId   File id.
			 * @param string $filename File name.
			 */
			public function markExported( int $itemId, string $fileId, string $filename ): void {
				unset( $fileId );
				$this->exported[ $itemId ] = $filename;
			}

			/**
			 * Record a left-out item.
			 *
			 * @param int    $itemId  Item.
			 * @param string $message Message.
			 * @param string $cause   Cause.
			 */
			public function markLeftOut( int $itemId, string $message, string $cause ): void {
				unset( $cause );
				$this->left[ $itemId ] = $message;
			}
		};
	}

	/**
	 * Connectors with their connection settings, a good order, an order the file cannot hold, a refund, and what the file looks like.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string,3:string,4:string}>
	 */
	public static function systems(): array {
		return array(
			'tally' => array( 'tallyprime-export', array( 'company' => 'Cedar & Stone Trading LLC', 'currency' => 'AED' ), 'AED', 'xml', 'Ledgers (' ),
			'datev' => array( 'datev-export', array( 'consultant_number' => 29098, 'client_number' => 55003 ), 'EUR', 'csv', 'Revenue accounts (Erlöskonten) (' ),
			'sage'  => array( 'sage50-export', array( 'edition' => 'uk', 'currency' => 'GBP' ), 'GBP', 'csv', 'Nominal codes (' ),
		);
	}

	/**
	 * Orders and a refund go in, the file is made, two items are exported, one is left out with its reason, and the batch is closed.
	 *
	 * @dataProvider systems
	 *
	 * @param string              $id       Connector id.
	 * @param array<string,mixed> $options  Settings.
	 * @param string              $currency Currency of the books.
	 * @param string              $ext      File extension.
	 * @param string              $heading  Start of a heading in the masters list.
	 */
	public function test_orders_become_one_file( string $id, array $options, string $currency, string $ext, string $heading ): void {
		$deps      = FakeExportKit::deps( $this->dir );
		$connector = match ( $id ) {
			'tallyprime-export' => new TallyConnector( $deps ),
			'datev-export'      => new DatevConnector( $deps ),
			default             => new Sage50Connector( $deps ),
		};
		$queue   = $this->queue();
		$conn    = FakeExportKit::connection( $id, $options );
		$service = new ExportService( $deps->exports, static fn( int $connectionId ): ?AbstractExportConnector => 7 === $connectionId ? $connector : null, $queue, null, null, static fn( int $connectionId ): ?Connection => 7 === $connectionId ? $conn : null );
		$code    = array(
			'tallyprime-export' => 'Output VAT 5%',
			'datev-export'      => '8400',
			'sage50-export'     => 'T1',
		)[ $id ];

		$good = FakeExportKit::order( '1001' );
		$good['document']['currency'] = $currency;
		$good['document']['total']    = 15750;
		foreach ( array_keys( $good['line'] ) as $i ) {
			$good['line'][ $i ]['tax_code'] = $code;
		}
		$good['shipping'][0]['tax_code'] = $code;
		$bad = $good;
		$bad['document']['number']   = '1002';
		$bad['document']['reference'] = 'WC-1002';
		$bad['document']['currency'] = 'JPY';
		$refund = FakeExportKit::refund( '1001', 55 );
		$refund['document']['currency'] = $currency;
		$refund['original_line'][0]['tax_code']     = $code;
		$refund['original_shipping'][0]['tax_code'] = $code;

		$this->assertTrue( $connector->send( $conn, FakeExportKit::item( 11, '1001' ), $good )->isSuccess() );
		$this->assertTrue( $connector->send( $conn, FakeExportKit::item( 12, '1002' ), $bad )->isSuccess(), 'The order is accepted into the batch; the file leaves it out.' );
		$this->assertTrue( $connector->send( $conn, FakeExportKit::item( 13, '1001', EntityType::Refund ), $refund )->isSuccess() );
		$this->assertSame( 3, $service->overview( 7 )['open_batch']['orders'] );

		$stored = $service->create( 7, 'Layla Haddad' );
		$this->assertSame( 2, $stored->orders );
		$this->assertSame( array( array( 'order' => '1002', 'reason' => sprintf( 'its currency (JPY) is not the currency of the books (%s)', $currency ) ) ), $stored->leftOut );
		$this->assertSame( 'Layla Haddad', $stored->createdBy );
		$this->assertMatchesRegularExpression( '/^7-\d{8}-\d{6}-[0-9a-f]{8}\.' . $ext . '$/', $stored->filename );

		// The masters list is the second file of the export: the names the person needs in the system before importing.
		$this->assertCount( 1, $stored->attachments );
		$this->assertSame( 'Masters list', $stored->attachments[0]['name'] );
		$masters = $service->download( $stored->id, 1 );
		$this->assertNotNull( $masters );
		$this->assertStringStartsWith( 'text/plain', $masters['mime'] );
		$list = (string) file_get_contents( $masters['path'] );
		$this->assertStringContainsString( $heading, $list );
		$this->assertStringNotContainsString( 'JPY', $list );
		$this->assertSame( $masters['size'], $stored->info()['attachments'][0]['size_bytes'] );

		$this->assertSame( array( 11 => $stored->filename, 13 => $stored->filename ), $queue->exported );
		$this->assertSame( array( 12 ), array_keys( $queue->left ) );
		$this->assertStringContainsString( '1 orders were left out of the export: its currency (JPY)', $queue->left[12] );
		$this->assertNull( $service->overview( 7 )['open_batch'], 'The batch is closed.' );

		$download = $service->download( $stored->id );
		$this->assertNotNull( $download );
		$bytes = (string) file_get_contents( $download['path'] );
		$this->assertSame( $download['size'], strlen( $bytes ) );
		$this->assertSame( $stored->sizeBytes, strlen( $bytes ) );
		$this->assertStringContainsString( 'tallyprime-export' === $id ? 'charset=utf-16' : 'charset=windows-1252', $download['mime'] );
		$this->assertStringNotContainsString( 'JPY', mb_convert_encoding( $bytes, 'UTF-8', 'tallyprime-export' === $id ? 'UTF-16' : 'Windows-1252' ) );

		// The file carries the sale and the credit note, once each.
		$text = mb_convert_encoding( $bytes, 'UTF-8', 'tallyprime-export' === $id ? 'UTF-16' : 'Windows-1252' );
		if ( 'tallyprime-export' === $id ) {
			$this->assertSame( 2, substr_count( $text, '<VOUCHER ' ) );
			$this->assertSame( 1, substr_count( $text, 'VCHTYPE="Credit Note"' ) );
		} elseif ( 'datev-export' === $id ) {
			$this->assertCount( 4, explode( "\r\n", rtrim( $text, "\r\n" ) ) );
		} else {
			$this->assertCount( 1 + 3 + 1, explode( "\r\n", rtrim( $text, "\r\n" ) ) );
		}
	}

	/**
	 * When every order is left out the service says so and writes no file.
	 */
	public function test_nothing_to_write_when_everything_is_left_out(): void {
		$deps      = FakeExportKit::deps( $this->dir );
		$connector = new TallyConnector( $deps );
		$queue     = $this->queue();
		$service   = new ExportService( $deps->exports, static fn( int $id ): ?AbstractExportConnector => $connector, $queue );
		$payload   = FakeExportKit::order();
		$payload['document']['currency'] = 'SAR';
		$connector->send( FakeExportKit::connection( 'tallyprime-export', array( 'company' => 'X', 'currency' => 'AED' ) ), FakeExportKit::item( 21, '1001' ), $payload );
		try {
			$service->create( 7 );
			$this->fail( 'Expected SYNC-EXP-002.' );
		} catch ( ExportException $exception ) {
			$this->assertSame( 'SYNC-EXP-002', $exception->error()->code->value );
			$this->assertStringContainsString( '1 orders were left out of the export: its currency (SAR)', $exception->error()->message() );
		}
		$this->assertSame( array(), $deps->exports->list( 7 ) );
		$this->assertArrayHasKey( 21, $queue->left );
	}

	/**
	 * A DATEV order of another financial year is left out when the file is made, and a retry puts it into the next file.
	 */
	public function test_next_financial_year_goes_into_the_next_file(): void {
		$deps      = FakeExportKit::deps( $this->dir );
		$connector = new DatevConnector( $deps );
		$queue     = $this->queue();
		$service   = new ExportService( $deps->exports, static fn( int $id ): ?AbstractExportConnector => $connector, $queue );
		$conn      = FakeExportKit::connection( 'datev-export', array( 'consultant_number' => 29098, 'client_number' => 55003 ) );
		$make      = static function ( string $number, string $date ): array {
			$payload = FakeExportKit::order( $number );
			$payload['document']['currency'] = 'EUR';
			$payload['document']['date']     = $date;
			$payload['document']['total']    = 15750;
			foreach ( array_keys( $payload['line'] ) as $i ) {
				$payload['line'][ $i ]['tax_code'] = '8400';
			}
			$payload['shipping'][0]['tax_code'] = '8400';
			return $payload;
		};
		$connector->send( $conn, FakeExportKit::item( 31, '3001' ), $make( '3001', '2026-12-30' ) );
		$connector->send( $conn, FakeExportKit::item( 32, '3002' ), $make( '3002', '2027-01-02' ) );
		$first = $service->create( 7 );
		$this->assertSame( 1, $first->orders );
		$this->assertSame( '3002', $first->leftOut[0]['order'] );
		$this->assertStringContainsString( 'different financial year', $first->leftOut[0]['reason'] );
		$this->assertArrayHasKey( 32, $queue->left );

		// Retry: the queue sends the item again, and it goes into a file of its own year.
		$connector->send( $conn, FakeExportKit::item( 32, '3002' ), $make( '3002', '2027-01-02' ) );
		$second = $service->create( 7 );
		$this->assertSame( 1, $second->orders );
		$this->assertSame( array(), $second->leftOut );
		$this->assertCount( 2, $deps->exports->list( 7 ) );
	}
}
