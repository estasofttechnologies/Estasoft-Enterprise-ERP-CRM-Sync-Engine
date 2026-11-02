<?php
/**
 * WooOrderReader: WooCommerce objects into the canonical model.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Woo;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalFields;
use Estasoft\SyncEngine\Mapping\Ports\SourceNotFoundException;
use Estasoft\SyncEngine\Tests\Support\WooRegistry;
use Estasoft\SyncEngine\Woo\ProductMeta;
use Estasoft\SyncEngine\Woo\WooOrderReader;

final class WooOrderReaderTest extends WooTestCase {

	private function reader(): WooOrderReader {
		return new WooOrderReader( new ProductMeta() );
	}

	/**
	 * The sample order #10490: two bowls (219.00 each), 25.00 shipping, 5% VAT.
	 */
	private function sample(): \WC_Order {
		$bowl = $this->product( 501, array( 'sku' => 'CS-BOWL-02' ) );
		$line = $this->line(
			$bowl,
			array(
				'name'      => 'Stoneware bowl set',
				'quantity'  => 2,
				'subtotal'  => '438.00',
				'total'     => '438.00',
				'total_tax' => '21.90',
				'taxes'     => array( 'total' => array( 7 => '21.90' ), 'subtotal' => array( 7 => '21.90' ) ),
			)
		);
		$ship = new \WC_Order_Item_Shipping( array( 'method_id' => 'flat_rate', 'method_title' => 'Standard delivery', 'total' => '25.00', 'total_tax' => '1.25', 'taxes' => array( 'total' => array( 7 => '1.25' ) ) ) );
		$tax  = new \WC_Order_Item_Tax( array( 'rate_id' => 7, 'label' => 'VAT', 'rate_percent' => 5.0, 'tax_total' => '21.90', 'shipping_tax_total' => '1.25' ) );
		$fee  = new \WC_Order_Item_Fee( array( 'name' => 'Gift wrap', 'total' => '5.00', 'total_tax' => '0.25' ) );
		$coupon = new \WC_Order_Item_Coupon( array( 'code' => 'WELCOME', 'discount' => '10.00' ) );

		return $this->order(
			10490,
			array(
				'billing_first_name' => 'نور',
				'billing_last_name'  => 'المنصوري',
				'billing_company'    => '',
				'billing_address_1'  => 'شارع الشيخ زايد',
				'shipping_country'   => 'AE',
				'shipping_phone'     => '050 999 8888',
				'date_paid'          => new \DateTimeImmutable( '2026-11-02 07:40:00', new \DateTimeZone( 'UTC' ) ),
			),
			array(
				'line_item' => array( $line ),
				'shipping'  => array( $ship ),
				'tax'       => array( $tax ),
				'fee'       => array( $fee ),
				'coupon'    => array( $coupon ),
			)
		);
	}

	public function test_an_order_becomes_the_canonical_model(): void {
		$this->sample();
		$data = $this->reader()->read( EntityType::Order, '10490' )->toArray();

		$this->assertSame( 10490, $data['order']['id'] );
		$this->assertSame( '10490', $data['order']['number'] );
		$this->assertSame( 'processing', $data['order']['status'] );
		$this->assertSame( 'AED', $data['order']['currency'] );
		$this->assertSame( '2026-11-02T07:34:00Z', $data['order']['created_at'] );
		$this->assertSame( '2026-11-02T07:40:00Z', $data['order']['paid_at'] );
		$this->assertSame( 'Gift wrap please', $data['order']['note'] );
		$this->assertSame( 'WC-10490', $data['order']['client_ref'] );
		$this->assertSame( 48615, $data['order']['total'] );
		$this->assertSame( 43800, $data['order']['subtotal'] );
		$this->assertSame( 0, $data['order']['discount_total'] );
		$this->assertSame( 2500, $data['order']['shipping_total'] );
		$this->assertSame( 2315, $data['order']['tax_total'] );
		$this->assertSame( 'cod', $data['order']['payment']['method_id'] );
		$this->assertTrue( $data['order']['payment']['is_cod'] );
		$this->assertTrue( $data['customer']['is_guest'] );
		$this->assertSame( 'noor@example.com', $data['customer']['email'] );
		$this->assertSame( 'نور', $data['billing']['first_name'], 'Arabic stays Arabic.' );
		$this->assertSame( 'شارع الشيخ زايد', $data['billing']['address_1'] );
		$this->assertSame( '050 999 8888', $data['shipping']['phone'] );
	}

	public function test_lines_carry_exact_minor_units_and_the_tax_rate(): void {
		$this->sample();
		$data = $this->reader()->read( EntityType::Order, '10490' )->toArray();
		$this->assertSame(
			array(
				array(
					'product_id'   => 501,
					'variation_id' => 0,
					'sku'          => 'CS-BOWL-02',
					'name'         => 'Stoneware bowl set',
					'quantity'     => 2,
					'unit_price'   => 21900,
					'line_total'   => 43800,
					'line_tax'     => 2190,
					'tax_rate'     => 5,
					'tax_class'    => '',
				),
			),
			$data['lines']
		);
		$this->assertSame(
			array( array( 'method_id' => 'flat_rate', 'method_title' => 'Standard delivery', 'total' => 2500, 'tax' => 125, 'tax_rate' => 5 ) ),
			$data['shipping_lines']
		);
		$this->assertSame( array( array( 'name' => 'Gift wrap', 'total' => 500, 'tax' => 25 ) ), $data['fees'] );
		$this->assertSame( array( array( 'rate_id' => 7, 'label' => 'VAT', 'rate_percent' => 5, 'amount' => 2315 ) ), $data['taxes'] );
		$this->assertSame( array( array( 'code' => 'WELCOME', 'discount' => 1000 ) ), $data['coupons'] );
	}

	public function test_as_18_kuwaiti_dinar_has_three_decimals(): void {
		$this->order(
			20001,
			array( 'currency' => 'KWD', 'total' => '12.345', 'subtotal' => '12.345', 'shipping_total' => '0.000', 'total_tax' => '0.000' ),
			array( 'line_item' => array( $this->line( $this->product( 601, array( 'sku' => 'K-1' ) ), array( 'quantity' => 3, 'subtotal' => '12.345', 'total' => '12.345' ) ) ) )
		);
		$data = $this->reader()->read( EntityType::Order, '20001' )->toArray();
		$this->assertSame( 12345, $data['order']['total'] );
		$this->assertSame( 4115, $data['lines'][0]['unit_price'], '12.345 divided by 3 is exactly 4.115.' );
		$this->assertSame( 12345, $data['lines'][0]['line_total'] );
	}

	public function test_yen_has_no_decimals(): void {
		$this->order( 20002, array( 'currency' => 'JPY', 'total' => '15000', 'shipping_total' => '0', 'total_tax' => '0', 'subtotal' => '15000' ) );
		$this->assertSame( 15000, $this->reader()->read( EntityType::Order, '20002' )->get( 'order.total' ) );
	}

	public function test_a_unit_price_that_does_not_divide_evenly_rounds_to_the_nearest_minor_unit(): void {
		$this->order( 20003, array(), array( 'line_item' => array( $this->line( $this->product( 602 ), array( 'quantity' => 3, 'subtotal' => '10.00', 'total' => '10.00' ) ) ) ) );
		$this->assertSame( array( 333 ), $this->reader()->read( EntityType::Order, '20003' )->get( 'lines[].unit_price' ) );
		$this->order( 20004, array(), array( 'line_item' => array( $this->line( $this->product( 603 ), array( 'quantity' => 0.5, 'subtotal' => '3.00', 'total' => '3.00' ) ) ) ) );
		$this->assertSame( array( 600 ), $this->reader()->read( EntityType::Order, '20004' )->get( 'lines[].unit_price' ) );
		$this->assertSame( array( 0.5 ), $this->reader()->read( EntityType::Order, '20004' )->get( 'lines[].quantity' ) );
	}

	public function test_lines_of_excluded_products_are_left_out(): void {
		$gift = $this->product( 700, array( 'sku' => 'GIFT' ), array( ProductMeta::EXCLUDE => 'yes' ) );
		$bowl = $this->product( 701, array( 'sku' => 'CS-BOWL-02' ) );
		$this->order( 20005, array(), array( 'line_item' => array( $this->line( $gift ), $this->line( $bowl ) ) ) );
		$this->assertSame( array( 'CS-BOWL-02' ), $this->reader()->read( EntityType::Order, '20005' )->get( 'lines[].sku' ) );
	}

	public function test_a_line_whose_product_was_deleted_still_reads(): void {
		$this->order( 20006, array(), array( 'line_item' => array( $this->line( null, array( 'name' => 'Old product', 'product_id' => 999 ) ) ) ) );
		$data = $this->reader()->read( EntityType::Order, '20006' )->toArray();
		$this->assertSame( '', $data['lines'][0]['sku'] );
		$this->assertSame( 'Old product', $data['lines'][0]['name'] );
	}

	public function test_a_line_with_no_tax_rate_reads_as_null(): void {
		$this->order( 20007, array(), array( 'line_item' => array( $this->line( $this->product( 604 ) ) ) ) );
		$this->assertSame( array( null ), $this->reader()->read( EntityType::Order, '20007' )->get( 'lines[].tax_rate' ) );
	}

	public function test_compound_rates_on_a_line_are_added(): void {
		$tax1 = new \WC_Order_Item_Tax( array( 'rate_id' => 1, 'rate_percent' => 5, 'tax_total' => '0.50', 'shipping_tax_total' => '0' ) );
		$tax2 = new \WC_Order_Item_Tax( array( 'rate_id' => 2, 'rate_percent' => 2.5, 'tax_total' => '0.25', 'shipping_tax_total' => '0' ) );
		$line = $this->line( $this->product( 605 ), array( 'taxes' => array( 'total' => array( 1 => '0.50', 2 => '0.25' ) ) ) );
		$this->order( 20008, array(), array( 'line_item' => array( $line ), 'tax' => array( $tax1, $tax2 ) ) );
		$this->assertSame( array( 7.5 ), $this->reader()->read( EntityType::Order, '20008' )->get( 'lines[].tax_rate' ) );
	}

	public function test_no_card_data_is_ever_read_only_transaction_id_and_last4(): void {
		$this->order(
			20009,
			array( 'transaction_id' => 'pi_3Abc' ),
			array(),
			array(
				'_card_last4'   => '4242',
				'_card_number'  => '4242424242424242',
				'_card_cvc'     => '123',
				'_card_expiry'  => '12/30',
			)
		);
		$data = $this->reader()->read( EntityType::Order, '20009' )->toArray();
		$this->assertSame( 'pi_3Abc', $data['order']['payment']['transaction_id'] );
		$this->assertSame( '4242', $data['order']['payment']['last4'] );
		$json = (string) wp_json_encode( $data );
		foreach ( array( '4242424242424242', '"123"', '12\/30', 'cvc', '_card_number' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json, $secret );
		}
	}

	public function test_last4_never_returns_more_than_four_digits(): void {
		$this->order( 20010, array(), array(), array( '_card_last4' => '4242 4242 4242 4242' ) );
		$this->assertSame( '4242', $this->reader()->read( EntityType::Order, '20010' )->get( 'order.payment.last4' ) );
		$this->order( 20011, array(), array(), array( '_card_last4' => '42' ) );
		$this->assertSame( '', $this->reader()->read( EntityType::Order, '20011' )->get( 'order.payment.last4' ) );
	}

	public function test_the_buyer_tax_number_is_found_in_known_meta_keys(): void {
		$this->order( 20012, array(), array(), array( '_billing_vat_number' => ' 300000000000003 ' ) );
		$this->assertSame( '300000000000003', $this->reader()->read( EntityType::Order, '20012' )->get( 'order.buyer_tax_number' ) );
		$this->order( 20013 );
		$this->assertSame( '', $this->reader()->read( EntityType::Order, '20013' )->get( 'order.buyer_tax_number' ) );
	}

	/**
	 * @dataProvider typedTaxNumbers
	 */
	public function test_the_buyer_tax_number_is_stored_in_the_plain_form_that_is_validated( string $typed, string $plain ): void {
		$this->order( 20012, array(), array(), array( '_billing_vat_number' => $typed ) );
		$this->assertSame( $plain, $this->reader()->read( EntityType::Order, '20012' )->get( 'order.buyer_tax_number' ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function typedTaxNumbers(): array {
		return array(
			'spaces'                 => array( '300 1234 5678 9003', '300123456789003' ),
			'dashes'                 => array( '300-123456789-003', '300123456789003' ),
			'dots and slashes'       => array( '300.123456789/003', '300123456789003' ),
			'arabic-indic digits'    => array( '٣٠٠١٢٣٤٥٦٧٨٩٠٠٣', '300123456789003' ),
			'persian digits'         => array( '۳۰۰۱۲۳۴۵۶۷۸۹۰۰۳', '300123456789003' ),
			'mixed and spaced'       => array( "\u{200F}٣٠٠ 123456789 ٠٠٣\u{200E}", '300123456789003' ),
			'no-break space'         => array( "300\u{00A0}123456789\u{00A0}003", '300123456789003' ),
			'letters are upper case' => array( 'de 123 456 789', 'DE123456789' ),
			'nothing but separators' => array( ' - . ', '' ),
		);
	}

	public function test_a_blank_first_tax_number_key_does_not_hide_the_next_one(): void {
		$this->order( 20012, array(), array(), array( '_billing_vat_number' => ' - ', '_billing_tax_number' => '٣٠٠ ١٢٣٤٥٦٧٨٩ ٠٠٣' ) );
		$this->assertSame( '300123456789003', $this->reader()->read( EntityType::Order, '20012' )->get( 'order.buyer_tax_number' ) );
	}

	public function test_what_is_validated_is_what_is_sent(): void {
		$this->order( 20012, array( 'billing_country' => 'SA', 'billing_company' => 'شركة الأمل' ), array(), array( '_billing_vat_number' => '٣٠٠-١٢٣٤٥٦٧٨٩-٠٠٣' ) );
		$order    = $this->reader()->read( EntityType::Order, '20012' );
		$failure  = ( new \Estasoft\SyncEngine\Mapping\BuyerTaxRules() )->check( ( new \Estasoft\SyncEngine\Mapping\BuyerTaxRules() )->defaults(), $order );
		$this->assertNull( $failure, 'A 15-digit number typed with Arabic digits and dashes passes.' );
		$this->assertSame( '300123456789003', $order->get( 'order.buyer_tax_number' ), 'The same plain number is the one in the payload.' );
	}

	public function test_requested_meta_keys_are_exposed_and_others_are_not(): void {
		$this->order( 20014, array(), array(), array( '_gift_message' => 'Happy birthday نور', '_secret' => 'x', 'bad key!' => 'y', '_array' => array( 'a' ), '_flag' => true ) );
		$data = $this->reader()->read( EntityType::Order, '20014', array( '_gift_message', 'missing', 'bad key!', '_array', '_flag' ) )->toArray();
		$this->assertSame( array( '_gift_message' => 'Happy birthday نور', 'missing' => '', '_flag' => 'true' ), $data['meta'] );
		$this->assertTrue( ( new CanonicalFields() )->has( 'meta._gift_message' ) );
		$this->assertArrayNotHasKey( 'meta', $this->reader()->read( EntityType::Order, '20014' )->toArray() );
	}

	public function test_meta_keys_that_hold_payment_or_visitor_data_are_never_returned_even_if_asked_for(): void {
		$this->order(
			20015,
			array(),
			array(),
			array(
				'_gift_message'        => 'Happy birthday',
				'_payment_token'       => 'tok_123',
				'_stripe_source_id'    => 'src_1',
				'_card_last4'          => '4242',
				'_customer_ip_address' => '203.0.113.9',
				'_transaction_nonce'   => 'abc',
				'_api_key'             => 'k',
				'_paypal_payer_id'     => 'p',
			)
		);
		$asked = array( '_gift_message', '_payment_token', '_stripe_source_id', '_card_last4', '_customer_ip_address', '_transaction_nonce', '_api_key', '_paypal_payer_id' );
		$data  = $this->reader()->read( EntityType::Order, '20015', $asked )->toArray();
		$this->assertSame( array( '_gift_message' => 'Happy birthday' ), $data['meta'] );
	}

	public function test_a_key_without_a_sensitive_word_is_returned(): void {
		$this->order( 20016, array(), array(), array( '_loyalty_pin' => '1234', '_delivery_slot' => 'Morning' ) );
		$data = $this->reader()->read( EntityType::Order, '20016', array( '_loyalty_pin', '_delivery_slot' ) )->toArray();
		$this->assertSame( array( '_loyalty_pin' => '1234', '_delivery_slot' => 'Morning' ), $data['meta'], 'Only the built-in word list applies unless a site widens it with the filter.' );
	}

	public function test_the_totals_of_excluded_lines_are_reported_so_a_total_in_the_other_system_can_be_worked_out(): void {
		$gift = $this->product( 700, array( 'sku' => 'GIFT' ), array( ProductMeta::EXCLUDE => 'yes' ) );
		$bowl = $this->product( 701, array( 'sku' => 'CS-BOWL-02' ) );
		$this->order(
			20017,
			array( 'total' => '131.25', 'total_tax' => '6.25', 'shipping_total' => '0.00' ),
			array(
				'line_item' => array(
					$this->line( $gift, array( 'total' => '25.00', 'total_tax' => '1.25' ) ),
					$this->line( $bowl, array( 'total' => '100.00', 'total_tax' => '5.00' ) ),
				),
			)
		);
		$order = $this->reader()->read( EntityType::Order, '20017' );
		$this->assertSame( 13125, $order->get( 'order.total' ), 'The WooCommerce total is not changed.' );
		$this->assertSame( 1, $order->get( 'order.excluded_count' ) );
		$this->assertSame( 2625, $order->get( 'order.excluded_total' ), 'Total of the left-out lines, with their tax.' );
		$this->assertSame( 125, $order->get( 'order.excluded_tax' ) );
		$this->assertSame( array( 'CS-BOWL-02' ), $order->get( 'lines[].sku' ) );
	}

	public function test_an_order_without_excluded_products_reports_zero(): void {
		$this->sample();
		$order = $this->reader()->read( EntityType::Order, '10490' );
		$this->assertSame( array( 0, 0, 0 ), array( $order->get( 'order.excluded_count' ), $order->get( 'order.excluded_total' ), $order->get( 'order.excluded_tax' ) ) );
	}

	public function test_a_missing_order_or_a_refund_id_is_not_found(): void {
		$this->expectException( SourceNotFoundException::class );
		$this->reader()->read( EntityType::Order, '999' );
	}

	public function test_a_refund_is_the_parent_order_plus_a_refund_section(): void {
		$bowl   = $this->product( 501, array( 'sku' => 'CS-BOWL-02' ) );
		$parent = $this->order( 10490, array(), array( 'line_item' => array( $this->line( $bowl, array( 'quantity' => 2, 'subtotal' => '438.00', 'total' => '438.00' ) ) ) ) );
		$refund = new \WC_Order_Refund(
			array(
				'id'           => 10500,
				'parent_id'    => 10490,
				'amount'       => '219.00',
				'reason'       => 'Damaged in delivery',
				'date_created' => new \DateTimeImmutable( '2026-11-03 09:00:00', new \DateTimeZone( 'UTC' ) ),
			)
		);
		$refund->items = array( 'line_item' => array( $this->line( $bowl, array( 'quantity' => -1, 'total' => '-219.00' ) ) ) );
		WooRegistry::$orders[10500] = $refund;
		$parent->refunds            = array( $refund );

		foreach ( array( '10500', '10490' ) as $id ) {
			$data = $this->reader()->read( EntityType::Refund, $id )->toArray();
			$this->assertSame( 'WC-10490', $data['order']['client_ref'], 'The order it belongs to.' );
			$this->assertSame(
				array(
					'id'         => 10500,
					'order_id'   => 10490,
					'amount'     => 21900,
					'reason'     => 'Damaged in delivery',
					'created_at' => '2026-11-03T09:00:00Z',
					'lines'      => array( array( 'sku' => 'CS-BOWL-02', 'quantity' => 1, 'amount' => 21900, 'tax' => 0 ) ),
					'shipping_amount' => 0,
					'excluded_amount' => 0,
					'other_amount'    => 0,
				),
				$data['refund'],
				'Refund ' . $id . ': amounts are positive.'
			);
		}
	}

	/**
	 * A refund with the given items, registered under its parent.
	 *
	 * @param string                          $amount Refund amount (positive, as WooCommerce reports it).
	 * @param array<string,array<int,object>> $items  Items by type.
	 */
	private function refundOf( string $amount, array $items ): \WC_Order_Refund {
		$parent = $this->order( 10490, array( 'currency' => 'AED' ), array( 'line_item' => array( $this->line( $this->product( 501, array( 'sku' => 'CS-BOWL-02' ) ) ) ) ) );
		$refund = new \WC_Order_Refund( array( 'id' => 10500, 'parent_id' => 10490, 'amount' => $amount, 'reason' => '' ) );
		$refund->items              = $items;
		WooRegistry::$orders[10500] = $refund;
		$parent->refunds            = array( $refund );
		return $refund;
	}

	public function test_a_shipping_only_refund_has_no_lines_but_says_where_the_amount_went(): void {
		$this->refundOf( '26.25', array( 'shipping' => array( new \WC_Order_Item_Shipping( array( 'total' => '-25.00', 'total_tax' => '-1.25' ) ) ) ) );
		$refund = $this->reader()->read( EntityType::Refund, '10500' )->toArray()['refund'];
		$this->assertSame( 2625, $refund['amount'] );
		$this->assertSame( array(), $refund['lines'] );
		$this->assertSame( 2625, $refund['shipping_amount'] );
		$this->assertSame( 0, $refund['excluded_amount'] );
		$this->assertSame( 0, $refund['other_amount'] );
	}

	public function test_refund_lines_of_excluded_products_are_dropped_and_counted_separately(): void {
		$gift = $this->product( 700, array( 'sku' => 'GIFT' ), array( ProductMeta::EXCLUDE => 'yes' ) );
		$bowl = $this->product( 501, array( 'sku' => 'CS-BOWL-02' ) );
		$this->refundOf(
			'157.50',
			array(
				'line_item' => array(
					$this->line( $gift, array( 'quantity' => -1, 'total' => '-25.00', 'total_tax' => '-1.25' ) ),
					$this->line( $bowl, array( 'quantity' => -1, 'total' => '-100.00', 'total_tax' => '-5.00' ) ),
				),
				'shipping'  => array( new \WC_Order_Item_Shipping( array( 'total' => '-25.00', 'total_tax' => '-1.25' ) ) ),
			)
		);
		$refund = $this->reader()->read( EntityType::Refund, '10500' )->toArray()['refund'];
		$this->assertSame( array( array( 'sku' => 'CS-BOWL-02', 'quantity' => 1, 'amount' => 10000, 'tax' => 500 ) ), $refund['lines'], 'No credit note line for the excluded product.' );
		$this->assertSame( 2625, $refund['excluded_amount'] );
		$this->assertSame( 2625, $refund['shipping_amount'] );
		$this->assertSame( 0, $refund['other_amount'] );
		$this->assertSame( 15750, $refund['amount'] );
	}

	public function test_a_manual_refund_amount_with_no_items_is_other_amount(): void {
		$this->refundOf( '40.00', array() );
		$refund = $this->reader()->read( EntityType::Refund, '10500' )->toArray()['refund'];
		$this->assertSame( array( 4000, 0, 0, 0 ), array( $refund['other_amount'], $refund['shipping_amount'], $refund['excluded_amount'], count( $refund['lines'] ) ) );
	}

	public function test_every_refund_field_the_mapping_screen_offers_is_present_in_a_read_refund(): void {
		$bowl = $this->product( 501, array( 'sku' => 'CS-BOWL-02' ) );
		$this->refundOf( '105.00', array( 'line_item' => array( $this->line( $bowl, array( 'quantity' => -1, 'total' => '-100.00', 'total_tax' => '-5.00' ) ) ) ) );
		$data = $this->reader()->read( EntityType::Refund, '10500' )->toArray();
		foreach ( ( new CanonicalFields() )->all() as $field ) {
			if ( 0 !== strpos( $field['path'], 'refund.' ) ) {
				continue;
			}
			$node = $data['refund'];
			foreach ( explode( '.', substr( $field['path'], 7 ) ) as $segment ) {
				$isList = '[]' === substr( $segment, -2 );
				$key    = $isList ? substr( $segment, 0, -2 ) : $segment;
				$this->assertTrue( is_array( $node ) && array_key_exists( $key, $node ), $field['path'] . ' is missing from the canonical refund.' );
				$node = $isList ? ( $node[ $key ][0] ?? array() ) : $node[ $key ];
			}
		}
	}

	public function test_an_order_without_refunds_has_no_refund_to_read(): void {
		$this->order( 10491 );
		$this->expectException( SourceNotFoundException::class );
		$this->reader()->read( EntityType::Refund, '10491' );
	}

	public function test_a_customer_is_read_from_the_customer_object(): void {
		\WC_Customer::$known[42] = array(
			'id'                 => 42,
			'billing_email'      => 'layla@example.com',
			'billing_first_name' => 'ليلى',
			'billing_last_name'  => 'حداد',
			'billing_phone'      => '055 123 4567',
			'billing_country'    => 'SA',
			'shipping_city'      => 'Riyadh',
		);
		$data = $this->reader()->read( EntityType::Customer, '42' )->toArray();
		$this->assertSame( 42, $data['customer']['id'] );
		$this->assertFalse( $data['customer']['is_guest'] );
		$this->assertSame( 'ليلى', $data['customer']['first_name'] );
		$this->assertSame( 'SA', $data['billing']['country'] );
		$this->assertSame( 'Riyadh', $data['shipping']['city'] );
		$this->assertArrayNotHasKey( 'order', $data );
	}

	public function test_an_unknown_customer_is_not_found(): void {
		$this->expectException( SourceNotFoundException::class );
		$this->reader()->read( EntityType::Customer, '404' );
	}

	public function test_other_entity_types_are_not_read_from_woocommerce(): void {
		$this->expectException( SourceNotFoundException::class );
		$this->reader()->read( EntityType::Stock, 'SKU' );
	}

	public function test_every_canonical_field_the_mapping_screen_offers_is_present_in_a_read_order(): void {
		$this->sample();
		$data = $this->reader()->read( EntityType::Order, '10490' )->toArray();
		foreach ( ( new CanonicalFields() )->all() as $field ) {
			if ( 0 === strpos( $field['path'], 'refund.' ) ) {
				continue;
			}
			$node = $data;
			foreach ( explode( '.', $field['path'] ) as $segment ) {
				$isList  = '[]' === substr( $segment, -2 );
				$key     = $isList ? substr( $segment, 0, -2 ) : $segment;
				$present = is_array( $node ) && array_key_exists( $key, $node );
				$this->assertTrue( $present, $field['path'] . ' is missing from the canonical order.' );
				$node = $isList ? ( $node[ $key ][0] ?? array() ) : $node[ $key ];
				if ( $isList && array() === $node ) {
					break;
				}
			}
		}
	}
}
