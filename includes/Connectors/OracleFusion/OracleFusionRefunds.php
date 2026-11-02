<?php
/**
 * Refunds as on-account credit memos.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\OracleFusion;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\RefundData;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\ShopTotalMarker;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Warnings;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * A refund is an on-account credit memo in the order's business unit and currency, billed to the account of the order's invoice. The
 * connector does not apply it to the invoice: a Receivables clerk does that. The resource `receivablesCreditMemos` is documented; the names
 * of its attributes and of the line array are taken from the invoice resource and are listed under "Not verified" in
 * docs/connectors/oracle-fusion.md. They are constants here so one place changes when a pod shows otherwise.
 */
final class OracleFusionRefunds {

	public const RESOURCE = 'receivablesCreditMemos';

	/**
	 * Name of the child array of credit memo lines [inferred].
	 */
	public const LINES = 'receivablesCreditMemoLines';

	/**
	 * Set up with what it depends on.
	 *
	 * @param OracleFusionGateway $gateway Calls.
	 * @param OracleFusionOrders  $orders  Invoices.
	 * @param OracleFusionOptions $options Options.
	 * @param Amounts             $amounts Conversion helper.
	 * @param ShopTotalMarker     $marker  Shop total in the comments.
	 * @param string              $system  System name for messages.
	 */
	public function __construct(
		private readonly OracleFusionGateway $gateway,
		private readonly OracleFusionOrders $orders,
		private readonly OracleFusionOptions $options,
		private readonly Amounts $amounts,
		private readonly ShopTotalMarker $marker,
		private readonly string $system
	) {
	}

