<?php
/**
 * A worker process for the claim concurrency test. Not a test: it is started by CoreClaimConcurrencyTest.
 *
 * Usage: php CoreClaimWorkerProcess.php <worker id> <batch size> <start at (unix time with micro seconds)> <connection id>
 * Environment: the same WP_* variables as the integration bootstrap. Prints the claimed ids as JSON.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

$worker = (string) ( $argv[1] ?? 'worker' );
$batch  = (int) ( $argv[2] ?? 5 );
$start  = (float) ( $argv[3] ?? 0 );
$conn   = (int) ( $argv[4] ?? 0 );

require_once __DIR__ . '/CoreProcessBootstrap.php';

global $wpdb;
$queue = new Estasoft\SyncEngine\Core\Repositories\QueueRepository(
	$wpdb,
	new Estasoft\SyncEngine\Core\Database\Tables( $wpdb->prefix ),
	new Estasoft\SyncEngine\Core\Support\SystemClock(),
	new DateTimeZone( 'UTC' )
);

// Start all workers at the same moment.
while ( microtime( true ) < $start ) {
	usleep( 200 );
}

$claimed = array();
$empty   = 0;
for ( $round = 0; $round < 2000 && $empty < 3; ++$round ) {
	$items = $queue->claim( $batch, 60, $worker, $conn );
	if ( array() === $items ) {
		++$empty;
		usleep( 2000 );
		continue;
	}
	$empty = 0;
	foreach ( $items as $item ) {
		$claimed[] = $item->id;
	}
}
echo json_encode( $claimed ); // phpcs:ignore
