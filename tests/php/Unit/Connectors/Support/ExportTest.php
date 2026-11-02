<?php
/**
 * Export files: the protected folder, batches, the service and an export connector.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support;

require_once dirname( __DIR__, 3 ) . '/Support/ApiWpFunctions.php';

use Estasoft\SyncEngine\Connectors\Support\Export\ExportException;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportQueue;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportService;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportStore;
use Estasoft\SyncEngine\Connectors\Support\GuardedConnector;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Tests\Support\ApiMemoryLock;
use Estasoft\SyncEngine\Tests\Support\ApiTestKit;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceExportConnector;
use Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors\ReferenceStreamingExportConnector;
use PHPUnit\Framework\TestCase;

final class ExportTest extends TestCase {

	private ApiTestKit $kit;

	private ReferenceExportConnector $connector;

	private ExportService $service;

	private object $queue;

	protected function setUp(): void {
		$this->kit       = ApiTestKit::make();
		$this->connector = new ReferenceExportConnector( $this->kit->deps );
		$this->queue     = new class() implements ExportQueue {
			public array $exported = array();
			public array $leftOut = array();
			public function markExported( int $itemId, string $fileId, string $filename ): void {
				$this->exported[ $itemId ] = array( $fileId, $filename );
			}
			public function markLeftOut( int $itemId, string $message, string $cause ): void {
				$this->leftOut[ $itemId ] = array( $message, $cause );
			}
		};
		$this->service = new ExportService( $this->kit->exports, fn( int $id ) => 1 === $id ? $this->connector : null, $this->queue, new ApiMemoryLock() );
	}

	protected function tearDown(): void {
		$this->kit->cleanup();
	}

	private function connection(): Connection {
		return new Connection( 1, 'reference-export', ConnectorType::Accounting, 'Acme Ledger', ConnectionStatus::Active );
	}

	private function item( int $id ): Item {
		return new Item( $id, 1, EntityType::Order, (string) ( 5000 + $id ), 'created', 'order:' . $id, 'k' . $id, ItemState::Syncing );
	}

	private function payload( int $id, string $ledger = '4000', string $customer = 'نور المنصوري' ): array {
		return array( 'order' => array( 'reference' => 'WC-' . ( 5000 + $id ), 'customer' => $customer, 'currency' => 'KWD' ), 'lines' => array( array( 'sku' => 'CS-MUG-01', 'quantity' => 3, 'unit_price' => 12345, 'ledger' => $ledger ) ) );
	}

	public function test_send_adds_the_order_to_the_open_batch_and_a_retry_does_not_add_it_twice(): void {
		$first = $this->connector->send( $this->connection(), $this->item( 1 ), $this->payload( 1 ) );
		$this->assertTrue( $first->isSuccess() );
		$this->assertFalse( $first->alreadyExisted );
		$this->assertSame( 'export-1', $first->ref->id );

		$again = $this->connector->send( $this->connection(), $this->item( 1 ), $this->payload( 1 ) );
		$this->assertTrue( $again->alreadyExisted );
		$this->assertNotNull( $this->connector->findExisting( $this->connection(), $this->item( 1 ) ) );
		$this->assertNull( $this->connector->findExisting( $this->connection(), $this->item( 2 ) ) );

		$summary = $this->kit->exports->batchSummary( 1 );
		$this->assertSame( 1, $summary['orders'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $summary['since'] );
		$this->assertNull( $this->kit->exports->batchSummary( 2 ), 'Another connection has its own batch.' );
	}

	public function test_only_orders_go_into_a_file(): void {
		$item   = new Item( 9, 1, EntityType::Refund, '1', 'created', 'r', 'k', ItemState::Syncing );
		$result = $this->connector->send( $this->connection(), $item, array() );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
	}

	public function test_creating_the_file_writes_it_into_the_protected_folder_and_marks_the_items_with_the_file_name(): void {
		foreach ( array( 1, 2 ) as $n ) {
			$this->connector->send( $this->connection(), $this->item( $n ), $this->payload( $n ) );
		}
		$file = $this->service->create( 1, 'Layla Haddad' );

		$this->assertSame( 2, $file->orders );
		$this->assertSame( 'Layla Haddad', $file->createdBy );
		$this->assertMatchesRegularExpression( '/^1-\d{8}-\d{6}-[a-f0-9]{8}\.csv$/', $file->filename );
		$this->assertSame( 'Acme Ledger CSV import', $file->format->label );
		$this->assertSame( array( $file->id, $file->filename ), $this->queue->exported[1] );
		$this->assertSame( array( $file->id, $file->filename ), $this->queue->exported[2] );
		$this->assertNull( $this->kit->exports->batchSummary( 1 ), 'The batch is closed.' );

		$download = $this->service->download( $file->id );
		$contents = (string) file_get_contents( $download['path'] );
		$this->assertStringStartsWith( "\xEF\xBB\xBFreference,date,customer,sku,quantity,unit_price,ledger\r\n", $contents );
		$this->assertStringContainsString( 'WC-5001,', $contents );
		$this->assertStringContainsString( '"نور المنصوري",CS-MUG-01,3,12.345,4000', $contents, 'Arabic text and a three-decimal currency, exactly.' );
		$this->assertSame( 'text/csv; charset=utf-8', $download['mime'] );
		$this->assertSame( strlen( $contents ), $file->sizeBytes );
		$this->assertSame( $file->filename, $download['filename'] );
	}

	public function test_the_folder_is_hidden_and_protected_at_every_level(): void {
		$this->connector->send( $this->connection(), $this->item( 1 ), $this->payload( 1 ) );
		$file = $this->service->create( 1 );
		$root = (string) $this->kit->exports->root( false );

		$this->assertMatchesRegularExpression( '#/estasoft-sync/exports/[a-f0-9]{32}$#', $root );
		foreach ( array( dirname( $root, 2 ), dirname( $root ), $root, $root . '/files', $root . '/meta', $root . '/batches' ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$this->assertFileExists( $dir . '/index.php', $dir );
			$this->assertStringContainsString( 'Silence is golden', (string) file_get_contents( $dir . '/index.php' ) );
			$this->assertStringContainsString( 'Require all denied', (string) file_get_contents( $dir . '/.htaccess' ) );
			$this->assertStringContainsString( 'accessType="Deny"', (string) file_get_contents( $dir . '/web.config' ) );
		}
		$this->assertSame( $root, $this->kit->exports->root( true ), 'The same random folder is used every time.' );
		unset( $file );
	}

	public function test_an_order_without_a_ledger_code_is_left_out_listed_and_its_item_needs_a_person(): void {
		$this->connector->send( $this->connection(), $this->item( 1 ), $this->payload( 1 ) );
		$this->connector->send( $this->connection(), $this->item( 2 ), $this->payload( 2, '' ) );
		$this->connector->send( $this->connection(), $this->item( 3 ), $this->payload( 3, '' ) );

		$file = $this->service->create( 1 );

		$this->assertSame( 1, $file->orders );
		$this->assertSame( array( array( 'order' => '5002', 'reason' => 'no ledger code for CS-MUG-01' ), array( 'order' => '5003', 'reason' => 'no ledger code for CS-MUG-01' ) ), $file->leftOut );
		$this->assertArrayHasKey( 1, $this->queue->exported );
		$this->assertSame( array( 2, 3 ), array_keys( $this->queue->leftOut ) );
		$this->assertStringContainsString( 'left out of the export: no ledger code for CS-MUG-01', $this->queue->leftOut[2][0] );
		$this->assertStringNotContainsString( 'WC-5002', (string) file_get_contents( $this->service->download( $file->id )['path'] ) );
		$this->assertSame( $file->leftOut, $this->service->find( $file->id )->leftOut, 'The list is kept with the file.' );
	}

	public function test_when_every_order_is_left_out_there_is_no_file_and_the_error_is_exp_002(): void {
		$this->connector->send( $this->connection(), $this->item( 1 ), $this->payload( 1, '' ) );
		try {
			$this->service->create( 1 );
			$this->fail( 'Expected SYNC-EXP-002.' );
		} catch ( ExportException $exception ) {
			$this->assertSame( ErrorCode::Exp002, $exception->error()->code );
			$this->assertSame( 1, $exception->error()->context['n'] );
		}
		$this->assertSame( array(), $this->kit->exports->list() );
		$this->assertArrayHasKey( 1, $this->queue->leftOut );
	}

	public function test_nothing_to_export_and_a_connection_that_makes_no_files_are_refused(): void {
		foreach ( array( 1, 99 ) as $id ) {
			try {
				$this->service->create( $id );
				$this->fail( 'Expected a refusal.' );
			} catch ( ExportException $exception ) {
				$this->assertSame( ErrorCode::Val002, $exception->error()->code );
			}
		}
	}

	public function test_two_clicks_do_not_make_two_files(): void {
		$this->connector->send( $this->connection(), $this->item( 1 ), $this->payload( 1 ) );
		$lock = new ApiMemoryLock();
		$lock->busy = 1;
		$service = new ExportService( $this->kit->exports, fn() => $this->connector, $this->queue, $lock );
		try {
			$service->create( 1 );
			$this->fail( 'Expected a refusal while another export is being made.' );
		} catch ( ExportException $exception ) {
			$this->assertStringContainsString( 'already being made', (string) $exception->error()->context['reason'] );
		}
		$this->assertSame( array(), $this->kit->exports->list() );
		$this->assertSame( 'text/csv; charset=utf-8', $service->create( 1 )->format->mime );
		$this->assertCount( 1, $this->kit->exports->list() );
	}

	public function test_files_are_listed_newest_first_per_connection_and_deleted_on_request(): void {
		$ids = array();
		foreach ( array( 1, 2, 3 ) as $n ) {
			$this->connector->send( $this->connection(), $this->item( $n ), $this->payload( $n ) );
			$ids[] = $this->service->create( 1 )->id;
			$this->kit->clock->advance( 60 );
		}
		$overview = $this->service->overview( 1 );
		$this->assertSame( array_reverse( $ids ), array_map( static fn( $f ) => $f->id, $overview['files'] ) );
		$this->assertNull( $overview['open_batch'] );
		$this->assertSame( array(), $this->service->overview( 2 )['files'] );

		$this->assertTrue( $this->service->delete( $ids[0] ) );
		$this->assertFalse( $this->service->delete( $ids[0] ) );
		$this->assertNull( $this->service->download( $ids[0] ) );
		$this->assertCount( 2, $this->service->overview( 1 )['files'] );
		$this->assertNull( $this->service->find( '../../etc/passwd' ) );
		$this->assertNull( $this->service->download( 'zz' ) );
	}

	public function test_files_older_than_thirty_days_are_purged_by_the_daily_task(): void {
		$this->connector->send( $this->connection(), $this->item( 1 ), $this->payload( 1 ) );
		$old = $this->service->create( 1 );
		$this->kit->clock->advance( 29 * 86400 );
		$this->connector->send( $this->connection(), $this->item( 2 ), $this->payload( 2 ) );
		$new = $this->service->create( 1 );
		$this->assertSame( 0, $this->service->purgeExpired() );

		$this->kit->clock->advance( 2 * 86400 );
		$this->assertSame( 1, $this->service->purgeExpired() );
		$this->assertNull( $this->service->find( $old->id ) );
		$this->assertNotNull( $this->service->find( $new->id ) );
		$this->assertSame( ExportStore::RETENTION_DAYS * 86400, strtotime( $new->expiresAt ) - strtotime( $new->createdAt ) );
	}

	public function test_a_folder_that_cannot_be_written_is_exp_001(): void {
		$blocked = $this->kit->uploads . '/blocked';
		file_put_contents( $blocked, 'a file where the uploads folder should be' );
		$store   = new ExportStore( static fn(): string => $blocked, $this->kit->clock );
		$this->assertNull( $store->root( true ) );
		try {
			$store->appendToBatch( 1, 1, '5001', array( 'a' => 1 ) );
			$this->fail( 'Expected SYNC-EXP-001.' );
		} catch ( ExportException $exception ) {
			$this->assertSame( ErrorCode::Exp001, $exception->error()->code );
		}
		$none = new ExportStore( static fn(): string => '', $this->kit->clock );
		$this->assertSame( array(), $none->list() );
		$this->assertNull( $none->find( 'abcdefabcdefabcd' ) );
	}

	public function test_erase_all_removes_the_whole_folder(): void {
		$this->connector->send( $this->connection(), $this->item( 1 ), $this->payload( 1 ) );
		$this->service->create( 1 );
		$this->connector->send( $this->connection(), $this->item( 2 ), $this->payload( 2 ) );
		$this->kit->exports->eraseAll();
		$this->assertDirectoryDoesNotExist( $this->kit->uploads . '/estasoft-sync/exports' );
		$this->assertSame( array(), $this->kit->exports->list() );
		$this->assertNull( $this->kit->exports->batchSummary( 1 ) );
	}

	public function test_the_connector_reads_as_an_export_connector(): void {
		$wrapped = GuardedConnector::wrap( $this->connector );
		$this->assertSame( array( 'orders' => true ), array_filter( $wrapped->capabilities() ) );
		$this->assertSame( array(), $wrapped->credentialFields() );
		$this->assertTrue( $wrapped->test( $this->connection() )->ok );
		$this->assertSame( 'Acme Ledger CSV import', $wrapped->test( $this->connection() )->version );
		$this->assertTrue( $wrapped->ping( $this->connection() ) );
		$this->assertSame( 0, $this->kit->http->count(), 'A file export never touches the network.' );
	}

	/* ------------------------------------------------------------ big files, encodings, a second file, refunds */

	private function streaming(): array {
		$connector = new ReferenceStreamingExportConnector( $this->kit->deps );
		$service   = new ExportService(
			$this->kit->exports,
			fn() => $connector,
			$this->queue,
			null,
			null,
			fn( int $id ) => new Connection( $id, 'reference-stream', ConnectorType::Accounting, 'Voucher', ConnectionStatus::Active, array( 'company' => 'شركة الأمل' ) )
		);
		return array( $connector, $service );
	}

	public function test_a_file_written_in_pieces_keeps_its_own_encoding_and_gets_no_extra_byte_order_mark(): void {
		list( $connector, $service ) = $this->streaming();
		$connection                  = new Connection( 1, 'reference-stream', ConnectorType::Accounting, 'Voucher', ConnectionStatus::Active );
		for ( $n = 1; $n <= 40; ++$n ) {
			$this->assertTrue( $connector->send( $connection, $this->item( $n ), array( 'customer' => 'نور ' . $n ) )->isSuccess() );
		}
		$file = $service->create( 1 );

		$this->assertSame( 40, $file->orders );
		$this->assertSame( 40, $connector->seen['streamed'] );
		$this->assertSame( 'شركة الأمل', $connector->seen['company'], 'finish() reads the connection.' );
		$this->assertSame( 1, $connector->seen['append_batch_connection'] );
		$download = $service->download( $file->id );
		$bytes    = (string) file_get_contents( $download['path'] );
		$this->assertSame( "\xFF\xFE", substr( $bytes, 0, 2 ), 'Only the connector\'s own mark.' );
		$this->assertStringStartsWith( 'شركة الأمل', mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16LE' ) );
		$this->assertStringContainsString( 'order 5040 نور 40', mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16LE' ) );
		$this->assertSame( strlen( $bytes ), $file->sizeBytes );
		$this->assertSame( 'text/xml; charset=utf-16le', $download['mime'] );
		$this->assertCount( 40, $this->queue->exported );
		$this->assertSame( array(), glob( (string) $this->kit->exports->root( false ) . '/tmp/*.part' ), 'The work file was moved into place.' );
	}

	public function test_a_second_file_is_stored_listed_and_downloaded_by_part_and_deleted_with_the_first(): void {
		list( $connector, $service ) = $this->streaming();
		$connection                  = new Connection( 1, 'reference-stream', ConnectorType::Accounting, 'Voucher', ConnectionStatus::Active );
		$connector->send( $connection, $this->item( 1 ), array( 'customer' => 'x' ) );
		$file = $service->create( 1 );

		$info = $file->info();
		$this->assertCount( 1, $info['attachments'] );
		$this->assertSame( 'Masters', $info['attachments'][0]['name'] );
		$this->assertMatchesRegularExpression( '/^1-\d{8}-\d{6}-[a-f0-9]{8}-masters\.csv$/', $info['attachments'][0]['filename'] );
		$part = $service->download( $file->id, 1 );
		$this->assertSame( "name,code\r\nنور,C1\r\n", file_get_contents( $part['path'] ) );
		$this->assertSame( 'text/csv; charset=utf-8', $part['mime'] );
		$this->assertNull( $service->download( $file->id, 2 ) );
		$this->assertSame( $info['attachments'], $service->find( $file->id )->info()['attachments'], 'Kept with the file.' );

		$this->assertTrue( $service->delete( $file->id ) );
		$this->assertNull( $service->download( $file->id, 1 ), 'The second file goes with the first.' );
		$this->assertSame( array(), array_merge( (array) glob( (string) $this->kit->exports->root( false ) . '/files/*.csv' ), (array) glob( (string) $this->kit->exports->root( false ) . '/files/*.xml' ) ) );
	}

	public function test_refunds_go_into_the_same_batch_when_the_connector_declares_them(): void {
		list( $connector )  = $this->streaming();
		$connection         = new Connection( 1, 'reference-stream', ConnectorType::Accounting, 'Voucher', ConnectionStatus::Active );
		$refund             = new Item( 77, 1, EntityType::Refund, '9001', 'created', 'refund:9001', 'k77', ItemState::Syncing );
		$this->assertTrue( $connector->send( $connection, $refund, array( 'customer' => 'نور' ) )->isSuccess() );
		$this->assertTrue( $connector->send( $connection, $refund, array() )->alreadyExisted );

		// A connector that did not declare refunds still refuses them.
		$orders = new ReferenceExportConnector( $this->kit->deps );
		$this->assertFalse( $orders->send( new Connection( 1, 'reference-export', ConnectorType::Accounting, 'x', ConnectionStatus::Active ), $refund, array() )->isSuccess() );
	}

	public function test_sending_stays_one_file_check_however_many_orders_wait(): void {
		for ( $n = 1; $n <= 300; ++$n ) {
			$this->connector->send( $this->connection(), $this->item( $n ), $this->payload( $n ) );
		}
		$this->assertTrue( $this->kit->exports->hasItem( 1, 300 ) );
		$this->assertFalse( $this->kit->exports->hasItem( 1, 301 ) );
		$this->assertFalse( $this->kit->exports->hasItem( 2, 300 ), 'Another connection has its own batch.' );
		$this->assertSame( 300, $this->kit->exports->batchSummary( 1 )['orders'] );
		$batch = $this->kit->exports->openBatch( 1 );
		$this->assertCount( 300, $batch->includedIds() );
		$this->assertNull( $batch->entry( 1 ), 'Opening a batch reads no rows.' );
		$ids = array();
		foreach ( $batch->iterate() as $entry ) {
			$ids[] = $entry['item_id'];
		}
		$this->assertSame( range( 1, 300 ), $ids, 'The rows stream in item order.' );
		$this->assertNull( $batch->entry( 1 ), 'And are still not held in memory.' );
		$this->assertCount( 300, $batch->included() );
		$this->assertNotNull( $batch->entry( 1 ) );
	}

	public function test_iterating_thousands_of_orders_holds_one_row_at_a_time(): void {
		for ( $n = 1; $n <= 3000; ++$n ) {
			$this->connector->send( $this->connection(), $this->item( $n ), $this->payload( $n ) );
		}
		$batch = $this->kit->exports->openBatch( 1 );
		$this->assertSame( 3000, $batch->count() );
		gc_collect_cycles();
		$base = memory_get_usage();
		$peak = 0;
		$seen = 0;
		foreach ( $batch->iterate() as $entry ) {
			++$seen;
			$peak = max( $peak, memory_get_usage() - $base );
		}
		$this->assertSame( 3000, $seen );
		$this->assertLessThan( 1024 * 1024, $peak, 'Iterating held ' . $peak . ' bytes. A connector that calls included() or rows() instead loads the whole batch.' );
	}

	public function test_an_order_added_while_the_file_is_being_made_stays_for_the_next_file(): void {
		$queue   = new class() implements ExportQueue {
			public ?\Closure $whileMaking = null;
			public array $exported = array();
			public function markExported( int $itemId, string $fileId, string $filename ): void {
				$this->exported[] = $itemId;
				if ( null !== $this->whileMaking ) {
					( $this->whileMaking )();
					$this->whileMaking = null;
				}
			}
			public function markLeftOut( int $itemId, string $message, string $cause ): void {
			}
		};
		$service = new ExportService( $this->kit->exports, fn( int $id ) => 1 === $id ? $this->connector : null, $queue, new ApiMemoryLock() );
		$this->connector->send( $this->connection(), $this->item( 1 ), $this->payload( 1 ) );
		$this->connector->send( $this->connection(), $this->item( 2 ), $this->payload( 2 ) );
		// Between the snapshot and the close, a worker adds order 3 (and order 4 is left out because its ledger code is missing).
		$queue->whileMaking = function (): void {
			$this->connector->send( $this->connection(), $this->item( 3 ), $this->payload( 3 ) );
			$this->connector->send( $this->connection(), $this->item( 4 ), $this->payload( 4, '' ) );
		};

		$first = $service->create( 1 );

		$this->assertSame( 2, $first->orders );
		$this->assertSame( array( 1, 2 ), $queue->exported );
		$waiting = $this->kit->exports->openBatch( 1 );
		$this->assertSame( array( 3 ), $waiting->includedIds(), 'The order that arrived during the build is still waiting.' );
		$this->assertSame( array( 4 ), $waiting->leftOutItemIds(), 'And so is the one left out then.' );
		$this->assertFalse( $this->kit->exports->hasItem( 1, 1 ) );
		$this->assertFalse( $this->kit->exports->hasItem( 1, 2 ) );

		$second = $service->create( 1 );
		$this->assertSame( 1, $second->orders );
		$this->assertSame( array( 1, 2, 3 ), $queue->exported );
		$this->assertNull( $this->kit->exports->batchSummary( 1 ) );
	}

	public function test_an_order_left_out_when_it_was_added_is_kept_out_of_iterate_and_marked_by_the_file_name(): void {
		$this->connector->send( $this->connection(), $this->item( 1 ), $this->payload( 1 ) );
		$this->connector->send( $this->connection(), $this->item( 2 ), $this->payload( 2, '' ) );
		$batch = $this->kit->exports->openBatch( 1 );
		$this->assertSame( array( 1 ), $batch->includedIds() );
		$this->assertSame( array( 2 ), $batch->leftOutItemIds() );
		$this->assertSame( 'no ledger code for CS-MUG-01', $batch->reasonFor( 2 ) );
		$this->assertSame( array( 1 ), array_column( iterator_to_array( $batch->iterate(), false ), 'item_id' ) );
		$this->assertFileExists( (string) $this->kit->exports->root( false ) . '/batches/1/2.left.json' );
		$this->assertTrue( $this->kit->exports->hasItem( 1, 2 ) );
	}
}
