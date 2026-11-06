<?php
/**
 * The payload paths and starting mapping rules the four accounting connectors share.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EuAccountingCommon;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Connectors\Support\FieldCatalogBuilder;
use Estasoft\SyncEngine\Connectors\Support\FieldDef;

/**
 * Lexware Office, sevDesk, Moneybird and Pennylane take the same mapped payload: customer fields, product lines with a total after
 * discount, shipping and fee rows, totals for the check, and payment facts. This class lists those target paths (describeFields) and the
 * starting rules (defaultMapping). Each rule follows the rule model of docs/ARCHITECTURE.md.
 */
final class PayloadSchema {

	/**
	 * Target fields for a mapping type.
	 *
	 * @param string            $mappingType orders, customers, products, refunds, stock or tracking.
	 * @param array<int,string> $texts       Free-text order fields the vendor has (header, title, introduction, remark, head_text, foot_text).
	 * @param bool              $rates       True when the vendor also needs the tax rate in percent per line (sevDesk).
	 * @return array<int,FieldDef>
	 */
	public static function fields( string $mappingType, array $texts = array(), bool $rates = false ): array {
		return match ( $mappingType ) {
			'orders'    => self::orderFields( $texts, $rates ),
			'customers' => self::customerFields( FieldCatalogBuilder::make() )->build(),
			'products'  => FieldCatalogBuilder::make()->group( __( 'Product', 'estasoft-enterprise-erpcrm-sync-engine' ) )->sku( 'product.sku', __( 'SKU (the product number in the system)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )->build(),
			'refunds'   => self::refundFields(),
			default     => array(),
		};
	}

	/**
	 * Starting rules for a mapping type.
	 *
	 * @param string            $mappingType orders, customers, products, refunds, stock or tracking.
	 * @param array<int,string> $texts       Free-text order fields the vendor has; none gets a default rule.
	 * @param bool              $rates       True when the vendor also needs the tax rate in percent per line.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rules( string $mappingType, array $texts = array(), bool $rates = false ): array {
		unset( $texts );
		return match ( $mappingType ) {
			'orders'    => self::orderRules( $rates ),
			'customers' => self::customerRules(),
			'products'  => array( self::rule( 'product-sku', 'product.sku', 'lines[].sku', array( self::transform( 'trim' ) ), true, 'SYNC-MAP-001' ) ),
			'refunds'   => self::refundRules(),
			default     => array(),
		};
	}

	/**
	 * Order target fields.
	 *
	 * @param array<int,string> $texts Free-text fields.
	 * @param bool              $rates True when the tax rate in percent is a field of each line.
	 * @return array<int,FieldDef>
	 */
	private static function orderFields( array $texts, bool $rates ): array {
		$b      = FieldCatalogBuilder::make()->group( __( 'Order', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'currency', __( 'Currency', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency', true )
			->datetime( 'date', __( 'Order date', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->longText( 'note', __( 'Order note', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'coupons', __( 'Coupon codes', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'total', __( 'Order total, with tax (for the total check)', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'excluded_total', __( 'Part of the total that belongs to products left out', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->boolean( 'prices_include_tax', __( 'Mapped prices include tax', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		$labels = array(
			'header'       => __( 'Document heading', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'title'        => __( 'Document title', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'introduction' => __( 'Text above the items', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'remark'       => __( 'Text below the items', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'head_text'    => __( 'Text above the items', 'estasoft-enterprise-erpcrm-sync-engine' ),
			'foot_text'    => __( 'Text below the items', 'estasoft-enterprise-erpcrm-sync-engine' ),
		);
		foreach ( $texts as $key ) {
			if ( isset( $labels[ $key ] ) ) {
				$b->longText( $key, $labels[ $key ] );
			}
		}
		self::customerFields( $b );
		$b->group( __( 'Order lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'lines[].sku', __( 'SKU', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'lines[].name', __( 'Product name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'lines[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'lines[].total', __( 'Line total after discount, without tax', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->taxCode( 'lines[].tax_code', __( 'Tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Shipping and fees', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'shipping.name', __( 'Shipping name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping.total', __( 'Shipping, without tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'shipping.tax_code', __( 'Shipping tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'fees[].name', __( 'Fee name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'fees[].total', __( 'Fee, without tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->taxCode( 'fees[].tax_code', __( 'Fee tax code', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Payment', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->datetime( 'payment.date', __( 'Payment date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'payment.reference', __( 'Payment reference', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		if ( $rates ) {
			$b->group( __( 'Tax rates', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->number( 'lines[].tax_rate', __( 'Tax rate of the line in percent', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->number( 'shipping.tax_rate', __( 'Tax rate of shipping in percent', 'estasoft-enterprise-erpcrm-sync-engine' ) )
				->number( 'fees[].tax_rate', __( 'Tax rate of the fee in percent', 'estasoft-enterprise-erpcrm-sync-engine' ) );
		}
		return $b->build();
	}

	/**
	 * Customer target fields, added to a builder.
	 *
	 * @param FieldCatalogBuilder $b Builder.
	 */
	private static function customerFields( FieldCatalogBuilder $b ): FieldCatalogBuilder {
		return $b->group( __( 'Customer', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'customer.email', __( 'Email', 'estasoft-enterprise-erpcrm-sync-engine' ), 'email', true )
			->text( 'customer.first_name', __( 'First name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.last_name', __( 'Last name', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->text( 'customer.company', __( 'Company', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'customer.phone', __( 'Phone', 'estasoft-enterprise-erpcrm-sync-engine' ), 'phone' )
			->text( 'customer.street', __( 'Street', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.supplement', __( 'Address line 2', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.zip', __( 'Postcode', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.city', __( 'City', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'customer.state', __( 'State', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'customer.country', __( 'Country', 'estasoft-enterprise-erpcrm-sync-engine' ), 'country' )
			->text( 'customer.vat_id', __( 'VAT id', 'estasoft-enterprise-erpcrm-sync-engine' ) );
	}

	/**
	 * Refund target fields.
	 *
	 * @return array<int,FieldDef>
	 */
	private static function refundFields(): array {
		return FieldCatalogBuilder::make()->group( __( 'Refund', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'order_ref', __( 'Reference of the refunded order (WC-…)', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->field( 'customer.email', __( 'Customer email', 'estasoft-enterprise-erpcrm-sync-engine' ), 'email' )
			->datetime( 'order_date', __( 'Date of the refunded order', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->field( 'currency', __( 'Currency', 'estasoft-enterprise-erpcrm-sync-engine' ), 'currency' )
			->datetime( 'date', __( 'Refund date', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'reason', __( 'Reason', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'amount', __( 'Refund amount, with tax', 'estasoft-enterprise-erpcrm-sync-engine' ), true )
			->money( 'excluded_amount', __( 'Part that belongs to products left out', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'shipping_amount', __( 'Refunded shipping, with tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'other_amount', __( 'Other refunded amount, with tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->group( __( 'Refunded lines', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->sku( 'lines[].sku', __( 'SKU', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->text( 'lines[].name', __( 'Product name', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->number( 'lines[].quantity', __( 'Quantity', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'lines[].amount', __( 'Amount, without tax', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->money( 'lines[].tax_amount', __( 'Tax of the line', 'estasoft-enterprise-erpcrm-sync-engine' ) )
			->build();
	}

	/**
	 * Order rules.
	 *
	 * @param bool $rates True when the rate in percent is mapped too.
	 * @return array<int,array<string,mixed>>
	 */
	private static function orderRules( bool $rates ): array {
		$rateRules = $rates ? array(
			self::rule( 'line-rate', 'lines[].tax_rate', 'lines[].tax_rate' ),
			self::rule( 'shipping-rate', 'shipping.tax_rate', 'shipping_lines[].tax_rate' ),
		) : array();
		return array_merge(
			$rateRules,
			array(
				self::rule( 'order-currency', 'currency', 'order.currency', array( self::transform( 'case', array( 'mode' => 'upper' ) ) ), true, 'SYNC-VAL-002' ),
				self::rule( 'order-date', 'date', 'order.created_at', array( self::transform( 'date_utc', array( 'format' => 'mysql' ) ) ), true, 'SYNC-VAL-002' ),
				self::rule( 'order-note', 'note', 'order.note', array( self::transform( 'trim' ) ) ),
				self::rule( 'order-coupons', 'coupons', 'coupons[].code', array( self::transform( 'join', array( 'separator' => ', ' ) ) ) ),
				self::rule( 'order-total', 'total', 'order.total' ),
				self::rule( 'order-excluded-total', 'excluded_total', 'order.excluded_total' ),
			),
			self::customerRules(),
			array(
				self::rule( 'line-sku', 'lines[].sku', 'lines[].sku', array( self::transform( 'trim' ) ) ),
				self::rule( 'line-name', 'lines[].name', 'lines[].name', array( self::transform( 'trim' ) ) ),
				self::rule( 'line-quantity', 'lines[].quantity', 'lines[].quantity', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'line-total', 'lines[].total', 'lines[].line_total', array(), true, 'SYNC-VAL-002' ),
				self::rule( 'line-tax', 'lines[].tax_code', 'lines[].tax_rate', array( self::transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				self::rule( 'shipping-name', 'shipping.name', array( 'const' => 'Shipping' ) ),
				self::rule( 'shipping-total', 'shipping.total', 'order.shipping_total' ),
				self::rule( 'shipping-tax', 'shipping.tax_code', 'shipping_lines[].tax_rate', array( self::transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				self::rule( 'fee-name', 'fees[].name', 'fees[].name', array( self::transform( 'trim' ) ) ),
				self::rule( 'fee-total', 'fees[].total', 'fees[].total' ),
				self::rule( 'payment-date', 'payment.date', 'order.paid_at', array( self::transform( 'date_utc', array( 'format' => 'mysql' ) ) ) ),
				self::rule( 'payment-reference', 'payment.reference', 'order.payment.transaction_id', array( self::transform( 'trim' ) ) ),
			)
		);
	}

	/**
	 * Customer rules from the billing block.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function customerRules(): array {
		return array(
			self::rule( 'customer-email', 'customer.email', 'billing.email', array( self::transform( 'default', array( 'from' => 'customer.email' ) ), self::transform( 'trim' ) ), true, 'SYNC-MAP-003' ),
			self::rule( 'customer-first-name', 'customer.first_name', 'billing.first_name', array( self::transform( 'trim' ) ) ),
			self::rule( 'customer-last-name', 'customer.last_name', 'billing.last_name', array( self::transform( 'trim' ) ), true, 'SYNC-VAL-002' ),
			self::rule( 'customer-company', 'customer.company', 'billing.company', array( self::transform( 'trim' ) ) ),
			self::rule( 'customer-phone', 'customer.phone', 'billing.phone', array( self::transform( 'phone_e164', array( 'default_country' => 'billing.country' ) ) ) ),
			self::rule( 'customer-street', 'customer.street', 'billing.address_1', array( self::transform( 'trim' ) ) ),
			self::rule( 'customer-supplement', 'customer.supplement', 'billing.address_2', array( self::transform( 'trim' ) ) ),
			self::rule( 'customer-zip', 'customer.zip', 'billing.postcode', array( self::transform( 'trim' ) ) ),
			self::rule( 'customer-city', 'customer.city', 'billing.city', array( self::transform( 'trim' ) ) ),
			self::rule( 'customer-state', 'customer.state', 'billing.state', array( self::transform( 'iso_state', array( 'country' => 'billing.country' ) ) ) ),
			self::rule( 'customer-country', 'customer.country', 'billing.country', array( self::transform( 'iso_country' ) ), true, 'SYNC-VAL-002' ),
			self::rule( 'customer-vat', 'customer.vat_id', 'order.buyer_tax_number', array( self::transform( 'trim' ) ) ),
		);
	}

	/**
	 * Refund rules.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function refundRules(): array {
		return array(
			self::rule( 'refund-order-ref', 'order_ref', 'refund.order_id', array( self::transform( 'prefix', array( 'value' => 'WC-' ) ) ), true, 'SYNC-VAL-002' ),
			self::rule( 'refund-customer-email', 'customer.email', 'billing.email', array( self::transform( 'default', array( 'from' => 'customer.email' ) ), self::transform( 'trim' ) ) ),
			self::rule( 'refund-order-date', 'order_date', 'order.created_at', array( self::transform( 'date_utc', array( 'format' => 'mysql' ) ) ) ),
			self::rule( 'refund-currency', 'currency', 'order.currency', array( self::transform( 'case', array( 'mode' => 'upper' ) ) ) ),
			self::rule( 'refund-date', 'date', 'refund.created_at', array( self::transform( 'date_utc', array( 'format' => 'mysql' ) ) ) ),
			self::rule( 'refund-reason', 'reason', 'refund.reason', array( self::transform( 'trim' ) ) ),
			self::rule( 'refund-amount', 'amount', 'refund.amount', array(), true, 'SYNC-VAL-002' ),
			self::rule( 'refund-excluded', 'excluded_amount', 'refund.excluded_amount' ),
			self::rule( 'refund-shipping', 'shipping_amount', 'refund.shipping_amount' ),
			self::rule( 'refund-other', 'other_amount', 'refund.other_amount' ),
			self::rule( 'refund-line-sku', 'lines[].sku', 'refund.lines[].sku', array( self::transform( 'trim' ) ) ),
			self::rule( 'refund-line-quantity', 'lines[].quantity', 'refund.lines[].quantity' ),
			self::rule( 'refund-line-amount', 'lines[].amount', 'refund.lines[].amount' ),
			self::rule( 'refund-line-tax', 'lines[].tax_amount', 'refund.lines[].tax' ),
		);
	}

	/**
	 * One rule.
	 *
	 * @param string                                      $id         Stable id inside the rule list.
	 * @param string                                      $target     Target path.
	 * @param string|array<int,string>|array{const:mixed} $source     Source path, list of paths, or a constant.
	 * @param array<int,array<string,mixed>>              $transforms Transforms in order.
	 * @param bool                                        $required   Whether an empty result stops the item.
	 * @param string|null                                 $errorCode  Catalog code raised when a required value is empty.
	 * @return array<string,mixed>
	 */
	private static function rule( string $id, string $target, string|array $source, array $transforms = array(), bool $required = false, ?string $errorCode = null ): array {
		$rule = array(
			'id'         => $id,
			'target'     => $target,
			'source'     => $source,
			'transforms' => $transforms,
			'required'   => $required,
		);
		if ( null !== $errorCode ) {
			$rule['error_code'] = $errorCode;
		}
		return $rule;
	}

	/**
	 * One transform.
	 *
	 * @param string              $type Transform name from the fixed library.
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,mixed>
	 */
	private static function transform( string $type, array $args = array() ): array {
		$transform = array( 'type' => $type );
		if ( array() !== $args ) {
			$transform['args'] = $args;
		}
		return $transform;
	}
}
