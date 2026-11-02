<?php
/**
 * The DATEV export connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Datev;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ExportShared\Charset;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportDocument;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportSettings;
use Estasoft\SyncEngine\Connectors\ExportShared\LedgerExportConnector;
use Estasoft\SyncEngine\Connectors\ExportShared\SafeText;
use Estasoft\SyncEngine\Connectors\ExportShared\Utf8;
use Estasoft\SyncEngine\Connectors\Support\AuthStyle;
use Estasoft\SyncEngine\Connectors\Support\ConnectorMeta;
use Estasoft\SyncEngine\Connectors\Support\Export\CsvWriter;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportBatch;
use Estasoft\SyncEngine\Connectors\Support\Export\ExportFormat;
use Estasoft\SyncEngine\Connectors\Support\RateLimitHints;
use Estasoft\SyncEngine\Contract\CredentialField;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;

/**
 * Writes a DATEV-Format file: category 21 Buchungsstapel, header version 700, format version 13, 125 columns. The person (or the tax
 * adviser) imports it in DATEV Rechnungswesen with Bestand, Importieren, Stapelverarbeitung. See docs/connectors/datev-export.md.
 */
final class DatevConnector extends LedgerExportConnector {

	/**
	 * Format version written in the header (the column set of DATEV document 1003221, 125 fields).
	 */
	private const FORMAT_VERSION = 13;

	/**
	 * Identity and capabilities.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'datev-export',
			label: 'DATEV',
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Refunds ),
			entities: array( EntityType::Order, EntityType::Refund ),
			auth: AuthStyle::ExportFile,
			regions: array( 'DE', 'AT' ),
			rateLimit: new RateLimitHints( 1.0, 1 )
		);
	}

	/**
	 * The default format; the content type of a real file follows the chosen character set.
	 */
	public function exportFormat(): ExportFormat {
		return new ExportFormat( 'csv', 'text/csv; charset=windows-1252', __( 'DATEV-Format Buchungsstapel', 'estasoft-erp-crm-sync-engine' ), false, 'windows-1252', true );
	}

