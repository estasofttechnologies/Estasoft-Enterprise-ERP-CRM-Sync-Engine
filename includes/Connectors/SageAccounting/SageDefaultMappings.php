<?php
/**
 * Version 1 mapping rules for Sage.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\SageAccounting;

defined( 'ABSPATH' ) || exit;

/**
 * The starting rules a merchant sees before editing anything. Each rule follows the rule model of docs/ARCHITECTURE.md: id, target path
 * (a payload path from SageFieldCatalog), source path or list of paths (the canonical order model), transforms from the fixed library,
 * required, optional error_code. Dates are written as the shop's own day (`date_utc` with format date and the store's time zone). Sage has
 * no stock level to read and no tracking, so those lists are empty.
 */
final class SageDefaultMappings {

	/**
	 * Rules for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products, refunds, stock or tracking.
	 * @return array<int,array<string,mixed>>
	 */
	public function rules( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => $this->orders(),
			'customers' => $this->customerRules( 'partner.', 'billing', true ),
			'products'  => array( $this->rule( 'product-reference', 'product.reference', 'lines[].sku', array( $this->transform( 'trim' ) ), true, 'SYNC-MAP-001' ) ),
			'refunds'   => $this->refunds(),
			default     => array(),
		};
	}

	/**
	 * Orders to sales invoices.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function orders(): array {
		$day = array(
			$this->transform(
				'date_utc',
				array(
					'format'          => 'date',
					'output_timezone' => 'store',
				)
			),
		);
		return array_merge(
			array(
				$this->rule( 'order-client-ref', 'client_order_ref', 'order.client_ref', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'order-date', 'date_order', 'order.created_at', $day ),
				$this->rule( 'order-currency', 'currency', 'order.currency' ),
				$this->rule( 'order-note', 'note', 'order.note', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'order-total', 'total', 'order.total' ),
				$this->rule( 'order-excluded-total', 'excluded_total', 'order.excluded_total' ),
			),
			$this->customerRules( 'partner.', 'billing', true ),
			$this->deliveryRules(),
			array(
				$this->rule( 'line-product', 'order_line[].product', 'lines[].sku', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'line-name', 'order_line[].name', 'lines[].name', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'line-quantity', 'order_line[].quantity', 'lines[].quantity', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'line-price', 'order_line[].price_unit', 'lines[].unit_price', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'line-tax', 'order_line[].tax', 'lines[].tax_rate', array( $this->transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				$this->rule( 'shipping-total', 'shipping.total', 'order.shipping_total' ),
				$this->rule( 'shipping-tax', 'shipping.tax', 'shipping_lines[].tax_rate', array( $this->transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				$this->rule( 'fee-name', 'fee[].name', 'fees[].name', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'fee-total', 'fee[].total', 'fees[].total' ),
				$this->rule( 'discount-total', 'discount.total', 'order.discount_total' ),
				$this->rule( 'discount-name', 'discount.name', 'coupons[].code', array( $this->transform( 'join', array( 'separator' => ', ' ) ) ) ),
				$this->rule( 'payment-reference', 'payment.reference', 'order.payment.transaction_id', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'payment-date', 'payment.date', 'order.paid_at', $day ),
			)
		);
	}

	/**
	 * Refunds to sales credit notes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function refunds(): array {
		return array(
			$this->rule( 'refund-order-ref', 'order_ref', 'refund.order_id', array( $this->transform( 'prefix', array( 'value' => 'WC-' ) ) ), true, 'SYNC-VAL-002' ),
			$this->rule(
				'refund-date',
				'date',
				'refund.created_at',
				array(
					$this->transform(
						'date_utc',
						array(
							'format'          => 'date',
							'output_timezone' => 'store',
						)
					),
				)
			),
			$this->rule( 'refund-reason', 'reason', 'refund.reason', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'refund-amount', 'amount', 'refund.amount' ),
			$this->rule( 'refund-line-product', 'refund_line[].product', 'refund.lines[].sku', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'refund-line-quantity', 'refund_line[].quantity', 'refund.lines[].quantity' ),
			$this->rule( 'refund-line-amount', 'refund_line[].amount', 'refund.lines[].amount' ),
			$this->rule( 'refund-shipping', 'shipping.total', 'refund.shipping_amount' ),
		);
	}

	/**
	 * Rules for the billing customer.
	 *
	 * @param string $prefix  Target prefix.
	 * @param string $source  Source block: billing.
	 * @param bool   $primary Whether the email and name are required.
	 * @return array<int,array<string,mixed>>
	 */
	private function customerRules( string $prefix, string $source, bool $primary ): array {
		return array(
			$this->rule( 'partner-email', $prefix . 'email', $source . '.email', array( $this->transform( 'default', array( 'from' => 'customer.email' ) ), $this->transform( 'trim' ) ), $primary, 'SYNC-MAP-003' ),
			$this->rule( 'partner-name', $prefix . 'name', array( $source . '.first_name', $source . '.last_name' ), array( $this->transform( 'join', array( 'separator' => ' ' ) ), $this->transform( 'trim' ) ), $primary, 'SYNC-VAL-002' ),
			$this->rule( 'partner-first-name', $prefix . 'first_name', $source . '.first_name', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'partner-last-name', $prefix . 'last_name', $source . '.last_name', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'partner-company', $prefix . 'company_name', $source . '.company', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'partner-phone', $prefix . 'phone', $source . '.phone', array( $this->transform( 'phone_e164', array( 'default_country' => $source . '.country' ) ) ) ),
			$this->rule( 'partner-street', $prefix . 'street', $source . '.address_1', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'partner-street2', $prefix . 'street2', $source . '.address_2', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'partner-city', $prefix . 'city', $source . '.city', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'partner-state', $prefix . 'state_id', $source . '.state', array( $this->transform( 'iso_state', array( 'country' => $source . '.country' ) ) ) ),
			$this->rule( 'partner-zip', $prefix . 'zip', $source . '.postcode', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'partner-country', $prefix . 'country_id', $source . '.country', array( $this->transform( 'iso_country' ) ) ),
			$this->rule( 'partner-vat', $prefix . 'vat', 'order.buyer_tax_number', array( $this->transform( 'trim' ) ) ),
		);
	}

	/**
	 * Rules for the delivery address.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function deliveryRules(): array {
		return array(
			$this->rule( 'delivery-street', 'delivery.street', 'shipping.address_1', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'delivery-street2', 'delivery.street2', 'shipping.address_2', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'delivery-city', 'delivery.city', 'shipping.city', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'delivery-state', 'delivery.state_id', 'shipping.state', array( $this->transform( 'iso_state', array( 'country' => 'shipping.country' ) ) ) ),
			$this->rule( 'delivery-zip', 'delivery.zip', 'shipping.postcode', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'delivery-country', 'delivery.country_id', 'shipping.country', array( $this->transform( 'iso_country' ) ) ),
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
	private function rule( string $id, string $target, string|array $source, array $transforms = array(), bool $required = false, ?string $errorCode = null ): array {
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
	private function transform( string $type, array $args = array() ): array {
		$transform = array( 'type' => $type );
		if ( array() !== $args ) {
			$transform['args'] = $args;
		}
		return $transform;
	}
}
