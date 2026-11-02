<?php
/**
 * The base of the ledger-based export connectors (TallyPrime, DATEV, Sage 50).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the reasons of a refusal are plain translated sentences kept in the batch; the admin screen escapes them where it shows them.

use Estasoft\SyncEngine\Connectors\Support\Export\AbstractExportConnector;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportBatch;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportException;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportFile;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportFormat;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\TestResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Orders and refunds go through one path: the mapped payload becomes an ExportDocument, the vendor class turns the document into the
 * text of the file (XML or CSV lines), and that text is stored as the row of the order. Nothing but the small rows is kept: when the
 * file is made the rows are read one at a time (`ExportBatch::iterate()`), converted to the file's character set piece by piece and
 * written to a work file in the protected exports folder, which the engine moves into place. An order the file cannot hold is left
 * out with a short reason (SYNC-EXP-002).
 */
abstract class LedgerExportConnector extends AbstractExportConnector {

	/**
	 * Row version, for files written by an older plugin version that are still waiting in a batch.
	 */
	protected const ROW_VERSION = 1;

	/**
	 * The typed settings of a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	abstract protected function settingsFor( Connection $connection ): ExportSettings;

	/**
	 * Turn a document into its part of the file.
	 *
	 * @param ExportDocument $document Document.
	 * @param ExportSettings $settings Settings of this connector.
	 * @return array{text:string,masters:array<string,array<int,string>>,cut:int,extra:array<string,mixed>} The text in UTF-8, the names the order uses by kind (ledgers, stock items, ...), the number of texts shortened, and facts the file needs.
	 *
	 * @throws Refusal When the file cannot hold the document.
	 */
	abstract protected function buildRow( ExportDocument $document, ExportSettings $settings ): array;

	/**
	 * The bytes of the file, piece by piece, in the file's character set and starting with its byte order mark. One piece is a
	 * header, the text of one order, or a footer, so the caller can write pieces to disk one at a time. Read the rows with
	 * rowsOf(); a row the file cannot take is passed to `$batch->leaveOut()` before the first piece is yielded.
	 *
	 * @param ExportBatch         $batch    The batch.
	 * @param array<string,mixed> $settings The file's settings (see fileSettings()).
	 * @return \Generator<int,string>
	 */
	abstract protected function chunks( ExportBatch $batch, array $settings ): \Generator;

	/**
	 * The format of the file made with these settings: the content type names the character set, the contents are already encoded.
	 *
	 * @param array<string,mixed> $settings The file's settings.
	 */
	abstract protected function fileFormat( array $settings ): ExportFormat;

	/**
	 * The character set of the file.
	 *
	 * @param array<string,mixed> $settings The file's settings.
	 */
	abstract protected function charsetFor( array $settings ): Charset;

	/**
	 * The settings that may change between the day an order was queued and the day the file is made, and that the file then takes
	 * from the connection as it is now (company name, consultant number, character set). Everything else stays as the order saw it,
	 * because the rows were written with it.
	 *
	 * @return array<int,string> Keys of the settings snapshot.
	 */
	abstract protected function fileLevelKeys(): array;

	/**
	 * A short plain-text look at the start of the file for the Exports screen.
	 *
	 * @param array<int,array{item_id:int,order:string,row:array<string,mixed>}> $first    The first rows.
	 * @param array{orders:int,lines:int,lost:int}                               $stats    Orders, rows of the file's text, characters the character set cannot hold.
	 * @param array<string,mixed>                                                $settings The file's settings.
	 */
	abstract protected function preview( array $first, array $stats, array $settings ): string;

	/**
	 * The headings of the masters list by kind.
	 *
	 * @return array<string,string> Kind (as used in buildRow()) to heading.
	 */
	abstract protected function masterHeadings(): array;

	/**
	 * The fields of the connection's settings screen.
	 *
	 * @return array<int,\Estasoft\SyncEngine\Contract\CredentialField>
	 */
	abstract protected function settingFields(): array;

	/**
	 * The file name pattern the Exports screen shows ("{connection}-{date}-{random}.xml").
	 */
	public function filenamePattern(): string {
		return '{connection}-{date}-{random}.' . $this->exportFormat()->safeExtension();
	}

