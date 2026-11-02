<?php
/**
 * Sends a refund to MYOB as a credit invoice.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * MYOB has no credit note endpoint on its sale pages. A credit is an item invoice with negative quantities and positive prices, and MYOB reports
 * it with the status Credit. The connector creates it, reads it back, checks the status and the total, and deletes it when either is wrong. The credit is
 * not applied to the original invoice; the person does that in MYOB.
 */
final class MyobRefundSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param MyobInvoices       $invoices Invoices.
	 * @param MyobLookups        $lookups  Tax codes, accounts and items.
	 * @param MyobCompany        $company  Company file facts.
	 * @param MyobInvoiceBuilder $builder  Body builder.
	 * @param MyobTotals         $totals   Total check.
	 * @param MyobAmounts        $amounts  Conversion helper.
	 * @param MyobOptions        $options  Connection options.
	 */
	public function __construct(
		private readonly MyobInvoices $invoices,
		private readonly MyobLookups $lookups,
		private readonly MyobCompany $company,
		private readonly MyobInvoiceBuilder $builder,
		private readonly MyobTotals $totals,
		private readonly MyobAmounts $amounts,
		private readonly MyobOptions $options
	) {
	}

	/**
	 * The credit invoice of a refund.
	 *
	 * @param Item $item The queue item.
	 * @return ExternalRef|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function find( Item $item ): ?ExternalRef {
		$credit = $this->invoices->find( $item->clientReference() );
		return null === $credit ? null : $this->reference( $credit, array() );
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
		$parsed = MyobRefundPayload::parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		$existing = $this->invoices->find( $parsed->clientRef );
		if ( null !== $existing ) {
			return SendResult::existing( $this->reference( $existing, array() ) );
		}
		$invoice = $this->invoices->find( $parsed->orderRef );
		if ( null === $invoice ) {
			return SendResult::failure(
				MyobErrors::refused(
					'Invoice',
					sprintf(
						/* translators: %s: the shop's reference of the order, such as WC-1042. */
						__( 'The invoice %s was not found in MYOB, so the refund cannot be linked to it.', 'estasoft-erp-crm-sync-engine' ),
						$parsed->orderRef
					)
				)
			);
		}
		$warnings = array();
		$made     = $this->create( $parsed, $invoice, $warnings );
		return $made instanceof ConnectorError ? SendResult::failure( $made ) : SendResult::created( $this->reference( $made, $warnings ) );
	}

	/**
	 * Create the credit invoice and check it.
	 *
	 * @param MyobRefundPayload   $parsed   Checked payload.
	 * @param array<string,mixed> $invoice  The refunded order's invoice.
	 * @param array<int,string>   $warnings Receives notes.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	private function create( MyobRefundPayload $parsed, array $invoice, array &$warnings ): array|ConnectorError {
		$currency = $this->company->currency();
		$codes    = array();
		$noTax    = false;
		$skus     = array();
		foreach ( $parsed->lines as $line ) {
			if ( null !== $line['tax'] && '' !== $line['tax'] ) {
				$codes[] = (string) $line['tax'];
			}
			$noTax = $noTax || '' === $line['tax'];
			if ( '' !== $line['sku'] ) {
				$skus[] = (string) $line['sku'];
			}
		}
		$tax = array();
		foreach ( array_unique( $codes ) as $code ) {
			$uid = $this->lookups->taxUid( $code );
			if ( '' === $uid ) {
				return MyobErrors::unknownTax( $code );
			}
			$tax[ strtolower( $code ) ] = $uid;
		}
		$items = $this->lookups->items( $skus );
		$named = array();
		$base  = $this->options->itemFor( 'generic' );
		if ( '' !== $base ) {
			$named['generic'] = $this->lookups->item( $base );
		}
		$body = $this->builder->credit( $parsed, $invoice, $currency, $items, $named, $tax, $noTax ? $this->lookups->taxUid( 'N-T' ) : '', $warnings );
		if ( $body instanceof ConnectorError ) {
			return $body;
		}
		$credit = $this->invoices->create( $body, $parsed->clientRef );
		if ( ! MyobInvoices::isCredit( $credit ) ) {
			$this->discard( $credit );
			return MyobErrors::refused(
				'Status',
				sprintf(
					/* translators: %s: the status MYOB reported, such as Open. */
					__( 'MYOB did not make a credit (its status is "%s"). The document was removed. Check the credit in MYOB by hand.', 'estasoft-erp-crm-sync-engine' ),
					MyobPayloadReader::text( $credit['Status'] ?? '' )
				)
			);
		}
		$problem = $this->totals->check( $credit, $currency, $parsed->amountMinor );
		if ( null === $problem ) {
			return $credit;
		}
		if ( 'warn' === $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
			$warnings[] = (string) ( $problem->context['reason'] ?? '' );
			return $credit;
		}
		$this->discard( $credit );
		return $problem;
	}

	/**
	 * Delete a credit this pass found wrong; a failure to do so is not worse than leaving it.
	 *
	 * @param array<string,mixed> $credit Credit invoice.
	 */
	private function discard( array $credit ): void {
		try {
			$this->invoices->delete( $credit );
		} catch ( ApiException $exception ) {
			unset( $exception );
		}
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $credit   Credit invoice.
	 * @param array<int,string>   $warnings Notes.
	 */
	private function reference( array $credit, array $warnings ): ExternalRef {
		$currency = $this->company->currency();
		$extra    = array( 'currency' => $currency );
		$total    = $this->amounts->fromNumber( $credit['TotalAmount'] ?? null, $currency );
		if ( null !== $total ) {
			$extra['total_minor'] = abs( $total );
		}
		$status = MyobPayloadReader::text( $credit['Status'] ?? '' );
		if ( '' !== $status ) {
			$extra['status'] = $status;
		}
		$warnings = array_values( array_filter( $warnings, static fn( string $warning ): bool => '' !== $warning ) );
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( MyobPayloadReader::text( $credit['UID'] ?? '' ), MyobPayloadReader::text( $credit['Number'] ?? '' ), null, $extra );
	}
}
