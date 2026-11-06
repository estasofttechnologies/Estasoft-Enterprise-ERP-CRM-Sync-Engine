<?php
/**
 * The settings of a TallyPrime export connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Tally;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ExportShared\Charset;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportOptions;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportSettings;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Names of ledgers and the company, and a few switches. Nothing here is secret. Defaults are the names the setup screen suggests;
 * the person must create those ledgers in TallyPrime (the masters list names every one the file uses).
 */
final class TallySettings implements ExportSettings {

	/**
	 * Keys read from the connection.
	 */
	public const KEYS = array(
		'company',
		'currency',
		'encoding',
		'customer_ledger_mode',
		'shared_customer_ledger',
		'sales_ledger',
		'shipping_ledger',
		'fee_ledger',
		'discount_ledger',
		'roundoff_ledger',
		'roundoff_max_minor',
		'adjustment_ledger',
		'tax_mode',
		'use_inventory',
		'stock_item_source',
		'missing_sku',
		'unit',
		'godown',
		'batch',
		'voucher_type_sales',
		'voucher_type_credit_note',
		'voucher_type_receipt',
		'voucher_prefix',
		'stable_id',
		'record_payments',
		'payment_ledgers',
		'fee_tax_code',
	);

	/**
	 * Set up with the values.
	 *
	 * @param ExportOptions $options      Values.
	 * @param int           $connectionId Connection id (part of the stable voucher ID).
	 */
	public function __construct( private readonly ExportOptions $options, private readonly int $connectionId = 0 ) {
	}

	/**
	 * Read a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function fromConnection( Connection $connection ): self {
		return new self( ExportOptions::fromConnection( $connection, self::KEYS ), $connection->id );
	}

	/**
	 * Connection id.
	 */
	public function connectionId(): int {
		return $this->connectionId;
	}

	/**
	 * Company name as TallyPrime shows it.
	 */
	public function company(): string {
		return $this->options->text( 'company' );
	}

	/**
	 * Currency of the books.
	 */
	public function currency(): string {
		return strtoupper( $this->options->text( 'currency' ) );
	}

	/**
	 * Character set of the file.
	 */
	public function charset(): Charset {
		return Charset::fromSetting( $this->options->choice( 'encoding', array( 'utf-16', 'utf-8' ), 'utf-16' ), Charset::Utf16 );
	}

	/**
	 * Whether every customer has an own ledger.
	 */
	public function perCustomer(): bool {
		return 'per_customer' === $this->options->choice( 'customer_ledger_mode', array( 'shared', 'per_customer' ), 'shared' );
	}

	/**
	 * Party ledger used in shared mode.
	 */
	public function sharedLedger(): string {
		return $this->options->text( 'shared_customer_ledger', 'WooCommerce customers' );
	}

	/**
	 * Sales ledger.
	 */
	public function salesLedger(): string {
		return $this->options->text( 'sales_ledger', 'Sales' );
	}

	/**
	 * Shipping ledger.
	 */
	public function shippingLedger(): string {
		return $this->options->text( 'shipping_ledger', 'Shipping charged' );
	}

	/**
	 * Fee ledger; the sales ledger when none is set.
	 */
	public function feeLedger(): string {
		return $this->options->text( 'fee_ledger', $this->salesLedger() );
	}

	/**
	 * Discount ledger; empty means no discount line.
	 */
	public function discountLedger(): string {
		return $this->options->text( 'discount_ledger' );
	}

	/**
	 * Round-off ledger.
	 */
	public function roundOffLedger(): string {
		return $this->options->text( 'roundoff_ledger', 'Round Off' );
	}

	/**
	 * Largest difference in minor units that goes to the round-off ledger.
	 */
	public function roundOffMax(): int {
		return $this->options->integer( 'roundoff_max_minor', 5, 0, 100 );
	}

	/**
	 * Ledger for refund amounts that belong to no product.
	 */
	public function adjustmentLedger(): string {
		return $this->options->text( 'adjustment_ledger' );
	}

	/**
	 * Whether tax is written as ledger entries.
	 */
	public function taxAsLedger(): bool {
		return 'ledger' === $this->options->choice( 'tax_mode', array( 'ledger', 'none' ), 'ledger' );
	}

	/**
	 * Whether lines are stock item entries.
	 */
	public function useInventory(): bool {
		return $this->options->flag( 'use_inventory', true );
	}

	/**
	 * Whether the stock item name is the SKU (true) or the product name (false).
	 */
	public function stockBySku(): bool {
		return 'sku' === $this->options->choice( 'stock_item_source', array( 'sku', 'name' ), 'sku' );
	}

	/**
	 * Whether a line without a stock item name is posted to the sales ledger (true) or the order is left out (false).
	 */
	public function ledgerOnlyWhenMissing(): bool {
		return 'ledger_only' === $this->options->choice( 'missing_sku', array( 'refuse', 'ledger_only' ), 'refuse' );
	}

	/**
	 * Unit written after quantities.
	 */
	public function unit(): string {
		return $this->options->text( 'unit', 'nos' );
	}

	/**
	 * Godown name.
	 */
	public function godown(): string {
		return $this->options->text( 'godown', 'Main Location' );
	}

	/**
	 * Batch name.
	 */
	public function batch(): string {
		return $this->options->text( 'batch', 'Primary Batch' );
	}

	/**
	 * Voucher type name for sales.
	 */
	public function salesType(): string {
		return $this->options->text( 'voucher_type_sales', 'Sales' );
	}

	/**
	 * Voucher type name for credit notes.
	 */
	public function creditNoteType(): string {
		return $this->options->text( 'voucher_type_credit_note', 'Credit Note' );
	}

	/**
	 * Voucher type name for receipts.
	 */
	public function receiptType(): string {
		return $this->options->text( 'voucher_type_receipt', 'Receipt' );
	}

	/**
	 * Text added before the order number.
	 */
	public function prefix(): string {
		return $this->options->text( 'voucher_prefix' );
	}

	/**
	 * Whether vouchers carry a stable ID.
	 */
	public function stableId(): bool {
		return $this->options->flag( 'stable_id', true );
	}

	/**
	 * Whether paid orders get a receipt voucher.
	 */
	public function recordPayments(): bool {
		return $this->options->flag( 'record_payments', false );
	}

	/**
	 * Ledger of a payment method id, or empty.
	 *
	 * @param string $method Payment method id.
	 */
	public function paymentLedger( string $method ): string {
		foreach ( $this->options->table( 'payment_ledgers' ) as $id => $ledger ) {
			if ( 0 === strcasecmp( $id, $method ) ) {
				return $ledger;
			}
		}
		return '';
	}

	/**
	 * Tax ledger used for fee lines.
	 */
	public function feeTaxCode(): string {
		return $this->options->text( 'fee_tax_code' );
	}

	/**
	 * What must be fixed before a file can be made.
	 *
	 * @return array<int,string>
	 */
	public function problems(): array {
		$problems = array();
		if ( '' === $this->company() ) {
			$problems[] = __( 'Enter the company name exactly as TallyPrime shows it.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $this->currency() ) ) {
			$problems[] = __( 'Enter the currency of your books as a three-letter code, for example AED.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		return $problems;
	}

	/**
	 * What the file needs later.
	 *
	 * @return array<string,mixed>
	 */
	public function snapshot(): array {
		return array(
			'company'  => $this->company(),
			'encoding' => $this->charset()->value,
		);
	}
}
