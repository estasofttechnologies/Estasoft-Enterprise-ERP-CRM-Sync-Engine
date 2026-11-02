<?php
/**
 * Sends a WooCommerce order to Holded as an invoice or a sales order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Holded;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;

/**
 * Holded v1 has no draft step: a document is created complete, and an invoice is numbered at once. So the lines are added up here first and
 * nothing is sent when they do not match the order; the document is read back after creation; a sales order that still fails is deleted, and an
 * invoice is left (a numbered invoice is corrected by a credit note, not deleted) with an error that names it. The first line of the notes is the
 * marker `[WC-{id}] amount currency`, which is what a retry searches for and what tells a failed invoice from a finished one.
 */
final class HoldedOrderSender {

	/**
	 * Set up with what it depends on.
	 *
	 * @param HoldedDocuments $docs     Document calls.
	 * @param HoldedContacts  $contacts Contacts.
	 * @param HoldedCatalog   $catalog  Products.
	 * @param HoldedLines     $lines    Line builder.
	 * @param HoldedTotals    $totals   Total checks.
	 * @param HoldedAmounts   $amounts  Conversion helper.
	 * @param HoldedIds       $ids      References.
	 * @param HoldedDates     $dates    Dates.
	 */
	public function __construct(
		private readonly HoldedDocuments $docs,
		private readonly HoldedContacts $contacts,
		private readonly HoldedCatalog $catalog,
		private readonly HoldedLines $lines,
		private readonly HoldedTotals $totals,
		private readonly HoldedAmounts $amounts,
		private readonly HoldedIds $ids,
		private readonly HoldedDates $dates,
	) {
	}

