<?php
/**
 * The version 1 QuickBooks rules, checked by the mapping engine and sent to the fake QuickBooks.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Connectors\QuickBooks;

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

final class QuickBooksMappingTest extends QuickBooksTestCase {

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

	public function test_stock_rules_read_a_documented_quickbooks_field_and_tracking_is_empty(): void {
		$rules = $this->connector->defaultMapping( 'stock' );
		$this->assertSame( 'QtyOnHand', $rules[0]['source'] );
		$this->assertSame( array(), $this->connector->describeFields( 'tracking' ) );
		$this->assertSame( array(), $this->connector->defaultMapping( 'tracking' ) );
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
				return 'AE' === $country && '5' === $rateKey ? '4' : null;
			}
		};
		$products = new class() implements ProductResolver {
			public function resolve( int $connectionId, string $sku, int $productId = 0, int $variationId = 0 ): ?ExternalRef {
				return new ExternalRef( '1', trim( $sku ) );
			}
		};
		return new MappingEnvironment( 1, 'QuickBooks', $taxes, $products );
	}

	public function test_the_sample_order_maps_and_quickbooks_agrees_with_the_boards_total(): void {
		$this->qbo->addItem( 'CS-BOWL-02', array( 'Name' => 'Bowl set' ) );
		$engine = new RuleEngine( TransformRegistry::standard() );
		$output = $engine->apply( $this->connector->defaultMapping( 'orders' ), new CanonicalOrder( Orders::dubai() ), $this->environment() );
		$this->assertTrue( $output->isValid(), json_encode( array_map( static fn( $e ): string => $e->getMessage(), $output->errors ) ) );

		$payload = $output->payload;
		$this->assertSame( 'WC-10490', $payload['client_order_ref'] );
		$this->assertSame( '2026-11-02', $payload['date_order'] );
		$this->assertSame( 'نور المنصوري', $payload['partner']['name'] );
		$this->assertSame( '4', $payload['order_line'][0]['tax'] );
		$this->assertSame( 48615, $payload['total'] );

		$connection = $this->connect( array( 'generic_item' => 'WC-SERVICE' ) );
		$result     = $this->connector->send( $connection, $this->item( '10490' ), $payload );

		$this->assertTrue( $result->isSuccess(), (string) $result->error?->message() . ' ' . (string) ( $result->error?->context['reason'] ?? '' ) );
		$this->assertSame( 48615, $result->ref->extra['total_minor'] );
		$this->assertSame( 'AED', $result->ref->extra['currency'] );
		$customer = array_values( $this->qbo->store['Customer'] )[0];
		$this->assertSame( 'نور المنصوري', $customer['DisplayName'] );
		$this->assertCount( 1, $this->qbo->store['Customer'] );
	}

	public function test_identity_and_flags_are_honest(): void {
		$this->assertSame( 'quickbooks', $this->connector->id() );
		$this->assertSame( 'QuickBooks Online', $this->connector->label() );
		$this->assertSame( ConnectorType::Accounting, $this->connector->type() );
		$flags = $this->connector->capabilities();
		$this->assertTrue( $flags[ Capability::Orders->value ] && $flags[ Capability::Refunds->value ] && $flags[ Capability::Customers->value ] );
		$this->assertTrue( $flags[ Capability::StockIn->value ], 'QtyOnHand is documented.' );
		$this->assertFalse( $flags[ Capability::TrackingIn->value ], 'QuickBooks has no delivery records.' );
		$this->assertFalse( $flags[ Capability::PricesIn->value ] );
		$this->assertFalse( $flags[ Capability::Webhooks->value ] );
		$this->assertSame( array( EntityType::Order, EntityType::Refund, EntityType::Customer ), $this->connector->supportedEntities() );
	}

	public function test_the_documented_limits_are_the_hints(): void {
		$hints = $this->connector->rateLimitHints();
		$this->assertSame( 1, $hints['concurrency'], 'Intuit allows 10; one keeps the order of writes.' );
		$inner  = $this->connector->inner();
		$method = new \ReflectionMethod( $inner, 'connectorMeta' );
		$method->setAccessible( true );
		$meta = $method->invoke( $inner );
		$this->assertSame( 450, $meta->limits()->requestsPerMinute, 'Intuit allows 500 a minute per company.' );
		$this->assertSame( array( 'quickbooks.api.intuit.com', 'sandbox-quickbooks.api.intuit.com', 'appcenter.intuit.com', 'oauth.platform.intuit.com' ), $meta->hosts );
		$this->assertSame( array( 'US', 'GB', 'CA', 'AU' ), $meta->regions );
	}

	public function test_the_connect_form_asks_for_the_apps_id_secret_and_key_set_and_shows_the_redirect_address(): void {
		$fields = $this->connector->credentialFields();
		$names  = array_map( static fn( $field ): string => $field->name, $fields );

		$this->assertSame( array( 'client_id', 'client_secret', 'environment', 'realm_id', 'redirect_uri' ), $names );
	}
}
