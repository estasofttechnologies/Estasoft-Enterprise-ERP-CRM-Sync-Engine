<?php
/**
 * Sends an order to MYOB as an item invoice, one resumable step at a time.
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
 * A pass starts from a read and does only what is missing. MYOB posts an invoice when it is created, so everything the invoice needs (tax codes,
 * items, the customer) is resolved first, the body is built and checked, and only then is the invoice created. It is read back at once: a
 * wrong invoice made by this pass is deleted. Create and payment run once per pass; a failure that may have reached MYOB ends the pass. If a pass is
 * killed between the create and the check, the invoice is accepted as it is on the next pass and the nightly reconciliation compares its total.
 */
final class MyobOrderSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param MyobInvoices       $invoices  Invoices.
	 * @param MyobCustomers      $customers Customers.
	 * @param MyobLookups        $lookups   Tax codes, accounts and items.
	 * @param MyobCompany        $company   Company file facts.
	 * @param MyobInvoiceBuilder $builder   Body builder.
	 * @param MyobTotals         $totals    Total check.
	 * @param MyobAmounts        $amounts   Conversion helper.
	 * @param MyobOptions        $options   Connection options.
	 */
	public function __construct(
		private readonly MyobInvoices $invoices,
		private readonly MyobCustomers $customers,
		private readonly MyobLookups $lookups,
		private readonly MyobCompany $company,
		private readonly MyobInvoiceBuilder $builder,
		private readonly MyobTotals $totals,
		private readonly MyobAmounts $amounts,
		private readonly MyobOptions $options
	) {
	}

	/**
	 * The invoice of an order, when it is finished for the connection's settings.
	 *
	 * @param Item $item The queue item.
	 * @return ExternalRef|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function find( Item $item ): ?ExternalRef {
		$invoice = $this->invoices->find( $item->clientReference() );
		if ( null === $invoice ) {
			return null;
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
		$parsed = MyobOrderPayload::parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		$currency = $this->company->currency();
		if ( '' !== $parsed->currency && $parsed->currency !== $currency ) {
			return SendResult::failure(
				MyobErrors::refused(
					'currency',
					sprintf(
						/* translators: 1: order currency, 2: currency of the company file. */
						__( 'The order is in %1$s but the company file works in %2$s. Foreign-currency invoices are not sent.', 'estasoft-erp-crm-sync-engine' ),
						$parsed->currency,
						$currency
					)
				)
			);
		}
		$warnings   = array();
		$invoice    = $this->invoices->find( $parsed->clientRef );
		$createdNow = false;
		if ( null === $invoice ) {
			$made = $this->create( $parsed, $currency, $warnings );
			if ( $made instanceof ConnectorError ) {
				return SendResult::failure( $made );
			}
			$invoice    = $made;
			$createdNow = true;
		}
		if ( $this->options->pays() && '' !== $parsed->paymentDate ) {
			$invoice = $this->pay( $invoice, $parsed, $currency );
		}
		$ref = $this->reference( $invoice, $warnings );
		return $createdNow ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Create the invoice after everything it needs has been resolved, and check it.
	 *
	 * @param MyobOrderPayload  $parsed   Checked payload.
	 * @param string            $currency Currency of the file.
	 * @param array<int,string> $warnings Receives notes.
	 * @return array<string,mixed>|ConnectorError The invoice as MYOB holds it.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function create( MyobOrderPayload $parsed, string $currency, array &$warnings ): array|ConnectorError {
		$tax = $this->taxUids( MyobInvoiceBuilder::taxCodesUsed( $parsed->lines, $parsed->extras, $parsed->freightTax ) );
		if ( $tax instanceof ConnectorError ) {
			return $tax;
		}
		$noTax = $this->needsNoTax( $parsed ) ? $this->lookups->taxUid( 'N-T' ) : '';
		$skus  = array();
		foreach ( $parsed->lines as $line ) {
			$skus[] = (string) $line['sku'];
		}
		$items = $this->lookups->items( $skus );
		$named = $this->named( array_map( static fn( array $extra ): string => (string) $extra['kind'], $parsed->extras ) );
		$body  = $this->builder->invoice( $parsed, '', $currency, $items, $named, $tax, $noTax, $warnings );
		if ( $body instanceof ConnectorError ) {
			return $body;
		}
		$customer         = $this->customers->resolve( $parsed->partner, $parsed->delivery, $warnings );
		$body['Customer'] = array( 'UID' => MyobPayloadReader::text( $customer['UID'] ?? '' ) );
		$invoice          = $this->invoices->create( $body, $parsed->clientRef );
		$problem          = $this->totals->check( $invoice, $currency, $parsed->totalMinor );
		if ( null === $problem ) {
			return $invoice;
		}
		if ( 'warn' === $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
			$warnings[] = (string) ( $problem->context['reason'] ?? '' );
			return $invoice;
		}
		$this->discard( $invoice );
		return $problem;
	}

	/**
	 * The UIDs of the tax codes an order uses.
	 *
	 * @param array<int,string> $codes Codes.
	 * @return array<string,string>|ConnectorError UIDs by lower-case code, or the failure for the first code MYOB does not have.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function taxUids( array $codes ): array|ConnectorError {
		$uids = array();
		foreach ( $codes as $code ) {
			$uid = $this->lookups->taxUid( $code );
			if ( '' === $uid ) {
				return MyobErrors::unknownTax( $code );
			}
			$uids[ strtolower( $code ) ] = $uid;
		}
		return $uids;
	}

	/**
	 * Whether some line asked for "no tax" with an empty code.
	 *
	 * @param MyobOrderPayload $parsed Checked payload.
	 */
	private function needsNoTax( MyobOrderPayload $parsed ): bool {
		foreach ( array_merge( $parsed->lines, $parsed->extras ) as $line ) {
			if ( '' === ( $line['tax'] ?? null ) ) {
				return true;
			}
		}
		return '' === $parsed->freightTax && 0 !== $parsed->freightMinor;
	}

	/**
	 * The items the options name for fee, discount and generic lines.
	 *
	 * @param array<int,string> $kinds Kinds the order needs.
	 * @return array<string,array<string,mixed>|null>
	 *
	 * @throws ApiException On a failed call.
	 */
	private function named( array $kinds ): array {
		$named = array();
		$cache = array();
		foreach ( array() === $kinds ? array() : array_unique( array_merge( $kinds, array( 'generic' ) ) ) as $kind ) {
			$number = $this->options->itemFor( $kind );
			if ( '' === $number ) {
				$named[ $kind ] = null;
				continue;
			}
			if ( ! array_key_exists( $number, $cache ) ) {
				$cache[ $number ] = $this->lookups->item( $number );
			}
			$named[ $kind ] = $cache[ $number ];
		}
		return $named;
	}

	/**
	 * Record the payment, once, for the balance.
	 *
	 * @param array<string,mixed> $invoice  The invoice.
	 * @param MyobOrderPayload    $parsed   Checked payload.
	 * @param string              $currency Currency of the file.
	 * @return array<string,mixed> The invoice after the payment.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function pay( array $invoice, MyobOrderPayload $parsed, string $currency ): array {
		$balance = $this->amounts->fromNumber( $invoice['BalanceDueAmount'] ?? null, $currency );
		if ( null === $balance || $balance <= 0 ) {
			return $invoice;
		}
		$uid     = MyobPayloadReader::text( $invoice['UID'] ?? '' );
		$number  = $this->amounts->number( $balance, $currency );
		$payment = array(
			'Date'       => $parsed->paymentDate . ' 00:00:00',
			'Customer'   => array( 'UID' => MyobPayloadReader::text( $invoice['Customer']['UID'] ?? '' ) ),
			'Memo'       => MyobPayloadReader::limit( '' !== $parsed->paymentReference ? $parsed->clientRef . ' ' . $parsed->paymentReference : $parsed->clientRef, 255 ),
			'AmountPaid' => $number,
			'Invoices'   => array(
				array(
					'UID'           => $uid,
					'Type'          => 'Invoice',
					'AmountApplied' => $number,
				),
			),
		);
		$account = $this->options->text( 'payment_account' );
		if ( '' !== $account ) {
			$accountUid = $this->lookups->accountUid( $account );
			if ( '' === $accountUid ) {
				$failure = MyobErrors::exception(
					MyobErrors::refused(
						'payment_account',
						sprintf(
							/* translators: %s: an account number such as 1-1110. */
							__( 'MYOB has no account "%s" for the payment. Check the connection option payment_account.', 'estasoft-erp-crm-sync-engine' ),
							$account
						)
					)
				);
				throw $failure;
			}
			$payment['DepositTo'] = 'Account';
			$payment['Account']   = array( 'UID' => $accountUid );
		} else {
			$payment['DepositTo'] = 'UndepositedFunds';
		}
		$this->invoices->pay( $payment );
		return $this->invoices->get( $uid ) ?? $invoice;
	}

	/**
	 * Delete an invoice this pass found wrong; a failure to do so is not worse than leaving it, and the nightly check compares the total.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 */
	private function discard( array $invoice ): void {
		try {
			$this->invoices->delete( $invoice );
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
		return ! $this->options->pays() || MyobInvoices::isPaid( $invoice );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $invoice  Invoice.
	 * @param array<int,string>   $warnings Notes.
	 */
	private function reference( array $invoice, array $warnings ): ExternalRef {
		$currency = $this->company->currency();
		$extra    = array( 'currency' => $currency );
		$total    = $this->amounts->fromNumber( $invoice['TotalAmount'] ?? null, $currency );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		$balance = $this->amounts->fromNumber( $invoice['BalanceDueAmount'] ?? null, $currency );
		if ( null !== $balance ) {
			$extra['balance_minor'] = $balance;
		}
		$status = MyobPayloadReader::text( $invoice['Status'] ?? '' );
		if ( '' !== $status ) {
			$extra['status'] = $status;
		}
		$warnings = array_values( array_filter( $warnings, static fn( string $warning ): bool => '' !== $warning ) );
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( MyobPayloadReader::text( $invoice['UID'] ?? '' ), MyobPayloadReader::text( $invoice['Number'] ?? '' ), null, $extra );
	}
}
