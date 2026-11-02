<?php
/**
 * The payload builder: live mapping, errors before sending, the add-on filter, Arabic (AS-17) and money (AS-18).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping;

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Domain\Connection;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Domain\Item;
use Estasoft\SyncEngine\Domain\ItemState;
use Estasoft\SyncEngine\Mapping\CanonicalFields;
use Estasoft\SyncEngine\Mapping\PayloadBuilder;
use Estasoft\SyncEngine\Mapping\Presets;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\SkuMatcher;
use Estasoft\SyncEngine\Mapping\TaxMap;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Sync\PayloadBuilderInterface;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\ArrayConnections;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\ArraySource;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\FakeConnector;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\InMemoryIdMap;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\InMemoryMappingStore;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\RecordingLog;
use PHPUnit\Framework\TestCase;

final class PayloadBuilderTest extends TestCase {

	private InMemoryMappingStore $store;
	private ArraySource $source;
	private FakeConnector $connector;
	private RecordingLog $log;
	private TaxMap $taxes;
	private Connection $connection;

	/**
	 * Arguments the filter was called with.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $filterCalls = array();

	protected function setUp(): void {
		$this->store      = new InMemoryMappingStore();
		$this->source     = new ArraySource();
		$this->connector  = new FakeConnector();
		$this->log        = new RecordingLog();
		$this->taxes      = new TaxMap( $this->store, $this->log );
		$this->connection = ArrayConnections::connection();
		$this->connector->products['CS-BOWL-02'] = new ExternalRef( '77', 'CS-BOWL-02' );
		$this->taxes->save( 1, array( array( 'country' => 'AE', 'rate_percent' => 5, 'system_code' => 'VAT 5% (AE)' ) ), null, 'x' );
		$this->store->seedLive( 1, 'orders', Presets::neutralOrders() );
		$this->source->data['order:10490'] = Orders::dubai();
	}

	private function builder( ?\Closure $filter = null ): PayloadBuilder {
		$connections = new ArrayConnections();
		$connectors  = new ConnectorRegistry( array( $this->connector ) );
		return new PayloadBuilder(
			$this->store,
			$this->source,
			new RuleEngine( TransformRegistry::standard() ),
			$this->taxes,
			new SkuMatcher( new InMemoryIdMap(), $connections, $connectors ),
			$connectors,
			new CanonicalFields(),
			$this->log,
			$filter
		);
	}

	private function item( EntityType $type = EntityType::Order, string $id = '10490' ): Item {
		return new Item( 5, 1, $type, $id, 'created', 'order:' . $id, 'key', ItemState::Syncing );
	}

	public function test_it_is_the_payload_builder_the_queue_asks_for(): void {
		$this->assertInstanceOf( PayloadBuilderInterface::class, $this->builder() );
	}

	public function test_it_builds_the_payload_from_the_live_mapping_and_reports_its_version(): void {
		$result = $this->builder()->build( $this->item(), $this->connection );
		$this->assertTrue( $result->isReady() );
		$this->assertSame( 1, $result->mappingVersion );
		$this->assertSame( 'WC-10490', $result->payload['reference'] );
		$this->assertSame( 'نور المنصوري', $result->payload['customer']['name'] );
		$this->assertSame( 'VAT 5% (AE)', $result->payload['lines'][0]['tax_code'] );
	}

	public function test_without_a_live_mapping_the_item_fails_with_cfg_001(): void {
		$this->store->versions = array();
		$result                = $this->builder()->build( $this->item(), $this->connection );
		$this->assertFalse( $result->isReady() );
		$this->assertSame( ErrorCode::Cfg001, $result->error?->code );
		$this->assertSame( 'There is no live mapping version for orders.', $result->error?->message() );
		$this->assertSame( array(), $result->payload );
	}

	public function test_a_draft_is_never_used_for_sending(): void {
		$this->store->saveDraft( 1, 'orders', array( Presets::rule( 'x', 'only', array( 'const' => 'draft' ) ) ), null, 'x' );
		$result = $this->builder()->build( $this->item(), $this->connection );
		$this->assertArrayNotHasKey( 'only', $result->payload );
	}

	public function test_types_that_are_read_from_the_system_are_not_built_here(): void {
		$result = $this->builder()->build( $this->item( EntityType::Stock, 'CS-BOWL-02' ), $this->connection );
		$this->assertSame( ErrorCode::Cfg001, $result->error?->code );
		$this->assertNull( PayloadBuilder::mappingType( EntityType::Tracking ) );
		$this->assertNull( PayloadBuilder::mappingType( EntityType::Invoice ) );
		$this->assertSame( 'refunds', PayloadBuilder::mappingType( EntityType::Refund ) );
		$this->assertSame( 'customers', PayloadBuilder::mappingType( EntityType::Customer ) );
	}

	public function test_a_missing_email_is_found_before_anything_is_sent(): void {
		$this->source->data['order:10490'] = Orders::dubai( array( 'billing' => array( 'email' => '' ) ) );
		$result                            = $this->builder()->build( $this->item(), $this->connection );
		$this->assertSame( ErrorCode::Map003, $result->error?->code );
		$this->assertSame( 'Customer email is missing on order #10490.', $result->error?->message() );
		$this->assertSame( array(), $this->connector->calls );
	}

	public function test_an_unmapped_tax_rate_is_found_before_anything_is_sent(): void {
		$this->taxes->save( 1, array(), null, 'x' );
		$result = $this->builder()->build( $this->item(), $this->connection );
		$this->assertSame( ErrorCode::Map002, $result->error?->code );
		$this->assertSame( 1, $result->mappingVersion );
	}

	public function test_as_16_a_saudi_business_with_a_short_tax_number_stops_the_order(): void {
		$this->source->data['order:10490'] = Orders::dubai( array( 'billing' => array( 'country' => 'SA', 'company' => 'شركة الأمل' ), 'order' => array( 'buyer_tax_number' => '12345' ) ) );
		$result                            = $this->builder()->build( $this->item(), $this->connection );
		$this->assertSame( ErrorCode::Val001, $result->error?->code );
		$this->assertSame( array(), $this->connector->calls );
	}

	public function test_refunds_and_customers_skip_the_buyer_tax_check(): void {
		$this->store->seedLive( 1, 'refunds', array( Presets::rule( 'r', 'reference', 'order.client_ref' ) ) );
		$this->source->data['refund:77'] = Orders::dubai( array( 'billing' => array( 'country' => 'SA', 'company' => 'Acme' ) ) );
		$result                          = $this->builder()->build( $this->item( EntityType::Refund, '77' ), $this->connection );
		$this->assertTrue( $result->isReady() );
		$this->assertSame( 'WC-10490', $result->payload['reference'] );
	}

	/**
	 * The Dubai order with its lines taken away.
	 *
	 * @param array<string,mixed> $overrides More values.
	 * @return array<string,mixed>
	 */
	private function withoutLines( array $overrides = array() ): array {
		$data          = Orders::dubai( $overrides );
		$data['lines'] = array();
		return $data;
	}

	public function test_an_order_with_no_product_lines_fails_with_one_clear_reason_before_any_rule_runs(): void {
		$this->source->data['order:10490'] = $this->withoutLines();
		$result                            = $this->builder()->build( $this->item(), $this->connection );
		$this->assertFalse( $result->isReady() );
		$this->assertSame( ErrorCode::Val002, $result->error?->code );
		$this->assertSame( 'Fake rejected a field: Order lines. The order has no products to send.', $result->error?->message() );
		$this->assertSame( array(), $this->connector->calls );
	}

	public function test_an_order_whose_products_were_all_left_out_says_so(): void {
		$this->source->data['order:10490'] = $this->withoutLines( array( 'order' => array( 'excluded_count' => 2, 'excluded_total' => 12000 ) ) );
		$result                            = $this->builder()->build( $this->item(), $this->connection );
		$this->assertSame( ErrorCode::Val002, $result->error?->code );
		$this->assertStringContainsString( 'Every product on the order is marked Exclude from syncing.', $result->error?->message() ?? '' );
	}

	public function test_an_order_with_no_lines_never_gets_a_phantom_line_with_only_fixed_values(): void {
		$this->source->data['order:10490'] = $this->withoutLines();
		$this->store->versions             = array();
		$this->store->seedLive( 1, 'orders', array( Presets::rule( 'kind', 'order_line[].kind', array( 'const' => 'product' ) ) ) );
		$result = $this->builder()->build( $this->item(), $this->connection );
		$this->assertFalse( $result->isReady() );
		$this->assertArrayNotHasKey( 'order_line', $result->payload );
	}

	public function test_a_refund_of_only_left_out_products_has_nothing_to_send_but_a_shipping_refund_does(): void {
		$this->store->seedLive( 1, 'refunds', array( Presets::rule( 'r', 'reference', 'order.client_ref' ) ) );
		$refund = array( 'id' => 77, 'order_id' => 10490, 'amount' => 1000, 'lines' => array(), 'shipping_amount' => 0, 'excluded_amount' => 1000, 'other_amount' => 0 );

		$this->source->data['refund:77'] = Orders::dubai( array( 'refund' => $refund ) );
		$result                          = $this->builder()->build( $this->item( EntityType::Refund, '77' ), $this->connection );
		$this->assertSame( ErrorCode::Val002, $result->error?->code );
		$this->assertStringContainsString( 'Every refunded product is marked Exclude from syncing.', $result->error?->message() ?? '' );

		$this->source->data['refund:77'] = Orders::dubai( array( 'refund' => array_merge( $refund, array( 'amount' => 3500, 'shipping_amount' => 2500 ) ) ) );
		$this->assertTrue( $this->builder()->build( $this->item( EntityType::Refund, '77' ), $this->connection )->isReady(), 'A refund of shipping is a real refund.' );
	}

	public function test_an_unmatched_sku_stops_the_order_with_map_001(): void {
		$this->store->versions = array();
		$this->store->seedLive( 1, 'orders', array( Presets::rule( 'p', 'l[].product', 'lines[].sku', array( Presets::transform( 'sku_match' ) ) ) ) );
		$this->connector->products = array();
		$result                    = $this->builder()->build( $this->item(), $this->connection );
		$this->assertSame( ErrorCode::Map001, $result->error?->code );
		$this->assertSame( 'Product CS-BOWL-02 was not found in Fake.', $result->error?->message() );
	}

	public function test_a_lookup_that_cannot_reach_the_system_is_a_retryable_error_not_a_mapping_error(): void {
		$this->store->versions = array();
		$this->store->seedLive( 1, 'orders', array( Presets::rule( 'p', 'l[].product', 'lines[].sku', array( Presets::transform( 'sku_match' ) ) ) ) );
		$this->connector->lookupError = new ConnectorError( ErrorCode::Net001, array( 'system' => 'Fake' ) );
		$result                       = $this->builder()->build( $this->item(), $this->connection );
		$this->assertSame( ErrorCode::Net001, $result->error?->code );
	}

	public function test_an_order_that_no_longer_exists_fails_clearly(): void {
		$result = $this->builder()->build( $this->item( EntityType::Order, '999' ), $this->connection );
		$this->assertSame( ErrorCode::Val002, $result->error?->code );
		$this->assertStringContainsString( 'no longer exists', $result->error?->message() ?? '' );
	}

	public function test_meta_keys_asked_for_by_rules_are_passed_to_the_reader(): void {
		$this->store->versions = array();
		$this->store->seedLive( 1, 'orders', array( Presets::rule( 'g', 'gift', 'meta._gift_message' ), Presets::rule( 'w', 'wrap', array( 'const' => 1 ), array(), false, null, array( 'source' => 'meta.wrap', 'equals' => 'yes' ) ) ) );
		$this->builder()->build( $this->item(), $this->connection );
		$this->assertSame( array( '_gift_message', 'wrap' ), $this->source->lastMetaKeys );
	}

	public function test_warnings_become_notes_for_the_preview(): void {
		$this->source->data['order:10490'] = Orders::dubai( array( 'billing' => array( 'phone' => '12' ) ) );
		$result                            = $this->builder()->build( $this->item(), $this->connection );
		$this->assertTrue( $result->isReady() );
		$this->assertArrayNotHasKey( 'phone', $result->payload['customer'] );
		$this->assertCount( 1, $result->notes );
	}

	public function test_the_add_on_filter_can_change_the_payload(): void {
		$builder = $this->builder(
			function ( string $hook, mixed $value, mixed ...$args ): mixed {
				$this->filterCalls[] = array( $hook, $args );
				$value['extra']      = 'from an add-on';
				return $value;
			}
		);
		$result = $builder->build( $this->item(), $this->connection );
		$this->assertSame( 'from an add-on', $result->payload['extra'] );
		$this->assertSame( 'estasoft_sync_payload', $this->filterCalls[0][0] );
		$this->assertInstanceOf( Item::class, $this->filterCalls[0][1][0] );
		$this->assertSame( $this->connection, $this->filterCalls[0][1][1] );
	}

	public function test_a_filter_that_returns_the_wrong_type_is_ignored_and_logged(): void {
		$result = $this->builder( static fn( string $hook, mixed $value ): mixed => 'oops' )->build( $this->item(), $this->connection );
		$this->assertSame( 'WC-10490', $result->payload['reference'] );
		$this->assertCount( 1, $this->log->level( 'warning' ) );
	}

	public function test_as_17_arabic_text_reaches_the_payload_and_survives_json(): void {
		$this->source->data['order:10490'] = Orders::dubai(
			array(
				'billing' => array( 'first_name' => 'عبدالرحمن', 'last_name' => 'الكعبي', 'address_1' => 'شارع الكورنيش، أبوظبي' ),
				'order'   => array( 'note' => 'يرجى الاتصال قبل التسليم' ),
			)
		);
		$this->store->versions = array();
		$this->store->seedLive( 1, 'orders', array_merge( Presets::neutralOrders(), array( Presets::rule( 'street', 'customer.street', 'billing.address_1', array( Presets::transform( 'trim' ) ) ) ) ) );
		$payload = $this->builder()->build( $this->item(), $this->connection )->payload;
		$this->assertSame( 'عبدالرحمن الكعبي', $payload['customer']['name'] );
		$this->assertSame( 'شارع الكورنيش، أبوظبي', $payload['customer']['street'] );
		$this->assertSame( 'يرجى الاتصال قبل التسليم', $payload['note'] );
		$this->assertSame( $payload, json_decode( (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ), true ) );
		$this->assertSame( $payload, json_decode( (string) wp_json_encode( $payload ), true ), 'Escaped JSON gives the same text back.' );
	}

	public function test_as_18_kuwaiti_dinar_totals_keep_three_decimals_as_whole_fils(): void {
		$this->source->data['order:10490'] = Orders::dubai(
			array(
				'order'    => array( 'currency' => 'KWD', 'total' => 12345 ),
				'lines'    => array( array( 'unit_price' => 4115, 'quantity' => 3, 'line_total' => 12345, 'tax_rate' => null ) ),
				'billing'  => array( 'country' => 'KW' ),
				'shipping' => array( 'country' => 'KW' ),
			)
		);
		$result = $this->builder()->build( $this->item(), $this->connection );
		$this->assertSame( 12345, $result->payload['total'] );
		$this->assertSame( 4115, $result->payload['lines'][0]['unit_price'] );
		$this->assertSame( 'KWD', $result->payload['currency'] );
		$this->assertIsInt( $result->payload['total'] );
		$this->assertArrayNotHasKey( 'tax_code', $result->payload['lines'][0], 'A line with no tax rate gets no tax code.' );
	}

	public function test_building_never_writes_to_the_connected_system(): void {
		$this->builder()->build( $this->item(), $this->connection );
		$this->assertNotContains( 'send', $this->connector->calls );
		$this->assertNotContains( 'findExisting', $this->connector->calls );
	}

	public function test_the_same_payload_always_has_the_same_hash(): void {
		$builder = $this->builder();
		$a       = array( 'b' => 1, 'a' => array( 'y' => 2, 'x' => 'نور' ) );
		$b       = array( 'a' => array( 'x' => 'نور', 'y' => 2 ), 'b' => 1 );
		$this->assertSame( $builder->hash( $a ), $builder->hash( $b ) );
		$this->assertNotSame( $builder->hash( $a ), $builder->hash( array( 'b' => 2 ) + $a ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $builder->hash( $a ) );
	}
}
