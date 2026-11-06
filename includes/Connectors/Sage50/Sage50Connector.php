<?php
/**
 * The Sage 50 export connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage50;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ExportShared\Charset;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportDocument;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportSettings;
use Estasoft\SyncEngine\Connectors\ExportShared\LedgerExportConnector;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportBatch;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportFormat;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;

/**
 * Writes the CSV that the desktop Sage 50 imports: the Audit Trail Transactions layout of Sage 50 Accounts (UK and Ireland, version
 * 12 and later) or the Sales Journal layout of Sage 50 U.S. The setting `edition` chooses. See docs/connectors/sage50-export.md.
 */
final class Sage50Connector extends LedgerExportConnector {

	/**
	 * Identity and capabilities.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'sage50-export',
			label: 'Sage 50',
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Refunds ),
			entities: array( EntityType::Order, EntityType::Refund ),
			auth: AuthStyle::ExportFile,
			regions: array( 'GB', 'IE', 'US' ),
			rateLimit: new RateLimitHints( 1.0, 1 )
		);
	}

	/**
	 * The default format; the content type of a real file follows the chosen character set.
	 */
	public function exportFormat(): ExportFormat {
		return new ExportFormat( 'csv', 'text/csv; charset=windows-1252', __( 'Sage 50 CSV import', 'estasoft-enterprise-erpcrm-sync-engine' ), false, 'windows-1252', true );
	}

