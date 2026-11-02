<?php
/**
 * The target fields shared by the ledger-based export connectors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\ExportShared;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * TallyPrime, DATEV and Sage 50 all take the same document: a number, a date, a customer, product lines, shipping, fees and
 * (optionally) a payment, with a tax code on every line. The paths below are the paths of the payload the mapping engine builds
 * (arrays use []), and DocumentReader reads exactly these.
 */
final class LedgerFields {

	/**
	 * Fields for a mapping type. Only orders and refunds exist: an export file writes names, it creates no master data.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,FieldDef>
	 */
	public function fields( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'  => $this->orders(),
			'refunds' => $this->refunds(),
			default   => array(),
		};
	}

	/**
	 * Order fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private function orders(): array {
		$builder = FieldCatalogBuilder::make()->group( __( 'Document', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'document.number', __( 'Order number (the number of the voucher, invoice or posting)', 'estasoft-erp-crm-sync-engine' ), true )
			->text( 'document.reference', __( 'Client reference (WC- and the order id)', 'estasoft-erp-crm-sync-engine' ), true )
			->date( 'document.date', __( 'Order date, in the shop\'s time zone', 'estasoft-erp-crm-sync-engine' ), true )
			->field( 'document.currency', __( 'Currency (ISO code); it must be the currency of your books', 'estasoft-erp-crm-sync-engine' ), 'currency' )
			->money( 'document.total', __( 'Order total with tax (minor units); the file is checked against it', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'document.excluded_total', __( 'Part of that total that belongs to products left out of the sync (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->longText( 'document.note', __( 'Customer note', 'estasoft-erp-crm-sync-engine' ) );
		$this->customer( $builder );
		$builder->group( __( 'Product lines', 'estasoft-erp-crm-sync-engine' ) )
			->sku( 'line[].sku', __( 'SKU', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'line[].name', __( 'Product name', 'estasoft-erp-crm-sync-engine' ) )
			->number( 'line[].quantity', __( 'Quantity', 'estasoft-erp-crm-sync-engine' ), true )
			->money( 'line[].unit_price', __( 'Unit price before discount, without tax (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'line[].net', __( 'Line amount after discount, without tax (minor units)', 'estasoft-erp-crm-sync-engine' ), true )
			->money( 'line[].tax', __( 'Line tax (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->taxCode( 'line[].tax_code', __( 'Tax code from the tax mapping', 'estasoft-erp-crm-sync-engine' ) )
			->number( 'line[].tax_rate', __( 'Tax rate in percent', 'estasoft-erp-crm-sync-engine' ) )
			->group( __( 'Shipping', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'shipping[].name', __( 'Shipping text', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'shipping[].net', __( 'Shipping without tax (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'shipping[].tax', __( 'Shipping tax (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->taxCode( 'shipping[].tax_code', __( 'Shipping tax code', 'estasoft-erp-crm-sync-engine' ) )
			->number( 'shipping[].tax_rate', __( 'Shipping tax rate in percent', 'estasoft-erp-crm-sync-engine' ) )
			->group( __( 'Fees', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'fee[].name', __( 'Fee text', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'fee[].net', __( 'Fee without tax (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'fee[].tax', __( 'Fee tax (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->group( __( 'Payment', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'payment.method', __( 'Payment method id', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'payment.title', __( 'Payment method title', 'estasoft-erp-crm-sync-engine' ) )
			->date( 'payment.paid_at', __( 'Date paid, in the shop\'s time zone', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference', 'estasoft-erp-crm-sync-engine' ) );
		return $builder->build();
	}

	/**
	 * Refund fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private function refunds(): array {
		$builder = FieldCatalogBuilder::make()->group( __( 'Credit note', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'document.number', __( 'Original order number', 'estasoft-erp-crm-sync-engine' ), true )
			->text( 'document.reference', __( 'Client reference of the original order', 'estasoft-erp-crm-sync-engine' ), true )
			->integer( 'document.refund_id', __( 'Refund id (makes the credit note number)', 'estasoft-erp-crm-sync-engine' ), true )
			->date( 'document.date', __( 'Refund date, in the shop\'s time zone', 'estasoft-erp-crm-sync-engine' ), true )
			->field( 'document.currency', __( 'Currency (ISO code); it must be the currency of your books', 'estasoft-erp-crm-sync-engine' ), 'currency' )
			->money( 'document.total', __( 'Refund total with tax (minor units); the file is checked against it', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'document.excluded_total', __( 'Part of the refund that belongs to products left out of the sync (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->longText( 'document.note', __( 'Reason', 'estasoft-erp-crm-sync-engine' ) );
		$this->customer( $builder );
		$builder->group( __( 'Refunded lines', 'estasoft-erp-crm-sync-engine' ) )
			->sku( 'refund_line[].sku', __( 'SKU', 'estasoft-erp-crm-sync-engine' ) )
			->number( 'refund_line[].quantity', __( 'Quantity', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'refund_line[].net', __( 'Line amount without tax (minor units)', 'estasoft-erp-crm-sync-engine' ), true )
			->money( 'refund_line[].tax', __( 'Line tax (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->group( __( 'Other refunded amounts', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'refund_shipping.gross', __( 'Refunded shipping with tax (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->money( 'refund_other.gross', __( 'Refund amount that belongs to no product (minor units)', 'estasoft-erp-crm-sync-engine' ) )
			->group( __( 'Original order (tax codes of the refunded lines)', 'estasoft-erp-crm-sync-engine' ) )
			->sku( 'original_line[].sku', __( 'SKU', 'estasoft-erp-crm-sync-engine' ) )
			->taxCode( 'original_line[].tax_code', __( 'Tax code from the tax mapping', 'estasoft-erp-crm-sync-engine' ) )
			->number( 'original_line[].tax_rate', __( 'Tax rate in percent', 'estasoft-erp-crm-sync-engine' ) )
			->taxCode( 'original_shipping[].tax_code', __( 'Shipping tax code', 'estasoft-erp-crm-sync-engine' ) )
			->number( 'original_shipping[].tax_rate', __( 'Shipping tax rate in percent', 'estasoft-erp-crm-sync-engine' ) );
		return $builder->build();
	}

	/**
	 * The customer group, the same for orders and refunds.
	 *
	 * @param FieldCatalogBuilder $builder Builder to add to.
	 */
	private function customer( FieldCatalogBuilder $builder ): void {
		$builder->group( __( 'Customer', 'estasoft-erp-crm-sync-engine' ) )
			->text( 'customer.name', __( 'Customer name (company, or first and last name)', 'estasoft-erp-crm-sync-engine' ), true )
			->text( 'customer.account', __( 'Account, ledger or customer ID in the system (overrides the default)', 'estasoft-erp-crm-sync-engine' ) )
			->integer( 'customer.id', __( 'WooCommerce customer id (0 for a guest)', 'estasoft-erp-crm-sync-engine' ) );
	}
}
