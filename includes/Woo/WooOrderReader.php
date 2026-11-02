<?php
/**
 * Reads WooCommerce orders, refunds and customers as canonical data.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Woo;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\Ports\CanonicalSource;
use Estasoft\SyncEngine\Mapping\Ports\SourceNotFoundException;
use Estasoft\SyncEngine\Mapping\Support\MinorUnits;
use Estasoft\SyncEngine\Mapping\Support\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Uses the CRUD layer only (wc_get_order, getters, get_meta); no SQL on posts, post meta or the order tables. Money becomes integer
 * minor units with the order currency's exponent. Card data is never read: only the transaction id and the last four digits, if a
 * gateway keeps them in a known meta key. Lines of products marked "Exclude from syncing" are left out.
 */
final class WooOrderReader implements CanonicalSource {

	/**
	 * Order meta keys searched, in order, for the buyer's tax number. Filter: estasoft_sync_buyer_tax_number_keys.
	 */
	private const TAX_NUMBER_KEYS = array( '_billing_vat_number', '_billing_tax_number', '_billing_tax_id', '_billing_vat', '_billing_trn', '_vat_number' );

	/**
	 * Order meta keys searched, in order, for the last four digits of the card. Filter: estasoft_sync_card_last4_keys.
	 */
	private const LAST4_KEYS = array( '_card_last4', '_stripe_card_last4', '_wc_stripe_card_last4', '_payment_card_last4' );

	/**
	 * Set up with what it depends on.
	 *
	 * @param ProductMeta $products Product meta (exclusion flag).
	 */
	public function __construct( private readonly ProductMeta $products ) {
	}

	/**
	 * Read an order, refund or customer.
	 *
	 * @param EntityType        $type     Order, Refund or Customer.
	 * @param string            $entityId WooCommerce id (a refund id, or the id of an order whose latest refund is wanted).
	 * @param array<int,string> $metaKeys Meta keys to expose as meta.<key>.
	 *
	 * @throws SourceNotFoundException When the object does not exist.
	 */
	public function read( EntityType $type, string $entityId, array $metaKeys = array() ): CanonicalOrder {
		if ( ! function_exists( 'wc_get_order' ) ) {
			throw new SourceNotFoundException( 'WooCommerce is not available.' );
		}
		$id = (int) $entityId;
		return match ( $type ) {
			EntityType::Order    => new CanonicalOrder( $this->order( $this->loadOrder( $id ), $metaKeys ) ),
			EntityType::Refund   => new CanonicalOrder( $this->refund( $id, $metaKeys ) ),
			EntityType::Customer => new CanonicalOrder( $this->customer( $id, $metaKeys ) ),
			default              => throw new SourceNotFoundException( 'This entity type is not read from WooCommerce.' ),
		};
	}

	/**
	 * Load an order that must be a real order (not a refund).
	 *
	 * @param int $id Order id.
	 *
	 * @throws SourceNotFoundException When it does not exist.
	 */
	private function loadOrder( int $id ): \WC_Order {
		$order = $id > 0 ? wc_get_order( $id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			throw new SourceNotFoundException( 'The order does not exist.' );
		}
		return $order;
	}

