<?php
/**
 * Reads from SAP Business One: stock, invoice status, the nightly check, tax codes, warehouses and items.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapBusinessOne;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\EnterpriseCommon\Amounts;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Read-only. Filters are built from fixed property names and values quoted by the gateway.
 */
final class SapBusinessOneReader {

	/**
	 * Items per stock request.
	 */
	private const CHUNK = 20;

	/**
	 * Set up with what it depends on.
	 *
	 * @param SapBusinessOneGateway $gateway Calls.
	 * @param SapBusinessOneOrders  $orders  Orders.
	 * @param Amounts               $amounts Conversion helper.
	 */
	public function __construct(
		private readonly SapBusinessOneGateway $gateway,
		private readonly SapBusinessOneOrders $orders,
		private readonly Amounts $amounts
	) {
	}

	/**
	 * An item by its code.
	 *
	 * @param string $code Item code.
	 * @return array{id:string,ref:string,name:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function item( string $code ): ?array {
		$rows = $this->gateway->list(
			'Items',
			array(
				'filter' => 'ItemCode eq ' . $this->gateway->literal( $code ) . " and Frozen eq 'tNO'",
				'select' => 'ItemCode,ItemName',
			),
			1
		);
		return array() === $rows ? null : $this->itemSummary( $rows[0] );
	}

	/**
	 * Items whose code or name contains a text.
	 *
	 * @param string $query Search text.
	 * @return array<int,array{id:string,ref:string,name:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function searchItems( string $query ): array {
		$text = $this->gateway->literal( $query );
		$rows = $this->gateway->list(
			'Items',
			array(
				'filter'  => "(contains(ItemCode,{$text}) or contains(ItemName,{$text})) and Frozen eq 'tNO'",
				'select'  => 'ItemCode,ItemName',
				'orderby' => 'ItemCode',
			),
			25
		);
		return array_map( fn( array $row ): array => $this->itemSummary( $row ), $rows );
	}

	/**
	 * Quantities on hand and committed per SKU, summed over the chosen warehouses.
	 *
	 * @param array<int,string> $skus       Item codes.
	 * @param array<int,string> $warehouses Warehouse codes that count; empty means all.
	 * @return array<string,array{quantity:int,held:int}> Keyed by the SKU as requested; unknown items are left out.
	 *
	 * @throws ApiException On failure.
	 */
	public function stock( array $skus, array $warehouses ): array {
		$wanted = array();
		foreach ( $skus as $sku ) {
			$wanted[ strtolower( trim( $sku ) ) ] = $sku;
		}
		$result = array();
		foreach ( array_chunk( array_keys( $wanted ), self::CHUNK ) as $chunk ) {
			$parts = array_map( fn( string $code ): string => 'ItemCode eq ' . $this->gateway->literal( $wanted[ $code ] ), $chunk );
			$rows  = $this->gateway->list(
				'Items',
				array(
					'filter' => implode( ' or ', $parts ),
					'select' => 'ItemCode,ItemWarehouseInfoCollection',
				),
				self::CHUNK * 2
			);
			foreach ( $rows as $row ) {
				$key = strtolower( trim( (string) ( $row['ItemCode'] ?? '' ) ) );
				if ( ! isset( $wanted[ $key ] ) ) {
					continue;
				}
				$onHand = 0.0;
				$held   = 0.0;
				foreach ( (array) ( $row['ItemWarehouseInfoCollection'] ?? array() ) as $info ) {
					if ( ! is_array( $info ) || ( array() !== $warehouses && ! in_array( (string) ( $info['WarehouseCode'] ?? '' ), $warehouses, true ) ) ) {
						continue;
					}
					$onHand += (float) ( $info['InStock'] ?? 0 );
					$held   += (float) ( $info['Committed'] ?? 0 );
				}
				$result[ $wanted[ $key ] ] = array(
					'quantity' => (int) round( $onHand ),
					'held'     => (int) round( $held ),
				);
			}
		}
		return $result;
	}

	/**
	 * Invoice facts for an order.
	 *
	 * @param string $docEntry DocEntry of the order.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function invoice( string $docEntry ): ?array {
		$order = $this->gateway->one( 'Orders', $docEntry, array( 'select' => 'DocEntry,NumAtCard' ) );
		if ( null === $order || '' === (string) ( $order['NumAtCard'] ?? '' ) ) {
			return null;
		}
		$invoice = $this->orders->invoiceOf( (string) $order['NumAtCard'] );
		if ( null === $invoice ) {
			return null;
		}
		$currency = $this->orders->currencyOf( $invoice );
		$total    = $this->orders->totalOf( $invoice, $currency );
		$paid     = $this->amounts->fromWire( $invoice['PaidToDate'] ?? null, $currency );
		return array(
			'number'           => (string) ( $invoice['DocNum'] ?? '' ),
			'status'           => null !== $total && null !== $paid && $total > 0 && $paid >= $total ? 'paid' : 'open',
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => substr( (string) ( $invoice['DocDate'] ?? '' ), 0, 10 ),
		);
	}

	/**
	 * The shop's orders since a date, with totals, refunds and the buyer's email.
	 *
	 * @param \DateTimeImmutable $from UTC window start.
	 * @return array<string,ExternalRef> Keyed by WC-{id}.
	 *
	 * @throws ApiException On failure.
	 */
	public function orders( \DateTimeImmutable $from ): array {
		$day    = $this->gateway->dateLiteral( $from->setTimezone( new \DateTimeZone( 'UTC' ) )->modify( '-1 day' )->format( 'Y-m-d' ) );
		$filter = "startswith(NumAtCard,'WC-') and DocDate ge {$day} and Cancelled eq 'tNO'";
		$rows   = $this->gateway->list(
			'Orders',
			array(
				'filter'  => $filter,
				'select'  => SapBusinessOneOrders::FIELDS,
				'orderby' => 'DocEntry',
			),
			10000
		);
		$emails = $this->emails( array_values( array_unique( array_map( static fn( array $row ): string => (string) ( $row['CardCode'] ?? '' ), $rows ) ) ) );
		$refund = $this->refunded( $filter );
		$found  = array();
		foreach ( $rows as $row ) {
			$reference = (string) ( $row['NumAtCard'] ?? '' );
			$currency  = $this->orders->currencyOf( $row );
			if ( '' === $reference || '' === $currency ) {
				continue;
			}
			$found[ $reference ] = $this->orders->reference(
				$row,
				$currency,
				array(
					'refunded_minor' => $refund[ $reference ] ?? 0,
					'email'          => $emails[ (string) ( $row['CardCode'] ?? '' ) ] ?? '',
				)
			);
		}
		return $found;
	}

