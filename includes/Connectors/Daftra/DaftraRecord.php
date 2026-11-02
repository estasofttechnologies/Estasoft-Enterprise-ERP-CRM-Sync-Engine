<?php
/**
 * A Daftra invoice, credit note or client as the connector reads it.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

/**
 * Daftra answers `{result, code, data}` where `data` holds the record under its own name (`Invoice`), with its lines (`InvoiceItem`) and payments
 * (`InvoicePayment`) next to it; list rows are `{"Invoice": {...}}`. Every accessor returns an empty value for a field that is absent, and callers
 * treat "absent" as "cannot be checked", never as zero.
 */
final class DaftraRecord {

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<mixed>                   $data     The record's own fields.
	 * @param array<int,array<string,mixed>> $items    Its line items, when the answer has them.
	 * @param array<int,array<string,mixed>> $payments Its payments, when the answer has them.
	 */
	public function __construct( private readonly array $data, private readonly array $items = array(), private readonly array $payments = array() ) {
	}

	/**
	 * A record out of a single-record answer.
	 *
	 * @param array<mixed> $json Decoded answer.
	 * @param string       $key  Record name (Invoice, CreditNote, Client).
	 */
	public static function fromAnswer( array $json, string $key ): self {
		$data = $json['data'] ?? array();
		$data = is_array( $data ) ? $data : array();
		$main = $data[ $key ] ?? array();
		$main = is_array( $main ) ? $main : array();
		return new self( $main, self::rows( $data[ $key . 'Item' ] ?? ( $data['InvoiceItem'] ?? null ) ), self::rows( $data[ $key . 'Payment' ] ?? ( $data['InvoicePayment'] ?? null ) ) );
	}

	/**
	 * A record out of a list row.
	 *
	 * @param array<mixed> $row Row.
	 * @param string       $key Record name.
	 */
	public static function fromRow( array $row, string $key ): self {
		$main = $row[ $key ] ?? $row;
		return new self( is_array( $main ) ? $main : array() );
	}

	/**
	 * The raw fields.
	 *
	 * @return array<mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Daftra's id of the record.
	 */
	public function id(): string {
		return DaftraPayloadReader::text( $this->data['id'] ?? '' );
	}

	/**
	 * The document number Daftra shows (`no`).
	 */
	public function number(): string {
		return DaftraPayloadReader::text( $this->data['no'] ?? '' );
	}

	/**
	 * The purchase-order reference, where the connector keeps WC-{id}.
	 */
	public function reference(): string {
		return DaftraPayloadReader::text( $this->data['po_number'] ?? '' );
	}

	/**
	 * Whether the document is a draft.
	 */
	public function isDraft(): bool {
		return DaftraPayloadReader::flag( $this->data['draft'] ?? null, false );
	}

	/**
	 * The document date as Y-m-d.
	 */
	public function date(): string {
		return DaftraOrderPayload::date( $this->data['date'] ?? '' );
	}

	/**
	 * The currency code in upper case.
	 */
	public function currency(): string {
		return strtoupper( DaftraPayloadReader::text( $this->data['currency_code'] ?? '' ) );
	}

	/**
	 * The client id.
	 */
	public function clientId(): string {
		return DaftraPayloadReader::text( $this->data['client_id'] ?? '' );
	}

	/**
	 * The link to the PDF, when Daftra gave one.
	 */
	public function pdfUrl(): string {
		return DaftraPayloadReader::text( $this->data['invoice_pdf_url'] ?? '' );
	}

	/**
	 * The total in minor units (`summary_total`), or null when the answer carries none.
	 *
	 * @param int $exponent Decimal places of the currency.
	 */
	public function totalMinor( int $exponent ): ?int {
		if ( ! array_key_exists( 'summary_total', $this->data ) ) {
			return null;
		}
		return ( new DaftraAmounts() )->toMinor( $this->data['summary_total'], $exponent );
	}

	/**
	 * The number of line items, or null when the answer does not carry them.
	 */
	public function lineCount(): ?int {
		return array() === $this->items && ! array_key_exists( 'InvoiceItem', $this->data ) ? null : count( $this->items );
	}

	/**
	 * Whether the answer listed its payments (an invoice read on its own does).
	 */
	public function hasPaymentList(): bool {
		return array() !== $this->payments || array_key_exists( 'summary_paid', $this->data );
	}

	/**
	 * The transaction ids of the payments.
	 *
	 * @return array<int,string>
	 */
	public function paymentReferences(): array {
		$ids = array();
		foreach ( $this->payments as $payment ) {
			$id = DaftraPayloadReader::text( $payment['transaction_id'] ?? '' );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Rows of a nested list, unwrapping `{"InvoiceItem": {...}}` entries.
	 *
	 * @param mixed $value Value.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows( mixed $value ): array {
		$rows = array();
		foreach ( is_array( $value ) ? $value : array() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$inner  = reset( $entry );
			$rows[] = 1 === count( $entry ) && is_array( $inner ) && ! array_is_list( $inner ) && ! isset( $entry['id'] ) ? $inner : $entry;
		}
		return $rows;
	}
}
