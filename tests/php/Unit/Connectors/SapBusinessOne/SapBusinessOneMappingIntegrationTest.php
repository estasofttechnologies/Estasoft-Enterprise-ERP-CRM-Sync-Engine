<?php
/**
 * The version 1 SAP Business One rules, run by the mapping engine on the sample order, then sent to the fake Service Layer.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SapBusinessOne;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class SapBusinessOneMappingIntegrationTest extends SapBusinessOneTestCase {

	/**
	 * Run the default rules of a mapping type on the sample order.
	 *
	 * @param string $type Mapping type.
	 * @return array<string,mixed>
	 */
	private function mapped( string $type ): array {
		$taxes     = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'AE' === $country && '5' === $rateKey ? 'S1' : null;
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
		$output    = $engine->apply( $this->connector->defaultMapping( $type ), new CanonicalOrder( Orders::dubai() ), new MappingEnvironment( 1, 'SAP Business One', $taxes, $products ) );
		$this->assertTrue( $output->isValid(), json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		return $output->payload;
	}

	public function test_the_default_rules_build_the_payload_the_connector_expects(): void {
		$payload = $this->mapped( 'orders' );
		$this->assertSame( 'WC-10490', $payload['client_ref'] );
		$this->assertSame( 'AED', $payload['currency'] );
		$this->assertSame( '2026-11-02 07:34:00', $payload['order_date'] );
		$this->assertSame( 'نور المنصوري', $payload['partner']['name'], 'Arabic stays Arabic.' );
		$this->assertSame( 'CS-BOWL-02', $payload['order_line'][0]['product'], 'The SKU match gives the item code.' );
		$this->assertSame( 21900, $payload['order_line'][0]['price_unit'] );
		$this->assertSame( 'S1', $payload['order_line'][0]['tax'] );
		$this->assertSame( 2500, $payload['shipping']['total'] );
		$this->assertSame( 48615, $payload['total'] );
	}

	public function test_the_mapped_order_is_accepted_when_sap_adds_the_same_tax_as_the_shop(): void {
		$payload = $this->mapped( 'orders' );
		$this->server->addItem( 'SHIP', 'Shipping' );
		$payload['shipping']['tax'] = 'S1';
		$result                     = $this->connector->send( $this->connection( array( 'shipping_item' => 'SHIP' ) ), $this->item( '10490' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 48615, $result->ref->extra['total_minor'], '2 x 219.00 and 25.00 shipping, each with 5% VAT.' );
	}

	public function test_the_stock_rule_reads_the_quantity_the_connector_returns(): void {
		$rules = $this->connector->defaultMapping( 'stock' );
		$this->assertSame( 'quantity', $rules[0]['source'] );
		$this->assertSame( 'product.stock_quantity', $rules[0]['target'] );
		$this->assertContains( 'quantity', array_column( $this->connector->describeFields( 'stock' ), 'path' ) );
		$this->assertSame( array(), $this->connector->defaultMapping( 'tracking' ) );
	}

	public function test_the_connector_flags_match_what_it_does(): void {
		$flags = $this->connector->capabilities();
		$this->assertTrue( $flags['orders'] && $flags['customers'] && $flags['refunds'] && $flags['stock_in'] && $flags['invoice_readback'] && $flags['reconciliation'] && $flags['shadow_lookup'] );
		$this->assertFalse( $flags['tracking_in'] || $flags['prices_in'] || $flags['webhooks'] );
		$this->assertEquals( array( EntityType::Order, EntityType::Refund, EntityType::Customer ), $this->connector->supportedEntities() );
	}
}