	/**
	 * The typed settings.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function settingsFor( Connection $connection ): ExportSettings {
		return DatevSettings::fromConnection( $connection );
	}

	/**
	 * The booking records of a document.
	 *
	 * @param ExportDocument $document Document.
	 * @param ExportSettings $settings Settings.
	 * @return array{text:string,masters:array<string,array<int,string>>,cut:int,extra:array<string,mixed>}
	 *
	 * @throws \LogicException When the settings are of another connector (a programming error).
	 */
	protected function buildRow( ExportDocument $document, ExportSettings $settings ): array {
		if ( ! $settings instanceof DatevSettings ) {
			throw new \LogicException( esc_html__( 'These are not DATEV settings.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return ( new DatevPostingWriter() )->render( $document, $settings );
	}

	/**
	 * Header, column names and records, one piece at a time. A first pass over the rows leaves out the orders of another financial
	 * year and finds the first and last booking date for the header; the second pass writes the records.
	 *
	 * @param ExportBatch         $batch    Batch.
	 * @param array<string,mixed> $settings The file's settings (with `fy`, the first day of the financial year).
	 * @return \Generator<int,string>
	 */
	protected function chunks( ExportBatch $batch, array $settings ): \Generator {
		$charset = $this->charsetFor( $settings );
		$year    = (string) ( $settings['fy'] ?? '' );
		$from    = '9999-12-31';
		$to      = '0000-01-01';
		foreach ( $this->rowsOf( $batch ) as $entry ) {
			if ( ! $this->belongsToFile( $entry, $settings ) ) {
				$batch->leaveOut(
					$entry['item_id'],
					$entry['order'],
					sprintf(
						/* translators: 1: date of the order, 2: first day of the financial year of this file */
						__( 'dated %1$s, a different financial year from this file (which starts %2$s); export it again after this file', 'estasoft-erp-crm-sync-engine' ),
						(string) ( $entry['row']['date'] ?? '' ),
						$year
					)
				);
				continue;
			}
			$extra = (array) ( $entry['row']['extra'] ?? array() );
			$from  = min( $from, (string) ( $extra['from'] ?? $from ) );
			$to    = max( $to, (string) ( $extra['to'] ?? $to ) );
		}
		yield $charset->bom() . $charset->encode( $this->header( $settings, $year, $from, $to ) . "\r\n" . DatevColumns::headings() . "\r\n" );
		foreach ( $this->rowsOf( $batch ) as $entry ) {
			yield $charset->encode( str_replace( "\n", "\r\n", (string) $entry['row']['text'] ) . "\r\n" );
		}
	}

	/**
	 * The file is for the financial year of the first order.
	 *
	 * @param array<string,mixed> $settings The settings snapshot of the first order.
	 * @param array<string,mixed> $row      The first order's row.
	 * @return array<string,mixed>
	 */
	protected function fileFacts( array $settings, array $row ): array {
		$settings['fy'] = (string) ( $row['extra']['fy'] ?? '' );
		return $settings;
	}

	/**
	 * Whether a row is of the financial year of the file.
	 *
	 * @param array{item_id:int,order:string,row:array<string,mixed>} $entry    The row.
	 * @param array<string,mixed>                                     $settings The file's settings.
	 */
	protected function belongsToFile( array $entry, array $settings ): bool {
		return (string) ( $entry['row']['extra']['fy'] ?? '' ) === (string) ( $settings['fy'] ?? '' );
	}

	/**
	 * The format of the file: the content type names the character set and the contents are already encoded.
	 *
	 * @param array<string,mixed> $settings The file's settings.
	 */
	protected function fileFormat( array $settings ): ExportFormat {
		$charset = $this->charsetFor( $settings );
		return new ExportFormat( 'csv', 'text/csv; charset=' . strtolower( $charset->label() ), __( 'DATEV-Format Buchungsstapel', 'estasoft-erp-crm-sync-engine' ), false, strtolower( $charset->label() ), true );
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
	 * The header takes the numbers and the character set from the connection when the file is made.
	 *
	 * @return array<int,string>
	 */
	protected function fileLevelKeys(): array {
		return array( 'encoding', 'consultant', 'client', 'origin', 'by', 'dictation' );
	}

	/**
	 * A short look at the file for the Exports screen.
	 *
	 * @param array<int,array{item_id:int,order:string,row:array<string,mixed>}>                $first    The first rows.
	 * @param array{orders:int,lines:int,lost:int,lost_orders:int,lost_lines:array<int,string>} $stats Counts, and which orders have characters the file cannot hold.
	 * @param array<string,mixed>                                                               $settings The file's settings.
	 */
	protected function preview( array $first, array $stats, array $settings ): string {
		$charset = $this->charsetFor( $settings );
		$lines   = array(
			sprintf(
				/* translators: 1: number of orders, 2: number of postings, 3: character set */
				_n( 'DATEV-Format Buchungsstapel: %1$d order, %2$d postings, written in %3$s.', 'DATEV-Format Buchungsstapel: %1$d orders, %2$d postings, written in %3$s.', $stats['orders'], 'estasoft-erp-crm-sync-engine' ),
				$stats['orders'],
				$stats['lines'],
				$charset->label()
			),
			sprintf(
				/* translators: 1: consultant number, 2: client number, 3: first day of the financial year */
				__( 'Consultant %1$d, client %2$d, financial year from %3$s.', 'estasoft-erp-crm-sync-engine' ),
				(int) ( $settings['consultant'] ?? 0 ),
				(int) ( $settings['client'] ?? 0 ),
				(string) ( $settings['fy'] ?? '' )
			),
		);
		if ( $stats['lost'] > 0 ) {
			$lines[] = sprintf(
				/* translators: 1: number of characters, 2: number of orders */
				_n( '%1$d character cannot be written in Windows-1252 and becomes a question mark (%2$d orders).', '%1$d characters cannot be written in Windows-1252 and become question marks (%2$d orders).', $stats['lost'], 'estasoft-erp-crm-sync-engine' ),
				$stats['lost'],
				$stats['lost_orders']
			);
			$lines = array_merge( $lines, $stats['lost_lines'] );
			if ( $stats['lost_orders'] > count( $stats['lost_lines'] ) ) {
				$lines[] = '...';
			}
		}
		$lines[] = '';
		$shown   = 0;
		foreach ( $first as $entry ) {
			foreach ( explode( "\n", (string) ( $entry['row']['text'] ?? '' ) ) as $record ) {
				if ( $shown >= 6 ) {
					break 2;
				}
				$lines[] = $record;
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
			'debtors' => __( 'Debtor accounts (Debitoren)', 'estasoft-erp-crm-sync-engine' ),
			'revenue' => __( 'Revenue accounts (Erlöskonten)', 'estasoft-erp-crm-sync-engine' ),
			'banks'   => __( 'Bank and clearing accounts', 'estasoft-erp-crm-sync-engine' ),
			'bu_keys' => __( 'BU keys (Buchungsschlüssel)', 'estasoft-erp-crm-sync-engine' ),
		);
	}

	/**
	 * The header record (row 1).
	 *
	 * @param array<string,mixed> $settings Settings snapshot of the first order.
	 * @param string              $year     First day of the financial year, Y-m-d.
	 * @param string              $from     First booking date, Y-m-d.
	 * @param string              $to       Last booking date, Y-m-d.
	 */
	private function header( array $settings, string $year, string $from, string $to ): string {
		$writer = ExportFormat::csv( 'DATEV-Format Buchungsstapel', false, ';' )->writer( CsvWriter::SPACE, "\r\n", true );
		$text   = static fn( string $value ): string => '' === $value ? '' : $writer->field( $value, true );
		$name   = Utf8::cut( 'WooCommerce ' . substr( $from, 0, 7 ), 30 );
		$by     = SafeText::fit( (string) ( $settings['by'] ?? '' ), 25 )['text'];
		$when   = $this->deps->clock->now()->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'YmdHis' ) . '000';
		$cols   = array(
			'"EXTF"',
			'700',
			'21',
			'"Buchungsstapel"',
			(string) self::FORMAT_VERSION,
			$when,
			'',
			$text( (string) ( $settings['origin'] ?? 'RE' ) ),
			$text( $by ),
			'',
			(string) (int) ( $settings['consultant'] ?? 0 ),
			(string) (int) ( $settings['client'] ?? 0 ),
			str_replace( '-', '', $year ),
			(string) (int) ( $settings['length'] ?? 4 ),
			str_replace( '-', '', $from ),
			str_replace( '-', '', $to ),
			$text( $name ),
			$text( (string) ( $settings['dictation'] ?? '' ) ),
			'1',
			'0',
			(string) (int) ( $settings['lock'] ?? 0 ),
			$text( (string) ( $settings['currency'] ?? 'EUR' ) ),
			'',
			'',
			'',
			'',
			$text( (string) ( $settings['chart'] ?? '03' ) ),
			'',
			'',
			'',
			$text( 'Estasoft Sync' ),
		);
		return implode( ';', $cols );
	}

	/**
	 * The settings screen.
	 *
	 * @return array<int,CredentialField>
	 */
	protected function settingFields(): array {
		$yesno = array(
			'yes' => __( 'Yes', 'estasoft-erp-crm-sync-engine' ),
			'no'  => __( 'No', 'estasoft-erp-crm-sync-engine' ),
		);
		return array(
			new CredentialField( 'consultant_number', __( 'Consultant number (Beraternummer)', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'Your tax adviser knows it. Between 1001 and 9999999.', 'estasoft-erp-crm-sync-engine' ), '', 'number', array(), 'connection' ),
			new CredentialField( 'client_number', __( 'Client number (Mandantennummer)', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'Between 1 and 99999.', 'estasoft-erp-crm-sync-engine' ), '', 'number', array(), 'connection' ),
			new CredentialField( 'fiscal_year_start', __( 'First day of the financial year', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'Month and day, for example 01-01. A file never mixes two financial years.', 'estasoft-erp-crm-sync-engine' ), '01-01', 'text', array(), 'connection' ),
			new CredentialField( 'account_length', __( 'Account length (Sachkontenlänge)', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'Digits of a G/L account, 4 to 8. Debtor accounts have one digit more.', 'estasoft-erp-crm-sync-engine' ), '4', 'number', array(), 'connection' ),
			new CredentialField(
				'chart',
				__( 'Chart of accounts', 'estasoft-erp-crm-sync-engine' ),
				false,
				true,
				'',
				'skr03',
				'select',
				array(
					'skr03' => __( 'SKR03', 'estasoft-erp-crm-sync-engine' ),
					'skr04' => __( 'SKR04', 'estasoft-erp-crm-sync-engine' ),
				),
				'connection'
			),
			new CredentialField(
				'debtor_mode',
				__( 'Debtor accounts', 'estasoft-erp-crm-sync-engine' ),
				false,
				false,
				__( 'One collective account, or an own account for every registered customer (the account is the first personal account number plus the WooCommerce customer number).', 'estasoft-erp-crm-sync-engine' ),
				'collective',
				'select',
				array(
					'collective'  => __( 'One collective debtor account', 'estasoft-erp-crm-sync-engine' ),
					'customer_id' => __( 'An account for each registered customer', 'estasoft-erp-crm-sync-engine' ),
				),
				'connection'
			),
			new CredentialField( 'debtor_account', __( 'Debtor account', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'For example 10000 with four-digit accounts. Revenue accounts and BU keys come from your tax mapping, written as 8400 or 8200:3.', 'estasoft-erp-crm-sync-engine' ), '10000', 'text', array(), 'connection' ),
			new CredentialField( 'collective_account', __( 'Debtor account for guests', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Used for guest orders when every registered customer has an own account. Empty uses the debtor account.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'shipping_account', __( 'Revenue account for shipping', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Empty uses the account of the shipping line\'s tax code.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'adjustment_account', __( 'Account for other refund amounts', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Refund amounts that belong to no product, such as a manual amount.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'untaxed_code', __( 'Account for sales without tax', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Used for lines that have no tax. An account, or an account and BU key like 8120:2.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'fee_tax_code', __( 'Account for fees', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Fees carry no rate. Name the account (and BU key) for fees that have tax.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'currency', __( 'Currency of the books', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Orders in another currency are left out.', 'estasoft-erp-crm-sync-engine' ), 'EUR', 'text', array(), 'advanced' ),
			new CredentialField(
				'encoding',
				__( 'File encoding', 'estasoft-erp-crm-sync-engine' ),
				false,
				false,
				__( 'Windows-1252 is the "ANSI" of the DATEV description, which does not mention UTF-8. It cannot hold Arabic and other scripts: each such character becomes a question mark, and the export lists them in its notes file. Ask your adviser whether their DATEV reads UTF-8 before you choose it.', 'estasoft-erp-crm-sync-engine' ),
				'windows-1252',
				'select',
				array(
					'windows-1252' => __( 'Windows-1252', 'estasoft-erp-crm-sync-engine' ),
					'utf-8-bom'    => __( 'UTF-8 with byte order mark', 'estasoft-erp-crm-sync-engine' ),
				),
				'advanced'
			),
			new CredentialField(
				'booking_text',
				__( 'Booking text', 'estasoft-erp-crm-sync-engine' ),
				false,
				false,
				'',
				'number_name',
				'select',
				array(
					'number_name' => __( 'Order number and customer name', 'estasoft-erp-crm-sync-engine' ),
					'number'      => __( 'Order number only', 'estasoft-erp-crm-sync-engine' ),
				),
				'advanced'
			),
			new CredentialField( 'origin', __( 'Origin (Herkunft)', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Two letters in the header.', 'estasoft-erp-crm-sync-engine' ), 'RE', 'text', array(), 'advanced' ),
			new CredentialField( 'exported_by', __( 'Exported by', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'At most 25 characters in the header.', 'estasoft-erp-crm-sync-engine' ), 'Estasoft Sync', 'text', array(), 'advanced' ),
			new CredentialField( 'dictation_code', __( 'Dictation code (Diktatkürzel)', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'At most two letters. Optional.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'lock_postings', __( 'Lock the postings after import', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'No: your tax adviser locks them (recommended).', 'estasoft-erp-crm-sync-engine' ), 'no', 'select', $yesno, 'advanced' ),
			new CredentialField( 'record_payments', __( 'Record payments', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Adds a posting for paid orders. Needs an account for each payment method below.', 'estasoft-erp-crm-sync-engine' ), 'no', 'select', $yesno, 'advanced' ),
			new CredentialField( 'payment_accounts', __( 'Account for each payment method', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'One line for each method, like: stripe = 1360', 'estasoft-erp-crm-sync-engine' ), '', 'textarea', array(), 'advanced' ),
		);
	}
}
