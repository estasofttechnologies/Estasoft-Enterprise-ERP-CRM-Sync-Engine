<?php
/**
 * Reads from S/4HANA: products and billing documents.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SapS4Hana;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Read-only. Filters are built from fixed property names and values quoted by the gateway.
 */
final class SapS4HanaReader {

	public const PRODUCTS = 'API_PRODUCT_SRV';

	public const BILLING = 'API_BILLING_DOCUMENT_SRV';

	/**
	 * Set up with what it depends on.
	 *
	 * @param SapS4HanaGateway $gateway Calls.
	 */
	public function __construct( private readonly SapS4HanaGateway $gateway ) {
	}

	/**
	 * A product by its number (material numbers are upper case in most systems, so the upper-case form is tried next).
	 *
	 * @param string $sku SKU.
	 * @return array{id:string,ref:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function product( string $sku ): ?array {
		foreach ( array_unique( array( trim( $sku ), strtoupper( trim( $sku ) ) ) ) as $candidate ) {
			$rows = $this->gateway->list(
				self::PRODUCTS,
				'A_Product',
				array(
					'filter' => 'Product eq ' . $this->gateway->literal( $candidate ),
					'select' => 'Product',
				),
				1
			);
			if ( array() !== $rows ) {
				return array(
					'id'  => (string) ( $rows[0]['Product'] ?? $candidate ),
					'ref' => (string) ( $rows[0]['Product'] ?? $candidate ),
				);
			}
		}
		return null;
	}

	/**
	 * Products whose number contains a text.
	 *
	 * @param string $query Search text.
	 * @return array<int,array{id:string,ref:string}>
	 *
	 * @throws ApiException On failure.
	 */
	public function search( string $query ): array {
		$found = array();
		foreach ( $this->gateway->list(
			self::PRODUCTS,
			'A_Product',
			array(
				'filter'  => 'substringof(' . $this->gateway->literal( strtoupper( trim( $query ) ) ) . ',Product)',
				'select'  => 'Product',
				'orderby' => 'Product',
			),
			25
		) as $row ) {
			$found[] = array(
				'id'  => (string) ( $row['Product'] ?? '' ),
				'ref' => (string) ( $row['Product'] ?? '' ),
			);
		}
		return $found;
	}

	/**
	 * Billing document facts for a sales order.
	 *
	 * @param string $salesOrder Sales order number.
	 * @return array{number:string,status:string,authority_status:string,pdf_url:string,qr:string,issued_at:string}|null
	 *
	 * @throws ApiException On failure.
	 */
	public function invoice( string $salesOrder ): ?array {
		$items = $this->gateway->list(
			self::BILLING,
			'A_BillingDocumentItem',
			array(
				'filter'  => 'ReferenceSDDocument eq ' . $this->gateway->literal( $salesOrder ),
				'select'  => 'BillingDocument',
				'orderby' => 'BillingDocument desc',
			),
			1
		);
		if ( array() === $items || '' === (string) ( $items[0]['BillingDocument'] ?? '' ) ) {
			return null;
		}
		$found = $this->gateway->one( self::BILLING, 'A_BillingDocument', (string) $items[0]['BillingDocument'], array( 'select' => 'BillingDocument,BillingDocumentDate,BillingDocumentIsCancelled' ) );
		if ( null === $found ) {
			return null;
		}
		$document = $found['data'];
		return array(
			'number'           => (string) ( $document['BillingDocument'] ?? '' ),
			'status'           => ! empty( $document['BillingDocumentIsCancelled'] ) ? 'cancelled' : 'issued',
			'authority_status' => '',
			'pdf_url'          => '',
			'qr'               => '',
			'issued_at'        => $this->gateway->readDate( $document['BillingDocumentDate'] ?? '' ),
		);
	}
}
