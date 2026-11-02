<?php
/**
 * A/R credit notes in SAP Business One.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapBusinessOne;

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
 * A refund is a credit note with the lines that were refunded. `NumAtCard` is `WC-{refund id}`; the remarks name the order
 * ("Refund WC-501 of order WC-5") because that text is what links the note to its order for the nightly check. The note is not copied
 * from the invoice through base document fields: a partial refund by amount cannot be expressed that way.
 */
final class SapBusinessOneRefunds {

	/**
	 * Set up with what it depends on.
	 *
	 * @param SapBusinessOneGateway $gateway Calls.
	 * @param SapBusinessOneOrders  $orders  Orders.
	 * @param SapBusinessOneOptions $options Options.
	 * @param Amounts               $amounts Conversion helper.
	 * @param ShopTotalMarker       $marker  Shop total in the remarks.
	 * @param string                $system  System name for messages.
	 */
	public function __construct(
		private readonly SapBusinessOneGateway $gateway,
		private readonly SapBusinessOneOrders $orders,
		private readonly SapBusinessOneOptions $options,
		private readonly Amounts $amounts,
		private readonly ShopTotalMarker $marker,
		private readonly string $system
	) {
	}

	/**
	 * The credit note with a refund reference.
	 *
	 * @param string $reference WC-{refund id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $reference ): ?array {
		$rows = $this->gateway->list(
			'CreditNotes',
			array(
				'filter'  => 'NumAtCard eq ' . $this->gateway->literal( $reference ) . " and Cancelled eq 'tNO'",
				'select'  => SapBusinessOneOrders::FIELDS,
				'orderby' => 'DocEntry',
			),
			1
		);
		return $rows[0] ?? null;
	}

	/**
	 * Make the credit note.
	 *
	 * @param RefundData $refund   Checked payload.
	 * @param Warnings   $warnings Receives notes about cut text.
	 * @return array<string,mixed> The credit note.
	 *
	 * @throws ApiException When the order is missing or SAP refuses.
	 */
	public function create( RefundData $refund, Warnings $warnings ): array {
		$order = $this->orders->find( $refund->orderRef );
		if ( null === $order ) {
			$problem = Problem::validation( $this->system, 'order_ref', __( 'The order of this refund is not in SAP yet.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		if ( null === $this->orders->invoiceOf( $refund->orderRef ) && ! $this->options->refundWithoutInvoice() ) {
			$problem = Problem::validation( $this->system, 'invoice', __( 'SAP has no A/R invoice for this order, so a credit note would book against a sale that was never invoiced. Set documents to "order and invoice", or turn on refund_without_invoice, or credit it in SAP.', 'estasoft-erp-crm-sync-engine' ) );
			throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
		}
		$currency = $this->orders->currencyOf( $order );
		$expected = $this->expected( $refund );
		$last     = $this->marker->append( '', $expected, $currency );
		$remarks  = trim( sprintf( 'Refund %s of order %s: %s', $refund->reference, $refund->orderRef, $refund->reason ), ' :' );
		$body     = array(
			'CardCode'      => (string) ( $order['CardCode'] ?? '' ),
			'NumAtCard'     => $refund->reference,
			'DocCurrency'   => $currency,
			'Comments'      => $this->marker->append( $warnings->fit( 'Comments', $remarks, 254 - ( '' === $last ? 0 : strlen( $last ) + 1 ) ), $expected, $currency ),
			'DocumentLines' => $this->lines( $refund, $currency ),
		);
		if ( '' !== $refund->date ) {
			$body['DocDate'] = $refund->date;
			$body['TaxDate'] = $refund->date;
		}
		$created = $this->gateway->create( 'CreditNotes', $body );
		return isset( $created['DocEntry'] ) ? $created : ( $this->find( $refund->reference ) ?? $created );
	}

	/**
	 * What the credit note should come to in minor units: the refund amount, else the sum of its lines.
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
	 * Compare a credit note with the refund: currency, and total within one minor unit. A credit note is a final document, so it is never
	 * reported as synced unchecked.
	 *
	 * @param array<string,mixed> $note     Credit note.
	 * @param int|null            $expected Refund amount in minor units.
	 * @param string              $currency ISO code of the order.
	 * @param TotalCheck          $total    Total check.
	 * @param Warnings            $warnings Receives accepted differences.
	 */
	public function check( array $note, ?int $expected, string $currency, TotalCheck $total, Warnings $warnings ): ?\Estasoft\SyncEngine\Contract\ConnectorError {
		$actual = strtoupper( (string) ( $note['DocCurrency'] ?? '' ) );
		if ( '' !== $actual && strtoupper( $currency ) !== $actual ) {
			return Problem::validation(
				$this->system,
				'DocCurrency',
				sprintf(
					/* translators: 1: currency in SAP, 2: currency of the order. */
					__( 'SAP holds the credit note in %1$s but the order is in %2$s.', 'estasoft-erp-crm-sync-engine' ),
					$actual,
					$currency
				)
			);
		}
		$figure = $this->orders->totalOf( $note, $currency );
		return $total->run( $expected, null === $figure ? null : abs( $figure ), $currency, $warnings, __( 'credit note total', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The check findExisting() can make without a payload: the credit note total against the refund total written in its remarks.
	 *
	 * @param array<string,mixed> $note  Credit note.
	 * @param TotalCheck          $total Total check.
	 */
	public function checkMarked( array $note, TotalCheck $total ): ?\Estasoft\SyncEngine\Contract\ConnectorError {
		$marked = $this->marker->read( (string) ( $note['Comments'] ?? '' ) );
		return null === $marked ? null : $this->check( $note, $marked['minor'], $marked['currency'], $total, new Warnings() );
	}

	/**
	 * Cancel a credit note that failed the check (the Cancel action makes the cancelling document).
	 *
	 * @param array<string,mixed> $note Credit note.
	 * @return bool False when SAP refused (the credit note stays and a person cancels it).
	 */
	public function cancel( array $note ): bool {
		try {
			$this->gateway->post( 'CreditNotes(' . rawurlencode( (string) ( $note['DocEntry'] ?? '' ) ) . ')/Cancel', array() );
			return true;
		} catch ( ApiException $exception ) {
			unset( $exception );
			return false;
		}
	}

	/**
	 * The reference the engine stores for a credit note.
	 *
	 * @param array<string,mixed> $note     Credit note.
	 * @param string              $currency ISO code.
	 */
	public function reference( array $note, string $currency ): ExternalRef {
		return $this->orders->reference( $note, $currency );
	}

	/**
	 * Credit note lines.
	 *
	 * @param RefundData $refund   Checked payload.
	 * @param string     $currency ISO code of the order.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException When an amount-only refund has no item.
	 */
	private function lines( RefundData $refund, string $currency ): array {
		if ( array() === $refund->lines ) {
			if ( '' === $this->options->refundItem() || null === $refund->amountMinor ) {
				$problem = Problem::validation( $this->system, 'refund_item', __( 'The refund names no products. Enter the code of an item for such refunds in the connection options.', 'estasoft-erp-crm-sync-engine' ) );
				throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
			}
			return array(
				array(
					'ItemCode'  => $this->options->refundItem(),
					'Quantity'  => 1,
					'LineTotal' => $this->amounts->toNumber( $refund->amountMinor, $currency ),
				),
			);
		}
		$lines = array();
		foreach ( $refund->lines as $line ) {
			$entry = array(
				'ItemCode'  => '' !== $line->sku ? $line->sku : $this->options->refundItem(),
				'Quantity'  => false === strpos( $line->quantity, '.' ) ? (int) $line->quantity : (float) $line->quantity,
				'LineTotal' => $this->amounts->toNumber( $line->amountMinor, $currency ),
			);
			if ( '' === $entry['ItemCode'] ) {
				$problem = Problem::validation( $this->system, 'refund_item', __( 'A refund line names no product. Enter the code of an item for such lines in the connection options.', 'estasoft-erp-crm-sync-engine' ) );
				throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
			}
			if ( '' !== $line->taxCode() ) {
				$entry[ $this->options->taxField() ] = $line->taxCode();
			}
			$lines[] = $entry;
		}
		return $lines;
	}
}
