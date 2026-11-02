<?php
/**
 * The three kinds of sales document the connector writes.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\BusinessCentral;

defined( 'ABSPATH' ) || exit;

/**
 * Names of the API collections and fields of a sales order, a sales invoice and a sales credit memo.
 */
enum BcDocType: string {
	case Order      = 'order';
	case Invoice    = 'invoice';
	case CreditMemo = 'credit';

	/**
	 * Collection of the documents.
	 */
	public function collection(): string {
		return match ( $this ) {
			self::Order      => 'salesOrders',
			self::Invoice    => 'salesInvoices',
			self::CreditMemo => 'salesCreditMemos',
		};
	}

	/**
	 * Collection of the lines, under a document.
	 */
	public function lines(): string {
		return match ( $this ) {
			self::Order      => 'salesOrderLines',
			self::Invoice    => 'salesInvoiceLines',
			self::CreditMemo => 'salesCreditMemoLines',
		};
	}

	/**
	 * The date field of the document.
	 */
	public function dateField(): string {
		return match ( $this ) {
			self::Order      => 'orderDate',
			self::Invoice    => 'invoiceDate',
			self::CreditMemo => 'creditMemoDate',
		};
	}

	/**
	 * Whether a status text means the document is posted (an order is never posted).
	 *
	 * @param string $status Status text from the API.
	 */
	public function isPosted( string $status ): bool {
		return self::Order !== $this && in_array( $status, array( 'Open', 'Paid', 'Canceled', 'Corrective' ), true );
	}
}
