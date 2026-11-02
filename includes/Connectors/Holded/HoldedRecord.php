<?php
/**
 * A Holded document or contact as the API reports it.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

/**
 * Holded answers a document as one flat object (id, docNumber, contact, date, currency, total, notes, products). Every accessor returns an empty
 * value for a field that is absent, and callers treat "absent" as "cannot be checked", never as zero.
 */
final class HoldedRecord {

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<mixed> $data The record's fields.
	 */
	public function __construct( private readonly array $data ) {
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
	 * Holded's id of the record.
	 */
	public function id(): string {
		return HoldedPayloadReader::text( $this->data['id'] ?? ( $this->data['_id'] ?? '' ) );
	}

	/**
	 * The document number Holded shows.
	 */
	public function number(): string {
		return HoldedPayloadReader::text( $this->data['docNumber'] ?? '' );
	}

	/**
	 * The id of the contact the document is for.
	 */
	public function contactId(): string {
		return HoldedPayloadReader::text( $this->data['contact'] ?? ( $this->data['contactId'] ?? '' ) );
	}

	/**
	 * The notes and the description, joined: the connector writes its marker in the notes, a person may have moved it.
	 */
	public function notes(): string {
		return trim( HoldedPayloadReader::text( $this->data['notes'] ?? '' ) . "\n" . HoldedPayloadReader::text( $this->data['desc'] ?? '' ) );
	}

	/**
	 * The currency code in upper case.
	 */
	public function currency(): string {
		return strtoupper( HoldedPayloadReader::text( $this->data['currency'] ?? '' ) );
	}

	/**
	 * The document date as Unix seconds, or null.
	 */
	public function timestamp(): ?int {
		$value = $this->data['date'] ?? null;
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * The total in minor units, or null when the answer carries none.
	 *
	 * @param int $exponent Decimal places of the currency.
	 */
	public function totalMinor( int $exponent ): ?int {
		if ( ! array_key_exists( 'total', $this->data ) ) {
			return null;
		}
		return ( new HoldedAmounts() )->toMinor( $this->data['total'], $exponent );
	}

	/**
	 * Whether the answer carries a total at all.
	 */
	public function hasTotal(): bool {
		return array_key_exists( 'total', $this->data );
	}

	/**
	 * The number of lines, or null when the answer does not carry them.
	 */
	public function lineCount(): ?int {
		$lines = $this->data['products'] ?? ( $this->data['items'] ?? null );
		return is_array( $lines ) ? count( $lines ) : null;
	}

	/**
	 * Whether the notes carry the bracketed reference.
	 *
	 * @param string $marker The bracketed reference.
	 */
	public function hasMarker( string $marker ): bool {
		return str_contains( $this->notes(), $marker );
	}
}
