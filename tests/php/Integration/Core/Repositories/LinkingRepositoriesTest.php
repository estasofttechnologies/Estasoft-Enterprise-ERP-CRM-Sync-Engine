<?php
/**
 * Id map, data hashes, idempotency ledger and reconciliation history.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Repositories;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Core\Repositories\IdMapRepository;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;

final class LinkingRepositoriesTest extends CoreIntegrationTestCase {

	public function test_an_order_is_linked_to_the_system_record(): void {
		$this->assertNull( $this->h->idMap->get( 1, EntityType::Order, '10490' ) );
		$this->assertTrue( $this->h->idMap->link( 1, EntityType::Order, '10490', '1001', 'S90001', 'https://erp.example.test/o/1001' ) );
		$link = $this->h->idMap->get( 1, EntityType::Order, '10490' );
		$this->assertSame( '1001', $link['external_id'] );
		$this->assertSame( 'S90001', $link['external_ref'] );
		$this->assertSame( 'https://erp.example.test/o/1001', $link['external_url'] );
		$this->assertNull( $link['sku'] );
		$this->assertFalse( $link['is_override'] );
		$this->assertNull( $this->h->idMap->get( 2, EntityType::Order, '10490' ), 'links belong to a connection' );
		$this->assertNull( $this->h->idMap->get( 1, EntityType::Refund, '10490' ), 'and to an entity type' );
		$this->assertSame( '10490', $this->h->idMap->findWcId( 1, EntityType::Order, '1001' ) );
	}

	public function test_linking_again_replaces_the_link_and_keeps_one_row(): void {
		$this->h->idMap->link( 1, EntityType::Order, '5', '1', 'S1' );
		$this->h->idMap->link( 1, EntityType::Order, '5', '2', null, null );
		$link = $this->h->idMap->get( 1, EntityType::Order, '5' );
		$this->assertSame( '2', $link['external_id'] );
		$this->assertNull( $link['external_ref'], 'null is stored as NULL' );
		$this->assertSame( 1, $this->h->count( $this->h->tables->idMap() ) );
	}

	public function test_a_link_chosen_by_a_person_is_not_replaced_by_automatic_matching(): void {
		$p = IdMapRepository::PRODUCT;
		$this->assertTrue( $this->h->idMap->link( 1, $p, '77', 'prod-9', null, null, 'CS-BOWL-04', true ) );
		$this->assertFalse( $this->h->idMap->link( 1, $p, '77', 'prod-AUTO', null, null, 'CS-BOWL-04' ) );
		$this->assertSame( 'prod-9', $this->h->idMap->get( 1, $p, '77' )['external_id'] );
		$this->assertTrue( $this->h->idMap->link( 1, $p, '77', 'prod-10', null, null, 'CS-BOWL-04', true ), 'a person can choose again' );
		$this->assertSame( 'prod-10', $this->h->idMap->get( 1, $p, '77' )['external_id'] );
	}

	public function test_sku_lookup_prefers_a_link_a_person_chose(): void {
		$p = IdMapRepository::PRODUCT;
		$this->h->idMap->link( 1, $p, '77', 'auto-1', null, null, 'CS-1' );
		$this->h->idMap->link( 1, $p, '78', 'manual-1', null, null, 'CS-1', true );
		$found = $this->h->idMap->findBySku( 1, 'CS-1' );
		$this->assertSame( 'manual-1', $found['external_id'] );
		$this->assertSame( '78', $found['wc_id'] );
		$this->assertNull( $this->h->idMap->findBySku( 1, 'NOPE' ) );
		$this->assertNull( $this->h->idMap->findBySku( 2, 'CS-1' ) );
	}

	public function test_a_link_can_be_removed(): void {
		$this->h->idMap->link( 1, EntityType::Order, '5', '1' );
		$this->h->idMap->forget( 1, EntityType::Order, '5' );
		$this->assertNull( $this->h->idMap->get( 1, EntityType::Order, '5' ) );
	}

	public function test_the_data_hash_decides_whether_an_update_changes_anything(): void {
		$this->assertFalse( $this->h->state->isUnchanged( 1, EntityType::Order, '5', 'abc' ), 'never synced' );
		$this->h->state->save( 1, EntityType::Order, '5', 'abc', 4 );
		$this->assertTrue( $this->h->state->isUnchanged( 1, EntityType::Order, '5', 'abc' ) );
		$this->assertFalse( $this->h->state->isUnchanged( 1, EntityType::Order, '5', 'abd' ) );
		$this->assertFalse( $this->h->state->isUnchanged( 2, EntityType::Order, '5', 'abc' ) );
		$state = $this->h->state->get( 1, EntityType::Order, '5' );
		$this->assertSame( 'abc', $state['data_hash'] );
		$this->assertSame( 4, $state['mapping_version'] );
		$this->assertSame( '2026-11-02T12:00:00Z', $state['synced_at'] );
	}

	public function test_extra_facts_are_merged_and_survive_a_new_hash(): void {
		$this->h->state->save( 1, EntityType::Order, '5', 'abc' );
		$this->h->state->mergeExtra( 1, EntityType::Order, '5', array( 'invoice_number' => 'INV-1' ) );
		$this->h->state->mergeExtra( 1, EntityType::Order, '5', array( 'tracking' => 'TRK9' ) );
		$this->h->state->save( 1, EntityType::Order, '5', 'def', 5, array( 'invoice_number' => 'INV-2' ) );
		$state = $this->h->state->get( 1, EntityType::Order, '5' );
		$this->assertSame( 'def', $state['data_hash'] );
		$this->assertSame( array( 'invoice_number' => 'INV-2', 'tracking' => 'TRK9' ), $state['extra'] );
		$this->assertSame( 1, $this->h->count( $this->h->tables->state() ) );
	}

	public function test_extra_facts_before_any_sync_do_not_count_as_unchanged(): void {
		$this->h->state->mergeExtra( 1, EntityType::Order, '6', array( 'invoice_number' => 'INV-1' ) );
		$this->assertFalse( $this->h->state->isUnchanged( 1, EntityType::Order, '6', '' ) );
		$this->h->state->forget( 1, EntityType::Order, '6' );
		$this->assertNull( $this->h->state->get( 1, EntityType::Order, '6' ) );
	}

	public function test_a_key_can_be_reserved_only_once(): void {
		$key = hash( 'sha256', 'k' );
		$this->assertFalse( $this->h->idempotency->exists( $key ) );
		$this->assertTrue( $this->h->idempotency->reserve( $key, 1, EntityType::Order, '5', 'created', 0 ) );
		$this->assertFalse( $this->h->idempotency->reserve( $key, 1, EntityType::Order, '5', 'created', 0 ) );
		$this->assertTrue( $this->h->idempotency->exists( $key ) );
		$this->assertSame( 1, $this->h->count( $this->h->tables->idempotency() ) );
	}

	public function test_a_key_remembers_its_queue_item_and_its_result(): void {
		$key = hash( 'sha256', 'k2' );
		$this->h->idempotency->reserve( $key, 1, EntityType::Order, '5', 'created', 0 );
		$this->assertNull( $this->h->idempotency->queueIdFor( $key ) );
		$this->h->idempotency->attach( $key, 42 );
		$this->assertSame( 42, $this->h->idempotency->queueIdFor( $key ) );
		$this->assertSame( array( 'result' => 'pending', 'external_id' => null ), $this->h->idempotency->get( $key ) );
		$this->h->idempotency->markDone( $key, '1001' );
		$this->assertSame( array( 'result' => 'done', 'external_id' => '1001' ), $this->h->idempotency->get( $key ) );
	}

	public function test_a_reservation_without_a_queue_row_can_be_released(): void {
		$key = hash( 'sha256', 'k3' );
		$this->h->idempotency->reserve( $key, 1, EntityType::Order, '5', 'created', 0 );
		$this->h->idempotency->release( $key );
		$this->assertFalse( $this->h->idempotency->exists( $key ) );

		$this->h->idempotency->reserve( $key, 1, EntityType::Order, '5', 'created', 0 );
		$this->h->idempotency->attach( $key, 7 );
		$this->h->idempotency->release( $key );
		$this->assertTrue( $this->h->idempotency->exists( $key ), 'a key with a queue row is never released' );
	}

	public function test_keys_outlive_the_queue_and_go_after_90_days(): void {
		$old = hash( 'sha256', 'old' );
		$new = hash( 'sha256', 'new' );
		$this->h->idempotency->reserve( $old, 1, EntityType::Order, '5', 'created', 0 );
		$this->h->clock->advance( 89 * 86400 );
		$this->h->idempotency->reserve( $new, 1, EntityType::Order, '6', 'created', 0 );
		$this->h->clock->advance( 2 * 86400 );
		$this->assertSame( 1, $this->h->idempotency->prune() );
		$this->assertFalse( $this->h->idempotency->exists( $old ) );
		$this->assertTrue( $this->h->idempotency->exists( $new ) );
	}

	public function test_ordinals_count_earlier_keys_for_an_event(): void {
		$this->assertSame( 0, $this->h->idempotency->countFor( 1, EntityType::Order, '5', 'updated' ) );
		$this->h->idempotency->reserve( hash( 'sha256', 'a' ), 1, EntityType::Order, '5', 'updated', 0 );
		$this->h->idempotency->reserve( hash( 'sha256', 'b' ), 1, EntityType::Order, '5', 'updated', 1 );
		$this->h->idempotency->reserve( hash( 'sha256', 'c' ), 1, EntityType::Order, '5', 'paid', 0 );
		$this->assertSame( 2, $this->h->idempotency->countFor( 1, EntityType::Order, '5', 'updated' ) );
	}

	public function test_a_reconciliation_run_and_its_differences(): void {
		$run = $this->h->recon->startRun( 1, 7 );
		$this->assertSame( 'running', $this->h->recon->findRun( $run )['status'] );
		$a = $this->h->recon->addDiff( $run, 1, 10490, '10490', 'total_differs', 'AED 438.00', 'AED 428.00' );
		$b = $this->h->recon->addDiff( $run, 1, 10491, '10491', 'missing_in_system', 'AED 120.00', '' );
		$this->h->clock->advance( 5 );
		$this->h->recon->finishRun( $run, 120, 118, 2, 0 );

		$last = $this->h->recon->lastRun();
		$this->assertSame( 'done', $last['status'] );
		$this->assertSame( array( 'id', 'started_at', 'finished_at', 'window_days', 'compared', 'matched', 'differences', 'repaired', 'status' ), array_keys( $last ) );
		$this->assertSame( 118, $last['matched'] );
		$this->assertSame( 2, $this->h->recon->openCount() );

		$diff = $this->h->recon->diff( $a );
		$this->assertSame( array( 'id', 'run_id', 'order_id', 'order_label', 'type', 'wc_value', 'system_value', 'status', 'note', 'acted_by', 'acted_at' ), array_keys( $diff ) );
		$this->assertSame( 'total_differs', $diff['type'] );
		$this->assertSame( 'open', $diff['status'] );

		$this->assertTrue( $this->h->recon->markRepaired( $a, 'Layla' ) );
		$this->assertFalse( $this->h->recon->markRepaired( $a, 'Layla' ), 'already closed' );
		$this->assertTrue( $this->h->recon->ignore( $b, 'Test order', 'Layla' ) );
		$ignored = $this->h->recon->diff( $b );
		$this->assertSame( 'ignored', $ignored['status'] );
		$this->assertSame( 'Test order', $ignored['note'] );
		$this->assertSame( 'Layla', $ignored['acted_by'] );
		$this->assertNotNull( $ignored['acted_at'] );
		$this->assertSame( 0, $this->h->recon->openCount() );
		$this->assertCount( 2, $this->h->recon->diffs( null, $run ) );
		$this->assertCount( 1, $this->h->recon->diffs( 'repaired' ) );
	}

	public function test_a_failed_run_is_recorded_with_its_code(): void {
		$run = $this->h->recon->startRun( 1, 7 );
		$this->h->recon->finishRun( $run, 0, 0, 0, 0, 'failed', 'SYNC-AUTH-001' );
		$this->assertSame( 'failed', $this->h->recon->findRun( $run )['status'] );
	}

	public function test_reconciliation_history_goes_after_90_days(): void {
		$run = $this->h->recon->startRun( 1, 7 );
		$this->h->recon->addDiff( $run, 1, 1, '1', 'total_differs', 'a', 'b' );
		$this->h->clock->advance( 91 * 86400 );
		$fresh = $this->h->recon->startRun( 1, 7 );
		$this->assertSame( 2, $this->h->recon->prune() );
		$this->assertNull( $this->h->recon->findRun( $run ) );
		$this->assertNotNull( $this->h->recon->findRun( $fresh ) );
		$this->assertSame( array(), $this->h->recon->diffs() );
	}
}
