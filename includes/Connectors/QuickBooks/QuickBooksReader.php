<?php
/**
 * Reads from QuickBooks: tax codes, items, stock, invoice facts and the list for the nightly check.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\QuickBooks;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Every list is read through the query language with a server-side condition and, where it can be long, every page.
 */
final class QuickBooksReader {

	/**
	 * Matches shown when searching products for SKU mapping.
	 */
	private const SEARCH_LIMIT = 20;

	/**
	 * Set up with what it depends on.
	 *
	 * @param QuickBooksApi       $api       Client.
	 * @param QuickBooksItems     $items     Items.
	 * @param QuickBooksDocuments $documents Documents.
	 * @param QuickBooksAmounts   $amounts   Conversion helper.
	 * @param QuickBooksCompany   $company   Company facts.
	 */
	public function __construct(
		private readonly QuickBooksApi $api,
		private readonly QuickBooksItems $items,
		private readonly QuickBooksDocuments $documents,
		private readonly QuickBooksAmounts $amounts,
		private readonly QuickBooksCompany $company
	) {
	}

	/**
	 * Active sales tax codes with the sum of their sales rates in percent.
	 *
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function taxCodes(): array {
		$rates = array();
		foreach ( $this->api->select( 'TaxRate' ) as $rate ) {
			$rates[ QuickBooksPayloadReader::text( $rate['Id'] ?? '' ) ] = (float) ( $rate['RateValue'] ?? 0 );
		}
		$codes = array();
		foreach ( $this->api->select( 'TaxCode' ) as $code ) {
			if ( isset( $code['Active'] ) && false === $code['Active'] ) {
				continue;
			}
			$percent = 0.0;
			foreach ( (array) ( $code['SalesTaxRateList']['TaxRateDetail'] ?? array() ) as $detail ) {
				$percent += $rates[ QuickBooksPayloadReader::text( $detail['TaxRateRef']['value'] ?? '' ) ] ?? 0.0;
			}
			$codes[] = array(
				'code'         => QuickBooksPayloadReader::text( $code['Id'] ?? '' ),
				'name'         => QuickBooksPayloadReader::text( $code['Name'] ?? ( $code['Id'] ?? '' ) ),
				'rate_percent' => number_format( $percent, 4, '.', '' ),
			);
		}
		return $codes;
	}

	/**
	 * One product by SKU.
	 *
	 * @param string $sku SKU.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function product( string $sku ): ?ExternalRef {
		$found = $this->items->bySkus( array( $sku ) );
		$item  = $found[ strtolower( $sku ) ] ?? null;
		return null === $item ? null : $this->itemRef( $item );
	}

	/**
	 * Items whose SKU or name contains a text (two queries, because the query language has no OR).
	 *
	 * @param string $query Text.
	 * @return array<int,ExternalRef>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function searchProducts( string $query ): array {
		$pattern = QuickBooksApi::quote( '%' . str_replace( array( '%', '_' ), '', trim( $query ) ) . '%' );
		$found   = array();
		foreach ( array( 'Sku', 'Name' ) as $field ) {
			foreach ( $this->api->select( 'Item', $field . ' like ' . $pattern ) as $item ) {
				if ( 'Category' !== ( $item['Type'] ?? '' ) ) {
					$found[ QuickBooksPayloadReader::text( $item['Id'] ?? '' ) ] = $this->itemRef( $item );
				}
			}
		}
		return array_slice( array_values( $found ), 0, self::SEARCH_LIMIT );
	}

	/**
	 * Quantities for SKUs: tracked inventory items only. QuickBooks has no warehouses.
	 *
	 * @param array<int,string> $skus SKUs.
	 * @return array<string,array{quantity:int,held:int}> Keyed by SKU as asked.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function stock( array $skus ): array {
		$found  = $this->items->bySkus( $skus );
		$result = array();
		foreach ( $skus as $sku ) {
			$item = $found[ strtolower( $sku ) ] ?? null;
			if ( null === $item || empty( $item['TrackQtyOnHand'] ) || ! isset( $item['QtyOnHand'] ) || ! is_numeric( $item['QtyOnHand'] ) ) {
				continue;
			}
			$result[ $sku ] = array(
				'quantity' => max( 0, (int) floor( (float) $item['QtyOnHand'] ) ),
				'held'     => 0,
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
		$invoice = $this->documents->invoice( $ref->id );
		if ( null === $invoice ) {
			return null;
		}
		$currency = strtoupper( QuickBooksPayloadReader::text( $invoice['CurrencyRef']['value'] ?? '' ) );
		$currency = '' !== $currency ? $currency : $this->company->homeCurrency();
		$total    = $this->amounts->fromNumber( $invoice['TotalAmt'] ?? 0, $currency );
		$balance  = $this->amounts->fromNumber( $invoice['Balance'] ?? 0, $currency );
		$status   = 'unpaid';
		if ( null !== $balance && null !== $total ) {
			$status = $balance <= 0 ? 'paid' : ( $balance < $total ? 'partial' : 'unpaid' );
		}
		return array(
			'number'           => QuickBooksPayloadReader::text( $invoice['DocNumber'] ?? '' ),
			'status'           => $status,
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => QuickBooksPayloadReader::text( $invoice['TxnDate'] ?? '' ),
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
		$where    = 'TxnDate >= ' . QuickBooksApi::quote( $from->format( 'Y-m-d' ) ) . ' and DocNumber like ' . QuickBooksApi::quote( 'WC-%' );
		$refunded = array();
		foreach ( $this->api->select( 'CreditMemo', $where ) as $memo ) {
			if ( 1 === preg_match( '/^Refund of (WC-\d+)/', QuickBooksPayloadReader::text( $memo['PrivateNote'] ?? '' ), $match ) ) {
				$currency              = strtoupper( QuickBooksPayloadReader::text( $memo['CurrencyRef']['value'] ?? '' ) );
				$refunded[ $match[1] ] = ( $refunded[ $match[1] ] ?? 0 ) + (int) $this->amounts->fromNumber( $memo['TotalAmt'] ?? 0, '' !== $currency ? $currency : $this->company->homeCurrency() );
			}
		}
		$list = array();
		foreach ( $this->api->select( 'Invoice', $where ) as $invoice ) {
			$number = QuickBooksPayloadReader::text( $invoice['DocNumber'] ?? '' );
			if ( 1 !== preg_match( '/^WC-\d+$/', $number ) || ( is_numeric( $invoice['TotalAmt'] ?? null ) && 0.0 === (float) $invoice['TotalAmt'] && false !== stripos( QuickBooksPayloadReader::text( $invoice['PrivateNote'] ?? '' ), 'voided' ) ) ) {
				continue;
			}
			$currency        = strtoupper( QuickBooksPayloadReader::text( $invoice['CurrencyRef']['value'] ?? '' ) );
			$currency        = '' !== $currency ? $currency : $this->company->homeCurrency();
			$extra           = array(
				'total_minor'    => (int) $this->amounts->fromNumber( $invoice['TotalAmt'] ?? 0, $currency ),
				'refunded_minor' => $refunded[ $number ] ?? 0,
				'email'          => QuickBooksPayloadReader::text( $invoice['BillEmail']['Address'] ?? '' ),
			);
			$list[ $number ] = new ExternalRef( QuickBooksPayloadReader::text( $invoice['Id'] ?? '' ), $number, null, $extra );
		}
		return $list;
	}

	/**
	 * A reference to an item for the mapping screens.
	 *
	 * @param array<string,mixed> $item QuickBooks item.
	 */
	private function itemRef( array $item ): ExternalRef {
		return new ExternalRef(
			QuickBooksPayloadReader::text( $item['Id'] ?? '' ),
			QuickBooksPayloadReader::text( $item['Sku'] ?? '' ),
			null,
			array(
				'name'    => QuickBooksPayloadReader::text( $item['Name'] ?? '' ),
				'tracked' => ! empty( $item['TrackQtyOnHand'] ),
			)
		);
	}
}
