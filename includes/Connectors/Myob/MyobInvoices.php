<?php
/**
 * Item invoices, credit invoices and customer payments in MYOB.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Myob;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\ApiException;

/**
 * Search by the customer purchase order number (the shop's reference), create, read back, delete, pay. MYOB posts an invoice when it is created
 * and answers a create with 201, the address of the new record in the Location header, and no body, so the record is read with a second call.
 */
final class MyobInvoices {

	/**
	 * Path of the item layout.
	 */
	public const PATH = 'Sale/Invoice/Item';

	/**
	 * Set up with what it depends on.
	 *
	 * @param MyobApi $api Client.
	 */
	public function __construct( private readonly MyobApi $api ) {
	}

	/**
	 * The invoice with a shop reference.
	 *
	 * @param string $reference WC-{id}.
	 * @return array<string,mixed>|null
	 *
	 * @throws ApiException On a failed call.
	 */
	public function find( string $reference ): ?array {
		foreach ( $this->api->select( self::PATH, 'CustomerPurchaseOrderNumber eq ' . MyobApi::quote( $reference ) ) as $invoice ) {
			if ( 0 === strcasecmp( MyobPayloadReader::text( $invoice['CustomerPurchaseOrderNumber'] ?? '' ), $reference ) ) {
				return $invoice;
			}
		}
		return null;
	}

	/**
	 * One invoice by UID.
	 *
	 * @param string $uid UID.
	 * @return array<string,mixed>|null Null when MYOB has no such invoice.
	 *
	 * @throws ApiException On a failed call other than 404.
	 */
	public function get( string $uid ): ?array {
		$response = $this->api->get( self::PATH . '/' . rawurlencode( $uid ), array(), array( 'allow' => array( 404 ) ) );
		if ( 404 === $response->status() ) {
			return null;
		}
		$data = $response->json();
		return isset( $data['UID'] ) ? $data : null;
	}

	/**
	 * Create an invoice and read it back.
	 *
	 * @param array<string,mixed> $body      Body.
	 * @param string              $reference WC-{id}, to find the invoice when MYOB does not say where it is.
	 * @return array<string,mixed>
	 *
	 * @throws ApiException On a failed call.
	 */
	public function create( array $body, string $reference ): array {
		$response = $this->api->post( self::PATH, $body );
		$uid      = MyobApi::uidFromLocation( $response );
		$invoice  = '' !== $uid ? $this->get( $uid ) : $this->find( $reference );
		if ( null === $invoice ) {
			$failure = MyobErrors::exception( MyobErrors::refused( 'Invoice', __( 'MYOB accepted the invoice but did not say where it is.', 'estasoft-erp-crm-sync-engine' ), $response->status() ), $response );
			throw $failure;
		}
		return $invoice;
	}

	/**
	 * Delete an invoice the connector made and found wrong.
	 *
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function delete( array $invoice ): void {
		$this->api->delete( self::PATH . '/' . rawurlencode( MyobPayloadReader::text( $invoice['UID'] ?? '' ) ) );
	}

	/**
	 * Record a customer payment.
	 *
	 * @param array<string,mixed> $payment Payment.
	 * @return string The UID of the payment.
	 *
	 * @throws ApiException On a failed call.
	 */
	public function pay( array $payment ): string {
		return MyobApi::uidFromLocation( $this->api->post( 'Sale/CustomerPayment', $payment ) );
	}

	/**
	 * Whether MYOB reports an invoice as a credit.
	 *
	 * @param array<string,mixed> $invoice Invoice.
	 */
	public static function isCredit( array $invoice ): bool {
		return 'credit' === strtolower( MyobPayloadReader::text( $invoice['Status'] ?? '' ) );
	}

	/**
	 * Whether the balance of an invoice is paid.
	 *
	 * @param array<string,mixed> $invoice Invoice.
	 */
	public static function isPaid( array $invoice ): bool {
		if ( 'closed' === strtolower( MyobPayloadReader::text( $invoice['Status'] ?? '' ) ) ) {
			return true;
		}
		return is_numeric( $invoice['BalanceDueAmount'] ?? null ) && (float) $invoice['BalanceDueAmount'] <= 0.0;
	}
}
