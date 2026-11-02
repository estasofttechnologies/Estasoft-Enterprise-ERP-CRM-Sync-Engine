<?php
/**
 * The version 1 rules of Finance and Supply Chain, checked by the mapping engine and sent to the fake.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\DynamicsFinance;

use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleValidator;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class DynamicsFinanceMappingTest extends DynamicsFinanceTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( RuleEngine::class ) || ! class_exists( Orders::class ) ) {
			$this->markTestSkipped( 'The mapping engine is not built yet.' );
		}
		$this->fo->addProduct( 'CS-BOWL-02' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_types(): array {
		return array(
			'orders'    => array( 'orders' ),
			'customers' => array( 'customers' ),
			'products'  => array( 'products' ),
		);
	}

	/**
	 * @dataProvider provide_types
	 */
	public function test_the_version_one_rules_pass_the_mapping_engine_s_own_validator( string $type ): void {
		$errors = ( new RuleValidator( TransformRegistry::standard() ) )->validate( $this->connector->defaultMapping( $type ), $this->connector->describeFields( $type ), true );
		$this->assertSame( array(), array_map( static fn( $error ): string => $error->ruleId . ': ' . $error->message, $errors ), $type );
	}

	public function test_what_the_system_cannot_take_has_no_fields_or_rules(): void {
		foreach ( array( 'refunds', 'stock', 'tracking' ) as $type ) {
			$this->assertSame( array(), $this->connector->describeFields( $type ), $type );
			$this->assertSame( array(), $this->connector->defaultMapping( $type ), $type );
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

	/**
	 * @param array<string,mixed> $order Overrides of the sample order.
	 * @return array<string,mixed>
	 */
	private function mapped( string $type, array $order = array() ): array {
		$output = ( new RuleEngine( TransformRegistry::standard() ) )->apply( $this->connector->defaultMapping( $type ), new CanonicalOrder( Orders::dubai( $order ) ), new MappingEnvironment( 1, 'Finance and Supply Chain' ) );
		$this->assertTrue( $output->isValid(), (string) json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		return $output->payload;
	}

	public function test_countries_are_written_with_three_letters_as_the_system_names_them(): void {
		$payload = $this->mapped( 'orders' );
		$this->assertSame( 'ARE', $payload['partner']['country_id'] );
		$this->assertSame( 'ARE', $payload['delivery']['country_id'] );
	}

	public function test_the_mapped_order_becomes_a_sales_order_worth_the_boards_total(): void {
		$connection = $this->connection( array( 'shipping_item' => 'SHIP' ) );
		$payload    = $this->mapped( 'orders' );
		$this->assertSame( 'نور', $payload['partner']['first_name'] );
		$this->assertSame( 'المنصوري', $payload['partner']['last_name'] );

		$result = $this->connector->send( $connection, $this->item( '10490' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 48615, $result->ref->extra['total_minor'], 'AED 486.15, as on the preview board.' );
		$lines = $this->linesOfOrder();
		$this->assertCount( 2, $lines, 'The bowls and the shipping.' );
		$this->assertSame( 'CS-BOWL-02', $lines[0]['ItemNumber'] );
		$this->assertSame( 'WC-10490', $this->fo->all( 'SalesOrderHeadersV2' )[0]['CustomersOrderReference'] );
		$this->assertSame( 'نور', $this->fo->all( 'CustomersV3' )[0]['PersonFirstName'] );
	}

	public function test_the_mapped_customer_is_created_by_the_customer_sync(): void {
		$payload = $this->mapped( 'customers' );
		$result  = $this->connector->send( $this->connection, $this->item( '501', \Estasoft\SyncEngine\Domain\EntityType::Customer ), $payload + array( 'currency' => 'AED' ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 'noor@example.com', strtolower( $this->fo->all( 'CustomersV3' )[0]['PrimaryContactEmail'] ) );
	}

	public function test_the_product_rule_reads_the_sku_that_becomes_the_item_number(): void {
		$payload = $this->mapped( 'products' );
		$this->assertSame( array( 'CS-BOWL-02' ), $payload['product']['reference'] );
		$this->assertNotNull( $this->connector->lookupProduct( $this->connection, $payload['product']['reference'][0] ) );
	}
}
