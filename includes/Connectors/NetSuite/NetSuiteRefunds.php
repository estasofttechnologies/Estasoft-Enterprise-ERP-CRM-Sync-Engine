<?php
/**
 * Credit memos made from the invoice of an order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\NetSuite;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\RefundData;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Warnings;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * A refund is `POST invoice/{id}/!transform/creditMemo` with the refunded lines, followed by a PATCH that sets the external id and PO number
 * `WC-{refund id}`. The refund reference is also in the memo as `[WC-{refund id}]`, so a credit memo whose PATCH was lost is still found by
 * the search that runs before every create.
 */
final class NetSuiteRefunds {

	/**
	 * Set up with what it depends on.
	 *
	 * @param NetSuiteGateway $gateway Calls.
	 * @param NetSuiteLookups $lookups Reads.
	 * @param NetSuiteOrders  $orders  Orders.
	 * @param NetSuiteOptions $options Options.
	 * @param Amounts         $amounts Conversion helper.
	 * @param string          $system  System name for messages.
	 * @param string          $host    Host of the account (for links).
	 */
	public function __construct(
		private readonly NetSuiteGateway $gateway,
		private readonly NetSuiteLookups $lookups,
		private readonly NetSuiteOrders $orders,
		private readonly NetSuiteOptions $options,
		private readonly Amounts $amounts,
		private readonly string $system,
		private readonly string $host
	) {
	}

	/**
	 * The credit memo of a refund reference.
	 *
	 * @param string $reference WC-{refund id}.
	 * @return array<string,mixed>|null Row (id, tranid, foreigntotal, currency) or null.
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $reference ): ?array {
		$rows = $this->gateway->rows(
			"SELECT id, tranid, foreigntotal, currency FROM transaction WHERE type = 'CustCred' AND (externalid = ? OR memo LIKE ?) ORDER BY id",
			array( $reference, '%[' . $reference . ']%' ),
			1
		);
		return $rows[0] ?? null;
	}

	/**
	 * Make the credit memo.
	 *
	 * @param RefundData $refund   Checked payload.
	 * @param Warnings   $warnings Receives notes about cut text.
	 * @return array<string,mixed> The credit memo row read back (id, tranid, foreigntotal, currency).
	 *
	 * @throws ApiException When the order or its invoice is missing, a product is unknown, or NetSuite refuses.
	 */
	public function create( RefundData $refund, Warnings $warnings ): array {
		$order = $this->orders->find( $refund->orderRef );
		if ( null === $order ) {
			$problem = Problem::validation( $this->system, 'order_ref', __( 'The order of this refund is not in NetSuite yet.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$invoice = $this->orders->invoiceOf( (string) $order['id'] );
		if ( null === $invoice || ! isset( $invoice['id'] ) ) {
			$problem = Problem::validation( $this->system, 'invoice', __( 'NetSuite has no invoice for this order, so there is nothing to credit. Set documents to "order and invoice" or credit it in NetSuite.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$currency = $this->orders->currencyCodeOf( $order );
		$memo     = $warnings->fit( 'memo', trim( '[' . $refund->reference . '] ' . $refund->reason ), 999 );
		$body     = array(
			'memo' => $memo,
			'item' => array( 'items' => $this->lines( $refund, $currency ) ),
		);
		if ( '' !== $refund->date ) {
			$body['tranDate'] = $refund->date;
		}
		$response = $this->gateway->write( 'POST', 'record/v1/invoice/' . rawurlencode( (string) $invoice['id'] ) . '/!transform/creditMemo', $body );
		$id       = $this->gateway->idFromLocation( $response );
		if ( '' === $id ) {
			$row = $this->find( $refund->reference );
			$id  = null === $row ? '' : (string) $row['id'];
		}
		if ( '' === $id ) {
			$problem = Problem::validation( $this->system, 'creditMemo', __( 'NetSuite accepted the credit memo but did not say which record it is.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$this->gateway->write(
			'PATCH',
			'record/v1/creditMemo/' . rawurlencode( $id ),
			array(
				'externalId'  => $refund->reference,
				'otherRefNum' => $refund->reference,
			)
		);
		$row = $this->find( $refund->reference );
		return null === $row ? array( 'id' => $id ) : $row;
	}

	/**
	 * The reference the engine stores for a credit memo.
	 *
	 * @param array<string,mixed> $row      Credit memo row.
	 * @param string              $currency ISO code, or empty.
	 */
	public function reference( array $row, string $currency ): ExternalRef {
		$id    = (string) ( $row['id'] ?? '' );
		$total = '' === $currency ? null : $this->amounts->fromWire( $row['foreigntotal'] ?? null, $currency );
		return new ExternalRef(
			$id,
			(string) ( $row['tranid'] ?? '' ),
			'https://' . $this->host . '.app.netsuite.com/app/accounting/transactions/custcred.nl?id=' . rawurlencode( $id ),
			array(
				'total_minor' => null === $total ? 0 : abs( $total ),
				'currency'    => $currency,
			)
		);
	}

	/**
	 * The lines of the credit memo.
	 *
	 * @param RefundData $refund   Checked payload.
	 * @param string     $currency ISO code of the order.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException When a product is unknown or an amount-only refund has no item.
	 */
	private function lines( RefundData $refund, string $currency ): array {
		if ( array() === $refund->lines ) {
			if ( '' === $this->options->refundItem() || null === $refund->amountMinor ) {
				$problem = Problem::validation( $this->system, 'refund_item', __( 'The refund names no products. Enter the id of an item for such refunds in the connection options.', 'estasoft-erp-crm-sync-engine' ) );
				throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
			}
			return array(
				array(
					'item'     => array( 'id' => $this->options->refundItem() ),
					'quantity' => 1,
					'amount'   => $this->amounts->toNumber( $refund->amountMinor, $currency ),
				),
			);
		}
		$items = array();
		foreach ( $refund->lines as $line ) {
			$id      = 1 === preg_match( '/^\d{1,12}$/', $line->sku ) ? $line->sku : $this->itemId( $line->sku );
			$items[] = array(
				'item'     => array( 'id' => $id ),
				'quantity' => false === strpos( $line->quantity, '.' ) ? (int) $line->quantity : (float) $line->quantity,
				'amount'   => $this->amounts->toNumber( $line->amountMinor, $currency ),
			);
		}
		return $items;
	}

	/**
	 * Look up a product that was not matched by the mapping.
	 *
	 * @param string $sku SKU.
	 *
	 * @throws ApiException With SYNC-MAP-001 when unknown.
	 */
	private function itemId( string $sku ): string {
		$item = '' === $sku ? null : $this->lookups->itemBySku( $sku );
		if ( null === $item ) {
			$problem = Problem::unknownSku( $this->system, '' === $sku ? '#' : $sku );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		return $item['id'];
	}
}