	/**
	 * Target fields for the mapping screens.
	 *
	 * @param string $mappingType orders, refunds (others have no fields).
	 * @return array<int,FieldDef>
	 */
	protected function fieldCatalog( string $mappingType ): array {
		return ( new LedgerFields() )->fields( $mappingType );
	}

	/**
	 * Version 1 mapping rules.
	 *
	 * @param string $mappingType orders, refunds (others have no rules).
	 * @return array<int,array<string,mixed>>
	 */
	protected function mappings( string $mappingType ): array {
		return ( new LedgerMappings() )->rules( $mappingType );
	}

	/**
	 * The connect screen shows the settings, none of them secret.
	 *
	 * @return array<int,\Estasoft\SyncEngine\Contract\CredentialField>
	 */
	protected function credentialDefinition(): array {
		return $this->settingFields();
	}

	/**
	 * There are no tax codes to read from a system that does not answer. The codes are typed into the tax mapping.
	 *
	 * @param Connection $connection Connection.
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 */
	public function listTaxCodes( Connection $connection ): array|ConnectorError {
		unset( $connection );
		return array();
	}

	/**
	 * Test connection: check the settings, then report the file format. Nothing is contacted.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function identify( Connection $connection ): TestResult {
		$problems = $this->settingsFor( $connection )->problems();
		if ( array() === $problems ) {
			return parent::identify( $connection );
		}
		return new TestResult(
			false,
			$this->exportFormat()->label,
			$connection->label,
			array(),
			array(),
			new ConnectorError(
				ErrorCode::Val002,
				array(
					'system' => $this->label(),
					'field'  => 'settings',
					'reason' => implode( ' ', $problems ),
				),
				null,
				null,
				null,
				'export settings incomplete'
			)
		);
	}

	/**
	 * Read the payload of an order or a refund and put the order's part of the file in the batch as its row.
	 *
	 * @param ExportBatch         $batch   A batch that holds only this order; `$batch->connection()` is the connection.
	 * @param Item                $item    Item.
	 * @param array<string,mixed> $payload Mapped payload.
	 *
	 * @throws ExportException When the settings are incomplete.
	 */
	public function appendOrder( ExportBatch $batch, Item $item, array $payload ): void {
		$connection = $batch->connection();
		if ( null === $connection ) {
			throw ExportException::refused( __( 'The order was not sent through a connection.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$settings = $this->settingsFor( $connection );
		$problems = $settings->problems();
		if ( array() !== $problems ) {
			throw ExportException::refused( implode( ' ', $problems ) );
		}
		try {
			$document = ( new DocumentReader( $settings->currency() ) )->read( $payload, EntityType::Refund === $item->entityType );
			$part     = $this->buildRow( $document, $settings );
			$batch->add(
				$item->id,
				$item->entityId,
				array(
					'v'        => self::ROW_VERSION,
					'kind'     => $document->kind,
					'number'   => $document->voucherNumber(),
					'ref'      => $document->reference,
					'date'     => $document->date,
					'settings' => $settings->snapshot(),
					'text'     => $part['text'],
					'masters'  => $part['masters'],
					'cut'      => $part['cut'],
					'extra'    => $part['extra'],
				)
			);
		} catch ( Refusal $refusal ) {
			$batch->add(
				$item->id,
				$item->entityId,
				array(
					'v'        => self::ROW_VERSION,
					'left_out' => true,
				)
			);
			$batch->leaveOut( $item->id, $item->entityId, $refusal->getMessage() );
		}
	}

	/**
	 * Make the file: write it piece by piece to a work file, add the masters list as a second file, and hand both to the engine.
	 *
	 * @param ExportBatch $batch The open batch (`$batch->connection()` is the connection when the engine made it).
	 *
	 * @throws ExportException SYNC-EXP-001 when the work file cannot be written.
	 */
	public function finish( ExportBatch $batch ): ExportFile {
		$settings = $this->fileSettings( $batch );
		if ( null === $settings ) {
			return new ExportFile( '', $this->exportFormat() );
		}
		$work = $this->deps->exports->workFile();
		if ( null === $work ) {
			throw ExportException::notWritable();
		}
		$handle = fopen( $work, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the plugin's own work file in its protected exports folder.
		if ( false === $handle ) {
			throw ExportException::notWritable();
		}
		$failed = false;
		foreach ( $this->chunks( $batch, $settings ) as $chunk ) {
			if ( false === fwrite( $handle, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				$failed = true;
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( $failed ) {
			wp_delete_file( $work );
			throw ExportException::notWritable();
		}
		$summary = $this->summary( $batch, $settings );
		$this->warnAboutText( $batch, $summary['cut'] );
		$masters     = $this->renderMasters( $summary['masters'] );
		$attachments = '' === $masters ? array() : array(
			array(
				'name'     => __( 'Masters list', 'estasoft-erp-crm-sync-engine' ),
				'filename' => 'masters.txt',
				'mime'     => 'text/plain; charset=utf-8',
				'contents' => $masters,
			),
		);
		return ExportFile::fromPath( $work, $this->fileFormat( $settings ), $attachments );
	}

	/**
	 * The file as pieces, for a caller that wants to write them itself. Rows are read one at a time.
	 *
	 * @param ExportBatch $batch The open batch.
	 * @return \Generator<int,string>
	 */
	public function stream( ExportBatch $batch ): \Generator {
		$settings = $this->fileSettings( $batch );
		if ( null === $settings ) {
			return;
		}
		yield from $this->chunks( $batch, $settings );
	}

	/**
	 * The first lines of the file as plain text, for the Exports screen. Nothing is written; rows are read one at a time.
	 *
	 * @param ExportBatch $batch The open batch.
	 */
	public function previewText( ExportBatch $batch ): string {
		$settings = $this->fileSettings( $batch );
		if ( null === $settings ) {
			return '';
		}
		$summary = $this->summary( $batch, $settings, 8 );
		return 0 === $summary['orders'] ? '' : $this->preview(
			$summary['first'],
			array(
				'orders' => $summary['orders'],
				'lines'  => $summary['lines'],
				'lost'   => $summary['lost'],
			),
			$settings
		);
	}

	/**
	 * The names the file uses (ledgers, stock items, accounts), one heading per kind, so the person can check they exist in the
	 * system before importing. It carries no amounts. The engine offers the same text as the second file of an export.
	 *
	 * @param ExportBatch $batch The open batch.
	 */
	public function mastersList( ExportBatch $batch ): string {
		$settings = $this->fileSettings( $batch );
		if ( null === $settings ) {
			return '';
		}
		return $this->renderMasters( $this->summary( $batch, $settings )['masters'] );
	}

	/**
	 * The rows of the orders that stay in the file, one at a time. Rows written by another plugin version and markers of orders
	 * that were left out are skipped.
	 *
	 * @param ExportBatch $batch The batch.
	 * @return \Generator<int,array{item_id:int,order:string,row:array<string,mixed>}>
	 */
	protected function rowsOf( ExportBatch $batch ): \Generator {
		foreach ( $batch->iterate() as $entry ) {
			if ( empty( $entry['row']['left_out'] ) && self::ROW_VERSION === ( $entry['row']['v'] ?? 0 ) ) {
				yield $entry;
			}
		}
	}

	/**
	 * Facts about the file that only the first order shows (for DATEV, the financial year the file is for). The default adds none.
	 *
	 * @param array<string,mixed> $settings The settings snapshot of the first order.
	 * @param array<string,mixed> $row      The first order's row.
	 * @return array<string,mixed>
	 */
	protected function fileFacts( array $settings, array $row ): array {
		unset( $row );
		return $settings;
	}

	/**
	 * Whether a row goes in this file. A format that holds only some of the rows (one financial year in a DATEV file) says so here.
	 *
	 * @param array{item_id:int,order:string,row:array<string,mixed>} $entry    The row.
	 * @param array<string,mixed>                                     $settings The file's settings.
	 */
	protected function belongsToFile( array $entry, array $settings ): bool {
		unset( $entry, $settings );
		return true;
	}

	/**
	 * The settings of the file: what the first order saw, with the file-level keys taken from the connection as it is now when the
	 * engine gave the batch its connection.
	 *
	 * @param ExportBatch $batch The batch.
	 * @return array<string,mixed>|null Null when no order stays in the file.
	 */
	protected function fileSettings( ExportBatch $batch ): ?array {
		foreach ( $this->rowsOf( $batch ) as $entry ) {
			$settings   = $this->fileFacts( (array) ( $entry['row']['settings'] ?? array() ), $entry['row'] );
			$connection = $batch->connection();
			if ( null !== $connection ) {
				$now = $this->settingsFor( $connection )->snapshot();
				foreach ( $this->fileLevelKeys() as $key ) {
					if ( array_key_exists( $key, $now ) ) {
						$settings[ $key ] = $now[ $key ];
					}
				}
			}
			return $settings;
		}
		return null;
	}

	/**
	 * One pass over the rows that belong in the file: how many orders and rows of text, the names they use, the texts that were
	 * shortened, the characters the character set cannot hold, and the first few rows.
	 *
	 * @param ExportBatch         $batch    The batch.
	 * @param array<string,mixed> $settings The file's settings.
	 * @param int                 $keep     How many of the first rows to keep.
	 * @return array{orders:int,lines:int,cut:int,lost:int,masters:array<string,array<string,true>>,first:array<int,array{item_id:int,order:string,row:array<string,mixed>}>}
	 */
	private function summary( ExportBatch $batch, array $settings, int $keep = 0 ): array {
		$charset = $this->charsetFor( $settings );
		$summary = array(
			'orders'  => 0,
			'lines'   => 0,
			'cut'     => 0,
			'lost'    => 0,
			'masters' => array(),
			'first'   => array(),
		);
		foreach ( $this->rowsOf( $batch ) as $entry ) {
			if ( ! $this->belongsToFile( $entry, $settings ) ) {
				continue;
			}
			$text = (string) ( $entry['row']['text'] ?? '' );
			++$summary['orders'];
			$summary['lines'] += 1 + substr_count( $text, "\n" );
			$summary['cut']   += (int) ( $entry['row']['cut'] ?? 0 );
			$summary['lost']  += $charset->lossy( $text );
			foreach ( (array) ( $entry['row']['masters'] ?? array() ) as $kind => $names ) {
				foreach ( (array) $names as $name ) {
					if ( is_string( $name ) && '' !== $name ) {
						$summary['masters'][ (string) $kind ][ $name ] = true;
					}
				}
			}
			if ( count( $summary['first'] ) < $keep ) {
				$summary['first'][] = $entry;
			}
		}
		return $summary;
	}

	/**
	 * The masters list as text.
	 *
	 * @param array<string,array<string,true>> $found Names by kind.
	 */
	private function renderMasters( array $found ): string {
		$lines = array();
		foreach ( $this->masterHeadings() as $kind => $heading ) {
			if ( empty( $found[ $kind ] ) ) {
				continue;
			}
			$names = array_map( 'strval', array_keys( $found[ $kind ] ) );
			sort( $names, SORT_STRING );
			$lines[] = sprintf( /* translators: 1: heading such as Ledgers, 2: number of names */ __( '%1$s (%2$d)', 'estasoft-erp-crm-sync-engine' ), $heading, count( $names ) );
			foreach ( $names as $name ) {
				$lines[] = '  ' . $name;
			}
			$lines[] = '';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Log once per file how many texts were shortened. A log failure never stops an export.
	 *
	 * @param ExportBatch $batch The batch.
	 * @param int         $cut   Number of texts shortened.
	 */
	private function warnAboutText( ExportBatch $batch, int $cut ): void {
		if ( $cut <= 0 ) {
			return;
		}
		try {
			$this->deps->logger->warning(
				sprintf(
					/* translators: 1: number of texts, 2: system name */
					_n( '%1$d text was shortened to fit the %2$s file.', '%1$d texts were shortened to fit the %2$s file.', $cut, 'estasoft-erp-crm-sync-engine' ),
					$cut,
					$this->label()
				),
				array( 'connection_id' => $batch->connectionId )
			);
		} catch ( \Throwable $failure ) {
			unset( $failure ); // Logging is best effort.
		}
	}
}
