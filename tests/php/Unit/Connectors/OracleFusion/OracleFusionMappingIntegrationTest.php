<?php
/**
 * The version 1 Oracle Fusion rules, run by the mapping engine on the sample order, then sent to the fake pod.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\OracleFusion;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class OracleFusionMappingIntegrationTest extends OracleFusionTestCase {

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
		$output    = $engine->apply( $this->connector->defaultMapping( $type ), new CanonicalOrder( Orders::dubai() ), new MappingEnvironment( 1, 'Oracle Fusion', $taxes, $products ) );
		$this->assertTrue( $output->isValid(), json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		return $output->payload;
	}

	public function test_the_default_rules_name_the_item_and_send_no_buyer_and_no_tax_code(): void {
		$payload = $this->mapped( 'orders' );
		$this->assertSame( 'AED', $payload['currency'] );
		$this->assertSame( 'CS-BOWL-02', $payload['order_line'][0]['product'], 'The SKU match gives the item number.' );
		$this->assertSame( 21900, $payload['order_line'][0]['price_unit'] );
		$this->assertArrayNotHasKey( 'partner', $payload );
		$this->assertArrayNotHasKey( 'tax', $payload['order_line'][0] );
		$this->assertSame( 2500, $payload['shipping']['total'] );
		$this->assertSame( 48615, $payload['total'] );
	}

	public function test_the_sample_order_is_accepted_when_fusion_adds_the_same_tax_as_the_shop(): void {
		$this->server->taxRate = 5.0;
		$result                = $this->connector->send( $this->connection(), $this->item( '10490' ), $this->mapped( 'orders' ) );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 48615, $result->ref->extra['total_minor'] );
		$body = json_decode( (string) $this->calls( 'POST', '/receivablesInvoices' )[0]['body'], true );
		$this->assertSame( 'WC-10490', $body['PurchaseOrder'] );
		$this->assertEquals( 25, $body['FreightAmount'], 'Shipping goes in the freight amount.' );
		$this->assertSame( '[CS-BOWL-02] Stoneware bowl set', $body['receivablesInvoiceLines'][0]['Description'] );
		$this->assertEquals( 219, $body['receivablesInvoiceLines'][0]['UnitSellingPrice'] );
		$this->assertCount( 1, $body['receivablesInvoiceLines'], 'A zero discount adds no line.' );
	}

	public function test_the_sample_order_stops_when_fusion_works_out_no_tax_and_the_message_names_both_totals(): void {
		$result = $this->connector->send( $this->connection(), $this->item( '10490' ), $this->mapped( 'orders' ) );
		$this->assertFalse( $result->isSuccess() );
		$context = json_encode( $result->error->context );
		$this->assertStringContainsString( '463.00', $context, 'What Fusion shows.' );
		$this->assertStringContainsString( '486.15', $context, 'What the shop charged.' );
		$this->assertCount( 1, $this->server->all( 'receivablesInvoices' ), 'The invoice stays for a person.' );
	}

	public function test_the_default_rules_of_every_type_are_valid_for_the_catalog(): void {
		foreach ( array( 'orders', 'refunds', 'products' ) as $type ) {
			$this->assertNotEmpty( $this->connector->defaultMapping( $type ), $type );
			$this->assertNotEmpty( $this->connector->describeFields( $type ), $type );
		}
		$names = array_map( static fn( array $field ): string => (string) $field['path'], $this->connector->describeFields( 'orders' ) );
		$this->assertContains( 'bill_to_customer_number', $names );
		$this->assertContains( 'order_line[].product', $names );
		$this->assertNotContains( 'partner.email', $names, 'No buyer fields are offered for a system that takes no customers.' );
	}
}
