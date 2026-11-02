<?php
/**
 * The version 1 Qoyod rules, run by the mapping engine on the sample order, then sent to Qoyod.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Qoyod;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleValidator;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class QoyodMappingIntegrationTest extends QoyodTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( RuleEngine::class ) || ! class_exists( Orders::class ) ) {
			$this->markTestSkipped( 'The mapping engine is not built yet.' );
		}
		$this->server->tables['products'][5] = array( 'id' => 5, 'sku' => 'CS-BOWL-02', 'name_en' => 'Stoneware bowl set', 'name_ar' => 'طقم أوعية', 'tax_id' => '1', 'track_quantity' => true, 'type' => 'Product', 'inventories' => array() );
	}

	private function environment(): MappingEnvironment {
		$taxes = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'AE' === $country && '5' === $rateKey ? '9' : null;
			}
		};
		$products = new class() implements ProductResolver {
			public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
				return 'CS-BOWL-02' === trim( $sku ) ? new ExternalRef( '5', 'CS-BOWL-02' ) : null;
			}
		};
		return new MappingEnvironment( 1, 'Qoyod', $taxes, $products );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function mapped( array $order = array() ): array {
		$engine = new RuleEngine( TransformRegistry::standard() );
		$output = $engine->apply( $this->connector()->defaultMapping( 'orders' ), new CanonicalOrder( Orders::dubai( $order ) ), $this->environment() );
		$this->assertTrue( $output->isValid(), json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		return $output->payload;
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_types(): array {
		return array( 'orders' => array( 'orders' ), 'customers' => array( 'customers' ), 'products' => array( 'products' ), 'refunds' => array( 'refunds' ) );
	}

	/**
	 * @dataProvider provide_types
	 */
	public function test_the_version_one_rules_pass_the_mapping_engine_s_own_validator( string $type ): void {
		$connector = $this->connector();
		$validator = new RuleValidator( TransformRegistry::standard() );
		$errors    = $validator->validate( $connector->defaultMapping( $type ), $connector->describeFields( $type ), true );
		$this->assertSame( array(), array_map( static fn( $error ): string => $error->ruleId . ': ' . $error->message, $errors ), $type );
	}

	public function test_the_default_rules_build_the_payload_the_connector_expects(): void {
		$payload = $this->mapped();
		$this->assertSame( 'AED', $payload['currency'] );
		$this->assertSame( '2026-11-02', $payload['date'] );
		$this->assertSame( '5', $payload['lines'][0]['item'] );
		$this->assertSame( '9', $payload['lines'][0]['tax_code'] );
		$this->assertSame( 43800, $payload['lines'][0]['total'] );
		$this->assertSame( 48615, $payload['total'] );
		$this->assertSame( 'نور المنصوري', $payload['customer']['name'] );
	}

	public function test_the_mapped_order_is_accepted_by_qoyod_with_the_shop_s_total(): void {
		$payload                         = $this->mapped( array( 'order' => array( 'currency' => 'SAR' ) ) );
		$payload['shipping']['tax_code'] = '9';
		$result                          = $this->connector()->send( $this->connection( array( 'shipping_product_sku' => 'SRV-SHIP' ) ), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 48615, $result->ref->extra['total_minor'], 'Qoyod and the shop agree to the minor unit.' );
	}
}
