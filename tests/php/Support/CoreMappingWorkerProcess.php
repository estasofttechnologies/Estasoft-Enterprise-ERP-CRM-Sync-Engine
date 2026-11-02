<?php
/**
 * A process that edits and publishes mappings, for the mapping concurrency test. Not a test.
 *
 * Usage: php CoreMappingWorkerProcess.php <worker name> <rounds> <start: unix time with micro seconds, or a barrier directory> <connection id>
 * Prints the number of publishes that worked.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

$worker = (string) ( $argv[1] ?? 'worker' );
$rounds = (int) ( $argv[2] ?? 10 );
$start  = (string) ( $argv[3] ?? '0' );
$conn   = (int) ( $argv[4] ?? 1 );

require_once __DIR__ . '/CoreProcessBootstrap.php';

global $wpdb;
$repo = new Estasoft\SyncEngine\Core\Repositories\MappingRepository(
	$wpdb,
	new Estasoft\SyncEngine\Core\Database\Tables( $wpdb->prefix ),
	new Estasoft\SyncEngine\Core\Support\SystemClock()
);

core_wait_for_start( $start );

$published = 0;
$errors    = 0;
for ( $i = 0; $i < $rounds; ++$i ) {
	try {
		$repo->saveDraft( $conn, 'orders', array( array( 'id' => 'r1', 'target' => "$worker-$i" ) ), null, $worker );
		$repo->publish( $conn, 'orders', $worker );
		++$published;
	} catch ( LogicException $e ) {
		// The other process published the shared draft first. Fine.
		continue;
	} catch ( Throwable $e ) {
		++$errors;
	}
}
echo json_encode( array( 'published' => $published, 'errors' => $errors ) ); // phpcs:ignore
