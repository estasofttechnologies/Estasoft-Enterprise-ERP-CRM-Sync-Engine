<?php
/**
 * The version 1 SAP S/4HANA rules, run by the mapping engine on the sample order, then sent to the fake tenant.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapS4Hana;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class SapS4HanaMappingIntegrationTest extends SapS4HanaTestCase {

	/**
	 * Run the default rules of a mapping type on the sample order.
	 *
	 * @param string $type Mapping type.
	 * @return array<string,mixed>
	 */
	private function mapped( string $type ): array {
		$taxes     = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return null;
			}
		};
		$connector = $this->connector;
		$products  = new class( $connector, $this->connection() ) implements ProductResolver {
			public function __construct( private $connector, private $connection ) {
			}
			public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
				$found = $this->connector->lookupProduct( $this->connection, $sku );
				return $found instanceof ExternalRef ? $found : null;
			}
		};
		$engine    = new RuleEngine( TransformRegistry::standard() );
		$output    = $engine->apply( $this->connector->defaultMapping( $type ), new CanonicalOrder( Orders::dubai() ), new MappingEnvironment( 1, 'SAP S/4HANA', $taxes, $products ) );
		$this->assertTrue( $output->isValid(), json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		return $output->payload;
	}

	public function test_the_default_rules_name_the_material_and_send_no_buyer_and_no_tax_code(): void {
		$payload = $this->mapped( 'orders' );
		$this->assertSame( 'AED', $payload['currency'] );
		$this->assertSame( 'CS-BOWL-02', $payload['order_line'][0]['product'], 'The SKU match gives the material number.' );
		$this->assertSame( 21900, $payload['order_line'][0]['price_unit'] );
		$this->assertArrayNotHasKey( 'partner', $payload );
		$this->assertArrayNotHasKey( 'tax', $payload['order_line'][0] );
		$this->assertSame( 2500, $payload['shipping']['total'] );
	}

	public function test_the_sample_order_with_shipping_and_a_discount_is_refused_by_default_with_the_reason_in_words(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '10490' ), $this->mapped( 'orders' ) );
		$this->assertFalse( $result->isSuccess() );
		$this->assertSame( ErrorCode::Val002, $result->error->code );
		$this->assertStringContainsString( 'pricing procedure', json_encode( $result->error->context ) );
		$this->assertSame( array(), $this->server->all( 'A_SalesOrder' ) );
	}

	public function test_with_extra_charges_left_out_the_sample_order_is_sent_and_the_lines_add_up(): void {
		$result = $this->connector->send( $this->connection( array( 'extra_charges' => 'ignore' ) ), $this->item( '10490' ), $this->mapped( 'orders' ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$order = array_values( $this->server->all( 'A_SalesOrder' ) )[0];
		$this->assertSame( 'WC-10490', $order['PurchaseOrderByCustomer'] );
		$this->assertSame( 'CS-BOWL-02', $order['to_Item'][0]['Material'] );
		$this->assertNotEmpty( $result->ref->extra['warnings'] );
	}

	public function test_the_default_rules_of_every_type_are_valid_for_the_catalog(): void {
		foreach ( array( 'orders', 'refunds', 'products' ) as $type ) {
			$this->assertNotEmpty( $this->connector->defaultMapping( $type ), $type );
			$this->assertNotEmpty( $this->connector->describeFields( $type ), $type );
		}
		$names = array_map( static fn( array $field ): string => (string) $field['path'], $this->connector->describeFields( 'orders' ) );
		$this->assertContains( 'sold_to_party', $names );
		$this->assertContains( 'order_line[].product', $names );
		$this->assertNotContains( 'partner.email', $names, 'No buyer fields are offered for a system that takes no customers.' );
	}
}