	/**
	 * The canonical order.
	 *
	 * @param \WC_Order         $order    Order.
	 * @param array<int,string> $metaKeys Meta keys to expose.
	 * @return array<string,mixed>
	 */
	private function order( \WC_Order $order, array $metaKeys ): array {
		$currency = (string) $order->get_currency();
		$exponent = MinorUnits::exponent( $currency );
		$rates    = $this->ratePercents( $order );

		$customerId = (int) $order->get_customer_id();
		$methodId   = (string) $order->get_payment_method();

		$data = array(
			'order'          => array(
				'id'               => (int) $order->get_id(),
				'number'           => (string) $order->get_order_number(),
				'status'           => (string) $order->get_status(),
				'currency'         => $currency,
				'created_at'       => $this->iso( $order->get_date_created() ),
				'paid_at'          => $this->iso( $order->get_date_paid() ),
				'note'             => (string) $order->get_customer_note(),
				'client_ref'       => 'WC-' . $order->get_id(),
				'total'            => $this->minor( $order->get_total(), $exponent ),
				'subtotal'         => $this->minor( $order->get_subtotal(), $exponent ),
				'discount_total'   => $this->minor( $order->get_discount_total(), $exponent ),
				'shipping_total'   => $this->minor( $order->get_shipping_total(), $exponent ),
				'tax_total'        => $this->minor( $order->get_total_tax(), $exponent ),
				'buyer_tax_number' => $this->firstMeta( $order, (array) apply_filters( 'estasoft_sync_buyer_tax_number_keys', self::TAX_NUMBER_KEYS ) ),
				'payment'          => array(
					'method_id'      => $methodId,
					'method_title'   => (string) $order->get_payment_method_title(),
					'transaction_id' => (string) $order->get_transaction_id(),
					'last4'          => $this->last4( $order ),
					'is_cod'         => 'cod' === $methodId,
				),
			),
			'customer'       => array(
				'id'         => $customerId,
				'email'      => (string) $order->get_billing_email(),
				'first_name' => (string) $order->get_billing_first_name(),
				'last_name'  => (string) $order->get_billing_last_name(),
				'company'    => (string) $order->get_billing_company(),
				'phone'      => (string) $order->get_billing_phone(),
				'is_guest'   => 0 === $customerId,
			),
			'billing'        => array(
				'first_name' => (string) $order->get_billing_first_name(),
				'last_name'  => (string) $order->get_billing_last_name(),
				'company'    => (string) $order->get_billing_company(),
				'address_1'  => (string) $order->get_billing_address_1(),
				'address_2'  => (string) $order->get_billing_address_2(),
				'city'       => (string) $order->get_billing_city(),
				'state'      => (string) $order->get_billing_state(),
				'postcode'   => (string) $order->get_billing_postcode(),
				'country'    => (string) $order->get_billing_country(),
				'phone'      => (string) $order->get_billing_phone(),
				'email'      => (string) $order->get_billing_email(),
			),
			'shipping'       => array(
				'first_name' => (string) $order->get_shipping_first_name(),
				'last_name'  => (string) $order->get_shipping_last_name(),
				'company'    => (string) $order->get_shipping_company(),
				'address_1'  => (string) $order->get_shipping_address_1(),
				'address_2'  => (string) $order->get_shipping_address_2(),
				'city'       => (string) $order->get_shipping_city(),
				'state'      => (string) $order->get_shipping_state(),
				'postcode'   => (string) $order->get_shipping_postcode(),
				'country'    => (string) $order->get_shipping_country(),
				'phone'      => (string) $order->get_shipping_phone(),
			),
			'lines'          => $this->lines( $order, $exponent, $rates ),
			'shipping_lines' => $this->shippingLines( $order, $exponent, $rates ),
			'fees'           => $this->fees( $order, $exponent ),
			'taxes'          => $this->taxes( $order, $exponent ),
			'coupons'        => $this->coupons( $order, $exponent ),
		);

		return $this->withMeta( $data, $order, $metaKeys );
	}

	/**
	 * The canonical refund: the parent order plus a refund section.
	 *
	 * @param int               $id       Refund id, or the id of an order (its latest refund is used).
	 * @param array<int,string> $metaKeys Meta keys to expose.
	 * @return array<string,mixed>
	 *
	 * @throws SourceNotFoundException When there is no such refund.
	 */
	private function refund( int $id, array $metaKeys ): array {
		$object = $id > 0 ? wc_get_order( $id ) : false;
		if ( $object instanceof \WC_Order ) {
			$refunds = $object->get_refunds();
			$object  = array() === $refunds ? false : reset( $refunds );
		}
		if ( ! $object instanceof \WC_Order_Refund ) {
			throw new SourceNotFoundException( 'The refund does not exist.' );
		}
		$parent   = $this->loadOrder( (int) $object->get_parent_id() );
		$exponent = MinorUnits::exponent( (string) $parent->get_currency() );

		$lines = array();
		foreach ( $object->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$lines[] = array(
				'sku'      => $product instanceof \WC_Product ? (string) $product->get_sku() : '',
				'quantity' => $this->quantity( abs( (float) $item->get_quantity() ) ),
				'amount'   => abs( $this->minor( $item->get_total(), $exponent ) ),
			);
		}

		$data           = $this->order( $parent, $metaKeys );
		$data['refund'] = array(
			'id'         => (int) $object->get_id(),
			'order_id'   => (int) $parent->get_id(),
			'amount'     => abs( $this->minor( $object->get_amount(), $exponent ) ),
			'reason'     => (string) $object->get_reason(),
			'created_at' => $this->iso( $object->get_date_created() ),
			'lines'      => $lines,
		);
		return $data;
	}

