<?php
/**
 * The settings of a Sage 50 export connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage50;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ExportShared\Charset;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportOptions;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportSettings;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * One connector, two layouts: `uk` (Sage 50 Accounts, Audit Trail Transactions) and `us` (Sage 50 U.S., Sales Journal). The
 * settings of the other edition are ignored.
 */
final class Sage50Settings implements ExportSettings {

	public const UK = 'uk';
	public const US = 'us';

	/**
	 * Keys read from the connection.
	 */
	public const KEYS = array(
		'edition',
		'currency',
		'encoding',
		'header_row',
		'record_payments',
		'payment_accounts',
		'fee_tax_code',
		'untaxed_code',
		'adjustment_account',
		'customer_account',
		'sales_nominal',
		'shipping_nominal',
		'department',
		'user_name',
		'date_format',
		'customer_id',
		'ar_account',
		'sales_account',
		'freight_account',
		'tax_gl_account',
		'item_tax_type',
		'due_days',
		'use_items',
	);

	/**
	 * Accepted date formats of the UK import.
	 */
	public const DATE_FORMATS = array( 'dd/mm/yyyy', 'dd/mm/yy', 'ddmmyyyy', 'ddmmyy' );

	/**
	 * Set up with the values.
	 *
	 * @param ExportOptions $options Values.
	 */
	public function __construct( private readonly ExportOptions $options ) {
	}

	/**
	 * Read a connection.
	 *
	 * @param Connection $connection Connection.
	 */
	public static function fromConnection( Connection $connection ): self {
		return new self( ExportOptions::fromConnection( $connection, self::KEYS ) );
	}

	/**
	 * The edition: uk or us.
	 */
	public function edition(): string {
		return $this->options->choice( 'edition', array( self::UK, self::US ), self::UK );
	}

	/**
	 * Whether the UK layout is written.
	 */
	public function isUk(): bool {
		return self::UK === $this->edition();
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
		return 'utf-8-bom' === $this->options->choice( 'encoding', array( 'windows-1252', 'utf-8-bom' ), 'windows-1252' ) ? Charset::Utf8Bom : Charset::Windows1252;
	}

	/**
	 * Whether the first row holds the field names.
	 */
	public function headerRow(): bool {
		return $this->options->flag( 'header_row', false );
	}

	/**
	 * Whether paid orders get a receipt row (UK only).
	 */
	public function recordPayments(): bool {
		return $this->isUk() && $this->options->flag( 'record_payments', false );
	}

	/**
	 * Bank nominal of a payment method id, or empty.
	 *
	 * @param string $method Payment method id.
	 */
	public function paymentAccount( string $method ): string {
		foreach ( $this->options->table( 'payment_accounts' ) as $id => $account ) {
			if ( 0 === strcasecmp( $id, $method ) ) {
				return $account;
			}
		}
		return '';
	}

	/**
	 * Tax code used for fee lines.
	 */
	public function feeTaxCode(): string {
		return $this->options->text( 'fee_tax_code' );
	}

	/**
	 * Tax code for lines that have no tax and no tax code: T0 in the UK edition.
	 */
	public function untaxedCode(): string {
		return $this->options->text( 'untaxed_code', $this->isUk() ? 'T0' : '' );
	}

	/**
	 * Nominal code or G/L account for refund amounts that belong to no product.
	 */
	public function adjustmentAccount(): string {
		return $this->options->text( 'adjustment_account' );
	}

	/**
	 * UK: the account reference every web order posts to.
	 */
	public function customerAccount(): string {
		return $this->options->text( 'customer_account', 'WEB' );
	}

	/**
	 * UK: nominal code for products.
	 */
	public function salesNominal(): string {
		return $this->options->text( 'sales_nominal', '4000' );
	}

	/**
	 * UK: nominal code for shipping; the sales nominal when empty.
	 */
	public function shippingNominal(): string {
		return $this->options->text( 'shipping_nominal' );
	}

	/**
	 * UK: department code, or empty.
	 */
	public function department(): string {
		return $this->options->text( 'department' );
	}

	/**
	 * UK: user name column, or empty.
	 */
	public function userName(): string {
		return $this->options->text( 'user_name' );
	}

	/**
	 * UK: the date format.
	 */
	public function dateFormat(): string {
		return $this->options->choice( 'date_format', self::DATE_FORMATS, 'dd/mm/yyyy' );
	}

	/**
	 * US: the customer ID every web order uses.
	 */
	public function customerId(): string {
		return $this->options->text( 'customer_id', 'WEB' );
	}

	/**
	 * US: Accounts Receivable account.
	 */
	public function arAccount(): string {
		return $this->options->text( 'ar_account' );
	}

	/**
	 * US: G/L account for products.
	 */
	public function salesAccount(): string {
		return $this->options->text( 'sales_account' );
	}

	/**
	 * US: G/L account for shipping; the sales account when empty.
	 */
	public function freightAccount(): string {
		return $this->options->text( 'freight_account', $this->salesAccount() );
	}

	/**
	 * US: G/L account that receives tax lines.
	 */
	public function taxGlAccount(): string {
		return $this->options->text( 'tax_gl_account' );
	}

	/**
	 * US: Tax Type written on product lines.
	 */
	public function itemTaxType(): int {
		return $this->options->integer( 'item_tax_type', 1, 0, 26 );
	}

	/**
	 * US: days from the order date to Date Due.
	 */
	public function dueDays(): int {
		return $this->options->integer( 'due_days', 0, 0, 365 );
	}

	/**
	 * US: whether the SKU is written as Item ID.
	 */
	public function useItems(): bool {
		return $this->options->flag( 'use_items', false );
	}

	/**
	 * What must be fixed before a file can be made.
	 *
	 * @return array<int,string>
	 */
	public function problems(): array {
		$problems = array();
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $this->currency() ) ) {
			$problems[] = __( 'Enter the currency of your books as a three-letter code, for example GBP.', 'estasoft-erp-crm-sync-engine' );
		}
		if ( $this->isUk() ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9]{1,8}$/', $this->customerAccount() ) ) {
				$problems[] = __( 'Enter the account reference of the customer that web orders post to (up to 8 letters or digits, for example WEB).', 'estasoft-erp-crm-sync-engine' );
			}
			if ( 1 !== preg_match( '/^\d{1,8}$/', $this->salesNominal() ) ) {
				$problems[] = __( 'Enter the nominal code for sales (up to 8 digits, for example 4000).', 'estasoft-erp-crm-sync-engine' );
			}
			return $problems;
		}
		if ( '' === $this->arAccount() ) {
			$problems[] = __( 'Enter the Accounts Receivable account.', 'estasoft-erp-crm-sync-engine' );
		}
		if ( '' === $this->salesAccount() ) {
			$problems[] = __( 'Enter the G/L account for sales.', 'estasoft-erp-crm-sync-engine' );
		}
		if ( '' === $this->customerId() || strlen( $this->customerId() ) > 20 ) {
			$problems[] = __( 'Enter the customer ID that web orders use (up to 20 characters).', 'estasoft-erp-crm-sync-engine' );
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
			'edition'  => $this->edition(),
			'encoding' => $this->charset()->value,
			'header'   => $this->headerRow() ? 1 : 0,
		);
	}
}
