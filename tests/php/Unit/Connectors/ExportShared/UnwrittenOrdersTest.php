<?php
/**
 * An order that is not in the file must never be marked exported. The engine marks every included order exported and closes its row, so
 * the connector reports each order it could not write as left out (SYNC-EXP-002), and a file with nothing in it is not stored.
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
use Estasoft\SyncEngine\Tests\Support\FakeExportKit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Estasoft\SyncEngine\Connectors\ExportShared\LedgerExportConnector
 */
final class UnwrittenOrdersTest extends TestCase {

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
		$this->dir = FakeExportKit::folder( 'unwritten' );
	}

	/**
	 * Remove the folder.
	 */
	protected function tearDown(): void {
		FakeExportKit::remove( $this->dir );
	}

	/**
	 * A recording stand-in for the queue.
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
	 * The three connectors with their settings, the currency of the books and a tax code each accepts.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string,3:string}>
	 */
	public static function systems(): array {
		return array(
			'tally' => array( 'tallyprime-export', array( 'company' => 'Cedar & Stone Trading LLC', 'currency' => 'AED' ), 'AED', 'Output VAT 5%' ),
			'datev' => array( 'datev-export', array( 'consultant_number' => 29098, 'client_number' => 55003 ), 'EUR', '8400' ),
			'sage'  => array( 'sage50-export', array( 'edition' => 'uk', 'currency' => 'GBP' ), 'GBP', 'T1' ),
		);
	}

	/**
	 * A connector, the service around it and a queue, with one good order (item 11) in the open batch.
	 *
	 * @param string              $id      Connector id.
	 * @param array<string,mixed> $options Settings.
	 * @param string              $currency Currency.
	 * @param string              $code     Tax code.
	 * @param bool                $good     Whether to queue the good order.
	 * @return array{0:ExportService,1:ExportQueue,2:\Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies}
	 */
	private function setUpSystem( string $id, array $options, string $currency, string $code, bool $good ): array {
		$deps      = FakeExportKit::deps( $this->dir );
		$connector = match ( $id ) {
			'tallyprime-export' => new TallyConnector( $deps ),
			'datev-export'      => new DatevConnector( $deps ),
			default             => new Sage50Connector( $deps ),
		};
		$conn      = FakeExportKit::connection( $id, $options );
		$queue     = $this->queue();
		$service   = new ExportService(
			$deps->exports,
			static fn( int $connectionId ): ?AbstractExportConnector => 7 === $connectionId ? $connector : null,
			$queue,
			null,
			null,
			static fn( int $connectionId ): ?Connection => 7 === $connectionId ? $conn : null
		);
		if ( $good ) {
			$payload                         = FakeExportKit::order( '1001' );
			$payload['document']['currency'] = $currency;
			foreach ( array_keys( $payload['line'] ) as $i ) {
				$payload['line'][ $i ]['tax_code'] = $code;
			}
			$payload['shipping'][0]['tax_code'] = $code;
			$this->assertTrue( $connector->send( $conn, FakeExportKit::item( 11, '1001' ), $payload )->isSuccess() );
		}
		return array( $service, $queue, $deps );
	}

	/**
	 * A row that the connector would write if it were of this version, saved with a version number from another release.
	 *
	 * @param \Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies $deps Services.
	 * @param int                                                          $item Queue item id.
	 */
	private function otherVersionRow( $deps, int $item ): void {
		$deps->exports->appendToBatch( 7, $item, '10' . $item, array( 'v' => 99, 'text' => 'old text', 'settings' => array() ) );
	}

	/**
	 * A row file that holds something that is not JSON (a half-written or damaged file).
	 *
	 * @param \Estasoft\SyncEngine\Connectors\Support\ConnectorDependencies $deps Services.
	 * @param int                                                          $item Queue item id.
	 */
	private function damagedRow( $deps, int $item ): void {
		$root = $deps->exports->root( true );
		$this->assertNotNull( $root );
		if ( ! is_dir( $root . '/batches/7' ) ) {
			mkdir( $root . '/batches/7', 0777, true );
		}
		file_put_contents( $root . '/batches/7/' . $item . '.json', '{"item_id": 13, "order": "13", "row": {' );
	}

	/**
	 * A row from another version of the plugin is left out with a reason, the good order is exported, and only the good order is marked.
	 *
	 * @dataProvider systems
	 *
	 * @param string              $id       Connector id.
	 * @param array<string,mixed> $options  Settings.
	 * @param string              $currency Currency.
	 * @param string              $code     Tax code.
	 */
	public function test_a_row_of_another_version_is_left_out( string $id, array $options, string $currency, string $code ): void {
		list( $service, $queue, $deps ) = $this->setUpSystem( $id, $options, $currency, $code, true );
		$this->otherVersionRow( $deps, 12 );
		$stored = $service->create( 7 );
		$this->assertSame( 1, $stored->orders );
		$this->assertCount( 1, $stored->leftOut );
		$this->assertSame( '10' . 12, $stored->leftOut[0]['order'] );
		$this->assertStringContainsString( 'another version of the plugin', $stored->leftOut[0]['reason'] );
		$this->assertSame( array( 11 ), array_keys( $queue->exported ), 'Only the order in the file is marked exported.' );
		$this->assertSame( array( 12 ), array_keys( $queue->left ) );
		$this->assertStringContainsString( 'another version of the plugin', $queue->left[12] );
	}

	/**
	 * A row file that cannot be read is left out with a reason; the order id is unknown, so the queue item id stands for it.
	 *
	 * @dataProvider systems
	 *
	 * @param string              $id       Connector id.
	 * @param array<string,mixed> $options  Settings.
	 * @param string              $currency Currency.
	 * @param string              $code     Tax code.
	 */
	public function test_an_unreadable_row_is_left_out( string $id, array $options, string $currency, string $code ): void {
		list( $service, $queue, $deps ) = $this->setUpSystem( $id, $options, $currency, $code, true );
		$this->damagedRow( $deps, 13 );
		$stored = $service->create( 7 );
		$this->assertSame( 1, $stored->orders );
		$this->assertSame( array( array( 'order' => '13', 'reason' => 'its saved row could not be read; send it again' ) ), $stored->leftOut );
		$this->assertSame( array( 11 ), array_keys( $queue->exported ) );
		$this->assertSame( array( 13 ), array_keys( $queue->left ) );
	}

	/**
	 * Both at once, next to the good order.
	 *
	 * @dataProvider systems
	 *
	 * @param string              $id       Connector id.
	 * @param array<string,mixed> $options  Settings.
	 * @param string              $currency Currency.
	 * @param string              $code     Tax code.
	 */
	public function test_every_order_not_written_is_listed( string $id, array $options, string $currency, string $code ): void {
		list( $service, $queue, $deps ) = $this->setUpSystem( $id, $options, $currency, $code, true );
		$this->otherVersionRow( $deps, 12 );
		$this->damagedRow( $deps, 13 );
		$stored = $service->create( 7 );
		$this->assertSame( 1, $stored->orders );
		$this->assertCount( 2, $stored->leftOut );
		$this->assertSame( array( 11 ), array_keys( $queue->exported ) );
		$this->assertSame( array( 12, 13 ), array_keys( $queue->left ) );
		$this->assertNull( $service->overview( 7 )['open_batch'], 'The batch is closed: nothing is left behind to be forgotten.' );
	}

	/**
	 * When nothing can be written the service raises SYNC-EXP-002, stores no file and marks nothing exported.
	 *
	 * @dataProvider systems
	 *
	 * @param string              $id       Connector id.
	 * @param array<string,mixed> $options  Settings.
	 * @param string              $currency Currency.
	 * @param string              $code     Tax code.
	 */
	public function test_an_empty_file_raises_exp_002_and_marks_nothing( string $id, array $options, string $currency, string $code ): void {
		list( $service, $queue, $deps ) = $this->setUpSystem( $id, $options, $currency, $code, false );
		$this->otherVersionRow( $deps, 12 );
		$this->damagedRow( $deps, 13 );
		try {
			$service->create( 7 );
			$this->fail( 'Expected SYNC-EXP-002.' );
		} catch ( ExportException $exception ) {
			$this->assertSame( 'SYNC-EXP-002', $exception->error()->code->value );
			$this->assertStringContainsString( '2 orders were left out of the export', $exception->error()->message() );
		}
		$this->assertSame( array(), $queue->exported, 'Nothing is marked exported.' );
		$this->assertSame( array( 12, 13 ), array_keys( $queue->left ) );
		$this->assertSame( array(), $deps->exports->list( 7 ), 'No file is stored.' );
	}

	/**
	 * The connector on its own: after finish() the batch no longer counts an order that is not in the file.
	 */
	public function test_the_batch_counts_only_what_is_in_the_file(): void {
		list( , , $deps ) = $this->setUpSystem( 'datev-export', array( 'consultant_number' => 29098, 'client_number' => 55003 ), 'EUR', '8400', true );
		$this->otherVersionRow( $deps, 12 );
		$batch = $deps->exports->openBatch( 7 );
		$this->assertSame( array( 11, 12 ), $batch->includedIds() );
		( new DatevConnector( $deps ) )->finish( $batch );
		$this->assertSame( array( 11 ), $batch->includedIds() );
		$this->assertSame( array( 12 ), $batch->leftOutItemIds() );
	}
}