	/**
	 * The canonical customer (no order section).
	 *
	 * @param int               $id       User id.
	 * @param array<int,string> $metaKeys Meta keys to expose.
	 * @return array<string,mixed>
	 *
	 * @throws SourceNotFoundException When there is no such customer.
	 */
	private function customer( int $id, array $metaKeys ): array {
		$customer = $id > 0 ? new \WC_Customer( $id ) : null;
		if ( null === $customer || (int) $customer->get_id() !== $id ) {
			throw new SourceNotFoundException( 'The customer does not exist.' );
		}
		$data = array(
			'customer' => array(
				'id'         => $id,
				'email'      => (string) $customer->get_billing_email(),
				'first_name' => (string) $customer->get_billing_first_name(),
				'last_name'  => (string) $customer->get_billing_last_name(),
				'company'    => (string) $customer->get_billing_company(),
				'phone'      => (string) $customer->get_billing_phone(),
				'is_guest'   => false,
			),
			'billing'  => array(
				'first_name' => (string) $customer->get_billing_first_name(),
				'last_name'  => (string) $customer->get_billing_last_name(),
				'company'    => (string) $customer->get_billing_company(),
				'address_1'  => (string) $customer->get_billing_address_1(),
				'address_2'  => (string) $customer->get_billing_address_2(),
				'city'       => (string) $customer->get_billing_city(),
				'state'      => (string) $customer->get_billing_state(),
				'postcode'   => (string) $customer->get_billing_postcode(),
				'country'    => (string) $customer->get_billing_country(),
				'phone'      => (string) $customer->get_billing_phone(),
				'email'      => (string) $customer->get_billing_email(),
			),
			'shipping' => array(
				'first_name' => (string) $customer->get_shipping_first_name(),
				'last_name'  => (string) $customer->get_shipping_last_name(),
				'company'    => (string) $customer->get_shipping_company(),
				'address_1'  => (string) $customer->get_shipping_address_1(),
				'address_2'  => (string) $customer->get_shipping_address_2(),
				'city'       => (string) $customer->get_shipping_city(),
				'state'      => (string) $customer->get_shipping_state(),
				'postcode'   => (string) $customer->get_shipping_postcode(),
				'country'    => (string) $customer->get_shipping_country(),
				'phone'      => (string) $customer->get_shipping_phone(),
			),
		);
		return $this->withMeta( $data, $customer, $metaKeys );
	}

