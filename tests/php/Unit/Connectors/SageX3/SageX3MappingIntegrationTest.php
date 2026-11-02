<?php
/**
 * The version 1 Sage X3 rules, run by the mapping engine on the sample order, then sent to Sage X3.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageX3;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleValidator;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class SageX3MappingIntegrationTest extends SageX3TestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( RuleEngine::class ) || ! class_exists( Orders::class ) ) {
			$this->markTestSkipped( 'The mapping engine is not built yet.' );
		}
	}

	private function environment(): MappingEnvironment {
		$taxes    = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'AE' === $country && '5' === $rateKey ? 'VAT5' : null;
			}
		};
		$products = new class() implements ProductResolver {
			public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
				return 'CS-BOWL-02' === trim( $sku ) ? new ExternalRef( 'CS-BOWL-02', 'CS-BOWL-02' ) : null;
			}
		};
		return new MappingEnvironment( 1, 'Sage X3', $taxes, $products );
	}

	/**
	 * @param array<string,mixed> $order Overrides of the sample canonical order.
	 * @return array<string,mixed>
	 */
	private function mapped( array $order = array() ): array {
		$engine = new RuleEngine( TransformRegistry::standard() );
		$output = $engine->apply( $this->connector()->defaultMapping( 'orders' ), new CanonicalOrder( Orders::dubai( $order ) ), $this->environment() );
		$this->assertTrue( $output->isValid(), json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		return $output->payload;
	}

	public function test_the_default_rules_build_the_payload_the_connector_reads(): void {
		$payload = $this->mapped();

		$this->assertSame( 'WC-10490', $payload['client_ref'] );
		$this->assertSame( 'AED', $payload['currency'] );
		$this->assertSame( '2026-11-02', $payload['date_order'], 'The shop\'s day, not the UTC time.' );
		$this->assertArrayNotHasKey( 'partner', $payload, 'Every order goes on one customer, so the buyer is not mapped.' );
		$this->assertSame( 'CS-BOWL-02', $payload['order_line'][0]['product'] );
		$this->assertSame( 2500, $payload['shipping']['total'] );
		$this->assertSame( 48615, $payload['total'] );
	}

	public function test_the_sample_order_with_shipping_needs_the_shipping_item_and_then_matches_the_boards_total(): void {
		$this->server->records['ITMMASTER'][] = array( 'ITMREF' => 'SHIPPING' );
		$this->server->vat                    = 0.0;
		$payload                              = $this->mapped();
		$refused                              = $this->connector()->send( $this->connection( array( 'total_property' => 'ORDINVATI' ) ), $this->item( EntityType::Order, '10490' ), $payload );
		$this->assertSame( 'shipping_item', $refused->error->context['field'] );
		$this->assertCount( 0, $this->server->all( 'SORDER' ) );

		$payload['total'] = 46300;
		$result           = $this->connector()->send( $this->connection( array( 'total_property' => 'ORDINVATI', 'shipping_item' => 'SHIPPING' ) ), $this->item( EntityType::Order, '10490' ), $payload );
		$this->assertTrue( $result->isSuccess(), $this->why( $result ) );
		$this->assertSame( 46300, $result->ref->extra['total_minor'], 'Lines 438.00 and shipping 25.00; the tax setup of the folder here adds none.' );
		$this->assertSame( 'WC-10490', $this->server->all( 'SORDER' )[0]['CUSORDREF'] );
		$this->assertSame( array( 'CS-BOWL-02', 'SHIPPING' ), array_column( $this->server->all( 'SORDER' )[0]['SORDERQ'], 'ITMREF' ) );
	}

	public function test_every_default_rule_validates_against_the_connectors_own_fields(): void {
		$connector = $this->connector();
		$validator = new RuleValidator( TransformRegistry::standard() );
		foreach ( array( 'orders', 'products' ) as $type ) {
			$rules = $connector->defaultMapping( $type );
			$paths = array_column( $connector->describeFields( $type ), 'path' );
			$this->assertNotEmpty( $rules, $type );
			foreach ( $rules as $rule ) {
				$this->assertContains( $rule['target'], $paths, $type . ': ' . $rule['id'] );
			}
			$errors = $validator->validate( $rules, $paths );
			$this->assertSame( array(), $errors, $type . ' ' . json_encode( $errors ) );
		}
	}
}
