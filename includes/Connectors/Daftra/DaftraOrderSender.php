<?php
/**
 * Sends a WooCommerce order to Daftra as a sales invoice.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Daftra;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Every pass starts from a read. The invoice is searched by its purchase-order reference (`po_number` = WC-{order id}); when it exists the pass
 * carries on from where it stands, when it does not it is created as a draft. A draft has no official number and no ledger entries
 * [documented], so it is checked (currency, total, number of items) before it is issued. A draft that fails the check is deleted, so a later
 * pass can never mistake it for a finished invoice, and the error says why.
 */
final class DaftraOrderSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param DaftraDocuments $docs    Document calls.
	 * @param DaftraClients   $clients Clients.
	 * @param DaftraCatalog   $catalog Taxes, products, warehouses.
	 * @param DaftraLines     $lines   Line builder.
	 * @param DaftraTotals    $totals  Total check.
	 * @param DaftraAmounts   $amounts Conversion helper.
	 * @param DaftraIds       $ids     References.
	 * @param Clock           $clock   Time source.
	 */
	public function __construct(
		private readonly DaftraDocuments $docs,
		private readonly DaftraClients $clients,
		private readonly DaftraCatalog $catalog,
		private readonly DaftraLines $lines,
		private readonly DaftraTotals $totals,
		private readonly DaftraAmounts $amounts,
		private readonly DaftraIds $ids,
		private readonly Clock $clock,
	) {
	}

	/**
	 * The invoice of an order when the connector is finished with it: issued (or a draft when drafts are wanted), with its payment when payments
	 * are switched on. A draft waiting to be issued, or an issued invoice waiting for its payment, is not returned, so send() finishes it.
	 *
	 * @param ApiClient  $api        Client.
	 * @param Connection $connection Connection.
	 * @param Item       $item       Queue item.
	 * @return ExternalRef|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, Connection $connection, Item $item ): ?ExternalRef {
		$options = DaftraOptions::fromConnection( $connection );
		$found   = $this->docs->findInvoices( $api, $this->ids->reference( $item ) );
		if ( array() === $found ) {
			return null;
		}
		$record = $this->docs->invoice( $api, $found[0]->id() );
		if ( $options->issue() && $record->isDraft() ) {
			return null;
		}
		if ( $options->recordPayment() && ! in_array( $this->ids->paymentReference( $item ), $record->paymentReferences(), true ) ) {
			return null;
		}
		return $this->reference( $record, array() );
	}

	/**
	 * Send an order.
	 *
	 * @param ApiClient           $api        Client.
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Queue item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( ApiClient $api, Connection $connection, Item $item, array $payload ): SendResult {
		$options = DaftraOptions::fromConnection( $connection );
		$parsed  = DaftraOrderPayload::parse( $payload, $item );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		if ( '' === $parsed->currency ) {
			return SendResult::failure( $this->refused( 'currency', __( 'The order has no currency.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$reference = $this->ids->reference( $item );
		$found     = $this->docs->findInvoices( $api, $reference );
		$existed   = array() !== $found;
		$warnings  = array();
		$lineCount = null;

		if ( $existed ) {
			$record = $this->docs->invoice( $api, $found[0]->id() );
		} else {
			$made = $this->create( $api, $connection, $options, $parsed, $reference );
			if ( $made instanceof ConnectorError ) {
				return SendResult::failure( $made );
			}
			$record    = $made['record'];
			$lineCount = $made['lines'];
		}

		$settled = $this->settle( $api, $options, $parsed, $record, $lineCount, $warnings );
		if ( $settled instanceof ConnectorError ) {
			return SendResult::failure( $settled );
		}
		$record = $settled;

		$paid = $this->pay( $api, $options, $item, $parsed, $record, $warnings );
		if ( $paid instanceof ConnectorError ) {
			return SendResult::failure( $paid );
		}

		$ref = $this->reference( $record, $warnings );
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Create the invoice as a draft. Products, taxes and the client are settled first: nothing is created until they are.
	 *
	 * @param ApiClient          $api        Client.
	 * @param Connection         $connection Connection.
	 * @param DaftraOptions      $options    Options.
	 * @param DaftraOrderPayload $parsed    Payload.
	 * @param string             $reference  WC-{id}.
	 * @return array{record:DaftraRecord,lines:int}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( ApiClient $api, Connection $connection, DaftraOptions $options, DaftraOrderPayload $parsed, string $reference ): array|ConnectorError {
		$taxes = $this->catalog->taxes( $api, $connection->id );
		$rows  = $this->rows( $api, $options, $parsed );
		if ( $rows instanceof ConnectorError ) {
			return $rows;
		}
		$built = $this->lines->build( $rows, $parsed->currency, $parsed->pricesIncludeTax, $taxes, $options->storeId() );
		if ( $built instanceof ConnectorError ) {
			return $built;
		}
		$client = $this->clients->resolve( $api, $parsed->customer, $parsed->currency );
		if ( $client instanceof ConnectorError ) {
			return $client;
		}
		$invoice = array(
			'client_id'     => (int) $client,
			'po_number'     => $reference,
			'date'          => '' !== $parsed->date ? $parsed->date : $this->clock->now()->format( 'Y-m-d' ),
			'currency_code' => $parsed->currency,
			'draft'         => 1,
		);
		if ( '' !== $parsed->note ) {
			$invoice['notes'] = $parsed->note;
		}
		if ( '' !== $options->storeId() ) {
			$invoice['store_id'] = (int) $options->storeId();
		}
		$id = $this->docs->createInvoice( $api, $invoice, $built['lines'] );
		if ( $id instanceof ConnectorError ) {
			return $id;
		}
		return array(
			'record' => $this->docs->invoice( $api, $id ),
			'lines'  => count( $built['lines'] ),
		);
	}

	/**
	 * The rows of the invoice: product lines with their Daftra product, then shipping and fees as text lines.
	 *
	 * @param ApiClient          $api     Client.
	 * @param DaftraOptions      $options Options.
	 * @param DaftraOrderPayload $parsed  Payload.
	 * @return array<int,array<string,mixed>>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function rows( ApiClient $api, DaftraOptions $options, DaftraOrderPayload $parsed ): array|ConnectorError {
		$rows = array();
		foreach ( $parsed->lines as $line ) {
			if ( '' === (string) $line['item'] ) {
				$found = '' !== (string) $line['sku'] ? $this->catalog->productBySku( $api, (string) $line['sku'] ) : null;
				if ( null !== $found && '' !== DaftraPayloadReader::text( $found['id'] ?? '' ) ) {
					$line['item'] = DaftraPayloadReader::text( $found['id'] );
				} elseif ( ! $options->freeTextForUnknownSku() ) {
					return new ConnectorError(
						ErrorCode::Map001,
						array(
							'sku'    => '' !== (string) $line['sku'] ? (string) $line['sku'] : (string) $line['name'],
							'system' => DaftraConnector::LABEL,
						),
						null,
						null,
						null,
						'sku not found'
					);
				}
			}
			$rows[] = $line;
		}
		foreach ( $parsed->extras as $extra ) {
			$extra['item'] = '';
			$rows[]        = $extra;
		}
		return $rows;
	}

	/**
	 * Check a draft, then issue it. An issued invoice is left alone. A draft that fails the check is deleted.
	 *
	 * @param ApiClient          $api       Client.
	 * @param DaftraOptions      $options   Options.
	 * @param DaftraOrderPayload $parsed    Payload.
	 * @param DaftraRecord       $record    The invoice as Daftra reports it.
	 * @param int|null           $lineCount Items sent when this pass created the invoice, else null.
	 * @param array<int,string>  $warnings  Warnings (added to).
	 * @return DaftraRecord|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function settle( ApiClient $api, DaftraOptions $options, DaftraOrderPayload $parsed, DaftraRecord $record, ?int $lineCount, array &$warnings ): DaftraRecord|ConnectorError {
		if ( ! $record->isDraft() ) {
			return $record;
		}
		$failure = $this->totals->check( $record, $parsed->currency, $parsed->totalMinor, $options->failOnMismatch(), $warnings );
		if ( null === $failure && null !== $lineCount && null !== $record->lineCount() && $record->lineCount() !== $lineCount ) {
			$failure = $this->refused(
				'InvoiceItem',
				sprintf(
					/* translators: 1: number of items Daftra kept, 2: number of items sent. */
					__( 'Daftra kept %1$d lines of the %2$d that were sent.', 'estasoft-erp-crm-sync-engine' ),
					$record->lineCount(),
					$lineCount
				)
			);
		}
		if ( null !== $failure ) {
			return $this->withNote( $failure, $this->discard( $api, $record ) );
		}
		$wantIssue = ( $options->issue() && 'draft' !== $parsed->flowInvoice ) || 'issued' === $parsed->flowInvoice;
		if ( ! $wantIssue ) {
			return $record;
		}
		$this->docs->issue( $api, $record->id() );
		$after = $this->docs->invoice( $api, $record->id() );
		if ( $after->isDraft() ) {
			return $this->refused( 'draft', __( 'Daftra did not issue the invoice.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( '' !== $parsed->date && '' !== $after->date() && $after->date() !== $parsed->date ) {
			$warnings[] = __( 'Daftra changed the date of the invoice.', 'estasoft-erp-crm-sync-engine' );
		}
		return $after;
	}

	/**
	 * Delete a draft that failed the check.
	 *
	 * @param ApiClient    $api    Client.
	 * @param DaftraRecord $record The draft.
	 * @return string What to tell the person when Daftra would not delete it, else an empty string.
	 */
	private function discard( ApiClient $api, DaftraRecord $record ): string {
		try {
			$this->docs->deleteInvoice( $api, $record->id() );
		} catch ( ApiException $exception ) {
			unset( $exception );
			return __( 'The draft invoice could not be removed. Delete it in Daftra before syncing the order again.', 'estasoft-erp-crm-sync-engine' );
		}
		return '';
	}

	/**
	 * The same error with a sentence added to its reason.
	 *
	 * @param ConnectorError $error Error.
	 * @param string         $note  Sentence, or empty to change nothing.
	 */
	private function withNote( ConnectorError $error, string $note ): ConnectorError {
		if ( '' === $note ) {
			return $error;
		}
		$context           = $error->context;
		$context['reason'] = trim( (string) ( $context['reason'] ?? '' ) . ' ' . $note );
		return new ConnectorError( $error->code, $context, $error->httpStatus, $error->retryAfter, $error->classOverride, $error->detail );
	}

	/**
	 * Record the payment of a paid order against the issued invoice.
	 *
	 * @param ApiClient          $api      Client.
	 * @param DaftraOptions      $options  Options.
	 * @param Item               $item     Queue item.
	 * @param DaftraOrderPayload $parsed   Payload.
	 * @param DaftraRecord       $record   The issued invoice.
	 * @param array<int,string>  $warnings Warnings (added to).
	 * @return ConnectorError|null
	 *
	 * @throws ApiException On failure.
	 */
	private function pay( ApiClient $api, DaftraOptions $options, Item $item, DaftraOrderPayload $parsed, DaftraRecord $record, array &$warnings ): ?ConnectorError {
		$wanted = $parsed->flowPayment ?? $options->recordPayment();
		if ( ! $wanted || ! $parsed->paid || $record->isDraft() ) {
			return null;
		}
		$reference = $this->ids->paymentReference( $item );
		if ( in_array( $reference, $record->paymentReferences(), true ) ) {
			return null;
		}
		if ( '' === $options->paymentMethod() ) {
			return $this->refused( 'payment_method', __( 'Choose the payment method in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$exponent = $this->amounts->exponent( $parsed->currency );
		$total    = $record->totalMinor( $exponent ) ?? $parsed->totalMinor;
		if ( null === $total || $total <= 0 ) {
			$warnings[] = __( 'The payment was not recorded because the invoice total is not known.', 'estasoft-erp-crm-sync-engine' );
			return null;
		}
		$this->docs->createPayment(
			$api,
			array(
				'invoice_id'     => (int) $record->id(),
				'amount'         => $this->amounts->number( $total, $exponent ),
				'payment_method' => $options->paymentMethod(),
				'date'           => '' !== $parsed->paymentDate ? $parsed->paymentDate : $this->clock->now()->format( 'Y-m-d' ),
				'transaction_id' => $reference,
			)
		);
		return null;
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param DaftraRecord      $record   Invoice.
	 * @param array<int,string> $warnings Warnings.
	 */
	private function reference( DaftraRecord $record, array $warnings ): ExternalRef {
		$currency = $record->currency();
		$extra    = array(
			'currency' => $currency,
			'state'    => $record->isDraft() ? 'draft' : 'issued',
		);
		$total    = $record->totalMinor( $this->amounts->exponent( $currency ) );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		$number = $record->isDraft() || '' === $record->number() ? $record->reference() : $record->number();
		return new ExternalRef( $record->id(), $number, null, $extra );
	}

	/**
	 * A SYNC-VAL-002.
	 *
	 * @param string $field  Field.
	 * @param string $reason Reason.
	 */
	private function refused( string $field, string $reason ): ConnectorError {
		return new ConnectorError(
			ErrorCode::Val002,
			array(
				'system' => DaftraConnector::LABEL,
				'field'  => $field,
				'reason' => $reason,
			),
			null,
			null,
			null,
			'refused'
		);
	}
}
