<?php
/**
 * Reads from Xero: tax rates, items, stock, invoice facts and the list for the nightly check.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Xero;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiClient;
use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Every list is read through a server-side filter and, where Xero pages it, through every page.
 */
final class XeroReader {

	/**
	 * Matches shown when searching products for SKU mapping.
	 */
	private const SEARCH_LIMIT = 20;

	/**
	 * Set up with what it depends on.
	 *
	 * @param ApiClient    $api      Client for the connection.
	 * @param XeroItems    $items    Items.
	 * @param XeroInvoices $invoices Documents.
	 * @param XeroAmounts  $amounts  Conversion helper.
	 * @param XeroPaging   $paging   Paging helper.
	 */
	public function __construct(
		private readonly ApiClient $api,
		private readonly XeroItems $items,
		private readonly XeroInvoices $invoices,
		private readonly XeroAmounts $amounts,
		private readonly XeroPaging $paging = new XeroPaging()
	) {
	}

	/**
	 * Active tax rates that can be used on sales.
	 *
	 * @return array<int,array{code:string,name:string,rate_percent:string}>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function taxCodes(): array {
		$data  = $this->api->get( 'TaxRates', array( 'where' => 'Status=="ACTIVE"' ) )->json();
		$codes = array();
		foreach ( (array) ( $data['TaxRates'] ?? array() ) as $rate ) {
			if ( ! is_array( $rate ) || '' === XeroPayloadReader::text( $rate['TaxType'] ?? '' ) ) {
				continue;
			}
			if ( isset( $rate['CanApplyToRevenue'] ) && false === $rate['CanApplyToRevenue'] ) {
				continue;
			}
			$codes[] = array(
				'code'         => XeroPayloadReader::text( $rate['TaxType'] ),
				'name'         => XeroPayloadReader::text( $rate['Name'] ?? $rate['TaxType'] ),
				'rate_percent' => number_format( (float) ( $rate['DisplayTaxRate'] ?? 0 ), 4, '.', '' ),
			);
		}
		return $codes;
	}

	/**
	 * One product by SKU.
	 *
	 * @param string $sku SKU.
	 * @return ExternalRef|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function product( string $sku ): ?ExternalRef {
		$found = $this->items->byCodes( array( $sku ) );
		$item  = $found[ strtolower( $sku ) ] ?? null;
		return null === $item ? null : $this->itemRef( $item );
	}

	/**
	 * Items whose code or name contains a text. Xero has no search on items, so the whole item list is read (it is not paged).
	 *
	 * @param string $query Text.
	 * @return array<int,ExternalRef>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function searchProducts( string $query ): array {
		$needle = mb_strtolower( trim( $query ) );
		$found  = array();
		foreach ( (array) ( $this->api->get( 'Items', array( 'unitdp' => 4 ) )->json()['Items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$haystack = mb_strtolower( XeroPayloadReader::text( $item['Code'] ?? '' ) . ' ' . XeroPayloadReader::text( $item['Name'] ?? '' ) );
			if ( '' === $needle || false !== mb_strpos( $haystack, $needle ) ) {
				$found[] = $this->itemRef( $item );
			}
			if ( count( $found ) >= self::SEARCH_LIMIT ) {
				break;
			}
		}
		return $found;
	}

	/**
	 * Quantities for SKUs. Only tracked inventory items have a quantity; Xero has one figure per item and no warehouses.
	 *
	 * @param array<int,string> $skus SKUs.
	 * @return array<string,array{quantity:int,held:int}> Keyed by SKU as asked.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function stock( array $skus ): array {
		$found  = $this->items->byCodes( $skus );
		$result = array();
		foreach ( $skus as $sku ) {
			$item = $found[ strtolower( $sku ) ] ?? null;
			if ( null === $item || empty( $item['IsTrackedAsInventory'] ) || ! isset( $item['QuantityOnHand'] ) || ! is_numeric( $item['QuantityOnHand'] ) ) {
				continue;
			}
			$result[ $sku ] = array(
				'quantity' => max( 0, (int) floor( (float) $item['QuantityOnHand'] ) ),
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
		$invoice = $this->invoices->invoice( $ref->id );
		if ( null === $invoice ) {
			return null;
		}
		$status = (string) ( $invoice['Status'] ?? '' );
		$link   = '';
		if ( in_array( $status, array( 'AUTHORISED', 'PAID' ), true ) ) {
			$online = $this->api->get( 'Invoices/' . rawurlencode( $ref->id ) . '/OnlineInvoice', array(), null, array( 'allow' => array( 404, 400 ) ) );
			$link   = $online->isSuccess() ? XeroPayloadReader::text( $online->json()['OnlineInvoices'][0]['OnlineInvoiceUrl'] ?? '' ) : '';
		}
		return array(
			'number'           => XeroPayloadReader::text( $invoice['InvoiceNumber'] ?? '' ),
			'status'           => strtolower( $status ),
			'authority_status' => '',
			'pdf_url'          => $link,
			'qr'               => '',
			'issued_at'        => XeroPayloadReader::text( $invoice['DateString'] ?? '' ),
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
		$where = 'Type=="ACCREC" AND Reference.StartsWith("WC-") AND Date>=DateTime(' . $from->format( 'Y, m, d' ) . ')';
		$list  = array();
		foreach ( $this->paging->all(
			$this->api,
			'Invoices',
			array(
				'where'       => $where,
				'summaryOnly' => 'false',
			),
			'Invoices'
		) as $invoice ) {
			$reference = XeroPayloadReader::text( $invoice['Reference'] ?? '' );
			$status    = (string) ( $invoice['Status'] ?? '' );
			if ( 1 !== preg_match( '/^WC-\d+$/', $reference ) || in_array( $status, array( 'DELETED', 'VOIDED' ), true ) ) {
				continue;
			}
			$currency           = strtoupper( XeroPayloadReader::text( $invoice['CurrencyCode'] ?? 'USD' ) );
			$extra              = array(
				'total_minor'    => (int) $this->amounts->fromNumber( $invoice['Total'] ?? 0, $currency ),
				'refunded_minor' => (int) $this->amounts->fromNumber( $invoice['AmountCredited'] ?? 0, $currency ),
				'email'          => XeroPayloadReader::text( $invoice['Contact']['EmailAddress'] ?? '' ),
				'status'         => $status,
			);
			$list[ $reference ] = new ExternalRef( XeroPayloadReader::text( $invoice['InvoiceID'] ?? '' ), XeroPayloadReader::text( $invoice['InvoiceNumber'] ?? '' ), null, $extra );
		}
		return $list;
	}

	/**
	 * A reference to an item for the mapping screens.
	 *
	 * @param array<string,mixed> $item Xero item.
	 */
	private function itemRef( array $item ): ExternalRef {
		return new ExternalRef(
			XeroPayloadReader::text( $item['ItemID'] ?? '' ),
			XeroPayloadReader::text( $item['Code'] ?? '' ),
			null,
			array(
				'name'    => XeroPayloadReader::text( $item['Name'] ?? '' ),
				'tracked' => ! empty( $item['IsTrackedAsInventory'] ),
			)
		);
	}
}
