<?php
/**
 * The `wp estasoft-sync` commands.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Cli;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Core\Queue\PauseController;
use Estasoft\SyncEngine\Core\Queue\QueueService;
use Estasoft\SyncEngine\Core\Queue\Worker;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Jobs\Connections\ConnectionService;
use Estasoft\SyncEngine\Jobs\Dashboard\DashboardService;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Jobs\Readiness\ReadinessService;
use Estasoft\SyncEngine\Jobs\Reconciliation\ReconService;

/**
 * Manage ESTASOFT SYNC from the command line.
 *
 * Exit codes: 0 when the command did what was asked (including "nothing to do"), 1 when it failed or, for `readiness`, when a check
 * needs fixing, and 2 when the arguments are wrong. A worker stopped by SIGTERM, SIGINT, its item limit or its memory limit exits
 * with 0, so a supervisor restarts it without raising an alarm.
 *
 * Every public method is a subcommand; WP-CLI reads the synopsis from the docblocks. Helpers are private.
 */
final class Commands {

	/**
	 * Seconds to wait when a run found nothing to do.
	 */
	private const IDLE_SECONDS = 2;

	/**
	 * Most calls one reconciliation makes while it reports progress, a stop against a run that never finishes.
	 */
	private const RECON_ROUNDS = 500;

	/**
	 * Waits for a number of seconds.
	 *
	 * @var \Closure(int):void
	 */
	private \Closure $sleep;

	/**
	 * Bytes of memory in use now.
	 *
	 * @var \Closure():int
	 */
	private \Closure $memory;

	/**
	 * @param Container      $container Services, resolved when a command runs.
	 * @param CliOutput      $out       Where messages go.
	 * @param StopSignal     $stop      Set by SIGTERM and SIGINT.
	 * @param \Closure|null  $sleep     Waits for seconds; sleep() by default.
	 * @param \Closure|null  $memory    Bytes in use; memory_get_usage( true ) by default.
	 */
	public function __construct(
		private readonly Container $container,
		private readonly CliOutput $out,
		private readonly StopSignal $stop,
		?\Closure $sleep = null,
		?\Closure $memory = null
	) {
		$this->sleep  = $sleep ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
		$this->memory = $memory ?? static fn(): int => memory_get_usage( true );
	}

	/**
	 * Run a worker that sends queued items until it is stopped or reaches a limit.
	 *
	 * It claims items the same way the background runner does, with the same ordering, retry, pause and rate-limit rules, so it can run
	 * next to it or instead of it (Settings, Runtime profile, High volume). It stops cleanly on SIGTERM or SIGINT, after the item it is
	 * sending, and exits with 0 when it reaches a limit so that a supervisor (systemd, supervisord, cron) restarts it.
	 *
	 * ## OPTIONS
	 *
	 * [--concurrency=<n>]
	 * : Items claimed at a time, 1 to 10. The lower of this and the setting "Items at once" applies.
	 * ---
	 * default: 2
	 * ---
	 *
	 * [--max-items=<n>]
	 * : Stop after claiming this many items.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--max-memory=<mb>]
	 * : Stop when PHP uses this many megabytes.
	 * ---
	 * default: 128
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-sync worker --concurrency=4 --max-items=1000
	 *
	 * @param array<int,string>    $args       Positional arguments (none).
	 * @param array<string,string> $assoc_args Options.
	 */
	public function worker( array $args, array $assoc_args ): void {
		unset( $args );
		$concurrency = $this->intOption( $assoc_args, 'concurrency', 2, 1, 10 );
		$maxItems    = $this->intOption( $assoc_args, 'max-items', 500, 1, 1000000 );
		$maxMemory   = $this->intOption( $assoc_args, 'max-memory', 128, 16, 8192 );

		/** @var Worker $worker */
		$worker = $this->container->get( Services::WORKER );
		$this->stop->listen();
		/* translators: 1: items at once, 2: item limit, 3: memory limit in megabytes. */
		$this->out->log( sprintf( __( 'Worker started: %1$d at a time, stops after %2$d items or %3$d MB.', 'estasoft-erp-crm-sync-engine' ), $concurrency, $maxItems, $maxMemory ) );

		$claimed = 0;
		$reason  = '';
		while ( '' === $reason ) {
			$report   = $worker->run( Worker::MAX_SECONDS, $concurrency );
			$claimed += $report->claimed;
			if ( $report->claimed > 0 ) {
				$this->out->log(
					sprintf(
						/* translators: 1: items claimed, 2: items sent, 3: items to retry, 4: items failed, 5: seconds, 6: items so far. */
						__( 'Claimed %1$d: %2$d sent, %3$d to retry, %4$d failed, in %5$.1f s. %6$d so far.', 'estasoft-erp-crm-sync-engine' ),
						$report->claimed,
						$report->synced,
						$report->retrying,
						$report->failed,
						$report->seconds,
						$claimed
					)
				);
			}
			$reason = $this->stopReason( $claimed, $maxItems, $maxMemory );
			if ( '' === $reason && 0 === $report->claimed ) {
				( $this->sleep )( self::IDLE_SECONDS );
				$reason = $this->stopReason( $claimed, $maxItems, $maxMemory );
			}
		}
		/* translators: 1: why the worker stopped, 2: items claimed. */
		$this->out->success( sprintf( __( 'Worker stopped: %1$s. %2$d items handled.', 'estasoft-erp-crm-sync-engine' ), $reason, $claimed ) );
	}