	/**
	 * The credit memo with a refund reference.
	 *
	 * @param string $reference WC-{refund id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $reference ): ?array {
		return $this->orders->findIn( self::RESOURCE, $reference );
	}

	/**
	 * Make the credit memo.
	 *
	 * @param RefundData $refund   Checked payload.
	 * @param Warnings   $warnings Receives notes.
	 * @return array<string,mixed> The new credit memo.
	 *
	 * @throws ApiException When the invoice is missing, the amount is missing, or Fusion refuses.
	 */
	public function create( RefundData $refund, Warnings $warnings ): array {
		$this->orders->requireUnit();
		if ( strlen( $refund->reference ) > $this->options->referenceLength() ) {
			$this->refuse( $this->options->referenceAttribute(), __( 'The refund reference is longer than Oracle Fusion takes in this field.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$invoice = $this->orders->find( $refund->orderRef );
		if ( null === $invoice ) {
			$this->refuse( 'order_ref', __( 'The invoice of this refund is not in Oracle Fusion yet.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$currency = strtoupper( (string) ( $invoice['InvoiceCurrencyCode'] ?? '' ) );
		if ( '' === $currency ) {
			$this->refuse( 'InvoiceCurrencyCode', __( 'Oracle Fusion did not show the currency of the invoice, so the credit memo cannot be made in the same currency.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$source = (string) ( $invoice['TransactionSource'] ?? $this->options->transactionSource() );
		if ( '' === $source ) {
			$this->refuse( 'transaction_source', __( 'Enter the transaction source in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$billTo = (string) ( $invoice['BillToCustomerNumber'] ?? $this->options->billToAccount() );
		if ( '' === $billTo ) {
			$this->refuse( 'BillToCustomerNumber', __( 'Oracle Fusion did not show the customer account of the invoice.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$expected = $this->expected( $refund );
		$comment  = sprintf(
			/* translators: 1: refund reference, 2: order reference. */
			__( 'Refund %1$s of order %2$s', 'estasoft-erp-crm-sync-engine' ),
			$refund->reference,
			$refund->orderRef
		) . ( '' === $refund->reason ? '' : ': ' . $refund->reason );
		$last = $this->marker->append( '', $expected, $currency );
		$body = array(
			'BusinessUnit'                       => $this->options->businessUnit(),
			'TransactionSource'                  => $source,
			'TransactionType'                    => $this->options->creditMemoType(),
			'BillToCustomerNumber'               => $billTo,
			'InvoiceCurrencyCode'                => $currency,
			$this->options->referenceAttribute() => $refund->reference,
			'InvoiceStatus'                      => 'Incomplete',
			'Comments'                           => $this->marker->append( $warnings->fit( 'Comments', $comment, OracleFusionOrders::COMMENTS_LENGTH - ( '' === $last ? 0 : strlen( $last ) + 1 ) ), $expected, $currency ),
			self::LINES                          => $this->lines( $refund, $currency, $warnings ),
		);
		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $refund->date, $match ) ) {
			$body['TransactionDate'] = $match[1];
		}
		$draft = $this->gateway->create( self::RESOURCE, $body );
		if ( ! isset( $draft['CustomerTransactionId'] ) ) {
			$draft = $this->find( $refund->reference );
		}
		if ( null === $draft || ! isset( $draft['CustomerTransactionId'] ) ) {
			$this->refuse( 'CustomerTransactionId', __( 'Oracle Fusion accepted the credit memo but it cannot be read back.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$id    = (string) $draft['CustomerTransactionId'];
		$draft = ( $this->gateway->one( self::RESOURCE, $id ) ?? array() ) + $draft;
		$bad   = $this->draftProblem( $draft, $currency );
		if ( null !== $bad ) {
			$this->discard( $id, $bad );
		}
		try {
			$this->gateway->update( self::RESOURCE, $id, array( 'InvoiceStatus' => 'Complete' ) );
		} catch ( ApiException $exception ) {
			$this->discard( $id, $exception->error()->message() );
		}
		$done = ( $this->gateway->one( self::RESOURCE, $id ) ?? array() ) + $draft;
		return $done + array( 'OrderCurrency' => $currency );
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
	 * Check a credit memo that is complete: not Incomplete, same currency, and a total that agrees with the refund (the sign does not
	 * count here, it was checked on the draft).
	 *
	 * @param array<string,mixed> $memo     Credit memo from Fusion.
	 * @param int|null            $expected Refund amount in minor units.
	 * @param string              $currency ISO code of the order.
	 * @param TotalCheck          $total    Total check.
	 * @param Warnings            $warnings Receives accepted differences.
	 */
	public function check( array $memo, ?int $expected, string $currency, TotalCheck $total, Warnings $warnings ): ?ConnectorError {
		$unfinished = $this->orders->unfinished( $memo );
		if ( null !== $unfinished ) {
			return $unfinished;
		}
		$actual = strtoupper( (string) ( $memo['InvoiceCurrencyCode'] ?? '' ) );
		if ( '' !== $actual && '' !== $currency && $actual !== $currency ) {
			return Problem::validation(
				$this->system,
				'InvoiceCurrencyCode',
				sprintf(
					/* translators: 1: currency of the credit memo, 2: currency of the order. */
					__( 'Oracle Fusion holds the credit memo in %1$s but the invoice is in %2$s.', 'estasoft-erp-crm-sync-engine' ),
					$actual,
					$currency
				)
			);
		}
		$figure = $this->orders->totalOf( $memo, false );
		$error  = $total->run( $expected, null === $figure ? null : abs( $figure['minor'] ), $currency, $warnings, __( 'credit memo total', 'estasoft-erp-crm-sync-engine' ) );
		return null === $error ? null : Problem::validation( $this->system, 'credit memo total', (string) $error->context['reason'] . ' ' . __( 'The credit memo stays in Oracle Fusion; correct or reverse it there.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The check findExisting() can make without a payload: status, and the credit memo total against the refund total in its comments.
	 *
	 * @param array<string,mixed> $memo  Credit memo from Fusion.
	 * @param TotalCheck          $total Total check.
	 */
	public function checkMarked( array $memo, TotalCheck $total ): ?ConnectorError {
		$marked = $this->marker->read( (string) ( $memo['Comments'] ?? '' ) );
		return $this->check( $memo, null === $marked ? null : $marked['minor'], null === $marked ? '' : $marked['currency'], $total, new Warnings() );
	}

	/**
	 * What is wrong with a draft credit memo, or null. A credit memo that would post as a debit is the worst case: the sign convention of
	 * the lines was inferred, not read from Oracle, so the total of the draft decides.
	 *
	 * @param array<string,mixed> $draft    Draft read back.
	 * @param string              $currency ISO code of the invoice.
	 */
	private function draftProblem( array $draft, string $currency ): ?string {
		$actual = strtoupper( (string) ( $draft['InvoiceCurrencyCode'] ?? '' ) );
		if ( '' !== $actual && $actual !== $currency ) {
			return sprintf(
				/* translators: 1: currency of the draft, 2: currency of the invoice. */
				__( 'Oracle Fusion holds the draft credit memo in %1$s but the invoice is in %2$s.', 'estasoft-erp-crm-sync-engine' ),
				$actual,
				$currency
			);
		}
		$figure = $this->orders->totalOf( $draft, false );
		if ( null === $figure ) {
			return __( 'Oracle Fusion shows no total for the draft credit memo, so it cannot be checked that it credits the customer.', 'estasoft-erp-crm-sync-engine' );
		}
		if ( $figure['minor'] > 0 ) {
			return __( 'Oracle Fusion would book this credit memo as a debit (its total is positive). If this pod wants negative credit memo lines, set the option credit_line_sign to negative.', 'estasoft-erp-crm-sync-engine' );
		}
		return null;
	}

	/**
	 * Delete a draft and stop with the reason.
	 *
	 * @param string $id     CustomerTransactionId of the draft.
	 * @param string $reason Why.
	 *
	 * @throws ApiException Always, with SYNC-VAL-002.
	 */
	private function discard( string $id, string $reason ): never {
		$removed = $this->gateway->delete( self::RESOURCE, $id );
		$this->refuse(
			'credit memo',
			$reason . ' ' . ( $removed ? __( 'The draft credit memo was deleted.', 'estasoft-erp-crm-sync-engine' ) : __( 'The draft credit memo could not be deleted and stays in Oracle Fusion as Incomplete; delete it there.', 'estasoft-erp-crm-sync-engine' ) )
		);
	}

	/**
	 * The reference the engine stores for a credit memo.
	 *
	 * @param array<string,mixed> $memo Credit memo from Fusion.
	 */
	public function reference( array $memo ): ExternalRef {
		return new ExternalRef(
			(string) ( $memo['CustomerTransactionId'] ?? '' ),
			(string) ( $memo['TransactionNumber'] ?? ( $memo['CustomerTransactionId'] ?? '' ) )
		);
	}

	/**
	 * One line per refunded product, or one line for the amount when the refund names no product.
	 *
	 * @param RefundData $refund   Payload.
	 * @param string     $currency ISO code.
	 * @param Warnings   $warnings Receives notes.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ApiException With SYNC-VAL-002 when the refund has neither lines nor an amount.
	 */
	private function lines( RefundData $refund, string $currency, Warnings $warnings ): array {
		$lines = array();
		foreach ( $refund->lines as $index => $line ) {
			$text = '' === $line->sku ? __( 'Refund', 'estasoft-erp-crm-sync-engine' ) : '[' . $line->sku . '] ' . __( 'Refund', 'estasoft-erp-crm-sync-engine' );
			$row  = array(
				'LineNumber'       => $index + 1,
				'Description'      => $warnings->fit( 'Description', $text, OracleFusionOrders::DESCRIPTION_LENGTH ),
				'Quantity'         => $this->signed( str_contains( $line->quantity, '.' ) ? (float) $line->quantity : (int) $line->quantity ),
				'UnitSellingPrice' => $this->amounts->toNumber( $this->amounts->unitPrice( $line->amountMinor, $line->quantity ), $currency ),
			);
			if ( '' !== $line->taxCode() ) {
				$row['TaxClassificationCode'] = $line->taxCode();
			}
			if ( '' !== $this->options->memoLine() ) {
				$row['MemoLine'] = $this->options->memoLine();
			}
			$lines[] = $row;
		}
		if ( array() !== $lines ) {
			return $lines;
		}
		if ( null === $refund->amountMinor || $refund->amountMinor <= 0 ) {
			$this->refuse( 'amount', __( 'The refund has no amount.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$row = array(
			'LineNumber'       => 1,
			'Description'      => $warnings->fit( 'Description', sprintf( /* translators: %s: order reference. */ __( 'Refund of order %s', 'estasoft-erp-crm-sync-engine' ), $refund->orderRef ), OracleFusionOrders::DESCRIPTION_LENGTH ),
			'Quantity'         => $this->signed( 1 ),
			'UnitSellingPrice' => $this->amounts->toNumber( $refund->amountMinor, $currency ),
		);
		if ( '' !== $this->options->memoLine() ) {
			$row['MemoLine'] = $this->options->memoLine();
		}
		return array( $row );
	}

	/**
	 * A quantity with the sign the option asks for.
	 *
	 * @param int|float $quantity Positive quantity.
	 * @return int|float
	 */
	private function signed( int|float $quantity ): int|float {
		return 'negative' === $this->options->creditLineSign() ? -$quantity : $quantity;
	}

	/**
	 * Stop with SYNC-VAL-002.
	 *
	 * @param string $field  Field.
	 * @param string $reason Reason.
	 *
	 * @throws ApiException Always.
	 */
	private function refuse( string $field, string $reason ): never {
		$problem = Problem::validation( $this->system, $field, $reason );
		throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
	}
}
