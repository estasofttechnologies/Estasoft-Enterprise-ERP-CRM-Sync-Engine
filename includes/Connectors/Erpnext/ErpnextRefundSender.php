<?php
/**
 * Sends a WooCommerce refund to ERPNext as a credit note (a Sales Invoice with Is Return).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Erpnext;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\RefundPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\TaxPricing;
use Estasoft\SyncEngine\Connectors\ErpCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The credit note has negative quantities and positive rates, the tax template of the original order, the refund number in `po_no`,
 * and `return_against` the order's invoice when there is one. Like an order, every pass starts from a read: a credit note that exists
 * is never made again, and a draft is submitted when the option `refund_state` is `post`.
 */
final class ErpnextRefundSender {

	/**
	 * Set up with the services it uses.
	 *
	 * @param ErpnextClient      $client  Client.
	 * @param Options            $options Connection options.
	 * @param Amounts            $amounts Money helper.
	 * @param ErpnextLookups     $lookups Setup lookups.
	 * @param ErpnextTaxes       $taxes   Tax templates.
	 * @param ErpnextOrderSender $orders Order reads.
	 * @param string             $system  System name.
	 */
	public function __construct(
		private readonly ErpnextClient $client,
		private readonly Options $options,
		private readonly Amounts $amounts,
		private readonly ErpnextLookups $lookups,
		private readonly ErpnextTaxes $taxes,
		private readonly ErpnextOrderSender $orders,
		private readonly string $system
	) {
	}

	/**
	 * Whether posting is wanted.
	 */
	private function posting(): bool {
		return 'post' === $this->options->choice( 'refund_state', array( 'draft', 'post' ), 'draft' );
	}

	/**
	 * The credit note of a refund when it is finished the way the options ask; null otherwise.
	 *
	 * @param Item $item The queue item (a refund).
	 *
	 * @throws ApiException On failure.
	 */
	public function finished( Item $item ): ?ExternalRef {
		$note = $this->find( $item->clientReference() );
		if ( null === $note || ( $this->posting() && 1 !== (int) $note['docstatus'] ) ) {
			return null;
		}
		return $this->reference( $note );
	}

	/**
	 * Send a refund.
	 *
	 * @param Item          $item    The queue item.
	 * @param RefundPayload $payload The refund.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Item $item, RefundPayload $payload ): SendResult {
		unset( $item );
		$note     = $this->find( $payload->clientRef );
		$existing = null !== $note;
		if ( null === $note ) {
			try {
				$created = $this->create( $payload );
			} catch ( ApiException $exception ) {
				// The site says the credit note already exists: it is the same refund, so it is linked, not an error.
				$created = ErpnextOrderSender::isDuplicate( $exception ) ? $this->find( $payload->clientRef ) : null;
				if ( null === $created ) {
					throw $exception;
				}
				$existing = true;
			}
			if ( $created instanceof ConnectorError ) {
				return SendResult::failure( $created );
			}
			$note = $created;
		}
		if ( 0 === (int) $note['docstatus'] && $this->posting() ) {
			$stored = $this->client->submit( 'Sales Invoice', (string) $note['name'] );
			$note   = array_merge( $note, array_intersect_key( $stored, array_flip( array( 'status', 'grand_total', 'currency' ) ) ), array( 'docstatus' => 1 ) );
		}
		$ref = $this->reference( $note );
		return $existing ? SendResult::existing( $ref ) : SendResult::created( $ref );
	}

	/**
	 * The credit note of a client reference.
	 *
	 * @param string $clientRef WC-{refund id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	private function find( string $clientRef ): ?array {
		$rows = $this->client->rows(
			'Sales Invoice',
			array( array( 'po_no', '=', $clientRef ), array( 'is_return', '=', 1 ), array( 'docstatus', '!=', 2 ) ),
			array( 'name', 'docstatus', 'status', 'grand_total', 'currency', 'po_no', 'creation' ),
			'creation asc',
			5
		);
		foreach ( $rows as $row ) {
			if ( (string) ( $row['po_no'] ?? '' ) === $clientRef ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The record for a credit note.
	 *
	 * @param array<string,mixed> $note Sales Invoice row.
	 */
	private function reference( array $note ): ExternalRef {
		$currency = strtoupper( (string) ( $note['currency'] ?? '' ) );
		$name     = (string) $note['name'];
		return new ExternalRef(
			$name,
			$name,
			$this->orders->link( 'sales-invoice', $name ),
			array(
				'total_minor' => abs( $this->amounts->toMinor( (float) ( $note['grand_total'] ?? 0 ), $this->amounts->exponent( $currency ) ) ),
				'currency'    => $currency,
				'status'      => (string) ( $note['status'] ?? '' ),
			)
		);
	}