	/**
	 * The finished document of an order, when it exists: found through the id the engine linked, or through the marker among recent documents.
	 *
	 * @param ApiClient  $api        Client.
	 * @param Connection $connection Connection.
	 * @param Item       $item       Queue item.
	 * @return ExternalRef|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, Connection $connection, Item $item ): ?ExternalRef {
		$type      = HoldedOptions::fromConnection( $connection )->documentType();
		$reference = $this->ids->reference( $item );
		$record    = null;
		if ( null !== $item->externalId && '' !== $item->externalId ) {
			$record = $this->docs->getIfExists( $api, $type, $item->externalId );
		}
		if ( null === $record ) {
			$window = $this->dates->recent();
			$record = $this->docs->find( $api, $type, $reference, $window[0], $window[1] );
		}
		if ( null === $record ) {
			return null;
		}
		$record = $this->complete( $api, $type, $record );
		if ( ! $this->totals->kept( $record, $this->ids->promise( $record->notes(), $reference ) ) ) {
			return null;
		}
		return $this->reference( $record, $type, $reference, array() );
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
		$options = HoldedOptions::fromConnection( $connection );
		$order   = HoldedOrderPayload::parse( $payload, $item );
		if ( $order instanceof ConnectorError ) {
			return SendResult::failure( $order );
		}
		if ( '' === $order->currency ) {
			return SendResult::failure( $this->refused( 'currency', __( 'The order has no currency.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$type      = $options->documentType();
		$reference = $this->ids->reference( $item );
		$date      = '' !== $order->date ? $order->date : $this->dates->today();
		$window    = $this->dates->around( $date );
		$warnings  = array();

		$existing = $this->docs->find( $api, $type, $reference, $window[0], $window[1] );
		if ( null !== $existing ) {
			$record  = $this->complete( $api, $type, $existing );
			$failure = $this->totals->check( $record, $order->currency, $order->totalMinor, $options->failOnMismatch(), $warnings, $this->lineAllowance( $record, $order ) );
			if ( null !== $failure ) {
				return SendResult::failure( $this->staysInHolded( $failure, $record ) );
			}
			return SendResult::existing( $this->reference( $record, $type, $reference, $warnings ) );
		}

		$rows = $this->rows( $api, $connection, $options, $order );
		if ( $rows instanceof ConnectorError ) {
			return SendResult::failure( $rows );
		}
		$built = $this->lines->build( $rows, $order->currency, $order->pricesIncludeTax );
		if ( $built instanceof ConnectorError ) {
			return SendResult::failure( $built );
		}
		$failure = $this->totals->plan( $built['total_minor'], $order->totalMinor, $order->currency, $options->failOnMismatch(), $warnings, count( $built['items'] ) );
		if ( null !== $failure ) {
			return SendResult::failure( $failure );
		}
		$contact = $this->contacts->resolve( $api, $connection->id, $order->customer );
		if ( $contact instanceof ConnectorError ) {
			return SendResult::failure( $contact );
		}

		$expected = $order->totalMinor ?? $built['total_minor'];
		$body     = array(
			'contactId' => $contact,
			'date'      => $this->dates->stamp( $date ),
			'currency'  => strtolower( $order->currency ),
			'notes'     => $this->notes( $item, $order, $expected ),
			'items'     => $built['items'],
		);
		foreach ( array(
			'warehouseId'     => $options->warehouseId(),
			'salesChannelId'  => $options->salesChannelId(),
			'paymentMethodId' => $options->paymentMethodId(),
		) as $field => $value ) {
			if ( '' !== $value ) {
				$body[ $field ] = $value;
			}
		}
		$id = $this->docs->create( $api, $type, $body );
		if ( $id instanceof ConnectorError ) {
			return SendResult::failure( $id );
		}
		$record  = $this->docs->get( $api, $type, $id );
		$failure = $this->totals->check( $record, $order->currency, $expected, $options->failOnMismatch(), $warnings, count( $built['items'] ) );
		if ( null !== $failure ) {
			return SendResult::failure( $this->discard( $api, $type, $record, $failure ) );
		}
		return SendResult::created( $this->reference( $record, $type, $reference, array_values( array_unique( $warnings ) ) ) );
	}

	/**
	 * The product rows, then the shipping and fee rows. A SKU Holded does not know stops the order unless the option sends a text line.
	 *
	 * @param ApiClient          $api        Client.
	 * @param Connection         $connection Connection.
	 * @param HoldedOptions      $options    Options.
	 * @param HoldedOrderPayload $order      Parsed payload.
	 * @return array<int,array<string,mixed>>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function rows( ApiClient $api, Connection $connection, HoldedOptions $options, HoldedOrderPayload $order ): array|ConnectorError {
		$rows = array();
		foreach ( $order->lines as $line ) {
			$link = '';
			$sku  = (string) $line['sku'];
			if ( '' !== $sku ) {
				$product = $this->catalog->productBySku( $api, $connection->id, $sku );
				if ( null !== $product ) {
					$link = HoldedPayloadReader::text( $product['sku'] ?? $sku );
				} elseif ( ! $options->freeTextForUnknownSku() ) {
					return new ConnectorError(
						ErrorCode::Map001,
						array(
							'sku'    => $sku,
							'system' => HoldedConnector::LABEL,
						),
						null,
						null,
						null,
						'sku unknown'
					);
				}
			}
			$rows[] = array(
				'sku'         => $link,
				'name'        => (string) $line['name'],
				'quantity'    => (float) $line['quantity'],
				'total_minor' => (int) $line['total_minor'],
				'tax_code'    => (string) $line['tax_code'],
				'tax_amount'  => null,
			);
		}
		foreach ( $order->extras as $extra ) {
			$rows[] = array(
				'sku'         => '',
				'name'        => (string) $extra['name'],
				'quantity'    => 1.0,
				'total_minor' => (int) $extra['total_minor'],
				'tax_code'    => (string) $extra['tax_code'],
				'tax_amount'  => $extra['tax_amount'],
			);
		}
		return $rows;
	}

	/**
	 * A document with its total, read from Holded when a list row did not carry it.
	 *
	 * @param ApiClient    $api    Client.
	 * @param string       $type   Document type.
	 * @param HoldedRecord $record Record found by the search.
	 *
	 * @throws ApiException On failure.
	 */
	private function complete( ApiClient $api, string $type, HoldedRecord $record ): HoldedRecord {
		return ! $record->hasTotal() || '' === $record->currency() ? $this->docs->get( $api, $type, $record->id() ) : $record;
	}