	/**
	 * The typed settings.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function settingsFor( Connection $connection ): ExportSettings {
		return Sage50Settings::fromConnection( $connection );
	}

	/**
	 * The rows of a document in the layout of the chosen edition.
	 *
	 * @param ExportDocument $document Document.
	 * @param ExportSettings $settings Settings.
	 * @return array{text:string,masters:array<string,array<int,string>>,cut:int,extra:array<string,mixed>}
	 *
	 * @throws \LogicException When the settings are of another connector (a programming error).
	 */
	protected function buildRow( ExportDocument $document, ExportSettings $settings ): array {
		if ( ! $settings instanceof Sage50Settings ) {
			throw new \LogicException( esc_html__( 'These are not Sage 50 settings.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$part = $settings->isUk() ? ( new Sage50UkWriter() )->render( $document, $settings ) : ( new Sage50UsWriter() )->render( $document, $settings );
		return $part + array( 'extra' => array() );
	}

	/**
	 * The byte order mark and heading row (when chosen) with the first rows, then the rows, one piece at a time.
	 *
	 * @param ExportBatch         $batch    Batch.
	 * @param array<string,mixed> $settings The file's settings.
	 * @return \Generator<int,string>
	 */
	protected function chunks( ExportBatch $batch, array $settings ): \Generator {
		$charset = $this->charsetFor( $settings );
		$head    = $charset->bom();
		if ( ! empty( $settings['header'] ) ) {
			$head .= $charset->encode( Sage50Csv::line( $this->headings( $settings ) ) . "\r\n" );
		}
		foreach ( $this->rowsOf( $batch ) as $entry ) {
			yield $head . $charset->encode( str_replace( "\n", "\r\n", (string) $entry['row']['text'] ) . "\r\n" );
			$head = '';
		}
	}

	/**
	 * The format of the file: the content type names the character set and the contents are already encoded.
	 *
	 * @param array<string,mixed> $settings The file's settings.
	 */
	protected function fileFormat( array $settings ): ExportFormat {
		$charset = $this->charsetFor( $settings );
		return new ExportFormat( 'csv', 'text/csv; charset=' . strtolower( $charset->label() ), __( 'Sage 50 CSV import', 'estasoft-enterprise-erpcrm-sync-engine' ), false, strtolower( $charset->label() ), true );
	}

	/**
	 * The character set of the file.
	 *
	 * @param array<string,mixed> $settings The file's settings.
	 */
	protected function charsetFor( array $settings ): Charset {
		return Charset::fromSetting( (string) ( $settings['encoding'] ?? 'windows-1252' ), Charset::Windows1252 );
	}

	/**
	 * The character set and the heading row are read from the connection when the file is made.
	 *
	 * @return array<int,string>
	 */
	protected function fileLevelKeys(): array {
		return array( 'encoding', 'header' );
	}

	/**
	 * A short look at the file and, for the U.S. edition, the order of the fields for the import template.
	 *
	 * @param array<int,array{item_id:int,order:string,row:array<string,mixed>}>                $first    The first rows.
	 * @param array{orders:int,lines:int,lost:int,lost_orders:int,lost_lines:array<int,string>} $stats Counts, and which orders have characters the file cannot hold.
	 * @param array<string,mixed>                                                               $settings The file's settings.
	 */
	protected function preview( array $first, array $stats, array $settings ): string {
		$charset = $this->charsetFor( $settings );
		$uk      = Sage50Settings::UK === ( $settings['edition'] ?? Sage50Settings::UK );
		$lines   = array(
			sprintf(
				/* translators: 1: Sage 50 edition, 2: number of orders, 3: number of rows, 4: character set */
				_n( 'Sage 50 %1$s CSV: %2$d order, %3$d rows, written in %4$s.', 'Sage 50 %1$s CSV: %2$d orders, %3$d rows, written in %4$s.', $stats['orders'], 'estasoft-enterprise-erpcrm-sync-engine' ),
				$uk ? __( 'Accounts (UK and Ireland)', 'estasoft-enterprise-erpcrm-sync-engine' ) : __( 'U.S. edition', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$stats['orders'],
				$stats['lines'],
				$charset->label()
			),
		);
		if ( ! $uk ) {
			$lines[] = __( 'Import template fields, in this order:', 'estasoft-enterprise-erpcrm-sync-engine' ) . ' ' . implode( ', ', Sage50UsWriter::HEADINGS );
		}
		if ( $stats['lost'] > 0 ) {
			$lines[] = sprintf(
				/* translators: 1: number of characters, 2: number of orders */
				_n( '%1$d character cannot be written in Windows-1252 and becomes a question mark (%2$d orders).', '%1$d characters cannot be written in Windows-1252 and become question marks (%2$d orders).', $stats['lost'], 'estasoft-enterprise-erpcrm-sync-engine' ),
				$stats['lost'],
				$stats['lost_orders']
			);
			$lines = array_merge( $lines, $stats['lost_lines'] );
			if ( $stats['lost_orders'] > count( $stats['lost_lines'] ) ) {
				$lines[] = '...';
			}
		}
		$lines[] = '';
		$lines[] = Sage50Csv::line( $this->headings( $settings ) );
		$shown   = 0;
		foreach ( $first as $entry ) {
			foreach ( explode( "\n", (string) ( $entry['row']['text'] ?? '' ) ) as $row ) {
				if ( $shown >= 8 ) {
					break 2;
				}
				$lines[] = $row;
				++$shown;
			}
		}
		if ( $stats['lines'] > $shown ) {
			$lines[] = '...';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Headings of the masters list.
	 *
	 * @return array<string,string>
	 */
	protected function masterHeadings(): array {
		return array(
			'accounts'  => __( 'Accounts and nominal or G/L codes', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'customers' => __( 'Customer IDs', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'nominals'  => __( 'Nominal codes', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'tax_codes' => __( 'Tax codes', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'agencies'  => __( 'Sales tax agencies', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'items'     => __( 'Item IDs', 'estasoft-enterprise-erpcrm-sync-engine' ),
		);
	}

	/**
	 * The heading names of the edition in the settings snapshot.
	 *
	 * @param array<string,mixed> $settings Snapshot.
	 * @return array<int,string>
	 */
	private function headings( array $settings ): array {
		return Sage50Settings::US === ( $settings['edition'] ?? Sage50Settings::UK ) ? Sage50UsWriter::HEADINGS : Sage50UkWriter::HEADINGS;
	}

	/**
	 * The settings screen.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function settingFields(): array {
		$yesno = array(
			'yes' => __( 'Yes', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'no'  => __( 'No', 'estasoft-enterprise-erpcrm-sync-engine' ),
		);
		return array(
			new CredentialField(
				'edition',
				__( 'Sage 50 edition', 'estasoft-enterprise-erpcrm-sync-engine' ),
				false,
				true,
				__( 'Sage 50 Canada is not covered.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'uk',
				'select',
				array(
					'uk' => __( 'Sage 50 Accounts (UK and Ireland)', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'us' => __( 'Sage 50 (U.S. edition)', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				'connection'
			),
			new CredentialField( 'currency', __( 'Currency of your books', 'estasoft-enterprise-erpcrm-sync-engine' ), false, true, __( 'Three letters, for example GBP or USD. Orders in another currency are left out.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'GBP', 'text', array(), 'connection' ),
			new CredentialField( 'customer_account', __( 'UK: customer account reference', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'The one account that web orders post to, for example WEB. It must exist in Sage 50.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'WEB', 'text', array(), 'connection' ),
			new CredentialField( 'sales_nominal', __( 'UK: sales nominal code', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'A tax code in the tax mapping can name another nominal for its rate, written like T1:4001.', 'estasoft-enterprise-erpcrm-sync-engine' ), '4000', 'text', array(), 'connection' ),
			new CredentialField( 'shipping_nominal', __( 'UK: shipping nominal code', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Empty uses the sales nominal.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'department', __( 'UK: department code', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, '', '', 'text', array(), 'advanced' ),
			new CredentialField( 'user_name', __( 'UK: user name column', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, '', '', 'text', array(), 'advanced' ),
			new CredentialField( 'date_format', __( 'UK: date format', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'It must match the date format of the computer that imports the file.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'dd/mm/yyyy', 'select', array_combine( Sage50Settings::DATE_FORMATS, Sage50Settings::DATE_FORMATS ), 'advanced' ),
			new CredentialField( 'customer_id', __( 'US: customer ID', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'The one customer that web orders use. It must exist in Sage 50.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'WEB', 'text', array(), 'connection' ),
			new CredentialField( 'ar_account', __( 'US: Accounts Receivable account', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, '', '', 'text', array(), 'connection' ),
			new CredentialField( 'sales_account', __( 'US: sales G/L account', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, '', '', 'text', array(), 'connection' ),
			new CredentialField( 'freight_account', __( 'US: freight G/L account', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Empty uses the sales account.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'tax_gl_account', __( 'US: sales tax G/L account', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'A tax code in the tax mapping can name another account for its agency, written like STATE:2300.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'item_tax_type', __( 'US: tax type of product lines', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'A number from 0 to 26.', 'estasoft-enterprise-erpcrm-sync-engine' ), '1', 'number', array(), 'advanced' ),
			new CredentialField( 'due_days', __( 'US: days until due', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, '', '0', 'number', array(), 'advanced' ),
			new CredentialField( 'use_items', __( 'US: write the SKU as Item ID', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Yes needs an inventory item with that ID in Sage 50.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no', 'select', $yesno, 'advanced' ),
			new CredentialField( 'untaxed_code', __( 'Tax code for lines without tax', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'UK default T0.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'fee_tax_code', __( 'Tax code for fees', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Fees carry no rate. Name the tax code for fees that have tax.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'adjustment_account', __( 'Account for other refund amounts', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Nominal code (UK) or G/L account (US) for refund amounts that belong to no product.', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'header_row', __( 'Heading row', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Leave it on No unless you have confirmed that your Sage 50 import skips the first row: no source says the UK import does, and a heading row it does not skip is read as an order.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no', 'select', $yesno, 'advanced' ),
			new CredentialField(
				'encoding',
				__( 'File encoding', 'estasoft-enterprise-erpcrm-sync-engine' ),
				false,
				false,
				__( 'Windows-1252 is the ANSI text Sage 50 reads. It cannot hold Arabic and other scripts: each such character becomes a question mark, and the export lists them in its notes file. UTF-8 keeps every script; Sage 50 reading it is not verified.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				'windows-1252',
				'select',
				array(
					'windows-1252' => __( 'Windows-1252', 'estasoft-enterprise-erpcrm-sync-engine' ),
					'utf-8-bom'    => __( 'UTF-8 with byte order mark', 'estasoft-enterprise-erpcrm-sync-engine' ),
				),
				'advanced'
			),
			new CredentialField( 'record_payments', __( 'UK: record payments', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'Adds a receipt row for paid orders. Needs a bank nominal for each payment method below.', 'estasoft-enterprise-erpcrm-sync-engine' ), 'no', 'select', $yesno, 'advanced' ),
			new CredentialField( 'payment_accounts', __( 'UK: bank nominal for each payment method', 'estasoft-enterprise-erpcrm-sync-engine' ), false, false, __( 'One line for each method, like: stripe = 1200', 'estasoft-enterprise-erpcrm-sync-engine' ), '', 'textarea', array(), 'advanced' ),
		);
	}
}
