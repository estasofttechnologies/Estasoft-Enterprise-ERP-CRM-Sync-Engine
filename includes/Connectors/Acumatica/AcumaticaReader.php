<?php
/**
 * Reads stock, shipments, invoices and the order list from Acumatica.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Acumatica;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Every read is a plain GET with a filter, so nothing here changes anything in Acumatica.
 */
final class AcumaticaReader {

	/**
	 * Keys asked for in one filter.
	 */
	private const CHUNK = 10;

	/**
	 * Set up with the services it uses.
	 *
	 * @param AcumaticaClient      $client  Client.
	 * @param Amounts              $amounts Money and date helper.
	 * @param AcumaticaOrderSender $orders  Order reads.
	 */
	public function __construct( private readonly AcumaticaClient $client, private readonly Amounts $amounts, private readonly AcumaticaOrderSender $orders ) {
	}

	/**
	 * Quantity on hand per SKU, summed over the warehouses that count.
	 *
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Warehouse IDs; empty means all.
	 * @return array<string,array{quantity:int,held:int}> Keyed by the requested SKU. Items that are not stock items are left out.
	 *
	 * @throws ApiException On failure.
	 */
	public function stock( array $skus, array $warehouses ): array {
		$byLow = array();
		foreach ( $skus as $sku ) {
			$byLow[ strtolower( trim( $sku ) ) ] = $sku;
		}
		$wanted = array_map( 'strtolower', $warehouses );
		$result = array();
		foreach ( array_chunk( array_values( array_filter( array_map( 'trim', $skus ), static fn( string $sku ): bool => '' !== $sku ) ), self::CHUNK ) as $chunk ) {
			$filter = implode( ' or ', array_map( static fn( string $sku ): string => 'InventoryID eq ' . AcumaticaValue::literal( $sku ), $chunk ) );
			$rows   = $this->client->query(
				'StockItem',
				array(
					'$filter' => $filter,
					'$expand' => 'WarehouseDetails',
					'$select' => 'InventoryID,WarehouseDetails/WarehouseID,WarehouseDetails/QtyOnHand',
				)
			);
			foreach ( $rows as $row ) {
				$low = strtolower( AcumaticaValue::text( $row, 'InventoryID' ) );
				if ( ! isset( $byLow[ $low ] ) ) {
					continue;
				}
				$sum = 0.0;
				foreach ( (array) ( $row['WarehouseDetails'] ?? array() ) as $detail ) {
					if ( ! is_array( $detail ) ) {
						continue;
					}
					$quantity = AcumaticaValue::get( $detail, 'QtyOnHand' );
					if ( ( array() === $wanted || in_array( strtolower( AcumaticaValue::text( $detail, 'WarehouseID' ) ), $wanted, true ) ) && is_numeric( $quantity ) ) {
						$sum += (float) $quantity;
					}
				}
				$result[ $byLow[ $low ] ] = array(
					'quantity' => (int) floor( $sum ),
					'held'     => 0,
				);
			}
		}
		return $result;
	}

