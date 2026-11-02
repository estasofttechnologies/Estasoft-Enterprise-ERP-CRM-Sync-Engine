<?php
/**
 * Refunds as sales returns, and the reads of Sage 200: customers, products, tax codes and the nightly list.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Sage200;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;

final class Sage200RefundReadTest extends Sage200TestCase {

	/**
	 * Send the order the refunds refer to.
	 */
	private function placeOrder(): void {
		$result = $this->sendOrder();
		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
	}

	/**
	 * Send a refund.
	 *
	 * @param array<string,mixed> $config  Connection config.
	 * @param array<string,mixed> $payload Refund override.
	 * @param string              $id      Refund id.
	 */
	private function sendRefund( array $config = array(), array $payload = array(), string $id = '501' ) {
		return $this->connector()->send( $this->connection( $config ), $this->item( EntityType::Refund, $id ), $this->refund( $payload ) );
	}

	public function test_a_refund_becomes_a_sop_return_for_the_orders_customer_with_the_refund_reference(): void {
		$this->placeOrder();
		$result = $this->sendRefund();

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$returns = $this->server->all( 'sop_returns' );
		$this->assertCount( 1, $returns );
		$this->assertSame( 'WC-501', $returns[0]['customer_document_no'] );
		$this->assertSame( $this->server->all( 'sop_orders' )[0]['customer_id'], $returns[0]['customer_id'] );
		$this->assertSame( 102, $returns[0]['lines'][0]['product_id'] );
		$this->assertSame( 1.0, (float) $returns[0]['lines'][0]['line_quantity'], 'The quantity is positive; a return is its own document.' );
		$this->assertSame( 21.9, (float) $returns[0]['lines'][0]['selling_unit_price'] );
		$this->assertSame( 1, (int) $returns[0]['lines'][0]['tax_code_id'], 'Copied from the line of the original order.' );
		$this->assertSame( 2628, $result->ref->extra['total_minor'] );
		$this->assertSame( 'RET000001', $result->ref->ref );
	}

	public function test_the_same_refund_is_never_made_twice_and_findExisting_knows_it(): void {
		$this->placeOrder();
		$first = $this->sendRefund();
		$again = $this->sendRefund();

		$this->assertTrue( $again->alreadyExisted );
		$this->assertSame( $first->ref->id, $again->ref->id );
		$this->assertCount( 1, $this->server->all( 'sop_returns' ) );
		$this->assertSame( 'RET000001', $this->connector()->findExisting( $this->connection(), $this->item( EntityType::Refund, '501' ) )->ref );
		$this->assertNull( $this->connector()->findExisting( $this->connection(), $this->item( EntityType::Refund, '502' ) ) );
	}

	public function test_a_return_whose_total_differs_is_reported_every_pass(): void {
		$this->placeOrder();
		$first  = $this->sendRefund( array(), array( 'amount' => 2190 ) );
		$second = $this->sendRefund( array(), array( 'amount' => 2190 ) );

		$this->assertSame( ErrorCode::Val002, $first->error->code );
		$this->assertSame( 'total', $first->error->context['field'] );
		$this->assertFalse( $second->isSuccess() );
		$this->assertCount( 1, $this->server->all( 'sop_returns' ) );
	}

	public function test_an_order_that_is_not_in_sage_cannot_be_refunded_there(): void {
		$result = $this->sendRefund();

		$this->assertSame( 'order_ref', $result->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'sop_returns' ) );
	}

	public function test_an_unknown_product_is_map_001(): void {
		$this->placeOrder();
		$result = $this->sendRefund( array(), array( 'refund_line' => array( array( 'product' => 'NO-SUCH', 'quantity' => 1, 'amount' => 100 ) ) ) );

		$this->assertSame( ErrorCode::Map001, $result->error->code );
	}

	public function test_a_refund_without_lines_needs_the_refund_product(): void {
		$this->placeOrder();
		$refused = $this->sendRefund( array(), array( 'refund_line' => array(), 'amount' => 500 ) );
		$this->assertSame( 'refund_line', $refused->error->context['field'] );

		$sent = $this->sendRefund( array( 'refund_product' => 'REFUND' ), array( 'refund_line' => array(), 'amount' => 500 ) );
		$this->assertTrue( $sent->isSuccess(), $this->why( $sent ) );
		$this->assertSame( 106, $this->server->all( 'sop_returns' )[0]['lines'][0]['product_id'] );
		$this->assertSame( 500, $sent->ref->extra['total_minor'] );
	}

	public function test_refunded_shipping_takes_the_tax_out_with_the_rate_of_the_original_line(): void {
		$this->sendOrder(
			array( 'shipping_product' => 'SHIPPING' ),
			array(
				'shipping' => array( 'total' => 500, 'tax' => '1' ),
				'total'    => 5856,
			)
		);
		$result = $this->sendRefund( array( 'shipping_product' => 'SHIPPING' ), array( 'refund_line' => array(), 'shipping_amount' => 600, 'amount' => 600 ) );

		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$line = $this->server->all( 'sop_returns' )[0]['lines'][0];
		$this->assertSame( 103, $line['product_id'] );
		$this->assertSame( 5.0, (float) $line['selling_unit_price'], '6.00 with 20 percent tax is 5.00 without.' );
	}

	public function test_a_refund_that_names_no_order_is_refused(): void {
		$result = $this->sendRefund( array(), array( 'order_ref' => 'bogus' ) );

		$this->assertSame( 'order_ref', $result->error->context['field'] );
	}

	public function test_a_customer_is_found_by_contact_email_and_an_unknown_one_is_null(): void {
		$customer = $this->server->addCustomer( 'ALPHA001', 'Amelia existing', 'amelia@example.test' );
		$ref      = $this->connector()->lookupCustomer( $this->connection(), 'AMELIA@example.test' );

		$this->assertSame( (string) $customer['id'], $ref->id );
		$this->assertSame( 'Amelia existing', $ref->ref );
		$this->assertNull( $this->connector()->lookupCustomer( $this->connection(), 'nobody@example.test' ) );
		$this->assertNull( $this->connector()->lookupCustomer( $this->connection(), '' ) );
	}

	public function test_a_product_is_found_by_code_and_products_are_searched_by_code_or_name(): void {
		$ref = $this->connector()->lookupProduct( $this->connection(), 'cs-mug-01' );
		$this->assertSame( '101', $ref->id );
		$this->assertSame( 'CS-MUG-01', $ref->ref );
		$this->assertNull( $this->connector()->lookupProduct( $this->connection(), 'NO-SUCH' ) );
		$this->assertNull( $this->connector()->lookupProduct( $this->connection(), ' ' ) );

		$found = $this->connector()->searchProducts( $this->connection(), 'mug' );
		$this->assertSame( array( 'CS-MUG-01' ), array_map( static fn( ExternalRef $r ): string => $r->ref, $found ) );
		$this->assertSame( array(), $this->connector()->searchProducts( $this->connection(), '' ) );
	}

	public function test_tax_codes_come_with_their_rate_as_a_percentage(): void {
		$codes = $this->connector()->listTaxes( $this->connection() );

		$this->assertSame( array( '1', '0', '5' ), array_column( $codes, 'code' ) );
		$this->assertSame( array( 20.0, 0.0, 5.0 ), array_column( $codes, 'rate_percent' ) );
		$this->assertSame( 'Standard rate', $codes[0]['label'] );
	}
}