	/**
	 * Output tax codes (VAT groups) for the tax mapping screen.
	 *
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function taxCodes(): array {
		$codes = array();
		foreach ( $this->gateway->list(
			'VatGroups',
			array(
				'filter'  => "Category eq 'bovcOutputTax' and Inactive eq 'tNO'",
				'select'  => 'Code,Name,Rate',
				'orderby' => 'Code',
			),
			500
		) as $row ) {
			$codes[] = array(
				'code'         => (string) ( $row['Code'] ?? '' ),
				'name'         => (string) ( $row['Name'] ?? ( $row['Code'] ?? '' ) ),
				'rate_percent' => $this->percent( $row['Rate'] ?? 0 ),
			);
		}
		return $codes;
	}

	/**
	 * Active warehouses.
	 *
	 * @return array<int,array{id:string,name:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function warehouses(): array {
		$list = array();
		foreach ( $this->gateway->list(
			'Warehouses',
			array(
				'filter'  => "Inactive eq 'tNO'",
				'select'  => 'WarehouseCode,WarehouseName',
				'orderby' => 'WarehouseCode',
			),
			200
		) as $row ) {
			$list[] = array(
				'id'   => (string) ( $row['WarehouseCode'] ?? '' ),
				'name' => (string) ( $row['WarehouseName'] ?? ( $row['WarehouseCode'] ?? '' ) ),
			);
		}
		return $list;
	}

	/**
	 * Emails of business partners by card code.
	 *
	 * @param array<int,string> $codes Card codes.
	 * @return array<string,string>
	 *
	 * @throws ApiException On failure.
	 */
	private function emails( array $codes ): array {
		$emails = array();
		foreach ( array_chunk( array_filter( $codes ), self::CHUNK ) as $chunk ) {
			$parts = array_map( fn( string $code ): string => 'CardCode eq ' . $this->gateway->literal( $code ), $chunk );
			foreach ( $this->gateway->list(
				'BusinessPartners',
				array(
					'filter' => implode( ' or ', $parts ),
					'select' => 'CardCode,EmailAddress',
				),
				self::CHUNK * 2
			) as $row ) {
				$emails[ (string) ( $row['CardCode'] ?? '' ) ] = (string) ( $row['EmailAddress'] ?? '' );
			}
		}
		return $emails;
	}

	/**
	 * Credit note totals per order reference (the remarks of a credit note name its order).
	 *
	 * @param string $filter Filter of the order list (same window).
	 * @return array<string,int> Minor units by order reference.
	 *
	 * @throws ApiException On failure.
	 */
	private function refunded( string $filter ): array {
		$rows = $this->gateway->list(
			'CreditNotes',
			array(
				'filter' => $filter,
				'select' => 'DocTotal,DocTotalFc,DocCurrency,Comments,NumAtCard',
			),
			10000
		);
		$sums = array();
		foreach ( $rows as $row ) {
			if ( 1 !== preg_match( '/of order (WC-[A-Za-z0-9_-]+)/', (string) ( $row['Comments'] ?? '' ), $match ) ) {
				continue;
			}
			$currency = strtoupper( (string) ( $row['DocCurrency'] ?? '' ) );
			if ( '' === $currency ) {
				continue;
			}
			$sums[ $match[1] ] = ( $sums[ $match[1] ] ?? 0 ) + abs( (int) $this->orders->totalOf( $row, $currency ) );
		}
		return $sums;
	}

	/**
	 * A rate as percent text.
	 *
	 * @param mixed $rate Rate from SAP.
	 */
	private function percent( mixed $rate ): string {
		return is_numeric( $rate ) ? rtrim( rtrim( sprintf( '%.4F', (float) $rate ), '0' ), '.' ) : '0';
	}

	/**
	 * An item row as a small array.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array{id:string,ref:string,name:string}
	 */
	private function itemSummary( array $row ): array {
		return array(
			'id'   => (string) ( $row['ItemCode'] ?? '' ),
			'ref'  => (string) ( $row['ItemCode'] ?? '' ),
			'name' => (string) ( $row['ItemName'] ?? '' ),
		);
	}
}
