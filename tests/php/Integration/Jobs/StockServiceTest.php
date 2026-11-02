<?php
/**
 * STK-1 to STK-8 and AS-19.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Jobs;

require_once __DIR__ . '/JobsTestCase.php';

use Estasoft\SyncEngine\Contract\ConnectorError;
use Estasoft\SyncEngine\Core\Support\ValidationException;
use Estasoft\SyncEngine\Domain\ConnectionStatus;
use Estasoft\SyncEngine\Domain\ErrorCode;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Jobs\Stock\StockService;

final class StockServiceTest extends JobsTestCase {

	public function test_buffer_and_held_units_give_39_for_42_in_the_system(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 2 ) );
		$this->j->stock->add( 11, 'LAMP-1', 50 );
		$this->j->stock->held[11] = 1;
		$this->j->connector->stock = array( 'LAMP-1' => array( 'quantity' => 42, 'held' => 0 ) );

		$summary = $this->j->stockService->run();

		$this->assertSame( 39, $this->j->stock->products[11]['quantity'] );
		$this->assertSame( 1, $summary['changed'] );
		$this->assertSame( 1, $summary['matched'] );
		$this->assertTrue( $summary['complete'] );
		$this->assertSame( array( array( 11, 39, 42 ) ), $this->j->stock->writes );
	}

	public function test_nothing_is_written_when_the_value_did_not_change_and_the_system_is_never_written_to(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 2 ) );
		$this->j->stock->add( 11, 'LAMP-1', 40 );
		$this->j->connector->stock = array( 'LAMP-1' => array( 'quantity' => 42, 'held' => 0 ) );

		$summary = $this->j->stockService->run();

		$this->assertSame( 0, $summary['changed'] );
		$this->assertSame( array(), $this->j->stock->writes );
		$this->assertSame( 0, $this->j->connector->count( 'send' ) );
	}

	public function test_the_shop_quantity_never_goes_below_zero_and_a_product_buffer_wins(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 5 ) );
		$this->j->stock->add( 1, 'A', 10 );
		$this->j->stock->add( 2, 'B', 10, array( 'buffer' => 0 ) );
		$this->j->connector->stock = array(
			'A' => array( 'quantity' => 3, 'held' => 0 ),
			'B' => array( 'quantity' => 3, 'held' => 0 ),
		);

		$this->j->stockService->run();

		$this->assertSame( 0, $this->j->stock->products[1]['quantity'] );
		$this->assertSame( 3, $this->j->stock->products[2]['quantity'] );
	}

	public function test_units_the_system_has_reserved_are_subtracted_when_the_option_is_on_and_ignored_when_off(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 0, 'subtract_held' => true ) );
		$this->j->stock->add( 1, 'A', 0 );
		$this->j->connector->stock = array( 'A' => array( 'quantity' => 10, 'held' => 3 ) );
		$this->j->stockService->run();
		$this->assertSame( 7, $this->j->stock->products[1]['quantity'] );

		$this->j->stockService->saveRules( $id, array( 'subtract_held' => false ) );
		$this->j->stockService->run();
		$this->assertSame( 10, $this->j->stock->products[1]['quantity'] );
	}

	public function test_unmatched_excluded_and_unmanaged_products_are_skipped_and_counted(): void {
		$id = $this->j->connect();
		$this->enableStock( $id );
		$this->j->stock->add( 1, 'KNOWN', 5 );
		$this->j->stock->add( 2, 'UNKNOWN', 5 );
		$this->j->stock->add( 3, 'EXCL', 5, array( 'excluded' => true ) );
		$this->j->stock->add( 4, 'NOSTOCK', null, array( 'manages_stock' => false ) );
		$this->j->connector->stock = array( 'KNOWN' => array( 'quantity' => 20, 'held' => 0 ) );

		$summary = $this->j->stockService->run();

		$this->assertSame( 1, $summary['matched'] );
		$this->assertSame( 1, $summary['unmatched'] );
		$this->assertSame( 2, $summary['skipped'] );
		$this->assertSame( 5, $this->j->stock->products[2]['quantity'] );
		$overview = $this->j->stockService->overview();
		$this->assertSame( 1, $overview['matched'] );
		$this->assertSame( 1, $overview['unmatched'] );
		$this->assertNotNull( $overview['last_run_at'] );
	}

	public function test_a_persons_own_sku_match_is_asked_for_under_the_systems_reference(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 0 ) );
		$this->j->stock->add( 7, 'SHOP-SKU', 1 );
		$this->h->idMap->link( $id, 'product', '7', '555', 'ERP-REF', null, 'SHOP-SKU', true );
		$this->j->connector->stock = array( 'ERP-REF' => array( 'quantity' => 9, 'held' => 0 ) );

		$this->j->stockService->run();

		$this->assertSame( 9, $this->j->stock->products[7]['quantity'] );
	}

	public function test_only_the_warehouses_that_count_are_asked_for(): void {
		$id = $this->j->connect();
		$this->enableStock( $id );
		$this->j->stock->add( 1, 'A', 1 );
		$this->j->connector->stock = array( 'A' => array( 'quantity' => 5, 'held' => 0 ) );
		$this->j->stockService->run();
		$this->assertSame( array(), $this->j->connector->lastWarehouses );

		$rules = $this->j->stockService->saveRules(
			$id,
			array(
				'warehouses' => array(
					array(
						'id'                  => '2',
						'counts_toward_stock' => false,
					),
				),
			)
		);
		$this->assertFalse( $rules['warehouses'][1]['counts_toward_stock'] );
		$this->j->stockService->run();
		$this->assertSame( array( '1' ), $this->j->connector->lastWarehouses );
	}

	public function test_a_conflict_waits_for_the_person_then_accept_sets_the_system_quantity(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 0 ) );
		$this->j->stock->add( 1, 'LOW', 8, array( 'name' => 'Low lamp' ) );
		$this->j->stock->unsynced[1] = 3;
		$this->j->connector->stock   = array( 'LOW' => array( 'quantity' => 1, 'held' => 0 ) );

		$summary = $this->j->stockService->run();

		$this->assertSame( 1, $summary['conflicts'] );
		$this->assertSame( 8, $this->j->stock->products[1]['quantity'], 'nothing changes until chosen' );
		$conflicts = $this->j->stockService->overview()['conflicts'];
		$this->assertCount( 1, $conflicts );
		$this->assertSame( 'SYNC-STK-001', $conflicts[0]['code'] );
		$this->assertSame( 3, $conflicts[0]['sold_unsynced'] );
		$this->assertSame( 1, $conflicts[0]['system_quantity'] );

		$this->j->stockService->acceptConflict( (string) $conflicts[0]['id'] );

		$this->assertSame( 1, $this->j->stock->products[1]['quantity'] );
		$this->assertSame( array(), $this->j->stockService->overview()['conflicts'] );
		$again = $this->j->stockService->run();
		$this->assertSame( 0, $again['conflicts'], 'accepted: not raised again while the system quantity is the same' );
		$this->assertSame( 1, $this->j->stock->products[1]['quantity'] );
	}

	public function test_accepting_a_conflict_uses_the_products_own_buffer_and_the_held_units_like_a_normal_pass(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 0, 'subtract_held' => true ) );
		$this->j->stock->add( 1, 'LOW', 20, array( 'buffer' => 2 ) );
		$this->j->stock->held[1]     = 1;
		$this->j->stock->unsynced[1] = 10;
		$this->j->connector->stock   = array( 'LOW' => array( 'quantity' => 6, 'held' => 1 ) );
		$this->j->stockService->run();
		$conflict = $this->j->stockService->overview()['conflicts'][0];

		$this->j->stockService->acceptConflict( (string) $conflict['id'] );

		// 6 in the system, minus the product's buffer of 2, minus 1 held by the system and 1 held by WooCommerce.
		$this->assertSame( 2, $this->j->stock->products[1]['quantity'] );
		$again = $this->j->stockService->run();
		$this->assertSame( 0, $again['changed'], 'the next normal pass agrees with what accepting set' );
		$this->assertSame( 2, $this->j->stock->products[1]['quantity'] );
	}

	public function test_accepting_a_conflict_without_own_buffer_uses_the_connections_buffer(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 3, 'subtract_held' => false ) );
		$this->j->stock->add( 1, 'LOW', 20 );
		$this->j->stock->unsynced[1] = 10;
		$this->j->connector->stock   = array( 'LOW' => array( 'quantity' => 6, 'held' => 4 ) );
		$this->j->stockService->run();

		$this->j->stockService->acceptConflict( (string) $this->j->stockService->overview()['conflicts'][0]['id'] );

		$this->assertSame( 3, $this->j->stock->products[1]['quantity'] );
	}

	public function test_a_pass_that_could_not_count_every_waiting_order_writes_nothing_and_warns(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 0 ) );
		$this->j->stock->add( 1, 'A', 1 );
		$this->j->connector->stock       = array( 'A' => array( 'quantity' => 50, 'held' => 0 ) );
		$this->j->stock->unsyncedComplete = false;

		$summary = $this->j->stockService->run();

		$this->assertSame( array(), $this->j->stock->writes, 'a quantity set from a short count could be too high' );
		$this->assertSame( 1, $this->j->stock->products[1]['quantity'] );
		$this->assertFalse( $summary['complete'] );
		$this->assertTrue( $summary['unsynced_unknown'] );
		$this->assertSame( 0, $this->j->connector->count( 'fetchStock' ), 'the system was not even asked' );
		$messages = array_filter( $this->h->logs->latest( 5 ), static fn( array $entry ): bool => 'warning' === $entry['level'] );
		$this->assertStringContainsString( 'too many orders are still waiting', implode( ' ', array_column( $messages, 'message' ) ) );

		// Not tried again on every five-minute tick, but within a quarter of an hour.
		$this->assertSame( 0, $this->j->stockService->runDue()['connections'] );
		$this->h->clock->advance( 16 * 60 );
		$this->j->stock->unsyncedComplete = true;
		$later = $this->j->stockService->runDue();
		$this->assertSame( 1, $later['connections'] );
		$this->assertSame( 50, $this->j->stock->products[1]['quantity'] );
		$this->assertFalse( $later['unsynced_unknown'] );
	}

	public function test_hold_keeps_the_shop_quantity_until_the_system_quantity_changes(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 0 ) );
		$this->j->stock->add( 1, 'LOW', 8 );
		$this->j->stock->unsynced[1] = 3;
		$this->j->connector->stock   = array( 'LOW' => array( 'quantity' => 1, 'held' => 0 ) );
		$this->j->stockService->run();
		$conflict = $this->j->stockService->overview()['conflicts'][0];

		$this->j->stockService->holdConflict( (string) $conflict['id'] );
		$held = $this->j->stockService->run();

		$this->assertSame( 0, $held['conflicts'] );
		$this->assertSame( 8, $this->j->stock->products[1]['quantity'] );

		$this->j->connector->stock = array( 'LOW' => array( 'quantity' => 2, 'held' => 0 ) );
		$changed = $this->j->stockService->run();
		$this->assertSame( 1, $changed['conflicts'], 'a new system quantity is a new question' );
	}

	public function test_a_conflict_that_resolves_itself_disappears(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 0 ) );
		$this->j->stock->add( 1, 'LOW', 8 );
		$this->j->stock->unsynced[1] = 3;
		$this->j->connector->stock   = array( 'LOW' => array( 'quantity' => 1, 'held' => 0 ) );
		$this->j->stockService->run();
		$this->assertCount( 1, $this->j->stockService->overview()['conflicts'] );

		$this->j->stock->unsynced = array();
		$this->j->stockService->run();

		$this->assertCount( 0, $this->j->stockService->overview()['conflicts'] );
	}

	public function test_unknown_conflict_is_a_404(): void {
		$this->expectException( JobException::class );
		$this->j->stockService->holdConflict( '999' );
	}

	public function test_shadow_mode_changes_nothing_in_the_shop_and_only_counts(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 0 ) );
		$this->j->stock->add( 1, 'A', 1 );
		$this->j->connector->stock = array( 'A' => array( 'quantity' => 5, 'held' => 0 ) );
		$this->j->shadowService->start( $id, 7 );

		$summary = $this->j->stockService->run();

		$this->assertTrue( $summary['shadow'] );
		$this->assertSame( 1, $summary['changed'] );
		$this->assertSame( array(), $this->j->stock->writes );
		$this->assertSame( 1, $this->j->stock->products[1]['quantity'] );
		$this->assertSame( array(), $this->j->stockService->overview()['changes'] );
	}

	public function test_a_disabled_rule_a_paused_connection_and_a_missing_capability_do_nothing(): void {
		$id = $this->j->connect();
		$this->j->stock->add( 1, 'A', 1 );
		$this->j->connector->stock = array( 'A' => array( 'quantity' => 5, 'held' => 0 ) );
		$this->assertSame( 0, $this->j->stockService->run()['connections'], 'off until the person turns it on' );

		$this->enableStock( $id, array( 'safety_buffer' => 0 ) );
		$this->h->connections->setStatus( $id, ConnectionStatus::Paused, null, 'person' );
		$this->assertSame( 0, $this->j->stockService->run()['connections'] );

		$this->h->connections->setStatus( $id, ConnectionStatus::Active );
		$this->j->connector->flags['stock_in'] = false;
		$this->assertSame( 0, $this->j->stockService->run()['connections'] );
		$this->assertSame( 0, $this->j->connector->count( 'fetchStock' ) );
	}

	public function test_a_failing_connection_does_not_stop_the_others_and_is_logged(): void {
		$first  = $this->j->connect();
		$second = $this->j->connect();
		$this->enableStock( $first, array( 'safety_buffer' => 0 ) );
		$this->enableStock( $second, array( 'safety_buffer' => 0 ) );
		$this->j->stock->add( 1, 'A', 1 );
		$this->j->connector->stock     = array( 'A' => array( 'quantity' => 5, 'held' => 0 ) );
		$this->j->connector->fail      = array( 'fetchStock' => new \RuntimeException( 'boom with secret sk-live-abcdefghijklmnop' ) );
		$this->j->connector->failOnce  = true;

		$summary = $this->j->stockService->run();

		$this->assertSame( 1, $summary['errors'] );
		$this->assertSame( 5, $this->j->stock->products[1]['quantity'], 'the second connection still ran' );
		$entries = $this->h->logs->latest( 5 );
		$this->assertNotSame( array(), $entries );
		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( 'sk-live', (string) $entry['message'] );
		}
	}

	public function test_a_refused_sign_in_pauses_the_connection_like_the_queue_does(): void {
		$id = $this->j->connect();
		$this->enableStock( $id );
		$this->j->stock->add( 1, 'A', 1 );
		$this->j->connector->fail = array( 'fetchStock' => new ConnectorError( ErrorCode::Auth001, array( 'system' => 'Fakesystem' ), 401 ) );

		$this->j->stockService->run();

		$this->assertSame( ConnectionStatus::Paused, $this->h->connections->findWithoutSecrets( $id )->status );
	}

	public function test_a_pass_that_runs_out_of_time_continues_on_the_next_call(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 0 ) );
		for ( $i = 1; $i <= 250; ++$i ) {
			$this->j->stock->add( $i, 'S' . $i, 0 );
			$this->j->connector->stock[ 'S' . $i ] = array( 'quantity' => 1, 'held' => 0 );
		}
		$this->j->stock->onRead = function (): void {
			$this->h->clock->advance( 8 );
		};
		$service = new StockService( $this->j->jobConfig, $this->j->trackingSettings, $this->h->connections, $this->j->gateway, $this->j->stock, $this->h->idMap, $this->j->stockStore, $this->j->trackingLog, $this->h->onboarding, $this->h->logger, $this->h->clock, 10 );

		$first = $service->run();
		$this->assertFalse( $first['complete'] );
		$this->assertSame( array( 1, 2 ), $this->j->stock->pagesRead );

		$this->j->stock->pagesRead = array();
		$second                    = $service->run();
		$this->assertSame( 3, $this->j->stock->pagesRead[0], 'continues where it stopped' );
		$this->assertTrue( $second['complete'] );
		$this->assertSame( 250, $this->j->stockService->overview()['matched'] );
	}

	public function test_recent_changes_keep_only_the_last_100(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'safety_buffer' => 0 ) );
		for ( $i = 1; $i <= 105; ++$i ) {
			$this->j->stock->add( $i, 'S' . $i, 0 );
			$this->j->connector->stock[ 'S' . $i ] = array( 'quantity' => 4, 'held' => 0 );
		}
		$this->j->stockService->run();

		$changes = $this->j->stockService->overview()['changes'];

		$this->assertCount( 100, $changes );
		$this->assertSame( 'S105', $changes[0]['sku'], 'newest first' );
		$this->assertSame( 0, $changes[0]['shop_before'] );
		$this->assertSame( 4, $changes[0]['shop_after'] );
	}

	public function test_rules_are_validated_with_a_message_per_field(): void {
		$id = $this->j->connect();
		try {
			$this->j->stockService->saveRules(
				$id,
				array(
					'safety_buffer'     => 1000,
					'interval_minutes'  => 2,
					'delivered_becomes' => 'nonsense',
					'system_wins'       => false,
					'warehouses'        => array( array( 'id' => 'nope' ) ),
				)
			);
			$this->fail( 'expected a validation error' );
		} catch ( ValidationException $e ) {
			$this->assertEqualsCanonicalizing( array( 'safety_buffer', 'interval_minutes', 'delivered_becomes', 'system_wins', 'warehouses' ), array_keys( $e->errors() ) );
		}
		$this->assertSame( 2, $this->j->stockService->rules( $id )['safety_buffer'], 'nothing was saved' );
	}

	public function test_at_least_one_warehouse_must_count(): void {
		$id = $this->j->connect();
		$this->expectException( ValidationException::class );
		$this->j->stockService->saveRules(
			$id,
			array(
				'warehouses' => array(
					array(
						'id'                  => '1',
						'counts_toward_stock' => false,
					),
					array(
						'id'                  => '2',
						'counts_toward_stock' => false,
					),
				),
			)
		);
	}

	public function test_rules_include_the_tracking_settings_and_system_wins(): void {
		$id    = $this->j->connect();
		$rules = $this->j->stockService->saveRules( $id, array( 'tracking_enabled' => true, 'delivered_becomes' => 'completed', 'cod_payment_on_delivery' => true ) );

		$this->assertTrue( $rules['system_wins'] );
		$this->assertTrue( $rules['tracking_enabled'] );
		$this->assertTrue( $rules['cod_payment_on_delivery'] );
		$this->assertSame( 'Dubai warehouse', $rules['warehouses'][0]['name'] );
		$this->assertSame( array( 'SA' ), $rules['warehouses'][1]['countries'] );
	}

	public function test_due_runs_respect_the_interval(): void {
		$id = $this->j->connect();
		$this->enableStock( $id, array( 'interval_minutes' => 15 ) );
		$this->j->stock->add( 1, 'A', 0 );
		$this->j->connector->stock = array( 'A' => array( 'quantity' => 5, 'held' => 0 ) );

		$this->assertSame( 1, $this->j->stockService->runDue()['connections'] );
		$this->h->clock->advance( 300 );
		$this->assertSame( 0, $this->j->stockService->runDue()['connections'], 'not yet' );
		$this->h->clock->advance( 700 );
		$this->assertSame( 1, $this->j->stockService->runDue()['connections'] );
	}
}
