<?php
/**
 * Reads stock, delivery tracking, invoices and the nightly list back from ERPNext.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Erpnext;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\ErpCommon\Amounts;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Every read filters on the server (item codes, a modified-since time, the `WC-` prefix) and pages through the whole answer. Nothing
 * takes the first page and filters it afterwards.
 */
final class ErpnextReader {

	/**
	 * Set up with the services it uses.
	 *
	 * @param ErpnextClient      $client  Client.
	 * @param Amounts            $amounts Money and date helper.
	 * @param ErpnextLookups     $lookups Setup lookups.
	 * @param ErpnextOrderSender $orders  Order reads.
	 * @param string             $address Site address for record links.
	 */
	public function __construct( private readonly ErpnextClient $client, private readonly Amounts $amounts, private readonly ErpnextLookups $lookups, private readonly ErpnextOrderSender $orders, private readonly string $address ) {
	}

	/**
	 * Quantity on hand and reserved quantity per SKU, summed over the warehouses that count.
	 *
	 * @param array<int,string> $skus       SKUs.
	 * @param array<int,string> $warehouses Warehouse names; empty means all.
	 * @return array<string,array{quantity:int,held:int}> Keyed by the requested SKU. Items that are not stock items are left out.
	 *
	 * @throws ApiException On failure.
	 */
	public function stock( array $skus, array $warehouses ): array {
		$result = array();
		$items  = $this->lookups->items( $skus );
		$byLow  = array();
		foreach ( $skus as $sku ) {
			$byLow[ strtolower( $sku ) ] = $sku;
		}
		$codes = array();
		foreach ( $items as $low => $item ) {
			if ( 1 === (int) ( $item['is_stock_item'] ?? 1 ) && isset( $byLow[ $low ] ) ) {
				$codes[]                  = (string) $item['item_code'];
				$result[ $byLow[ $low ] ] = array(
					'quantity' => 0,
					'held'     => 0,
				);
			}
		}
		$sums = array();
		foreach ( array_chunk( $codes, 40 ) as $chunk ) {
			$filters = array( array( 'item_code', 'in', $chunk ) );
			if ( array() !== $warehouses ) {
				$filters[] = array( 'warehouse', 'in', array_values( $warehouses ) );
			}
			foreach ( $this->client->complete( 'Bin', $filters, array( 'item_code', 'warehouse', 'actual_qty', 'reserved_qty' ), 'item_code asc' ) as $row ) {
				$low                      = strtolower( (string) $row['item_code'] );
				$sums[ $low ]['quantity'] = ( $sums[ $low ]['quantity'] ?? 0.0 ) + (float) ( $row['actual_qty'] ?? 0 );
				$sums[ $low ]['held']     = ( $sums[ $low ]['held'] ?? 0.0 ) + (float) ( $row['reserved_qty'] ?? 0 );
			}
		}
		foreach ( $sums as $low => $sum ) {
			if ( isset( $byLow[ $low ], $result[ $byLow[ $low ] ] ) ) {
				$result[ $byLow[ $low ] ] = array(
					'quantity' => (int) floor( $sum['quantity'] ),
					'held'     => (int) ceil( max( 0.0, $sum['held'] ) ),
				);
			}
		}
		return $result;
	}

