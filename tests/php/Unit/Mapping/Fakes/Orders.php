<?php
/**
 * Sample canonical orders for the mapping tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes;

/**
 * Order #10490 of the sample shop (Dubai, AED) and variants. Built the way WooOrderReader builds orders.
 */
final class Orders {

	/**
	 * The sample order: Arabic customer name, UAE, one line, shipping, 5% VAT, cash on delivery.
	 *
	 * @param array<string,mixed> $overrides Values to replace (nested arrays are merged).
	 * @return array<string,mixed>
	 */
	public static function dubai( array $overrides = array() ): array {
		$order = array(
			'order'          => array(
				'id'               => 10490,
				'number'           => '10490',
				'status'           => 'processing',
				'currency'         => 'AED',
				'created_at'       => '2026-11-02T07:34:00Z',
				'paid_at'          => null,
				'note'             => 'Gift wrap please',
				'client_ref'       => 'WC-10490',
				'total'            => 48615,
				'subtotal'         => 43800,
				'discount_total'   => 0,
				'shipping_total'   => 2500,
				'tax_total'        => 2315,
				'buyer_tax_number' => '',
				'payment'          => array(
					'method_id'      => 'cod',
					'method_title'   => 'Cash on delivery',
					'transaction_id' => '',
					'last4'          => '',
					'is_cod'         => true,
				),
			),
			'customer'       => array(
				'id'         => 0,
				'email'      => ' Noor@Example.com ',
				'first_name' => 'نور',
				'last_name'  => 'المنصوري',
				'company'    => '',
				'phone'      => '050 123 4567',
				'is_guest'   => true,
			),
			'billing'        => array(
				'first_name' => 'نور',
				'last_name'  => 'المنصوري',
				'company'    => '',
				'address_1'  => 'Sheikh Zayed Road',
				'address_2'  => '',
				'city'       => 'Dubai',
				'state'      => 'DU',
				'postcode'   => '',
				'country'    => 'AE',
				'phone'      => '050 123 4567',
				'email'      => ' Noor@Example.com ',
			),
			'shipping'       => array(
				'first_name' => 'نور',
				'last_name'  => 'المنصوري',
				'company'    => '',
				'address_1'  => 'Sheikh Zayed Road',
				'address_2'  => '',
				'city'       => 'Dubai',
				'state'      => 'DU',
				'postcode'   => '',
				'country'    => 'AE',
				'phone'      => '050 123 4567',
			),
			'lines'          => array(
				array(
					'product_id'   => 501,
					'variation_id' => 0,
					'sku'          => ' CS-BOWL-02 ',
					'name'         => 'Stoneware bowl set',
					'quantity'     => 2,
					'unit_price'   => 21900,
					'line_total'   => 43800,
					'line_tax'     => 2190,
					'tax_rate'     => 5,
					'tax_class'    => '',
				),
			),
			'shipping_lines' => array(
				array(
					'method_id'    => 'flat_rate',
					'method_title' => 'Standard delivery',
					'total'        => 2500,
					'tax'          => 125,
					'tax_rate'     => 5,
				),
			),
			'fees'           => array(),
			'taxes'          => array(
				array(
					'rate_id'      => 1,
					'label'        => 'VAT',
					'rate_percent' => 5,
					'amount'       => 2315,
				),
			),
			'coupons'        => array(),
		);
		return array_replace_recursive( $order, $overrides );
	}
}
