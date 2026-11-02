<?php
/**
 * Sends a WooCommerce refund to Daftra as a credit note.
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
 * The credit note is made in one step (the documented draft call is for invoices), so the guard is a check afterwards: its currency and total must
 * match the refund, and a credit note that does not is deleted and reported. The credit note list has a `client_id` filter and no reference
 * filter, so a credit note is found by reading the credit notes of the invoice's client and matching the purchase-order reference locally. That
 * finds it however late the retry comes.
 */
final class DaftraRefundSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param DaftraDocuments $docs    Document calls.
	 * @param DaftraCatalog   $catalog Taxes, products, warehouses.
	 * @param DaftraLines     $lines   Line builder.
	 * @param DaftraTotals    $totals  Total check.
	 * @param DaftraAmounts   $amounts Conversion helper.
	 * @param DaftraIds       $ids     References.
	 * @param Clock           $clock   Time source.
	 */
	public function __construct(
		private readonly DaftraDocuments $docs,
		private readonly DaftraCatalog $catalog,
		private readonly DaftraLines $lines,
		private readonly DaftraTotals $totals,
		private readonly DaftraAmounts $amounts,
		private readonly DaftraIds $ids,
		private readonly Clock $clock,
	) {
	}

	/**
	 * The credit note of a refund, when it exists.
	 *
	 * @param ApiClient $api  Client.
	 * @param Item      $item Queue item.
	 * @return ExternalRef|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, Item $item ): ?ExternalRef {
		if ( null !== $item->externalId && '' !== $item->externalId ) {
			$linked = $this->docs->creditNoteIfExists( $api, $item->externalId );
			if ( null !== $linked ) {
				return $this->reference( $linked, array() );
			}
		}
		$orderRef = $this->ids->orderReference( $item );
		if ( '' === $orderRef ) {
			return null;
		}
		$invoices = $this->docs->findInvoices( $api, $orderRef );
		if ( array() === $invoices ) {
			return null;
		}
		$clientId = $this->docs->invoice( $api, $invoices[0]->id() )->clientId();
		$found    = '' === $clientId ? array() : $this->docs->findCreditNotes( $api, $this->ids->reference( $item ), $clientId );
		if ( array() === $found ) {
			return null;
		}
		return $this->reference( $this->docs->creditNote( $api, $found[0]->id() ), array() );
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
		$options = DaftraOptions::fromConnection( $connection );
		$parsed  = DaftraRefundPayload::parse( $payload );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		$reference = $this->ids->reference( $item );
		$invoices  = $this->docs->findInvoices( $api, $parsed->orderRef );
		if ( array() === $invoices ) {
			return SendResult::failure( $this->refused( 'order', __( 'The order of this refund is not in Daftra.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$invoice  = $this->docs->invoice( $api, $invoices[0]->id() );
		$existing = '' === $invoice->clientId() ? array() : $this->docs->findCreditNotes( $api, $reference, $invoice->clientId() );
		if ( array() !== $existing ) {
			return SendResult::existing( $this->reference( $this->docs->creditNote( $api, $existing[0]->id() ), array() ) );
		}
		if ( $invoice->isDraft() ) {
			return SendResult::failure( $this->refused( 'order', __( 'The invoice of this order is still a draft in Daftra. Issue it first.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$currency = '' !== $invoice->currency() ? $invoice->currency() : $parsed->currency;
		if ( '' === $currency ) {
			return SendResult::failure( $this->refused( 'currency', __( 'Daftra did not report the currency of the invoice and the refund has none.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$taxes = $this->catalog->taxes( $api, $connection->id );
		$rows  = $this->rows( $api, $parsed );
		$built = $this->lines->build( $rows, $currency, false, $taxes, $options->storeId() );
		if ( $built instanceof ConnectorError ) {
			return SendResult::failure( $built );
		}
		$fields = array(
			'client_id'     => (int) $invoice->clientId(),
			'po_number'     => $reference,
			'date'          => '' !== $parsed->date ? $parsed->date : $this->clock->now()->format( 'Y-m-d' ),
			'currency_code' => $currency,
			'notes'         => $this->note( $parsed ),
		);
		if ( $options->linkCreditNotes() ) {
			$fields['subscription_id'] = (int) $invoice->id();
		}
		if ( '' !== $options->storeId() ) {
			$fields['store_id'] = (int) $options->storeId();
		}
		$id = $this->docs->createCreditNote( $api, $fields, $built['lines'] );
		if ( $id instanceof ConnectorError ) {
			return SendResult::failure( $id );
		}
		$record   = $this->docs->creditNote( $api, $id );
		$warnings = array();
		$failure  = $this->totals->check( $record, $currency, $parsed->expectedTotal(), $options->failOnMismatch(), $warnings );
		if ( null !== $failure ) {
			$note = '';
			try {
				$this->docs->deleteCreditNote( $api, $id );
			} catch ( ApiException $exception ) {
				unset( $exception );
				$note = __( 'The credit note could not be removed. Delete it in Daftra before syncing the refund again.', 'estasoft-erp-crm-sync-engine' );
			}
			$context           = $failure->context;
			$context['reason'] = trim( (string) ( $context['reason'] ?? '' ) . ' ' . $note );
			return SendResult::failure( new ConnectorError( $failure->code, $context, $failure->httpStatus, $failure->retryAfter, $failure->classOverride, $failure->detail ) );
		}
		return SendResult::created( $this->reference( $record, $warnings ) );
	}

	/**
	 * The rows of the credit note: refunded product lines, shipping and other amounts. A refund with nothing else becomes one row.
	 *
	 * @param ApiClient           $api    Client.
	 * @param DaftraRefundPayload $parsed Payload.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	private function rows( ApiClient $api, DaftraRefundPayload $parsed ): array {
		$rows = array();
		foreach ( $parsed->lines as $line ) {
			$id = (string) $line['item'];
			if ( '' === $id && '' !== (string) $line['sku'] ) {
				$found = $this->catalog->productBySku( $api, (string) $line['sku'] );
				$id    = null !== $found ? DaftraPayloadReader::text( $found['id'] ?? '' ) : '';
			}
			$rows[] = array(
				'item'        => $id,
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
	 * @param string $name  Text of the item.
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
	 * The note of the credit note: the order and the reason.
	 *
	 * @param DaftraRefundPayload $parsed Payload.
	 */
	private function note( DaftraRefundPayload $parsed ): string {
		$text = '' !== $parsed->reason
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
		return DaftraPayloadReader::shorten( $text, 1000 );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param DaftraRecord      $record   Credit note.
	 * @param array<int,string> $warnings Warnings.
	 */
	private function reference( DaftraRecord $record, array $warnings ): ExternalRef {
		$currency = $record->currency();
		$extra    = array(
			'currency' => $currency,
			'state'    => 'issued',
		);
		$total    = $record->totalMinor( $this->amounts->exponent( $currency ) );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( $record->id(), '' !== $record->number() ? $record->number() : $record->reference(), null, $extra );
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
