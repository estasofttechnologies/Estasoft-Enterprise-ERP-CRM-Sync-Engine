<?php
/**
 * Sends an order to QuickBooks as an invoice, one resumable step at a time.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * A pass starts from a read and does only what is missing. QuickBooks posts an invoice when it is created, so the invoice is made with an
 * "[unchecked]" note, its currency and total are read back, and only then is the note cleaned; a wrong invoice made by this pass is deleted. An invoice
 * that still has the marker (an answer was lost, or the pass stopped) is never counted as finished: the next pass checks it. Create and payment run once per
 * pass; a failure that may have reached QuickBooks ends the pass.
 */
final class QuickBooksOrderSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QuickBooksDocuments       $documents    Documents.
	 * @param QuickBooksCustomers       $customers    Customers.
	 * @param QuickBooksItems           $items        Items.
	 * @param QuickBooksCompany         $company      Company facts.
	 * @param QuickBooksDocumentBuilder $builder      Body builder.
	 * @param QuickBooksTotals          $totals       Total check.
	 * @param QuickBooksAmounts         $amounts      Conversion helper.
	 * @param QuickBooksOptions         $options      Connection options.
	 * @param int                       $connectionId Connection id, for request ids.
	 */
	public function __construct(
		private readonly QuickBooksDocuments $documents,
		private readonly QuickBooksCustomers $customers,
		private readonly QuickBooksItems $items,
		private readonly QuickBooksCompany $company,
		private readonly QuickBooksDocumentBuilder $builder,
		private readonly QuickBooksTotals $totals,
		private readonly QuickBooksAmounts $amounts,
		private readonly QuickBooksOptions $options,
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
		$invoice = $this->documents->findInvoice( $item->clientReference() );
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
		$parsed = QuickBooksOrderPayload::parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		$warnings = array();
		$invoice  = $this->documents->findInvoice( $parsed->clientRef );
		if ( null !== $invoice && ! empty( $invoice['voided'] ) ) {
			return SendResult::failure( $this->voided( $parsed->clientRef ) );
		}
		$createdNow = false;
		if ( null === $invoice ) {
			$made = $this->create( $parsed, $warnings );
			if ( $made instanceof ConnectorError ) {
				return SendResult::failure( $made );
			}
			list( $invoice, $createdNow ) = $made;
		}
		$done = $this->finish( $invoice, $parsed, $warnings );
		if ( $done instanceof ConnectorError ) {
			return SendResult::failure( $done );
		}
		$ref = $this->reference( $done, $warnings );
		return $createdNow ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Create the invoice after everything it needs has been resolved.
	 *
	 * @param QuickBooksOrderPayload $parsed   Checked payload.
	 * @param array<int,string>      $warnings Receives notes.
	 * @return array{0:array<string,mixed>,1:bool}|ConnectorError The invoice and whether this pass made it.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function create( QuickBooksOrderPayload $parsed, array &$warnings ): array|ConnectorError {
		if ( ! $this->company->customNumbers() ) {
			return QuickBooksErrors::refused(
				'DocNumber',
				__( 'Turn on Custom transaction numbers in QuickBooks (Account and settings, Sales, Sales form content). Without it QuickBooks does not keep the shop reference and a retry could make a second invoice.', 'estasoft-erp-crm-sync-engine' )
			);
		}
		$home     = $this->company->homeCurrency();
		$currency = '' !== $parsed->currency ? $parsed->currency : $home;
		if ( $currency !== $home && ! $this->company->multiCurrency() ) {
			return QuickBooksErrors::refused(
				'CurrencyRef',
				sprintf(
					/* translators: 1: order currency, 2: home currency of the company. */
					__( 'The order is in %1$s but the company works in %2$s and multicurrency is off in QuickBooks.', 'estasoft-erp-crm-sync-engine' ),
					$currency,
					$home
				)
			);
		}
		$us = $this->company->isUs();
		if ( $us && $parsed->pricesIncludeTax ) {
			return QuickBooksErrors::refused( 'prices_include_tax', __( 'QuickBooks US adds sales tax on top of prices, so prices that include tax cannot be sent. Send prices without tax.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$skus = array();
		foreach ( $parsed->lines as $line ) {
			if ( '' !== $line['sku'] ) {
				$skus[] = (string) $line['sku'];
			}
		}
		$found = $this->items->bySkus( $skus );
		$kinds = array_map( static fn( array $extra ): string => (string) $extra['kind'], $parsed->extras );
		if ( 'free_text' === $this->options->choice( 'unknown_sku', array( 'refuse', 'free_text' ), 'refuse' ) && count( array_unique( $skus ) ) > count( $found ) ) {
			$kinds[] = 'generic';
		}
		$named = $this->items->forKinds( $kinds, $this->options );
		$body  = $this->builder->invoice( $parsed, '', $currency, $home, $us, $found, $named, $warnings );
		if ( $body instanceof ConnectorError ) {
			return $body;
		}
		$customer            = $this->customers->resolve( $parsed->partner, $parsed->delivery, $warnings );
		$body['CustomerRef'] = array( 'value' => QuickBooksPayloadReader::text( $customer['Id'] ?? '' ) );
		try {
			return array( $this->documents->create( 'invoice', $body, $this->requestId( $parsed->clientRef, 'invoice', $parsed->attempt, $body ) ), true );
		} catch ( ApiException $exception ) {
			if ( ! QuickBooksErrors::has( $exception->response(), '6140' ) ) {
				throw $exception;
			}
			$existing = $this->documents->findInvoice( $parsed->clientRef );
			if ( null === $existing || ! empty( $existing['voided'] ) ) {
				throw $exception;
			}
			return array( $existing, false );
		}
	}

	/**
	 * Check, mark as checked and pay as far as the settings ask.
	 *
	 * @param array<string,mixed>    $invoice  The invoice as QuickBooks last returned it.
	 * @param QuickBooksOrderPayload $parsed   Checked payload.
	 * @param array<int,string>      $warnings Receives notes.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	private function finish( array $invoice, QuickBooksOrderPayload $parsed, array &$warnings ): array|ConnectorError {
		if ( QuickBooksDocuments::isUnchecked( $invoice ) ) {
			$problem = $this->totals->check( $invoice, $parsed->currency, $this->company->homeCurrency(), $parsed->totalMinor );
			if ( null !== $problem ) {
				if ( 'warn' !== $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
					$this->discard( 'invoice', $invoice );
					return $problem;
				}
				$warnings[] = (string) ( $problem->context['reason'] ?? '' );
			}
			$invoice = $this->documents->markChecked( 'invoice', $invoice );
		}
		if ( $this->options->pays() && '' !== $parsed->paymentDate ) {
			$invoice = $this->pay( $invoice, $parsed );
		}
		return $invoice;
	}

	/**
	 * Record the payment, once, for the balance.
	 *
	 * @param array<string,mixed>    $invoice The invoice.
	 * @param QuickBooksOrderPayload $parsed  Checked payload.
	 * @return array<string,mixed> The invoice after the payment.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function pay( array $invoice, QuickBooksOrderPayload $parsed ): array {
		$currency = strtoupper( QuickBooksPayloadReader::text( $invoice['CurrencyRef']['value'] ?? '' ) );
		$currency = '' !== $currency ? $currency : $this->company->homeCurrency();
		$balance  = $this->amounts->fromNumber( $invoice['Balance'] ?? null, $currency );
		if ( null === $balance || $balance <= 0 ) {
			return $invoice;
		}
		$id      = QuickBooksPayloadReader::text( $invoice['Id'] ?? '' );
		$payment = array(
			'CustomerRef'   => array( 'value' => QuickBooksPayloadReader::text( $invoice['CustomerRef']['value'] ?? '' ) ),
			'TotalAmt'      => $this->amounts->number( $balance, $currency ),
			'TxnDate'       => $parsed->paymentDate,
			'PaymentRefNum' => QuickBooksPayloadReader::limit( $parsed->clientRef, 21 ),
			'Line'          => array(
				array(
					'Amount'    => $this->amounts->number( $balance, $currency ),
					'LinkedTxn' => array(
						array(
							'TxnId'   => $id,
							'TxnType' => 'Invoice',
						),
					),
				),
			),
		);
		$deposit = $this->options->text( 'deposit_account' );
		if ( '' !== $deposit ) {
			$payment['DepositToAccountRef'] = array( 'value' => $deposit );
		}
		$this->documents->pay( $payment, $this->requestId( $parsed->clientRef, 'payment', $parsed->attempt, $payment ) );
		return $this->documents->invoice( $id ) ?? $invoice;
	}

	/**
	 * Delete a document this pass found wrong; a failure to do so is not worse than leaving it, and the next pass checks it again.
	 *
	 * @param string              $entity   invoice or creditmemo.
	 * @param array<string,mixed> $document The document.
	 */
	private function discard( string $entity, array $document ): void {
		try {
			$this->documents->delete( $entity, $document );
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
		if ( QuickBooksDocuments::isUnchecked( $invoice ) ) {
			return false;
		}
		if ( $this->options->pays() ) {
			return is_numeric( $invoice['Balance'] ?? null ) && 0.0 === (float) $invoice['Balance'];
		}
		return true;
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $invoice  Invoice.
	 * @param array<int,string>   $warnings Notes.
	 */
	private function reference( array $invoice, array $warnings ): ExternalRef {
		$currency = strtoupper( QuickBooksPayloadReader::text( $invoice['CurrencyRef']['value'] ?? '' ) );
		$currency = '' !== $currency ? $currency : $this->company->homeCurrency();
		$extra    = array( 'currency' => $currency );
		$total    = $this->amounts->fromNumber( $invoice['TotalAmt'] ?? null, '' !== $currency ? $currency : 'USD' );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		$balance = $this->amounts->fromNumber( $invoice['Balance'] ?? null, '' !== $currency ? $currency : 'USD' );
		if ( null !== $balance ) {
			$extra['balance_minor'] = $balance;
		}
		$warnings = array_values( array_filter( $warnings, static fn( string $warning ): bool => '' !== $warning ) );
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		$number = QuickBooksPayloadReader::text( $invoice['DocNumber'] ?? '' );
		return new ExternalRef( QuickBooksPayloadReader::text( $invoice['Id'] ?? '' ), $number, null, $extra );
	}

	/**
	 * The failure for an invoice that was voided in QuickBooks.
	 *
	 * @param string $reference Reference.
	 */
	private function voided( string $reference ): ConnectorError {
		return QuickBooksErrors::refused(
			'Invoice',
			sprintf(
				/* translators: %s: the shop's reference of the order, such as WC-1042. */
				__( 'The invoice %s was voided in QuickBooks. Delete the voided invoice there, then retry.', 'estasoft-erp-crm-sync-engine' ),
				$reference
			)
		);
	}

	/**
	 * A requestid: the same body in the same try replays the first answer; a corrected body, or the next try, gets a new id. The document number is what
	 * guards against duplicates across tries, so a try never receives the answer about an invoice it deleted. At most 50 characters.
	 *
	 * @param string              $reference Client reference.
	 * @param string              $step      invoice or payment.
	 * @param int                 $attempt   Tries the queue made so far.
	 * @param array<string,mixed> $body      Request body.
	 */
	private function requestId( string $reference, string $step, int $attempt, array $body ): string {
		return 'wc-' . substr( sha1( $this->connectionId . '|' . $reference . '|' . $step . '|' . $attempt . '|' . (string) wp_json_encode( $body ) ), 0, 40 );
	}
}
