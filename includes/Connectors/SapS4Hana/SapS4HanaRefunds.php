<?php
/**
 * Credit memo requests in SAP S/4HANA (API_CREDIT_MEMO_REQUEST_SRV).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapS4Hana;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\RefundData;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Warnings;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * A refund is a credit memo request that refers to the sales order: S/4HANA turns a request into a credit memo in its own billing step. The
 * request carries `WC-{refund id}` as its customer purchase order number and is searched for before every create. Entity and property names of
 * this service could not be read from SAP's pages and are listed in docs/connectors/sap-s4hana.md under "Not verified".
 */
final class SapS4HanaRefunds {

	public const SERVICE = 'API_CREDIT_MEMO_REQUEST_SRV';

	public const ENTITY = 'A_CreditMemoRequest';

	/**
	 * Set up with what it depends on.
	 *
	 * @param SapS4HanaGateway $gateway Calls.
	 * @param SapS4HanaOrders  $orders  Orders.
	 * @param SapS4HanaOptions $options Options.
	 * @param Amounts          $amounts Conversion helper.
	 * @param string           $system  System name for messages.
	 */
	public function __construct(
		private readonly SapS4HanaGateway $gateway,
		private readonly SapS4HanaOrders $orders,
		private readonly SapS4HanaOptions $options,
		private readonly Amounts $amounts,
		private readonly string $system
	) {
	}

	/**
	 * The request with a refund reference.
	 *
	 * @param string $reference WC-{refund id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $reference ): ?array {
		$rows = $this->gateway->list(
			self::SERVICE,
			self::ENTITY,
			array(
				'filter'  => 'PurchaseOrderByCustomer eq ' . $this->gateway->literal( $reference ),
				'select'  => 'CreditMemoRequest,PurchaseOrderByCustomer,TransactionCurrency,TotalNetAmount',
				'orderby' => 'CreditMemoRequest',
			),
			1
		);
		return $rows[0] ?? null;
	}

	/**
	 * Make the request.
	 *
	 * @param RefundData $refund Checked payload.
	 * @return array<string,mixed> The new request.
	 *
	 * @throws ApiException When the order is missing, a line names no product, or S/4HANA refuses.
	 */
	public function create( RefundData $refund ): array {
		$order = $this->orders->find( $refund->orderRef );
		if ( null === $order ) {
			$this->refuse( 'order_ref', __( 'The order of this refund is not in S/4HANA yet.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$currency = strtoupper( (string) ( $order['TransactionCurrency'] ?? '' ) );
		if ( array() === $refund->lines ) {
			$this->refuse( 'refund_line', __( 'S/4HANA needs a product on every refund line. Refund items, or credit the amount in S/4HANA.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$items = array();
		foreach ( $refund->lines as $line ) {
			if ( '' === $line->sku ) {
				$this->refuse( 'refund_line[].product', __( 'A refund line names no product, which S/4HANA needs.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$items[] = array(
				'Material'          => $line->sku,
				'RequestedQuantity' => $line->quantity,
				'to_PricingElement' => array(
					array(
						'ConditionType'      => $this->options->priceCondition(),
						'ConditionRateValue' => $this->amounts->toDecimal( $this->amounts->unitPrice( $line->amountMinor, $line->quantity ), $currency ),
						'ConditionCurrency'  => $currency,
					),
				),
			);
		}
		$body    = array(
			'CreditMemoRequestType'   => $this->options->creditMemoType(),
			'SalesOrganization'       => $this->options->salesOrganization(),
			'DistributionChannel'     => $this->options->distributionChannel(),
			'OrganizationDivision'    => $this->options->division(),
			'SoldToParty'             => (string) ( $order['SoldToParty'] ?? $this->options->soldToParty() ),
			'PurchaseOrderByCustomer' => $refund->reference,
			'TransactionCurrency'     => $currency,
			'ReferenceSDDocument'     => (string) ( $order['SalesOrder'] ?? '' ),
			'to_Item'                 => $items,
		);
		$created = $this->gateway->create( self::SERVICE, self::ENTITY, $body );
		if ( ! isset( $created['CreditMemoRequest'] ) ) {
			$created = $this->find( $refund->reference );
		}
		if ( null === $created ) {
			$this->refuse( 'CreditMemoRequest', __( 'S/4HANA accepted the credit memo request but it cannot be read back.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $created + array( 'OrderCurrency' => $currency );
	}

	/**
	 * What the request's net amount should be: the sum of the refund lines (without tax).
	 *
	 * @param RefundData $refund Payload.
	 */
	public function expectedNet( RefundData $refund ): int {
		$sum = 0;
		foreach ( $refund->lines as $line ) {
			$sum += $line->amountMinor;
		}
		return $sum;
	}

	/**
	 * Compare the request with the refund: currency, and net amount within one minor unit. The price condition is the only thing that sets
	 * the amount, and whether the pricing procedure accepts a manual value is not verified, so a request is never reported as synced unchecked.
	 *
	 * @param array<string,mixed> $request  Request from S/4HANA.
	 * @param RefundData          $refund   Payload.
	 * @param string              $currency Currency of the order.
	 * @param TotalCheck          $total    Total check.
	 * @param Warnings            $warnings Receives accepted differences.
	 */
	public function check( array $request, RefundData $refund, string $currency, TotalCheck $total, Warnings $warnings ): ?\Estasoft\SyncEngine\Contract\ConnectorError {
		$actual = strtoupper( (string) ( $request['TransactionCurrency'] ?? '' ) );
		if ( '' !== $actual && $actual !== $currency ) {
			return Problem::validation(
				$this->system,
				'TransactionCurrency',
				sprintf(
					/* translators: 1: currency in S/4HANA, 2: currency of the order. */
					__( 'S/4HANA holds the credit memo request in %1$s but the order is in %2$s.', 'estasoft-erp-crm-sync-engine' ),
					$actual,
					$currency
				)
			);
		}
		$figure = $this->amounts->fromWire( $request['TotalNetAmount'] ?? null, $currency );
		return $total->run( $this->expectedNet( $refund ), null === $figure ? null : abs( $figure ), $currency, $warnings, __( 'net amount', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * Delete a request that failed the check.
	 *
	 * @param array<string,mixed> $request Request from S/4HANA.
	 * @return bool False when S/4HANA refused.
	 */
	public function remove( array $request ): bool {
		return $this->gateway->delete( self::SERVICE, self::ENTITY, (string) ( $request['CreditMemoRequest'] ?? '' ), 'CreditMemoRequest' );
	}

	/**
	 * The reference the engine stores for a request.
	 *
	 * @param array<string,mixed> $request Request from S/4HANA.
	 */
	public function reference( array $request ): ExternalRef {
		return new ExternalRef( (string) ( $request['CreditMemoRequest'] ?? '' ), (string) ( $request['CreditMemoRequest'] ?? '' ) );
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