	/**
	 * The product lines, without products marked as excluded.
	 *
	 * @param \WC_Order            $order    Order.
	 * @param int                  $exponent Currency exponent.
	 * @param array<int,float|int> $rates    Rate percent by WooCommerce tax rate id.
	 * @return array<int,array<string,mixed>>
	 */
	private function lines( \WC_Order $order, int $exponent, array $rates ): array {
		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( $product instanceof \WC_Product && $this->products->isExcluded( $product ) ) {
				continue;
			}
			$quantity = (float) $item->get_quantity();
			$lines[]  = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'sku'          => $product instanceof \WC_Product ? (string) $product->get_sku() : '',
				'name'         => (string) $item->get_name(),
				'quantity'     => $this->quantity( $quantity ),
				'unit_price'   => $this->unitPrice( $this->minor( $item->get_subtotal(), $exponent ), $quantity ),
				'line_total'   => $this->minor( $item->get_total(), $exponent ),
				'line_tax'     => $this->minor( $item->get_total_tax(), $exponent ),
				'tax_rate'     => $this->lineRate( $item->get_taxes(), $rates ),
				'tax_class'    => (string) $item->get_tax_class(),
			);
		}
		return $lines;
	}

	/**
	 * The shipping lines.
	 *
	 * @param \WC_Order            $order    Order.
	 * @param int                  $exponent Currency exponent.
	 * @param array<int,float|int> $rates    Rate percent by WooCommerce tax rate id.
	 * @return array<int,array<string,mixed>>
	 */
	private function shippingLines( \WC_Order $order, int $exponent, array $rates ): array {
		$lines = array();
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Shipping ) {
				continue;
			}
			$lines[] = array(
				'method_id'    => (string) $item->get_method_id(),
				'method_title' => (string) $item->get_method_title(),
				'total'        => $this->minor( $item->get_total(), $exponent ),
				'tax'          => $this->minor( $item->get_total_tax(), $exponent ),
				'tax_rate'     => $this->lineRate( $item->get_taxes(), $rates ),
			);
		}
		return $lines;
	}

	/**
	 * The fee lines.
	 *
	 * @param \WC_Order $order    Order.
	 * @param int       $exponent Currency exponent.
	 * @return array<int,array<string,mixed>>
	 */
	private function fees( \WC_Order $order, int $exponent ): array {
		$fees = array();
		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Fee ) {
				continue;
			}
			$fees[] = array(
				'name'  => (string) $item->get_name(),
				'total' => $this->minor( $item->get_total(), $exponent ),
				'tax'   => $this->minor( $item->get_total_tax(), $exponent ),
			);
		}
		return $fees;
	}

	/**
	 * The tax breakdown.
	 *
	 * @param \WC_Order $order    Order.
	 * @param int       $exponent Currency exponent.
	 * @return array<int,array<string,mixed>>
	 */
	private function taxes( \WC_Order $order, int $exponent ): array {
		$taxes = array();
		foreach ( $order->get_items( 'tax' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Tax ) {
				continue;
			}
			$taxes[] = array(
				'rate_id'      => (int) $item->get_rate_id(),
				'label'        => (string) $item->get_label(),
				'rate_percent' => $this->number( $item->get_rate_percent() ),
				'amount'       => $this->minor( $item->get_tax_total(), $exponent ) + $this->minor( $item->get_shipping_tax_total(), $exponent ),
			);
		}
		return $taxes;
	}

	/**
	 * The coupons used.
	 *
	 * @param \WC_Order $order    Order.
	 * @param int       $exponent Currency exponent.
	 * @return array<int,array<string,mixed>>
	 */
	private function coupons( \WC_Order $order, int $exponent ): array {
		$coupons = array();
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}
			$coupons[] = array(
				'code'     => (string) $item->get_code(),
				'discount' => $this->minor( $item->get_discount(), $exponent ),
			);
		}
		return $coupons;
	}

	/**
	 * Percent of each tax rate used on the order, by rate id.
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int,float|int>
	 */
	private function ratePercents( \WC_Order $order ): array {
		$rates = array();
		foreach ( $order->get_items( 'tax' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Tax ) {
				$rates[ (int) $item->get_rate_id() ] = $this->number( $item->get_rate_percent() );
			}
		}
		return $rates;
	}

	/**
	 * The tax rate of a line: the sum of the rates applied to it, or null when it carries no tax rate.
	 *
	 * @param mixed                $taxes Result of get_taxes() ( 'total' => rate id => amount ).
	 * @param array<int,float|int> $rates Rate percent by rate id.
	 * @return float|int|null
	 */
	private function lineRate( mixed $taxes, array $rates ): float|int|null {
		$ids = is_array( $taxes ) && is_array( $taxes['total'] ?? null ) ? array_keys( $taxes['total'] ) : array();
		if ( array() === $ids ) {
			return null;
		}
		$sum   = 0;
		$found = false;
		foreach ( $ids as $rateId ) {
			if ( isset( $rates[ (int) $rateId ] ) ) {
				$sum  += $rates[ (int) $rateId ];
				$found = true;
			}
		}
		return $found ? $sum : null;
	}

	/**
	 * Add the meta keys a mapping asked for.
	 *
	 * @param array<string,mixed> $data     Canonical data.
	 * @param object              $holder   Order or customer with get_meta().
	 * @param array<int,string>   $metaKeys Meta keys.
	 * @return array<string,mixed>
	 */
	private function withMeta( array $data, object $holder, array $metaKeys ): array {
		if ( array() === $metaKeys || ! method_exists( $holder, 'get_meta' ) ) {
			return $data;
		}
		$meta = array();
		foreach ( $metaKeys as $key ) {
			if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]+$/', $key ) ) {
				continue;
			}
			$value = $holder->get_meta( $key, true );
			if ( is_scalar( $value ) ) {
				$meta[ $key ] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			}
		}
		$data['meta'] = $meta;
		return $data;
	}

	/**
	 * The first non-empty value among some meta keys.
	 *
	 * @param \WC_Order        $order Order.
	 * @param array<int,mixed> $keys  Meta keys, searched in order.
	 */
	private function firstMeta( \WC_Order $order, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = $order->get_meta( (string) $key, true );
			if ( is_scalar( $value ) && '' !== Text::trim( (string) $value ) ) {
				return Text::trim( (string) $value );
			}
		}
		return '';
	}

	/**
	 * The last four digits of the card, when a gateway keeps them; never more than four digits.
	 *
	 * @param \WC_Order $order Order.
	 */
	private function last4( \WC_Order $order ): string {
		$value  = $this->firstMeta( $order, (array) apply_filters( 'estasoft_sync_card_last4_keys', self::LAST4_KEYS ) );
		$digits = (string) preg_replace( '/\D+/', '', $value );
		return strlen( $digits ) >= 4 ? substr( $digits, -4 ) : '';
	}

	/**
	 * An amount as integer minor units; text that is not a number counts as zero.
	 *
	 * @param mixed $amount   Amount in major units.
	 * @param int   $exponent Currency exponent.
	 */
	private function minor( mixed $amount, int $exponent ): int {
		return MinorUnits::parse( is_scalar( $amount ) ? $amount : '0', $exponent ) ?? 0;
	}

	/**
	 * Unit price in minor units: the line subtotal divided by the quantity, rounded half away from zero. The quantity is taken in
	 * thousandths so the division stays in integers.
	 *
	 * @param int   $subtotal Line subtotal in minor units (before discounts).
	 * @param float $quantity Quantity.
	 */
	private function unitPrice( int $subtotal, float $quantity ): int {
		$thousandths = (int) round( $quantity * 1000 );
		if ( $thousandths <= 0 ) {
			return $subtotal;
		}
		$sign = $subtotal < 0 ? -1 : 1;
		return $sign * intdiv( 2 * abs( $subtotal ) * 1000 + $thousandths, 2 * $thousandths );
	}

	/**
	 * A quantity as an integer when it is whole.
	 *
	 * @param float $quantity Quantity.
	 * @return int|float
	 */
	private function quantity( float $quantity ): int|float {
		return floor( $quantity ) === $quantity ? (int) $quantity : $quantity;
	}

	/**
	 * A percentage as an integer when it is whole.
	 *
	 * @param mixed $value Value from WooCommerce.
	 * @return int|float
	 */
	private function number( mixed $value ): int|float {
		return $this->quantity( is_numeric( $value ) ? (float) $value : 0.0 );
	}

	/**
	 * A WooCommerce date as ISO 8601 in UTC, or null.
	 *
	 * @param \DateTimeInterface|null $date Date.
	 */
	private function iso( ?\DateTimeInterface $date ): ?string {
		return null === $date ? null : gmdate( 'Y-m-d\TH:i:s\Z', $date->getTimestamp() );
	}
}
