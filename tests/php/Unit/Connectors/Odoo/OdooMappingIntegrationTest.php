<?php
/**
 * The version 1 Odoo rules, run by the mapping engine on the sample order, then sent to Odoo.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\Odoo;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class OdooMappingIntegrationTest extends OdooTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( RuleEngine::class ) || ! class_exists( Orders::class ) ) {
			$this->markTestSkipped( 'The mapping engine is not built yet.' );
		}
	}

	private function environment( string $taxCode = 'VAT 5% (AE)' ): MappingEnvironment {
		$taxes = new class( $taxCode ) implements TaxResolver {
			public function __construct( private string $code ) {
			}

			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'AE' === $country && '5' === $rateKey ? $this->code : null;
			}
		};
		$products = new class() implements ProductResolver {
			public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
				return 'CS-BOWL-02' === trim( $sku ) ? new ExternalRef( '1', 'CS-BOWL-02' ) : null;
			}
		};
		return new MappingEnvironment( 1, 'Odoo', $taxes, $products );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function mapped( array $order = array() ): array {
		$connector = $this->connector( $this->server() );
		$engine    = new RuleEngine( TransformRegistry::standard() );
		$output    = $engine->apply( $connector->defaultMapping( 'orders' ), new CanonicalOrder( Orders::dubai( $order ) ), $this->environment() );
		$this->assertTrue( $output->isValid(), json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );
		return $output->payload;
	}

	public function test_the_default_rules_build_the_payload_the_connector_expects(): void {
		$payload = $this->mapped();

		$this->assertSame( 'WC-10490', $payload['client_order_ref'] );
		$this->assertSame( 'AED', $payload['currency'] );
		$this->assertSame( '2026-11-02 07:34:00', $payload['date_order'] );
		$this->assertSame( 'Noor@Example.com', $payload['partner']['email'], 'Trimmed, not changed: the connector ignores case when it matches.' );
		$this->assertSame( 'نور المنصوري', $payload['partner']['name'], 'Arabic stays Arabic.' );
		$this->assertSame( '+971501234567', $payload['partner']['phone'] );
		$this->assertSame( 'AE-DU', $payload['partner']['state_id'] );
		$this->assertSame( 'AE', $payload['partner']['country_id'] );
		$this->assertSame( 'CS-BOWL-02', $payload['order_line'][0]['product'] );
		$this->assertSame( 21900, $payload['order_line'][0]['price_unit'] );
		$this->assertSame( 'VAT 5% (AE)', $payload['order_line'][0]['tax'] );
		$this->assertSame( 2500, $payload['shipping']['total'] );
		$this->assertSame( 'Gift wrap please', $payload['note'] );
	}

	public function test_the_mapped_order_is_accepted_by_odoo_with_the_boards_total(): void {
		foreach ( array( 17, 18, 19 ) as $major ) {
			$server = $this->server( $major );
			$engine = new RuleEngine( TransformRegistry::standard() );
			$output = $engine->apply( $this->connector( $server )->defaultMapping( 'orders' ), new CanonicalOrder( Orders::dubai() ), $this->environment() );
			$result = $this->connector( $server )->send( $this->connection( $server ), $this->item( EntityType::Order, '10490' ), $output->payload );

			$this->assertTrue( $result->isSuccess(), "Odoo {$major}: " . (string) $result->error?->message() . ' ' . (string) $result->error?->detail );
			$this->assertSame( 48615, $result->ref->extra['total_minor'], "Odoo {$major}: AED 486.15, as on the preview board." );
			$partner = $server->db->all( 'res.partner' )[0];
			$this->assertSame( 'نور المنصوري', $partner['name'] );
			$this->assertSame( 'Sheikh Zayed Road', $partner['street'] );
			$this->assertSame( 1, $partner['country_id'] );
			$this->assertSame( 1, $partner['state_id'] );
			$this->assertCount( 1, $server->db->all( 'res.partner' ), 'The delivery address is the billing address, so no second contact.' );
			$lines = $server->db->all( 'sale.order.line' );
			$this->assertCount( 2, $lines, 'The product and the shipping line.' );
		}
	}

	public function test_a_discount_and_a_fee_in_the_canonical_order_become_lines(): void {
		$payload = $this->mapped(
			array(
				'order'   => array( 'discount_total' => 5000 ),
				'coupons' => array( array( 'code' => 'SUMMER10', 'discount' => 5000 ) ),
				'fees'    => array( array( 'name' => 'Gift wrap', 'total' => 1000, 'tax' => 50 ) ),
			)
		);
		$this->assertSame( 5000, $payload['discount']['total'] );
		$this->assertSame( 'SUMMER10', $payload['discount']['name'] );

		$server = $this->server( 18 );
		$result = $this->connector( $server )->send( $this->connection( $server ), $this->item( EntityType::Order, '10490' ), $payload );
		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() );
		$names = array_column( $server->db->all( 'sale.order.line' ), 'name' );
		$this->assertContains( 'Gift wrap', $names );
		$this->assertContains( 'SUMMER10', $names );
	}
}
