<?php
/**
 * Preview with a real order: AS-14, nothing is sent and nothing is written.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping;

use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\ExternalRef;
use Estasoft\SyncEngine\Mapping\CanonicalFields;
use Estasoft\SyncEngine\Mapping\Presets;
use Estasoft\SyncEngine\Mapping\Previewer;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\SkuMatcher;
use Estasoft\SyncEngine\Mapping\TaxMap;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\ArrayConnections;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\ArraySource;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\FakeConnector;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\InMemoryIdMap;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\InMemoryMappingStore;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\Orders;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\RecordingLog;
use PHPUnit\Framework\TestCase;

final class PreviewerTest extends TestCase {

	private InMemoryMappingStore $store;
	private InMemoryIdMap $idMap;
	private ArraySource $source;
	private FakeConnector $connector;
	private Previewer $previewer;

	protected function setUp(): void {
		$this->store     = new InMemoryMappingStore();
		$this->idMap     = new InMemoryIdMap();
		$this->source    = new ArraySource();
		$this->connector = new FakeConnector();
		$log             = new RecordingLog();
		$taxes           = new TaxMap( $this->store, $log );
		$connections     = new ArrayConnections();
		$connectors      = new ConnectorRegistry( array( $this->connector ) );

		$taxes->save( 1, array( array( 'country' => 'AE', 'rate_percent' => 5, 'system_code' => 'VAT 5% (AE)' ) ), null, 'x' );
		$this->store->calls = array();
		$this->store->seedLive( 1, 'orders', Presets::neutralOrders() );
		$this->source->data['order:10490'] = Orders::dubai();
		$this->source->data['refund:55']   = Orders::dubai();
		$this->connector->products['CS-BOWL-02'] = new ExternalRef( '77', 'CS-BOWL-02' );
		$this->connector->customers['noor@example.com'] = new ExternalRef( '12', 'Noor Al-Mansouri' );

		$this->previewer = new Previewer(
			$this->store,
			$this->source,
			new RuleEngine( TransformRegistry::standard() ),
			$taxes,
			new SkuMatcher( $this->idMap, $connections, $connectors ),
			$connections,
			$connectors,
			new CanonicalFields()
		);
	}

	public function test_it_shows_source_target_and_what_changed(): void {
		$preview = $this->previewer->preview( 1, 'orders', 10490 );
		$this->assertSame( array(), $preview['errors'] );
		$this->assertSame( 10490, $preview['source']['order']['id'] );
		$this->assertSame( 'WC-10490', $preview['target']['reference'] );
		$this->assertSame( '+971501234567', $preview['target']['customer']['phone'] );
		$this->assertContains( 'customer.phone', $preview['changed_paths'] );
		$this->assertContains( 'customer.state', $preview['changed_paths'] );
		$this->assertNotContains( 'reference', $preview['changed_paths'] );
		$this->assertSame( array(), $preview['lookups'] );
	}

	public function test_as_14_the_preview_sends_nothing_and_writes_nothing(): void {
		$calls = $this->store->calls;
		$this->previewer->preview( 1, 'orders', 10490, null, true );
		$this->previewer->preview( 1, 'orders', 10490, array( Presets::rule( 'x', 'a', 'order.number' ) ), true );

		$this->assertNotContains( 'send', $this->connector->calls );
		$this->assertNotContains( 'findExisting', $this->connector->calls );
		$this->assertSame( 0, $this->idMap->writes, 'A looked-up product is not remembered by a preview.' );
		$this->assertSame( $calls, $this->store->calls, 'No draft is saved and no version is published.' );
	}

	public function test_without_lookups_the_connected_system_is_not_asked_at_all(): void {
		$this->previewer->preview( 1, 'orders', 10490 );
		$this->assertSame( array(), $this->connector->calls );
	}

	public function test_with_lookups_it_asks_read_only_questions_and_labels_them(): void {
		$this->store->versions = array();
		$this->store->seedLive( 1, 'orders', array( Presets::rule( 'p', 'l[].product', 'lines[].sku', array( Presets::transform( 'sku_match' ) ) ) ) );
		$preview = $this->previewer->preview( 1, 'orders', 10490, null, true );

		$this->assertSame( '77', $preview['target']['l'][0]['product'] );
		$this->assertSame( array( 'lookupProduct:CS-BOWL-02', 'lookupCustomer:Noor@Example.com', 'lookupProduct:CS-BOWL-02' ), $this->connector->calls );
		$this->assertCount( 2, $preview['lookups'] );
		$this->assertSame( 'Customer in Fake (read-only lookup)', $preview['lookups'][0]['label'] );
		$this->assertTrue( $preview['lookups'][0]['found'] );
		$this->assertSame( 'Noor Al-Mansouri', $preview['lookups'][0]['detail'] );
		$this->assertSame( 'Product CS-BOWL-02 in Fake (read-only lookup)', $preview['lookups'][1]['label'] );
		$this->assertSame( 'CS-BOWL-02', $preview['lookups'][1]['detail'] );
	}

	public function test_lookups_report_what_is_not_found(): void {
		$this->connector->customers = array();
		$this->connector->products  = array();
		$preview                    = $this->previewer->preview( 1, 'orders', 10490, null, true );
		$this->assertFalse( $preview['lookups'][0]['found'] );
		$this->assertSame( 'Not found.', $preview['lookups'][0]['detail'] );
	}

	public function test_a_draft_can_be_previewed_instead_of_the_live_rules(): void {
		$draft   = array( Presets::rule( 'x', 'only_in_draft', 'order.number' ) );
		$preview = $this->previewer->preview( 1, 'orders', 10490, $draft );
		$this->assertSame( array( 'only_in_draft' => '10490' ), $preview['target'] );
	}

	public function test_problems_are_listed_together_in_the_error_shape_of_the_api(): void {
		$this->source->data['order:10490'] = Orders::dubai( array( 'billing' => array( 'email' => '', 'country' => 'SA', 'company' => 'Acme' ), 'shipping' => array( 'country' => 'SA' ) ) );
		$preview                           = $this->previewer->preview( 1, 'orders', 10490 );
		$codes                             = array_column( $preview['errors'], 'code' );
		$this->assertContains( 'SYNC-VAL-001', $codes, 'Buyer tax number.' );
		$this->assertContains( 'SYNC-MAP-003', $codes, 'Missing email.' );
		$this->assertContains( 'SYNC-MAP-002', $codes, 'Saudi rate not mapped.' );
		foreach ( $preview['errors'] as $error ) {
			$this->assertSame( 422, $error['data']['status'] );
			$this->assertSame( 'needs_person', $error['data']['class'] );
			$this->assertNotSame( '', $error['message'] );
		}
		$this->assertArrayHasKey( 'reference', $preview['target'], 'The rest of the payload is still shown.' );
	}

	public function test_a_message_is_never_repeated_in_the_error_list(): void {
		// An unmapped rate fails every rule that uses it (the product lines and the shipping line): the person is told once.
		$this->source->data['order:10490'] = Orders::dubai( array( 'billing' => array( 'country' => 'SA' ), 'shipping' => array( 'country' => 'SA' ) ) );
		$preview                           = $this->previewer->preview( 1, 'orders', 10490 );
		$messages                          = array_column( $preview['errors'], 'message' );
		$this->assertContains( 'Tax code not mapped for Saudi Arabia 5%.', $messages );
		$this->assertSame( array_values( array_unique( $messages ) ), $messages, 'No message appears twice.' );
	}

	public function test_an_order_with_no_product_lines_is_reported_in_the_preview_too(): void {
		$data                              = Orders::dubai();
		$data['lines']                     = array();
		$this->source->data['order:10491'] = $data;
		$preview                           = $this->previewer->preview( 1, 'orders', 10491 );
		$codes                             = array_column( $preview['errors'], 'code' );
		$this->assertContains( 'SYNC-VAL-002', $codes );
		$this->assertContains( 'SYNC-MAP-001', $codes, 'The required line rule fails as well, and both are shown together.' );
		$message = implode( ' ', array_column( $preview['errors'], 'message' ) );
		$this->assertStringContainsString( 'The order has no products to send.', $message );
	}

	public function test_a_preview_that_cannot_be_built_says_why_with_prv_001(): void {
		$missing = $this->previewer->preview( 1, 'orders', 999 );
		$this->assertSame( 'SYNC-PRV-001', $missing['errors'][0]['code'] );
		$this->assertSame( 'The preview could not build this order: The order does not exist in WooCommerce.', $missing['errors'][0]['message'] );

		$this->assertSame( 'SYNC-PRV-001', $this->previewer->preview( 9, 'orders', 10490 )['errors'][0]['code'] );
		$this->assertSame( 'SYNC-PRV-001', $this->previewer->preview( 1, 'stock', 10490 )['errors'][0]['code'] );
		$this->assertSame( array(), $missing['target'] );
	}

	public function test_without_a_live_mapping_the_preview_says_so(): void {
		$preview = $this->previewer->preview( 1, 'customers', 10490 );
		$this->assertSame( 'SYNC-CFG-001', $preview['errors'][0]['code'] );
		$this->assertSame( 'There is no live mapping version for customers.', $preview['errors'][0]['message'] );
	}

	public function test_customer_rules_preview_on_the_order_and_refund_rules_on_a_refund(): void {
		$this->store->seedLive( 1, 'customers', array( Presets::rule( 'e', 'email', 'customer.email', array( Presets::transform( 'trim' ) ) ) ) );
		$this->store->seedLive( 1, 'refunds', array( Presets::rule( 'r', 'reference', 'order.client_ref' ) ) );
		$this->assertSame( 'Noor@Example.com', $this->previewer->preview( 1, 'customers', 10490 )['target']['email'] );
		$this->assertSame( 'WC-10490', $this->previewer->preview( 1, 'refunds', 55 )['target']['reference'] );
		$this->assertSame( 'SYNC-PRV-001', $this->previewer->preview( 1, 'refunds', 10490 )['errors'][0]['code'], 'There is no refund with that id.' );
	}

	public function test_lookup_failures_are_reported_not_thrown(): void {
		$this->store->versions = array();
		$this->store->seedLive( 1, 'orders', array( Presets::rule( 'p', 'l[].product', 'lines[].sku', array( Presets::transform( 'sku_match' ) ) ) ) );
		$this->connector->lookupError = new \Estasoft\SyncEngine\Contract\ConnectorError( \Estasoft\SyncEngine\Domain\ErrorCode::Net001, array( 'system' => 'Fake' ) );
		$preview                      = $this->previewer->preview( 1, 'orders', 10490, null, true );
		$this->assertSame( 'SYNC-NET-001', $preview['errors'][0]['code'] );
	}
}
