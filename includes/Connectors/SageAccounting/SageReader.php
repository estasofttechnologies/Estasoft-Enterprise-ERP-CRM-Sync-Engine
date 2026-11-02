<?php
/**
 * What the Sage connector reads: tax rates, products, invoice facts and the list for the nightly check.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;
use Estasoft\SyncEngine\Contract\ExternalRef;

/**
 * Reads only. Sage documents no stock level to read, so there is no stock read here.
 */
final class SageReader {

	/**
	 * Most products a search returns.
	 */
	private const SEARCH_LIMIT = 25;

	/**
	 * Set up with what it depends on.
	 *
	 * @param SageApi      $api      Client.
	 * @param SageProducts $products Products.
	 * @param SageInvoices $invoices Invoices.
	 * @param SageBusiness $business Business facts.
	 * @param SageAmounts  $amounts  Conversion helper.
	 */
	public function __construct(
		private readonly SageApi $api,
		private readonly SageProducts $products,
		private readonly SageInvoices $invoices,
		private readonly SageBusiness $business,
		private readonly SageAmounts $amounts
	) {
	}

	/**
	 * One product by item code.
	 *
	 * @param string $sku Item code.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function product( string $sku ): ?ExternalRef {
		$product = $this->products->product( $sku );
		return null === $product ? null : $this->productRef( $product );
	}

	/**
	 * Products whose code or description contains a text.
	 *
	 * @param string $query Text.
	 * @return array<int,ExternalRef>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function searchProducts( string $query ): array {
		$refs = array();
		foreach ( $this->api->select( 'products', array( 'search' => trim( $query ) ), 1 ) as $product ) {
			if ( false !== ( $product['active'] ?? true ) ) {
				$refs[] = $this->productRef( $product );
			}
		}
		return array_slice( $refs, 0, self::SEARCH_LIMIT );
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
		$invoice = $this->invoices->get( 'sales_invoices', $ref->id );
		if ( null === $invoice ) {
			return null;
		}
		$status = array(
			'DRAFT'     => 'draft',
			'UNPAID'    => 'unpaid',
			'PART_PAID' => 'partial',
			'PAID'      => 'paid',
			'VOID'      => 'void',
		)[ SageInvoices::status( $invoice ) ] ?? 'unpaid';
		return array(
			'number'           => SagePayloadReader::text( $invoice['invoice_number'] ?? '' ),
			'status'           => $status,
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => SagePayloadReader::day( $invoice['date'] ?? '' ),
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
		$currency = $this->business->currency();
		$filter   = array( 'from_date' => $from->format( 'Y-m-d' ) );
		$refunded = array();
		foreach ( $this->api->select( 'sales_credit_notes', $filter ) as $note ) {
			if ( 'VOID' !== SageInvoices::status( $note ) && 1 === preg_match( '/^Refund of (WC-\d+)/', SagePayloadReader::text( $note['notes'] ?? '' ), $match ) ) {
				$refunded[ $match[1] ] = ( $refunded[ $match[1] ] ?? 0 ) + abs( (int) $this->amounts->fromNumber( $note['total_amount'] ?? 0, '' !== $currency ? $currency : 'GBP' ) );
			}
		}
		$list = array();
		foreach ( $this->api->select( 'sales_invoices', $filter ) as $invoice ) {
			$number = SagePayloadReader::text( $invoice['reference'] ?? '' );
			if ( 1 !== preg_match( '/^WC-\d+$/', $number ) || 'VOID' === SageInvoices::status( $invoice ) ) {
				continue;
			}
			$list[ $number ] = new ExternalRef(
				SagePayloadReader::text( $invoice['id'] ?? '' ),
				$number,
				null,
				array(
					'total_minor'    => (int) $this->amounts->fromNumber( $invoice['total_amount'] ?? 0, '' !== $currency ? $currency : 'GBP' ),
					'refunded_minor' => $refunded[ $number ] ?? 0,
					'email'          => '',
				)
			);
		}
		return $list;
	}

	/**
	 * A reference to a product for the mapping screens.
	 *
	 * @param array<string,mixed> $product Sage product.
	 */
	private function productRef( array $product ): ExternalRef {
		return new ExternalRef(
			SagePayloadReader::text( $product['id'] ?? '' ),
			SagePayloadReader::text( $product['item_code'] ?? '' ),
			null,
			array(
				'name'    => SagePayloadReader::text( $product['description'] ?? ( $product['displayed_as'] ?? '' ) ),
				'tracked' => false,
			)
		);
	}
}
