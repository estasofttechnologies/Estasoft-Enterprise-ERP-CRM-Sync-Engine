<?php
/**
 * Path access on the canonical model.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping;

use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;
use PHPUnit\Framework\TestCase;

final class CanonicalOrderTest extends TestCase {

	private function order(): CanonicalOrder {
		$data                    = Orders::dubai();
		$data['lines'][]         = array(
			'product_id' => 502,
			'sku'        => 'CS-MUG-01',
			'name'       => 'Mug',
			'quantity'   => 1,
			'unit_price' => 4500,
		);
		$data['coupons']         = array( array( 'code' => 'WELCOME', 'discount' => 500 ) );
		return new CanonicalOrder( $data );
	}

	public function test_reads_scalars_by_dotted_path(): void {
		$order = $this->order();
		$this->assertSame( 'AED', $order->get( 'order.currency' ) );
		$this->assertSame( 48615, $order->get( 'order.total' ) );
		$this->assertTrue( $order->get( 'order.payment.is_cod' ) );
		$this->assertSame( 'نور', $order->get( 'billing.first_name' ) );
	}

	public function test_missing_paths_read_as_null(): void {
		$order = $this->order();
		$this->assertNull( $order->get( 'order.nope' ) );
		$this->assertNull( $order->get( 'nothing.at.all' ) );
		$this->assertNull( $order->get( 'order.currency.deeper' ) );
		$this->assertNull( $order->get( '' ) );
		$this->assertNull( $order->get( 'not a path!' ) );
	}

	public function test_fan_out_returns_one_value_per_row(): void {
		$order = $this->order();
		$this->assertSame( array( ' CS-BOWL-02 ', 'CS-MUG-01' ), $order->get( 'lines[].sku' ) );
		$this->assertSame( array( 21900, 4500 ), $order->get( 'lines[].unit_price' ) );
		$this->assertSame( array( 5, null ), $order->get( 'lines[].tax_rate' ), 'A row without the key reads as null, keeping rows aligned.' );
	}

	public function test_fan_out_over_a_missing_list_is_an_empty_list(): void {
		$this->assertSame( array(), $this->order()->get( 'refund.lines[].sku' ) );
		$this->assertSame( array(), $this->order()->get( 'fees[].name' ) );
	}

	public function test_one_row_by_index(): void {
		$order = $this->order();
		$this->assertSame( 'CS-MUG-01', $order->get( 'lines[1].sku' ) );
		$this->assertNull( $order->get( 'lines[7].sku' ) );
		$this->assertSame( 'CS-MUG-01', $order->getRow( 'lines[].sku', 1 ) );
		$this->assertSame( 'AED', $order->getRow( 'order.currency', 1 ), 'A path without fan-out ignores the row.' );
	}

	public function test_has_and_count(): void {
		$order = $this->order();
		$this->assertTrue( $order->has( 'order.currency' ) );
		$this->assertFalse( $order->has( 'order.paid_at' ) );
		$this->assertFalse( $order->has( 'billing.company' ) );
		$this->assertTrue( $order->has( 'lines[].sku' ) );
		$this->assertFalse( $order->has( 'fees[].name' ) );
		$this->assertSame( 2, $order->count( 'lines' ) );
		$this->assertSame( 2, $order->count( 'lines[]' ) );
		$this->assertSame( 0, $order->count( 'fees' ) );
	}

	public function test_with_returns_a_changed_copy_and_leaves_the_original(): void {
		$order = $this->order();
		$copy  = $order->with( 'order.note', 'Changed' )->with( 'meta._gift', 'yes' );
		$this->assertSame( 'Gift wrap please', $order->get( 'order.note' ) );
		$this->assertSame( 'Changed', $copy->get( 'order.note' ) );
		$this->assertSame( 'yes', $copy->get( 'meta._gift' ) );
		$this->assertNull( $order->get( 'meta._gift' ) );
	}

	public function test_to_array_returns_a_copy(): void {
		$order = $this->order();
		$array = $order->toArray();
		$array['order']['total'] = 1;
		$this->assertSame( 48615, $order->get( 'order.total' ) );
	}

	public function test_path_syntax(): void {
		$this->assertTrue( CanonicalOrder::isValidPath( 'lines[].sku' ) );
		$this->assertTrue( CanonicalOrder::isValidPath( 'lines[0].sku' ) );
		$this->assertTrue( CanonicalOrder::isValidPath( 'meta._gift-message' ) );
		$this->assertFalse( CanonicalOrder::isValidPath( 'lines[.sku' ) );
		$this->assertFalse( CanonicalOrder::isValidPath( 'a..b' ) );
		$this->assertFalse( CanonicalOrder::isValidPath( '.a' ) );
		$this->assertFalse( CanonicalOrder::isValidPath( 'a b' ) );
		$this->assertTrue( CanonicalOrder::fansOut( 'lines[].sku' ) );
		$this->assertFalse( CanonicalOrder::fansOut( 'lines[0].sku' ) );
	}

	public function test_nested_lists_are_flattened(): void {
		$order = new CanonicalOrder(
			array(
				'groups' => array(
					array( 'items' => array( array( 'sku' => 'a' ), array( 'sku' => 'b' ) ) ),
					array( 'items' => array( array( 'sku' => 'c' ) ) ),
				),
			)
		);
		$this->assertSame( array( 'a', 'b', 'c' ), $order->get( 'groups[].items[].sku' ) );
	}
}
