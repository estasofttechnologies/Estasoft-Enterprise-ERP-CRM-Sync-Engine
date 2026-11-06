<?php
/**
 * Sends a WooCommerce order to Acumatica as a Sales Order.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Acumatica;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\ErpCommon\Options;
use Estasoft\SyncEngine\Connectors\ErpCommon\OrderPayload;
use Estasoft\SyncEngine\Connectors\ErpCommon\Problems;
use Estasoft\SyncEngine\Connectors\ErpCommon\Text;
use Estasoft\SyncEngine\Connectors\ErpCommon\TotalCheck;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Contract\SendResult;
use Estasoft\SyncEngine\Domain\Item;

/**
 * The order is created on hold, its currency and total are read back, and only then is the hold removed. An order that a previous pass
 * left on hold (because the totals differed) is found by its `CustomerOrder` and checked again instead of being created a second time.
 */
final class AcumaticaOrderSender {

	/**
	 * Fields read when an order is searched.
	 */
	private const SELECT = 'OrderType,OrderNbr,Status,OrderTotal,CurrencyID,CustomerID,CustomerOrder,Date';

	/**
	 * Longest `CustomerOrder` the field is assumed to take.
	 */
	private const REF_MAX = 40;

	/**
	 * Set up with the services it uses.
	 *
	 * @param AcumaticaClient    $client    Client.
	 * @param Options            $options   Connection options.
	 * @param Amounts            $amounts   Money helper.
	 * @param AcumaticaLookups   $lookups   Setup lookups.
	 * @param AcumaticaCustomers $customers Customer handling.
	 * @param string             $system    System name.
	 * @param string             $address   Site address, for record links.
	 */
	public function __construct(
		private readonly AcumaticaClient $client,
		private readonly Options $options,
		private readonly Amounts $amounts,
		private readonly AcumaticaLookups $lookups,
		private readonly AcumaticaCustomers $customers,
		private readonly string $system,
		private readonly string $address
	) {
	}

	/**
	 * The order type used (option, default SO).
	 */
	public function orderType(): string {
		$type = strtoupper( $this->options->text( 'order_type' ) );
		return 1 === preg_match( '/^[A-Z0-9]{2}$/', $type ) ? $type : 'SO';
	}

	/**
	 * The record of an order that is not on hold and not cancelled; null otherwise.
	 *
	 * @param Item $item The queue item.
	 *
	 * @throws ApiException On failure.
	 */
	public function finished( Item $item ): ?ExternalRef {
		$order = $this->find( $item->clientReference() );
		if ( null === $order || 'hold' === strtolower( $order['status'] ) ) {
			return null;
		}
		return $this->reference( $order );
	}

	/**
	 * The Sales Order of a client reference (the oldest that is not cancelled).
	 *
	 * @param string $clientRef WC-{id}.
	 * @return array{type:string,nbr:string,status:string,total:float,currency:string,customer:string,ref:string,date:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function find( string $clientRef ): ?array {
		$rows = $this->client->query(
			'SalesOrder',
			array(
				'$filter' => 'OrderType eq ' . AcumaticaValue::literal( $this->orderType() ) . ' and CustomerOrder eq ' . AcumaticaValue::literal( $clientRef ),
				'$select' => self::SELECT,
			),
			10
		);
		foreach ( $rows as $row ) {
			$order = $this->flat( $row );
			if ( strtolower( $order['ref'] ) === strtolower( $clientRef ) && 'cancelled' !== strtolower( $order['status'] ) ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * Send an order, finishing whatever an earlier pass left undone.
	 *
	 * @param Item         $item    The queue item.
	 * @param OrderPayload $payload The order.
	 *
	 * @throws ApiException On failure.
	 */
	public function send( Item $item, OrderPayload $payload ): SendResult {
		unset( $item );
		$existing = $this->find( $payload->clientRef );
		$warnings = array();
		if ( null === $existing ) {
			$created = $this->create( $payload, $warnings );
			if ( $created instanceof ConnectorError ) {
				return SendResult::failure( $created );
			}
			$order = $created;
		} else {
			$order = $existing;
			if ( 'hold' === strtolower( $order['status'] ) ) {
				$problem = $this->verify( $order, $payload, $warnings );
				if ( $problem instanceof ConnectorError ) {
					return SendResult::failure( $problem );
				}
			}
		}
		if ( 'hold' === strtolower( $order['status'] ) ) {
			$stored = $this->client->put(
				'SalesOrder',
				AcumaticaValue::fields(
					array(
						'OrderType' => $order['type'],
						'OrderNbr'  => $order['nbr'],
						'Hold'      => false,
					)
				),
				array( '$select' => self::SELECT )
			);
			$order  = array_merge( $order, array_filter( $this->flat( $stored ), static fn( mixed $value ): bool => '' !== $value ) );
		}
		$ref = $this->reference( $order, $warnings );
		return null === $existing ? SendResult::created( $ref ) : SendResult::existing( $ref );
	}

