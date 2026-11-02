<?php
/**
 * Version 1 mapping rules shared by the ERP connectors.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Connectors\EnterpriseCommon;

defined( 'ABSPATH' ) || exit;

/**
 * The starting rules a merchant sees before editing anything. Each rule follows the rule model of docs/ARCHITECTURE.md: id, target path
 * (a payload path from the connector's field catalog), source path or list of paths (the canonical order model), transforms from the fixed
 * library, required, optional error_code. The payload shape is the one OrderParser and RefundParser read.
 */
final class DefaultRules {

	/**
	 * Set up with what it depends on.
	 *
	 * @param string $skuOutput        What the SKU match puts in `order_line[].product`: `id` (the system's product id), `ref` (its reference), or `none` (the SKU as typed; the connector checks it).
	 * @param bool   $customerRequired Whether the customer's email and name are required.
	 * @param bool   $withTax          Whether lines and charges carry a tax code chosen through the tax mapping.
	 * @param bool   $parties          Whether the buyer's and the delivery address blocks are mapped (false when the system takes no buyer data).
	 */
	public function __construct( private readonly string $skuOutput = 'ref', private readonly bool $customerRequired = true, private readonly bool $withTax = true, private readonly bool $parties = true ) {
	}

	/**
	 * Rules for a mapping type.
	 *
	 * @param string $mappingType orders, customers, products or refunds.
	 * @return array<int,array<string,mixed>>
	 */
	public function rules( string $mappingType ): array {
		return match ( $mappingType ) {
			'orders'    => $this->orders(),
			'customers' => $this->parties ? $this->customerRules( 'partner.', 'billing', $this->customerRequired ) : array(),
			'products'  => $this->products(),
			'refunds'   => $this->refunds(),
			default     => array(),
		};
	}

