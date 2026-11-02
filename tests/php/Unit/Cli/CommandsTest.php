<?php
/**
 * The `wp estasoft-sync` commands, with recorders in place of the services.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Unit\Cli;

use Estasoft\SyncEngine\Cli\Commands;
use Estasoft\SyncEngine\Cli\StopSignal;
use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Core\Queue\WorkerReport;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Jobs\JobException;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Tests\Support\RestCliExit;
use Estasoft\SyncEngine\Tests\Support\RestFake;
use Estasoft\SyncEngine\Tests\Support\RestFakeCliOutput;
use PHPUnit\Framework\TestCase;

final class CommandsTest extends TestCase {

	private Container $container;

	private RestFakeCliOutput $out;

	private StopSignal $stop;

	/**
	 * Seconds the commands waited.
	 *
	 * @var array<int,int>
	 */
	private array $slept = array();

	/**
	 * Bytes of memory the commands see.
	 */
	private int $memory = 10 * 1048576;

	protected function setUp(): void {
		$this->container = new Container();
		$this->out       = new RestFakeCliOutput();
		$this->stop      = new StopSignal();
		$this->slept     = array();
		$this->memory    = 10 * 1048576;
	}

	private function commands(): Commands {
		return new Commands(
			$this->container,
			$this->out,
			$this->stop,
			function ( int $seconds ): void {
				$this->slept[] = $seconds;
			},
			fn(): int => $this->memory
		);
	}

	/**
	 * A report of a worker run.
	 *
	 * @param int $claimed Items claimed.
	 * @param int $synced  Items sent.
	 * @param int $failed  Items failed.
	 */
	private function report( int $claimed, int $synced = 0, int $failed = 0 ): WorkerReport {
		$report          = new WorkerReport();
		$report->claimed = $claimed;
		$report->synced  = $synced;
		$report->failed  = $failed;
		return $report;
	}

	/**
	 * Run a command and return the exit code it ended with, 0 when it ended normally.
	 *
	 * @param callable $command The command.
	 */
	private function exitCode( callable $command ): int {
		try {
			$command();
		} catch ( RestCliExit $exit ) {
			return $exit->getCode();
		}
		return 0;
	}

	public function test_the_worker_stops_on_its_item_limit_and_exits_with_success_so_a_supervisor_restarts_it(): void {
		$worker = new RestFake( array( 'run' => fn(): WorkerReport => $this->report( 3, 3 ) ) );
		$this->container->instance( Services::WORKER, $worker );
		$this->commands()->worker( array(), array( 'max-items' => '7', 'concurrency' => '4' ) );

		$this->assertCount( 3, $worker->called( 'run' ), 'three runs of 3 reach 7' );
		$this->assertSame( array( 20, 4 ), $worker->called( 'run' )[0], 'at most 20 seconds, the concurrency given' );
		$this->assertSame( array(), $this->slept, 'busy, so no waiting' );
		$this->assertStringContainsString( 'item limit reached', $this->out->of( 'success' )[0] );
		$this->assertStringContainsString( '9 items handled', $this->out->of( 'success' )[0] );
		$this->assertStringContainsString( 'Worker started: 4 at a time, stops after 7 items or 128 MB.', $this->out->of( 'log' )[0] );
		$this->assertStringContainsString( 'Claimed 3: 3 sent, 0 to retry, 0 failed', $this->out->of( 'log' )[1] );
		$this->assertSame( array(), $this->out->of( 'error' ) );
	}

	public function test_the_worker_stops_cleanly_after_the_run_it_is_in_when_it_gets_a_stop_signal(): void {
		$runs   = 0;
		$worker = new RestFake(
			array(
				'run' => function () use ( &$runs ): WorkerReport {
					++$runs;
					if ( 2 === $runs ) {
						$this->stop->request();
					}
					return $this->report( 1, 1 );
				},
			)
		);
		$this->container->instance( Services::WORKER, $worker );
		$this->commands()->worker( array(), array() );
		$this->assertSame( 2, $runs, 'the second run finished before it stopped' );
		$this->assertStringContainsString( 'stop signal received', $this->out->of( 'success' )[0] );
	}

	public function test_the_worker_stops_when_memory_use_reaches_the_limit(): void {
		$worker = new RestFake( array( 'run' => function (): WorkerReport {
			$this->memory += 64 * 1048576;
			return $this->report( 1, 1 );
		} ) );
		$this->container->instance( Services::WORKER, $worker );
		$this->commands()->worker( array(), array( 'max-memory' => '128' ) );
		$this->assertCount( 2, $worker->called( 'run' ), '10 + 64 + 64 = 138 MB passes 128 MB after the second run' );
		$this->assertStringContainsString( 'memory limit reached', $this->out->of( 'success' )[0] );
	}

	public function test_an_idle_worker_waits_two_seconds_between_runs_and_says_nothing_about_empty_runs(): void {
		$worker = new RestFake( array( 'run' => fn(): WorkerReport => $this->report( 0 ) ) );
		$this->container->instance( Services::WORKER, $worker );
		$sleeps = 0;
		$run    = new Commands(
			$this->container,
			$this->out,
			$this->stop,
			function ( int $seconds ) use ( &$sleeps ): void {
				$this->slept[] = $seconds;
				if ( ++$sleeps === 3 ) {
					$this->stop->request();
				}
			},
			fn(): int => $this->memory
		);
		$run->worker( array(), array() );
		$this->assertSame( array( 2, 2, 2 ), $this->slept );
		$this->assertCount( 3, $worker->called( 'run' ) );
		$this->assertCount( 1, $this->out->of( 'log' ), 'only the start line' );
		$this->assertStringContainsString( '0 items handled', $this->out->of( 'success' )[0] );
	}

	/**
	 * @dataProvider badWorkerOptions
	 *
	 * @param array<string,string> $options Options.
	 */
	public function test_worker_options_outside_their_range_stop_the_command_with_exit_code_2_before_any_work( array $options ): void {
		$worker = new RestFake();
		$this->container->instance( Services::WORKER, $worker );
		$this->assertSame( 2, $this->exitCode( fn() => $this->commands()->worker( array(), $options ) ) );
		$this->assertSame( array(), $worker->calls );
	}

	/**
	 * Wrong options.
	 *
	 * @return array<string,array{0:array<string,string>}>
	 */
	public static function badWorkerOptions(): array {
		return array(
			'concurrency zero'   => array( array( 'concurrency' => '0' ) ),
			'concurrency eleven' => array( array( 'concurrency' => '11' ) ),
			'max-items text'     => array( array( 'max-items' => 'many' ) ),
			'max-items negative' => array( array( 'max-items' => '-5' ) ),
			'max-memory tiny'    => array( array( 'max-memory' => '1' ) ),
		);
	}

	/**
	 * @dataProvider bareOptions
	 *
	 * @param string               $command Subcommand.
	 * @param array<string,mixed>  $options Options with one given without a value, as WP-CLI passes it (true).
	 */
	public function test_an_option_given_without_a_value_stops_the_command_with_a_usage_error_and_does_nothing( string $command, array $options ): void {
		$services = array(
			Services::WORKER           => new RestFake(),
			Services::QUEUE_SERVICE    => new RestFake(),
			Services::PAUSE_CONTROLLER => new RestFake(),
			JobServices::DASHBOARD     => new RestFake(),
			JobServices::CONNECTIONS   => new RestFake(),
			JobServices::RECONCILIATION => new RestFake(),
			JobServices::READINESS     => new RestFake(),
		);
		foreach ( $services as $id => $service ) {
			$this->container->instance( $id, $service );
		}
		$this->assertSame( 2, $this->exitCode( fn() => $this->commands()->$command( array(), $options ) ), $command . ' ' . (string) wp_json_encode( $options ) );
		foreach ( $services as $id => $service ) {
			$this->assertSame( array(), $service->calls, $id . ' was not touched' );
		}
		$name = (string) array_key_first( array_filter( $options, static fn( $value ): bool => ! is_string( $value ) ) );
		$this->assertSame( array( "--$name needs a value, for example --$name=<value>." ), $this->out->of( 'error' ) );
	}

	/**
	 * Options written without a value.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public static function bareOptions(): array {
		return array(
			'retry --ids'          => array( 'retry', array( 'ids' => true ) ),
			'retry --code'         => array( 'retry', array( 'code' => true ) ),
			'retry --cause'        => array( 'retry', array( 'code' => 'SYNC-MAP-001', 'cause' => true ) ),
			'pause --minutes'      => array( 'pause', array( 'minutes' => true ) ),
			'pause --connection'   => array( 'pause', array( 'connection' => true ) ),
			'resume --connection'  => array( 'resume', array( 'connection' => true ) ),
			'status --connection'  => array( 'status', array( 'connection' => true ) ),
			'status --format'      => array( 'status', array( 'format' => true ) ),
			'reconcile --window'   => array( 'reconcile', array( 'window' => true ) ),
			'reconcile --connection' => array( 'reconcile', array( 'connection' => true ) ),
			'readiness --format'   => array( 'readiness', array( 'format' => true ) ),
			'worker --concurrency' => array( 'worker', array( 'concurrency' => true ) ),
			'worker --max-items'   => array( 'worker', array( 'max-items' => true ) ),
			'worker --max-memory'  => array( 'worker', array( 'max-memory' => true ) ),
			'an array'             => array( 'pause', array( 'minutes' => array( '5' ) ) ),
		);
	}

	public function test_numbers_given_as_numbers_and_empty_values_are_still_handled_as_before(): void {
		$pause = new RestFake( array( 'pauseAll' => 1 ) );
		$this->container->instance( Services::PAUSE_CONTROLLER, $pause );
		$this->commands()->pause( array(), array( 'minutes' => 30 ) );
		$this->assertSame( array( array( 30 ) ), $pause->called( 'pauseAll' ), 'WP-CLI can hand over an int from runcommand' );
		$this->assertSame( 2, $this->exitCode( fn() => $this->commands()->pause( array(), array( 'minutes' => '' ) ) ), 'an empty value is not a number' );
		$this->assertSame( 2, $this->exitCode( fn() => $this->commands()->pause( array(), array( 'minutes' => 1.5 ) ) ) );
	}

	public function test_status_prints_a_two_column_table_with_every_connection(): void {
		$this->container->instance( JobServices::DASHBOARD, new RestFake( array( 'status' => array( 'mode' => 'normal', 'synced_today' => 312, 'waiting' => 14, 'needs_attention' => 5, 'skipped' => 2, 'oldest_waiting_at' => '2026-11-02T10:00:00Z' ) ) ) );
		$this->container->instance(
			JobServices::CONNECTIONS,
			new RestFake( array( 'summaries' => array( array( 'id' => 1, 'label' => 'Odoo', 'status' => 'paused', 'paused_until' => '2026-11-02T13:00:00Z' ), array( 'id' => 2, 'label' => 'Other', 'status' => 'active' ) ) ) )
		);
		$this->commands()->status( array(), array() );
		$this->assertSame( 'table', $this->out->tables[0][0] );
		$this->assertSame( array( 'item', 'value' ), $this->out->tables[0][2] );
		$rows = array_column( $this->out->tables[0][1], 'value', 'item' );
		$this->assertSame( '312', $rows['Synced today'] );
		$this->assertSame( '5', $rows['Needs attention'] );
		$this->assertSame( 'paused (2026-11-02T13:00:00Z)', $rows['Connection 1 (Odoo)'] );
		$this->assertSame( 'active', $rows['Connection 2 (Other)'] );
	}

	public function test_status_as_json_prints_the_whole_status_and_can_be_limited_to_one_connection(): void {
		$dashboard = new RestFake( array( 'status' => array( 'mode' => 'normal' ) ) );
		$this->container->instance( JobServices::DASHBOARD, $dashboard );
		$this->container->instance( JobServices::CONNECTIONS, new RestFake( array( 'summaries' => array( array( 'id' => 1 ), array( 'id' => 2 ) ) ) ) );
		$this->commands()->status( array(), array( 'format' => 'json', 'connection' => '2' ) );
		$this->assertSame( array( array( 2 ) ), $dashboard->called( 'status' ) );
		$this->assertSame( 'json', $this->out->values[0][0] );
		$this->assertSame( array( array( 'id' => 2 ) ), $this->out->values[0][1]['connections'] );
		$this->assertSame( array( 'mode' => 'normal' ), $this->out->values[0][1]['status'] );
	}

	public function test_status_refuses_an_unknown_format(): void {
		$this->assertSame( 2, $this->exitCode( fn() => $this->commands()->status( array(), array( 'format' => 'xml' ) ) ) );
		$this->assertStringContainsString( 'table, csv, json, yaml', $this->out->of( 'error' )[0] );
	}

	public function test_retry_needs_ids_or_a_code_and_checks_what_it_is_given(): void {
		$queue = new RestFake();
		$this->container->instance( Services::QUEUE_SERVICE, $queue );
		foreach ( array(
			array(),
			array( 'code' => 'MAP-001' ),
			array( 'code' => "SYNC-MAP-001'" ),
			array( 'ids' => '10485, abc' ),
			array( 'ids' => '0' ),
			array( 'cause' => 'SKU A' ),
		) as $options ) {
			$this->assertSame( 2, $this->exitCode( fn() => $this->commands()->retry( array(), $options ) ), (string) wp_json_encode( $options ) );
		}
		$this->assertSame( array(), $queue->calls );
	}

	public function test_retry_a_group_by_code_and_cause_or_by_ids(): void {
		$queue = new RestFake( array( 'retryGroup' => 3, 'retryMany' => 1 ) );
		$this->container->instance( Services::QUEUE_SERVICE, $queue );
		$this->commands()->retry( array(), array( 'code' => 'SYNC-MAP-001', 'cause' => 'SKU CS-LAMP-07' ) );
		$this->commands()->retry( array(), array( 'code' => 'SYNC-MAP-001' ) );
		$this->commands()->retry( array(), array( 'ids' => '10485, 10491,10485' ) );
		$this->assertSame( array( array( 'SYNC-MAP-001', 'SKU CS-LAMP-07' ), array( 'SYNC-MAP-001', null ) ), $queue->called( 'retryGroup' ) );
		$this->assertSame( array( array( array( 10485, 10491 ) ) ), $queue->called( 'retryMany' ), 'duplicates are dropped' );
		$this->assertSame( array( '3 items moved back to the queue.', '3 items moved back to the queue.', '1 item moved back to the queue.' ), $this->out->of( 'success' ) );
	}

	public function test_retry_with_nothing_to_retry_warns_and_still_exits_with_success(): void {
		$this->container->instance( Services::QUEUE_SERVICE, new RestFake( array( 'retryMany' => 0 ) ) );
		$this->assertSame( 0, $this->exitCode( fn() => $this->commands()->retry( array(), array( 'ids' => '5' ) ) ) );
		$this->assertStringContainsString( 'Nothing was retried', $this->out->of( 'warning' )[0] );
	}

	public function test_pause_all_or_one_with_or_without_a_time(): void {
		$pause = new RestFake( array( 'pauseAll' => 2, 'pause' => true ) );
		$this->container->instance( Services::PAUSE_CONTROLLER, $pause );
		$this->commands()->pause( array(), array() );
		$this->commands()->pause( array(), array( 'minutes' => '60' ) );
		$this->commands()->pause( array(), array( 'connection' => '4', 'minutes' => '15' ) );
		$this->assertSame( array( array( null ), array( 60 ) ), $pause->called( 'pauseAll' ) );
		$this->assertSame( array( array( 4, 15 ) ), $pause->called( 'pause' ) );
		$this->assertSame(
			array( '2 connections paused until you resume them.', '2 connections paused for 60 minutes.', '1 connection paused for 15 minutes.' ),
			$this->out->of( 'success' )
		);
	}

	public function test_pause_with_nothing_to_pause_is_an_error_for_a_script_to_notice(): void {
		$this->container->instance( Services::PAUSE_CONTROLLER, new RestFake( array( 'pauseAll' => 0, 'pause' => false ) ) );
		$this->assertSame( 1, $this->exitCode( fn() => $this->commands()->pause( array(), array() ) ) );
		$this->assertSame( 1, $this->exitCode( fn() => $this->commands()->pause( array(), array( 'connection' => '9' ) ) ) );
		$this->assertSame( 2, $this->exitCode( fn() => $this->commands()->pause( array(), array( 'minutes' => '99999' ) ) ) );
	}

	public function test_resume_all_or_one_and_a_warning_when_none_was_paused(): void {
		$pause = new RestFake( array( 'resumeAll' => 1, 'resume' => true ) );
		$this->container->instance( Services::PAUSE_CONTROLLER, $pause );
		$this->commands()->resume( array(), array() );
		$this->commands()->resume( array(), array( 'connection' => '4' ) );
		$this->assertSame( array( '1 connection resumed.', '1 connection resumed.' ), $this->out->of( 'success' ) );

		$none = new RestFake( array( 'resumeAll' => 0 ) );
		$this->container->instance( Services::PAUSE_CONTROLLER, $none );
		$this->assertSame( 0, $this->exitCode( fn() => $this->commands()->resume( array(), array() ) ) );
		$this->assertSame( array( 'No connection was paused.' ), $this->out->of( 'warning' ) );
	}

	public function test_reconcile_keeps_going_while_the_run_reports_progress_then_prints_the_totals(): void {
		$done  = array( 'status' => 'done', 'compared' => 120, 'matched' => 117, 'differences' => 3, 'repaired' => 1 );
		$calls = 0;
		$recon = new RestFake(
			array(
				'run' => function () use ( &$calls, $done ): array {
					++$calls;
					return $calls < 3 ? array( 'status' => 'running', 'progress' => array( 'done' => $calls * 50, 'total' => 120 ) ) : $done;
				},
			)
		);
		$this->container->instance( JobServices::RECONCILIATION, $recon );
		$this->commands()->reconcile( array(), array( 'window' => '3' ) );
		$this->assertSame( array( 3, null, 'manual' ), $recon->called( 'run' )[0] );
		$this->assertCount( 3, $recon->called( 'run' ) );
		$this->assertSame( array( 'Compared 50 of 120 orders…', 'Compared 100 of 120 orders…' ), array_slice( $this->out->of( 'log' ), 0, 2 ) );
		$this->assertSame( array( 'Compared 120 orders: 117 match, 3 differ, 1 queued again.' ), $this->out->of( 'success' ) );
		$this->assertStringContainsString( 'repair or ignore', $this->out->of( 'log' )[2] );
	}

	public function test_reconcile_uses_a_week_by_default_and_a_chosen_connection(): void {
		$recon = new RestFake( array( 'run' => array( 'status' => 'done', 'compared' => 0, 'matched' => 0, 'differences' => 0, 'repaired' => 0 ) ) );
		$this->container->instance( JobServices::RECONCILIATION, $recon );
		$this->commands()->reconcile( array(), array() );
		$this->commands()->reconcile( array(), array( 'connection' => '6' ) );
		$this->assertSame( array( array( 7, null, 'manual' ), array( 7, 6, 'manual' ) ), $recon->called( 'run' ) );
		$this->assertCount( 0, $this->out->of( 'log' ), 'no hint when nothing differs' );
	}

	public function test_reconcile_fails_when_nothing_can_be_compared_when_the_system_cannot_be_read_and_when_it_stalls(): void {
		$this->container->instance( JobServices::RECONCILIATION, new RestFake( array(), array( 'run' => new JobException( 'No connected system can be compared.', 409 ) ) ) );
		$this->assertSame( 1, $this->exitCode( fn() => $this->commands()->reconcile( array(), array() ) ) );
		$this->assertSame( array( 'No connected system can be compared.' ), $this->out->of( 'error' ) );

		$this->container->instance( JobServices::RECONCILIATION, new RestFake( array( 'run' => array( 'status' => 'failed' ) ) ) );
		$this->assertSame( 1, $this->exitCode( fn() => $this->commands()->reconcile( array(), array() ) ) );

		$this->container->instance( JobServices::RECONCILIATION, new RestFake( array( 'run' => array( 'status' => 'running', 'progress' => array( 'done' => 10, 'total' => 99 ) ) ) ) );
		$this->assertSame( 1, $this->exitCode( fn() => $this->commands()->reconcile( array(), array() ) ), 'no progress between two calls' );
		$this->assertSame( 2, $this->exitCode( fn() => $this->commands()->reconcile( array(), array( 'window' => '31' ) ) ) );
	}

	public function test_a_reconcile_failure_prints_the_message_as_text_and_not_as_escaped_html(): void {
		$this->container->instance( JobServices::RECONCILIATION, new RestFake( array(), array( 'run' => new JobException( esc_html( "Odoo said: \"can't compare\" <now>." ), 409 ) ) ) );
		$this->assertSame( 1, $this->exitCode( fn() => $this->commands()->reconcile( array(), array() ) ) );
		$this->assertSame( array( "Odoo said: \"can't compare\" <now>." ), $this->out->of( 'error' ) );
	}

	public function test_readiness_prints_the_checks_and_exits_with_1_when_one_needs_fixing(): void {
		$checks = array(
			array( 'status' => 'passed', 'title' => 'HPOS compatible', 'detail' => 'On.' ),
			array( 'status' => 'needs_fixing', 'title' => 'Encryption key', 'detail' => 'Missing.' ),
		);
		$this->container->instance( JobServices::READINESS, new RestFake( array( 'run' => array( 'checks' => $checks, 'blocked' => true ) ) ) );
		$this->assertSame( 1, $this->exitCode( fn() => $this->commands()->readiness( array(), array() ) ) );
		$this->assertSame( array( 'status', 'check', 'detail' ), $this->out->tables[0][2] );
		$this->assertSame( 'Encryption key', $this->out->tables[0][1][1]['check'] );
		$this->assertStringContainsString( 'needs fixing', $this->out->of( 'error' )[0] );
	}

	public function test_readiness_exits_with_0_when_only_worth_fixing_and_can_print_json(): void {
		$result = array( 'checks' => array( array( 'status' => 'worth_fixing', 'title' => 'SKUs', 'detail' => '6 products have no SKU.' ) ), 'blocked' => false );
		$this->container->instance( JobServices::READINESS, new RestFake( array( 'run' => $result ) ) );
		$this->assertSame( 0, $this->exitCode( fn() => $this->commands()->readiness( array(), array( 'format' => 'json' ) ) ) );
		$this->assertSame( array( 'json', $result ), $this->out->values[0] );
		$this->assertSame( array( 'No check needs fixing.' ), $this->out->of( 'success' ) );
	}

	public function test_the_stop_signal_is_a_flag(): void {
		$stop = new StopSignal();
		$this->assertFalse( $stop->requested() );
		$stop->request();
		$this->assertTrue( $stop->requested() );
	}
}
