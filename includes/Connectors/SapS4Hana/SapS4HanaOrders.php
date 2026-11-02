<?php
/**
 * Sales orders in SAP S/4HANA (API_SALES_ORDER_SRV).
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapS4Hana;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\OrderData;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Problem;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\ShopTotalMarker;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Warnings;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * The client reference WC-{order id} is the customer purchase order number (PurchaseOrderByCustomer, 35 characters) and is searched before
 * every create. An order whose net amount or currency differs from what was sent is deleted again when the merchant chose to stop on
 * a difference (`total_mismatch = fail`); when the system refuses the delete the order stays and the error says so.
 */
final class SapS4HanaOrders {

	public const SERVICE = 'API_SALES_ORDER_SRV';

	public const ENTITY = 'A_SalesOrder';

	/**
	 * Longest client reference S/4HANA takes in PurchaseOrderByCustomer.
	 */
	public const REFERENCE_LENGTH = 35;

	/**
	 * Properties read from an order.
	 */
	private const FIELDS = 'SalesOrder,PurchaseOrderByCustomer,TotalNetAmount,TransactionCurrency,SalesOrderDate,SoldToParty';

	/**
	 * Set up with what it depends on.
	 *
	 * @param SapS4HanaGateway $gateway  Calls.
	 * @param SapS4HanaOptions $options  Options.
	 * @param Amounts          $amounts  Conversion helper.
	 * @param ShopTotalMarker  $marker   Writes and reads the shop's net amount.
	 * @param string           $system   System name for messages.
	 */
	public function __construct(
		private readonly SapS4HanaGateway $gateway,
		private readonly SapS4HanaOptions $options,
		private readonly Amounts $amounts,
		private readonly ShopTotalMarker $marker,
		private readonly string $system
	) {
	}

