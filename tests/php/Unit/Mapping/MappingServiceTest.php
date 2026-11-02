<?php
/**
 * Draft, publish, roll back: AS-13 (a linear history that nothing rewrites).
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Mapping;

use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Mapping\CanonicalFields;
use Estasoft\SyncEngine\Mapping\MappingService;
use Estasoft\SyncEngine\Mapping\Presets;
use Estasoft\SyncEngine\Mapping\RuleEngine;
use Estasoft\SyncEngine\Mapping\RuleSanitizer;
use Estasoft\SyncEngine\Mapping\RuleValidationException;
use Estasoft\SyncEngine\Mapping\Transforms\TransformRegistry;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\ArrayConnections;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\FakeConnector;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\InMemoryMappingStore;
use Estasoft\SyncEngine\Tests\Unit\Mapping\Fakes\RecordingLog;
use PHPUnit\Framework\TestCase;

final class MappingServiceTest extends TestCase {

	private InMemoryMappingStore $store;
	private RecordingLog $log;
	private FakeConnector $connector;
	private MappingService $service;

	protected function setUp(): void {
		$this->store     = new InMemoryMappingStore();
		$this->log       = new RecordingLog();
		$this->connector = new FakeConnector();
		$this->connector->fields['orders'] = array(
			array( 'path' => 'partner.name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'group' => 'Partner' ),
			array( 'path' => 'partner.phone', 'label' => 'Phone', 'type' => 'text', 'required' => false, 'group' => 'Partner' ),
			array( 'path' => 'client_order_ref', 'label' => 'Reference', 'type' => 'text', 'required' => false, 'group' => 'Order' ),
		);
		$this->connector->fields['stock'] = array(
			array( 'path' => 'qty_available', 'label' => 'Free quantity', 'type' => 'number', 'required' => false, 'group' => 'Stock' ),
		);
		$this->service = new MappingService(
			$this->store,
			new RuleEngine( TransformRegistry::standard() ),
			new RuleSanitizer(),
			new ArrayConnections(),
			new ConnectorRegistry( array( $this->connector ) ),
			new CanonicalFields(),
			$this->log
		);
	}

	/**
	 * A valid rule list; the number changes what the phone rule does.
	 *
	 * @param string $phone Default country of the phone rule.
	 * @return array<int,array<string,mixed>>
	 */
	private function rules( string $phone = 'AE', bool $withRef = true ): array {
		$rules = array(
			Presets::rule( 'name', 'partner.name', array( 'billing.first_name', 'billing.last_name' ), array( Presets::transform( 'join' ) ), true, 'SYNC-VAL-002' ),
			Presets::rule( 'phone', 'partner.phone', 'billing.phone', array( Presets::transform( 'phone_e164', array( 'default_country' => $phone ) ) ) ),
		);
		if ( $withRef ) {
			$rules[] = Presets::rule( 'ref', 'client_order_ref', 'order.client_ref' );
		}
		return $rules;
	}

	public function test_as_13_publish_and_rollback_keep_a_linear_history(): void {
		// Version 1: draft, then live.
		$this->service->saveDraft( 1, 'orders', $this->rules(), 'First rules', 'Layla Haddad' );
		$v1 = $this->service->publish( 1, 'orders', 'Layla Haddad' );
		$this->assertSame( 1, $v1['version'] );
		$this->assertSame( array( 1 => 'live' ), $this->store->statuses( 1, 'orders' ) );

		// Version 2: change the phone rule, publish. Version 1 is retired, not deleted.
		$this->service->saveDraft( 1, 'orders', $this->rules( 'SA' ), 'Saudi default', 'Layla Haddad' );
		$v2 = $this->service->publish( 1, 'orders', 'Layla Haddad' );
		$this->assertSame( 2, $v2['version'] );
		$this->assertSame( array( 1 => 'retired', 2 => 'live' ), $this->store->statuses( 1, 'orders' ) );

		// A draft that is not published yet.
		$this->service->saveDraft( 1, 'orders', $this->rules( 'KW' ), 'Kuwait', 'Layla Haddad' );
		$this->assertSame( array( 1 => 'retired', 2 => 'live', 3 => 'draft' ), $this->store->statuses( 1, 'orders' ) );

		// Roll back to version 1: it becomes a NEW live version (4); the old live version is retired; the draft stays.
		$v4 = $this->service->rollback( 1, 'orders', 1, 'Layla Haddad' );
		$this->assertSame( 4, $v4['version'] );
		$this->assertSame( 'live', $v4['status'] );
		$this->assertSame( $v1['rules'], $v4['rules'], 'The rules are version 1\'s.' );
		$this->assertSame( array( 1 => 'retired', 2 => 'retired', 3 => 'draft', 4 => 'live' ), $this->store->statuses( 1, 'orders' ) );
		$this->assertSame( 1, $v4['based_on'] );

		// Nothing was rewritten: version 2 still has the Saudi rules.
		$this->assertSame( 'SA', $this->service->version( 1, 'orders', 2 )['rules'][1]['transforms'][0]['args']['default_country'] );
		$this->assertSame( array( 4, 3, 2, 1 ), array_column( $this->service->versions( 1, 'orders' ), 'version' ) );
		$this->assertCount( 1, array_filter( $this->service->versions( 1, 'orders' ), static fn( array $v ): bool => 'live' === $v['status'] ), 'Exactly one live version.' );
	}

	public function test_rolling_back_to_a_version_that_does_not_exist_is_refused(): void {
		$this->expectException( \OutOfBoundsException::class );
		$this->service->rollback( 1, 'orders', 9, 'x' );
	}

	public function test_publish_without_a_draft_is_refused(): void {
		$this->expectException( \LogicException::class );
		$this->service->publish( 1, 'orders', 'x' );
	}

	public function test_publish_refuses_a_draft_with_problems_and_lists_all_of_them(): void {
		$bad = array(
			Presets::rule( 'name', 'partner.name', 'billing.shoe_size' ),
			Presets::rule( 'x', 'partner.phone', 'billing.phone', array( Presets::transform( 'exec' ) ) ),
		);
		$this->service->saveDraft( 1, 'orders', $bad, null, 'x' );
		try {
			$this->service->publish( 1, 'orders', 'x' );
			$this->fail( 'A draft with problems must not go live.' );
		} catch ( RuleValidationException $e ) {
			$codes = array_map( static fn( $error ): string => $error->code, $e->errors );
			$this->assertContains( 'source_unknown', $codes );
			$this->assertContains( 'transform_unknown', $codes );
		}
		$this->assertNull( $this->store->live( 1, 'orders' ), 'Nothing was published.' );
		$this->assertNotNull( $this->store->draft( 1, 'orders' ), 'The draft is kept so it can be fixed.' );
	}

	/**
	 * A service with another connector list.
	 *
	 * @param array<int,object> $connectors Connectors installed.
	 */
	private function serviceWith( array $connectors ): MappingService {
		return new MappingService(
			$this->store,
			new RuleEngine( TransformRegistry::standard() ),
			new RuleSanitizer(),
			new ArrayConnections(),
			new ConnectorRegistry( $connectors ),
			new CanonicalFields(),
			$this->log
		);
	}

	/**
	 * Publishing must refuse, say why, and leave the draft alone.
	 *
	 * @param MappingService $service Service.
	 * @param int            $connectionId Connection.
	 */
	private function assertPublishRefusedBecauseFieldsAreUnknown( MappingService $service, int $connectionId ): void {
		$service->saveDraft( $connectionId, 'orders', $this->rules(), null, 'x' );
		try {
			$service->publish( $connectionId, 'orders', 'x' );
			$this->fail( 'A mapping that cannot be checked must not go live.' );
		} catch ( RuleValidationException $e ) {
			$this->assertContains( 'fields_unavailable', array_map( static fn( $error ): string => $error->code, $e->errors ) );
			$this->assertStringContainsString( 'cannot be checked', $e->errors[0]->message );
		}
		$this->assertNull( $this->store->live( $connectionId, 'orders' ) );
		$this->assertNotNull( $this->store->draft( $connectionId, 'orders' ) );
	}

	public function test_publish_is_refused_when_the_connection_does_not_exist(): void {
		$this->assertPublishRefusedBecauseFieldsAreUnknown( $this->service, 9 );
	}

	public function test_publish_is_refused_when_the_connector_is_not_installed(): void {
		$this->assertPublishRefusedBecauseFieldsAreUnknown( $this->serviceWith( array() ), 1 );
	}

	public function test_publish_is_refused_when_the_connector_offers_no_fields_for_the_type(): void {
		$this->connector->fields['orders'] = array();
		$this->assertPublishRefusedBecauseFieldsAreUnknown( $this->service, 1 );
	}

	public function test_validate_says_the_same_without_publishing(): void {
		$errors = $this->serviceWith( array() )->validate( 1, 'orders', $this->rules() );
		$this->assertSame( 'fields_unavailable', $errors[0]->code );
		$this->assertSame( 'target', $errors[0]->field );
	}

	public function test_stock_rules_need_no_connector_fields_to_be_published(): void {
		$service = $this->serviceWith( array() );
		$service->saveDraft( 1, 'stock', array( Presets::rule( 'q', 'product.stock_quantity', 'qty_available' ) ), null, 'x' );
		$this->assertSame( 1, $service->publish( 1, 'stock', 'x' )['version'], 'An inbound rule reads the other system, so its fields are not needed to check it.' );
	}

	public function test_discarding_the_draft_keeps_the_live_version(): void {
		$this->service->saveDraft( 1, 'orders', $this->rules(), null, 'x' );
		$this->service->publish( 1, 'orders', 'x' );
		$this->service->saveDraft( 1, 'orders', $this->rules( 'SA' ), null, 'x' );
		$this->assertTrue( $this->service->discardDraft( 1, 'orders', 'x' ) );
		$this->assertNull( $this->store->draft( 1, 'orders' ) );
		$this->assertSame( 1, $this->store->live( 1, 'orders' )['version'] );
		$this->assertFalse( $this->service->discardDraft( 1, 'orders', 'x' ), 'Discarding twice does nothing.' );
	}

	public function test_the_change_list_names_what_publishing_would_do(): void {
		$this->service->saveDraft( 1, 'orders', $this->rules(), null, 'x' );
		$this->service->publish( 1, 'orders', 'x' );

		$edited   = $this->rules( 'SA', false );
		$edited[] = Presets::rule( 'note', 'partner.name', 'order.note' );
		$this->service->saveDraft( 1, 'orders', $edited, null, 'x' );

		$changes = array();
		foreach ( $this->service->changes( 1, 'orders' ) as $change ) {
			$changes[ $change['rule_id'] ] = $change['kind'];
		}
		$this->assertSame( array( 'phone' => 'changed', 'note' => 'added', 'ref' => 'removed' ), $changes );
		$this->assertSame( array(), ( new MappingService( new InMemoryMappingStore(), new RuleEngine( TransformRegistry::standard() ), new RuleSanitizer(), new ArrayConnections(), new ConnectorRegistry( array() ), new CanonicalFields(), $this->log ) )->changes( 1, 'orders' ) );
	}

	public function test_the_set_flags_added_and_changed_rules_in_the_draft(): void {
		$this->service->saveDraft( 1, 'orders', $this->rules(), null, 'x' );
		$this->service->publish( 1, 'orders', 'x' );
		$edited   = $this->rules( 'SA' );
		$edited[] = Presets::rule( 'note', 'partner.name', 'order.note' );
		$this->service->saveDraft( 1, 'orders', $edited, null, 'x' );

		$set = $this->service->set( 1, 'orders' );
		$this->assertSame( 'orders', $set['type'] );
		$this->assertTrue( $set['versioned'] );
		$this->assertSame( 1, $set['live']['version'] );
		$this->assertSame( 2, $set['draft']['version'] );
		$flags = array();
		foreach ( $set['draft']['rules'] as $rule ) {
			$flags[ $rule['id'] ] = ( $rule['added'] ?? false ? 'added' : '' ) . ( $rule['changed'] ?? false ? 'changed' : '' );
		}
		$this->assertSame( array( 'name' => '', 'phone' => 'changed', 'ref' => '', 'note' => 'added' ), $flags );
		$this->assertArrayNotHasKey( 'rules', $set['versions'][0] );
		$this->assertCount( 3, $set['target_fields'] );
		$this->assertSame( 'order.total', array_column( $set['source_fields'], 'path' )[8] );
	}

	public function test_a_set_without_versions_has_no_live_and_no_draft(): void {
		$set = $this->service->set( 1, 'orders' );
		$this->assertNull( $set['live'] );
		$this->assertNull( $set['draft'] );
		$this->assertSame( array(), $set['versions'] );
		$this->assertFalse( $this->service->set( 1, 'products' )['versioned'] );
	}

	public function test_stock_and_tracking_rules_read_the_systems_fields_and_write_woocommerce_fields(): void {
		$set = $this->service->set( 1, 'stock' );
		$this->assertSame( array( 'qty_available' ), array_column( $set['source_fields'], 'path' ) );
		$this->assertSame( array( 'product.stock_quantity', 'product.stock_status' ), array_column( $set['target_fields'], 'path' ) );
		$this->assertContains( 'order.status', array_column( $this->service->set( 1, 'tracking' )['target_fields'], 'path' ) );

		$rules = array( Presets::rule( 'q', 'product.stock_quantity', 'qty_available' ) );
		$this->assertSame( array(), $this->service->validate( 1, 'stock', $rules ) );
		$this->service->saveDraft( 1, 'stock', $rules, null, 'x' );
		$this->assertSame( 1, $this->service->publish( 1, 'stock', 'x' )['version'] );
	}

	public function test_rules_are_cleaned_on_the_way_in(): void {
		$this->service->saveDraft(
			1,
			'orders',
			array(
				array(
					'id'         => 'name',
					'target'     => 'partner.name',
					'source'     => 'billing.first_name',
					'transforms' => array(),
					'required'   => 0,
					'changed'    => true,
					'added'      => true,
					'evil'       => '<script>',
				),
			),
			null,
			'x'
		);
		$stored = $this->store->draft( 1, 'orders' )['rules'][0];
		$this->assertSame( array( 'id', 'target', 'source', 'transforms', 'required' ), array_keys( $stored ) );
		$this->assertFalse( $stored['required'] );
	}

	public function test_every_step_is_written_to_the_log_with_who_did_it(): void {
		$this->service->saveDraft( 1, 'orders', $this->rules(), null, 'Layla Haddad' );
		$this->service->publish( 1, 'orders', 'Layla Haddad' );
		$this->service->rollback( 1, 'orders', 1, 'Layla Haddad' );
		$this->service->saveDraft( 1, 'orders', $this->rules( 'SA' ), null, 'Layla Haddad' );
		$this->service->discardDraft( 1, 'orders', 'Layla Haddad' );

		$messages = array_map( static fn( array $entry ): string => $entry[1], $this->log->level( 'info' ) );
		$this->assertSame( array( 'Mapping draft saved.', 'Mapping version published.', 'Mapping rolled back.', 'Mapping draft saved.', 'Mapping draft discarded.' ), $messages );
		foreach ( $this->log->level( 'info' ) as $entry ) {
			$this->assertSame( 'Layla Haddad', $entry[2]['by'] );
			$this->assertSame( 'orders', $entry[2]['type'] );
		}
	}

	public function test_rules_of_two_connections_do_not_mix(): void {
		$this->service->saveDraft( 1, 'orders', $this->rules(), null, 'x' );
		$this->service->publish( 1, 'orders', 'x' );
		$this->assertNull( $this->store->live( 2, 'orders' ) );
	}
}
