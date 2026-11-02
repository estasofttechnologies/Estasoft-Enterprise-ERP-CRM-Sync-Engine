<?php
/**
 * Sends an order to Xero as a sales invoice, one resumable step at a time.
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
 * A pass starts from a read and does only what is missing. Create and payment run once per pass; a failure that may have reached Xero
 * ends the pass with that error, and the next pass reads again and carries on from what it finds. Approving is safe to repeat. The invoice
 * is created as a draft and approved only after its currency and total have been read back and agree with the shop.
 */
final class XeroOrderSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param XeroInvoices        $invoices     Documents.
	 * @param XeroContacts        $contacts     Contacts.
	 * @param XeroItems           $items        Items.
	 * @param XeroOrganisation    $organisation Organisation facts.
	 * @param XeroDocumentBuilder $builder      Body builder.
	 * @param XeroTotals          $totals       Total check.
	 * @param XeroAmounts         $amounts      Conversion helper.
	 * @param XeroOptions         $options      Connection options.
	 * @param int                 $connectionId Connection id, for idempotency keys.
	 */
	public function __construct(
		private readonly XeroInvoices $invoices,
		private readonly XeroContacts $contacts,
		private readonly XeroItems $items,
		private readonly XeroOrganisation $organisation,
		private readonly XeroDocumentBuilder $builder,
		private readonly XeroTotals $totals,
		private readonly XeroAmounts $amounts,
		private readonly XeroOptions $options,
		private readonly int $connectionId
	) {
	}

	/**
	 * The invoice of an order, when it is finished for the connection's settings.
	 *
	 * @param Item $item The queue item.
	 * @return ExternalRef|ConnectorError|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function find( Item $item ): ExternalRef|ConnectorError|null {
		$invoice = $this->invoices->findInvoice( $item->clientReference() );
		if ( null === $invoice ) {
			return null;
		}
		if ( ! empty( $invoice['voided'] ) ) {
			return $this->voided( $item->clientReference() );
		}
		return $this->finished( $invoice ) ? $this->reference( $invoice, array() ) : null;
	}

	/**
	 * Send the order.
	 *
	 * @param Item                $item    The queue item.
	 * @param array<string,mixed> $payload Mapped payload.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function send( Item $item, array $payload ): SendResult {
		$parsed = XeroOrderPayload::parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		$warnings = array();
		$invoice  = $this->invoices->findInvoice( $parsed->clientRef );
		if ( null !== $invoice && ! empty( $invoice['voided'] ) ) {
			return SendResult::failure( $this->voided( $parsed->clientRef ) );
		}
		$createdNow = false;
		if ( null === $invoice ) {
			$invoice = $this->create( $parsed, $warnings );
			if ( $invoice instanceof ConnectorError ) {
				return SendResult::failure( $invoice );
			}
			$createdNow = true;
		}
		$done = $this->finish( $invoice, $parsed, $createdNow, $warnings );
		if ( $done instanceof ConnectorError ) {
			return SendResult::failure( $done );
		}
		$ref = $this->reference( $done, $warnings );
		return $createdNow ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Create the draft invoice after everything it needs has been resolved.
	 *
	 * @param XeroOrderPayload  $parsed   Checked payload.
	 * @param array<int,string> $warnings Receives notes.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	private function create( XeroOrderPayload $parsed, array &$warnings ): array|ConnectorError {
		$base     = $this->organisation->baseCurrency();
		$currency = '' !== $parsed->currency ? $parsed->currency : $base;
		if ( ! $this->organisation->currencyEnabled( $currency ) ) {
			return XeroErrors::refused(
				'CurrencyCode',
				sprintf(
					/* translators: %s: currency code. */
					__( 'The currency %s is not enabled in Xero. Add it under Settings, Currencies.', 'estasoft-erp-crm-sync-engine' ),
					$currency
				)
			);
		}
		$skus = array();
		foreach ( $parsed->lines as $line ) {
			if ( '' !== $line['sku'] ) {
				$skus[] = (string) $line['sku'];
			}
		}
		$found = $this->items->byCodes( $skus );
		$body  = $this->builder->invoice( $parsed, '', $currency, $found, $warnings );
		if ( $body instanceof ConnectorError ) {
			return $body;
		}
		$contact         = $this->contacts->resolve( $parsed->partner, $parsed->delivery, '', $warnings );
		$body['Contact'] = array( 'ContactID' => XeroPayloadReader::text( $contact['ContactID'] ?? '' ) );
		return $this->invoices->createInvoice( $body, $this->key( $parsed->clientRef, 'invoice', $this->invoices->deadIds( $parsed->clientRef ), $body ), $this->options->flag( 'allow_backorders', true ) );
	}

	/**
	 * Check, approve and pay as far as the settings ask.
	 *
	 * @param array<string,mixed> $invoice    The invoice as Xero last returned it.
	 * @param XeroOrderPayload    $parsed     Checked payload.
	 * @param bool                $createdNow True when this pass created the invoice.
	 * @param array<int,string>   $warnings   Receives notes.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	private function finish( array $invoice, XeroOrderPayload $parsed, bool $createdNow, array &$warnings ): array|ConnectorError {
		$id     = XeroPayloadReader::text( $invoice['InvoiceID'] ?? '' );
		$status = (string) ( $invoice['Status'] ?? '' );
		if ( in_array( $status, array( 'DRAFT', 'SUBMITTED' ), true ) ) {
			$problem = $this->totals->check( $invoice, $parsed->currency, $parsed->totalMinor );
			if ( null !== $problem ) {
				if ( 'warn' !== $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
					$this->discard( $id, $createdNow && 'DRAFT' === $status );
					return $problem;
				}
				$warnings[] = $problem->context['reason'] ?? '';
			}
			if ( $this->options->approves() ) {
				$invoice  = $this->invoices->setInvoiceStatus( $id, 'AUTHORISED' );
				$status   = (string) ( $invoice['Status'] ?? '' );
				$expected = $parsed->date;
				$actual   = substr( XeroPayloadReader::text( $invoice['DateString'] ?? '' ), 0, 10 );
				if ( '' !== $expected && '' !== $actual && $actual !== $expected ) {
					$warnings[] = __( 'Xero changed the invoice date when it approved the invoice.', 'estasoft-erp-crm-sync-engine' );
				}
			}
		}
		if ( $this->options->pays() && '' !== $parsed->paymentDate && 'AUTHORISED' === $status ) {
			$paid = $this->pay( $invoice, $parsed );
			if ( $paid instanceof ConnectorError ) {
				return $paid;
			}
			$invoice = $paid;
		}
		return $invoice;
	}

	/**
	 * Record the payment of an approved invoice, once.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 * @param XeroOrderPayload    $parsed  Checked payload.
	 * @return array<string,mixed>|ConnectorError The invoice after the payment.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function pay( array $invoice, XeroOrderPayload $parsed ): array|ConnectorError {
		$account = $this->options->text( 'payment_account' );
		if ( '' === $account ) {
			return XeroErrors::refused( 'payment_account', __( 'Set the Xero account code that receives payments in the connection option "payment_account".', 'estasoft-erp-crm-sync-engine' ) );
		}
		$id        = XeroPayloadReader::text( $invoice['InvoiceID'] ?? '' );
		$currency  = strtoupper( XeroPayloadReader::text( $invoice['CurrencyCode'] ?? '' ) );
		$currency  = '' !== $currency ? $currency : $this->organisation->baseCurrency();
		$due       = $this->amounts->fromNumber( $invoice['AmountDue'] ?? null, $currency );
		$reference = '' !== $parsed->paymentReference ? $parsed->paymentReference : $parsed->clientRef;
		foreach ( (array) ( $invoice['Payments'] ?? array() ) as $payment ) {
			if ( is_array( $payment ) && ( $payment['Reference'] ?? '' ) === $reference ) {
				return $invoice;
			}
		}
		if ( null === $due || $due <= 0 ) {
			return $invoice;
		}
		$payment = array(
			'Invoice'   => array( 'InvoiceID' => $id ),
			'Account'   => array( 'Code' => $account ),
			'Date'      => $parsed->paymentDate,
			'Amount'    => $this->amounts->number( $due, $currency ),
			'Reference' => XeroPayloadReader::limit( $reference, 255 ),
		);
		$this->invoices->createPayment( $payment, $this->key( $parsed->clientRef, 'payment', array(), $payment ) );
		return $this->invoices->invoice( $id ) ?? $invoice;
	}

	/**
	 * Remove a draft this pass made, so a wrong document does not stay behind. A failure here is not worse than leaving it.
	 *
	 * @param string $id     InvoiceID.
	 * @param bool   $remove True when the draft may be removed.
	 */
	private function discard( string $id, bool $remove ): void {
		if ( ! $remove || '' === $id ) {
			return;
		}
		try {
			$this->invoices->setInvoiceStatus( $id, 'DELETED' );
		} catch ( ApiException $exception ) {
			unset( $exception );
		}
	}

	/**
	 * Whether an invoice has all the steps the connection's settings ask for.
	 *
	 * @param array<string,mixed> $invoice Invoice.
	 */
	private function finished( array $invoice ): bool {
		$status = (string) ( $invoice['Status'] ?? '' );
		if ( $this->options->pays() ) {
			return 'PAID' === $status;
		}
		if ( $this->options->approves() ) {
			return in_array( $status, array( 'AUTHORISED', 'PAID' ), true );
		}
		return in_array( $status, array( 'DRAFT', 'SUBMITTED', 'AUTHORISED', 'PAID' ), true );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $invoice  Invoice.
	 * @param array<int,string>   $warnings Notes.
	 */
	private function reference( array $invoice, array $warnings ): ExternalRef {
		$currency = strtoupper( XeroPayloadReader::text( $invoice['CurrencyCode'] ?? '' ) );
		$extra    = array(
			'currency' => $currency,
			'status'   => (string) ( $invoice['Status'] ?? '' ),
		);
		$total    = $this->amounts->fromNumber( $invoice['Total'] ?? null, '' !== $currency ? $currency : 'USD' );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		$warnings = array_values( array_filter( $warnings, static fn( string $warning ): bool => '' !== $warning ) );
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		$number = XeroPayloadReader::text( $invoice['InvoiceNumber'] ?? '' );
		return new ExternalRef( XeroPayloadReader::text( $invoice['InvoiceID'] ?? '' ), '' !== $number ? $number : XeroPayloadReader::text( $invoice['Reference'] ?? '' ), null, $extra );
	}

	/**
	 * The failure for an invoice that was voided in Xero.
	 *
	 * @param string $reference Reference.
	 */
	private function voided( string $reference ): ConnectorError {
		return XeroErrors::refused(
			'Invoice',
			sprintf(
				/* translators: %s: the shop's reference of the order, such as WC-1042. */
				__( 'The invoice for %s was voided in Xero. Restore it there, or delete the voided invoice, then retry.', 'estasoft-erp-crm-sync-engine' ),
				$reference
			)
		);
	}

	/**
	 * An idempotency key from the reference, the step and the body. A retry of the same request gets the same key and so the first answer (Xero references are not
	 * unique, so a create that finished late must not be made twice); the key changes when the body is corrected, and when the connector itself deleted a draft with this
	 * reference (the ids of the deleted drafts are part of the key, so the new create is not answered with the deleted draft).
	 *
	 * @param string              $reference Client reference.
	 * @param string              $step      invoice or payment.
	 * @param array<int,string>   $dead      Ids of deleted documents with this reference.
	 * @param array<string,mixed> $body      Request body.
	 */
	private function key( string $reference, string $step, array $dead, array $body ): string {
		return 'wc-' . $this->connectionId . '-' . $reference . '-' . $step . '-' . substr( sha1( implode( ',', $dead ) . '|' . (string) wp_json_encode( $body ) ), 0, 16 );
	}
}