	/**
	 * The order with a client reference (the oldest when several have it).
	 *
	 * @param string $reference WC-{id}.
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
				'select'  => $this->fields(),
				'orderby' => 'SalesOrder',
			),
			1
		);
		return $rows[0] ?? null;
	}

	/**
	 * Create the order.
	 *
	 * @param OrderData $data     Checked payload.
	 * @param Warnings  $warnings Receives notes.
	 * @return array<string,mixed> The new order.
	 *
	 * @throws ApiException With SYNC-VAL-002 for a payload S/4HANA cannot take, SYNC-MAP-001 for an unknown product; on any request failure.
	 */
	public function create( OrderData $data, Warnings $warnings ): array {
		$soldTo = '' !== $data->rawText( 'sold_to_party' ) ? $data->rawText( 'sold_to_party' ) : $this->options->soldToParty();
		$this->mustHave( 'sold_to_party', $soldTo, __( 'Enter the customer number all web orders are sold to in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
		$this->mustHave( 'sales_organization', $this->options->salesOrganization(), __( 'Enter the sales organization in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
		$this->mustHave( 'distribution_channel', $this->options->distributionChannel(), __( 'Enter the distribution channel in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
		$this->mustHave( 'division', $this->options->division(), __( 'Enter the division in the connection settings.', 'estasoft-erp-crm-sync-engine' ) );
		if ( strlen( $data->reference ) > self::REFERENCE_LENGTH ) {
			$this->refuse( 'PurchaseOrderByCustomer', __( 'The order reference is longer than 35 characters, which S/4HANA does not take.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( array() !== $data->charges ) {
			if ( 'fail' === $this->options->extraCharges() ) {
				$this->refuse( 'extra_charges', __( 'The order has shipping, fees or a discount. S/4HANA prices those with condition types of your pricing procedure, which this connection cannot know. Set extra_charges to ignore to leave them out.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$warnings->add( __( 'Shipping, fees and discounts were left out of the sales order.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$this->assertProductsExist( $data );
		$items = array();
		foreach ( $data->lines as $line ) {
			$item = array(
				'Material'          => $line->sku,
				'RequestedQuantity' => $line->quantity,
			);
			if ( 'shop' === $this->options->priceSource() ) {
				$item['to_PricingElement'] = array(
					array(
						'ConditionType'      => $this->options->priceCondition(),
						'ConditionRateValue' => $this->amounts->toDecimal( $line->priceMinor, $data->currency ),
						'ConditionCurrency'  => $data->currency,
					),
				);
			}
			$items[] = $item;
		}
		$body = array(
			'SalesOrderType'          => $this->options->salesOrderType(),
			'SalesOrganization'       => $this->options->salesOrganization(),
			'DistributionChannel'     => $this->options->distributionChannel(),
			'OrganizationDivision'    => $this->options->division(),
			'SoldToParty'             => $soldTo,
			'PurchaseOrderByCustomer' => $data->reference,
			'TransactionCurrency'     => $data->currency,
			'to_Item'                 => $items,
		);
		if ( '' !== $this->options->totalField() && 'shop' === $this->options->priceSource() ) {
			$body[ $this->options->totalField() ] = $this->marker->append( '', $this->expectedNet( $data ), $data->currency );
		}
		if ( '' !== $data->orderDate ) {
			$body['SalesOrderDate']            = $this->gateway->jsonDate( $data->orderDate );
			$body['CustomerPurchaseOrderDate'] = $this->gateway->jsonDate( $data->orderDate );
		}
		$created = $this->gateway->create( self::SERVICE, self::ENTITY, $body );
		if ( ! isset( $created['SalesOrder'] ) ) {
			$created = $this->find( $data->reference );
		}
		if ( null === $created ) {
			$this->refuse( 'SalesOrder', __( 'S/4HANA accepted the order but it cannot be read back.', 'estasoft-erp-crm-sync-engine' ) );
		}
		return $created;
	}

	/**
	 * Compare the order with what was sent: currency, and the net amount against the sum of the lines (shop prices only).
	 *
	 * @param array<string,mixed> $order    Order from S/4HANA.
	 * @param OrderData           $data     Payload.
	 * @param TotalCheck          $total    Total check.
	 * @param Warnings            $warnings Receives accepted differences.
	 * @return ConnectorError|null
	 */
	public function check( array $order, OrderData $data, TotalCheck $total, Warnings $warnings ): ?ConnectorError {
		$actual = strtoupper( (string) ( $order['TransactionCurrency'] ?? '' ) );
		if ( '' !== $actual && $actual !== $data->currency ) {
			return Problem::validation(
				$this->system,
				'TransactionCurrency',
				sprintf(
					/* translators: 1: currency in S/4HANA, 2: currency of the shop. */
					__( 'S/4HANA holds the order in %1$s but the shop charged in %2$s.', 'estasoft-erp-crm-sync-engine' ),
					$actual,
					$data->currency
				)
			);
		}
		if ( 'shop' !== $this->options->priceSource() ) {
			$warnings->add( __( 'S/4HANA priced the order itself, so its amount was not compared with the shop.', 'estasoft-erp-crm-sync-engine' ) );
			return null;
		}
		$expected = $this->expectedNet( $data );
		return $total->run( $expected, $this->amounts->fromWire( $order['TotalNetAmount'] ?? null, $data->currency ), $data->currency, $warnings, __( 'net amount', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The net amount S/4HANA should show with the shop's prices: the sum of quantity times unit price of the goods lines.
	 *
	 * @param OrderData $data Payload.
	 */
	public function expectedNet( OrderData $data ): int {
		$expected = 0;
		foreach ( $data->lines as $line ) {
			$expected += $this->amounts->lineTotal( $line->priceMinor, $line->quantity );
		}
		return $expected;
	}

	/**
	 * The check findExisting() can make without a payload: the net amount against the shop's net amount written in the header property named
	 * by the option `total_field`. Without that option nothing is written, so nothing is compared.
	 *
	 * @param array<string,mixed> $order Order from S/4HANA.
	 * @param TotalCheck          $total Total check.
	 */
	public function checkMarked( array $order, TotalCheck $total ): ?ConnectorError {
		$field = $this->options->totalField();
		if ( '' === $field ) {
			return null;
		}
		$marked = $this->marker->read( (string) ( $order[ $field ] ?? '' ) );
		if ( null === $marked ) {
			return null;
		}
		$actual = strtoupper( (string) ( $order['TransactionCurrency'] ?? '' ) );
		if ( '' !== $actual && $actual !== $marked['currency'] ) {
			return Problem::validation( $this->system, 'TransactionCurrency', __( 'The order exists in S/4HANA but in another currency than the shop charged. Correct or delete it in S/4HANA.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$error = $total->run( $marked['minor'], $this->amounts->fromWire( $order['TotalNetAmount'] ?? null, $marked['currency'] ), $marked['currency'], new Warnings(), __( 'net amount', 'estasoft-erp-crm-sync-engine' ) );
		return null === $error ? null : Problem::validation( $this->system, 'net amount', (string) $error->context['reason'] . ' ' . __( 'Correct or delete the order in S/4HANA.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * The properties read from an order.
	 */
	private function fields(): string {
		return '' === $this->options->totalField() ? self::FIELDS : self::FIELDS . ',' . $this->options->totalField();
	}

	/**
	 * Delete an order that failed the check.
	 *
	 * @param array<string,mixed> $order Order from S/4HANA.
	 * @return bool False when S/4HANA refused.
	 */
	public function remove( array $order ): bool {
		return $this->gateway->delete( self::SERVICE, self::ENTITY, (string) ( $order['SalesOrder'] ?? '' ) );
	}

	/**
	 * The reference the engine stores for an order.
	 *
	 * @param array<string,mixed> $order    Order from S/4HANA.
	 * @param string              $currency ISO code.
	 * @param array<string,mixed> $extra    More facts.
	 */
	public function reference( array $order, string $currency, array $extra = array() ): ExternalRef {
		$total = '' === $currency ? null : $this->amounts->fromWire( $order['TotalNetAmount'] ?? null, $currency );
		return new ExternalRef(
			(string) ( $order['SalesOrder'] ?? '' ),
			(string) ( $order['SalesOrder'] ?? '' ),
			null,
			array(
				'net_minor' => null === $total ? 0 : abs( $total ),
				'currency'  => $currency,
			) + $extra
		);
	}

	/**
	 * Stop with SYNC-MAP-001 for the first product that is not in S/4HANA.
	 *
	 * @param OrderData $data Payload.
	 *
	 * @throws ApiException With SYNC-MAP-001.
	 */
	private function assertProductsExist( OrderData $data ): void {
		$codes = array_values( array_unique( array_map( static fn( $line ): string => $line->sku, $data->lines ) ) );
		foreach ( array_chunk( $codes, 20 ) as $chunk ) {
			$filter = implode( ' or ', array_map( fn( string $code ): string => 'Product eq ' . $this->gateway->literal( $code ), $chunk ) );
			$found  = array();
			foreach ( $this->gateway->list(
				'API_PRODUCT_SRV',
				'A_Product',
				array(
					'filter' => $filter,
					'select' => 'Product',
				),
				40
			) as $row ) {
				$found[] = strtoupper( (string) ( $row['Product'] ?? '' ) );
			}
			foreach ( $chunk as $code ) {
				if ( ! in_array( strtoupper( $code ), $found, true ) ) {
					$problem = Problem::unknownSku( $this->system, $code );
					throw new ApiException( $problem ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the argument is an error object, not output.
				}
			}
		}
	}

	/**
	 * Refuse a setting that is missing.
	 *
	 * @param string $field  Setting name.
	 * @param string $value  Value.
	 * @param string $reason Why it is needed.
	 *
	 * @throws ApiException With SYNC-VAL-002 when the value is empty.
	 */
	private function mustHave( string $field, string $value, string $reason ): void {
		if ( '' === $value ) {
			$this->refuse( $field, $reason );
		}
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
