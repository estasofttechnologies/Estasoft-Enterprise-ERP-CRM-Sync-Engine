<?php
/**
 * Sends a refund to Xero as a sales credit note, one resumable step at a time.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The credit note is made for the contact and in the currency of the order's invoice, checked against the refund amount, then approved and
 * applied to the invoice as far as the `refund_state` option asks. Every pass reads the credit note first and does only what is missing.
 */
final class XeroRefundSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param XeroInvoices        $invoices     Documents.
	 * @param XeroItems           $items        Items.
	 * @param XeroDocumentBuilder $builder      Body builder.
	 * @param XeroTotals          $totals       Total check.
	 * @param XeroAmounts         $amounts      Conversion helper.
	 * @param XeroOptions         $options      Connection options.
	 * @param int                 $connectionId Connection id, for idempotency keys.
	 */
	public function __construct(
		private readonly XeroInvoices $invoices,
		private readonly XeroItems $items,
		private readonly XeroDocumentBuilder $builder,
		private readonly XeroTotals $totals,
		private readonly XeroAmounts $amounts,
		private readonly XeroOptions $options,
		private readonly int $connectionId
	) {
	}

	/**
	 * The credit note of a refund, when it is finished for the connection's settings.
	 *
	 * @param Item $item The queue item.
	 * @return ExternalRef|ConnectorError|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function find( Item $item ): ExternalRef|ConnectorError|null {
		$note = $this->invoices->findCreditNote( $item->clientReference() );
		if ( null === $note ) {
			return null;
		}
		if ( ! empty( $note['voided'] ) ) {
			return $this->voided( $item->clientReference() );
		}
		return $this->finished( $note ) ? $this->reference( $note, array() ) : null;
	}

	/**
	 * Send the refund.
	 *
	 * @param Item                $item    The queue item.
	 * @param array<string,mixed> $payload Mapped payload.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function send( Item $item, array $payload ): SendResult {
		$parsed = XeroRefundPayload::parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		$warnings = array();
		$note     = $this->invoices->findCreditNote( $parsed->clientRef );
		if ( null !== $note && ! empty( $note['voided'] ) ) {
			return SendResult::failure( $this->voided( $parsed->clientRef ) );
		}
		$invoice = $this->invoices->findInvoice( $parsed->orderRef );
		if ( null === $invoice || ! empty( $invoice['voided'] ) ) {
			return SendResult::failure(
				XeroErrors::refused(
					'Invoice',
					sprintf(
						/* translators: %s: the shop's reference of the order, such as WC-1042. */
						__( 'The invoice for %s was not found in Xero, so the refund cannot be linked to it.', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$parsed->orderRef
					)
				)
			);
		}
		$createdNow = false;
		if ( null === $note ) {
			$note = $this->create( $parsed, $invoice, $warnings );
			if ( $note instanceof ConnectorError ) {
				return SendResult::failure( $note );
			}
			$createdNow = true;
		}
		$done = $this->finish( $note, $invoice, $parsed, $createdNow, $warnings );
		if ( $done instanceof ConnectorError ) {
			return SendResult::failure( $done );
		}
		$ref = $this->reference( $done, $warnings );
		return $createdNow ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Create the draft credit note.
	 *
	 * @param XeroRefundPayload   $parsed   Checked payload.
	 * @param array<string,mixed> $invoice  The refunded order's invoice.
	 * @param array<int,string>   $warnings Receives notes.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	private function create( XeroRefundPayload $parsed, array $invoice, array &$warnings ): array|ConnectorError {
		$skus = array();
		foreach ( $parsed->lines as $line ) {
			if ( '' !== $line['sku'] ) {
				$skus[] = (string) $line['sku'];
			}
		}
		$currency = strtoupper( XeroPayloadReader::text( $invoice['CurrencyCode'] ?? '' ) );
		$contact  = XeroPayloadReader::text( $invoice['Contact']['ContactID'] ?? '' );
		$body     = $this->builder->creditNote( $parsed, $contact, $currency, $this->items->byCodes( $skus ), $warnings );
		if ( $body instanceof ConnectorError ) {
			return $body;
		}
		return $this->invoices->createCreditNote( $body, 'wc-' . $this->connectionId . '-' . $parsed->clientRef . '-creditnote-' . substr( sha1( implode( ',', $this->invoices->deadIds( $parsed->clientRef ) ) . '|' . (string) wp_json_encode( $body ) ), 0, 16 ) );
	}

	/**
	 * Check, approve and apply as far as the settings ask.
	 *
	 * @param array<string,mixed> $note       The credit note as Xero last returned it.
	 * @param array<string,mixed> $invoice    The refunded order's invoice.
	 * @param XeroRefundPayload   $parsed     Checked payload.
	 * @param bool                $createdNow True when this pass created the credit note.
	 * @param array<int,string>   $warnings   Receives notes.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	private function finish( array $note, array $invoice, XeroRefundPayload $parsed, bool $createdNow, array &$warnings ): array|ConnectorError {
		$id     = XeroPayloadReader::text( $note['CreditNoteID'] ?? '' );
		$status = (string) ( $note['Status'] ?? '' );
		$state  = $this->options->choice( 'refund_state', array( 'draft', 'authorised', 'authorised_allocate' ), 'draft' );
		if ( in_array( $status, array( 'DRAFT', 'SUBMITTED' ), true ) ) {
			$problem = $this->totals->check( $note, strtoupper( XeroPayloadReader::text( $invoice['CurrencyCode'] ?? '' ) ), $parsed->amountMinor );
			if ( null !== $problem ) {
				if ( 'warn' !== $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
					if ( $createdNow && 'DRAFT' === $status ) {
						$this->discard( $id );
					}
					return $problem;
				}
				$warnings[] = (string) ( $problem->context['reason'] ?? '' );
			}
			if ( 'draft' !== $state ) {
				$note   = $this->invoices->setCreditNoteStatus( $id, 'AUTHORISED' );
				$status = (string) ( $note['Status'] ?? '' );
			}
		}
		if ( 'authorised_allocate' === $state && 'AUTHORISED' === $status ) {
			$this->allocate( $note, $invoice, $parsed );
			$note = $this->invoices->creditNote( $id ) ?? $note;
		}
		return $note;
	}

	/**
	 * Apply the credit to the invoice's open amount, once.
	 *
	 * @param array<string,mixed> $note    Approved credit note.
	 * @param array<string,mixed> $invoice The order's invoice.
	 * @param XeroRefundPayload   $parsed  Checked payload.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function allocate( array $note, array $invoice, XeroRefundPayload $parsed ): void {
		$invoiceId = XeroPayloadReader::text( $invoice['InvoiceID'] ?? '' );
		$currency  = strtoupper( XeroPayloadReader::text( $invoice['CurrencyCode'] ?? '' ) );
		$currency  = '' !== $currency ? $currency : 'USD';
		foreach ( (array) ( $note['Allocations'] ?? array() ) as $allocation ) {
			if ( is_array( $allocation ) && XeroPayloadReader::text( $allocation['Invoice']['InvoiceID'] ?? '' ) === $invoiceId ) {
				return;
			}
		}
		$fresh = $this->invoices->invoice( $invoiceId ) ?? $invoice;
		if ( ! in_array( (string) ( $fresh['Status'] ?? '' ), array( 'AUTHORISED' ), true ) ) {
			return;
		}
		$open   = $this->amounts->fromNumber( $fresh['AmountDue'] ?? null, $currency );
		$credit = $this->amounts->fromNumber( $note['RemainingCredit'] ?? ( $note['Total'] ?? null ), $currency );
		if ( null === $open || null === $credit || $open <= 0 || $credit <= 0 ) {
			return;
		}
		$amount = min( $open, $credit );
		$day    = '' !== $parsed->date ? $parsed->date : gmdate( 'Y-m-d' );
		$key    = 'wc-' . $this->connectionId . '-' . $parsed->clientRef . '-allocation-' . $amount;
		$this->invoices->allocate( XeroPayloadReader::text( $note['CreditNoteID'] ?? '' ), $invoiceId, $this->amounts->number( $amount, $currency ), $day, $key );
	}

	/**
	 * Remove a draft this pass made.
	 *
	 * @param string $id CreditNoteID.
	 */
	private function discard( string $id ): void {
		try {
			$this->invoices->setCreditNoteStatus( $id, 'DELETED' );
		} catch ( ApiException $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Whether a credit note has the steps the settings ask for.
	 *
	 * @param array<string,mixed> $note Credit note.
	 */
	private function finished( array $note ): bool {
		$status = (string) ( $note['Status'] ?? '' );
		$state  = $this->options->choice( 'refund_state', array( 'draft', 'authorised', 'authorised_allocate' ), 'draft' );
		if ( 'draft' === $state ) {
			return in_array( $status, array( 'DRAFT', 'SUBMITTED', 'AUTHORISED', 'PAID' ), true );
		}
		if ( ! in_array( $status, array( 'AUTHORISED', 'PAID' ), true ) ) {
			return false;
		}
		if ( 'authorised' === $state ) {
			return true;
		}
		return 'PAID' === $status || ! empty( $note['Allocations'] );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $note     Credit note.
	 * @param array<int,string>   $warnings Notes.
	 */
	private function reference( array $note, array $warnings ): ExternalRef {
		$currency = strtoupper( XeroPayloadReader::text( $note['CurrencyCode'] ?? '' ) );
		$extra    = array(
			'currency' => $currency,
			'status'   => (string) ( $note['Status'] ?? '' ),
		);
		$total    = $this->amounts->fromNumber( $note['Total'] ?? null, '' !== $currency ? $currency : 'USD' );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		$warnings = array_values( array_filter( $warnings, static fn( string $warning ): bool => '' !== $warning ) );
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		$number = XeroPayloadReader::text( $note['CreditNoteNumber'] ?? '' );
		return new ExternalRef( XeroPayloadReader::text( $note['CreditNoteID'] ?? '' ), '' !== $number ? $number : XeroPayloadReader::text( $note['Reference'] ?? '' ), null, $extra );
	}

	/**
	 * The failure for a credit note that was voided in Xero.
	 *
	 * @param string $reference Reference.
	 */
	private function voided( string $reference ): ConnectorError {
		return XeroErrors::refused(
			'CreditNote',
			sprintf(
				/* translators: %s: the shop's reference of the refund, such as WC-77. */
				__( 'The credit note for %s was voided in Xero. Restore it there, or delete the voided credit note, then retry.', 'estasoft-enterprise-erpcrm-sync-engine' ),
				$reference
			)
		);
	}
}
