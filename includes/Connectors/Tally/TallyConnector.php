<?php
/**
 * The TallyPrime export connector.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Tally;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ExportShared\Charset;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportDocument;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportSettings;
use Estasoft\SyncEngine\Connectors\ExportShared\LedgerExportConnector;
use Estasoft\SyncEngine\Connectors\ExportShared\XmlBuilder;
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
 * Writes the TallyPrime XML import format (voucher import, `ID Vouchers`). TallyPrime does not answer: the person downloads the file
 * and imports it with Alt+O, Transactions. See docs/connectors/tallyprime-export.md.
 */
final class TallyConnector extends LedgerExportConnector {

	/**
	 * Identity and capabilities.
	 */
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'tallyprime-export',
			label: 'TallyPrime',
			type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Refunds ),
			entities: array( EntityType::Order, EntityType::Refund ),
			auth: AuthStyle::ExportFile,
			regions: array( 'AE', 'SA', 'IN' ),
			rateLimit: new RateLimitHints( 1.0, 1 )
		);
	}

	/**
	 * The default format; the content type of a real file follows the chosen character set.
	 */
	public function exportFormat(): ExportFormat {
		return new ExportFormat( 'xml', 'application/xml; charset=utf-16', __( 'TallyPrime XML import', 'estasoft-erp-crm-sync-engine' ), false, 'utf-16', true );
	}

	/**
	 * The typed settings.
	 *
	 * @param Connection $connection Connection.
	 */
	protected function settingsFor( Connection $connection ): ExportSettings {
		return TallySettings::fromConnection( $connection );
	}

	/**
	 * One voucher (and a receipt) for a document.
	 *
	 * @param ExportDocument $document Document.
	 * @param ExportSettings $settings Settings.
	 * @return array{text:string,masters:array<string,array<int,string>>,cut:int,extra:array<string,mixed>}
	 */
	protected function buildRow( ExportDocument $document, ExportSettings $settings ): array {
		return ( new TallyVoucherWriter() )->render( $document, $this->typed( $settings ) ) + array( 'extra' => array() );
	}

	/**
	 * The settings object this connector made.
	 *
	 * @param ExportSettings $settings Settings from settingsFor().
	 *
	 * @throws \LogicException When the settings are of another connector (a programming error).
	 */
	private function typed( ExportSettings $settings ): TallySettings {
		if ( ! $settings instanceof TallySettings ) {
			throw new \LogicException( esc_html__( 'These are not TallyPrime settings.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $settings;
	}

	/**
	 * The envelope around the vouchers, encoded, one piece at a time: the header with the byte order mark, each voucher text as it is
	 * read from the batch, and the closing tags.
	 *
	 * @param ExportBatch         $batch    Batch.
	 * @param array<string,mixed> $settings The file's settings.
	 * @return \Generator<int,string>
	 */
	protected function chunks( ExportBatch $batch, array $settings ): \Generator {
		$charset = $this->charsetFor( $settings );
		$head    = new XmlBuilder();
		$head->open( 'ENVELOPE' )->open( 'HEADER' )
			->leaf( 'VERSION', '1' )
			->leaf( 'TALLYREQUEST', 'Import' )
			->leaf( 'TYPE', 'Data' )
			->leaf( 'ID', 'Vouchers' )
			->close( 'HEADER' )
			->open( 'BODY' )
			->open( 'DESC' )
			->open( 'STATICVARIABLES' )
			->leaf( 'SVCURRENTCOMPANY', (string) ( $settings['company'] ?? '' ) )
			->close( 'STATICVARIABLES' )
			->close( 'DESC' )
			->open( 'DATA' );
		yield $charset->bom() . $charset->encode( '<?xml version="1.0" encoding="' . $charset->label() . "\"?>\n" . $head->toString() );
		foreach ( $this->rowsOf( $batch ) as $entry ) {
			$text = (string) ( $entry['row']['text'] ?? '' );
			yield $charset->encode( '   ' . str_replace( "\n", "\n   ", rtrim( $text, "\n" ) ) . "\n" );
		}
		yield $charset->encode( "  </DATA>\n </BODY>\n</ENVELOPE>\n" );
	}

	/**
	 * The format of the file: the content type names the character set and the contents are already encoded.
	 *
	 * @param array<string,mixed> $settings The file's settings.
	 */
	protected function fileFormat( array $settings ): ExportFormat {
		$charset = $this->charsetFor( $settings );
		return new ExportFormat( 'xml', 'application/xml; charset=' . strtolower( $charset->label() ), __( 'TallyPrime XML import', 'estasoft-erp-crm-sync-engine' ), false, strtolower( $charset->label() ), true );
	}

	/**
	 * The character set of the file.
	 *
	 * @param array<string,mixed> $settings The file's settings.
	 */
	protected function charsetFor( array $settings ): Charset {
		return Charset::fromSetting( (string) ( $settings['encoding'] ?? 'utf-16' ), Charset::Utf16 );
	}

	/**
	 * The company and the character set are read from the connection when the file is made.
	 *
	 * @return array<int,string>
	 */
	protected function fileLevelKeys(): array {
		return array( 'company', 'encoding' );
	}

	/**
	 * The first voucher, as plain text.
	 *
	 * @param array<int,array{item_id:int,order:string,row:array<string,mixed>}> $first    The first rows.
	 * @param array{orders:int,lines:int,lost:int}                               $stats    Counts.
	 * @param array<string,mixed>                                                $settings The file's settings.
	 */
	protected function preview( array $first, array $stats, array $settings ): string {
		$lines  = array(
			sprintf(
				/* translators: 1: number of vouchers, 2: company name, 3: character set */
				_n( 'TallyPrime XML: %1$d order for the company "%2$s", written in %3$s.', 'TallyPrime XML: %1$d orders for the company "%2$s", written in %3$s.', $stats['orders'], 'estasoft-erp-crm-sync-engine' ),
				$stats['orders'],
				(string) ( $settings['company'] ?? '' ),
				$this->charsetFor( $settings )->label()
			),
			'',
		);
		$sample = explode( "\n", rtrim( (string) ( $first[0]['row']['text'] ?? '' ), "\n" ) );
		foreach ( array_slice( $sample, 0, 30 ) as $line ) {
			$lines[] = $line;
		}
		if ( count( $sample ) > 30 ) {
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
			'ledgers'       => __( 'Ledgers', 'estasoft-erp-crm-sync-engine' ),
			'stock_items'   => __( 'Stock items', 'estasoft-erp-crm-sync-engine' ),
			'units'         => __( 'Units', 'estasoft-erp-crm-sync-engine' ),
			'godowns'       => __( 'Godowns', 'estasoft-erp-crm-sync-engine' ),
			'batches'       => __( 'Batches', 'estasoft-erp-crm-sync-engine' ),
			'voucher_types' => __( 'Voucher types', 'estasoft-erp-crm-sync-engine' ),
		);
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
			new CredentialField( 'company', __( 'Company name in TallyPrime', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'Type it exactly as TallyPrime shows it. Tally refuses a file for another company.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'connection' ),
			new CredentialField( 'currency', __( 'Currency of your books', 'estasoft-erp-crm-sync-engine' ), false, true, __( 'Three letters, for example AED or SAR. Orders in another currency are left out.', 'estasoft-erp-crm-sync-engine' ), 'AED', 'text', array(), 'connection' ),
			new CredentialField(
				'customer_ledger_mode',
				__( 'Customers in Tally', 'estasoft-erp-crm-sync-engine' ),
				false,
				false,
				__( 'One ledger for each customer needs the ledger to exist before you import.', 'estasoft-erp-crm-sync-engine' ),
				'shared',
				'select',
				array(
					'shared'       => __( 'One ledger for all web customers', 'estasoft-erp-crm-sync-engine' ),
					'per_customer' => __( 'One ledger for each customer', 'estasoft-erp-crm-sync-engine' ),
				),
				'connection'
			),
			new CredentialField( 'shared_customer_ledger', __( 'Ledger for web customers', 'estasoft-erp-crm-sync-engine' ), false, false, '', 'WooCommerce customers', 'text', array(), 'connection' ),
			new CredentialField( 'sales_ledger', __( 'Sales ledger', 'estasoft-erp-crm-sync-engine' ), false, false, '', 'Sales', 'text', array(), 'connection' ),
			new CredentialField( 'shipping_ledger', __( 'Shipping ledger', 'estasoft-erp-crm-sync-engine' ), false, false, '', 'Shipping charged', 'text', array(), 'connection' ),
			new CredentialField( 'fee_ledger', __( 'Fee ledger', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Empty uses the sales ledger.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'discount_ledger', __( 'Discount ledger', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'When set, products are written at their price before discount and the discount is posted here.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'roundoff_ledger', __( 'Round-off ledger', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Tally needs debits and credits to match to the last unit; a small difference goes here.', 'estasoft-erp-crm-sync-engine' ), 'Round Off', 'text', array(), 'advanced' ),
			new CredentialField( 'roundoff_max_minor', __( 'Largest round-off', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'In the smallest unit of the currency (fils, paise, cents). A larger difference leaves the order out.', 'estasoft-erp-crm-sync-engine' ), '5', 'number', array(), 'advanced' ),
			new CredentialField( 'adjustment_ledger', __( 'Ledger for other refund amounts', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Refund amounts that belong to no product, such as a manual amount.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField(
				'tax_mode',
				__( 'Tax in the file', 'estasoft-erp-crm-sync-engine' ),
				false,
				false,
				__( 'The tax rates come from your tax mapping. "No tax" writes the prices with tax and no tax ledger.', 'estasoft-erp-crm-sync-engine' ),
				'ledger',
				'select',
				array(
					'ledger' => __( 'Tax as ledger entries', 'estasoft-erp-crm-sync-engine' ),
					'none'   => __( 'No tax in the file', 'estasoft-erp-crm-sync-engine' ),
				),
				'connection'
			),
			new CredentialField( 'fee_tax_code', __( 'Tax ledger for fees', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Fees carry no rate, so name the tax ledger here when your fees have tax.', 'estasoft-erp-crm-sync-engine' ), '', 'text', array(), 'advanced' ),
			new CredentialField( 'use_inventory', __( 'Write stock items', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'No: products are posted to the sales ledger only.', 'estasoft-erp-crm-sync-engine' ), 'yes', 'select', $yesno, 'advanced' ),
			new CredentialField(
				'stock_item_source',
				__( 'Stock item name', 'estasoft-erp-crm-sync-engine' ),
				false,
				false,
				'',
				'sku',
				'select',
				array(
					'sku'  => __( 'The SKU', 'estasoft-erp-crm-sync-engine' ),
					'name' => __( 'The product name', 'estasoft-erp-crm-sync-engine' ),
				),
				'advanced'
			),
			new CredentialField(
				'missing_sku',
				__( 'A product without that name', 'estasoft-erp-crm-sync-engine' ),
				false,
				false,
				'',
				'refuse',
				'select',
				array(
					'refuse'      => __( 'Leave the order out', 'estasoft-erp-crm-sync-engine' ),
					'ledger_only' => __( 'Post the line to the sales ledger', 'estasoft-erp-crm-sync-engine' ),
				),
				'advanced'
			),
			new CredentialField( 'unit', __( 'Unit', 'estasoft-erp-crm-sync-engine' ), false, false, '', 'nos', 'text', array(), 'advanced' ),
			new CredentialField( 'godown', __( 'Godown', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Tally\'s name when godowns are off is Main Location.', 'estasoft-erp-crm-sync-engine' ), 'Main Location', 'text', array(), 'advanced' ),
			new CredentialField( 'batch', __( 'Batch', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Tally\'s name when batches are off is Primary Batch.', 'estasoft-erp-crm-sync-engine' ), 'Primary Batch', 'text', array(), 'advanced' ),
			new CredentialField( 'voucher_type_sales', __( 'Sales voucher type', 'estasoft-erp-crm-sync-engine' ), false, false, '', 'Sales', 'text', array(), 'advanced' ),
			new CredentialField( 'voucher_type_credit_note', __( 'Credit note voucher type', 'estasoft-erp-crm-sync-engine' ), false, false, '', 'Credit Note', 'text', array(), 'advanced' ),
			new CredentialField( 'voucher_type_receipt', __( 'Receipt voucher type', 'estasoft-erp-crm-sync-engine' ), false, false, '', 'Receipt', 'text', array(), 'advanced' ),
			new CredentialField( 'voucher_prefix', __( 'Text before the voucher number', 'estasoft-erp-crm-sync-engine' ), false, false, '', '', 'text', array(), 'advanced' ),
			new CredentialField( 'stable_id', __( 'Stable voucher ID', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'The same order always gets the same voucher ID, so Tally can overwrite it when you import twice and your Tally setting says so.', 'estasoft-erp-crm-sync-engine' ), 'yes', 'select', $yesno, 'advanced' ),
			new CredentialField( 'record_payments', __( 'Record payments', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'Adds a receipt for paid orders. Needs a ledger for each payment method below.', 'estasoft-erp-crm-sync-engine' ), 'no', 'select', $yesno, 'advanced' ),
			new CredentialField( 'payment_ledgers', __( 'Ledger for each payment method', 'estasoft-erp-crm-sync-engine' ), false, false, __( 'One line for each method, like: stripe = Stripe account', 'estasoft-erp-crm-sync-engine' ), '', 'textarea', array(), 'advanced' ),
			new CredentialField(
				'encoding',
				__( 'File encoding', 'estasoft-erp-crm-sync-engine' ),
				false,
				false,
				__( 'UTF-16 is what TallyPrime writes itself and keeps Arabic names intact.', 'estasoft-erp-crm-sync-engine' ),
				'utf-16',
				'select',
				array(
					'utf-16' => __( 'UTF-16', 'estasoft-erp-crm-sync-engine' ),
					'utf-8'  => __( 'UTF-8', 'estasoft-erp-crm-sync-engine' ),
				),
				'advanced'
			),
		);
	}
}
