<?php
/**
 * The settings of a DATEV export connection.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Datev;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ExportShared\Charset;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportOptions;
use Estasoft\SyncEngine\Connectors\ExportShared\ExportSettings;
use Estasoft\SyncEngine\Connectors\ExportShared\Utf8;
use Estasoft\SyncEngine\Domain\Connection;

/**
 * Consultant and client numbers, the financial year, the account length and the accounts. Nothing here is secret.
 */
final class DatevSettings implements ExportSettings {

	/**
	 * Keys read from the connection.
	 */
	public const KEYS = array(
		'consultant_number',
		'client_number',
		'fiscal_year_start',
		'account_length',
		'chart',
		'currency',
		'debtor_mode',
		'debtor_account',
		'collective_account',
		'shipping_account',
		'adjustment_account',
		'untaxed_code',
		'fee_tax_code',
		'encoding',
		'booking_text',
		'origin',
		'exported_by',
		'dictation_code',
		'lock_postings',
		'record_payments',
		'payment_accounts',
	);

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
	 * Berater number.
	 */
	public function consultant(): int {
		return $this->options->integer( 'consultant_number', 0, 0, 9999999 );
	}

	/**
	 * Mandant number.
	 */
	public function client(): int {
		return $this->options->integer( 'client_number', 0, 0, 99999 );
	}

	/**
	 * Month and day the financial year starts, as MMDD digits with a dash ("01-01").
	 */
	public function fiscalYearStart(): string {
		$text = $this->options->text( 'fiscal_year_start', '01-01' );
		return 1 === preg_match( '/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $text ) && checkdate( (int) substr( $text, 0, 2 ), (int) substr( $text, 3, 2 ), 2001 ) ? $text : '';
	}

	/**
	 * Sachkontenlänge.
	 */
	public function accountLength(): int {
		return $this->options->integer( 'account_length', 4, 4, 8 );
	}

	/**
	 * The chart: "03" or "04".
	 */
	public function chart(): string {
		return 'skr04' === $this->options->choice( 'chart', array( 'skr03', 'skr04' ), 'skr03' ) ? '04' : '03';
	}

	/**
	 * Currency of the books.
	 */
	public function currency(): string {
		return strtoupper( $this->options->text( 'currency', 'EUR' ) );
	}

	/**
	 * Whether every registered customer has an own debtor account.
	 */
	public function debtorPerCustomer(): bool {
		return 'customer_id' === $this->options->choice( 'debtor_mode', array( 'collective', 'customer_id' ), 'collective' );
	}

	/**
	 * The first personal account number for the account length: 10000 for four digits.
	 */
	public function personalBase(): int {
		return 10 ** $this->accountLength();
	}

	/**
	 * Collective debtor account.
	 */
	public function debtorAccount(): string {
		return $this->options->text( 'debtor_account', (string) $this->personalBase() );
	}

	/**
	 * Debtor account used for guests when every registered customer has an own one.
	 */
	public function collectiveAccount(): string {
		return $this->options->text( 'collective_account', $this->debtorAccount() );
	}

	/**
	 * Revenue account for shipping; empty means the account of the shipping tax code.
	 */
	public function shippingAccount(): string {
		return $this->options->text( 'shipping_account' );
	}

	/**
	 * Account for refund amounts that belong to no product.
	 */
	public function adjustmentAccount(): string {
		return $this->options->text( 'adjustment_account' );
	}

	/**
	 * Code (account, optionally a colon and a BU key) for lines without tax and without a tax code.
	 */
	public function untaxedCode(): string {
		return $this->options->text( 'untaxed_code' );
	}

	/**
	 * Code used for fee lines, which carry no rate.
	 */
	public function feeTaxCode(): string {
		return $this->options->text( 'fee_tax_code' );
	}

	/**
	 * Character set of the file.
	 */
	public function charset(): Charset {
		return 'utf-8-bom' === $this->options->choice( 'encoding', array( 'windows-1252', 'utf-8-bom' ), 'windows-1252' ) ? Charset::Utf8Bom : Charset::Windows1252;
	}

	/**
	 * Whether the Buchungstext carries the customer name.
	 */
	public function textWithName(): bool {
		return 'number_name' === $this->options->choice( 'booking_text', array( 'number_name', 'number' ), 'number_name' );
	}

	/**
	 * Header field Herkunft: two letters.
	 */
	public function origin(): string {
		$text = strtoupper( preg_replace( '/[^A-Za-z]/', '', $this->options->text( 'origin', 'RE' ) ) ?? '' );
		return 2 === strlen( $text ) ? $text : 'RE';
	}

	/**
	 * Header field Exportiert von: at most 25 characters.
	 */
	public function exportedBy(): string {
		return Utf8::cut( Utf8::line( $this->options->text( 'exported_by', 'Estasoft Sync' ) ), 25, '' );
	}

	/**
	 * Header field Diktatkürzel: at most two letters.
	 */
	public function dictationCode(): string {
		return substr( strtoupper( preg_replace( '/[^A-Za-z]/', '', $this->options->text( 'dictation_code' ) ) ?? '' ), 0, 2 );
	}

	/**
	 * Whether the batch is locked (Festschreibung 1) after the import.
	 */
	public function lock(): bool {
		return $this->options->flag( 'lock_postings', false );
	}

	/**
	 * Whether paid orders get a payment posting.
	 */
	public function recordPayments(): bool {
		return $this->options->flag( 'record_payments', false );
	}

	/**
	 * Bank or clearing account of a payment method id, or empty.
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
	 * What must be fixed before a file can be made.
	 *
	 * @return array<int,string>
	 */
	public function problems(): array {
		$problems = array();
		if ( $this->consultant() < 1001 ) {
			$problems[] = __( 'Enter the consultant number (Beraternummer) your tax adviser gave you, between 1001 and 9999999.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		if ( $this->client() < 1 ) {
			$problems[] = __( 'Enter the client number (Mandantennummer), between 1 and 99999.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		if ( '' === $this->fiscalYearStart() ) {
			$problems[] = __( 'Enter the first day of the financial year as month and day, for example 01-01.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $this->currency() ) ) {
			$problems[] = __( 'Enter the currency of the books as a three-letter code, for example EUR.', 'estasoft-enterprise-erpcrm-sync-engine' );
		}
		if ( ! DatevAccount::isPersonal( $this->debtorAccount(), $this->accountLength() ) ) {
			$problems[] = sprintf(
				/* translators: %d: number of digits */
				__( 'The debtor account must be a number with %d digits (one more than the account length).', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$this->accountLength() + 1
			);
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
			'encoding'   => $this->charset()->value,
			'consultant' => $this->consultant(),
			'client'     => $this->client(),
			'fy_start'   => $this->fiscalYearStart(),
			'length'     => $this->accountLength(),
			'chart'      => $this->chart(),
			'currency'   => $this->currency(),
			'origin'     => $this->origin(),
			'by'         => $this->exportedBy(),
			'dictation'  => $this->dictationCode(),
			'lock'       => $this->lock() ? 1 : 0,
		);
	}
}
