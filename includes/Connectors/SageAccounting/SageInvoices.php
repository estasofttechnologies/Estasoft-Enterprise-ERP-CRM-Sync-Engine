<?php
/**
 * Sales invoices, credit notes, payments and allocations in Sage.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Search by reference (the shop's reference), read, create, release a draft, delete, pay, allocate. A search answers with a short row, so the document is read again by
 * id; the status is `status.id` (DRAFT, UNPAID, PART_PAID, PAID, VOID) [inferred from the guides].
 */
final class SageInvoices {

	/**
	 * The reason the connector gives when it voids a credit note it made with the wrong total. A voided document with this reason is the connector's own discard
	 * and does not stop the refund; a document voided for any other reason was voided by a person and does.
	 */
	public const OWN_VOID_REASON = 'Created with the wrong total by the WooCommerce sync';

	/**
	 * Set up with what it depends on.
	 *
	 * @param SageApi $api Client.
	 */
	public function __construct( private readonly SageApi $api ) {
	}

	/**
	 * The sales invoice with a reference.
	 *
	 * @param string $reference WC-{id}.
	 * @return array<string,mixed>|null The invoice, null when none exists, or an array with only 'voided' => true when it was voided.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function findInvoice( string $reference ): ?array {
		return $this->find( 'sales_invoices', $reference );
	}

	/**
	 * The sales credit note with a reference.
	 *
	 * @param string $reference WC-{id}.
	 * @return array<string,mixed>|null Same convention as findInvoice().
	 *
	 * @throws ApiException On a failed call.
	 */
	public function findCreditNote( string $reference ): ?array {
		return $this->find( 'sales_credit_notes', $reference );
	}

	/**
	 * One document by id.
	 *
	 * @param string $path sales_invoices or sales_credit_notes.
	 * @param string $id   Id.
	 * @return array<string,mixed>|null Null when Sage has no such document.
	 *
	 * @throws ApiException On a failed call other than 404.
	 */
	public function get( string $path, string $id ): ?array {
		$response = $this->api->get( $path . '/' . rawurlencode( $id ), array(), array( 'allow' => array( 404 ) ) );
		if ( 404 === $response->status() ) {
			return null;
		}
		return $this->api->entity( $response, $path );
	}

	/**
	 * Create a document.
	 *
	 * @param string              $path sales_invoices or sales_credit_notes.
	 * @param string              $key  Wrapper key: sales_invoice or sales_credit_note.
	 * @param array<string,mixed> $body Body.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function create( string $path, string $key, array $body ): array {
		return $this->api->entity( $this->api->post( $path, array( $key => $body ) ), $path );
	}

	/**
	 * Release a draft invoice.
	 *
	 * @param string $id Invoice id.
	 * @return array<string,mixed> The released invoice.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function release( string $id ): array {
		return $this->api->entity( $this->api->post( 'sales_invoices/' . rawurlencode( $id ) . '/release' ), 'sales_invoices' );
	}

	/**
	 * Delete a draft invoice, or void a credit note, that the connector made and found wrong.
	 *
	 * @param string $path sales_invoices or sales_credit_notes.
	 * @param string $id   Id.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function remove( string $path, string $id ): void {
		$this->api->delete( $path . '/' . rawurlencode( $id ), 'sales_invoices' === $path ? array() : array( 'void_reason' => self::OWN_VOID_REASON ) );
	}

	/**
	 * Record a customer receipt.
	 *
	 * @param array<string,mixed> $payment Payment.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function pay( array $payment ): array {
		return $this->api->entity( $this->api->post( 'contact_payments', array( 'contact_payment' => $payment ) ), 'contact_payments' );
	}

	/**
	 * Apply a credit note to an invoice.
	 *
	 * @param array<string,mixed> $allocation Allocation.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function allocate( array $allocation ): array {
		return $this->api->entity( $this->api->post( 'contact_allocations', array( 'contact_allocation' => $allocation ) ), 'contact_allocations' );
	}

	/**
	 * The status id of a document.
	 *
	 * @param array<string,mixed> $document Invoice or credit note.
	 */
	public static function status( array $document ): string {
		return strtoupper( SagePayloadReader::text( $document['status']['id'] ?? ( $document['status'] ?? '' ) ) );
	}

	/**
	 * Find a document by reference among the search results, preferring a live one to a voided one.
	 *
	 * @param string $path      sales_invoices or sales_credit_notes.
	 * @param string $reference WC-{id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call.
	 */
	private function find( string $path, string $reference ): ?array {
		$voided = false;
		foreach ( $this->api->select( $path, array( 'search' => $reference ) ) as $row ) {
			if ( 0 !== strcasecmp( SagePayloadReader::text( $row['reference'] ?? '' ), $reference ) ) {
				continue;
			}
			$document = $this->get( $path, SagePayloadReader::text( $row['id'] ?? '' ) );
			if ( null === $document ) {
				continue;
			}
			if ( 'VOID' === self::status( $document ) ) {
				$voided = $voided || self::OWN_VOID_REASON !== SagePayloadReader::text( $document['void_reason'] ?? '' );
				continue;
			}
			return $document;
		}
		return $voided ? array( 'voided' => true ) : null;
	}
}