	/**
	 * The record for an order.
	 *
	 * @param array{type:string,nbr:string,status:string,total:float,currency:string,customer:string,ref:string,date:string} $order    Order.
	 * @param array<int,string>                                                                                              $warnings Warnings to carry.
	 */
	public function reference( array $order, array $warnings = array() ): ExternalRef {
		$extra = array(
			'total_minor' => $this->amounts->toMinor( $order['total'], $this->amounts->exponent( $order['currency'] ) ),
			'currency'    => $order['currency'],
			'status'      => $order['status'],
		);
		if ( array() !== $warnings ) {
			$extra['warnings'] = $warnings;
		}
		return new ExternalRef( $order['type'] . '/' . $order['nbr'], $order['nbr'], $this->link( 'SO301000', 'OrderType=' . rawurlencode( $order['type'] ) . '&OrderNbr=' . rawurlencode( $order['nbr'] ) ), $extra );
	}

	/**
	 * A link to a screen of the site.
	 *
	 * @param string $screen Screen id.
	 * @param string $query  Key query.
	 */
	public function link( string $screen, string $query ): string {
		return rtrim( $this->address, '/' ) . '/Main?ScreenId=' . $screen . '&' . $query;
	}

	/**
	 * A stored Sales Order as plain values.
	 *
	 * @param array<string,mixed> $row Record from the API.
	 * @return array{type:string,nbr:string,status:string,total:float,currency:string,customer:string,ref:string,date:string}
	 */
	public function flat( array $row ): array {
		$total = AcumaticaValue::get( $row, 'OrderTotal' );
		return array(
			'type'     => AcumaticaValue::text( $row, 'OrderType' ),
			'nbr'      => AcumaticaValue::text( $row, 'OrderNbr' ),
			'status'   => AcumaticaValue::text( $row, 'Status' ),
			'total'    => is_numeric( $total ) ? (float) $total : 0.0,
			'currency' => strtoupper( AcumaticaValue::text( $row, 'CurrencyID' ) ),
			'customer' => AcumaticaValue::text( $row, 'CustomerID' ),
			'ref'      => AcumaticaValue::text( $row, 'CustomerOrder' ),
			'date'     => substr( AcumaticaValue::text( $row, 'Date' ), 0, 10 ),
		);
	}

