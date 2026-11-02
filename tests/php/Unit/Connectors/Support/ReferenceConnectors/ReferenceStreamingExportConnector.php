<?php
/**
 * An export connector that writes a big file in pieces, in its own encoding, with a second file.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Support\ReferenceConnectors;

use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\Export\AbstractExportConnector;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportBatch;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportFile;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportFormat;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;

/**
 * One line per order or refund, UTF-16LE with its own byte order mark (so the store adds none), the company name from the connection in the first line,
 * and a "masters" list as a second file.
 */
final class ReferenceStreamingExportConnector extends AbstractExportConnector {

	/**
	 * What finish() saw of the batch, for the tests.
	 *
	 * @var array<string,mixed>
	 */
	public array $seen = array();

	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'reference-stream',
			label: 'Acme Voucher File',
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Refunds ),
			entities: array( EntityType::Order, EntityType::Refund ),
			auth: AuthStyle::ExportFile,
		);
	}

	public function exportFormat(): ExportFormat {
		return new ExportFormat( 'xml', 'text/xml; charset=utf-16le', 'Acme voucher file', false, 'utf-16le', true );
	}

	protected function fieldCatalog( string $mappingType ): array {
		return array();
	}

	protected function mappings( string $mappingType ): array {
		return array();
	}

	public function appendOrder( ExportBatch $batch, Item $item, array $payload ): void {
		$this->seen['append_batch_connection'] = $batch->connection()?->id;
		$batch->add( $item->id, $item->entityId, array( 'line' => $item->entityType->value . ' ' . $item->entityId . ' ' . (string) ( $payload['customer'] ?? '' ) ) );
	}

	public function finish( ExportBatch $batch ): ExportFile {
		$this->seen['company'] = $batch->connection()?->config( 'company' );
		$work                  = $this->deps->exports->workFile();
		$handle                = fopen( (string) $work, 'wb' );
		fwrite( $handle, "\xFF\xFE" ); // The byte order mark is the connector's own.
		fwrite( $handle, mb_convert_encoding( (string) $batch->connection()?->config( 'company' ) . "\r\n", 'UTF-16LE', 'UTF-8' ) );
		$this->seen['peak_before'] = memory_get_usage();
		$count                     = 0;
		foreach ( $batch->iterate() as $entry ) {
			fwrite( $handle, mb_convert_encoding( $entry['row']['line'] . "\r\n", 'UTF-16LE', 'UTF-8' ) );
			++$count;
		}
		fclose( $handle );
		$this->seen['streamed'] = $count;
		return ExportFile::fromPath(
			(string) $work,
			$this->exportFormat(),
			array(
				array(
					'name'     => 'Masters',
					'filename' => 'masters.csv',
					'mime'     => 'text/csv; charset=utf-8',
					'contents' => "name,code\r\nنور,C1\r\n",
				),
			)
		);
	}
}
