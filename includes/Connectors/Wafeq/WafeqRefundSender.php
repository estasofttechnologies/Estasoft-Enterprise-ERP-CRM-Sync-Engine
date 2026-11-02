<?php
/**
 * Sends a WooCommerce refund to Wafeq as a credit note applied to the order's invoice.
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
 * The steps are: find the order's invoice, create the credit note as a draft, check its total, finalize it, apply it to the invoice, and
 * only then drop the "(unchecked)" mark from its reference. The mark is what tells a later pass that something is left to do, so a crash
 * between any two steps is picked up where it stopped. Wafeq lets the same credit note be applied to the same invoice more than once
 * [documented], so the applications already on the invoice are read before anything is applied.
 */
final class WafeqRefundSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param WafeqDocuments $docs    Document calls.
	 * @param WafeqCatalog   $catalog Tax rates, accounts, items.
	 * @param WafeqLines     $lines   Line builder.
	 * @param WafeqTotals    $totals  Total check.
	 * @param WafeqAmounts   $amounts Conversion helper.
	 * @param WafeqIds       $ids     References and keys.
	 * @param Clock          $clock   Time source.
	 */
	public function __construct(
		private readonly WafeqDocuments $docs,
		private readonly WafeqCatalog $catalog,
		private readonly WafeqLines $lines,
		private readonly WafeqTotals $totals,
		private readonly WafeqAmounts $amounts,
		private readonly WafeqIds $ids,
		private readonly Clock $clock,
	) {
	}

	/**
	 * The credit note of a refund when every step is done (its reference has lost the "(unchecked)" mark).
	 *
	 * @param ApiClient $api  Client.
	 * @param Item      $item Queue item.
	 * @return ExternalRef|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, Item $item ): ?ExternalRef {
		$reference = $this->ids->reference( $item );
		$records   = $this->docs->findCreditNotes( $api, $reference );
		if ( array() === $records ) {
			return null;
		}
		$record = $records[0];
		if ( WafeqPayloadReader::text( $record->data()['reference'] ?? '' ) !== $reference ) {
			return null;
		}
		return $this->reference( $record, array(), '' );
	}

	/**
	 * Send a refund.
	 *
	 * @param ApiClient           $api        Client.
	 * @param Connection          $connection Connection.
	 * @param Item                $item       Queue item.
	 * @param array<string,mixed> $payload    Mapped payload.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( ApiClient $api, Connection $connection, Item $item, array $payload ): SendResult {
		$options = WafeqOptions::fromConnection( $connection );
		$parsed  = WafeqRefundPayload::parse( $payload );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		$invoices = $this->docs->findInvoices( $api, $parsed->orderRef );
		if ( array() === $invoices ) {
			return SendResult::failure( $this->refused( 'order', __( 'The order of this refund is not in Wafeq.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$invoice  = $this->docs->invoice( $api, $invoices[0]->id() );
		$currency = '' !== $invoice->currency() ? $invoice->currency() : $parsed->currency;
		if ( '' === $currency ) {
			return SendResult::failure( $this->refused( 'currency', __( 'Wafeq did not report the currency of the invoice and the refund has none.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$exponent  = $this->amounts->exponent( $currency );
		$reference = $this->ids->reference( $item );
		$found     = $this->docs->findCreditNotes( $api, $reference );
		$existed   = array() !== $found;
		$warnings  = array();

		if ( $existed ) {
			$record = $this->docs->creditNote( $api, $found[0]->id() );
		} else {
			$record = $this->create( $api, $connection, $item, $options, $parsed, $invoice, $currency, $reference );
			if ( $record instanceof ConnectorError ) {
				return SendResult::failure( $record );
			}
		}

		if ( $record->isDraft() ) {
			$failure = $this->totals->check( $record, $currency, $parsed->expectedTotal(), $options->failOnMismatch(), $warnings );
			if ( null !== $failure ) {
				return SendResult::failure( $failure );
			}
			$this->docs->patchCreditNote( $api, $record->id(), array( 'status' => 'FINALIZED' ) );
			$record = $this->docs->creditNote( $api, $record->id() );
			if ( ! $record->isFinalized() ) {
				return SendResult::failure( $this->refused( 'status', __( 'Wafeq did not finalize the credit note.', 'estasoft-erp-crm-sync-engine' ) ) );
			}
		}

		$this->apply( $api, $item, $invoice, $record, $exponent, $parsed, $warnings );
		if ( WafeqPayloadReader::text( $record->data()['reference'] ?? '' ) !== $reference ) {
			$this->docs->patchCreditNote( $api, $record->id(), array( 'reference' => $reference ) );
		}

		$ref = $this->reference( $record, $warnings, $currency );
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Create the credit note as a draft.
	 *
	 * @param ApiClient          $api        Client.
	 * @param Connection         $connection Connection.
	 * @param Item               $item       Queue item.
	 * @param WafeqOptions       $options    Options.
	 * @param WafeqRefundPayload $parsed     Payload.
	 * @param WafeqRecord        $invoice    The order's invoice.
	 * @param string             $currency   Currency of the invoice.
	 * @param string             $reference  WC-R-{id}.
	 * @return WafeqRecord|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( ApiClient $api, Connection $connection, Item $item, WafeqOptions $options, WafeqRefundPayload $parsed, WafeqRecord $invoice, string $currency, string $reference ): WafeqRecord|ConnectorError {
		$rates   = $this->catalog->taxRates( $api, $connection->id );
		$account = $this->catalog->salesAccountId( $api, $connection->id, $options->salesAccount() );
		if ( $account instanceof ConnectorError ) {
			return $account;
		}
		$rows  = $this->rows( $api, $connection, $parsed );
		$built = $this->lines->build( $rows, $currency, false, $rates, $account );
		if ( $built instanceof ConnectorError ) {
			return $built;
		}
		$date = '' !== $parsed->date ? $parsed->date : $this->clock->now()->format( 'Y-m-d' );
		$note = '' !== $parsed->reason
			? sprintf(
				/* translators: 1: order reference, 2: refund reason. */
				__( 'Refund of %1$s: %2$s', 'estasoft-erp-crm-sync-engine' ),
				$parsed->orderRef,
				$parsed->reason
			)
			: sprintf(
				/* translators: %s: order reference. */
				__( 'Refund of %s', 'estasoft-erp-crm-sync-engine' ),
				$parsed->orderRef
			);
		$body = array(
			'contact'            => $invoice->contactId(),
			'currency'           => $currency,
			'credit_note_date'   => $date,
			'credit_note_number' => $this->ids->documentNumber( $options->numberPrefix(), 'R-' . $item->entityId ),
			'external_id'        => $reference,
			'reference'          => $reference . WafeqOrderSender::UNCHECKED,
			'status'             => 'DRAFT',
			'tax_amount_type'    => 'TAX_EXCLUSIVE',
			'language'           => $options->language(),
			'notes'              => WafeqPayloadReader::shorten( $note, 1000 ),
			'line_items'         => $built['lines'],
		);
		if ( '' !== $options->placeOfSupply() ) {
			$body['place_of_supply'] = $options->placeOfSupply();
		}
		try {
			$created = $this->docs->createCreditNote( $api, $body, $this->ids->idempotencyKey( $item, 'credit-note' ) );
		} catch ( ApiException $exception ) {
			$this->catalog->itemRefused( $exception, $connection->id );
			throw $exception;
		}
		if ( $created instanceof ConnectorError ) {
			return $created;
		}
		return $this->docs->creditNote( $api, $created->id() );
	}

	/**
	 * The rows of the credit note: refunded product lines, shipping and other amounts. A refund with nothing else becomes one row.
	 *
	 * @param ApiClient          $api        Client.
	 * @param Connection         $connection Connection.
	 * @param WafeqRefundPayload $parsed     Payload.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	private function rows( ApiClient $api, Connection $connection, WafeqRefundPayload $parsed ): array {
		$rows = array();
		foreach ( $parsed->lines as $line ) {
			$item = (string) $line['item'];
			if ( '' === $item && '' !== (string) $line['sku'] ) {
				$found = $this->catalog->itemBySku( $api, $connection->id, (string) $line['sku'] );
				$item  = null !== $found ? WafeqPayloadReader::text( $found['id'] ?? '' ) : '';
			}
			$rows[] = array(
				'item'        => $item,
				'name'        => '' !== (string) $line['name'] ? (string) $line['name'] : __( 'Refund', 'estasoft-erp-crm-sync-engine' ),
				'quantity'    => (float) $line['quantity'] > 0.0 ? (float) $line['quantity'] : 1.0,
				'total_minor' => (int) $line['amount_minor'],
				'tax_code'    => '',
				'tax_amount'  => $line['tax_amount'],
			);
		}
		if ( $parsed->shippingMinor > 0 ) {
			$rows[] = $this->plain( __( 'Shipping', 'estasoft-erp-crm-sync-engine' ), $parsed->shippingMinor );
		}
		if ( $parsed->otherMinor > 0 ) {
			$rows[] = $this->plain( __( 'Other refund', 'estasoft-erp-crm-sync-engine' ), $parsed->otherMinor );
		}
		if ( array() === $rows ) {
			$rows[] = $this->plain( __( 'Refund', 'estasoft-erp-crm-sync-engine' ), $parsed->expectedTotal() );
		}
		return $rows;
	}

	/**
	 * A row without tax.
	 *
	 * @param string $name  Description.
	 * @param int    $total Amount in minor units.
	 * @return array<string,mixed>
	 */
	private function plain( string $name, int $total ): array {
		return array(
			'item'        => '',
			'name'        => $name,
			'quantity'    => 1.0,
			'total_minor' => $total,
			'tax_code'    => '',
			'tax_amount'  => null,
		);
	}

	/**
	 * Apply the credit note to the invoice, unless it already is. A failure here is a warning: the credit note is posted and correct.
	 *
	 * @param ApiClient          $api      Client.
	 * @param Item               $item     Queue item.
	 * @param WafeqRecord        $invoice  The invoice.
	 * @param WafeqRecord        $note     The posted credit note.
	 * @param int                $exponent Currency exponent.
	 * @param WafeqRefundPayload $parsed   Payload.
	 * @param array<int,string>  $warnings Warnings (added to).
	 *
	 * @throws ApiException When a request other than the application itself fails.
	 */
	private function apply( ApiClient $api, Item $item, WafeqRecord $invoice, WafeqRecord $note, int $exponent, WafeqRefundPayload $parsed, array &$warnings ): void {
		if ( ! $invoice->isFinalized() ) {
			$warnings[] = __( 'The credit note was not applied because the invoice is not posted.', 'estasoft-erp-crm-sync-engine' );
			return;
		}
		$applied = $invoice->appliedCreditNotes();
		if ( null === $applied ) {
			$warnings[] = __( 'The credit note was not applied because Wafeq did not show which credit notes the invoice already has.', 'estasoft-erp-crm-sync-engine' );
			return;
		}
		if ( isset( $applied[ $note->id() ] ) ) {
			return;
		}
		$total = $note->totalMinor( $exponent ) ?? $parsed->expectedTotal();
		if ( $total <= 0 ) {
			return;
		}
		try {
			$this->docs->applyCreditNote( $api, $note->id(), $invoice->id(), $this->amounts->number( $total, $exponent ), $this->ids->idempotencyKey( $item, 'apply' ) );
		} catch ( ApiException $exception ) {
			if ( ErrorCode::Val002 !== $exception->error()->code ) {
				throw $exception;
			}
			$warnings[] = __( 'Wafeq did not accept the application of the credit note to the invoice. It is posted and can be applied in Wafeq.', 'estasoft-erp-crm-sync-engine' );
		}
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param WafeqRecord       $record   Credit note.
	 * @param array<int,string> $warnings Warnings.
	 * @param string            $currency Currency, or empty to use the record's.
	 */
	private function reference( WafeqRecord $record, array $warnings, string $currency ): ExternalRef {
		$currency = '' !== $currency ? $currency : $record->currency();
		$extra    = array(
			'currency' => $currency,
			'state'    => $record->isFinalized() ? 'finalized' : 'draft',
		);
		$total    = $record->totalMinor( $this->amounts->exponent( $currency ) );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
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
