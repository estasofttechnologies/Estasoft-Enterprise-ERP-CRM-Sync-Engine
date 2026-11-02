<?php
/**
 * Sends a WooCommerce order to Wafeq as a sales invoice.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Wafeq;

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
 * Every pass starts from a read. The invoice is searched by `external_id` (WC-{order id}); when it exists the pass carries on from where it
 * stands, when it does not it is created as a draft. A draft is checked (currency, total, date) and only then finalized, so a wrong figure
 * never reaches the ledger. Nothing that makes something new (the invoice, the payment) is replayed blindly: the next pass reads first.
 *
 * The draft carries the reference "WC-{id} (unchecked)" until it has passed the check; only the checked reference counts as finished, so
 * a draft that failed the check is never linked by a later pass.
 */
final class WafeqOrderSender {

	/**
	 * Text added to the reference of a draft that has not passed the check.
	 */
	public const UNCHECKED = ' (unchecked)';

	/**
	 * Set up with what it depends on.
	 *
	 * @param WafeqDocuments $docs     Document calls.
	 * @param WafeqContacts  $contacts Contacts.
	 * @param WafeqCatalog   $catalog  Tax rates, accounts, items.
	 * @param WafeqLines     $lines    Line builder.
	 * @param WafeqTotals    $totals   Total check.
	 * @param WafeqAmounts   $amounts  Conversion helper.
	 * @param WafeqIds       $ids      References and keys.
	 * @param Clock          $clock    Time source.
	 */
	public function __construct(
		private readonly WafeqDocuments $docs,
		private readonly WafeqContacts $contacts,
		private readonly WafeqCatalog $catalog,
		private readonly WafeqLines $lines,
		private readonly WafeqTotals $totals,
		private readonly WafeqAmounts $amounts,
		private readonly WafeqIds $ids,
		private readonly Clock $clock,
	) {
	}

