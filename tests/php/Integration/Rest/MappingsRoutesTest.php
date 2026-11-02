<?php
/**
 * Mapping routes against the real mapping service and the Odoo field lists.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Rest;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';

use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Tests\Support\RestFake;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class MappingsRoutesTest extends RestIntegrationCase {

	private int $conn;

	/**
	 * Rules of version 1.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $rules;

	protected function setUp(): void {
		parent::setUp();
		$this->conn = $this->container->get( Services::CONNECTION_REPOSITORY )->create(
			array(
				'connector' => 'odoo',
				'type'      => 'erp',
				'label'     => 'Odoo',
				'address'   => 'https://erp.example.test',
			),
			array( 'api_key' => 'sk-test-abcdefghijklmnop' ),
			array()
		);
		$connector   = $this->container->get( Services::CONNECTOR_REGISTRY )->get( 'odoo' );
		$this->rules = $connector->defaultMapping( 'orders' );
		$this->container->get( Services::MAPPING_REPOSITORY )->seedLive( $this->conn, 'orders', $this->rules, 'System' );
		$this->actAs( 'administrator' );
	}

	/**
	 * A copy of version 1 with the first rule's target changed to another valid target.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function edited(): array {
		$rules             = $this->rules;
		$rules[0]['required'] = ! ( $rules[0]['required'] ?? false );
		return $rules;
	}

	public function test_the_set_has_the_live_version_the_fields_and_no_draft(): void {
		$response = $this->call( 'GET', '/mappings/orders' );
		$set      = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'orders', $set['type'] );
		$this->assertTrue( $set['versioned'] );
		$this->assertSame( 1, $set['live']['version'] );
		$this->assertNull( $set['draft'] );
		$this->assertCount( 1, $set['versions'] );
		$this->assertNotEmpty( $set['target_fields'] );
		$this->assertNotEmpty( $set['source_fields'] );
		$this->assertArrayNotHasKey( 'rules', $set['versions'][0], 'the list has no rules' );
	}

	public function test_an_unknown_type_is_not_a_route_and_an_unknown_connection_is_a_404(): void {
		$this->assertSame( 404, $this->call( 'GET', '/mappings/bogus' )->get_status() );
		$this->assertSame( 404, $this->call( 'GET', '/mappings/orders', array( 'connection_id' => 424242 ) )->get_status() );
	}

	public function test_without_any_connection_the_answer_says_to_connect_a_system_first(): void {
		$this->container->get( Services::CONNECTION_REPOSITORY )->delete( $this->conn );
		$response = $this->call( 'GET', '/mappings/orders' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'estasoft_sync_no_connection', $response->get_data()['code'] );
	}

	public function test_saving_a_draft_marks_changed_rules_and_leaves_the_live_version_alone(): void {
		$response = $this->call( 'PUT', '/mappings/orders', array( 'rules' => $this->edited(), 'note' => 'Tighten the first rule' ) );
		$set      = $response->get_data();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $set['draft']['version'] );
		$this->assertSame( 'draft', $set['draft']['status'] );
		$this->assertSame( 'Tighten the first rule', $set['draft']['note'] );
		$this->assertTrue( $set['draft']['rules'][0]['changed'] ?? false, 'Changed marker' );
		$this->assertSame( 1, $set['live']['version'] );
	}

	public function test_publish_makes_the_draft_live_and_keeps_a_straight_history_that_rollback_extends(): void {
		$this->call( 'PUT', '/mappings/orders', array( 'rules' => $this->edited() ) );
		$published = $this->call( 'POST', '/mappings/orders/publish' )->get_data();
		$this->assertSame( 2, $published['live']['version'] );
		$this->assertNull( $published['draft'] );
		$this->assertSame( array( 2, 1 ), array_column( $published['versions'], 'version' ) );
		$this->assertSame( array( 'live', 'retired' ), array_column( $published['versions'], 'status' ) );

		$rolled = $this->call( 'POST', '/mappings/orders/rollback', array( 'version' => 1 ) )->get_data();
		$this->assertSame( 3, $rolled['live']['version'], 'a new version, not a rewrite (AS-13)' );
		$this->assertSame( $this->rules[0]['required'] ?? false, $rolled['live']['rules'][0]['required'] ?? false, 'a copy of version 1' );
		$this->assertSame( array( 3, 2, 1 ), array_column( $rolled['versions'], 'version' ) );
	}

	public function test_a_note_given_at_publish_is_kept_with_the_version(): void {
		$this->call( 'PUT', '/mappings/orders', array( 'rules' => $this->edited() ) );
		$published = $this->call( 'POST', '/mappings/orders/publish', array( 'note' => 'Ready for the Dubai store' ) )->get_data();
		$this->assertSame( 'Ready for the Dubai store', $published['live']['note'] );
	}

	public function test_publishing_without_a_draft_is_a_409_with_sync_cfg_001(): void {
		$response = $this->call( 'POST', '/mappings/orders/publish' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'SYNC-CFG-001', $response->get_data()['code'] );
	}

	public function test_a_draft_with_problems_cannot_be_published_and_every_problem_is_listed(): void {
		$bad = $this->rules;
		$bad[0]['target'] = 'no.such.field';
		$bad[1]['transforms'][] = array( 'type' => 'no_such_transform' );
		$this->call( 'PUT', '/mappings/orders', array( 'rules' => $bad ) );
		$response = $this->call( 'POST', '/mappings/orders/publish' );
		$data     = $response->get_data();
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$this->assertGreaterThanOrEqual( 2, count( $data['data']['fields'] ) );
		$this->assertGreaterThanOrEqual( 2, count( $data['data']['details']['errors'] ) );
		$this->assertArrayHasKey( 'rule_id', $data['data']['details']['errors'][0] );
		$this->assertSame( 1, $this->call( 'GET', '/mappings/orders' )->get_data()['live']['version'], 'nothing was published' );
	}

	public function test_discard_removes_the_draft(): void {
		$this->call( 'PUT', '/mappings/orders', array( 'rules' => $this->edited() ) );
		$this->assertNull( $this->call( 'POST', '/mappings/orders/discard' )->get_data()['draft'] );
	}

	public function test_an_older_version_can_be_read_with_its_rules(): void {
		$this->call( 'PUT', '/mappings/orders', array( 'rules' => $this->edited() ) );
		$this->call( 'POST', '/mappings/orders/publish' );
		$old = $this->call( 'GET', '/mappings/orders/versions/1' );
		$this->assertSame( 200, $old->get_status() );
		$this->assertSame( 1, $old->get_data()['version'] );
		$this->assertCount( count( $this->rules ), $old->get_data()['rules'] );
		$this->assertSame( 404, $this->call( 'GET', '/mappings/orders/versions/99' )->get_status() );
	}

	public function test_rollback_to_a_version_that_does_not_exist_is_a_404(): void {
		$this->assertSame( 404, $this->call( 'POST', '/mappings/orders/rollback', array( 'version' => 99 ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/mappings/orders/rollback', array( 'version' => 0 ) )->get_status() );
	}

	public function test_a_shop_manager_can_read_but_not_change(): void {
		$this->actAs( 'shop_manager' );
		$this->assertSame( 200, $this->call( 'GET', '/mappings/orders' )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/mappings/orders/versions/1' )->get_status() );
		$this->assertSame( 403, $this->call( 'PUT', '/mappings/orders', array( 'rules' => array() ) )->get_status() );
	}

	public function test_preview_passes_the_order_and_the_draft_rules_and_sends_nothing(): void {
		$previewer = new RestFake( array( 'preview' => array( 'source' => array(), 'target' => array( 'x' => 1 ), 'changed_paths' => array(), 'rule_notes' => array(), 'lookups' => array(), 'errors' => array() ) ) );
		$this->replace( 'mapping.previewer', $previewer );
		$draft = $this->edited();
		$this->call( 'PUT', '/mappings/orders', array( 'rules' => $draft ) );

		$live = $this->call( 'POST', '/mappings/preview', array( 'type' => 'orders', 'order_id' => 10490, 'use_draft' => false, 'lookups' => false ) );
		$this->assertSame( 200, $live->get_status() );
		$this->assertSame( array( 'x' => 1 ), $live->get_data()['target'] );
		$withDraft = $this->call( 'POST', '/mappings/preview', array( 'type' => 'orders', 'order_id' => 10490, 'use_draft' => true, 'lookups' => true ) );
		$this->assertSame( 200, $withDraft->get_status() );

		$calls = $previewer->called( 'preview' );
		$this->assertSame( array( $this->conn, 'orders', 10490 ), array_slice( $calls[0], 0, 3 ) );
		$this->assertNull( $calls[0][3], 'live rules' );
		$this->assertFalse( $calls[0][4] );
		$this->assertCount( count( $draft ), $calls[1][3], 'draft rules' );
		$this->assertTrue( $calls[1][4], 'read-only lookups were asked for' );
		$this->assertSame( 400, $this->call( 'POST', '/mappings/preview', array( 'type' => 'orders' ) )->get_status(), 'order_id is required' );
	}
}
