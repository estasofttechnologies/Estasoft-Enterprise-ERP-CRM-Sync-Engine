<?php
/**
 * What the MYOB connector reads: tax codes, items, stock, invoice facts and the list for the nightly check.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Reads only. A text typed by a person goes into a $filter through MyobApi::quote(), which doubles every quote.
 */
final class MyobReader {

	/**
	 * Most products a search returns.
	 */
	private const SEARCH_LIMIT = 25;

	/**
	 * Set up with what it depends on.
	 *
	 * @param MyobApi      $api      Client.
	 * @param MyobLookups  $lookups  Tax codes, accounts and items.
	 * @param MyobInvoices $invoices Invoices.
	 * @param MyobAmounts  $amounts  Conversion helper.
	 * @param MyobCompany  $company  Company file facts.
	 */
	public function __construct(
		private readonly MyobApi $api,
		private readonly MyobLookups $lookups,
		private readonly MyobInvoices $invoices,
		private readonly MyobAmounts $amounts,
		private readonly MyobCompany $company
	) {
	}

	/**
	 * One product by item number.
	 *
	 * @param string $sku Item number.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function product( string $sku ): ?ExternalRef {
		$item = $this->lookups->item( $sku );
		return null === $item ? null : $this->itemRef( $item );
	}

	/**
	 * Items whose number or name contains a text.
	 *
	 * @param string $query Text.
	 * @return array<int,ExternalRef>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function searchProducts( string $query ): array {
		$text = MyobApi::quote( trim( $query ) );
		$rows = $this->api->select( 'Inventory/Item', 'substringof(' . $text . ',Number) or substringof(' . $text . ',Name)', 1 );
		$refs = array();
		foreach ( $rows as $item ) {
			if ( false !== ( $item['IsActive'] ?? true ) ) {
				$refs[] = $this->itemRef( $item );
			}
		}
		return array_slice( $refs, 0, self::SEARCH_LIMIT );
	}

	/**
	 * Quantities for item numbers: inventoried items only. With location identifiers the quantities of those locations are added up.
	 *
	 * @param array<int,string> $skus      Item numbers.
	 * @param array<int,string> $locations Location identifiers (empty for the item's total).
	 * @return array<string,array{quantity:int,held:int}> Keyed by the number as asked.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function stock( array $skus, array $locations ): array {
		$found  = $this->lookups->items( $skus );
		$result = array();
		foreach ( $skus as $sku ) {
			$item = $found[ strtolower( $sku ) ] ?? null;
			if ( null === $item || empty( $item['IsInventoried'] ) ) {
				continue;
			}
			$on   = null;
			$held = 0.0;
			if ( array() === $locations ) {
				$on   = is_numeric( $item['QuantityOnHand'] ?? null ) ? (float) $item['QuantityOnHand'] : null;
				$held = is_numeric( $item['QuantityCommitted'] ?? null ) ? (float) $item['QuantityCommitted'] : 0.0;
			} else {
				foreach ( MyobPayloadReader::rows( $item['LocationDetails'] ?? null ) as $detail ) {
					$id   = strtolower( MyobPayloadReader::text( $detail['Location']['Identifier'] ?? '' ) );
					$name = strtolower( MyobPayloadReader::text( $detail['Location']['Name'] ?? '' ) );
					foreach ( $locations as $wanted ) {
						if ( strtolower( $wanted ) === $id || strtolower( $wanted ) === $name ) {
							$on    = ( $on ?? 0.0 ) + (float) ( $detail['QuantityOnHand'] ?? 0 );
							$held += (float) ( $detail['QuantityCommitted'] ?? 0 );
						}
					}
				}
			}
			if ( null === $on ) {
				continue;
			}
			$result[ $sku ] = array(
				'quantity' => max( 0, (int) floor( $on ) ),
				'held'     => max( 0, (int) ceil( $held ) ),
			);
		}
		return $result;
	}

	/**
	 * Facts about the invoice of a synced order.
	 *
	 * @param ExternalRef $ref The order's invoice.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function invoiceFacts( ExternalRef $ref ): ?array {
		$invoice = $this->invoices->get( $ref->id );
		if ( null === $invoice ) {
			return null;
		}
		$currency = $this->company->currency();
		$total    = $this->amounts->fromNumber( $invoice['TotalAmount'] ?? 0, $currency );
		$balance  = $this->amounts->fromNumber( $invoice['BalanceDueAmount'] ?? 0, $currency );
		$status   = 'unpaid';
		if ( null !== $balance && null !== $total ) {
			$status = $balance <= 0 ? 'paid' : ( $balance < $total ? 'partial' : 'unpaid' );
		}
		return array(
			'number'           => MyobPayloadReader::text( $invoice['Number'] ?? '' ),
			'status'           => $status,
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => MyobPayloadReader::day( $invoice['Date'] ?? '' ),
		);
	}

	/**
	 * Invoices made by the plugin from a day on, for the nightly check.
	 *
	 * @param \DateTimeImmutable $from Window start (UTC).
	 * @return array<string,ExternalRef> Keyed by WC-{order id}.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function reconcile( \DateTimeImmutable $from ): array {
		$currency = $this->company->currency();
		$filter   = "startswith(CustomerPurchaseOrderNumber,'WC-') and Date ge datetime'" . $from->format( 'Y-m-d' ) . "'";
		$refunded = array();
		$orders   = array();
		foreach ( $this->api->select( MyobInvoices::PATH, $filter ) as $invoice ) {
			if ( MyobInvoices::isCredit( $invoice ) ) {
				if ( 1 === preg_match( '/^Refund of (WC-\d+)/', MyobPayloadReader::text( $invoice['JournalMemo'] ?? '' ), $match ) ) {
					$refunded[ $match[1] ] = ( $refunded[ $match[1] ] ?? 0 ) + abs( (int) $this->amounts->fromNumber( $invoice['TotalAmount'] ?? 0, $currency ) );
				}
				continue;
			}
			$orders[] = $invoice;
		}
		$list = array();
		foreach ( $orders as $invoice ) {
			$number = MyobPayloadReader::text( $invoice['CustomerPurchaseOrderNumber'] ?? '' );
			if ( 1 !== preg_match( '/^WC-\d+$/', $number ) ) {
				continue;
			}
			$list[ $number ] = new ExternalRef(
				MyobPayloadReader::text( $invoice['UID'] ?? '' ),
				$number,
				null,
				array(
					'total_minor'    => (int) $this->amounts->fromNumber( $invoice['TotalAmount'] ?? 0, $currency ),
					'refunded_minor' => $refunded[ $number ] ?? 0,
					'email'          => '',
				)
			);
		}
		return $list;
	}

	/**
	 * A reference to an item for the mapping screens.
	 *
	 * @param array<string,mixed> $item MYOB item.
	 */
	private function itemRef( array $item ): ExternalRef {
		return new ExternalRef(
			MyobPayloadReader::text( $item['UID'] ?? '' ),
			MyobPayloadReader::text( $item['Number'] ?? '' ),
			null,
			array(
				'name'    => MyobPayloadReader::text( $item['Name'] ?? '' ),
				'tracked' => ! empty( $item['IsInventoried'] ),
			)
		);
	}
}