	/**
	 * Check, then create the order on hold and verify it.
	 *
	 * @param OrderPayload      $payload  The order.
	 * @param array<int,string> $warnings Receives warnings.
	 * @return array{type:string,nbr:string,status:string,total:float,currency:string,customer:string,ref:string,date:string}|ConnectorError
	 *
	 * @throws ApiException On failure.
	 */
	private function create( OrderPayload $payload, array &$warnings ): array|ConnectorError {
		$problem = $this->precheck( $payload );
		if ( $problem instanceof ConnectorError ) {
			return $problem;
		}
		$skus = array_map( static fn( array $line ): string => (string) $line['sku'], $payload->lines );
		foreach ( $payload->extras as $row ) {
			$skus[] = $this->extraItem( $row );
		}
		$items = $this->lookups->items( $skus );
		foreach ( $skus as $sku ) {
			if ( ! isset( $items[ strtolower( $sku ) ] ) ) {
				return Problems::missingProduct( $this->system, $sku );
			}
		}
		$categories = $this->lookups->taxCategories();
		foreach ( $payload->taxCodes() as $code ) {
			if ( ! isset( $categories[ strtolower( $code ) ] ) ) {
				return Problems::missingTax( $this->system, $code );
			}
		}
		$customer = $this->customers->resolve( $payload->partner );
		if ( $customer instanceof ConnectorError ) {
			return $customer;
		}

		$exponent = $this->amounts->exponent( $payload->currency );
		$details  = array();
		foreach ( $payload->lines as $line ) {
			$unit      = $this->discounted( (int) $line['price_minor'], (float) $line['discount'] );
			$details[] = $this->detail( $items[ strtolower( (string) $line['sku'] ) ]['id'], (float) $line['quantity'], $this->amounts->toDecimal( $unit, $exponent ), $line['tax'], $categories );
		}
		foreach ( $payload->extras as $row ) {
			$sign      = 'discount' === $row['kind'] ? -1 : 1;
			$details[] = $this->detail( $items[ strtolower( $this->extraItem( $row ) ) ]['id'], 1.0, $sign * $this->amounts->toDecimal( (int) $row['amount_minor'], $exponent ), $row['tax'] ?? null, $categories );
		}

		$body = AcumaticaValue::fields(
			array(
				'OrderType'     => $this->orderType(),
				'CustomerID'    => $customer['id'],
				'CustomerOrder' => $payload->clientRef,
				'Date'          => ( '' !== $payload->date ? $payload->date : gmdate( 'Y-m-d' ) ) . 'T00:00:00',
				'CurrencyID'    => $payload->currency,
				'Description'   => Text::fit( $payload->note, 255 ),
				'Hold'          => true,
			)
		);
		if ( $this->customers->differs( $payload->partner, $payload->delivery ) ) {
			$body['ShipToAddressOverride'] = AcumaticaValue::wrap( true );
			$body['ShipToAddress']         = $this->customers->address( $payload->delivery );
		}
		$body['Details'] = $details;

		$stored = $this->client->put( 'SalesOrder', $body, array( '$expand' => 'Details' ) );
		$order  = $this->flat( $stored );
		if ( '' === $order['nbr'] ) {
			return Problems::refused( $this->system, 'sales_order', __( 'Acumatica did not return the new order.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( '' === $order['status'] ) {
			$order['status'] = 'Hold';
		}
		$problem = $this->verify( $order, $payload, $warnings );
		return $problem instanceof ConnectorError ? $problem : $order;
	}

	/**
	 * Everything that can be refused without a request.
	 *
	 * @param OrderPayload $payload The order.
	 */
	private function precheck( OrderPayload $payload ): ?ConnectorError {
		if ( '' === $payload->currency ) {
			return Problems::refused( $this->system, 'currency', __( 'The order has no currency.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( Text::exceeds( $payload->clientRef, self::REF_MAX ) ) {
			return Problems::refused( $this->system, 'client_ref', __( 'The order reference is longer than the Customer Order field of Acumatica takes.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		if ( $payload->pricesIncludeTax ) {
			return Problems::refused(
				$this->system,
				'prices_include_tax',
				__( 'Acumatica works out tax itself from the customer and the tax category, and this connector sends prices without tax. Set the shop to show prices without tax in the mapping.', 'estasoft-enterprise-erpcrm-sync-engine' )
			);
		}
		foreach ( $payload->lines as $line ) {
			if ( is_array( $line['tax'] ?? null ) && count( $line['tax'] ) > 1 ) {
				return Problems::refused( $this->system, 'order_line[].tax', __( 'A line in Acumatica has one tax category. This order has a line with several tax codes.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
			}
		}
		foreach ( $payload->extras as $row ) {
			if ( '' === $this->extraItem( $row ) ) {
				$option = $this->extraOption( $row );
				return Problems::refused(
					$this->system,
					$option,
					sprintf(
						/* translators: %s: option name. */
						__( 'The order has a shipping, fee or discount amount, so the connection settings need the inventory ID in "%s".', 'estasoft-enterprise-erpcrm-sync-engine' ),
						$option
					)
				);
			}
		}
		return null;
	}

	/**
	 * The name of the option that holds the item of an extra row.
	 *
	 * @param array<string,mixed> $row Extra row.
	 */
	private function extraOption( array $row ): string {
		return match ( $row['kind'] ) {
			'shipping' => 'shipping_item',
			'discount' => 'discount_item',
			default    => 'fee_item',
		};
	}

	/**
	 * The inventory ID configured for an extra row.
	 *
	 * @param array<string,mixed> $row Extra row.
	 */
	private function extraItem( array $row ): string {
		return $this->options->text( $this->extraOption( $row ) );
	}

	/**
	 * One detail line.
	 *
	 * @param string                                     $inventory  Inventory ID.
	 * @param float                                      $quantity   Quantity.
	 * @param float                                      $price      Unit price (negative for a discount).
	 * @param array<int,string>|null                     $tax        Tax codes of the line, or null.
	 * @param array<string,array{id:string,name:string}> $categories Tax categories of the site.
	 * @return array<string,array{value:mixed}>
	 */
	private function detail( string $inventory, float $quantity, float $price, ?array $tax, array $categories ): array {
		$category = null !== $tax && array() !== $tax ? ( $categories[ strtolower( (string) $tax[0] ) ]['id'] ?? '' ) : '';
		return AcumaticaValue::fields(
			array(
				'InventoryID' => $inventory,
				'OrderQty'    => $quantity,
				'UnitPrice'   => $price,
				'WarehouseID' => $this->options->text( 'warehouse' ),
				'TaxCategory' => $category,
			)
		);
	}

	/**
	 * Check currency and total of a stored order.
	 *
	 * @param array{type:string,nbr:string,status:string,total:float,currency:string,customer:string,ref:string,date:string} $order    Order.
	 * @param OrderPayload                                                                                                   $payload  The order.
	 * @param array<int,string>                                                                                              $warnings Receives warnings.
	 */
	private function verify( array $order, OrderPayload $payload, array &$warnings ): ?ConnectorError {
		if ( $order['currency'] !== $payload->currency ) {
			return Problems::refused(
				$this->system,
				'currency',
				sprintf(
					/* translators: 1: order number in Acumatica, 2: currency the order has there, 3: currency of the shop. */
					__( 'Order %1$s came back in %2$s but the shop order is in %3$s. It stays on hold in Acumatica; cancel it there and check the currency settings of the customer.', 'estasoft-enterprise-erpcrm-sync-engine' ),
					$order['nbr'],
					'' !== $order['currency'] ? $order['currency'] : '?',
					$payload->currency
				),
				'currency mismatch'
			);
		}
		$check = new TotalCheck( $this->options, $this->amounts, $this->system );
		$diff  = $check->difference( $payload->totalMinor, $order['total'], $payload->currency, $order['nbr'] );
		if ( null === $diff ) {
			return null;
		}
		if ( $check->isStrict() ) {
			return $check->error( $diff, __( 'The order stays on hold in Acumatica. Cancel it there after fixing the tax setup; the next try makes a new one.', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		$warnings[] = $diff['message'];
		return null;
	}

	/**
	 * A unit price reduced by a line discount in percent, in whole numbers.
	 *
	 * @param int   $minor    Price in minor units.
	 * @param float $discount Percent (0 to 100).
	 */
	private function discounted( int $minor, float $discount ): int {
		if ( $discount <= 0.0 ) {
			return $minor;
		}
		$per = (int) round( $discount * 100 );
		$abs = abs( $minor );
		$net = intdiv( $abs * ( 10000 - $per ) + 5000, 10000 );
		return $minor < 0 ? -$net : $net;
	}
}
