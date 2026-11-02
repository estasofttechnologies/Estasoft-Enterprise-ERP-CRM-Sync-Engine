<?php
/**
 * Reading the mapped order payload.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Microsoft;

use Estasoft\SyncEngine\Connectors\Microsoft\OrderData;
use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Domain\ErrorCode;
use PHPUnit\Framework\TestCase;

final class OrderDataTest extends TestCase {

	/**
	 * @param array<string,mixed> $override Values to replace.
	 * @return array<string,mixed>
	 */
	private function payload( array $override = array() ): array {
		return $override + array(
			'currency'   => 'aed',
			'order_date' => '2026-11-02T01:30:00+04:00',
			'total'      => 6297,
			'partner'    => array( 'email' => ' jane@example.test ', 'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => 12345 ),
			'order_line' => array( array( 'product' => ' CS-MUG-01 ', 'name' => 'Mug', 'quantity' => '3', 'price_unit' => '1999', 'tax' => 'FULL' ) ),
		);
	}

	private function parse( array $payload ): OrderData {
		$order = OrderData::parse( $payload, 'Test system' );
		$this->assertInstanceOf( OrderData::class, $order, $order instanceof ConnectorError ? $order->message() : '' );
		return $order;
	}

	private function refusal( array $payload ): ConnectorError {
		$order = OrderData::parse( $payload, 'Test system' );
		$this->assertInstanceOf( ConnectorError::class, $order );
		$this->assertSame( ErrorCode::Val002, $order->code );
		return $order;
	}

	public function test_a_good_payload_is_read_into_clean_values(): void {
		$order = $this->parse( $this->payload() );
		$this->assertSame( 'AED', $order->currency );
		$this->assertSame( '2026-11-01', $order->orderDate, 'The date is the UTC date.' );
		$this->assertSame( 6297, $order->expectedMinor() );
		$this->assertSame( 'jane@example.test', $order->partner['email'] );
		$this->assertSame( '12345', $order->partner['phone'] );
		$this->assertSame( 'CS-MUG-01', $order->lines[0]->sku );
		$this->assertSame( 3, $order->lines[0]->quantity );
		$this->assertSame( 1999, $order->lines[0]->priceMinor );
		$this->assertSame( 'Jane Doe', $order->buyerName() );
		$this->assertSame( 'Jane Doe', $order->personName() );
	}

	public function test_the_buyer_is_the_company_then_the_name_then_the_email(): void {
		$this->assertSame( 'Acme LLC', $this->parse( $this->payload( array( 'partner' => array( 'company_name' => 'Acme LLC', 'name' => 'Jane', 'email' => 'a@example.test' ) ) ) )->buyerName() );
		$this->assertSame( 'Jane D.', $this->parse( $this->payload( array( 'partner' => array( 'name' => 'Jane D.', 'email' => 'a@example.test' ) ) ) )->buyerName() );
		$this->assertSame( 'a@example.test', $this->parse( $this->payload( array( 'partner' => array( 'email' => 'a@example.test' ) ) ) )->buyerName() );
		$this->assertSame( '', $this->parse( $this->payload( array( 'partner' => array( 'email' => 'a@example.test' ) ) ) )->personName() );
	}

	public function test_what_was_left_out_is_taken_from_the_expected_total(): void {
		$this->assertSame( 5000, $this->parse( $this->payload( array( 'total' => 6297, 'excluded_total' => 1297 ) ) )->expectedMinor() );
		$this->assertSame( 0, $this->parse( $this->payload( array( 'total' => 100, 'excluded_total' => 500 ) ) )->expectedMinor() );
		$this->assertNull( $this->parse( array_diff_key( $this->payload(), array( 'total' => 1 ) ) )->expectedMinor() );
	}

	public function test_a_value_a_rule_wrote_as_a_list_is_read_as_its_first_entry(): void {
		$order = $this->parse(
			$this->payload(
				array(
					'total'    => 6797,
					'shipping' => array( 'name' => array( 'Courier' ), 'total' => 500, 'tax' => array( '', 'VAT5' ), 'tax_amount' => array( 25 ) ),
				)
			)
		);
		$this->assertSame( 'Courier', $order->shipping->name );
		$this->assertSame( 'VAT5', $order->shipping->taxCode );
		$this->assertSame( 25, $order->shipping->taxMinor );
	}

	public function test_zero_shipping_fees_and_discount_are_not_extras(): void {
		$order = $this->parse( $this->payload( array( 'shipping' => array( 'total' => 0 ), 'discount' => array( 'total' => '0' ), 'fee' => array( array( 'total' => 0 ), array( 'name' => 'Wrap', 'total' => 200 ) ) ) ) );
		$this->assertNull( $order->shipping );
		$this->assertNull( $order->discount );
		$this->assertCount( 1, $order->fees );
		$this->assertSame( 'Wrap', $order->fees[0]->name );
	}

	public function test_a_payload_that_is_wrong_on_its_face_is_refused_with_the_field_named(): void {
		$cases = array(
			'currency'       => array( $this->payload( array( 'currency' => 'dirham' ) ), 'currency' ),
			'no currency'    => array( $this->payload( array( 'currency' => '' ) ), 'currency' ),
			'no lines'       => array( $this->payload( array( 'order_line' => array() ) ), 'order_line' ),
			'no sku'         => array( $this->payload( array( 'order_line' => array( array( 'product' => '', 'quantity' => 1, 'price_unit' => 1 ) ) ) ), 'product' ),
			'zero quantity'  => array( $this->payload( array( 'order_line' => array( array( 'product' => 'A', 'quantity' => 0, 'price_unit' => 1 ) ) ) ), 'quantity' ),
			'text quantity'  => array( $this->payload( array( 'order_line' => array( array( 'product' => 'A', 'quantity' => 'many', 'price_unit' => 1 ) ) ) ), 'quantity' ),
			'decimal price'  => array( $this->payload( array( 'order_line' => array( array( 'product' => 'A', 'quantity' => 1, 'price_unit' => 19.99 ) ) ) ), 'price_unit' ),
			'negative price' => array( $this->payload( array( 'order_line' => array( array( 'product' => 'A', 'quantity' => 1, 'price_unit' => -5 ) ) ) ), 'price_unit' ),
			'decimal total'  => array( $this->payload( array( 'total' => 62.97 ) ), 'total' ),
			'text total'     => array( $this->payload( array( 'total' => '62,97' ) ), 'total' ),
			'bad shipping'   => array( $this->payload( array( 'shipping' => array( 'total' => '5.00' ) ) ), 'shipping.total' ),
			'negative fee'   => array( $this->payload( array( 'fee' => array( array( 'total' => -5 ) ) ) ), 'fee[].total' ),
		);
		foreach ( $cases as $name => $case ) {
			$this->assertStringContainsString( $case[1], $this->refusal( $case[0] )->message(), $name );
		}
	}

	public function test_a_fractional_quantity_is_kept(): void {
		$order = $this->parse( $this->payload( array( 'order_line' => array( array( 'product' => 'A', 'quantity' => 1.5, 'price_unit' => 100 ) ) ) ) );
		$this->assertSame( 1.5, $order->lines[0]->quantity );
	}

	public function test_an_unreadable_date_is_empty_and_prices_with_tax_are_a_flag(): void {
		$order = $this->parse( $this->payload( array( 'order_date' => 'next week sometime', 'prices_include_tax' => 'yes' ) ) );
		$this->assertSame( '', $order->orderDate );
		$this->assertTrue( $order->pricesIncludeTax );
		$this->assertFalse( $this->parse( $this->payload() )->pricesIncludeTax );
	}
}
