<?php
/**
 * The version 1 Sage rules, checked by the mapping engine and sent to the fake Sage.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\SageAccounting;

use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Capability;
use Estasoft\SyncEngine\Domain\ConnectorType;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Mapping\CanonicalOrder;
use Estasoft\SyncEngine\Mapping\MappingEnvironment;
use Estasoft\SyncEngine\Mapping\ProductResolver;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleValidator;
use Estasoft\SyncEngine\Mapping\TaxResolver;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;

final class SageMappingTest extends SageTestCase {

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_types(): array {
		return array(
			'orders'    => array( 'orders' ),
			'customers' => array( 'customers' ),
			'products'  => array( 'products' ),
			'refunds'   => array( 'refunds' ),
		);
	}

	/**
	 * @dataProvider provide_types
	 */
	public function test_the_version_one_rules_pass_the_mapping_engine_s_own_validator( string $type ): void {
		$validator = new RuleValidator( TransformRegistry::standard() );
		$errors    = $validator->validate( $this->connector->defaultMapping( $type ), $this->connector->describeFields( $type ), true );
		$this->assertSame( array(), array_map( static fn( $error ): string => $error->ruleId . ': ' . $error->message, $errors ), $type );
	}

	public function test_there_is_no_stock_or_tracking_to_read(): void {
		foreach ( array( 'stock', 'tracking' ) as $type ) {
			$this->assertSame( array(), $this->connector->describeFields( $type ), $type );
			$this->assertSame( array(), $this->connector->defaultMapping( $type ), $type );
		}
	}

	public function test_every_required_order_field_has_a_rule(): void {
		$targets = array_column( $this->connector->defaultMapping( 'orders' ), 'target' );
		foreach ( $this->connector->describeFields( 'orders' ) as $field ) {
			if ( $field['required'] ) {
				$this->assertContains( $field['path'], $targets, $field['path'] );
			}
		}
	}

	private function environment(): MappingEnvironment {
		$taxes = new class() implements TaxResolver {
			public function resolve( int $connectionId, string $country, string $taxClass, string $rateKey ): ?string {
				return 'AE' === $country && '5' === $rateKey ? 'GB_REDUCED' : null;
			}
		};
		$products = new class() implements ProductResolver {
			public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
				return new ExternalRef( '1', trim( $sku ) );
			}
		};
		return new MappingEnvironment( 1, 'Sage', $taxes, $products );
	}

	public function test_the_sample_order_maps_and_sage_agrees_with_the_boards_total(): void {
		$this->sage->addProduct( 'CS-BOWL-02', array( 'description' => 'Bowl set' ) );
		$this->sage->currency = 'AED';
		$engine               = new RuleEngine( TransformRegistry::standard() );
		$output               = $engine->apply( $this->connector->defaultMapping( 'orders' ), new CanonicalOrder( Orders::dubai() ), $this->environment() );
		$this->assertTrue( $output->isValid(), json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );

		$payload = $output->payload;
		$this->assertSame( 'WC-10490', $payload['client_order_ref'] );
		$this->assertSame( '2026-11-02', $payload['date_order'] );
		$this->assertSame( 'نور المنصوري', $payload['partner']['name'] );
		$this->assertSame( 'GB_REDUCED', $payload['order_line'][0]['tax'] );
		$this->assertSame( 48615, $payload['total'] );

		$connection = $this->connect( array(), 1 );
		$result     = $this->connector->send( $connection, $this->item( '10490' ), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . ' ' . (string) ( $result->error?->context['reason'] ?? '' ) );
		$this->assertSame( 48615, $result->ref->extra['total_minor'] );
		$this->assertSame( 'AED', $result->ref->extra['currency'] );
		$this->assertSame( 'نور المنصوري', array_values( $this->sage->store['contact'] )[0]['name'] );
		$this->assertCount( 1, $this->sage->store['contact'] );
	}

	public function test_identity_and_flags_are_honest(): void {
		$this->assertSame( 'sage-accounting', $this->connector->id() );
		$this->assertSame( 'Sage Business Cloud Accounting', $this->connector->label() );
		$this->assertSame( ConnectorType::Accounting, $this->connector->type() );
		$flags = $this->connector->capabilities();
		$this->assertTrue( $flags[ Capability::Orders->value ] && $flags[ Capability::Refunds->value ] && $flags[ Capability::Customers->value ] );
		$this->assertFalse( $flags[ Capability::StockIn->value ], 'Only stock movements are documented, no level to read.' );
		$this->assertFalse( $flags[ Capability::TrackingIn->value ], 'Sage has no delivery record.' );
		$this->assertFalse( $flags[ Capability::PricesIn->value ] );
		$this->assertFalse( $flags[ Capability::Webhooks->value ], 'Sage has no webhooks.' );
		$this->assertSame( array( EntityType::Order, EntityType::Refund, EntityType::Customer ), $this->connector->supportedEntities() );
	}

	public function test_the_documented_limits_are_the_hints(): void {
		$hints = $this->connector->rateLimitHints();
		$this->assertSame( 1, $hints['concurrency'], 'Sage asks not to POST contacts, products and sales documents in parallel.' );
		$inner  = $this->connector->inner();
		$method = new \ReflectionMethod( $inner, 'connectorMeta' );
		$method->setAccessible( true );
		$meta = $method->invoke( $inner );
		$this->assertSame( 3.0, $meta->limits()->requestsPerSecond );
		$this->assertSame( array( 'api.accounting.sage.com', 'oauth.accounting.sage.com', 'www.sageone.com' ), $meta->hosts );
		$this->assertSame( array( 'GB', 'IE', 'CA', 'ES', 'FR' ), $meta->regions );
	}

	public function test_the_connect_form_asks_for_the_apps_id_secret_and_country_and_shows_the_redirect_address(): void {
		$names = array_map( static fn( $field ): string => $field->name, $this->connector->credentialFields() );

		$this->assertSame( array( 'client_id', 'client_secret', 'country', 'business_id', 'redirect_uri' ), $names );
	}
}
