<?php
/**
 * Mapping versions: draft, publish, rollback.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Core\Repositories;

require_once dirname( __DIR__, 3 ) . '/Support/CoreIntegrationTestCase.php';

use Estasoft\SyncEngine\Tests\Support\CoreIntegrationTestCase;
use Estasoft\SyncEngine\Tests\Support\CoreProcessBarrier;

final class MappingRepositoryTest extends CoreIntegrationTestCase {

	private int $conn = 1;

	/**
	 * Rules with a recognisable first target.
	 *
	 * @param string $target Target path.
	 * @return array<int,array<string,mixed>>
	 */
	private function rules( string $target ): array {
		return array(
			array(
				'id'         => 'r1',
				'target'     => $target,
				'source'     => 'order.number',
				'transforms' => array( array( 'type' => 'trim' ) ),
				'required'   => true,
			),
		);
	}

	/**
	 * Count rows with a status.
	 *
	 * @param string $type   Mapping type.
	 * @param string $status Status.
	 */
	private function countStatus( string $type, string $status ): int {
		return (int) $this->h->wpdb->get_var( $this->h->wpdb->prepare( "SELECT COUNT(*) FROM `{$this->h->tables->mappings()}` WHERE connection_id = %d AND type = %s AND status = %s", $this->conn, $type, $status ) ); // phpcs:ignore
	}

	public function test_nothing_exists_before_the_first_save(): void {
		$this->assertNull( $this->h->mappings->live( $this->conn, 'orders' ) );
		$this->assertNull( $this->h->mappings->draft( $this->conn, 'orders' ) );
		$this->assertSame( array(), $this->h->mappings->versions( $this->conn, 'orders' ) );
	}

	public function test_default_mapping_is_version_one_and_live(): void {
		$live = $this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'partner.name' ), 'Odoo connector' );
		$this->assertSame( 1, $live['version'] );
		$this->assertSame( 'live', $live['status'] );
		$this->assertNotNull( $live['published_at'] );
		$this->assertSame( 'order.number', $live['rules'][0]['source'] );

		$again = $this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'other' ), 'Odoo connector' );
		$this->assertSame( 1, $again['version'], 'seeding twice changes nothing' );
		$this->assertSame( 'partner.name', $again['rules'][0]['target'] );
	}

	public function test_a_draft_is_saved_and_replaced_without_changing_the_live_version(): void {
		$this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'a' ), 'seed' );
		$draft = $this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'b' ), 'try b', 'Layla' );
		$this->assertSame( 2, $draft['version'] );
		$this->assertSame( 'draft', $draft['status'] );
		$this->assertSame( 'Layla', $draft['created_by'] );
		$this->assertSame( 'try b', $draft['note'] );

		$again = $this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'c' ), 'try c', 'Layla' );
		$this->assertSame( 2, $again['version'], 'still the one draft' );
		$this->assertSame( 'c', $again['rules'][0]['target'] );
		$this->assertSame( 1, $this->countStatus( 'orders', 'draft' ) );
		$this->assertSame( 'a', $this->h->mappings->live( $this->conn, 'orders' )['rules'][0]['target'] );
	}

	public function test_publishing_retires_the_old_live_version_and_keeps_one_live(): void {
		$this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'a' ), 'seed' );
		$this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'b' ), null, 'Layla' );
		$this->h->clock->advance( 60 );
		$live = $this->h->mappings->publish( $this->conn, 'orders', 'Layla', 'Use b' );

		$this->assertSame( 2, $live['version'] );
		$this->assertSame( 'live', $live['status'] );
		$this->assertSame( 'Use b', $live['note'] );
		$this->assertSame( '2026-11-02T12:01:00Z', $live['published_at'] );
		$this->assertSame( 1, $this->countStatus( 'orders', 'live' ) );
		$this->assertSame( 1, $this->countStatus( 'orders', 'retired' ) );
		$this->assertSame( 0, $this->countStatus( 'orders', 'draft' ) );
		$this->assertNull( $this->h->mappings->draft( $this->conn, 'orders' ) );
		$this->assertSame( 'b', $this->h->mappings->live( $this->conn, 'orders' )['rules'][0]['target'] );
	}

	public function test_publishing_without_a_draft_is_refused(): void {
		$this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'a' ), 'seed' );
		$this->expectException( \LogicException::class );
		$this->h->mappings->publish( $this->conn, 'orders', 'Layla' );
	}

	public function test_a_failed_publish_leaves_no_half_state(): void {
		$this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'a' ), 'seed' );
		try {
			$this->h->mappings->publish( $this->conn, 'orders', 'Layla' );
		} catch ( \LogicException $e ) {
			unset( $e );
		}
		$this->assertSame( 1, $this->countStatus( 'orders', 'live' ), 'the live version is untouched' );
		$this->assertSame( 0, $this->countStatus( 'orders', 'retired' ) );
	}

	public function test_discarding_a_draft_removes_it_and_nothing_else(): void {
		$this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'a' ), 'seed' );
		$this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'b' ), null, 'Layla' );
		$this->assertTrue( $this->h->mappings->discardDraft( $this->conn, 'orders' ) );
		$this->assertFalse( $this->h->mappings->discardDraft( $this->conn, 'orders' ) );
		$this->assertSame( 1, $this->h->mappings->live( $this->conn, 'orders' )['version'] );
		$next = $this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'c' ), null, 'Layla' );
		$this->assertSame( 2, $next['version'], 'the discarded number is free again, history stays linear' );
	}

	public function test_rollback_copies_an_old_version_into_a_new_live_one(): void {
		$this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'a' ), 'seed' );
		$this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'b' ), null, 'Layla' );
		$this->h->mappings->publish( $this->conn, 'orders', 'Layla' );

		$rolled = $this->h->mappings->rollback( $this->conn, 'orders', 1, 'Sam' );
		$this->assertSame( 3, $rolled['version'] );
		$this->assertSame( 'live', $rolled['status'] );
		$this->assertSame( 'a', $rolled['rules'][0]['target'] );
		$this->assertSame( 1, $rolled['based_on'] );
		$this->assertSame( 'Rolled back to version 1', $rolled['note'] );
		$this->assertSame( 'Sam', $rolled['created_by'] );

		$versions = $this->h->mappings->versions( $this->conn, 'orders' );
		$this->assertSame( array( 3, 2, 1 ), array_column( $versions, 'version' ) );
		$this->assertSame( array( 'live', 'retired', 'retired' ), array_column( $versions, 'status' ) );
		$this->assertArrayNotHasKey( 'rules', $versions[0], 'the list does not carry the rules' );
		$this->assertSame( 'b', $this->h->mappings->find( $this->conn, 'orders', 2 )['rules'][0]['target'], 'old versions are never rewritten' );
		$this->assertSame( 1, $this->countStatus( 'orders', 'live' ) );
	}

	public function test_rollback_to_a_missing_version_is_an_error(): void {
		$this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'a' ), 'seed' );
		try {
			$this->h->mappings->rollback( $this->conn, 'orders', 9, 'Sam' );
			$this->fail( 'Expected an exception.' );
		} catch ( \OutOfBoundsException $e ) {
			unset( $e );
		}
		$this->assertSame( 1, $this->countStatus( 'orders', 'live' ), 'the live version was not retired by the failed rollback' );
	}

	public function test_types_and_connections_have_separate_histories(): void {
		$this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'o' ), 'seed' );
		$this->h->mappings->seedLive( $this->conn, 'customers', $this->rules( 'c' ), 'seed' );
		$this->h->mappings->seedLive( 2, 'orders', $this->rules( 'other' ), 'seed' );
		$this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'o2' ), null, 'x' );
		$this->h->mappings->publish( $this->conn, 'orders', 'x' );
		$this->assertSame( 2, $this->h->mappings->live( $this->conn, 'orders' )['version'] );
		$this->assertSame( 1, $this->h->mappings->live( $this->conn, 'customers' )['version'] );
		$this->assertSame( 1, $this->h->mappings->live( 2, 'orders' )['version'] );
	}

	public function test_an_unversioned_type_is_edited_in_place(): void {
		$first = $this->h->mappings->replaceLive( $this->conn, 'tax', array( array( 'country' => 'AE', 'code' => 'VAT5' ) ), 'Layla' );
		$this->assertSame( 1, $first['version'] );
		$second = $this->h->mappings->replaceLive( $this->conn, 'tax', array( array( 'country' => 'AE', 'code' => 'VAT5' ), array( 'country' => 'SA', 'code' => 'VAT15' ) ), 'Layla' );
		$this->assertSame( 1, $second['version'], 'no new version' );
		$this->assertCount( 2, $second['rules'] );
		$this->assertSame( 1, $this->countStatus( 'tax', 'live' ) );
		$this->assertSame( 0, $this->countStatus( 'tax', 'retired' ) );
	}

	public function test_many_operations_never_leave_two_live_versions(): void {
		$this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'v1' ), 'seed' );
		for ( $i = 2; $i <= 8; ++$i ) {
			$this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( "v$i" ), null, 'x' );
			$this->h->mappings->publish( $this->conn, 'orders', 'x' );
			if ( 0 === $i % 3 ) {
				$this->h->mappings->rollback( $this->conn, 'orders', $i - 1, 'x' );
			}
			$this->assertSame( 1, $this->countStatus( 'orders', 'live' ), "after step $i" );
		}
		$versions = array_column( $this->h->mappings->versions( $this->conn, 'orders' ), 'version' );
		$this->assertSame( range( count( $versions ), 1 ), $versions, 'version numbers are one linear run without gaps' );
	}

	public function test_two_processes_editing_and_publishing_at_once_never_make_two_live_versions(): void {
		$script = dirname( __DIR__, 3 ) . '/Support/CoreMappingWorkerProcess.php';
		$barrier = new CoreProcessBarrier();
		$procs   = array();
		foreach ( array( 'a', 'b' ) as $name ) {
			$pipes   = array();
			$proc    = proc_open( array( PHP_BINARY, $script, $name, '25', $barrier->dir(), (string) $this->conn ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			$this->assertIsResource( $proc );
			$procs[ $name ] = array( $proc, $pipes );
		}
		$this->assertTrue( $barrier->release( 2 ) );
		$published = 0;
		foreach ( $procs as $name => $parts ) {
			list( $proc, $pipes ) = $parts;
			$out = (string) stream_get_contents( $pipes[1] );
			$err = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $proc );
			$result = json_decode( $out, true );
			$this->assertIsArray( $result, "$name printed: $out $err" );
			$this->assertSame( 0, $result['errors'], "$name hit an unexpected error: $err" );
			$published += $result['published'];
		}
		$this->assertGreaterThan( 5, $published );
		$this->assertSame( 1, $this->countStatus( 'orders', 'live' ), 'exactly one live version' );
		$versions = array_map( 'intval', array_column( $this->h->mappings->versions( $this->conn, 'orders' ), 'version' ) );
		$this->assertSame( range( count( $versions ), 1 ), $versions, 'one linear run of version numbers, none twice' );
		$this->assertSame( $published, count( $versions ) - $this->countStatus( 'orders', 'draft' ), 'every successful publish made one version' );
	}

	/**
	 * Build the history live v3, draft v4.
	 */
	private function liveThreeDraftFour(): void {
		$this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'v1' ), 'seed' );
		$this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'v2' ), null, 'Layla' );
		$this->h->mappings->publish( $this->conn, 'orders', 'Layla' );
		$this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'v3' ), null, 'Layla' );
		$this->h->mappings->publish( $this->conn, 'orders', 'Layla' );
		$this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'v4' ), 'the draft', 'Layla' );
		$this->assertSame( 3, $this->h->mappings->live( $this->conn, 'orders' )['version'] );
		$this->assertSame( 4, $this->h->mappings->draft( $this->conn, 'orders' )['version'] );
	}

	public function test_publishing_a_draft_older_than_a_rollback_renumbers_it_so_history_stays_linear(): void {
		$this->liveThreeDraftFour();

		$rolled = $this->h->mappings->rollback( $this->conn, 'orders', 2, 'Layla' );
		$this->assertSame( 5, $rolled['version'], 'rollback creates the next number after the highest' );
		$this->assertSame( 'v2', $rolled['rules'][0]['target'] );
		$this->assertSame( 4, $this->h->mappings->draft( $this->conn, 'orders' )['version'], 'the draft is still numbered 4 until it is published' );

		$live = $this->h->mappings->publish( $this->conn, 'orders', 'Layla', 'Ship the draft' );
		$this->assertSame( 6, $live['version'], 'the draft is renumbered above the rollback' );
		$this->assertSame( 'live', $live['status'] );
		$this->assertSame( 'v4', $live['rules'][0]['target'], 'it is still the draft that went live' );
		$this->assertSame( 'Ship the draft', $live['note'] );
		$this->assertSame( 'retired', $this->h->mappings->find( $this->conn, 'orders', 5 )['status'], 'the rollback version is retired' );
		$this->assertNull( $this->h->mappings->find( $this->conn, 'orders', 4 ), 'there is no version 4 any more: its number moved up' );
		$this->assertNull( $this->h->mappings->draft( $this->conn, 'orders' ) );
		$this->assertSame( 1, $this->countStatus( 'orders', 'live' ) );
		$this->assertSame( array( 6, 5, 3, 2, 1 ), array_column( $this->h->mappings->versions( $this->conn, 'orders' ), 'version' ) );
	}

	public function test_the_next_rollback_and_the_next_draft_keep_counting_up(): void {
		$this->liveThreeDraftFour();
		$this->h->mappings->rollback( $this->conn, 'orders', 2, 'Layla' );
		$this->h->mappings->publish( $this->conn, 'orders', 'Layla' );
		$this->assertSame( 7, $this->h->mappings->rollback( $this->conn, 'orders', 1, 'Layla' )['version'] );
		$this->assertSame( 8, $this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'next' ), null, 'Layla' )['version'] );
		$this->assertSame( 8, $this->h->mappings->publish( $this->conn, 'orders', 'Layla' )['version'], 'a draft that is already the highest keeps its number' );
	}

	public function test_a_published_version_number_never_goes_down_and_queue_items_record_it_going_up(): void {
		$this->liveThreeDraftFour();
		$seen   = array();
		$record = function ( string $order ) use ( &$seen ): void {
			$live = $this->h->mappings->live( $this->conn, 'orders' );
			$id   = $this->h->item( $this->conn, $order );
			$this->h->queue->markSynced( $id, '1', 'S1', null, $live['version'] );
			$seen[] = (int) $this->h->queue->findRow( $id )['mapping_version'];
		};
		$record( '1' );
		$this->h->mappings->rollback( $this->conn, 'orders', 2, 'Layla' );
		$record( '2' );
		$this->h->mappings->publish( $this->conn, 'orders', 'Layla' );
		$record( '3' );
		$this->h->mappings->rollback( $this->conn, 'orders', 5, 'Layla' );
		$record( '4' );

		$this->assertSame( array( 3, 5, 6, 7 ), $seen );
		$sorted = $seen;
		sort( $sorted );
		$this->assertSame( $sorted, $seen, 'the version on queue items only increases' );
	}

	public function test_a_draft_saved_after_a_rollback_keeps_its_number_on_publish(): void {
		$this->h->mappings->seedLive( $this->conn, 'orders', $this->rules( 'v1' ), 'seed' );
		$this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'v2' ), null, 'Layla' );
		$this->h->mappings->publish( $this->conn, 'orders', 'Layla' );
		$this->h->mappings->rollback( $this->conn, 'orders', 1, 'Layla' );
		$draft = $this->h->mappings->saveDraft( $this->conn, 'orders', $this->rules( 'v4' ), null, 'Layla' );
		$this->assertSame( 4, $draft['version'] );
		$this->assertSame( 4, $this->h->mappings->publish( $this->conn, 'orders', 'Layla' )['version'], 'nothing was created after this draft, so nothing changes' );
	}

	public function test_renumbering_is_part_of_the_publish_transaction(): void {
		$this->liveThreeDraftFour();
		$this->h->mappings->rollback( $this->conn, 'orders', 2, 'Layla' );
		// A statement that fails halfway must undo the retire and the renumber together.
		$failing = static fn( string $query ): string => str_contains( $query, "SET version = 6" ) ? 'UPDATE definitely_not_a_table SET x = 1' : $query;
		add_filter( 'query', $failing );
		$this->h->wpdb->suppress_errors( true );
		try {
			$this->h->mappings->publish( $this->conn, 'orders', 'Layla' );
			$this->fail( 'Expected the publish to fail.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The mapping could not be saved.', $e->getMessage() );
		} finally {
			$this->h->wpdb->suppress_errors( false );
			remove_filter( 'query', $failing );
		}
		$this->assertSame( 5, $this->h->mappings->live( $this->conn, 'orders' )['version'], 'the rollback version is still live' );
		$this->assertSame( 4, $this->h->mappings->draft( $this->conn, 'orders' )['version'], 'and the draft still has its number' );
	}
}
