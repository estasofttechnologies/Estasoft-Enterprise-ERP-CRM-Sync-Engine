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
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\ShopTotalMarker;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\TotalCheck;
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
	 * @param ShopTotalMarker $marker  Shop total in the memo.
	 * @param string          $system  System name for messages.
	 * @param string          $host    Host of the account (for links).
	 */
	public function __construct(
		private readonly NetSuiteGateway $gateway,
		private readonly NetSuiteLookups $lookups,
		private readonly NetSuiteOrders $orders,
		private readonly NetSuiteOptions $options,
		private readonly Amounts $amounts,
		private readonly ShopTotalMarker $marker,
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
			"SELECT id, tranid, foreigntotal, currency, memo FROM transaction WHERE type = 'CustCred' AND (externalid = ? OR memo LIKE ?) ORDER BY id",
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
		$total    = $this->expected( $refund );
		$last     = $this->marker->append( '', $total, $currency );
		$memo     = $this->marker->append( $warnings->fit( 'memo', trim( '[' . $refund->reference . '] ' . $refund->reason ), 999 - ( '' === $last ? 0 : strlen( $last ) + 1 ) ), $total, $currency );
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
	 * What the credit memo should come to in minor units: the refund amount, else the sum of its lines.
	 *
	 * @param RefundData $refund Payload.
	 */
	public function expected( RefundData $refund ): ?int {
		if ( null !== $refund->amountMinor && $refund->amountMinor > 0 ) {
			return $refund->amountMinor;
		}
		$sum = 0;
		foreach ( $refund->lines as $line ) {
			$sum += $line->amountMinor;
		}
		return $sum > 0 ? $sum : null;
	}

	/**
	 * Compare a credit memo with the refund: currency, and total within one minor unit. The transform page does not say whether the lines
	 * sent replace the lines copied from the invoice or are added to them, so a memo is never reported as synced unchecked.
	 *
	 * @param array<string,mixed> $row      Credit memo row (foreigntotal, currency).
	 * @param int|null            $expected Refund amount in minor units.
	 * @param string              $currency ISO code of the order.
	 * @param TotalCheck          $total    Total check.
	 * @param Warnings            $warnings Receives accepted differences.
	 *
	 * @throws ApiException On failure of the currency read.
	 */
	public function check( array $row, ?int $expected, string $currency, TotalCheck $total, Warnings $warnings ): ?\Estasoft\SyncEngine\Contract\ConnectorError {
		$symbol = $this->lookups->symbolOf( (string) ( $row['currency'] ?? '' ) );
		if ( '' !== $symbol && '' !== $currency && $symbol !== $currency ) {
			return Problem::validation(
				$this->system,
				'currency',
				sprintf(
					/* translators: 1: currency of the credit memo, 2: currency of the order. */
					__( 'NetSuite holds the credit memo in %1$s but the order is in %2$s.', 'estasoft-erp-crm-sync-engine' ),
					$symbol,
					$currency
				)
			);
		}
		$actual = null === ( $row['foreigntotal'] ?? null ) ? null : $this->amounts->fromWire( $row['foreigntotal'], $currency );
		if ( null === $actual ) {
			return Problem::validation( $this->system, 'total', __( 'NetSuite did not show the total of the credit memo, so it could not be compared with the refund.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $total->run( $expected, abs( $actual ), $currency, $warnings, __( 'credit memo total', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The check findExisting() can make without a payload: the credit memo total against the refund total written in its memo.
	 *
	 * @param array<string,mixed> $row   Credit memo row.
	 * @param TotalCheck          $total Total check.
	 *
	 * @throws ApiException On failure of the currency read.
	 */
	public function checkMarked( array $row, TotalCheck $total ): ?\Estasoft\SyncEngine\Contract\ConnectorError {
		$marked = $this->marker->read( (string) ( $row['memo'] ?? '' ) );
		return null === $marked ? null : $this->check( $row, $marked['minor'], $marked['currency'], $total, new Warnings() );
	}

	/**
	 * Delete a credit memo that failed the check.
	 *
	 * @param string $id Internal id.
	 * @return bool False when NetSuite refused (the memo stays and a person removes it).
	 */
	public function remove( string $id ): bool {
		try {
			$this->gateway->write( 'DELETE', 'record/v1/creditMemo/' . rawurlencode( $id ), array() );
			return true;
		} catch ( ApiException $exception ) {
			unset( $exception );
			return false;
		}
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
