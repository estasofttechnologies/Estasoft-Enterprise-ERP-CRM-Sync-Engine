<?php
/**
 * A worker process for the claim concurrency test. Not a test: it is started by CoreClaimConcurrencyTest.
 *
 * Usage: php CoreClaimWorkerProcess.php <worker id> <batch size> <start: unix time with micro seconds, or a barrier directory> <connection id>
 * Environment: the same WP_* variables as the integration bootstrap. Prints the claimed ids as JSON.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

$worker = (string) ( $argv[1] ?? 'worker' );
$batch  = (int) ( $argv[2] ?? 5 );
$start  = (string) ( $argv[3] ?? '0' );
$conn   = (int) ( $argv[4] ?? 0 );

require_once __DIR__ . '/CoreProcessBootstrap.php';

global $wpdb;
$queue_table = ( new Estasoft\SyncEngine\Core\Database\Tables( $wpdb->prefix ) )->queue();
$queue = new Estasoft\SyncEngine\Core\Repositories\QueueRepository(
	$wpdb,
	new Estasoft\SyncEngine\Core\Database\Tables( $wpdb->prefix ),
	new Estasoft\SyncEngine\Core\Support\SystemClock(),
	new DateTimeZone( 'UTC' )
);

// Start all workers at the same moment.
core_wait_for_start( $start );

$claimed  = array();
$give_up  = microtime( true ) + 90;
// Something is still claimable when a queued item has no unfinished or failed item before it in its order.
$claimable = "SELECT COUNT(*) FROM {$queue_table} q WHERE q.connection_id = %d AND q.state = 'queued' AND NOT EXISTS (SELECT 1 FROM {$queue_table} e WHERE e.connection_id = q.connection_id AND e.partition_key = q.partition_key AND e.id < q.id AND e.state IN ('queued','syncing','retrying','failed'))";
while ( microtime( true ) < $give_up ) {
	$items = $queue->claim( $batch, 60, $worker, $conn );
	if ( array() !== $items ) {
		foreach ( $items as $item ) {
			$claimed[] = $item->id;
		}
		continue;
	}
	// An empty answer is only the end when nothing is left to claim; otherwise another worker was quicker, so look again.
	if ( 0 === (int) $wpdb->get_var( $wpdb->prepare( $claimable, $conn ) ) ) { // phpcs:ignore
		break;
	}
	usleep( 2000 );
}
echo json_encode( $claimed ); // phpcs:ignore
