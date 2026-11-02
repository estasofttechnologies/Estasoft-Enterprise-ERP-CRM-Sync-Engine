<?php
/**
 * Reads from Exact Online: stock, document facts and the nightly check.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Exact;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\CrmCommon\Amounts;
use Estasoft\SyncEngine\Connectors\CrmCommon\Text;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Read-only. Every list is paged to the end and filtered by Exact, never cut at the first page.
 */
final class ExactReads {

	/**
	 * Set up with what it depends on.
	 *
	 * @param ExactApi       $api       API of the division.
	 * @param ExactDocuments $documents Document helper (resource names and references).
	 */
	public function __construct( private readonly ExactApi $api, private readonly ExactDocuments $documents ) {
	}

	/**
	 * On-hand and held quantities of items, summed over the chosen warehouses.
	 *
	 * @param array<int,string> $skus       Item codes.
	 * @param array<int,string> $warehouses Warehouse codes that count; empty means all.
	 * @return array<string,array{quantity:int,held:int}>
	 */
	public function stock( array $skus, array $warehouses ): array {
		$wanted = array();
		foreach ( $warehouses as $code ) {
			$wanted[ Text::lower( trim( (string) $code ) ) ] = true;
		}
		$out = array();
		foreach ( array_values( array_unique( array_map( 'strval', $skus ) ) ) as $sku ) {
			$rows     = $this->api->rows(
				'inventory/ItemWarehouses',
				array(
					'$filter' => 'ItemCode eq ' . ExactApi::str( trim( $sku ) ),
					'$select' => 'ItemCode,WarehouseCode,CurrentStock,PlannedStockOut,ItemIsStockItem',
				)
			);
			$quantity = 0.0;
			$held     = 0.0;
			$seen     = false;
			foreach ( $rows as $row ) {
				if ( false === ( $row['ItemIsStockItem'] ?? true ) || ! Text::same( (string) ( $row['ItemCode'] ?? '' ), $sku ) ) {
					continue;
				}
				if ( array() !== $wanted && ! isset( $wanted[ Text::lower( trim( (string) ( $row['WarehouseCode'] ?? '' ) ) ) ] ) ) {
					continue;
				}
				$quantity += (float) ( $row['CurrentStock'] ?? 0 );
				$held     += (float) ( $row['PlannedStockOut'] ?? 0 );
				$seen      = true;
			}
			if ( $seen ) {
				$out[ $sku ] = array(
					'quantity' => (int) floor( $quantity ),
					'held'     => max( 0, (int) ceil( $held ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * Facts of the document a synced order became.
	 *
	 * @param ExternalRef $ref The order's reference.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|null
	 */
	public function invoice( ExternalRef $ref ): ?array {
		$orders = 'order' === ( $ref->extra['document'] ?? '' ) || $this->documents->usesOrders();
		$row    = $this->api->byKey(
			$orders ? 'salesorder/SalesOrders' : 'salesinvoice/SalesInvoices',
			$orders ? 'OrderID' : 'InvoiceID',
			$ref->id,
			$this->documents->select( $orders ) . ',StatusDescription'
		);
		if ( null === $row ) {
			return null;
		}
		$status = (int) ( $row['Status'] ?? 0 );
		$names  = $orders
			? array(
				12 => 'open',
				20 => 'partial',
				21 => 'complete',
				45 => 'cancelled',
			)
			: array(
				10 => 'draft',
				20 => 'open',
				50 => 'processed',
			);
		return array(
			'number'           => (string) ( $row['InvoiceNumber'] ?? $row['OrderNumber'] ?? '' ),
			'status'           => $names[ $status ] ?? strtolower( (string) ( $row['StatusDescription'] ?? '' ) ),
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => ExactApi::date( $row['InvoiceDate'] ?? $row['OrderDate'] ?? '' ),
		);
	}

	/**
	 * Documents with a WooCommerce reference dated from a day on, with their totals and refunded amounts.
	 *
	 * @param \DateTimeImmutable $from UTC window start.
	 * @return array<string,ExternalRef> Keyed by WC-{id}.
	 */
	public function reconcile( \DateTimeImmutable $from ): array {
		$orders = $this->documents->usesOrders();
		$date   = $orders ? 'OrderDate' : 'InvoiceDate';
		$stamp  = $from->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\T00:00:00' );
		$filter = "startswith(YourRef,'WC-') eq true and " . $date . " ge datetime'" . $stamp . "'";
		$found  = array();
		foreach ( $this->api->rows(
			$orders ? 'salesorder/SalesOrders' : 'salesinvoice/SalesInvoices',
			array(
				'$filter' => $filter,
				'$select' => $this->documents->select( $orders ),
			)
		) as $row ) {
			if ( 8021 !== (int) ( $row['Type'] ?? 0 ) ) {
				$found[ trim( (string) ( $row['YourRef'] ?? '' ) ) ] = $row;
			}
		}
		$refunded = array();
		$credits  = $this->api->rows(
			'salesinvoice/SalesInvoices',
			array(
				'$filter' => "startswith(YourRef,'WC-') eq true and Type eq 8021 and InvoiceDate ge datetime'" . $stamp . "'",
				'$select' => $this->documents->select( false ),
			)
		);
		foreach ( $credits as $credit ) {
			if ( 1 === preg_match( '/ of (WC-\d+)/', (string) ( $credit['Description'] ?? '' ), $parts ) ) {
				$currency              = (string) ( $credit['Currency'] ?? '' );
				$refunded[ $parts[1] ] = ( $refunded[ $parts[1] ] ?? 0 ) + abs( Amounts::minor( $credit['AmountFC'] ?? null, $currency ) ?? 0 );
			}
		}
		$out = array();
		foreach ( $found as $reference => $row ) {
			if ( '' === $reference ) {
				continue;
			}
			$ref               = $this->documents->ref( $row );
			$out[ $reference ] = new ExternalRef(
				$ref->id,
				$ref->ref,
				null,
				array(
					'total_minor'    => abs( (int) ( $ref->extra['total_minor'] ?? 0 ) ),
					'refunded_minor' => $refunded[ $reference ] ?? 0,
					'currency'       => (string) ( $ref->extra['currency'] ?? '' ),
					'email'          => '',
				)
			);
		}
		return $out;
	}
}
