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
	 * Most creates in one pass when the answer keeps naming an invoice that was deleted.
	 */
	private const ROUNDS = 3;

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
			$body = $this->prepare( $parsed, $warnings );
			if ( $body instanceof ConnectorError ) {
				return SendResult::failure( $body );
			}
			$dead = array();
			$done = null;
			for ( $round = 0; $round < self::ROUNDS && null === $done; ++$round ) {
				list( $invoice, $createdNow ) = $this->post( $parsed, $body, $dead );
				$done                         = $this->finish( $invoice, $parsed, $warnings );
				if ( null === $done ) {
					$dead[] = QuickBooksPayloadReader::text( $invoice['Id'] ?? '' );
				}
			}
		} else {
			$done = $this->finish( $invoice, $parsed, $warnings );
		}
		if ( null === $done ) {
			return SendResult::failure( QuickBooksErrors::refused( 'Invoice', __( 'QuickBooks keeps answering with an invoice that no longer exists. Try again later.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		if ( $done instanceof ConnectorError ) {
			return SendResult::failure( $done );
		}
		$ref = $this->reference( $done, $warnings );
		return $createdNow ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Check everything the invoice needs, resolve the item and customer, and build the body.
	 *
	 * @param QuickBooksOrderPayload $parsed   Checked payload.
	 * @param array<int,string>      $warnings Receives notes.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	private function prepare( QuickBooksOrderPayload $parsed, array &$warnings ): array|ConnectorError {
		if ( ! $this->company->customNumbers() ) {
			return QuickBooksErrors::refused(
				'DocNumber',
				__( 'Turn on Custom transaction numbers in QuickBooks (Account and settings, Sales, Sales form content). Without it QuickBooks does not keep the shop reference and a retry could make a second invoice.', 'estasoft-erp-crm-sync-engine' )
			);
		}
		$home     = $this->company->homeCurrency();
		$multi    = $this->company->multiCurrency();
		$currency = '' !== $parsed->currency ? $parsed->currency : $home;
		if ( $currency !== $home && ! $multi ) {
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
		$body  = $this->builder->invoice( $parsed, '', $currency, $home, $multi, $us, $found, $named, $warnings );
		if ( $body instanceof ConnectorError ) {
			return $body;
		}
		$customer            = $this->customers->resolve( $parsed->partner, $parsed->delivery, $warnings, $currency, $home, $multi );
		$body['CustomerRef'] = array( 'value' => QuickBooksPayloadReader::text( $customer['Id'] ?? '' ) );
		return $body;
	}

	/**
	 * Create the invoice. The request id is made from the reference, the step and the body, so a retry of the same request replays the first answer; ids of invoices that
	 * were found deleted are part of it, so a create that follows a deletion is a new request.
	 *
	 * @param QuickBooksOrderPayload $parsed Checked payload.
	 * @param array<string,mixed>    $body   Body of the invoice.
	 * @param array<int,string>      $dead   Ids of invoices a replayed answer named that no longer exist.
	 * @return array{0:array<string,mixed>,1:bool} The invoice and whether this pass made it.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function post( QuickBooksOrderPayload $parsed, array $body, array $dead ): array {
		try {
			return array( $this->documents->create( 'invoice', $body, $this->requestId( $parsed->clientRef, 'invoice', $dead, $body ) ), true );
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
	 * @return array<string,mixed>|ConnectorError|null The finished invoice, the failure, or null when the invoice turned out not to exist any more (a replayed answer).
	 *
	 * @throws ApiException On a failed call.
	 */
	private function finish( array $invoice, QuickBooksOrderPayload $parsed, array &$warnings ): array|ConnectorError|null {
		if ( QuickBooksDocuments::isUnchecked( $invoice ) ) {
			$problem = $this->totals->check( $invoice, $parsed->currency, $this->company->homeCurrency(), $parsed->totalMinor );
			if ( null !== $problem ) {
				if ( 'warn' !== $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
					if ( null === $this->documents->fetch( 'invoice', QuickBooksPayloadReader::text( $invoice['Id'] ?? '' ) ) ) {
						return null;
					}
					$this->discard( 'invoice', $invoice );
					return $problem;
				}
				$warnings[] = (string) ( $problem->context['reason'] ?? '' );
			}
			$invoice = $this->documents->markChecked( 'invoice', $invoice );
			if ( null === $invoice ) {
				return null;
			}
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
		$this->documents->pay( $payment, $this->requestId( $parsed->clientRef, 'payment', array(), $payment ) );
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
	 * A requestid from the reference, the step and the body: the same request sent again gets the first answer, so an invoice made by a request whose answer was lost is
	 * not made twice. A corrected body is a new request, and so is a create that follows a deletion (the ids of deleted invoices are part of it). At most 50 characters.
	 *
	 * @param string              $reference Client reference.
	 * @param string              $step      invoice or payment.
	 * @param array<int,string>   $dead      Ids of invoices that were found deleted.
	 * @param array<string,mixed> $body      Request body.
	 */
	private function requestId( string $reference, string $step, array $dead, array $body ): string {
		return 'wc-' . substr( sha1( $this->connectionId . '|' . $reference . '|' . $step . '|' . implode( ',', $dead ) . '|' . (string) wp_json_encode( $body ) ), 0, 40 );
	}
}
