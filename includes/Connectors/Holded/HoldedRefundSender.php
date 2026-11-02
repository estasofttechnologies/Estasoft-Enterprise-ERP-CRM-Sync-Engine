<?php
/**
 * Sends a WooCommerce refund to Holded as a credit note.
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
 * The credit note is made for the contact of the order's document, so that document must be in Holded first. Holded v1 documents no field that
 * links a credit note to its invoice, so the link is the note: `[WC-R-{refund id}] amount currency`, then the order reference and the reason.
 * A credit note is numbered at once and is never deleted by the connector; one that fails its check stays, with an error that names it.
 */
final class HoldedRefundSender {

	/**
	 * Days back the order's document is looked for when the refund does not carry the order date.
	 */
	public const ORDER_LOOK_BACK_DAYS = 90;

	/**
	 * Set up with what it depends on.
	 *
	 * @param HoldedDocuments $docs    Document calls.
	 * @param HoldedCatalog   $catalog Products.
	 * @param HoldedLines     $lines   Line builder.
	 * @param HoldedTotals    $totals  Total checks.
	 * @param HoldedAmounts   $amounts Conversion helper.
	 * @param HoldedIds       $ids     References.
	 * @param HoldedDates     $dates   Dates.
	 */
	public function __construct(
		private readonly HoldedDocuments $docs,
		private readonly HoldedCatalog $catalog,
		private readonly HoldedLines $lines,
		private readonly HoldedTotals $totals,
		private readonly HoldedAmounts $amounts,
		private readonly HoldedIds $ids,
		private readonly HoldedDates $dates,
	) {
	}