	/**
	 * Show the queue counts and the state of each connection.
	 *
	 * ## OPTIONS
	 *
	 * [--connection=<id>]
	 * : Only this connection.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-sync status
	 *     wp estasoft-sync status --format=json
	 *
	 * @param array<int,string>    $args       Positional arguments (none).
	 * @param array<string,string> $assoc_args Options.
	 */
	public function status( array $args, array $assoc_args ): void {
		unset( $args );
		$format = $this->format( $assoc_args, array( 'table', 'csv', 'json', 'yaml' ) );
		$id     = $this->intOption( $assoc_args, 'connection', 0, 0, PHP_INT_MAX );

		/** @var DashboardService $dashboard */
		$dashboard = $this->container->get( JobServices::DASHBOARD );
		/** @var ConnectionService $connections */
		$connections = $this->container->get( JobServices::CONNECTIONS );
		$status      = $dashboard->status( $id > 0 ? $id : null );
		$summaries   = array_values(
			array_filter(
				$connections->summaries(),
				static fn( array $summary ): bool => 0 === $id || (int) ( $summary['id'] ?? 0 ) === $id
			)
		);

		if ( in_array( $format, array( 'json', 'yaml' ), true ) ) {
			$this->out->value(
				$format,
				array(
					'status'      => $status,
					'connections' => $summaries,
				)
			);
			return;
		}
		$rows = array(
			$this->row( __( 'Mode', 'estasoft-erp-crm-sync-engine' ), (string) ( $status['mode'] ?? '' ) ),
			$this->row( __( 'Synced today', 'estasoft-erp-crm-sync-engine' ), (string) ( $status['synced_today'] ?? 0 ) ),
			$this->row( __( 'Waiting', 'estasoft-erp-crm-sync-engine' ), (string) ( $status['waiting'] ?? 0 ) ),
			$this->row( __( 'Needs attention', 'estasoft-erp-crm-sync-engine' ), (string) ( $status['needs_attention'] ?? 0 ) ),
			$this->row( __( 'Skipped', 'estasoft-erp-crm-sync-engine' ), (string) ( $status['skipped'] ?? 0 ) ),
			$this->row( __( 'Oldest waiting', 'estasoft-erp-crm-sync-engine' ), (string) ( $status['oldest_waiting_at'] ?? '-' ) ),
		);
		foreach ( $summaries as $summary ) {
			$rows[] = $this->row(
				/* translators: 1: connection id, 2: connection name. */
				sprintf( __( 'Connection %1$d (%2$s)', 'estasoft-erp-crm-sync-engine' ), (int) ( $summary['id'] ?? 0 ), (string) ( $summary['label'] ?? '' ) ),
				(string) ( $summary['status'] ?? '' ) . ( empty( $summary['paused_until'] ) ? '' : ' (' . (string) $summary['paused_until'] . ')' )
			);
		}
		$this->out->items( $format, $rows, array( 'item', 'value' ) );
	}

