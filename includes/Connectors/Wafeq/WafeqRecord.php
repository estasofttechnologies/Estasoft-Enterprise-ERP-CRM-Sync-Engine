<?php
/**
 * A Wafeq invoice, credit note or payment as the connector reads it.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a decoded answer. The reference pages of Wafeq do not render response schemas, so every accessor reads defensively and returns
 * an empty value for a field that is absent; the callers treat "absent" as "cannot be checked" rather than as zero.
 */
final class WafeqRecord {

	/**
	 * Field names that may hold the total of a document, in the order they are tried.
	 */
	public const TOTAL_FIELDS = array( 'amount', 'total', 'total_amount' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<mixed> $data Decoded answer.
	 */
	public function __construct( private readonly array $data ) {
	}

	/**
	 * The raw data.
	 *
	 * @return array<mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Wafeq's id of the record.
	 */
	public function id(): string {
		return WafeqPayloadReader::text( $this->data['id'] ?? '' );
	}

	/**
	 * The human number: the invoice number, the credit note number, or the reference.
	 */
	public function number(): string {
		foreach ( array( 'invoice_number', 'credit_note_number', 'reference' ) as $key ) {
			$value = WafeqPayloadReader::text( $this->data[ $key ] ?? '' );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * The status in upper case (DRAFT, SENT, FINALIZED), or an empty string.
	 */
	public function status(): string {
		return strtoupper( WafeqPayloadReader::text( $this->data['status'] ?? '' ) );
	}

	/**
	 * Whether the document is posted: finalized, or sent (which Wafeq only allows after posting).
	 */
	public function isFinalized(): bool {
		return in_array( $this->status(), array( 'FINALIZED', 'SENT' ), true );
	}

	/**
	 * Whether the document is still a draft.
	 */
	public function isDraft(): bool {
		return 'DRAFT' === $this->status();
	}

	/**
	 * The currency code in upper case.
	 */
	public function currency(): string {
		return strtoupper( WafeqPayloadReader::text( $this->data['currency'] ?? '' ) );
	}

	/**
	 * The document date as Y-m-d.
	 */
	public function date(): string {
		foreach ( array( 'invoice_date', 'credit_note_date', 'date' ) as $key ) {
			$value = WafeqOrderPayload::date( $this->data[ $key ] ?? '' );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * The external id.
	 */
	public function externalId(): string {
		return WafeqPayloadReader::text( $this->data['external_id'] ?? '' );
	}

	/**
	 * The contact id (the answer holds an id, or an object when expanded).
	 */
	public function contactId(): string {
		$contact = $this->data['contact'] ?? '';
		if ( is_array( $contact ) ) {
			return WafeqPayloadReader::text( $contact['id'] ?? '' );
		}
		return WafeqPayloadReader::text( $contact );
	}

	/**
	 * The total in minor units, or null when the answer carries none of the fields tried.
	 *
	 * @param int $exponent Decimal places of the document's currency.
	 */
	public function totalMinor( int $exponent ): ?int {
		$amounts = new WafeqAmounts();
		foreach ( self::TOTAL_FIELDS as $key ) {
			if ( array_key_exists( $key, $this->data ) ) {
				$minor = $amounts->toMinor( $this->data[ $key ], $exponent );
				if ( null !== $minor ) {
					return $minor;
				}
			}
		}
		return null;
	}

	/**
	 * The number of line items, or null when the answer does not carry them.
	 */
	public function lineCount(): ?int {
		$lines = $this->data['line_items'] ?? null;
		return is_array( $lines ) ? count( $lines ) : null;
	}

	/**
	 * The credit notes applied to this invoice as credit note id => amount in the string form Wafeq sent. Absent means "unknown".
	 *
	 * @return array<string,string>|null
	 */
	public function appliedCreditNotes(): ?array {
		$applied = $this->data['credit_notes'] ?? null;
		if ( ! is_array( $applied ) ) {
			return null;
		}
		$result = array();
		foreach ( $applied as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$note = $row['credit_note'] ?? '';
			$note = is_array( $note ) ? ( $note['id'] ?? '' ) : $note;
			$id   = WafeqPayloadReader::text( $note );
			if ( '' !== $id ) {
				$result[ $id ] = WafeqPayloadReader::text( $row['amount'] ?? '0' );
			}
		}
		return $result;
	}
}
