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
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Warnings;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
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
	 * @param string              $system  System name for messages.
	 */
	public function __construct(
		private readonly OracleFusionGateway $gateway,
		private readonly OracleFusionOrders $orders,
		private readonly OracleFusionOptions $options,
		private readonly Amounts $amounts,
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
		$comment = sprintf(
			/* translators: 1: refund reference, 2: order reference, 3: reason. */
			__( 'Refund %1$s of order %2$s', 'estasoft-erp-crm-sync-engine' ),
			$refund->reference,
			$refund->orderRef
		) . ( '' === $refund->reason ? '' : ': ' . $refund->reason );
		$body = array(
			'BusinessUnit'                       => $this->options->businessUnit(),
			'TransactionSource'                  => $source,
			'TransactionType'                    => $this->options->creditMemoType(),
			'BillToCustomerNumber'               => $billTo,
			'InvoiceCurrencyCode'                => $currency,
			$this->options->referenceAttribute() => $refund->reference,
			'Comments'                           => $warnings->fit( 'Comments', $comment, OracleFusionOrders::COMMENTS_LENGTH ),
			self::LINES                          => $this->lines( $refund, $currency, $warnings ),
		);
		if ( 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $refund->date, $match ) ) {
			$body['TransactionDate'] = $match[1];
		}
		$created = $this->gateway->create( self::RESOURCE, $body );
		if ( ! isset( $created['CustomerTransactionId'] ) ) {
			$created = $this->find( $refund->reference );
		}
		if ( null === $created ) {
			$this->refuse( 'CustomerTransactionId', __( 'Oracle Fusion accepted the credit memo but it cannot be read back.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $created;
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
				'Quantity'         => str_contains( $line->quantity, '.' ) ? (float) $line->quantity : (int) $line->quantity,
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
			'Quantity'         => 1,
			'UnitSellingPrice' => $this->amounts->toNumber( $refund->amountMinor, $currency ),
		);
		if ( '' !== $this->options->memoLine() ) {
			$row['MemoLine'] = $this->options->memoLine();
		}
		return array( $row );
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