	/**
	 * How many minor units a total may differ by: one per line of the document, or of the order when the document does not say.
	 *
	 * @param HoldedRecord       $record Document.
	 * @param HoldedOrderPayload $order  Order.
	 */
	private function lineAllowance( HoldedRecord $record, HoldedOrderPayload $order ): int {
		return $record->lineCount() ?? ( count( $order->lines ) + count( $order->extras ) );
	}

	/**
	 * The notes: the marker line, the order number when it differs from the id, then the customer's note.
	 *
	 * @param Item               $item     Queue item.
	 * @param HoldedOrderPayload $order    Parsed payload.
	 * @param int                $expected What the document should add up to.
	 */
	private function notes( Item $item, HoldedOrderPayload $order, int $expected ): string {
		$exponent = $this->amounts->exponent( $order->currency );
		$parts    = array( $this->ids->line( $this->ids->reference( $item ), $this->amounts->decimalString( $expected, $exponent ), $order->currency ) );
		if ( '' !== $order->number && $order->number !== $item->entityId ) {
			$parts[] = sprintf(
				/* translators: %s: order number. */
				__( 'Order number: %s', 'estasoft-erp-crm-sync-engine' ),
				$order->number
			);
		}
		if ( '' !== $order->note ) {
			$parts[] = $order->note;
		}
		return HoldedPayloadReader::shorten( implode( "\n", $parts ), 2000 );
	}

	/**
	 * What to do with a document that failed its check: a sales order is deleted, an invoice stays and the error names it.
	 *
	 * @param ApiClient      $api     Client.
	 * @param string         $type    Document type.
	 * @param HoldedRecord   $record  The document.
	 * @param ConnectorError $failure The failed check.
	 *
	 * @throws ApiException Never for the delete itself: a failed delete is reported in the error.
	 */
	private function discard( ApiClient $api, string $type, HoldedRecord $record, ConnectorError $failure ): ConnectorError {
		if ( 'salesorder' === $type ) {
			try {
				$this->docs->delete( $api, $type, $record->id() );
				return $failure;
			} catch ( ApiException $exception ) {
				unset( $exception );
				return $this->withReason(
					$failure,
					__( 'The sales order could not be removed. Delete it in Holded before syncing the order again.', 'estasoft-erp-crm-sync-engine' )
				);
			}
		}
		return $this->staysInHolded( $failure, $record );
	}

	/**
	 * Add to a failed check the fact that the document stays in Holded.
	 *
	 * @param ConnectorError $failure The failed check.
	 * @param HoldedRecord   $record  The document.
	 */
	private function staysInHolded( ConnectorError $failure, HoldedRecord $record ): ConnectorError {
		$name = '' !== $record->number() ? $record->number() : $record->id();
		return $this->withReason(
			$failure,
			sprintf(
				/* translators: %s: document number in Holded. */
				__( 'The document %s is in Holded. Correct it there (an issued invoice with a credit note) and sync the order again.', 'estasoft-erp-crm-sync-engine' ),
				$name
			)
		);
	}

	/**
	 * The same error with more in its reason.
	 *
	 * @param ConnectorError $failure Error.
	 * @param string         $more    Text to add.
	 */
	private function withReason( ConnectorError $failure, string $more ): ConnectorError {
		$context           = $failure->context;
		$context['reason'] = trim( (string) ( $context['reason'] ?? '' ) . ' ' . $more );
		return new ConnectorError( $failure->code, $context, $failure->httpStatus, $failure->retryAfter, $failure->classOverride, $failure->detail );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param HoldedRecord      $record    Document.
	 * @param string            $type      Document type.
	 * @param string            $reference The client reference, shown when Holded gives no document number.
	 * @param array<int,string> $warnings  Warnings.
	 */
	private function reference( HoldedRecord $record, string $type, string $reference, array $warnings ): ExternalRef {
		$currency = $record->currency();
		$extra    = array(
			'currency' => $currency,
			'document' => $type,
			'state'    => 'issued',
		);
		$total    = $record->totalMinor( $this->amounts->exponent( $currency ) );
		if ( null !== $total ) {
			$extra['total_minor'] = $total;
		}
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( $record->id(), '' !== $record->number() ? $record->number() : $reference, null, $extra );
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
				'system' => HoldedConnector::LABEL,
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
