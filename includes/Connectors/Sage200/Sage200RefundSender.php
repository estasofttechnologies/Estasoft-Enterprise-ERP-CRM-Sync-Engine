<?php
/**
 * Sends a WooCommerce refund to Sage 200 as a sales return.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Sage200;

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
 * A sales return (`sop_returns`) has the body of an order. It does not credit the customer until a person processes it in Sage 200, which
 * is the safe default. The tax code of each line is copied from the line of the same product on the original order.
 */
final class Sage200RefundSender {

	/**
	 * Set up with the services it uses.
	 *
	 * @param Sage200Client      $client  Client.
	 * @param Options            $options Connection options.
	 * @param Amounts            $amounts Money helper.
	 * @param Sage200Lookups     $lookups Setup lookups.
	 * @param Sage200OrderSender $orders  Order reads.
	 * @param string             $system  System name.
	 */
	public function __construct(
		private readonly Sage200Client $client,
		private readonly Options $options,
		private readonly Amounts $amounts,
		private readonly Sage200Lookups $lookups,
		private readonly Sage200OrderSender $orders,
		private readonly string $system
	) {
	}

	/**
	 * The record of a refund that was made; null otherwise.
	 *
	 * @param Item $item The queue item (a refund).
	 *
	 * @throws ApiException On failure.
	 */
	public function finished( Item $item ): ?ExternalRef {
		$memo = $this->find( $item->clientReference() );
		return null === $memo ? null : $this->reference( $memo );
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
		$existing = $this->find( $payload->clientRef );
		if ( null === $existing ) {
			$created = $this->create( $payload );
			if ( $created instanceof ConnectorError ) {
				return SendResult::failure( $created );
			}
			$memo = $created;
		} else {
			$memo = $this->orders->withTotals( $existing, 'sop_returns' );
		}
		$check = new TotalCheck( $this->options, $this->amounts, $this->system );
		$base  = $this->orders->baseCurrency();
		$diff  = isset( $memo['total_gross_value'] ) ? $check->difference( $payload->amountMinor, abs( (float) $memo['total_gross_value'] ), $base, (string) ( $memo['document_no'] ?? $memo['id'] ?? '' ) ) : null;
		$warn  = array();
		if ( null !== $diff ) {
			if ( $check->isStrict() ) {
				return SendResult::failure( $check->error( $diff, __( 'The return stays in Sage 200. Delete it there after fixing the tax setup; the next try makes a new one.', 'estasoft-erp-crm-sync-engine' ) ) );
			}
			$warn[] = $diff['message'];
		}
		$ref = $this->reference( $memo, $warn );
		return null === $existing ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The return of a client reference.
	 *
	 * @param string $clientRef WC-{refund id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $clientRef ): ?array {
		foreach ( $this->client->rows( 'sop_returns', 'customer_document_no eq ' . Sage200Client::literal( $clientRef ), 10 ) as $row ) {
			if ( strtolower( trim( (string) ( $row['customer_document_no'] ?? '' ) ) ) === strtolower( $clientRef ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * The record for a return.
	 *
	 * @param array<string,mixed> $memo   Return record.
	 * @param array<int,string>   $warnings Warnings to carry.
	 */
	private function reference( array $memo, array $warnings = array() ): ExternalRef {
		$extra = array(
			'total_minor' => abs( $this->amounts->toMinor( $memo['total_gross_value'] ?? 0, $this->amounts->exponent( $this->orders->baseCurrency() ) ) ),
			'currency'    => $this->orders->baseCurrency(),
			'status'      => isset( $memo['document_status'] ) && is_scalar( $memo['document_status'] ) ? (string) $memo['document_status'] : '',
		);
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		$id = isset( $memo['id'] ) && is_scalar( $memo['id'] ) ? (string) $memo['id'] : '';
		$no = isset( $memo['document_no'] ) && is_scalar( $memo['document_no'] ) ? (string) $memo['document_no'] : $id;
		return new ExternalRef( $id, $no, null, $extra );
	}

	/**
	 * Create the return.
	 *
	 * @param RefundPayload $payload The refund.
	 * @return array<string,mixed>|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( RefundPayload $payload ): array|ConnectorError {
		$order = $this->orders->find( $payload->orderRef );
		if ( null === $order || ! isset( $order['customer_id'] ) || ! is_scalar( $order['customer_id'] ) ) {
			return Problems::refused(
				$this->system,
				'order_ref',
				sprintf(
					/* translators: %s: order reference. */
					__( 'The order %s is not in Sage 200, so it cannot be refunded there. Send the order first.', 'estasoft-erp-crm-sync-engine' ),
					$payload->orderRef
				),
				'order not found'
			);
		}
		$shipping = $this->options->text( 'shipping_product' );
		$generic  = $this->options->text( 'refund_product' );
		$codes    = array_map( static fn( array $line ): string => (string) $line['sku'], $payload->lines );
		if ( $payload->shippingMinor > 0 ) {
			if ( '' === $shipping ) {
				return Problems::refused( $this->system, 'shipping_product', __( 'The refund includes shipping, so the connection settings need the shipping product code.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$codes[] = $shipping;
		}
		if ( $payload->isAmountOnly() ) {
			if ( '' === $generic || null === $payload->amountMinor || $payload->amountMinor <= 0 ) {
				return Problems::refused( $this->system, 'refund_line', __( 'This refund has no product lines. Enter a product code for refunds without lines in the connection settings ("refund_product"), or refund by line.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$codes[] = $generic;
		}
		$products = $this->lookups->products( $codes );
		foreach ( $codes as $code ) {
			if ( ! isset( $products[ strtolower( $code ) ] ) ) {
				return Problems::missingProduct( $this->system, $code );
			}
		}

		$full     = $this->client->find( 'sop_orders', (string) ( $order['id'] ?? '' ) ) ?? array();
		$original = array();
		foreach ( (array) ( $full['lines'] ?? array() ) as $line ) {
			if ( is_array( $line ) && isset( $line['product_id'], $line['tax_code_id'] ) && is_scalar( $line['product_id'] ) && is_scalar( $line['tax_code_id'] ) ) {
				$original[ (string) $line['product_id'] ] = (string) $line['tax_code_id'];
			}
		}
		$exponent = $this->amounts->exponent( $this->orders->baseCurrency() );
		$lines    = array();
		foreach ( $payload->lines as $line ) {
			$id      = $products[ strtolower( (string) $line['sku'] ) ]['id'];
			$unit    = $this->amounts->perUnit( (int) $line['amount_minor'], (float) $line['quantity'] );
			$lines[] = Sage200OrderSender::line( $id, (float) $line['quantity'], $this->amounts->toDecimal( $unit, $exponent ), $original[ $id ] ?? '' );
		}
		if ( $payload->shippingMinor > 0 ) {
			$id      = $products[ strtolower( $shipping ) ]['id'];
			$tax     = $original[ $id ] ?? '';
			$net     = $this->withoutTax( $payload->shippingMinor, $tax );
			$lines[] = Sage200OrderSender::line( $id, 1.0, $this->amounts->toDecimal( $net, $exponent ), $tax );
		}
		if ( $payload->isAmountOnly() ) {
			$lines[] = Sage200OrderSender::line( $products[ strtolower( $generic ) ]['id'], 1.0, $this->amounts->toDecimal( (int) $payload->amountMinor, $exponent ), '' );
		}

		$stored = $this->client->create(
			'sop_returns',
			array(
				'customer_id'          => Sage200Client::id( (string) $order['customer_id'] ),
				'document_date'        => ( '' !== $payload->date ? $payload->date : gmdate( 'Y-m-d' ) ) . 'T00:00:00Z',
				'customer_document_no' => $payload->clientRef,
				'lines'                => $lines,
			)
		);
		if ( ! isset( $stored['id'] ) || ! is_scalar( $stored['id'] ) ) {
			$stored = $this->find( $payload->clientRef ) ?? array();
		}
		if ( array() === $stored ) {
			return Problems::refused( $this->system, 'sop_returns', __( 'Sage 200 did not return the new return.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $this->orders->withTotals( $stored, 'sop_returns' );
	}

	/**
	 * An amount with tax as the price without it, using the rate of the tax code of the original line.
	 *
	 * @param int    $gross Amount with tax.
	 * @param string $taxId Tax code id of the original line, or empty.
	 *
	 * @throws ApiException On failure.
	 */
	private function withoutTax( int $gross, string $taxId ): int {
		if ( '' === $taxId ) {
			return $gross;
		}
		$tax = $this->lookups->taxById( $taxId );
		return null === $tax || null === $tax['rate'] ? $gross : TaxPricing::withoutTax( $gross, $tax['rate'] );
	}
}
