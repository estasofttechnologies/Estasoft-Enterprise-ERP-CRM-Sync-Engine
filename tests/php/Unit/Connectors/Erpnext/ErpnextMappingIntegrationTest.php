<?php
/**
 * The version 1 ERPNext rules, run by the mapping engine on the sample order, then sent to ERPNext.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Erpnext;

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

final class ErpnextMappingIntegrationTest extends ErpnextTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( RuleEngine::class ) || ! class_exists( Orders::class ) ) {
			$this->markTestSkipped( 'The mapping engine is not built yet.' );
		}
	}

	private function environment(): MappingEnvironment {
		$taxes    = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'AE' === $country && '5' === $rateKey ? 'UAE VAT 5% - CS' : null;
			}
		};
		$products = new class() implements ProductResolver {
			public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
				return 'CS-BOWL-02' === trim( $sku ) ? new ExternalRef( 'CS-BOWL-02', 'CS-BOWL-02' ) : null;
			}
		};
		return new MappingEnvironment( 1, 'ERPNext', $taxes, $products );
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
		$this->assertSame( 'Noor@Example.com', $payload['partner']['email'] );
		$this->assertSame( 'نور المنصوري', $payload['partner']['name'] );
		$this->assertSame( 'AE', $payload['partner']['country'] );
		$this->assertSame( 'CS-BOWL-02', $payload['order_line'][0]['product'] );
		$this->assertSame( 'UAE VAT 5% - CS', $payload['order_line'][0]['tax'] );
		$this->assertSame( 2500, $payload['shipping']['total'] );
		$this->assertSame( 48615, $payload['total'] );
	}

	public function test_the_mapped_order_is_accepted_with_the_boards_total(): void {
		$this->server->put( 'Item', array( 'name' => 'SHIP', 'item_code' => 'SHIP', 'item_name' => 'Shipping', 'is_stock_item' => 0, 'disabled' => 0 ) );
		$payload = $this->mapped();
		$result  = $this->connector()->send( $this->connection( array( 'shipping_item' => 'SHIP' ) ), $this->item( EntityType::Order, '10490' ), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . ' ' . (string) ( $result->error->context['reason'] ?? '' ) );
		$this->assertSame( 48615, $result->ref->extra['total_minor'], 'AED 486.15, as on the preview board.' );
		$this->assertSame( 'نور المنصوري', $this->server->all( 'Customer' )[0]['customer_name'] );
		$this->assertSame( 'Gift wrap please', $this->server->all( 'Comment' )[0]['content'] );
	}

	public function test_every_default_rule_validates_against_the_connectors_own_fields(): void {
		$connector = $this->connector();
		$validator = new RuleValidator( TransformRegistry::standard() );
		foreach ( array( 'orders', 'customers', 'products', 'refunds' ) as $type ) {
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
