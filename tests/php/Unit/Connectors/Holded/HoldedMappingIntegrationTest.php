<?php
/**
 * The version 1 Holded rules, run by the mapping engine on the sample order, then sent to Holded.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Holded;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleValidator;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class HoldedMappingIntegrationTest extends HoldedTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( RuleEngine::class ) || ! class_exists( Orders::class ) ) {
			$this->markTestSkipped( 'The mapping engine is not built yet.' );
		}
		$this->server->tables['products']['p5'] = array( 'id' => 'p5', 'name' => 'Bowl', 'sku' => 'CS-BOWL-02', 'price' => 438 );
	}

	private function environment(): MappingEnvironment {
		$taxes = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'AE' === $country && '5' === $rateKey ? '5' : null;
			}
		};
		$products = new class() implements ProductResolver {
			public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
				return 'CS-BOWL-02' === trim( $sku ) ? new ExternalRef( 'p5', 'CS-BOWL-02' ) : null;
			}
		};
		return new MappingEnvironment( 1, 'Holded', $taxes, $products );
	}

	/**
	 * @param array<string,mixed> $order Overrides for the sample order.
	 * @return array<string,mixed>
	 */
	private function mapped( array $order = array() ): array {
		$engine = new RuleEngine( TransformRegistry::standard() );
		$output = $engine->apply( $this->connector()->defaultMapping( 'orders' ), new CanonicalOrder( Orders::dubai( $order ) ), $this->environment() );
		$this->assertTrue( $output->isValid(), (string) json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
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
		$this->assertSame( '10490', $payload['number'] );
		$this->assertSame( 'Gift wrap please', $payload['note'] );
		$this->assertSame( 'Noor@Example.com', $payload['customer']['email'] );
		$this->assertSame( 'نور المنصوري', $payload['customer']['name'], 'Arabic stays Arabic.' );
		$this->assertSame( '+971501234567', $payload['customer']['phone'] );
		$this->assertSame( 'CS-BOWL-02', $payload['lines'][0]['sku'] );
		$this->assertSame( 43800, $payload['lines'][0]['total'] );
		$this->assertSame( '5', $payload['lines'][0]['tax_code'] );
		$this->assertSame( 2500, $payload['shipping']['total'] );
		$this->assertSame( 48615, $payload['total'] );
		$this->assertArrayNotHasKey( 'payment', $payload, 'Holded payments are not sent.' );
	}

	public function test_the_mapped_order_is_accepted_by_holded_with_the_shop_s_total(): void {
		$payload                         = $this->mapped();
		$payload['shipping']['tax_code'] = '5';
		$result                          = $this->connector()->send( $this->connection(), $this->item(), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$this->assertSame( 48615, $result->ref->extra['total_minor'], 'Holded and the shop agree to the minor unit.' );
		$contact = $this->server->all( 'contacts' )[0];
		$this->assertSame( 'noor@example.com', $contact['email'] );
		$this->assertSame( 'Sheikh Zayed Road', $contact['billAddress']['address'] );
		$this->assertSame( 'aed', $this->server->all( 'invoice' )[0]['currency'] );
	}

	public function test_the_refund_rules_carry_the_order_date_the_order_reference_and_the_amounts(): void {
		$refund = array(
			'refund' => array(
				'order_id'   => 10490,
				'created_at' => '2026-11-03T08:00:00Z',
				'reason'     => ' Damaged ',
				'amount'     => 2625,
				'lines'      => array( array( 'sku' => 'CS-BOWL-02', 'quantity' => 1, 'amount' => 2500, 'tax' => 125 ) ),
			),
		);
		$engine = new RuleEngine( TransformRegistry::standard() );
		$output = $engine->apply( $this->connector()->defaultMapping( 'refunds' ), new CanonicalOrder( Orders::dubai( $refund ) ), $this->environment() );
		$this->assertTrue( $output->isValid(), (string) json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		$this->assertSame( 'WC-10490', $output->payload['order_ref'] );
		$this->assertSame( '2026-11-02', $output->payload['order_date'] );
		$this->assertSame( '2026-11-03', $output->payload['date'] );
		$this->assertSame( 'Damaged', $output->payload['reason'] );
		$this->assertSame( 2625, $output->payload['amount'] );
		$this->assertSame( 125, $output->payload['lines'][0]['tax_amount'] );
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
