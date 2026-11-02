<?php
/**
 * Sends a WooCommerce refund to Qoyod as a credit note allocated to the order's invoice.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

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
 * The steps are: find the order's invoice, create the credit note as a draft, check its total, approve it, allocate it to the invoice, and
 * only then drop the "(unchecked)" mark from its description. The mark is what tells a later pass that something is left to do, so a crash
 * between any two steps is picked up where it stopped. The allocations already on the invoice are read before anything is allocated.
 */
final class QoyodRefundSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param QoyodDocuments $docs    Document calls.
	 * @param QoyodCatalog   $catalog Branches, products, tax codes.
	 * @param QoyodLines     $lines   Line builder.
	 * @param QoyodTotals    $totals  Total check.
	 * @param QoyodAmounts   $amounts Conversion helper.
	 * @param QoyodIds       $ids     References.
	 * @param Clock          $clock   Time source.
	 */
	public function __construct(
		private readonly QoyodDocuments $docs,
		private readonly QoyodCatalog $catalog,
		private readonly QoyodLines $lines,
		private readonly QoyodTotals $totals,
		private readonly QoyodAmounts $amounts,
		private readonly QoyodIds $ids,
		private readonly Clock $clock,
	) {
	}

	/**
	 * The credit note of a refund when every step is done (approved, allocated or knowingly not, and without the mark).
	 *
	 * @param ApiClient  $api        Client.
	 * @param Connection $connection Connection.
	 * @param Item       $item       Queue item.
	 * @return ExternalRef|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, Connection $connection, Item $item ): ?ExternalRef {
		$options = QoyodOptions::fromConnection( $connection );
		$records = $this->docs->findCreditNotes( $api, $this->ids->reference( $item ) );
		if ( array() === $records || $records[0]->isUnchecked() || ! $records[0]->isPosted() ) {
			return null;
		}
		return $this->reference( $records[0], $options->currency(), array() );
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
		$options = QoyodOptions::fromConnection( $connection );
		$parsed  = QoyodRefundPayload::parse( $payload );
		if ( $parsed instanceof ConnectorError ) {
			return SendResult::failure( $parsed );
		}
		$invoices = $this->docs->findInvoices( $api, $parsed->orderRef );
		if ( array() === $invoices ) {
			return SendResult::failure( $this->refused( 'order', __( 'The order of this refund is not in Qoyod.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$invoice = $this->docs->invoice( $api, $invoices[0]->id() );
		if ( ! $invoice->isPosted() ) {
			return SendResult::failure( $this->refused( 'order', __( 'The invoice of this order is still a draft in Qoyod. Approve it first.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$currency  = $options->currency();
		$reference = $this->ids->reference( $item );
		$found     = $this->docs->findCreditNotes( $api, $reference );
		$existed   = array() !== $found;
		$warnings  = array();

		if ( $existed ) {
			$record = $this->docs->creditNote( $api, $found[0]->id() );
		} else {
			$made = $this->create( $api, $connection, $options, $item, $parsed, $invoice, $reference );
			if ( $made instanceof ConnectorError ) {
				return SendResult::failure( $made );
			}
			$record = $made;
		}

		if ( $record->isDraft() ) {
			$failure = $this->totals->check( $record, $currency, $parsed->expectedTotal(), $options->failOnMismatch(), $warnings );
			if ( null !== $failure ) {
				return SendResult::failure( $failure );
			}
			$this->docs->patchCreditNote( $api, $record->id(), array( 'status' => 'Approved' ) );
			$record = $this->docs->creditNote( $api, $record->id() );
			if ( ! $record->isPosted() ) {
				return SendResult::failure( $this->refused( 'status', __( 'Qoyod did not approve the credit note.', 'estasoft-erp-crm-sync-engine' ) ) );
			}
		}

		$this->allocate( $api, $invoice, $record, $currency, $parsed, $warnings );
		if ( $record->isUnchecked() ) {
			$this->docs->patchCreditNote( $api, $record->id(), array( 'description' => $this->describe( $reference, $parsed, false ) ) );
		}

		$ref = $this->reference( $record, $currency, $warnings );
		return $existed ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * Create the credit note as a draft.
	 *
	 * @param ApiClient          $api        Client.
	 * @param Connection         $connection Connection.
	 * @param QoyodOptions       $options    Options.
	 * @param Item               $item       Queue item.
	 * @param QoyodRefundPayload $parsed     Payload.
	 * @param QoyodRecord        $invoice    The order's invoice.
	 * @param string             $reference  WC-R-{id}.
	 * @return QoyodRecord|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( ApiClient $api, Connection $connection, QoyodOptions $options, Item $item, QoyodRefundPayload $parsed, QoyodRecord $invoice, string $reference ): QoyodRecord|ConnectorError {
		unset( $item );
		$branch = $this->catalog->branchId( $api, $connection->id, $options->inventoryId() );
		if ( $branch instanceof ConnectorError ) {
			return $branch;
		}
		$rows = $this->rows( $api, $options, $parsed );
		if ( $rows instanceof ConnectorError ) {
			return $rows;
		}
		$built = $this->lines->build( $rows, $options->currency(), false, $options->taxField(), $branch, $this->catalog->standardTaxes() );
		if ( $built instanceof ConnectorError ) {
			return $built;
		}
		$date    = '' !== $parsed->date ? $parsed->date : $this->clock->now()->format( 'Y-m-d' );
		$body    = array(
			'contact_id'            => (int) $invoice->contactId(),
			'reference'             => $reference,
			'issue_date'            => $date,
			'due_date'              => $date,
			'status'                => 'Draft',
			'inventory_id'          => (int) $branch,
			'description'           => $this->describe( $reference, $parsed, true ),
			'line_items_attributes' => $built['lines'],
		);
		$created = $this->docs->createCreditNote( $api, $body );
		if ( $created instanceof ConnectorError ) {
			return $created;
		}
		return $this->docs->creditNote( $api, $created->id() );
	}

	/**
	 * The rows of the credit note: refunded product lines, shipping and other amounts. A refund with nothing else becomes one row.
	 *
	 * @param ApiClient          $api     Client.
	 * @param QoyodOptions       $options Options.
	 * @param QoyodRefundPayload $parsed  Payload.
	 * @return array<int,array<string,mixed>>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function rows( ApiClient $api, QoyodOptions $options, QoyodRefundPayload $parsed ): array|ConnectorError {
		$rows = array();
		foreach ( $parsed->lines as $line ) {
			$id = (string) $line['item'];
			if ( '' === $id ) {
				$found = '' !== (string) $line['sku'] ? $this->catalog->productBySku( $api, (string) $line['sku'] ) : null;
				if ( null === $found ) {
					return new ConnectorError(
						ErrorCode::Map001,
						array(
							'sku'    => (string) $line['sku'],
							'system' => QoyodConnector::LABEL,
						),
						null,
						null,
						null,
						'sku not found'
					);
				}
				$id = QoyodPayloadReader::text( $found['id'] ?? '' );
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
		$plain = array();
		if ( $parsed->shippingMinor > 0 ) {
			$plain[] = array( __( 'Shipping', 'estasoft-erp-crm-sync-engine' ), $parsed->shippingMinor, $options->shippingSku(), 'shipping_product_sku' );
		}
		if ( $parsed->otherMinor > 0 ) {
			$plain[] = array( __( 'Other refund', 'estasoft-erp-crm-sync-engine' ), $parsed->otherMinor, $options->feeSku(), 'fee_product_sku' );
		}
		if ( array() === $rows && array() === $plain ) {
			$plain[] = array( __( 'Refund', 'estasoft-erp-crm-sync-engine' ), $parsed->expectedTotal(), $options->feeSku(), 'fee_product_sku' );
		}
		foreach ( $plain as $entry ) {
			if ( '' === $entry[2] ) {
				return $this->refused( $entry[3], __( 'Qoyod has no lines without a product. Name a service product for shipping and fees in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$product = $this->catalog->productBySku( $api, $entry[2] );
			if ( null === $product ) {
				return new ConnectorError(
					ErrorCode::Map001,
					array(
						'sku'    => $entry[2],
						'system' => QoyodConnector::LABEL,
					),
					null,
					null,
					null,
					'sku not found'
				);
			}
			$rows[] = array(
				'item'        => QoyodPayloadReader::text( $product['id'] ?? '' ),
				'name'        => $entry[0],
				'quantity'    => 1.0,
				'total_minor' => $entry[1],
				'tax_code'    => '',
				'tax_amount'  => null,
			);
		}
		return $rows;
	}

	/**
	 * Allocate the credit note to the invoice when Qoyod allows it. A refusal is a warning: the credit note is posted and correct.
	 *
	 * @param ApiClient          $api      Client.
	 * @param QoyodRecord        $invoice  The invoice (as read before the credit note was made).
	 * @param QoyodRecord        $note     The posted credit note.
	 * @param string             $currency Account currency.
	 * @param QoyodRefundPayload $parsed   Payload.
	 * @param array<int,string>  $warnings Warnings (added to).
	 *
	 * @throws ApiException When a request other than the allocation itself fails.
	 */
	private function allocate( ApiClient $api, QoyodRecord $invoice, QoyodRecord $note, string $currency, QoyodRefundPayload $parsed, array &$warnings ): void {
		$fresh     = $this->docs->invoice( $api, $invoice->id() );
		$allocated = $fresh->allocatedCreditNotes();
		if ( null === $allocated ) {
			$warnings[] = __( 'The credit note was not allocated because Qoyod did not show what the invoice already has.', 'estasoft-erp-crm-sync-engine' );
			return;
		}
		if ( in_array( $note->id(), $allocated, true ) ) {
			return;
		}
		$exponent = $this->amounts->exponent( $currency );
		$total    = $note->totalMinor( $exponent ) ?? $parsed->expectedTotal();
		$due      = $fresh->dueMinor( $exponent );
		if ( $fresh->isPaid() || null === $due || $due < $total || $total <= 0 ) {
			$warnings[] = __( 'The credit note was not allocated because the invoice is paid or owes less than the credit note. It is approved and can be allocated in Qoyod.', 'estasoft-erp-crm-sync-engine' );
			return;
		}
		$date = '' !== $parsed->date ? $parsed->date : $this->clock->now()->format( 'Y-m-d' );
		try {
			$this->docs->allocateCreditNote( $api, $fresh->id(), $note->id(), $this->amounts->decimalString( $total, $exponent ), $date );
		} catch ( ApiException $exception ) {
			if ( ErrorCode::Val002 !== $exception->error()->code ) {
				throw $exception;
			}
			$warnings[] = __( 'Qoyod did not accept the allocation of the credit note to the invoice. It is approved and can be allocated in Qoyod.', 'estasoft-erp-crm-sync-engine' );
		}
	}

	/**
	 * The description of a credit note: the reference with the mark while it has not passed, then the order and the reason.
	 *
	 * @param string             $reference WC-R-{id}.
	 * @param QoyodRefundPayload $parsed    Payload.
	 * @param bool               $unchecked Whether the mark is added.
	 */
	private function describe( string $reference, QoyodRefundPayload $parsed, bool $unchecked ): string {
		$text = $reference . ( $unchecked ? QoyodIds::UNCHECKED : '' );
		$what = '' !== $parsed->reason
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
		return $text . "\n" . QoyodPayloadReader::shorten( $what, 500 );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param QoyodRecord       $record   Credit note.
	 * @param string            $currency Account currency.
	 * @param array<int,string> $warnings Warnings.
	 */
	private function reference( QoyodRecord $record, string $currency, array $warnings ): ExternalRef {
		$extra = array(
			'currency' => $currency,
			'state'    => $record->isPosted() ? 'approved' : 'draft',
		);
		$total = $record->totalMinor( $this->amounts->exponent( $currency ) );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( $record->id(), $record->reference(), null, $extra );
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
				'system' => QoyodConnector::LABEL,
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
