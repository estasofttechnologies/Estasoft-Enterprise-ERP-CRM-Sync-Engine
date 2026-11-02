<?php
/**
 * Sends a refund to Sage as a sales credit note.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A refund finds the order's invoice, takes its contact, and creates a sales credit note with the reference WC-{refund id}. Sage has no draft credit note, so the total is
 * read back at once and a wrong credit note made by this pass is voided. With the option refund_allocation the credit is then applied to the invoice, as a separate step
 * that every pass repeats only when it is still missing.
 */
final class SageRefundSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param SageInvoices $invoices     Invoices and credit notes.
	 * @param SageProducts $products     Products.
	 * @param SageBusiness $business     Business facts.
	 * @param SageBuilder  $builder      Body builder.
	 * @param SageTotals   $totals       Total check.
	 * @param SageAmounts  $amounts      Conversion helper.
	 * @param SageOptions  $options      Connection options.
	 * @param int          $connectionId Connection id, for idempotency ids.
	 * @param string       $country      Country of the sign-in (for messages).
	 */
	public function __construct(
		private readonly SageInvoices $invoices,
		private readonly SageProducts $products,
		private readonly SageBusiness $business,
		private readonly SageBuilder $builder,
		private readonly SageTotals $totals,
		private readonly SageAmounts $amounts,
		private readonly SageOptions $options,
		private readonly int $connectionId,
		private readonly string $country
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
		if ( $this->options->allocates() && $this->openAmount( $note ) > 0 && $this->canAllocate( $note ) ) {
			return null;
		}
		return $this->reference( $note, array() );
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
		$parsed = SageRefundPayload::parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		if ( $parsed->pricesIncludeTax ) {
			return SendResult::failure( SageErrors::refused( 'prices_include_tax', __( 'Sage takes amounts without tax and works the tax out from the tax rate. Send amounts without tax.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		if ( '' === $this->options->text( 'sales_ledger_account' ) ) {
			return SendResult::failure( SageErrors::refused( 'sales_ledger_account', __( 'Set the nominal code of an income account in the connection option "sales_ledger_account": Sage needs a ledger account on every line.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$warnings = array();
		$note     = $this->invoices->findCreditNote( $parsed->clientRef );
		if ( null !== $note && ! empty( $note['voided'] ) ) {
			return SendResult::failure( $this->voided( $parsed->clientRef ) );
		}
		$invoice = $this->invoices->findInvoice( $parsed->orderRef );
		if ( null === $invoice || ! empty( $invoice['voided'] ) ) {
			return SendResult::failure(
				SageErrors::refused(
					'sales_invoice',
					sprintf(
						/* translators: %s: the shop's reference of the order, such as WC-1042. */
						__( 'The invoice %s was not found in Sage, so the refund cannot be linked to it.', 'estasoft-erp-crm-sync-engine' ),
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
		if ( $this->options->allocates() && $this->openAmount( $note ) > 0 ) {
			$note = $this->allocate( $note, $invoice, $parsed );
		}
		$ref = $this->reference( $note, $warnings );
		return $createdNow ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Create the credit note and check it.
	 *
	 * @param SageRefundPayload   $parsed   Checked payload.
	 * @param array<string,mixed> $invoice  The refunded order's invoice.
	 * @param array<int,string>   $warnings Receives notes.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	private function create( SageRefundPayload $parsed, array $invoice, array &$warnings ): array|ConnectorError {
		$currency = $this->business->currency();
		$codes    = array();
		$none     = false;
		$skus     = array();
		foreach ( $parsed->lines as $line ) {
			if ( null !== $line['tax'] && '' !== $line['tax'] ) {
				$codes[] = (string) $line['tax'];
			}
			$none = $none || '' === $line['tax'] || ( in_array( $line['kind'] ?? '', array( 'shipping', 'amount' ), true ) && null === $line['tax'] );
			if ( '' !== $line['sku'] ) {
				$skus[] = (string) $line['sku'];
			}
		}
		$rates = array();
		foreach ( array_unique( $codes ) as $code ) {
			$rate = $this->business->taxRate( $code );
			if ( null === $rate ) {
				return SageErrors::unknownTax( strtoupper( $this->country ), $code );
			}
			$rates[ strtolower( $code ) ] = $rate;
		}
		if ( $none ) {
			$noTax = $this->business->noTaxRate( $this->options->text( 'no_tax_rate' ) );
			if ( null !== $noTax ) {
				$rates[''] = $noTax;
			}
		}
		$ledger = $this->business->ledgerId( $this->options->ledgerFor( 'sales' ) );
		if ( '' === $ledger ) {
			return SageErrors::refused(
				'sales_ledger_account',
				sprintf(
					/* translators: %s: a nominal code. */
					__( 'Sage has no ledger account "%s". Check the connection option "sales_ledger_account".', 'estasoft-erp-crm-sync-engine' ),
					$this->options->ledgerFor( 'sales' )
				)
			);
		}
		$body = $this->builder->creditNote( $parsed, $invoice, $currency, $this->products->byCodes( $skus ), $rates, array( 'sales' => $ledger ), $warnings );
		if ( $body instanceof ConnectorError ) {
			return $body;
		}
		$body['idempotency_id'] = md5( $this->connectionId . '|' . $parsed->clientRef . '|credit_note|' . $parsed->attempt . '|' . (string) wp_json_encode( $body ) );
		$note                   = $this->invoices->create( 'sales_credit_notes', 'sales_credit_note', $body );
		$problem                = $this->totals->check( $note, $currency, $parsed->amountMinor );
		if ( null === $problem ) {
			return $note;
		}
		if ( 'warn' === $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
			$warnings[] = (string) ( $problem->context['reason'] ?? '' );
			return $note;
		}
		$this->discard( SagePayloadReader::text( $note['id'] ?? '' ) );
		return $problem;
	}

	/**
	 * Apply the credit to the invoice for the smaller of the two open amounts.
	 *
	 * @param array<string,mixed> $note    The credit note.
	 * @param array<string,mixed> $invoice The invoice as found at the start of the pass.
	 * @param SageRefundPayload   $parsed  Checked payload.
	 * @return array<string,mixed> The credit note after the allocation.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function allocate( array $note, array $invoice, SageRefundPayload $parsed ): array {
		$currency = $this->business->currency();
		$fresh    = $this->invoices->get( 'sales_invoices', SagePayloadReader::text( $invoice['id'] ?? '' ) ) ?? $invoice;
		$left     = (int) $this->amounts->fromNumber( $fresh['outstanding_amount'] ?? null, $currency );
		$credit   = $this->openAmount( $note );
		$amount   = min( $left, $credit );
		if ( $amount <= 0 || ! in_array( SageInvoices::status( $fresh ), array( 'UNPAID', 'PART_PAID' ), true ) ) {
			return $note;
		}
		$allocation                   = array(
			'transaction_type_id' => 'CUSTOMER_ALLOCATION',
			'contact_id'          => SagePayloadReader::text( $note['contact']['id'] ?? '' ),
			'date'                => '' !== $parsed->date ? $parsed->date : SagePayloadReader::day( $note['date'] ?? '' ),
			'allocated_artefacts' => array(
				array(
					'artefact_id' => SagePayloadReader::text( $fresh['id'] ?? '' ),
					'amount'      => $this->amounts->text( $amount, $currency ),
				),
				array(
					'artefact_id' => SagePayloadReader::text( $note['id'] ?? '' ),
					'amount'      => $this->amounts->text( -$amount, $currency ),
				),
			),
		);
		$allocation['idempotency_id'] = md5( $this->connectionId . '|' . $parsed->clientRef . '|allocation|' . $parsed->attempt . '|' . (string) wp_json_encode( $allocation ) );
		$this->invoices->allocate( $allocation );
		return $this->invoices->get( 'sales_credit_notes', SagePayloadReader::text( $note['id'] ?? '' ) ) ?? $note;
	}

	/**
	 * Void a credit note this pass found wrong; a failure to do so is not worse than leaving it.
	 *
	 * @param string $id Credit note id.
	 */
	private function discard( string $id ): void {
		if ( '' === $id ) {
			return;
		}
		try {
			$this->invoices->remove( 'sales_credit_notes', $id );
		} catch ( ApiException $exception ) {
			unset( $exception );
		}
	}

	/**
	 * The amount of a credit note that is not yet applied, in minor units.
	 *
	 * @param array<string,mixed> $note Credit note.
	 */
	private function openAmount( array $note ): int {
		return (int) $this->amounts->fromNumber( $note['outstanding_amount'] ?? null, $this->business->currency() );
	}

	/**
	 * Whether the credit note is in a state that takes an allocation.
	 *
	 * @param array<string,mixed> $note Credit note.
	 */
	private function canAllocate( array $note ): bool {
		return in_array( SageInvoices::status( $note ), array( 'UNPAID', 'PART_PAID' ), true );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $note     Credit note.
	 * @param array<int,string>   $warnings Notes.
	 */
	private function reference( array $note, array $warnings ): ExternalRef {
		$currency = strtoupper( SagePayloadReader::text( $note['currency']['id'] ?? ( $note['currency'] ?? '' ) ) );
		$currency = '' !== $currency ? $currency : $this->business->currency();
		$extra    = array(
			'currency' => $currency,
			'status'   => SageInvoices::status( $note ),
		);
		$total    = $this->amounts->fromNumber( $note['total_amount'] ?? null, '' !== $currency ? $currency : 'GBP' );
		if ( null !== $total ) {
			$extra['total_minor'] = abs( $total );
		}
		$warnings = array_values( array_filter( $warnings, static fn( string $warning ): bool => '' !== $warning ) );
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		$number = SagePayloadReader::text( $note['credit_note_number'] ?? '' );
		return new ExternalRef( SagePayloadReader::text( $note['id'] ?? '' ), '' !== $number ? $number : SagePayloadReader::text( $note['reference'] ?? '' ), null, $extra );
	}

	/**
	 * The failure for a credit note that was voided in Sage.
	 *
	 * @param string $reference Reference.
	 */
	private function voided( string $reference ): ConnectorError {
		return SageErrors::refused(
			'sales_credit_note',
			sprintf(
				/* translators: %s: the shop's reference of the refund, such as WC-77. */
				__( 'The credit note %s was voided in Sage. Delete the voided credit note there, or change its reference, then retry.', 'estasoft-erp-crm-sync-engine' ),
				$reference
			)
		);
	}
}