	/**
	 * The finished credit note of a refund, when it exists.
	 *
	 * @param ApiClient $api  Client.
	 * @param Item      $item Queue item.
	 * @return ExternalRef|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( ApiClient $api, Item $item ): ?ExternalRef {
		$reference = $this->ids->reference( $item );
		$record    = null;
		if ( null !== $item->externalId && '' !== $item->externalId ) {
			$record = $this->docs->getIfExists( $api, 'creditnote', $item->externalId );
		}
		if ( null === $record ) {
			$window = $this->dates->recent();
			$record = $this->docs->find( $api, 'creditnote', $reference, $window[0], $window[1] );
		}
		if ( null === $record ) {
			return null;
		}
		if ( ! $record->hasTotal() || '' === $record->currency() ) {
			$record = $this->docs->get( $api, 'creditnote', $record->id() );
		}
		if ( ! $this->totals->kept( $record, $this->ids->promise( $record->notes(), $reference ) ) ) {
			return null;
		}
		return $this->reference( $record, $reference, array() );
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
		$options = HoldedOptions::fromConnection( $connection );
		$refund  = HoldedRefundPayload::parse( $payload );
		if ( $refund instanceof ConnectorError ) {
			return SendResult::failure( $refund );
		}
		$reference = $this->ids->reference( $item );
		$date      = '' !== $refund->date ? $refund->date : $this->dates->today();
		$window    = $this->dates->around( $date );
		$warnings  = array();
		$expected  = $refund->expectedTotal();

		$existing = $this->docs->find( $api, 'creditnote', $reference, $window[0], $window[1] );
		if ( null !== $existing ) {
			$record = $existing;
			if ( ! $record->hasTotal() || '' === $record->currency() ) {
				$record = $this->docs->get( $api, 'creditnote', $record->id() );
			}
			$failure = $this->totals->check( $record, '' !== $refund->currency ? $refund->currency : $record->currency(), $expected > 0 ? $expected : null, $options->failOnMismatch(), $warnings, $record->lineCount() ?? 1 );
			if ( null !== $failure ) {
				return SendResult::failure( $this->staysInHolded( $failure, $record ) );
			}
			return SendResult::existing( $this->reference( $record, $reference, $warnings ) );
		}

		$order = $this->orderDocument( $api, $options->documentType(), $refund, $date );
		if ( null === $order ) {
			return SendResult::failure( $this->refused( 'order', __( 'The order of this refund is not in Holded yet.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		if ( '' === $order->contactId() ) {
			return SendResult::failure( $this->refused( 'order', __( 'Holded did not say which contact the order belongs to.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		if ( 'salesorder' === $options->documentType() ) {
			$warnings[] = __( 'The order is a sales order in Holded. The credit note has no invoice to correct; use invoices if you need that.', 'estasoft-erp-crm-sync-engine' );
		}
		$currency = '' !== $order->currency() ? $order->currency() : $refund->currency;
		if ( '' === $currency ) {
			return SendResult::failure( $this->refused( 'currency', __( 'Holded did not report the currency of the order and the refund has none.', 'estasoft-erp-crm-sync-engine' ) ) );
		}
		$rows  = $this->rows( $api, $connection, $refund );
		$built = $this->lines->build( $rows, $currency, false );
		if ( $built instanceof ConnectorError ) {
			return SendResult::failure( $built );
		}
		$total   = $expected > 0 ? $expected : $built['total_minor'];
		$failure = $this->totals->plan( $built['total_minor'], $expected > 0 ? $expected : null, $currency, $options->failOnMismatch(), $warnings, count( $built['items'] ) );
		if ( null !== $failure ) {
			return SendResult::failure( $failure );
		}
		$id = $this->docs->create(
			$api,
			'creditnote',
			array(
				'contactId' => $order->contactId(),
				'date'      => $this->dates->stamp( $date ),
				'currency'  => strtolower( $currency ),
				'notes'     => $this->notes( $reference, $refund, $total, $currency ),
				'items'     => $built['items'],
			)
		);
		if ( $id instanceof ConnectorError ) {
			return SendResult::failure( $id );
		}
		$record  = $this->docs->get( $api, 'creditnote', $id );
		$failure = $this->totals->check( $record, $currency, $total, $options->failOnMismatch(), $warnings, count( $built['items'] ) );
		if ( null !== $failure ) {
			return SendResult::failure( $this->staysInHolded( $failure, $record ) );
		}
		return SendResult::created( $this->reference( $record, $reference, array_values( array_unique( $warnings ) ) ) );
	}

	/**
	 * The order's document: a window around the order date when the refund has it, else the last ninety days.
	 *
	 * @param ApiClient           $api    Client.
	 * @param string              $type   Document type of orders.
	 * @param HoldedRefundPayload $refund Refund.
	 * @param string              $date   Date of the refund.
	 *
	 * @throws ApiException On failure.
	 */
	private function orderDocument( ApiClient $api, string $type, HoldedRefundPayload $refund, string $date ): ?HoldedRecord {
		if ( '' !== $refund->orderDate ) {
			$window = $this->dates->around( $refund->orderDate );
		} else {
			$end    = $this->dates->dayStart( $date ) + 86400;
			$window = array( $end - self::ORDER_LOOK_BACK_DAYS * 86400, $end );
		}
		$found = $this->docs->find( $api, $type, $refund->orderRef, $window[0], $window[1] );
		return null === $found ? null : $this->docs->get( $api, $type, $found->id() );
	}

