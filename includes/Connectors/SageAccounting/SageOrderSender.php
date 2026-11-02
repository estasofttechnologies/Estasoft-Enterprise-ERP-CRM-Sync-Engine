<?php
/**
 * Sends an order to Sage as a sales invoice, one resumable step at a time.
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
 * A pass starts from a read and does only what is missing. The invoice is created as a draft (nothing is posted), its total is read back and checked, a wrong
 * draft made by this pass is deleted, and only then is it released. Create, release and payment run once per pass; a failure that may have reached Sage ends
 * the pass, and the idempotency id (the same request in the same try replays the first answer) covers a repeat inside the try.
 */
final class SageOrderSender {

	/**
	 * Most creates in one pass when the answer keeps naming an invoice that no longer exists.
	 */
	private const ROUNDS = 3;

	/**
	 * Set up with what it depends on.
	 *
	 * @param SageInvoices $invoices     Invoices.
	 * @param SageContacts $contacts     Contacts.
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
		private readonly SageContacts $contacts,
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
		$parsed = SageOrderPayload::parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		if ( '' === $this->options->text( 'sales_ledger_account' ) ) {
			return SendResult::failure( $this->needsLedger( 'sales_ledger_account' ) );
		}
		$warnings = array();
		$invoice  = $this->invoices->findInvoice( $parsed->clientRef );
		if ( null !== $invoice && ! empty( $invoice['voided'] ) ) {
			return SendResult::failure( $this->voided( $parsed->clientRef ) );
		}
		$createdNow = false;
		if ( null === $invoice ) {
			$body = $this->prepare( $parsed, $warnings );
			if ( $body instanceof ConnectorError ) {
				return SendResult::failure( $body );
			}
			$dead = $this->invoices->deadIds( 'sales_invoices', $parsed->clientRef );
			$done = null;
			for ( $round = 0; $round < self::ROUNDS && null === $done; ++$round ) {
				$invoice    = $this->invoices->create( 'sales_invoices', 'sales_invoice', $this->withKey( $body, $parsed->clientRef, 'invoice', $dead ) );
				$createdNow = true;
				$done       = $this->finish( $invoice, $parsed, true, $warnings );
				if ( null === $done ) {
					$dead[] = SagePayloadReader::text( $invoice['id'] ?? '' );
				}
			}
		} else {
			$done = $this->finish( $invoice, $parsed, false, $warnings );
		}
		if ( null === $done ) {
			return SendResult::failure( SageErrors::refused( 'sales_invoice', __( 'Sage keeps answering with an invoice that no longer exists. Try again later.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		if ( $done instanceof ConnectorError ) {
			return SendResult::failure( $done );
		}
		$ref = $this->reference( $done, $warnings );
		return $createdNow ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * Resolve everything the draft invoice needs and build its body.
	 *
	 * @param SageOrderPayload  $parsed   Checked payload.
	 * @param array<int,string> $warnings Receives notes.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	private function prepare( SageOrderPayload $parsed, array &$warnings ): array|ConnectorError {
		$currency = $this->business->currency();
		if ( '' !== $parsed->currency && '' !== $currency && $parsed->currency !== $currency ) {
			return SageErrors::refused(
				'currency',
				sprintf(
					/* translators: 1: order currency, 2: currency of the business. */
					__( 'The order is in %1$s but the business works in %2$s. Foreign-currency invoices are not sent.', 'estasoft-erp-crm-sync-engine' ),
					$parsed->currency,
					$currency
				)
			);
		}
		$rates = $this->rates( SageBuilder::taxCodesUsed( $parsed->lines, $parsed->extras, $parsed->shippingTax ) );
		if ( $rates instanceof ConnectorError ) {
			return $rates;
		}
		$ledgers = $this->ledgers( array() !== $parsed->extras ? array_map( static fn( array $extra ): string => (string) $extra['kind'], $parsed->extras ) : array() );
		if ( $ledgers instanceof ConnectorError ) {
			return $ledgers;
		}
		$skus = array();
		foreach ( $parsed->lines as $line ) {
			$skus[] = (string) $line['sku'];
		}
		$body = $this->builder->invoice( $parsed, '', $currency, $this->products->byCodes( $skus ), $rates, $ledgers, $warnings );
		if ( $body instanceof ConnectorError ) {
			return $body;
		}
		$contact            = $this->contacts->resolve( $parsed->partner, $parsed->delivery, $this->id( (string) ( $parsed->partner['email'] ?? '' ), 'contact', array(), array() ), $warnings );
		$body['contact_id'] = SagePayloadReader::text( $contact['id'] ?? '' );
		return $body;
	}

	/**
	 * The body with its idempotency id, made from the reference, the step and the body. The same request sent again gets the first answer, so a create whose answer was
	 * lost is not made twice (Sage references are not unique, so a search cannot be trusted to find it). The ids of documents the connector voided or found gone are
	 * part of it, so a create that follows its own void is a new request.
	 *
	 * @param array<string,mixed> $body      Body.
	 * @param string              $reference Client reference.
	 * @param string              $step      invoice or payment.
	 * @param array<int,string>   $dead      Ids of documents voided or found gone.
	 * @return array<string,mixed>
	 */
	private function withKey( array $body, string $reference, string $step, array $dead ): array {
		$body['idempotency_id'] = $this->id( $reference, $step, $dead, $body );
		return $body;
	}

	/**
	 * Check, release and pay as far as the settings ask.
	 *
	 * @param array<string,mixed> $invoice    The invoice as Sage last returned it.
	 * @param SageOrderPayload    $parsed     Checked payload.
	 * @param bool                $createdNow True when this pass created the invoice.
	 * @param array<int,string>   $warnings   Receives notes.
	 * @return array<string,mixed>|ConnectorError|null The invoice, the failure, or null when the invoice turned out not to exist any more (a replayed answer).
	 *
	 * @throws ApiException On a failed call.
	 */
	private function finish( array $invoice, SageOrderPayload $parsed, bool $createdNow, array &$warnings ): array|ConnectorError|null {
		$id     = SagePayloadReader::text( $invoice['id'] ?? '' );
		$status = SageInvoices::status( $invoice );
		if ( 'DRAFT' === $status ) {
			$problem = $this->totals->check( $invoice, $this->business->currency(), $parsed->totalMinor, $this->tolerance( $parsed ) );
			if ( null !== $problem ) {
				if ( 'warn' !== $this->options->choice( 'total_mismatch', array( 'fail', 'warn' ), 'fail' ) ) {
					if ( $createdNow && $this->invoices->isGone( 'sales_invoices', $id ) ) {
						return null;
					}
					return $this->wrong( $problem, $invoice, $createdNow );
				}
				$warnings[] = (string) ( $problem->context['reason'] ?? '' );
			}
			if ( $this->options->releases() ) {
				try {
					$invoice = $this->invoices->release( $id );
				} catch ( ApiException $exception ) {
					if ( $createdNow && $this->invoices->isGone( 'sales_invoices', $id ) ) {
						return null;
					}
					throw $exception;
				}
				$status = SageInvoices::status( $invoice );
			}
		}
		if ( $this->options->pays() && '' !== $parsed->paymentDate && in_array( $status, array( 'UNPAID', 'PART_PAID' ), true ) ) {
			$paid = $this->pay( $invoice, $parsed );
			if ( $paid instanceof ConnectorError ) {
				return $paid;
			}
			$invoice = $paid;
		}
		return $invoice;
	}

	/**
	 * The failure for a draft whose total is wrong. A draft made by this pass is voided if Sage lets it; the answer says what really happened, because the guide
	 * documents voiding for an unpaid invoice only and whether a draft can be voided is not stated.
	 *
	 * @param ConnectorError      $problem    The mismatch.
	 * @param array<string,mixed> $invoice    The draft.
	 * @param bool                $createdNow True when this pass made it.
	 */
	private function wrong( ConnectorError $problem, array $invoice, bool $createdNow ): ConnectorError {
		$id = SagePayloadReader::text( $invoice['id'] ?? '' );
		if ( $createdNow && '' !== $id && $this->invoices->remove( 'sales_invoices', $id ) ) {
			return $problem;
		}
		return SageErrors::withNote(
			$problem,
			sprintf(
				/* translators: %s: the shop's reference of the order, such as WC-1042. */
				__( 'The draft invoice %s is still in Sage (nothing is posted for a draft). Delete it in Sage, then retry.', 'estasoft-erp-crm-sync-engine' ),
				SagePayloadReader::text( $invoice['reference'] ?? '' )
			)
		);
	}

	/**
	 * Most the totals may differ. Prices that include tax are converted line by line (or worked out by Sage line by line), and each line can be off by one minor unit.
	 *
	 * @param SageOrderPayload $parsed Checked payload.
	 */
	private function tolerance( SageOrderPayload $parsed ): int {
		return $parsed->pricesIncludeTax ? max( 1, count( $parsed->lines ) + count( $parsed->extras ) + ( 0 !== $parsed->shippingMinor ? 1 : 0 ) ) : 1;
	}

	/**
	 * Record the payment of a released invoice, once, for the amount outstanding.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 * @param SageOrderPayload    $parsed  Checked payload.
	 * @return array<string,mixed>|ConnectorError The invoice after the payment.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function pay( array $invoice, SageOrderPayload $parsed ): array|ConnectorError {
		$account = $this->options->text( 'payment_bank_account' );
		if ( '' === $account ) {
			return SageErrors::refused( 'payment_bank_account', __( 'Set the bank account that receives payments in the connection option "payment_bank_account".', 'estasoft-erp-crm-sync-engine' ) );
		}
		$bank = $this->business->bankId( $account );
		if ( '' === $bank ) {
			return SageErrors::refused(
				'payment_bank_account',
				sprintf(
					/* translators: %s: name or id of a bank account. */
					__( 'Sage has no bank account "%s". Check the connection option payment_bank_account.', 'estasoft-erp-crm-sync-engine' ),
					$account
				)
			);
		}
		$currency    = $this->business->currency();
		$outstanding = $this->amounts->fromNumber( $invoice['outstanding_amount'] ?? null, '' !== $currency ? $currency : 'GBP' );
		if ( null === $outstanding || $outstanding <= 0 ) {
			return $invoice;
		}
		$id                        = SagePayloadReader::text( $invoice['id'] ?? '' );
		$amount                    = $this->amounts->text( $outstanding, $currency );
		$payment                   = array(
			'transaction_type_id' => 'CUSTOMER_RECEIPT',
			'contact_id'          => SagePayloadReader::text( $invoice['contact']['id'] ?? '' ),
			'bank_account_id'     => $bank,
			'date'                => $parsed->paymentDate,
			'total_amount'        => $amount,
			'reference'           => SagePayloadReader::limit( '' !== $parsed->paymentReference ? $parsed->paymentReference : $parsed->clientRef, 25 ),
			'allocated_artefacts' => array(
				array(
					'artefact_id' => $id,
					'amount'      => $amount,
				),
			),
		);
		$payment['idempotency_id'] = $this->id( $parsed->clientRef, 'payment', array(), $payment );
		$this->invoices->pay( $payment );
		return $this->invoices->get( 'sales_invoices', $id ) ?? $invoice;
	}

	/**
	 * The tax rates an order uses.
	 *
	 * @param array{codes:array<int,string>,none:bool} $used Codes the order uses and whether it needs the no-tax rate.
	 * @return array<string,array{id:string,rate:int}>|ConnectorError Rates by lower-case id (the key "" holds the no-tax rate), or the failure for the first rate Sage does not have.
	 *
	 * @throws ApiException On a failed call.
	 */
	private function rates( array $used ): array|ConnectorError {
		$rates = array();
		foreach ( $used['codes'] as $code ) {
			$rate = $this->business->taxRate( $code );
			if ( null === $rate ) {
				return SageErrors::unknownTax( strtoupper( $this->country ), $code );
			}
			$rates[ strtolower( $code ) ] = $rate;
		}
		if ( $used['none'] ) {
			$none = $this->business->noTaxRate( $this->options->text( 'no_tax_rate' ) );
			if ( null !== $none ) {
				$rates[''] = $none;
			}
		}
		return $rates;
	}

	/**
	 * The ledger account ids for the kinds of line an order has.
	 *
	 * @param array<int,string> $kinds fee and discount kinds the order has.
	 * @return array<string,string>|ConnectorError
	 *
	 * @throws ApiException On a failed call.
	 */
	private function ledgers( array $kinds ): array|ConnectorError {
		$ledgers = array();
		foreach ( array_unique( array_merge( array( 'sales' ), $kinds ) ) as $kind ) {
			$option = 'sales' === $kind ? 'sales_ledger_account' : $kind . '_ledger_account';
			$value  = $this->options->ledgerFor( $kind );
			$id     = '' === $value ? '' : $this->business->ledgerId( $value );
			if ( '' === $id ) {
				return '' === $value ? $this->needsLedger( $option ) : $this->unknownLedger( $option, $value );
			}
			$ledgers[ $kind ] = $id;
		}
		return $ledgers;
	}

	/**
	 * Whether an invoice has all the steps the connection's settings ask for.
	 *
	 * @param array<string,mixed> $invoice Invoice.
	 */
	private function finished( array $invoice ): bool {
		$status = SageInvoices::status( $invoice );
		if ( $this->options->pays() ) {
			return 'PAID' === $status;
		}
		if ( $this->options->releases() ) {
			return in_array( $status, array( 'UNPAID', 'PART_PAID', 'PAID' ), true );
		}
		return in_array( $status, array( 'DRAFT', 'UNPAID', 'PART_PAID', 'PAID' ), true );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param array<string,mixed> $invoice  Invoice.
	 * @param array<int,string>   $warnings Notes.
	 */
	private function reference( array $invoice, array $warnings ): ExternalRef {
		$currency = strtoupper( SagePayloadReader::text( $invoice['currency']['id'] ?? ( $invoice['currency'] ?? '' ) ) );
		$currency = '' !== $currency ? $currency : $this->business->currency();
		$extra    = array(
			'currency' => $currency,
			'status'   => SageInvoices::status( $invoice ),
		);
		$total    = $this->amounts->fromNumber( $invoice['total_amount'] ?? null, '' !== $currency ? $currency : 'GBP' );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		$outstanding = $this->amounts->fromNumber( $invoice['outstanding_amount'] ?? null, '' !== $currency ? $currency : 'GBP' );
		if ( null !== $outstanding ) {
			$extra['outstanding_minor'] = $outstanding;
		}
		$warnings = array_values( array_filter( $warnings, static fn( string $warning ): bool => '' !== $warning ) );
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		$number = SagePayloadReader::text( $invoice['invoice_number'] ?? '' );
		return new ExternalRef( SagePayloadReader::text( $invoice['id'] ?? '' ), '' !== $number ? $number : SagePayloadReader::text( $invoice['reference'] ?? '' ), null, $extra );
	}

	/**
	 * The failure for an invoice that was voided in Sage.
	 *
	 * @param string $reference Reference.
	 */
	private function voided( string $reference ): ConnectorError {
		return SageErrors::refused(
			'sales_invoice',
			sprintf(
				/* translators: %s: the shop's reference of the order, such as WC-1042. */
				__( 'The invoice for %s was voided in Sage. Delete the voided invoice there, or change its reference, then retry.', 'estasoft-erp-crm-sync-engine' ),
				$reference
			)
		);
	}

	/**
	 * The failure for a missing ledger account option.
	 *
	 * @param string $option Option name.
	 */
	private function needsLedger( string $option ): ConnectorError {
		return SageErrors::refused(
			$option,
			sprintf(
				/* translators: %s: the name of a connection option. */
				__( 'Set the nominal code of an income account in the connection option "%s": Sage needs a ledger account on every line.', 'estasoft-erp-crm-sync-engine' ),
				$option
			)
		);
	}

	/**
	 * The failure for a ledger account Sage does not have.
	 *
	 * @param string $option Option name.
	 * @param string $value  The value of the option.
	 */
	private function unknownLedger( string $option, string $value ): ConnectorError {
		return SageErrors::refused(
			$option,
			sprintf(
				/* translators: 1: a nominal code, 2: the name of a connection option. */
				__( 'Sage has no ledger account "%1$s". Check the connection option "%2$s".', 'estasoft-erp-crm-sync-engine' ),
				$value,
				$option
			)
		);
	}

	/**
	 * An idempotency id: 32 hex characters from the connection, the reference, the step, the ids of documents found voided or gone, and the body.
	 *
	 * @param string              $reference Client reference.
	 * @param string              $step      invoice, payment or contact.
	 * @param array<int,string>   $dead      Ids of documents voided or found gone.
	 * @param array<string,mixed> $body      Request body.
	 */
	private function id( string $reference, string $step, array $dead, array $body ): string {
		return md5( $this->connectionId . '|' . $reference . '|' . $step . '|' . implode( ',', $dead ) . '|' . (string) wp_json_encode( $body ) );
	}
}