	/**
	 * Orders.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function orders(): array {
		$product = 'none' === $this->skuOutput
			? $this->rule( 'line-product', 'order_line[].product', 'lines[].sku', array( $this->transform( 'trim' ) ), true, 'SYNC-MAP-001' )
			: $this->rule( 'line-product', 'order_line[].product', 'lines[].sku', array( $this->transform( 'sku_match', array( 'output' => $this->skuOutput ) ) ), true, 'SYNC-MAP-001' );
		$rules   = array_merge(
			array(
				$this->rule( 'order-client-ref', 'client_ref', 'order.client_ref', array(), true, 'SYNC-VAL-002' ),
				$this->rule(
					'order-date',
					'order_date',
					'order.created_at',
					array(
						$this->transform(
							'date_utc',
							array(
								'format'          => 'mysql',
								'output_timezone' => 'store',
							)
						),
					)
				),
				$this->rule( 'order-currency', 'currency', 'order.currency', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'order-note', 'note', 'order.note', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'order-total', 'total', 'order.total' ),
				$this->rule( 'order-excluded-total', 'excluded_total', 'order.excluded_total' ),
			),
			$this->parties ? $this->customerRules( 'partner.', 'billing', $this->customerRequired ) : array(),
			$this->parties ? $this->deliveryRules() : array(),
			array(
				$product,
				$this->rule( 'line-name', 'order_line[].name', 'lines[].name', array( $this->transform( 'trim' ) ) ),
				$this->rule( 'line-quantity', 'order_line[].quantity', 'lines[].quantity', array(), true, 'SYNC-VAL-002' ),
				$this->rule( 'line-price', 'order_line[].price_unit', 'lines[].unit_price', array(), true, 'SYNC-VAL-002' ),
			)
		);
		if ( $this->withTax ) {
			$rules[] = $this->rule( 'line-tax', 'order_line[].tax', 'lines[].tax_rate', array( $this->transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' );
		}
		$rules[] = $this->rule( 'shipping-total', 'shipping.total', 'order.shipping_total' );
		$rules[] = $this->rule( 'shipping-name', 'shipping.name', array( 'const' => 'Shipping' ) );
		if ( $this->withTax ) {
			$rules[] = $this->rule( 'shipping-tax', 'shipping.tax', 'shipping_lines[].tax_rate', array( $this->transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' );
		}
		$rules[] = $this->rule( 'fee-name', 'fee[].name', 'fees[].name', array( $this->transform( 'trim' ) ) );
		$rules[] = $this->rule( 'fee-total', 'fee[].total', 'fees[].total' );
		$rules[] = $this->rule( 'discount-total', 'discount.total', 'order.discount_total' );
		$rules[] = $this->rule( 'discount-name', 'discount.name', 'coupons[].code', array( $this->transform( 'join', array( 'separator' => ', ' ) ) ) );
		$rules[] = $this->rule( 'payment-reference', 'payment.reference', 'order.payment.transaction_id', array( $this->transform( 'trim' ) ) );
		return $rules;
	}

	/**
	 * Product matching: the WooCommerce SKU is the system's item code.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function products(): array {
		return array(
			$this->rule( 'product-reference', 'product.reference', 'lines[].sku', array( $this->transform( 'trim' ) ), true, 'SYNC-MAP-001' ),
		);
	}

	/**
	 * Refunds.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function refunds(): array {
		$line  = 'none' === $this->skuOutput
			? $this->rule( 'refund-line-product', 'refund_line[].product', 'refund.lines[].sku', array( $this->transform( 'trim' ) ) )
			: $this->rule( 'refund-line-product', 'refund_line[].product', 'refund.lines[].sku', array( $this->transform( 'sku_match', array( 'output' => $this->skuOutput ) ) ) );
		$rules = array(
			$this->rule( 'refund-order-ref', 'order_ref', 'refund.order_id', array( $this->transform( 'prefix', array( 'value' => 'WC-' ) ) ), true, 'SYNC-VAL-002' ),
			$this->rule(
				'refund-date',
				'date',
				'refund.created_at',
				array(
					$this->transform(
						'date_utc',
						array(
							'format'          => 'mysql',
							'output_timezone' => 'store',
						)
					),
				)
			),
			$this->rule( 'refund-reason', 'reason', 'refund.reason', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'refund-amount', 'amount', 'refund.amount' ),
			$line,
			$this->rule( 'refund-line-quantity', 'refund_line[].quantity', 'refund.lines[].quantity' ),
			$this->rule( 'refund-line-amount', 'refund_line[].amount', 'refund.lines[].amount' ),
		);
		if ( $this->withTax ) {
			$rules[] = $this->rule( 'refund-line-tax', 'refund_line[].tax', 'refund.lines[].tax', array( $this->transform( 'tax_lookup' ) ), false, 'SYNC-MAP-002' );
		}
		return $rules;
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
			$this->rule( 'partner-company', $prefix . 'company_name', $source . '.company', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'partner-phone', $prefix . 'phone', $source . '.phone', array( $this->transform( 'phone_e164', array( 'default_country' => $source . '.country' ) ) ) ),
			$this->rule( 'partner-street', $prefix . 'street', array( $source . '.address_1', $source . '.address_2' ), array( $this->transform( 'join', array( 'separator' => ', ' ) ), $this->transform( 'trim' ) ) ),
			$this->rule( 'partner-city', $prefix . 'city', $source . '.city', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'partner-state', $prefix . 'state', $source . '.state', array( $this->transform( 'iso_state', array( 'country' => $source . '.country' ) ) ) ),
			$this->rule( 'partner-zip', $prefix . 'zip', $source . '.postcode', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'partner-country', $prefix . 'country', $source . '.country', array( $this->transform( 'iso_country' ) ) ),
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
			$this->rule( 'delivery-name', 'delivery.name', array( 'shipping.first_name', 'shipping.last_name' ), array( $this->transform( 'join', array( 'separator' => ' ' ) ), $this->transform( 'trim' ) ) ),
			$this->rule( 'delivery-phone', 'delivery.phone', 'shipping.phone', array( $this->transform( 'phone_e164', array( 'default_country' => 'shipping.country' ) ) ) ),
			$this->rule( 'delivery-street', 'delivery.street', array( 'shipping.address_1', 'shipping.address_2' ), array( $this->transform( 'join', array( 'separator' => ', ' ) ), $this->transform( 'trim' ) ) ),
			$this->rule( 'delivery-city', 'delivery.city', 'shipping.city', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'delivery-state', 'delivery.state', 'shipping.state', array( $this->transform( 'iso_state', array( 'country' => 'shipping.country' ) ) ) ),
			$this->rule( 'delivery-zip', 'delivery.zip', 'shipping.postcode', array( $this->transform( 'trim' ) ) ),
			$this->rule( 'delivery-country', 'delivery.country', 'shipping.country', array( $this->transform( 'iso_country' ) ) ),
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
	public function rule( string $id, string $target, string|array $source, array $transforms = array(), bool $required = false, ?string $errorCode = null ): array {
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
	 * @param string              $type Transform name.
	 * @param array<string,mixed> $args Arguments.
	 * @return array{type:string,args?:array<string,mixed>}
	 */
	public function transform( string $type, array $args = array() ): array {
		return array() === $args ? array( 'type' => $type ) : array(
			'type' => $type,
			'args' => $args,
		);
	}
}
