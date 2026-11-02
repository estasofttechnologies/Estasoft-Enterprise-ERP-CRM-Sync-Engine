<?php
/**
 * The version 1 rules of Dynamics 365 Sales, checked by the mapping engine and sent to the fake.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\DynamicsSales;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleValidator;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class DynamicsSalesMappingTest extends DynamicsSalesTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( RuleEngine::class ) || ! class_exists( Orders::class ) ) {
			$this->markTestSkipped( 'The mapping engine is not built yet.' );
		}
		$this->dv->addProduct( 'CS-BOWL-02', array( 'name' => 'Stoneware bowl set' ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_types(): array {
		return array(
			'orders'    => array( 'orders' ),
			'customers' => array( 'customers' ),
			'products'  => array( 'products' ),
			'refunds'   => array( 'refunds' ),
		);
	}

	/**
	 * @dataProvider provide_types
	 */
	public function test_the_version_one_rules_pass_the_mapping_engine_s_own_validator( string $type ): void {
		$errors = ( new RuleValidator( TransformRegistry::standard() ) )->validate( $this->connector->defaultMapping( $type ), $this->connector->describeFields( $type ), true );
		$this->assertSame( array(), array_map( static fn( $error ): string => $error->ruleId . ': ' . $error->message, $errors ), $type );
	}

	public function test_a_crm_needs_no_tax_codes_stock_or_tracking(): void {
		foreach ( array( 'stock', 'tracking' ) as $type ) {
			$this->assertSame( array(), $this->connector->describeFields( $type ) );
		}
		$paths = array_column( $this->connector->describeFields( 'orders' ), 'path' );
		$this->assertNotContains( 'order_line[].tax', $paths );
		$this->assertContains( 'order_line[].tax_amount', $paths );
	}

	public function test_every_required_field_has_a_rule(): void {
		foreach ( array( 'orders', 'customers', 'products', 'refunds' ) as $type ) {
			$targets = array_column( $this->connector->defaultMapping( $type ), 'target' );
			foreach ( $this->connector->describeFields( $type ) as $field ) {
				if ( $field['required'] ) {
					$this->assertContains( $field['path'], $targets, $type . ' ' . $field['path'] );
				}
			}
		}
	}

	/**
	 * @param array<string,mixed> $order Overrides of the sample order.
	 * @return array<string,mixed>
	 */
	private function mapped( string $type, array $order = array() ): array {
		$output = ( new RuleEngine( TransformRegistry::standard() ) )->apply( $this->connector->defaultMapping( $type ), new CanonicalOrder( Orders::dubai( $order ) ), new MappingEnvironment( 1, 'Dynamics 365 Sales' ) );
		$this->assertTrue( $output->isValid(), (string) json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		return $output->payload;
	}

	public function test_the_mapped_order_becomes_a_deal_worth_the_boards_total(): void {
		$payload = $this->mapped( 'orders' );
		$this->assertSame( 'نور', $payload['partner']['first_name'] );
		$this->assertSame( 'المنصوري', $payload['partner']['last_name'] );
		$this->assertSame( 2190, $payload['order_line'][0]['tax_amount'] );

		$result = $this->connector->send( $this->connection, $this->item( '10490' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 48615, $result->ref->extra['total_minor'], 'AED 486.15, as on the preview board.' );
		$lines = $this->linesOfDeal();
		$this->assertCount( 2, $lines, 'The bowls and the shipping.' );
		$this->assertSame( 'Shipping', $lines[1]['productdescription'] );
		$this->assertSame( 'WC-10490 - نور المنصوري', $this->dv->all( 'opportunities' )[0]['name'] );
	}

	public function test_a_discount_and_a_fee_in_the_canonical_order_are_carried(): void {
		$payload = $this->mapped(
			'orders',
			array(
				'order'   => array( 'discount_total' => 5000, 'total' => 44615 ),
				'coupons' => array( array( 'code' => 'SUMMER10', 'discount' => 5000 ) ),
				'fees'    => array( array( 'name' => 'Gift wrap', 'total' => 1000, 'tax' => 50 ) ),
			)
		);
		$result = $this->connector->send( $this->connection( array( 'total_mismatch' => 'warn' ) ), $this->item( '10491' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 50.0, $this->dv->all( 'opportunities' )[0]['discountamount'] );
		$this->assertContains( 'Gift wrap', array_column( $this->linesOfDeal(), 'productdescription' ) );
	}

	public function test_a_mapped_refund_becomes_a_note(): void {
		$this->connector->send( $this->connection, $this->item( '10490' ), $this->mapped( 'orders' ) );
		$refund = array(
			'refund' => array(
				'id'         => 10499,
				'order_id'   => 10490,
				'amount'     => 22995,
				'reason'     => 'Chipped',
				'created_at' => '2026-11-03T09:00:00Z',
				'lines'      => array(),
			),
		);
		$payload = $this->mapped( 'refunds', $refund );
		$this->assertSame( 'WC-10490', $payload['order_ref'] );
		$result = $this->connector->send( $this->connection, $this->item( '10499', EntityType::Refund ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 22995, $result->ref->extra['refunded_minor'] );
	}
}