	/**
	 * The rows of the credit note: refunded product lines, shipping and other amounts. A refund with nothing else becomes one row.
	 *
	 * @param ApiClient           $api        Client.
	 * @param Connection          $connection Connection.
	 * @param HoldedRefundPayload $refund     Refund.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException On failure.
	 */
	private function rows( ApiClient $api, Connection $connection, HoldedRefundPayload $refund ): array {
		$rows = array();
		foreach ( $refund->lines as $line ) {
			$link = '';
			$name = (string) $line['name'];
			if ( '' !== (string) $line['sku'] ) {
				$product = $this->catalog->productBySku( $api, $connection->id, (string) $line['sku'] );
				$link    = null === $product ? '' : HoldedPayloadReader::text( $product['sku'] ?? '' );
				if ( null !== $product && ( '' === $name || $name === (string) $line['sku'] ) && '' !== HoldedPayloadReader::text( $product['name'] ?? '' ) ) {
					$name = HoldedPayloadReader::text( $product['name'] );
				}
			}
			$rows[] = array(
				'sku'         => $link,
				'name'        => '' !== $name ? $name : __( 'Refund', 'estasoft-erp-crm-sync-engine' ),
				'quantity'    => (float) $line['quantity'] > 0.0 ? (float) $line['quantity'] : 1.0,
				'total_minor' => (int) $line['amount_minor'],
				'tax_code'    => '',
				'tax_amount'  => $line['tax_amount'],
			);
		}
		if ( $refund->shippingMinor > 0 ) {
			$rows[] = $this->plain( __( 'Shipping', 'estasoft-erp-crm-sync-engine' ), $refund->shippingMinor );
		}
		if ( $refund->otherMinor > 0 ) {
			$rows[] = $this->plain( __( 'Other refund', 'estasoft-erp-crm-sync-engine' ), $refund->otherMinor );
		}
		if ( array() === $rows ) {
			$rows[] = $this->plain( __( 'Refund', 'estasoft-erp-crm-sync-engine' ), $refund->expectedTotal() );
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
			'sku'         => '',
			'name'        => $name,
			'quantity'    => 1.0,
			'total_minor' => $total,
			'tax_code'    => '',
			'tax_amount'  => null,
		);
	}

	/**
	 * The notes: the marker line, then the order and the reason.
	 *
	 * @param string              $reference Refund reference.
	 * @param HoldedRefundPayload $refund    Refund.
	 * @param int                 $total     What the credit note should add up to.
	 * @param string              $currency  Currency.
	 */
	private function notes( string $reference, HoldedRefundPayload $refund, int $total, string $currency ): string {
		$line = $this->ids->line( $reference, $this->amounts->decimalString( $total, $this->amounts->exponent( $currency ) ), $currency );
		$text = '' !== $refund->reason
			? sprintf(
				/* translators: 1: order reference, 2: refund reason. */
				__( 'Refund of %1$s: %2$s', 'estasoft-erp-crm-sync-engine' ),
				$refund->orderRef,
				$refund->reason
			)
			: sprintf(
				/* translators: %s: order reference. */
				__( 'Refund of %s', 'estasoft-erp-crm-sync-engine' ),
				$refund->orderRef
			);
		return HoldedPayloadReader::shorten( $line . "\n" . $text, 2000 );
	}

	/**
	 * Add to a failed check the fact that the credit note stays in Holded.
	 *
	 * @param ConnectorError $failure The failed check.
	 * @param HoldedRecord   $record  The credit note.
	 */
	private function staysInHolded( ConnectorError $failure, HoldedRecord $record ): ConnectorError {
		$name              = '' !== $record->number() ? $record->number() : $record->id();
		$context           = $failure->context;
		$context['reason'] = trim(
			(string) ( $context['reason'] ?? '' ) . ' ' . sprintf(
				/* translators: %s: credit note number in Holded. */
				__( 'The credit note %s is in Holded. Correct or cancel it there and sync the refund again.', 'estasoft-erp-crm-sync-engine' ),
				$name
			)
		);
		return new ConnectorError( $failure->code, $context, $failure->httpStatus, $failure->retryAfter, $failure->classOverride, $failure->detail );
	}

	/**
	 * The reference the engine stores.
	 *
	 * @param HoldedRecord      $record    Credit note.
	 * @param string            $reference The client reference, shown when Holded gives no number.
	 * @param array<int,string> $warnings  Warnings.
	 */
	private function reference( HoldedRecord $record, string $reference, array $warnings ): ExternalRef {
		$currency = $record->currency();
		$extra    = array(
			'currency' => $currency,
			'document' => 'creditnote',
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
