<?php
/**
 * A process for the global-limit test. Not a test: it is started by QueueClaimConcurrencyTest.
 *
 * Usage: php CoreCapWorkerProcess.php <worker id> <global limit> <start: unix time with micro seconds, or a barrier directory> <lock: 1|0> <rounds>
 * Claims with the real ClaimPlanner, and holds what it claims (nothing finishes), so the number of rows in `syncing` is
 * what the limit allows. The count step is slowed down on purpose, so that without the claim lock several processes would
 * see the same free slot. Prints the claimed ids as JSON.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

$worker = (string) ( $argv[1] ?? 'worker' );
$global = (int) ( $argv[2] ?? 3 );
$start  = (string) ( $argv[3] ?? '0' );
$locked = '0' !== (string) ( $argv[4] ?? '1' );
$rounds = (int) ( $argv[5] ?? 6 );

require_once __DIR__ . '/CoreProcessBootstrap.php';

global $wpdb;

$tables = new Estasoft\SyncEngine\Core\Database\Tables( $wpdb->prefix );
$clock  = new Estasoft\SyncEngine\Core\Support\SystemClock();
$queue  = new Estasoft\SyncEngine\Core\Repositories\QueueRepository( $wpdb, $tables, $clock, new DateTimeZone( 'UTC' ) );
$conns  = new Estasoft\SyncEngine\Core\Repositories\ConnectionRepository( $wpdb, $tables, $clock, new Estasoft\SyncEngine\Core\Crypto\SecretBox( null ), new Estasoft\SyncEngine\Core\ConnectionSettings() );

$planner = new class( $queue, $conns, $locked ) extends Estasoft\SyncEngine\Core\Queue\ClaimPlanner {
	public function __construct( Estasoft\SyncEngine\Core\Repositories\QueueRepository $queue, Estasoft\SyncEngine\Core\Repositories\ConnectionRepository $conns, private readonly bool $useLock ) {
		parent::__construct( $queue, $conns );
	}

	protected function running( ?int $connectionId ): int {
		$count = parent::running( $connectionId );
		usleep( 40000 );
		return $count;
	}

	protected function guard( Closure $work ): mixed {
		return $this->useLock ? parent::guard( $work ) : $work();
	}
};

core_wait_for_start( $start );

$claimed = array();
for ( $round = 0; $round < $rounds; ++$round ) {
	foreach ( $planner->claim( $global, $worker, 600 ) as $item ) {
		$claimed[] = $item->id;
	}
}
echo json_encode( $claimed ); // phpcs:ignore
