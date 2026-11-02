<?php
/**
 * A small file-export connector built on the shared base: the model for a system that imports a CSV.
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
use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Core\Support\Money;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\Item;

/**
 * One row per order line: reference, date, customer, SKU, quantity, unit price (decimal string). An order whose lines carry no ledger code is left out
 * of the file, and the Exports screen says so.
 */
final class ReferenceExportConnector extends AbstractExportConnector {

	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'reference-export',
			label: 'Acme Ledger CSV',
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders ),
			entities: array( EntityType::Order ),
			auth: AuthStyle::ExportFile,
		);
	}

	public function exportFormat(): ExportFormat {
		return ExportFormat::csv( 'Acme Ledger CSV import', true );
	}

	protected function fieldCatalog( string $mappingType ): array {
		return 'orders' === $mappingType
			? FieldCatalogBuilder::make()->group( 'Order' )->text( 'order.reference', 'Reference', true )->text( 'order.customer', 'Customer', true )->group( 'Lines' )->sku( 'lines[].sku', 'SKU', true )->number( 'lines[].quantity', 'Quantity', true )->money( 'lines[].unit_price', 'Unit price', true )->text( 'lines[].ledger', 'Ledger code' )->build()
			: array();
	}

	protected function mappings( string $mappingType ): array {
		return array();
	}

	public function appendOrder( ExportBatch $batch, Item $item, array $payload ): void {
		$lines = (array) ( $payload['lines'] ?? array() );
		foreach ( $lines as $line ) {
			if ( empty( $line['ledger'] ) ) {
				$batch->add( $item->id, $item->entityId, array( 'payload' => $payload ) );
				$batch->leaveOut( $item->id, $item->entityId, 'no ledger code for ' . (string) $line['sku'] );
				return;
			}
		}
		$batch->add( $item->id, $item->entityId, array( 'payload' => $payload ) );
	}

	public function finish( ExportBatch $batch ): ExportFile {
		$rows = array( 'reference,date,customer,sku,quantity,unit_price,ledger' );
		foreach ( $batch->included() as $entry ) {
			$payload  = $entry['row']['payload'];
			$currency = (string) $payload['order']['currency'];
			foreach ( $payload['lines'] as $line ) {
				$rows[] = implode( ',', array( $payload['order']['reference'], gmdate( 'Y-m-d' ), '"' . str_replace( '"', '""', (string) $payload['order']['customer'] ) . '"', $line['sku'], $line['quantity'], Money::toDecimalString( (int) $line['unit_price'], $currency ), $line['ledger'] ) );
			}
		}
		return new ExportFile( contents: implode( "\r\n", $rows ) . "\r\n", format: $this->exportFormat() );
	}
}
