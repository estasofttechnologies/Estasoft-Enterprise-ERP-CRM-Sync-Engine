<?php
/**
 * The version 1 NetSuite rules, run by the mapping engine on the sample order, then sent to the fake NetSuite.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\NetSuite;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class NetSuiteMappingIntegrationTest extends NetSuiteTestCase {

	/**
	 * Run the default rules of a mapping type on the sample order.
	 *
	 * @param string              $type  Mapping type.
	 * @param array<string,mixed> $order Overrides of the sample order.
	 * @return array<string,mixed>
	 */
	private function mapped( string $type, array $order = array() ): array {
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
		$output    = $engine->apply( $this->connector->defaultMapping( $type ), new CanonicalOrder( Orders::dubai( $order ) ), new MappingEnvironment( 1, 'NetSuite', $taxes, $products ) );
		$this->assertTrue( $output->isValid(), json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		return $output->payload;
	}

	public function test_the_default_rules_build_the_payload_the_connector_expects(): void {
		$this->server->addItem( 'CS-BOWL-02', 'Stoneware bowl set' );
		$payload = $this->mapped( 'orders' );
		$this->assertSame( 'WC-10490', $payload['client_ref'] );
		$this->assertSame( 'AED', $payload['currency'] );
		$this->assertSame( '2026-11-02 07:34:00', $payload['order_date'] );
		$this->assertSame( 'Noor@Example.com', $payload['partner']['email'] );
		$this->assertSame( 'نور المنصوري', $payload['partner']['name'], 'Arabic stays Arabic.' );
		$this->assertSame( 'AE', $payload['partner']['country'] );
		$this->assertSame( $this->itemId( 'CS-BOWL-02' ), (string) $payload['order_line'][0]['product'], 'The SKU match gives the internal id.' );
		$this->assertSame( 21900, $payload['order_line'][0]['price_unit'] );
		$this->assertSame( 2500, $payload['shipping']['total'] );
		$this->assertSame( 48615, $payload['total'] );
		$this->assertArrayNotHasKey( 'tax', $payload['order_line'][0], 'NetSuite works out tax itself.' );
	}

	public function test_the_mapped_order_is_accepted_when_netsuite_adds_the_same_tax_as_the_shop(): void {
		$payload = $this->mapped( 'orders' );
		// 2 x 219.00 of goods and 25.00 shipping make 463.00; 5% VAT added by NetSuite gives the shop's 486.15.
		$this->server->taxRate = 5.0;
		$ok                    = $this->connector->send( $this->connection(), $this->item( '10490' ), $payload );
		$this->assertTrue( $ok->isSuccess(), (string) $ok->error?->message() );
		$this->assertSame( 48615, $ok->ref->extra['total_minor'] );
		$order = array_values( $this->server->all( 'salesOrder' ) )[0];
		$this->assertSame( 25.0, $order['shippingCost'] );
		$this->assertSame( '2026-11-02', $order['tranDate'] );
	}

	public function test_the_same_order_is_refused_when_netsuite_works_out_no_tax(): void {
		$this->server->taxRate = 0.0;
		$result                = $this->connector->send( $this->connection(), $this->item( '10492' ), $this->mapped( 'orders' ) );
		$this->assertFalse( $result->isSuccess(), 'A legacy-tax account would sync 463.00 for 486.15: the check stops it.' );
		$this->assertStringContainsString( '463.00', json_encode( $result->error->context ) );
		$this->assertStringContainsString( '486.15', json_encode( $result->error->context ) );
	}

	public function test_refund_and_customer_rules_run_on_the_sample_order(): void {
		$customers = $this->mapped( 'customers' );
		$this->assertSame( 'Noor@Example.com', $customers['partner']['email'] );
		$this->assertSame( 'product.reference', array_column( $this->connector->defaultMapping( 'products' ), 'target' )[0] );
		$this->assertContains( 'order_ref', array_column( $this->connector->defaultMapping( 'refunds' ), 'target' ) );
		$this->assertSame( array(), $this->connector->defaultMapping( 'stock' ) );
		$this->assertSame( array(), $this->connector->defaultMapping( 'tracking' ) );
	}

	public function test_the_connector_flags_match_what_it_does(): void {
		$flags = $this->connector->capabilities();
		$this->assertTrue( $flags['orders'] && $flags['customers'] && $flags['refunds'] && $flags['invoice_readback'] && $flags['reconciliation'] && $flags['shadow_lookup'] );
		$this->assertFalse( $flags['stock_in'] || $flags['tracking_in'] || $flags['prices_in'] || $flags['webhooks'] );
		$this->assertEquals( array( EntityType::Order, EntityType::Refund, EntityType::Customer ), $this->connector->supportedEntities() );
		$this->assertSame( array(), $this->connector->listTaxes( $this->connection() ) );
	}
}
