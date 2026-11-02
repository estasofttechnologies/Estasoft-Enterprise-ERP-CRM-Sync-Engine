<?php
/**
 * Sends a refund to QuickBooks as a credit memo, one resumable step at a time.
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
 * The credit memo is made for the customer and in the currency of the order's invoice, with an "[unchecked]" note until its total has been read back
 * and compared with the refund amount. It is not applied to the invoice: outside India QuickBooks has no field on the credit memo for that, and the
 * person applies it in QuickBooks (docs/connectors/quickbooks.md, known gaps).
 */
final class QuickBooksRefundSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QuickBooksDocuments       $documents    Documents.
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
	 * The credit memo of a refund, when it is finished.
	 *
	 * @param Item $item The queue item.
	 * @return ExternalRef|ConnectorError|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function find( Item $item ): ExternalRef|ConnectorError|null {
		$memo = $this->documents->findCreditMemo( $item->clientReference() );
		if ( null === $memo ) {
			return null;
		}
		if ( ! empty( $memo['voided'] ) ) {
			return $this->voided( $item->clientReference() );
		}
		return QuickBooksDocuments::isUnchecked( $memo ) ? null : $this->reference( $memo, array() );
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
		$parsed = QuickBooksRefundPayload::parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		$warnings = array();
		$memo     = $this->documents->findCreditMemo( $parsed->clientRef );
		if ( null !== $memo && ! empty( $memo['voided'] ) ) {
			return SendResult::failure( $this->voided( $parsed->clientRef ) );
		}
		$invoice = $this->documents->findInvoice( $parsed->orderRef );
		if ( null === $invoice || ! empty( $invoice['voided'] ) ) {
			return SendResult::failure(
				QuickBooksErrors::refused(
					'Invoice',
					sprintf(
						/* translators: %s: the shop's reference of the order, such as WC-1042. */
						__( 'The invoice %s was not found in QuickBooks, so the refund cannot be linked to it.', 'estasoft-erp-crm-sync-engine' ),
						$parsed->orderRef
					)
				)
			);
		}
		$home       = $this->company->homeCurrency();
		$currency   = strtoupper( QuickBooksPayloadReader::text( $invoice['CurrencyRef']['value'] ?? '' ) );
		$currency   = '' !== $currency ? $currency : $home;
		$createdNow = false;
		if ( null === $memo ) {
			$skus = array();
			foreach ( $parsed->lines as $line ) {
				if ( '' !== $line['sku'] ) {
					$skus[] = (string) $line['sku'];
				}
			}
			$found = $this->items->bySkus( $skus );
			$kinds = array( 'generic' );
			foreach ( $parsed->lines as $line ) {
				if ( 'shipping' === ( $line['kind'] ?? '' ) ) {
					$kinds[] = 'shipping';
				}
			}
			$named = $this->items->forKinds( $kinds, $this->options );
			$body  = $this->builder->creditMemo( $parsed, $invoice, $currency, $home, $this->company->isUs(), $found, $named, $warnings );
			if ( $body instanceof ConnectorError ) {
				return SendResult::failure( $body );
			}
			$memo       = $this->documents->create( 'creditmemo', $body, 'wc-' . substr( sha1( $this->connectionId . '|' . $parsed->clientRef . '|creditmemo|' . $parsed->attempt . '|' . (string) wp_json_encode( $body ) ), 0, 40 ) );
			$createdNow = true;
		}
		if ( QuickBooksDocuments::isUnchecked( $memo ) ) {
			$problem = $this->totals->check( $memo, $currency, $home, $parsed->amountMinor );
			if ( null !== $problem ) {
				if ( 'warn' !== $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
					$this->discard( $memo );
					return SendResult::failure( $problem );
				}
				$warnings[] = (string) ( $problem->context['reason'] ?? '' );
			}
			$memo = $this->documents->markChecked( 'creditmemo', $memo );
		}
		$ref = $this->reference( $memo, $warnings );
		return $createdNow ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Delete a credit memo this pass found wrong; a failure to do so is not worse than leaving it.
	 *
	 * @param array<string,mixed> $memo Credit memo.
	 */
	private function discard( array $memo ): void {
		try {
			$this->documents->delete( 'creditmemo', $memo );
		} catch ( ApiException $exception ) {
			unset( $exception );
		}
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $memo     Credit memo.
	 * @param array<int,string>   $warnings Notes.
	 */
	private function reference( array $memo, array $warnings ): ExternalRef {
		$currency = strtoupper( QuickBooksPayloadReader::text( $memo['CurrencyRef']['value'] ?? '' ) );
		$currency = '' !== $currency ? $currency : $this->company->homeCurrency();
		$extra    = array( 'currency' => $currency );
		$total    = $this->amounts->fromNumber( $memo['TotalAmt'] ?? null, '' !== $currency ? $currency : 'USD' );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		$warnings = array_values( array_filter( $warnings, static fn( string $warning ): bool => '' !== $warning ) );
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( QuickBooksPayloadReader::text( $memo['Id'] ?? '' ), QuickBooksPayloadReader::text( $memo['DocNumber'] ?? '' ), null, $extra );
	}

	/**
	 * The failure for a credit memo that was voided in QuickBooks.
	 *
	 * @param string $reference Reference.
	 */
	private function voided( string $reference ): ConnectorError {
		return QuickBooksErrors::refused(
			'CreditMemo',
			sprintf(
				/* translators: %s: the shop's reference of the refund, such as WC-77. */
				__( 'The credit memo %s was voided in QuickBooks. Delete the voided credit memo there, then retry.', 'estasoft-erp-crm-sync-engine' ),
				$reference
			)
		);
	}
}
