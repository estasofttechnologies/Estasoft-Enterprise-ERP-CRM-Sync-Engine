<?php
/**
 * A reaper process for the concurrency test. Not a test: it is started by QueueClaimConcurrencyTest.
 *
 * Usage: php CoreReapProcess.php <start: unix time with micro seconds, or a barrier directory>
 * Prints, as JSON, the ids its reapStale() call reports as taken back.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

$start = (string) ( $argv[1] ?? '0' );

require_once __DIR__ . '/CoreProcessBootstrap.php';

global $wpdb;
$queue = new Estasoft\SyncEngine\Core\Repositories\QueueRepository(
	$wpdb,
	new Estasoft\SyncEngine\Core\Database\Tables( $wpdb->prefix ),
	new Estasoft\SyncEngine\Core\Support\SystemClock(),
	new DateTimeZone( 'UTC' )
);

core_wait_for_start( $start );

echo json_encode( array_column( $queue->reapStale(), 'id' ) ); // phpcs:ignore
