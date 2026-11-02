<?php
/**
 * Base class of the connectors that hand the merchant a file to import.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Support\Export;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\AbstractApiConnector;
use Estasoft\SyncEngine\Connectors\Support\Auth\AuthStrategy;
use Estasoft\SyncEngine\Connectors\Support\Auth\NoAuth;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * An export connector implements meta() (with AuthStyle::ExportFile), fieldCatalog(), mappings(), exportFormat(), appendOrder() and finish().
 * send() adds the order (or the refund, when the connector declares the Refund entity) to the open batch of the connection and reports it as created, with the batch as the reference. The person makes the
 * file on the Exports screen (or with `Create export now`); that is when the queue items get the file name as their reference. Nothing here
 * contacts a server.
 */
abstract class AbstractExportConnector extends AbstractApiConnector {

	/**
	 * The format of the files this connector writes.
	 */
	abstract public function exportFormat(): ExportFormat;

	/**
	 * Add one order to the batch: a row in the connector's own shape (`$batch->add( $item->id, $item->entityId, $row )`). An order that cannot be
	 * exported (a ledger code is missing) goes to `$batch->leaveOut()` with the reason instead.
	 *
	 * @param ExportBatch         $batch   A batch that holds only this order; `$batch->connection()` is the connection.
	 * @param Item                $item    Queue item.
	 * @param array<string,mixed> $payload Mapped payload.
	 */
	abstract public function appendOrder( ExportBatch $batch, Item $item, array $payload ): void;

	/**
	 * Build the file from the batch. Contents are UTF-8 text unless the format says `rawBytes`. Read the rows with `$batch->iterate()` (one at a time; a
	 * batch of ten thousand orders never sits in memory) or `$batch->included()` (all at once), call `$batch->leaveOut()` for any that turn out not to be
	 * exportable (the service lists those with SYNC-EXP-002), and read the company, ledger names and account numbers from `$batch->connection()->config()`.
	 * For a big file write to a stream and return `ExportFile::fromPath( $this->deps->exports->workFile(), $format )`.
	 *
	 * @param ExportBatch $batch The open batch.
	 */
	abstract public function finish( ExportBatch $batch ): ExportFile;

	/**
	 * No server: there is no base address.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function baseUrl( Connection $connection ): string {
		unset( $connection );
		return '';
	}

	/**
	 * No credentials.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function authStrategy( Connection $connection ): AuthStrategy {
		unset( $connection );
		return new NoAuth();
	}

	/**
	 * Test connection: nothing to reach. The result says which file format the system will import.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		return new TestResult(
			true,
			$this->exportFormat()->label,
			$connection->label,
			array(),
			array(),
			null,
			array( 'export_format' => $this->exportFormat()->safeExtension() )
		);
	}

	/**
	 * The batch is in the connection's protected folder; nothing external to look up. An order already in the open batch is found.
	 *
	 * @param Connection $connection Connection.
	 * @param Item       $item       Item.
	 */
	public function findExisting( Connection $connection, Item $item ): ExternalRef|ConnectorError|null {
		return $this->deps->exports->hasItem( $connection->id, $item->id ) ? $this->batchRef( $connection->id ) : null;
	}

	/**
	 * Add the order to the open batch.
	 *
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 */
	public function send( Connection $connection, Item $item, array $payload ): SendResult {
		if ( ! in_array( $item->entityType, array( EntityType::Order, EntityType::Refund ), true ) || ! in_array( $item->entityType, $this->supportedEntities(), true ) ) {
			return SendResult::failure(
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => $this->label(),
						'field'  => $item->entityType->value,
						'reason' => __( 'Only orders and refunds go into an export file.', 'estasoft-erp-crm-sync-engine' ),
					),
					null,
					null,
					null,
					'entity not exported'
				)
			);
		}
		$store = $this->deps->exports;
		if ( $store->hasItem( $connection->id, $item->id ) ) {
			return SendResult::existing( $this->batchRef( $connection->id ) );
		}
		// The batch handed to appendOrder() holds only this order: sending stays one file write however many orders wait.
		$batch = new ExportBatch( $connection->id );
		$batch->forConnection( $connection );
		$this->appendOrder( $batch, $item, $payload );
		$entry = $batch->entry( $item->id );
		if ( null === $entry ) {
			return SendResult::failure(
				new ConnectorError(
					ErrorCode::Val002,
					array(
						'system' => $this->label(),
						'field'  => 'order',
						'reason' => __( 'The order could not be added to the export.', 'estasoft-erp-crm-sync-engine' ),
					),
					null,
					null,
					null,
					'appendOrder added nothing'
				)
			);
		}
		$store->appendToBatch( $connection->id, $item->id, $entry['order'], $entry['row'], $batch->reasonFor( $item->id ) );
		return SendResult::created( $this->batchRef( $connection->id ) );
	}

	/**
	 * The reference every order of the open batch gets until the file is made.
	 *
	 * @param int $connectionId Connection.
	 */
	private function batchRef( int $connectionId ): ExternalRef {
		return new ExternalRef( 'export-' . $connectionId, __( 'Waiting for export', 'estasoft-erp-crm-sync-engine' ), null, array( 'export_batch' => true ) );
	}
}