	/**
	 * Send failed or retrying items back to the queue.
	 *
	 * Give the ids, or an error code (with a cause to retry one group of Needs attention). The items are tried again with the same
	 * idempotency key, oldest first, so a retry can never create a second order in your system.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Comma-separated queue item ids.
	 *
	 * [--code=<code>]
	 * : An error code such as SYNC-MAP-001. Retries every failed item with that code.
	 *
	 * [--cause=<cause>]
	 * : With --code: only the group with this cause, for example "SKU CS-LAMP-07".
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-sync retry --code=SYNC-MAP-001
	 *     wp estasoft-sync retry --ids=10485,10491
	 *
	 * @param array<int,string>    $args       Positional arguments (none).
	 * @param array<string,string> $assoc_args Options.
	 */
	public function retry( array $args, array $assoc_args ): void {
		unset( $args );
		$code  = trim( $this->textOption( $assoc_args, 'code' ) ?? '' );
		$cause = trim( $this->textOption( $assoc_args, 'cause' ) ?? '' );
		$list  = $this->textOption( $assoc_args, 'ids' );
		$ids   = null === $list ? array() : $this->ids( $list );

		if ( '' === $code && array() === $ids ) {
			$this->out->fail( __( 'Give --ids with queue item ids, or --code with an error code such as SYNC-MAP-001.', 'estasoft-erp-crm-sync-engine' ), 2 );
		}
		if ( '' !== $code && 1 !== preg_match( '/^SYNC-[A-Z]+-[0-9]{3}$/', $code ) ) {
			$this->out->fail( __( 'The error code must look like SYNC-MAP-001.', 'estasoft-erp-crm-sync-engine' ), 2 );
		}
		if ( '' === $code && '' !== $cause ) {
			$this->out->fail( __( '--cause needs --code.', 'estasoft-erp-crm-sync-engine' ), 2 );
		}

		/** @var QueueService $queue */
		$queue = $this->container->get( Services::QUEUE_SERVICE );
		$count = '' !== $code ? $queue->retryGroup( $code, '' === $cause ? null : $cause ) : $queue->retryMany( $ids );
		if ( 0 === $count ) {
			$this->out->warning( __( 'Nothing was retried. Only failed and retrying items can be retried.', 'estasoft-erp-crm-sync-engine' ) );
			return;
		}
		/* translators: %d: number of items. */
		$this->out->success( sprintf( _n( '%d item moved back to the queue.', '%d items moved back to the queue.', $count, 'estasoft-erp-crm-sync-engine' ), $count ) );
	}

