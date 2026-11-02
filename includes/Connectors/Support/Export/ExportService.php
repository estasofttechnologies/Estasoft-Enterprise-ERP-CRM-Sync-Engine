<?php
/**
 * Makes export files from the open batches.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Export;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the arguments of the exceptions here are error objects and fixed texts, not output.

use Estasoft\SyncEngine\Connectors\Support\OAuth\RefreshLock;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Domain\ErrorCode;

/**
 * One open batch per connection. send() appends orders to it; `create()` turns it into a file, marks the queue items with the file name as
 * their reference, lists the orders left out (SYNC-EXP-002) and closes the orders it dealt with (an order added while the file is being built stays
 * for the next file). A lock keeps two clicks from making the same file twice.
 */
final class ExportService {

	/**
	 * Seconds the creation lock lives if its owner dies.
	 */
	private const LOCK_TTL = 60;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ExportStore      $store        Files and batches.
	 * @param \Closure         $connectorFor Receives a connection id, returns its AbstractExportConnector or null.
	 * @param ExportQueue      $queue        Queue updates.
	 * @param RefreshLock|null $lock         Creation lock; none means no lock.
	 * @param Logger|null      $logger       Log.
	 * @param \Closure|null    $connectionFor Receives a connection id, returns the Connection (config and settings) or null; handed to finish() as `$batch->connection()`.
	 */
	public function __construct(
		private readonly ExportStore $store,
		private readonly \Closure $connectorFor,
		private readonly ExportQueue $queue,
		private readonly ?RefreshLock $lock = null,
		private readonly ?Logger $logger = null,
		private readonly ?\Closure $connectionFor = null,
	) {
	}

	/**
	 * The files, newest first, and the orders waiting for the next one.
	 *
	 * @param int|null $connectionId Connection, or null for all.
	 * @return array{files:array<int,ExportFile>,open_batch:array{connection_id:int,orders:int,since:string}|null}
	 */
	public function overview( ?int $connectionId ): array {
		return array(
			'files'      => $this->store->list( $connectionId ),
			'open_batch' => null === $connectionId ? null : $this->store->batchSummary( $connectionId ),
		);
	}

	/**
	 * Make the file of a connection's open batch.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $createdBy    Display name of the person, or empty for a task.
	 *
	 * @throws ExportException SYNC-EXP-001 when the folder cannot be written; SYNC-EXP-002 when every order was left out; SYNC-VAL-002 when nothing waits or the connection makes no files.
	 */
	public function create( int $connectionId, string $createdBy = '' ): ExportFile {
		$connector = ( $this->connectorFor )( $connectionId );
		if ( ! $connector instanceof AbstractExportConnector ) {
			throw ExportException::refused( __( 'This connection does not make export files.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$owner = null === $this->lock ? '' : $this->lock->acquire( 'export:' . $connectionId, self::LOCK_TTL );
		if ( null === $owner ) {
			throw ExportException::refused( __( 'An export is already being made. Wait a moment and look at the list.', 'estasoft-erp-crm-sync-engine' ) );
		}
		try {
			return $this->build( $connector, $connectionId, $createdBy );
		} finally {
			if ( null !== $this->lock ) {
				$this->lock->release( 'export:' . $connectionId, $owner );
			}
		}
	}

	/**
	 * Details of one file.
	 *
	 * @param string $id File id.
	 */
	public function find( string $id ): ?ExportFile {
		return $this->store->find( $id );
	}

	/**
	 * Delete a file.
	 *
	 * @param string $id File id.
	 * @return bool False when there was no such file.
	 */
	public function delete( string $id ): bool {
		return $this->store->delete( $id );
	}

	/**
	 * The path and type of a file (or of one of its attachments) for the download route.
	 *
	 * @param string $id   File id.
	 * @param int    $part 0 for the file, 1 and up for its attachments.
	 * @return array{path:string,mime:string,filename:string,size:int}|null
	 */
	public function download( string $id, int $part = 0 ): ?array {
		return $this->store->download( $id, $part );
	}

	/**
	 * Delete files older than the retention time.
	 *
	 * @return int Files deleted.
	 */
	public function purgeExpired(): int {
		return $this->store->purgeExpired();
	}

	/**
	 * Build the file.
	 *
	 * @param AbstractExportConnector $connector    Connector.
	 * @param int                     $connectionId Connection.
	 * @param string                  $createdBy    Display name.
	 *
	 * @throws ExportException On the failures create() lists.
	 */
	private function build( AbstractExportConnector $connector, int $connectionId, string $createdBy ): ExportFile {
		$batch = $this->store->openBatch( $connectionId );
		if ( array() === $batch->includedIds() && array() === $batch->leftOut() ) {
			throw ExportException::refused( __( 'No orders are waiting for an export file.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$batch->forConnection( null === $this->connectionFor ? null : ( $this->connectionFor )( $connectionId ) );
		$file     = $connector->finish( $batch );
		$included = $batch->includedIds();
		$left     = $batch->leftOut();
		$leftList = array_map(
			static fn( array $entry ): array => array(
				'order'  => $entry['order'],
				'reason' => $entry['reason'],
			),
			$left
		);
		// Only the orders this file dealt with leave the batch. An order a worker added while the file was being built is not in the
		// snapshot `$batch` holds, so it stays and goes into the next file.
		$handled = array_merge( $included, array_map( static fn( array $entry ): int => $entry['item_id'], $left ) );
		if ( array() === $included ) {
			$this->failLeftOut( $left );
			$this->store->closeBatch( $connectionId, $handled );
			throw ExportException::leftOut( count( $left ), $this->reasons( $left ) );
		}
		$stored = $this->store->write( $file, $connectionId, $createdBy, count( $included ), $leftList );
		foreach ( $included as $itemId ) {
			$this->queue->markExported( $itemId, $stored->id, $stored->filename );
		}
		$this->failLeftOut( $left );
		$this->store->closeBatch( $connectionId, $handled );
		if ( array() !== $left ) {
			$this->logger?->warning(
				str_replace( array( '{n}', '{reason}' ), array( (string) count( $left ), $this->reasons( $left ) ), ErrorCode::Exp002->template() ),
				array(
					'connection_id' => $connectionId,
					'code'          => ErrorCode::Exp002,
				)
			);
		}
		return $stored;
	}

	/**
	 * Fail the queue items that were left out.
	 *
	 * @param array<int,array{item_id:int,order:string,reason:string}> $left Orders left out.
	 */
	private function failLeftOut( array $left ): void {
		foreach ( $left as $entry ) {
			$this->queue->markLeftOut(
				$entry['item_id'],
				str_replace( array( '{n}', '{reason}' ), array( '1', $entry['reason'] ), ErrorCode::Exp002->template() ),
				$entry['reason']
			);
		}
	}

	/**
	 * The distinct reasons as one sentence part.
	 *
	 * @param array<int,array{item_id:int,order:string,reason:string}> $left Orders left out.
	 */
	private function reasons( array $left ): string {
		$reasons = array_values( array_unique( array_map( static fn( array $entry ): string => $entry['reason'], $left ) ) );
		return implode( '; ', array_slice( $reasons, 0, 3 ) );
	}
}
