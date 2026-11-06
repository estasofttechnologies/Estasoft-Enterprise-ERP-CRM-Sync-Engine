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
		$builder = FieldCatalogBuilder::make()->group( __( 'Document', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'document.number', __( 'Order number (the number of the voucher, invoice or posting)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->text( 'document.reference', __( 'Client reference (WC- and the order id)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->date( 'document.date', __( 'Order date, in the shop\'s time zone', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->field( 'document.currency', __( 'Currency (ISO code); it must be the currency of your books', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency' )
			->money( 'document.total', __( 'Order total with tax (minor units); the file is checked against it', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'document.excluded_total', __( 'Part of that total that belongs to products left out of the sync (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->longText( 'document.note', __( 'Customer note', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		$this->customer( $builder );
		$builder->group( __( 'Product lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'line[].sku', __( 'SKU', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'line[].name', __( 'Product name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'line[].unit_price', __( 'Unit price before discount, without tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'line[].net', __( 'Line amount after discount, without tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'line[].tax', __( 'Line tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'line[].tax_code', __( 'Tax code from the tax mapping', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'line[].tax_rate', __( 'Tax rate in percent', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Shipping', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'shipping[].name', __( 'Shipping text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping[].net', __( 'Shipping without tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping[].tax', __( 'Shipping tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'shipping[].tax_code', __( 'Shipping tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'shipping[].tax_rate', __( 'Shipping tax rate in percent', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Fees', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'fee[].name', __( 'Fee text', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'fee[].net', __( 'Fee without tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'fee[].tax', __( 'Fee tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Payment', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'payment.method', __( 'Payment method id', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'payment.title', __( 'Payment method title', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->date( 'payment.paid_at', __( 'Date paid, in the shop\'s time zone', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		return $builder->build();
	}

	/**
	 * Refund fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private function refunds(): array {
		$builder = FieldCatalogBuilder::make()->group( __( 'Credit note', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'document.number', __( 'Original order number', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->text( 'document.reference', __( 'Client reference of the original order', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->integer( 'document.refund_id', __( 'Refund id (makes the credit note number)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->date( 'document.date', __( 'Refund date, in the shop\'s time zone', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->field( 'document.currency', __( 'Currency (ISO code); it must be the currency of your books', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency' )
			->money( 'document.total', __( 'Refund total with tax (minor units); the file is checked against it', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'document.excluded_total', __( 'Part of the refund that belongs to products left out of the sync (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->longText( 'document.note', __( 'Reason', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		$this->customer( $builder );
		$builder->group( __( 'Refunded lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'refund_line[].sku', __( 'SKU', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'refund_line[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'refund_line[].net', __( 'Line amount without tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'refund_line[].tax', __( 'Line tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Other refunded amounts', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'refund_shipping.gross', __( 'Refunded shipping with tax (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'refund_other.gross', __( 'Refund amount that belongs to no product (minor units)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Original order (tax codes of the refunded lines)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'original_line[].sku', __( 'SKU', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'original_line[].tax_code', __( 'Tax code from the tax mapping', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'original_line[].tax_rate', __( 'Tax rate in percent', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'original_shipping[].tax_code', __( 'Shipping tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'original_shipping[].tax_rate', __( 'Shipping tax rate in percent', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		return $builder->build();
	}

	/**
	 * The customer group, the same for orders and refunds.
	 *
	 * @param FieldCatalogBuilder $builder Builder to add to.
	 */
	private function customer( FieldCatalogBuilder $builder ): void {
		$builder->group( __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.name', __( 'Customer name (company, or first and last name)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->text( 'customer.account', __( 'Account, ledger or customer ID in the system (overrides the default)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->integer( 'customer.id', __( 'WooCommerce customer id (0 for a guest)', 'estasoft-enterprise-erpcrm-sync-engine' ) );
	}
}
