<?php
/**
 * The version 1 rules of Business Central, checked by the mapping engine and sent to the fake.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\BusinessCentral;

use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleValidator;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class BusinessCentralMappingTest extends BusinessCentralTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( RuleEngine::class ) || ! class_exists( Orders::class ) ) {
			$this->markTestSkipped( 'The mapping engine is not built yet.' );
		}
		$this->bc->addItem( 'CS-BOWL-02', array( 'displayName' => 'Stoneware bowl set' ) );
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
		$validator = new RuleValidator( TransformRegistry::standard() );
		$errors    = $validator->validate( $this->connector->defaultMapping( $type ), $this->connector->describeFields( $type ), true );
		$this->assertSame( array(), array_map( static fn( $error ): string => $error->ruleId . ': ' . $error->message, $errors ), $type );
	}

	public function test_stock_and_tracking_have_no_fields_and_no_rules(): void {
		foreach ( array( 'stock', 'tracking' ) as $type ) {
			$this->assertSame( array(), $this->connector->describeFields( $type ) );
			$this->assertSame( array(), $this->connector->defaultMapping( $type ) );
		}
	}

	public function test_every_required_field_has_a_rule(): void {
		foreach ( array( 'orders', 'customers', 'products' ) as $type ) {
			$targets = array_column( $this->connector->defaultMapping( $type ), 'target' );
			foreach ( $this->connector->describeFields( $type ) as $field ) {
				if ( $field['required'] ) {
					$this->assertContains( $field['path'], $targets, $type . ' ' . $field['path'] );
				}
			}
		}
	}

	private function environment(): MappingEnvironment {
		$taxes = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'AE' === $country && '5' === $rateKey ? 'VAT5' : null;
			}
		};
		return new MappingEnvironment( 1, 'Business Central', $taxes, null );
	}

	/**
	 * @param array<string,mixed> $order Overrides of the sample order.
	 * @return array<string,mixed>
	 */
	private function mapped( string $type, array $order = array() ): array {
		$engine = new RuleEngine( TransformRegistry::standard() );
		$output = $engine->apply( $this->connector->defaultMapping( $type ), new CanonicalOrder( Orders::dubai( $order ) ), $this->environment() );
		$this->assertTrue( $output->isValid(), (string) json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		return $output->payload;
	}

	public function test_the_default_rules_build_the_payload_the_connector_reads(): void {
		$payload = $this->mapped( 'orders' );
		$this->assertSame( 'AED', $payload['currency'] );
		$this->assertSame( '2026-11-02', $payload['order_date'] );
		$this->assertSame( 'Noor@Example.com', $payload['partner']['email'], 'Trimmed, not changed.' );
		$this->assertSame( 'نور المنصوري', $payload['partner']['name'] );
		$this->assertSame( 'AE', $payload['partner']['country_id'] );
		$this->assertSame( 'CS-BOWL-02', $payload['order_line'][0]['product'], 'The SKU, trimmed.' );
		$this->assertSame( 21900, $payload['order_line'][0]['price_unit'] );
		$this->assertSame( 'VAT5', $payload['order_line'][0]['tax'] );
		$this->assertSame( array( 'VAT5' ), $payload['shipping']['tax'], 'A rule that reads a list writes a list; the connector takes the first.' );
		$this->assertSame( 2500, $payload['shipping']['total'] );
		$this->assertSame( 48615, $payload['total'] );
	}

	public function test_the_mapped_order_is_accepted_with_the_boards_total(): void {
		$connection = $this->connection( array( 'shipping_item' => 'SHIP' ) );
		$result     = $this->connector->send( $connection, $this->orderItem( '10490' ), $this->mapped( 'orders' ) );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 48615, $result->ref->extra['total_minor'], 'AED 486.15, as on the preview board.' );
		$customers = $this->bc->all( 'customers' );
		$this->assertCount( 1, $customers );
		$this->assertSame( 'نور المنصوري', $customers[0]['displayName'] );
		$lines = $this->linesOfOnly( 'salesOrders' );
		$this->assertCount( 4, $lines, 'The bowls, shipping, the note and the marker.' );
		$this->assertSame( 'Gift wrap please', $lines[2]['description'] );
	}

	public function test_a_discount_and_a_fee_in_the_canonical_order_become_lines(): void {
		$connection = $this->connection( array( 'shipping_item' => 'SHIP', 'fee_item' => 'SHIP', 'discount_item' => 'SHIP', 'total_mismatch' => 'warn' ) );
		$payload    = $this->mapped(
			'orders',
			array(
				'order'   => array( 'discount_total' => 5000, 'total' => 44615 ),
				'coupons' => array( array( 'code' => 'SUMMER10', 'discount' => 5000 ) ),
				'fees'    => array( array( 'name' => 'Gift wrap', 'total' => 1000, 'tax' => 50 ) ),
			)
		);
		$this->assertSame( 5000, $payload['discount']['total'] );
		$result = $this->connector->send( $connection, $this->orderItem( '10491' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$names = array_column( $this->linesOfOnly( 'salesOrders' ), 'description' );
		$this->assertContains( 'Gift wrap', $names );
		$this->assertContains( 'SUMMER10', $names );
	}

	public function test_a_mapped_refund_becomes_a_credit_memo_that_matches_the_refund(): void {
		$connection = $this->connection( array( 'shipping_item' => 'SHIP' ) );
		$this->connector->send( $connection, $this->orderItem( '10490' ), $this->mapped( 'orders' ) );
		$refund = array(
			'refund' => array(
				'id'              => 10499,
				'order_id'        => 10490,
				'amount'          => 22995,
				'reason'          => 'Chipped',
				'created_at'      => '2026-11-03T09:00:00Z',
				'lines'           => array( array( 'sku' => 'CS-BOWL-02', 'quantity' => 1, 'amount' => 21900, 'tax' => 1095 ) ),
				'shipping_amount' => 0,
				'other_amount'    => 0,
			),
		);
		$engine = new RuleEngine( TransformRegistry::standard() );
		$output = $engine->apply( $this->connector->defaultMapping( 'refunds' ), new CanonicalOrder( Orders::dubai( $refund ) ), $this->environment() );
		$this->assertTrue( $output->isValid(), (string) json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		$this->assertSame( 'WC-10490', $output->payload['order_ref'] );

		$result = $this->connector->send( $connection, $this->orderItem( '10499', EntityType::Refund ), $output->payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 22995, $result->ref->extra['total_minor'] );
	}

	public function test_the_product_rule_and_the_customer_rules_work_on_their_own(): void {
		$products = $this->mapped( 'products' );
		$this->assertSame( array( 'CS-BOWL-02' ), $products['product']['reference'] );
		$customer = $this->mapped( 'customers' );
		$this->assertSame( 'Noor@Example.com', $customer['partner']['email'] );
	}
}