	/**
	 * The invoice of an order when the connector is finished with it: finalized (or a checked draft when drafts are wanted), with its
	 * payment when payments are switched on. An invoice still waiting for its check, its posting or its payment is not returned, so
	 * send() finishes it.
	 *
	 * @param ApiClient  $api        Client.
	 * @param Connection $connection Connection.
	 * @param Item       $item       Queue item.
	 * @return ExternalRef|null
	 *
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException On failure.
	 */
	public function find( ApiClient $api, Connection $connection, Item $item ): ?ExternalRef {
		$options   = WafeqOptions::fromConnection( $connection );
		$reference = $this->ids->reference( $item );
		$records   = $this->docs->findInvoices( $api, $reference );
		if ( array() === $records ) {
			return null;
		}
		$record = $records[0];
		if ( ! $this->isFinished( $record, $reference, $options ) ) {
			return null;
		}
		if ( $options->recordPayment() && array() === $this->docs->findPayments( $api, $this->ids->paymentReference( $item ) ) ) {
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
	 * @throws \Estasoft\SyncEngine\Connectors\Support\ApiException On failure.
	 */
	public function send( ApiClient $api, Connection $connection, Item $item, array $payload ): SendResult {
		$options = WafeqOptions::fromConnection( $connection );
		$parsed  = WafeqOrderPayload::parse( $payload, $item );
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

		if ( $existed ) {
			$record = $this->docs->invoice( $api, $found[0]->id() );
		} else {
			$record = $this->create( $api, $connection, $item, $options, $parsed, $reference );
			if ( $record instanceof ConnectorError ) {
				return SendResult::failure( $record );
			}
		}

		$settled = $this->settle( $api, $options, $parsed, $record, $reference, $warnings );
		if ( $settled instanceof ConnectorError ) {
			return SendResult::failure( $settled );
		}
		$record = $settled;

		$paid = $this->pay( $api, $connection, $options, $item, $parsed, $record, $warnings );
		if ( $paid instanceof ConnectorError ) {
			return SendResult::failure( $paid );
		}

		$authority = $this->report( $api, $options, $item, $record, $warnings );

		$ref = $this->reference( $record, $warnings, $parsed->currency, $authority );
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Create the invoice as a draft. Products, taxes, the account and the contact are settled first: nothing is created until they are.
	 *
	 * @param ApiClient         $api       Client.
	 * @param Connection        $connection Connection.
	 * @param Item              $item      Queue item.
	 * @param WafeqOptions      $options   Options.
	 * @param WafeqOrderPayload $parsed    Payload.
	 * @param string            $reference WC-{id}.
	 * @return WafeqRecord|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( ApiClient $api, Connection $connection, Item $item, WafeqOptions $options, WafeqOrderPayload $parsed, string $reference ): WafeqRecord|ConnectorError {
		$rates   = $this->catalog->taxRates( $api, $connection->id );
		$account = $this->catalog->salesAccountId( $api, $connection->id, $options->salesAccount() );
		if ( $account instanceof ConnectorError ) {
			return $account;
		}
		$rows = $this->rows( $api, $connection, $options, $parsed );
		if ( $rows instanceof ConnectorError ) {
			return $rows;
		}
		$built = $this->lines->build( $rows, $parsed->currency, $parsed->pricesIncludeTax, $rates, $account );
		if ( $built instanceof ConnectorError ) {
			return $built;
		}
		$contact = $this->contacts->resolve( $api, $item, $parsed->customer );
		if ( $contact instanceof ConnectorError ) {
			return $contact;
		}

		$date = '' !== $parsed->date ? $parsed->date : $this->clock->now()->format( 'Y-m-d' );
		$body = array(
			'contact'          => $contact,
			'currency'         => $parsed->currency,
			'invoice_date'     => $date,
			'invoice_due_date' => $date,
			'invoice_number'   => $this->ids->documentNumber( $options->numberPrefix(), '' !== $parsed->number ? $parsed->number : $item->entityId ),
			'external_id'      => $reference,
			'reference'        => $reference . self::UNCHECKED,
			'status'           => 'DRAFT',
			'tax_amount_type'  => 'TAX_EXCLUSIVE',
			'language'         => $options->language(),
			'line_items'       => $built['lines'],
		);
		if ( '' !== $parsed->note ) {
			$body['notes'] = $parsed->note;
		}
		if ( '' !== $options->placeOfSupply() ) {
			$body['place_of_supply'] = $options->placeOfSupply();
		}
		try {
			$created = $this->docs->createInvoice( $api, $body, $this->ids->idempotencyKey( $item, 'invoice' ) );
		} catch ( ApiException $exception ) {
			$this->catalog->itemRefused( $exception, $connection->id );
			throw $exception;
		}
		if ( $created instanceof ConnectorError ) {
			return $created;
		}
		return $this->docs->invoice( $api, $created->id() );
	}

	/**
	 * The rows of the invoice: product lines with their Wafeq item, then shipping and fees.
	 *
	 * @param ApiClient         $api        Client.
	 * @param Connection        $connection Connection.
	 * @param WafeqOptions      $options    Options.
	 * @param WafeqOrderPayload $parsed     Payload.
	 * @return array<int,array<string,mixed>>|ConnectorError
	 */
	private function rows( ApiClient $api, Connection $connection, WafeqOptions $options, WafeqOrderPayload $parsed ): array|ConnectorError {
		$rows = array();
		foreach ( $parsed->lines as $line ) {
			if ( '' === (string) $line['item'] ) {
				$found = '' !== (string) $line['sku'] ? $this->catalog->itemBySku( $api, $connection->id, (string) $line['sku'] ) : null;
				if ( null !== $found && '' !== WafeqPayloadReader::text( $found['id'] ?? '' ) ) {
					$line['item'] = WafeqPayloadReader::text( $found['id'] );
				} elseif ( ! $options->freeTextForUnknownSku() ) {
					return new ConnectorError(
						ErrorCode::Map001,
						array(
							'sku'    => '' !== (string) $line['sku'] ? (string) $line['sku'] : (string) $line['name'],
							'system' => WafeqConnector::LABEL,
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
	 * Check a draft, then finalize it. A finalized invoice is left alone.
	 *
	 * @param ApiClient         $api       Client.
	 * @param WafeqOptions      $options   Options.
	 * @param WafeqOrderPayload $parsed    Payload.
	 * @param WafeqRecord       $record    The invoice as Wafeq reports it.
	 * @param string            $reference WC-{id}.
	 * @param array<int,string> $warnings  Warnings (added to).
	 * @return WafeqRecord|ConnectorError
	 */
	private function settle( ApiClient $api, WafeqOptions $options, WafeqOrderPayload $parsed, WafeqRecord $record, string $reference, array &$warnings ): WafeqRecord|ConnectorError {
		if ( ! $record->isDraft() ) {
			return $record;
		}
		$failure = $this->totals->check( $record, $parsed->currency, $parsed->totalMinor, $options->failOnMismatch(), $warnings );
		if ( null !== $failure ) {
			return $failure;
		}
		$changes = array( 'reference' => $reference );
		if ( '' !== $parsed->date && '' !== $record->date() && $record->date() !== $parsed->date ) {
			$changes['invoice_date']     = $parsed->date;
			$changes['invoice_due_date'] = $parsed->date;
		}
		$wantFinal = ( $options->finalize() && 'draft' !== $parsed->flowInvoice ) || 'finalized' === $parsed->flowInvoice;
		if ( $wantFinal ) {
			$changes['status'] = 'FINALIZED';
		}
		$this->docs->patchInvoice( $api, $record->id(), $changes );
		$after = $this->docs->invoice( $api, $record->id() );
		if ( $wantFinal && ! $after->isFinalized() ) {
			return $this->refused( 'status', __( 'Wafeq did not finalize the invoice.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( '' !== $parsed->date && '' !== $after->date() && $after->date() !== $parsed->date ) {
			$warnings[] = __( 'Wafeq changed the date of the invoice.', 'estasoft-erp-crm-sync-engine' );
		}
		return $after;
	}

	/**
	 * Record the payment of a paid order against the finalized invoice.
	 *
	 * @param ApiClient         $api        Client.
	 * @param Connection        $connection Connection.
	 * @param WafeqOptions      $options    Options.
	 * @param Item              $item       Queue item.
	 * @param WafeqOrderPayload $parsed     Payload.
	 * @param WafeqRecord       $record     The finalized invoice.
	 * @param array<int,string> $warnings   Warnings (added to).
	 * @return ConnectorError|null
	 */
	private function pay( ApiClient $api, Connection $connection, WafeqOptions $options, Item $item, WafeqOrderPayload $parsed, WafeqRecord $record, array &$warnings ): ?ConnectorError {
		$wanted = $parsed->flowPayment ?? $options->recordPayment();
		if ( ! $wanted || ! $parsed->paid || ! $record->isFinalized() ) {
			return null;
		}
		$paymentRef = $this->ids->paymentReference( $item );
		if ( array() !== $this->docs->findPayments( $api, $paymentRef ) ) {
			return null;
		}
		$account = $this->catalog->paymentAccountId( $api, $connection->id, $options->paymentAccount() );
		if ( $account instanceof ConnectorError ) {
			return $account;
		}
		$exponent = $this->amounts->exponent( $parsed->currency );
		$total    = $record->totalMinor( $exponent ) ?? $parsed->totalMinor;
		if ( null === $total || $total <= 0 ) {
			$warnings[] = __( 'The payment was not recorded because the invoice total is not known.', 'estasoft-erp-crm-sync-engine' );
			return null;
		}
		$amount = $this->amounts->number( $total, $exponent );
		$body   = array(
			'amount'               => $amount,
			'currency'             => $parsed->currency,
			'date'                 => '' !== $parsed->paymentDate ? $parsed->paymentDate : $this->clock->now()->format( 'Y-m-d' ),
			'paid_through_account' => $account,
			'external_id'          => $paymentRef,
			'reference'            => '' !== $parsed->paymentReference ? $parsed->paymentReference : $paymentRef,
			'invoice_payments'     => array(
				array(
					'invoice' => $record->id(),
					'amount'  => $amount,
				),
			),
		);
		if ( '' !== $record->contactId() ) {
			$body['contact'] = $record->contactId();
		}
		$created = $this->docs->createPayment( $api, $body, $this->ids->idempotencyKey( $item, 'payment' ) );
		return $created instanceof ConnectorError ? $created : null;
	}

	/**
	 * Ask Wafeq to report the finalized invoice to the tax authority, when the connection says so. A refusal of the request is a warning: the
	 * invoice is done and can be reported in Wafeq. A failure that a retry can cure (network, rate limit, server) is raised so the item retries.
	 *
	 * @param ApiClient         $api      Client.
	 * @param WafeqOptions      $options  Options.
	 * @param Item              $item     Queue item.
	 * @param WafeqRecord       $record   The invoice.
	 * @param array<int,string> $warnings Warnings (added to).
	 * @return string The status of the report, or an empty string when none was requested.
	 *
	 * @throws ApiException On a failure a retry can cure.
	 */
	private function report( ApiClient $api, WafeqOptions $options, Item $item, WafeqRecord $record, array &$warnings ): string {
		if ( ! $options->reportsToAuthority() || ! $record->isFinalized() ) {
			return '';
		}
		try {
			return $this->docs->report( $api, $record->id(), $this->ids->idempotencyKey( $item, 'report' ) );
		} catch ( ApiException $exception ) {
			if ( ErrorCode::Val002 !== $exception->error()->code ) {
				throw $exception;
			}
			$warnings[] = sprintf(
				/* translators: %s: reason Wafeq gave. */
				__( 'Wafeq did not accept the request to report the invoice to the tax authority: %s', 'estasoft-erp-crm-sync-engine' ),
				(string) ( $exception->error()->context['reason'] ?? '' )
			);
			return 'refused';
		}
	}

	/**
	 * The facts of an invoice for the read-back: its number, its status as Wafeq reports it, and its date.
	 *
	 * @param ApiClient   $api Client.
	 * @param ExternalRef $ref The synced order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}
	 *
	 * @throws ApiException On failure.
	 */
	public function facts( ApiClient $api, ExternalRef $ref ): array {
		$record = $this->docs->invoice( $api, $ref->id );
		$status = strtoupper( $record->status() );
		return array(
			'number'           => $record->number(),
			'status'           => strtolower( $status ),
			// The report page says to read the invoice to learn the final status [documented]; the field is the document status, so any status beyond the plain lifecycle ones is shown as the authority's.
			'authority_status' => in_array( $status, array( '', 'DRAFT', 'SENT', 'FINALIZED' ), true ) ? '' : strtolower( $status ),
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => $record->date(),
		);
	}

	/**
	 * Whether the invoice needs nothing more from this connector.
	 *
	 * @param WafeqRecord  $record    Invoice.
	 * @param string       $reference WC-{id}.
	 * @param WafeqOptions $options   Options.
	 */
	private function isFinished( WafeqRecord $record, string $reference, WafeqOptions $options ): bool {
		if ( $record->isFinalized() ) {
			return true;
		}
		return ! $options->finalize() && $record->isDraft() && WafeqPayloadReader::text( $record->data()['reference'] ?? '' ) === $reference;
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param WafeqRecord       $record   Invoice.
	 * @param array<int,string> $warnings Warnings.
	 * @param string            $currency  Currency of the order, or empty.
	 * @param string            $authority Status of the tax authority report, or empty when none was requested.
	 */
	private function reference( WafeqRecord $record, array $warnings, string $currency = '', string $authority = '' ): ExternalRef {
		$currency = '' !== $currency ? $currency : $record->currency();
		$extra    = array(
			'currency' => $currency,
			'state'    => $record->isFinalized() ? 'finalized' : 'draft',
		);
		$total    = $record->totalMinor( $this->amounts->exponent( $currency ) );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		if ( '' !== $authority ) {
			$extra['tax_authority'] = $authority;
		}
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( $record->id(), $record->number(), null, $extra );
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
				'system' => WafeqConnector::LABEL,
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
