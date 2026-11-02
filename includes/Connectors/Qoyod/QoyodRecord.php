<?php
/**
 * A Qoyod invoice, credit note or payment as the connector reads it.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a decoded record. The documented answer of an invoice has `contact_id`, `reference`, `status`, `issue_date`, `due_date`, `total`,
 * `paid_amount`, `due_amount`, `line_items[]` and `payments[]` (amounts are decimal strings); every accessor returns an empty value for
 * a field that is absent, and callers treat "absent" as "cannot be checked", never as zero.
 */
final class QoyodRecord {

	/**
	 * Set up with what it depends on.
	 *
	 * @param array<mixed> $data Decoded record.
	 */
	public function __construct( private readonly array $data ) {
	}

	/**
	 * A record out of an answer that may wrap it (`{"invoice": {...}}`) or hold it directly.
	 *
	 * @param array<mixed> $json Decoded answer.
	 * @param string       $key  Wrapper key.
	 */
	public static function unwrap( array $json, string $key ): self {
		$inner = $json[ $key ] ?? null;
		if ( is_array( $inner ) && ! \Estasoft\SyncEngine\Core\Support\Lists::isList( $inner ) ) {
			return new self( $inner );
		}
		if ( is_array( $inner ) && isset( $inner[0] ) && is_array( $inner[0] ) ) {
			return new self( $inner[0] );
		}
		return new self( $json );
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
	 * Qoyod's id of the record.
	 */
	public function id(): string {
		return QoyodPayloadReader::text( $this->data['id'] ?? '' );
	}

	/**
	 * The reference (Qoyod shows it as the document number).
	 */
	public function reference(): string {
		return QoyodPayloadReader::text( $this->data['reference'] ?? '' );
	}

	/**
	 * The description, where the connector keeps its "not checked yet" mark.
	 */
	public function description(): string {
		return QoyodPayloadReader::text( $this->data['description'] ?? '' );
	}

	/**
	 * Whether the document still carries the "not checked yet" mark.
	 */
	public function isUnchecked(): bool {
		return str_ends_with( $this->description(), QoyodIds::UNCHECKED ) || str_contains( $this->description(), QoyodIds::UNCHECKED . "\n" );
	}

	/**
	 * The status as Qoyod writes it (Draft, Approved, Paid, Partially Paid ...).
	 */
	public function status(): string {
		return QoyodPayloadReader::text( $this->data['status'] ?? '' );
	}

	/**
	 * Whether the document is a draft.
	 */
	public function isDraft(): bool {
		return 'draft' === strtolower( explode( ',', $this->status() )[0] );
	}

	/**
	 * Whether the document is posted: it has a status and is not a draft.
	 */
	public function isPosted(): bool {
		return '' !== $this->status() && ! $this->isDraft();
	}

	/**
	 * Whether the document is fully paid.
	 */
	public function isPaid(): bool {
		return 'paid' === strtolower( explode( ',', $this->status() )[0] );
	}

	/**
	 * The issue date as Y-m-d.
	 */
	public function date(): string {
		return QoyodOrderPayload::date( $this->data['issue_date'] ?? '' );
	}

	/**
	 * The contact id.
	 */
	public function contactId(): string {
		return QoyodPayloadReader::text( $this->data['contact_id'] ?? '' );
	}

	/**
	 * The total in minor units, or null when the answer carries none.
	 *
	 * @param int $exponent Decimal places of the currency.
	 */
	public function totalMinor( int $exponent ): ?int {
		return $this->amount( 'total', $exponent );
	}

	/**
	 * What is still due in minor units, or null.
	 *
	 * @param int $exponent Decimal places of the currency.
	 */
	public function dueMinor( int $exponent ): ?int {
		return $this->amount( 'due_amount', $exponent );
	}

	/**
	 * The number of line items, or null when the answer does not carry them.
	 */
	public function lineCount(): ?int {
		$lines = $this->data['line_items'] ?? null;
		return is_array( $lines ) ? count( $lines ) : null;
	}

	/**
	 * The ids of the credit notes allocated to this invoice, or null when the answer does not list payments.
	 *
	 * @return array<int,string>|null
	 */
	public function allocatedCreditNotes(): ?array {
		$payments = $this->data['payments'] ?? null;
		if ( ! is_array( $payments ) ) {
			return null;
		}
		$ids = array();
		foreach ( $payments as $payment ) {
			if ( is_array( $payment ) && 'CreditNote' === QoyodPayloadReader::text( $payment['source_type'] ?? '' ) ) {
				$ids[] = QoyodPayloadReader::text( $payment['source_id'] ?? '' );
			}
		}
		return $ids;
	}

	/**
	 * A money field.
	 *
	 * @param string $key      Field.
	 * @param int    $exponent Decimal places.
	 */
	private function amount( string $key, int $exponent ): ?int {
		if ( ! array_key_exists( $key, $this->data ) ) {
			return null;
		}
		return ( new QoyodAmounts() )->toMinor( $this->data[ $key ], $exponent );
	}
}