	/**
	 * Shipments of orders that name a WooCommerce order, since a time.
	 *
	 * @param \DateTimeImmutable $since UTC start of the window.
	 * @return array<int,array{order_ref:string,status:string,carrier:string,tracking:string,done_at:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function tracking( \DateTimeImmutable $since ): array {
		$orders     = $this->client->query(
			'SalesOrder',
			array(
				'$filter' => "startswith(CustomerOrder,'WC-') and LastModified gt " . AcumaticaValue::dateLiteral( $since ),
				'$expand' => 'Shipments',
				'$select' => 'OrderType,OrderNbr,CustomerOrder,Shipments/ShipmentNbr',
			)
		);
		$byShipment = array();
		foreach ( $orders as $order ) {
			$ref = AcumaticaValue::text( $order, 'CustomerOrder' );
			if ( 1 !== preg_match( '/^WC-\d+$/', $ref ) ) {
				continue;
			}
			foreach ( (array) ( $order['Shipments'] ?? array() ) as $shipment ) {
				$number = is_array( $shipment ) ? AcumaticaValue::text( $shipment, 'ShipmentNbr' ) : '';
				if ( '' !== $number ) {
					$byShipment[ $number ] = $ref;
				}
			}
		}
		$out = array();
		foreach ( array_chunk( array_keys( $byShipment ), self::CHUNK ) as $chunk ) {
			$filter = implode( ' or ', array_map( static fn( string $number ): string => 'ShipmentNbr eq ' . AcumaticaValue::literal( $number ), $chunk ) );
			$rows   = $this->client->query(
				'Shipment',
				array(
					'$filter' => $filter,
					'$expand' => 'Packages',
					'$select' => 'ShipmentNbr,Status,ShipmentDate,Packages/TrackingNbr',
				)
			);
			foreach ( $rows as $row ) {
				$status = strtolower( AcumaticaValue::text( $row, 'Status' ) );
				$number = AcumaticaValue::text( $row, 'ShipmentNbr' );
				if ( ! in_array( $status, array( 'confirmed', 'completed' ), true ) || ! isset( $byShipment[ $number ] ) ) {
					continue;
				}
				$tracking = '';
				foreach ( (array) ( $row['Packages'] ?? array() ) as $package ) {
					if ( is_array( $package ) && '' !== AcumaticaValue::text( $package, 'TrackingNbr' ) ) {
						$tracking = AcumaticaValue::text( $package, 'TrackingNbr' );
						break;
					}
				}
				$out[] = array(
					'order_ref' => $byShipment[ $number ],
					'status'    => 'done',
					'carrier'   => '',
					'tracking'  => $tracking,
					'done_at'   => $this->amounts->toIsoUtc( AcumaticaValue::text( $row, 'ShipmentDate' ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * The invoice of a synced order.
	 *
	 * @param ExternalRef $ref The Sales Order (id is type/number).
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function invoice( ExternalRef $ref ): ?array {
		$order = $this->client->get(
			'SalesOrder/' . $this->key( $ref->id ),
			array(
				'$expand' => 'Shipments',
				'$select' => 'OrderType,OrderNbr,Shipments/InvoiceNbr',
			)
		);
		if ( null === $order ) {
			return null;
		}
		$number = '';
		foreach ( (array) ( $order['Shipments'] ?? array() ) as $shipment ) {
			if ( is_array( $shipment ) && '' !== AcumaticaValue::text( $shipment, 'InvoiceNbr' ) ) {
				$number = AcumaticaValue::text( $shipment, 'InvoiceNbr' );
			}
		}
		if ( '' === $number ) {
			return null;
		}
		$invoice = $this->client->get( 'SalesInvoice/Invoice/' . rawurlencode( $number ), array( '$select' => 'ReferenceNbr,Type,Status,Date' ) );
		if ( null === $invoice ) {
			return null;
		}
		return array(
			'number'           => AcumaticaValue::text( $invoice, 'ReferenceNbr' ),
			'status'           => AcumaticaValue::text( $invoice, 'Status' ),
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => $this->amounts->toIsoUtc( AcumaticaValue::text( $invoice, 'Date' ) ),
		);
	}

	/**
	 * Orders since a date for the nightly check, with their credit memo totals.
	 *
	 * @param \DateTimeImmutable $from UTC start of the window.
	 * @return array<string,ExternalRef> Keyed by WC-{id}.
	 *
	 * @throws ApiException On failure.
	 */
	public function reconcile( \DateTimeImmutable $from ): array {
		$day      = $from->setTimezone( new \DateTimeZone( 'UTC' ) )->modify( '-1 day' );
		$literal  = AcumaticaValue::dateLiteral( $day );
		$refunded = array();
		$memos    = $this->client->query(
			'SalesInvoice',
			array(
				'$filter' => "Type eq 'Credit Memo' and startswith(Description,'WC-') and Date ge " . $literal,
				'$select' => 'Type,ReferenceNbr,Status,Amount,Currency,Description',
			)
		);
		foreach ( $memos as $memo ) {
			$status = strtolower( AcumaticaValue::text( $memo, 'Status' ) );
			if ( in_array( $status, array( 'voided', 'rejected', 'hold' ), true ) ) {
				continue;
			}
			if ( 1 === preg_match( '/^WC-\d+ refund of (WC-\d+)/', AcumaticaValue::text( $memo, 'Description' ), $match ) ) {
				$currency              = strtoupper( AcumaticaValue::text( $memo, 'Currency' ) );
				$refunded[ $match[1] ] = ( $refunded[ $match[1] ] ?? 0 ) + abs( $this->amounts->toMinor( AcumaticaValue::get( $memo, 'Amount' ), $this->amounts->exponent( $currency ) ) );
			}
		}
		$out  = array();
		$rows = $this->client->query(
			'SalesOrder',
			array(
				'$filter' => "startswith(CustomerOrder,'WC-') and Date ge " . $literal,
				'$select' => 'OrderType,OrderNbr,Status,OrderTotal,CurrencyID,CustomerID,CustomerOrder,Date',
			)
		);
		foreach ( $rows as $row ) {
			$order = $this->orders->flat( $row );
			if ( 1 !== preg_match( '/^WC-\d+$/', $order['ref'] ) ) {
				continue;
			}
			$ref                  = $this->orders->reference( $order );
			$out[ $order['ref'] ] = new ExternalRef(
				$ref->id,
				$ref->ref,
				$ref->url,
				$ref->extra + array(
					'refunded_minor' => (int) ( $refunded[ $order['ref'] ] ?? 0 ),
					'email'          => '',
					'state'          => 'cancelled' === strtolower( $order['status'] ) ? 'cancelled' : '',
				)
			);
		}
		return $out;
	}

	/**
	 * A `Type/Number` id as a path.
	 *
	 * @param string $id Reference id.
	 */
	private function key( string $id ): string {
		$parts = explode( '/', $id, 2 );
		return rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] ?? '' );
	}
}