	/**
	 * Insert the credit note as a draft and check its total.
	 *
	 * @param RefundPayload $payload The refund.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( RefundPayload $payload ): array|ConnectorError {
		$order = $this->orders->findOrder( $payload->orderRef );
		if ( null === $order ) {
			return Problems::refused( $this->system, 'order_ref', sprintf( /* translators: %s: order reference. */ __( 'The order %s is not in ERPNext, so it cannot be refunded there. Send the order first.', 'estasoft-erp-crm-sync-engine' ), $payload->orderRef ), 'order not found' );
		}
		$so = $this->client->get( 'Sales Order', (string) $order['name'] );
		if ( null === $so ) {
			return Problems::refused( $this->system, 'order_ref', __( 'The order disappeared from ERPNext.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$currency = strtoupper( (string) ( $so['currency'] ?? '' ) );
		$exponent = $this->amounts->exponent( $currency );
		$invoice  = $this->orders->findInvoice( (string) $order['name'] );

		$template = null;
		if ( '' !== (string) ( $so['taxes_and_charges'] ?? '' ) && array() !== (array) ( $so['taxes'] ?? array() ) ) {
			$loaded = $this->taxes->template( (string) $so['taxes_and_charges'], (string) $so['company'] );
			if ( $loaded instanceof ConnectorError ) {
				return $loaded;
			}
			$template = $loaded;
		}

		$skus         = array_map( static fn( array $line ): string => (string) $line['sku'], $payload->lines );
		$shippingItem = $this->options->text( 'shipping_item' );
		$refundItem   = $this->options->text( 'refund_item' );
		if ( $payload->shippingMinor > 0 ) {
			if ( '' === $shippingItem ) {
				return Problems::refused( $this->system, 'shipping_item', __( 'The refund includes shipping, so the connection settings need the shipping item code.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$skus[] = $shippingItem;
		}
		if ( $payload->isAmountOnly() ) {
			if ( '' === $refundItem || null === $payload->amountMinor || $payload->amountMinor <= 0 ) {
				return Problems::refused( $this->system, 'refund_line', __( 'This refund has no product lines. Enter an item code for refunds without lines in the connection settings ("refund_item"), or refund by line.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$skus[] = $refundItem;
		}
		$items = $this->lookups->items( $skus );
		foreach ( $skus as $sku ) {
			if ( ! isset( $items[ strtolower( $sku ) ] ) ) {
				return Problems::missingProduct( $this->system, $sku );
			}
		}

		$date = '' !== $payload->date ? $payload->date : gmdate( 'Y-m-d' );
		$rows = array();
		foreach ( $payload->lines as $line ) {
			$unit = $this->amounts->perUnit( (int) $line['amount_minor'], (float) $line['quantity'] );
			$unit = $this->taxes->price( $unit, $template, false );
			if ( $unit instanceof ConnectorError ) {
				return $unit;
			}
			$rows[] = $this->row( $items[ strtolower( (string) $line['sku'] ) ], - (float) $line['quantity'], $this->amounts->toDecimal( $unit, $exponent ) );
		}
		if ( $payload->shippingMinor > 0 ) {
			$net = $this->withoutTax( $payload->shippingMinor, $template );
			if ( $net instanceof ConnectorError ) {
				return $net;
			}
			$rows[] = $this->row( $items[ strtolower( $shippingItem ) ], -1.0, $this->amounts->toDecimal( $net, $exponent ) );
		}
		if ( $payload->isAmountOnly() ) {
			$rows[] = $this->row( $items[ strtolower( $refundItem ) ], -1.0, $this->amounts->toDecimal( (int) $payload->amountMinor, $exponent ) );
		}

		$taxes = array();
		if ( null !== $template && ! $payload->isAmountOnly() ) {
			$taxes = $template['rows'];
		}
		$doc = array(
			'customer'            => $so['customer'],
			'company'             => $so['company'],
			'is_return'           => 1,
			'posting_date'        => $date,
			'set_posting_time'    => 1,
			'due_date'            => $date,
			'po_no'               => $payload->clientRef,
			'currency'            => $so['currency'],
			'conversion_rate'     => $so['conversion_rate'] ?? 1,
			'selling_price_list'  => $so['selling_price_list'] ?? '',
			'price_list_currency' => $so['price_list_currency'] ?? $so['currency'],
			'plc_conversion_rate' => $so['plc_conversion_rate'] ?? 1,
			'ignore_pricing_rule' => 1,
			'update_stock'        => 0,
			'items'               => $rows,
			'taxes'               => $taxes,
			'taxes_and_charges'   => array() === $taxes ? '' : (string) $so['taxes_and_charges'],
			'remarks'             => 'Refund of ' . $payload->orderRef . ( '' !== $payload->reason ? ': ' . $payload->reason : '' ),
		);
		if ( null !== $invoice && ! $payload->isAmountOnly() ) {
			// ERPNext checks every returned item against the invoice, and the refund item is not on it, so an amount-only credit note is not linked.
			$doc['return_against'] = $invoice['name'];
		}
		foreach ( array( 'customer_address', 'contact_person' ) as $key ) {
			if ( ! empty( $so[ $key ] ) ) {
				$doc[ $key ] = $so[ $key ];
			}
		}
		$stored = $this->client->insert( 'Sales Invoice', $doc );
		if ( '' === (string) ( $stored['name'] ?? '' ) ) {
			return Problems::refused( $this->system, 'sales_invoice', __( 'ERPNext did not return the new credit note.', 'estasoft-erp-crm-sync-engine' ) );
		}

		$check = new TotalCheck( $this->options, $this->amounts, $this->system );
		$diff  = $check->difference( $payload->amountMinor, abs( (float) ( $stored['grand_total'] ?? 0 ) ), $currency, (string) $stored['name'] );
		if ( null !== $diff && $check->isStrict() ) {
			return $check->error( $diff, __( 'The credit note stays in ERPNext as a draft. Delete it there after fixing the tax setup; the next try makes a new one.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return array_intersect_key( $stored, array_flip( array( 'name', 'docstatus', 'status', 'grand_total', 'currency' ) ) ) + array(
			'docstatus' => 0,
			'currency'  => $currency,
		);
	}

	/**
	 * A gross amount (with tax) as the unit price that ERPNext expects for the order's template.
	 *
	 * @param int                      $gross    Amount with tax.
	 * @param array<string,mixed>|null $template Template of the order, or null for none.
	 * @return int|ConnectorError
	 */
	private function withoutTax( int $gross, ?array $template ): int|ConnectorError {
		if ( null === $template || $template['included'] ) {
			return $gross;
		}
		if ( null === $template['rate'] ) {
			return Problems::refused( $this->system, 'taxes_and_charges', __( 'The refunded shipping includes tax, and the tax template has rows that are not plain percentages, so the tax cannot be taken out exactly.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return TaxPricing::withoutTax( $gross, (int) $template['rate'] );
	}

	/**
	 * One item row of a credit note.
	 *
	 * @param array<string,mixed> $item     Item row from the lookup.
	 * @param float               $quantity Negative quantity.
	 * @param float               $rate     Positive unit price.
	 * @return array<string,mixed>
	 */
	private function row( array $item, float $quantity, float $rate ): array {
		return array(
			'item_code' => (string) $item['item_code'],
			'qty'       => $quantity,
			'rate'      => $rate,
		);
	}
}