	/**
	 * Pause syncing. Items keep arriving in the queue and wait.
	 *
	 * ## OPTIONS
	 *
	 * [--connection=<id>]
	 * : Pause only this connection. Without it every connection is paused.
	 *
	 * [--minutes=<n>]
	 * : Resume by itself after this many minutes. Without it the pause lasts until `resume`.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-sync pause --minutes=60
	 *
	 * @param array<int,string>    $args       Positional arguments (none).
	 * @param array<string,string> $assoc_args Options.
	 */
	public function pause( array $args, array $assoc_args ): void {
		unset( $args );
		$id      = $this->intOption( $assoc_args, 'connection', 0, 0, PHP_INT_MAX );
		$minutes = $this->intOption( $assoc_args, 'minutes', 0, 0, 43200 );

		/** @var PauseController $pause */
		$pause = $this->container->get( Services::PAUSE_CONTROLLER );
		$count = $id > 0 ? ( $pause->pause( $id, $minutes > 0 ? $minutes : null ) ? 1 : 0 ) : $pause->pauseAll( $minutes > 0 ? $minutes : null );
		if ( 0 === $count ) {
			$this->out->fail( __( 'There is no connection that can be paused.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$this->out->success(
			$minutes > 0
				/* translators: 1: number of connections, 2: minutes. */
				? sprintf( _n( '%1$d connection paused for %2$d minutes.', '%1$d connections paused for %2$d minutes.', $count, 'estasoft-erp-crm-sync-engine' ), $count, $minutes )
				/* translators: %d: number of connections. */
				: sprintf( _n( '%d connection paused until you resume it.', '%d connections paused until you resume them.', $count, 'estasoft-erp-crm-sync-engine' ), $count )
		);
	}

	/**
	 * Resume syncing. Also clears a pause caused by a failing system, or by a sign-in that you have fixed.
	 *
	 * ## OPTIONS
	 *
	 * [--connection=<id>]
	 * : Resume only this connection. Without it every paused connection is resumed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-sync resume
	 *
	 * @param array<int,string>    $args       Positional arguments (none).
	 * @param array<string,string> $assoc_args Options.
	 */
	public function resume( array $args, array $assoc_args ): void {
		unset( $args );
		$id = $this->intOption( $assoc_args, 'connection', 0, 0, PHP_INT_MAX );

		/** @var PauseController $pause */
		$pause = $this->container->get( Services::PAUSE_CONTROLLER );
		$count = $id > 0 ? ( $pause->resume( $id ) ? 1 : 0 ) : $pause->resumeAll();
		if ( 0 === $count ) {
			$this->out->warning( __( 'No connection was paused.', 'estasoft-erp-crm-sync-engine' ) );
			return;
		}
		/* translators: %d: number of connections. */
		$this->out->success( sprintf( _n( '%d connection resumed.', '%d connections resumed.', $count, 'estasoft-erp-crm-sync-engine' ), $count ) );
	}

	/**
	 * Compare recent WooCommerce orders with the connected system (read-only) and list the differences.
	 *
	 * Orders missing in the system are queued again when "automatic repair" is on in Reconciliation; amounts and refunds always wait for a person.
	 *
	 * ## OPTIONS
	 *
	 * [--window=<days>]
	 * : Days to compare, 1 to 30.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--connection=<id>]
	 * : Compare this connection. Without it the first connection that can be compared is used.
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-sync reconcile --window=3
	 *
	 * @param array<int,string>    $args       Positional arguments (none).
	 * @param array<string,string> $assoc_args Options.
	 */
	public function reconcile( array $args, array $assoc_args ): void {
		unset( $args );
		$window = $this->intOption( $assoc_args, 'window', 7, 1, 30 );
		$id     = $this->intOption( $assoc_args, 'connection', 0, 0, PHP_INT_MAX );

		/** @var ReconService $recon */
		$recon = $this->container->get( JobServices::RECONCILIATION );
		$run   = array();
		$done  = -1;
		for ( $round = 0; $round < self::RECON_ROUNDS; ++$round ) {
			try {
				$run = $recon->run( $window, $id > 0 ? $id : null, 'manual' );
			} catch ( JobException $error ) {
				$this->out->fail( $error->getMessage() );
			}
			if ( 'running' !== (string) ( $run['status'] ?? '' ) ) {
				break;
			}
			$progress = is_array( $run['progress'] ?? null ) ? $run['progress'] : array();
			$now      = (int) ( $progress['done'] ?? 0 );
			/* translators: 1: orders compared so far, 2: orders to compare. */
			$this->out->log( sprintf( __( 'Compared %1$d of %2$d orders…', 'estasoft-erp-crm-sync-engine' ), $now, (int) ( $progress['total'] ?? 0 ) ) );
			if ( $now <= $done ) {
				$this->out->fail( __( 'The comparison is not moving. Run it again in a minute.', 'estasoft-erp-crm-sync-engine' ) );
			}
			$done = $now;
		}
		if ( 'running' === (string) ( $run['status'] ?? '' ) ) {
			$this->out->fail( __( 'The comparison did not finish. Run it again to carry on.', 'estasoft-erp-crm-sync-engine' ) );
		}
		if ( 'failed' === (string) ( $run['status'] ?? '' ) ) {
			$this->out->fail( __( 'The connected system could not be read, so nothing was compared. Check the connection and run it again.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$differences = (int) ( $run['differences'] ?? 0 );
		$this->out->success(
			sprintf(
				/* translators: 1: orders compared, 2: orders that match, 3: differences found, 4: orders queued again. */
				__( 'Compared %1$d orders: %2$d match, %3$d differ, %4$d queued again.', 'estasoft-erp-crm-sync-engine' ),
				(int) ( $run['compared'] ?? 0 ),
				(int) ( $run['matched'] ?? 0 ),
				$differences,
				(int) ( $run['repaired'] ?? 0 )
			)
		);
		if ( $differences > 0 ) {
			$this->out->log( __( 'Open Reconciliation in ESTASOFT SYNC to repair or ignore the differences.', 'estasoft-erp-crm-sync-engine' ) );
		}
	}

	/**
	 * Run the readiness checks. Exits with 1 when a check needs fixing, so a deploy script can stop.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp estasoft-sync readiness
	 *
	 * @param array<int,string>    $args       Positional arguments (none).
	 * @param array<string,string> $assoc_args Options.
	 */
	public function readiness( array $args, array $assoc_args ): void {
		unset( $args );
		$format = $this->format( $assoc_args, array( 'table', 'csv', 'json', 'yaml' ) );

		/** @var ReadinessService $readiness */
		$readiness = $this->container->get( JobServices::READINESS );
		$result    = $readiness->run();
		$checks    = is_array( $result['checks'] ?? null ) ? $result['checks'] : array();

		if ( in_array( $format, array( 'json', 'yaml' ), true ) ) {
			$this->out->value( $format, $result );
		} else {
			$rows = array();
			foreach ( $checks as $check ) {
				$rows[] = array(
					'status' => (string) ( $check['status'] ?? '' ),
					'check'  => (string) ( $check['title'] ?? '' ),
					'detail' => (string) ( $check['detail'] ?? '' ),
				);
			}
			$this->out->items( $format, $rows, array( 'status', 'check', 'detail' ) );
		}
		if ( ! empty( $result['blocked'] ) ) {
			$this->out->fail( __( 'At least one check needs fixing before setup can finish.', 'estasoft-erp-crm-sync-engine' ) );
		}
		$this->out->success( __( 'No check needs fixing.', 'estasoft-erp-crm-sync-engine' ) );
	}

	/**
	 * Why the worker should stop now, or an empty string to carry on.
	 *
	 * @param int $claimed   Items claimed so far.
	 * @param int $maxItems  Item limit.
	 * @param int $maxMemory Memory limit in megabytes.
	 */
	private function stopReason( int $claimed, int $maxItems, int $maxMemory ): string {
		if ( $this->stop->requested() ) {
			return __( 'stop signal received', 'estasoft-erp-crm-sync-engine' );
		}
		if ( $claimed >= $maxItems ) {
			return __( 'item limit reached', 'estasoft-erp-crm-sync-engine' );
		}
		if ( ( $this->memory )() >= $maxMemory * 1048576 ) {
			return __( 'memory limit reached', 'estasoft-erp-crm-sync-engine' );
		}
		return '';
	}

	/**
	 * The text of an option, or null when it was not given.
	 *
	 * WP-CLI hands an option written without a value (`--ids`, `--minutes`) over as `true`, which would read as the text "1". That must
	 * never mean item 1, a pause of one minute or connection 1, so a value that is not text or a number stops the command with exit code 2.
	 *
	 * @param array<string,mixed> $assoc Options.
	 * @param string              $name  Option name.
	 * @return string|null
	 */
	private function textOption( array $assoc, string $name ): ?string {
		if ( ! array_key_exists( $name, $assoc ) ) {
			return null;
		}
		$value = $assoc[ $name ];
		if ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$this->out->fail(
			/* translators: %s: option name. */
			sprintf( __( '--%1$s needs a value, for example --%1$s=<value>.', 'estasoft-erp-crm-sync-engine' ), $name ),
			2
		);
	}

	/**
	 * A whole-number option inside a range; a value outside the range or not a number stops the command with exit code 2.
	 *
	 * @param array<string,mixed> $assoc   Options.
	 * @param string              $name    Option name.
	 * @param int                 $default Value when the option is absent.
	 * @param int                 $min     Smallest allowed.
	 * @param int                 $max     Largest allowed.
	 */
	private function intOption( array $assoc, string $name, int $default, int $min, int $max ): int {
		$raw = $this->textOption( $assoc, $name );
		if ( null === $raw ) {
			return $default;
		}
		$raw = trim( $raw );
		if ( 1 !== preg_match( '/^\d+$/', $raw ) || (int) $raw < $min || (int) $raw > $max ) {
			$this->out->fail(
				/* translators: 1: option name, 2: smallest value, 3: largest value. */
				sprintf( __( '--%1$s must be a whole number from %2$d to %3$d.', 'estasoft-erp-crm-sync-engine' ), $name, $min, min( $max, 1000000 ) ),
				2
			);
		}
		return (int) $raw;
	}

	/**
	 * The output format, checked against the allowed list.
	 *
	 * @param array<string,mixed> $assoc   Options.
	 * @param array<int,string>   $allowed Allowed formats.
	 */
	private function format( array $assoc, array $allowed ): string {
		$format = strtolower( $this->textOption( $assoc, 'format' ) ?? 'table' );
		if ( ! in_array( $format, $allowed, true ) ) {
			/* translators: %s: the allowed formats. */
			$this->out->fail( sprintf( __( '--format must be one of: %s.', 'estasoft-erp-crm-sync-engine' ), implode( ', ', $allowed ) ), 2 );
		}
		return $format;
	}

	/**
	 * Queue item ids from a comma-separated list.
	 *
	 * @param string $list Text such as "10485, 10491".
	 * @return array<int,int>
	 */
	private function ids( string $list ): array {
		$ids = array();
		foreach ( explode( ',', $list ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( 1 !== preg_match( '/^\d+$/', $part ) || (int) $part < 1 ) {
				$this->out->fail( __( '--ids must be a comma-separated list of queue item ids.', 'estasoft-erp-crm-sync-engine' ), 2 );
			}
			$ids[ (int) $part ] = (int) $part;
		}
		return array_values( $ids );
	}

	/**
	 * One row of the status table.
	 *
	 * @param string $item  Row name.
	 * @param string $value Value.
	 * @return array{item:string,value:string}
	 */
	private function row( string $item, string $value ): array {
		return array(
			'item'  => $item,
			'value' => $value,
		);
	}
}
