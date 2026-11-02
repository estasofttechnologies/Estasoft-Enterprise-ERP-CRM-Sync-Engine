<?php
/**
 * The version 1 Wafeq rules, run by the mapping engine on the sample order, then sent to Wafeq.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Wafeq;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleValidator;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class WafeqMappingIntegrationTest extends WafeqTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( RuleEngine::class ) || ! class_exists( Orders::class ) ) {
			$this->markTestSkipped( 'The mapping engine is not built yet.' );
		}
	}

	private function environment(): MappingEnvironment {
		$taxes = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'AE' === $country && '5' === $rateKey ? 'tax_5' : null;
			}
		};
		$products = new class() implements ProductResolver {
			public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
				return 'CS-BOWL-02' === trim( $sku ) ? new ExternalRef( 'itm_bowl', 'CS-BOWL-02' ) : null;
			}
		};
		return new MappingEnvironment( 1, 'Wafeq', $taxes, $products );
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
	 * @return array<int,array<string,string>>
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
		$this->assertSame( '10490', $payload['number'] );
		$this->assertSame( 'Gift wrap please', $payload['note'] );
		$this->assertSame( 'Noor@Example.com', $payload['customer']['email'] );
		$this->assertSame( 'نور المنصوري', $payload['customer']['name'], 'Arabic stays Arabic.' );
		$this->assertSame( '+971501234567', $payload['customer']['phone'] );
		$this->assertSame( 'AE', $payload['customer']['country'] );
		$this->assertSame( 'itm_bowl', $payload['lines'][0]['item'] );
		$this->assertSame( 'CS-BOWL-02', $payload['lines'][0]['sku'] );
		$this->assertSame( 43800, $payload['lines'][0]['total'] );
		$this->assertSame( 'tax_5', $payload['lines'][0]['tax_code'] );
		$this->assertSame( 2500, $payload['shipping']['total'] );
		$this->assertSame( 48615, $payload['total'] );
		$this->assertArrayNotHasKey( 'flow', $payload, 'The document flow is a choice of the merchant, not of the default rules.' );
	}

	public function test_the_mapped_order_is_accepted_by_wafeq_with_the_shop_s_total(): void {
		$payload                         = $this->mapped();
		$payload['shipping']['tax_code'] = 'tax_5';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 48615, $result->ref->extra['total_minor'], 'Wafeq and the shop agree to the minor unit.' );
		$contact = $this->server->all( 'contacts' )[0];
		$this->assertSame( 'noor@example.com', $contact['email'] );
		$this->assertSame( 'Sheikh Zayed Road', $contact['address'] );
	}

	public function test_a_paid_order_carries_its_payment_date_and_reference(): void {
		$payload = $this->mapped( array( 'order' => array( 'paid_at' => '2026-11-02T08:00:00Z', 'payment' => array( 'transaction_id' => ' ch_3Pabc ' ) ) ) );
		$this->assertSame( '2026-11-02', $payload['payment']['date'] );
		$this->assertSame( 'ch_3Pabc', $payload['payment']['reference'] );
	}

	public function test_an_order_with_a_coupon_carries_the_discounted_line_total(): void {
		$order   = array(
			'lines' => array( array( 'line_total' => 40000, 'line_tax' => 2000 ) ),
			'order' => array( 'discount_total' => 3800, 'total' => 44625, 'subtotal' => 43800 ),
		);
		$payload = $this->mapped( $order );
		$this->assertSame( 40000, $payload['lines'][0]['total'], 'The coupon is already inside the line total.' );
	}
}
