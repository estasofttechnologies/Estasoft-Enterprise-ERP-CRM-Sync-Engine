<?php
/**
 * Version 1 mapping rules for Qoyod.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\Qoyod;

defined( 'ABSPATH' ) || exit;

/**
 * The starting rules a merchant sees before editing anything. Each rule follows the rule model of docs/ARCHITECTURE.md: id, target path
 * (a payload path from QoyodFieldCatalog), source path or list of paths (the canonical order model), transforms from the fixed library,
 * required, optional error_code and optional when.
 */
final class QoyodDefaultMappings {

	/**
	 * Rules for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products or refunds (stock and tracking are not offered by Qoyod).
	 * @return array<int,array<string,mixed>>
	 */
	public function rules( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => $this->orders(),
			'customers' => $this->customer(),
			'products'  => array(
				$this->rule( 'product-sku', 'product.sku', 'lines[].sku', array( $this->transform( 'trim' ) ), true, 'SYNC-MAP-001' ),
			),
			'refunds'   => $this->refunds(),
			default     => array(),
		};
	}

	/**
	 * Orders to invoices.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function orders(): array {
		return array_merge(
			array(
				$this->rule(
					'order-date',
					'date',
					'order.created_at',
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
				$this->rule( 'order-currency', 'currency', 'order.currency', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'order-note', 'note', 'order.note', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'order-number', 'number', 'order.number', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'order-total', 'total', 'order.total' ),
				$this->rule( 'order-excluded-total', 'excluded_total', 'order.excluded_total' ),
			),
			$this->customer(),
			array(
				$this->rule( 'line-sku', 'lines[].sku', 'lines[].sku', array( $this->transform( 'trim' ) ), true, 'SYNC-MAP-001' ),
				$this->rule( 'line-item', 'lines[].item', 'lines[].sku', array( $this->transform( 'sku_match', array( 'output' => 'id' ) ) ), true, 'SYNC-MAP-001' ),
				$this->rule( 'line-name', 'lines[].name', 'lines[].name', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'line-quantity', 'lines[].quantity', 'lines[].quantity', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'line-total', 'lines[].total', 'lines[].line_total', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'line-tax', 'lines[].tax_code', 'lines[].tax_rate', array( $this->transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				$this->rule( 'shipping-name', 'shipping.name', array( 'const' => 'Shipping' ) ),
				$this->rule( 'shipping-total', 'shipping.total', 'order.shipping_total' ),
				$this->rule( 'shipping-tax', 'shipping.tax_code', 'shipping_lines[].tax_rate', array( $this->transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' ),
				$this->rule( 'fee-name', 'fees[].name', 'fees[].name', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'fee-total', 'fees[].total', 'fees[].total' ),
				$this->rule( 'fee-tax', 'fees[].tax_amount', 'fees[].tax' ),
				$this->rule(
					'payment-date',
					'payment.date',
					'order.paid_at',
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
				$this->rule( 'payment-reference', 'payment.reference', 'order.payment.transaction_id', array( $this->transform( 'trim' ) ) ),
			)
		);
	}

	/**
	 * The billing customer.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function customer(): array {
		return array(
			$this->rule( 'customer-email', 'customer.email', 'billing.email', array( $this->transform( 'default', array( 'from' => 'customer.email' ) ), $this->transform( 'trim' ) ), true, 'SYNC-MAP-003' ),
			$this->rule( 'customer-name', 'customer.name', array( 'billing.first_name', 'billing.last_name' ), array( $this->transform( 'join', array( 'separator' => ' ' ) ), $this->transform( 'trim' ) ), true, 'SYNC-VAL-002' ),
			$this->rule( 'customer-company', 'customer.company', 'billing.company', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'customer-phone', 'customer.phone', 'billing.phone', array( $this->transform( 'phone_e164', array( 'default_country' => 'billing.country' ) ) ) ),
			$this->rule(
				'customer-address',
				'customer.address',
				array( 'billing.address_1', 'billing.address_2' ),
				array(
					$this->transform(
						'join',
						array(
							'separator'  => ', ',
							'skip_empty' => true,
						)
					),
					$this->transform( 'trim' ),
				)
			),
			$this->rule( 'customer-city', 'customer.city', 'billing.city', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'customer-state', 'customer.state', 'billing.state', array( $this->transform( 'iso_state', array( 'country' => 'billing.country' ) ) ) ),
			$this->rule( 'customer-postcode', 'customer.postcode', 'billing.postcode', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'customer-country', 'customer.country', 'billing.country', array( $this->transform( 'iso_country' ) ) ),
			$this->rule( 'customer-tax-number', 'customer.tax_number', 'order.buyer_tax_number', array( $this->transform( 'trim' ) ) ),
		);
	}

	/**
	 * Refunds to credit notes.
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
			$this->rule( 'refund-currency', 'currency', 'order.currency' ),
			$this->rule( 'refund-reason', 'reason', 'refund.reason', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'refund-amount', 'amount', 'refund.amount' ),
			$this->rule( 'refund-excluded', 'excluded_amount', 'refund.excluded_amount' ),
			$this->rule( 'refund-shipping', 'shipping_amount', 'refund.shipping_amount' ),
			$this->rule( 'refund-other', 'other_amount', 'refund.other_amount' ),
			$this->rule( 'refund-line-sku', 'lines[].sku', 'refund.lines[].sku', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'refund-line-quantity', 'lines[].quantity', 'refund.lines[].quantity' ),
			$this->rule( 'refund-line-amount', 'lines[].amount', 'refund.lines[].amount' ),
			$this->rule( 'refund-line-tax', 'lines[].tax_amount', 'refund.lines[].tax' ),
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