	/**
	 * Submitted Delivery Notes that name a WooCommerce order, modified since a time.
	 *
	 * @param \DateTimeImmutable $since UTC start of the window; widened by one day because Frappe stores times in the site's time zone.
	 * @return array<int,array{order_ref:string,status:string,carrier:string,tracking:string,done_at:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function tracking( \DateTimeImmutable $since ): array {
		$from = $since->setTimezone( new \DateTimeZone( 'UTC' ) )->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		$rows = $this->client->complete(
			'Delivery Note',
			array( array( 'docstatus', '=', 1 ), array( 'is_return', '=', 0 ), array( 'modified', '>=', $from ), array( 'po_no', 'like', '%WC-%' ) ),
			array( 'name', 'po_no', 'lr_no', 'transporter_name', 'lr_date', 'posting_date', 'modified' ),
			'modified asc'
		);
		$out  = array();
		foreach ( $rows as $row ) {
			if ( 0 === preg_match_all( '/(?<![A-Za-z0-9])WC-(\d+)(?![A-Za-z0-9])/', (string) ( $row['po_no'] ?? '' ), $matches ) ) {
				continue;
			}
			$when = $this->amounts->toIsoUtc( (string) ( $row['posting_date'] ?? '' ) );
			foreach ( array_unique( $matches[1] ) as $id ) {
				$out[] = array(
					'order_ref' => 'WC-' . $id,
					'status'    => 'done',
					'carrier'   => trim( (string) ( $row['transporter_name'] ?? '' ) ),
					'tracking'  => trim( (string) ( $row['lr_no'] ?? '' ) ),
					'done_at'   => $when,
				);
			}
		}
		return $out;
	}

	/**
	 * The invoice of a synced order.
	 *
	 * @param ExternalRef $ref The Sales Order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function invoice( ExternalRef $ref ): ?array {
		$invoice = $this->orders->findInvoice( $ref->id );
		if ( null === $invoice ) {
			return null;
		}
		return array(
			'number'           => (string) $invoice['name'],
			'status'           => (string) ( $invoice['status'] ?? '' ),
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => $this->amounts->toIsoUtc( (string) ( $invoice['posting_date'] ?? '' ) ),
		);
	}

	/**
	 * Orders since a date for the nightly check, with their refunded amounts.
	 *
	 * @param \DateTimeImmutable $from UTC start of the window.
	 * @return array<string,ExternalRef> Keyed by WC-{id}.
	 *
	 * @throws ApiException On failure.
	 */
	public function reconcile( \DateTimeImmutable $from ): array {
		$date     = $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d' );
		$orders   = $this->client->complete(
			'Sales Order',
			array( array( 'po_no', 'like', 'WC-%' ), array( 'transaction_date', '>=', $date ), array( 'docstatus', '=', 1 ) ),
			array( 'name', 'po_no', 'grand_total', 'currency', 'contact_email', 'transaction_date' ),
			'transaction_date asc'
		);
		$refunded = array();
		$notes    = $this->client->complete(
			'Sales Invoice',
			array( array( 'is_return', '=', 1 ), array( 'docstatus', '=', 1 ), array( 'po_no', 'like', 'WC-%' ), array( 'posting_date', '>=', $date ) ),
			array( 'name', 'grand_total', 'currency', 'remarks' ),
			'posting_date asc'
		);
		foreach ( $notes as $note ) {
			if ( 1 === preg_match( '/^Refund of (WC-\d+)/', (string) ( $note['remarks'] ?? '' ), $match ) ) {
				$currency              = strtoupper( (string) ( $note['currency'] ?? '' ) );
				$refunded[ $match[1] ] = ( $refunded[ $match[1] ] ?? 0 ) + abs( $this->amounts->toMinor( (float) ( $note['grand_total'] ?? 0 ), $this->amounts->exponent( $currency ) ) );
			}
		}
		$out = array();
		foreach ( $orders as $order ) {
			$key = (string) ( $order['po_no'] ?? '' );
			if ( 1 !== preg_match( '/^WC-\d+$/', $key ) ) {
				continue;
			}
			$currency    = strtoupper( (string) ( $order['currency'] ?? '' ) );
			$name        = (string) $order['name'];
			$out[ $key ] = new ExternalRef(
				$name,
				$name,
				rtrim( $this->address, '/' ) . '/app/sales-order/' . rawurlencode( $name ),
				array(
					'total_minor'    => $this->amounts->toMinor( (float) ( $order['grand_total'] ?? 0 ), $this->amounts->exponent( $currency ) ),
					'refunded_minor' => (int) ( $refunded[ $key ] ?? 0 ),
					'email'          => strtolower( trim( (string) ( $order['contact_email'] ?? '' ) ) ),
					'currency'       => $currency,
				)
			);
		}
		return $out;
	}
}
