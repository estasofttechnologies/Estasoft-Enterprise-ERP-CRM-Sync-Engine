<?php
/**
 * The commands through the WP_CLI adapter, against the real services and database.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Integration\Cli;

require_once dirname( __DIR__, 2 ) . '/Support/RestIntegrationCase.php';
require_once dirname( __DIR__, 2 ) . '/Support/RestWpCliShim.php';

use Estasoft\SyncEngine\Cli\CliModule;
use Estasoft\SyncEngine\Cli\Commands;
use Estasoft\SyncEngine\Cli\StopSignal;
use Estasoft\SyncEngine\Cli\WpCliOutput;
use Estasoft\SyncEngine\Jobs\JobServices;
use Estasoft\SyncEngine\Tests\Support\RestFake;
use Estasoft\SyncEngine\Tests\Support\RestIntegrationCase;

final class CliCommandsTest extends RestIntegrationCase {

	private int $conn;

	private StopSignal $stop;

	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset();
		$this->conn = $this->h->connection();
		$this->stop = new StopSignal();
	}

	private function commands(): Commands {
		return new Commands(
			$this->container,
			new WpCliOutput(),
			$this->stop,
			function (): void {
				$this->stop->request(); // Waiting is where a real worker would get its signal.
			}
		);
	}

	/**
	 * The text of the lines of one kind that WP_CLI printed.
	 *
	 * @param string $kind log, success, warning or error.
	 * @return array<int,string>
	 */
	private function lines( string $kind ): array {
		return array_values( array_map( static fn( array $line ): string => (string) $line[1], array_filter( \WP_CLI::$lines, static fn( array $line ): bool => $line[0] === $kind ) ) );
	}

	public function test_retry_moves_failed_items_back_through_the_real_queue_service(): void {
		$a = $this->h->item( $this->conn, '1' );
		$b = $this->h->item( $this->conn, '2' );
		$this->h->queue->markFailed( $a, 'SYNC-MAP-001', 'x', 'SKU A' );
		$this->h->queue->markFailed( $b, 'SYNC-MAP-001', 'x', 'SKU A' );

		$this->commands()->retry( array(), array( 'code' => 'SYNC-MAP-001', 'cause' => 'SKU A' ) );
		$this->assertSame( array( '2 items moved back to the queue.' ), $this->lines( 'success' ) );
		$this->assertSame( 'queued', $this->h->row( $a )['state'] );

		\WP_CLI::reset();
		$this->commands()->retry( array(), array( 'ids' => (string) $a ) );
		$this->assertSame( array(), $this->lines( 'success' ), 'a queued item cannot be retried' );
		$this->assertCount( 1, $this->lines( 'warning' ) );
	}

	public function test_a_wrong_argument_ends_with_the_exit_code_wp_cli_is_given(): void {
		try {
			$this->commands()->retry( array(), array() );
			$this->fail( 'the command must stop' );
		} catch ( \RuntimeException $stopped ) {
			$this->assertSame( 2, $stopped->getCode() );
		}
		$this->assertCount( 1, $this->lines( 'error' ) );
	}

	public function test_options_written_without_a_value_change_nothing_in_the_database(): void {
		$item = $this->h->item( $this->conn, '1' );
		$this->h->queue->markFailed( $item, 'SYNC-MAP-001', 'x', 'SKU A' );

		foreach (
			array(
				fn() => $this->commands()->retry( array(), array( 'ids' => true ) ),
				fn() => $this->commands()->retry( array(), array( 'code' => true ) ),
				fn() => $this->commands()->pause( array(), array( 'minutes' => true ) ),
				fn() => $this->commands()->pause( array(), array( 'connection' => true ) ),
			) as $command
		) {
			try {
				$command();
				$this->fail( 'the command must stop' );
			} catch ( \RuntimeException $stopped ) {
				$this->assertSame( 2, $stopped->getCode() );
			}
		}
		$this->assertSame( 'failed', $this->h->row( $item )['state'] );
		$this->assertSame( 'active', $this->h->connectionRow( $this->conn )['status'] );
		$this->assertCount( 4, $this->lines( 'error' ) );
	}

	public function test_pause_and_resume_change_the_connection_in_the_database(): void {
		$this->commands()->pause( array(), array( 'minutes' => '30' ) );
		$this->assertSame( 'paused', $this->h->connectionRow( $this->conn )['status'] );
		$this->assertNotNull( $this->h->connectionRow( $this->conn )['paused_until'] );
		$this->commands()->resume( array(), array() );
		$this->assertSame( 'active', $this->h->connectionRow( $this->conn )['status'] );
		$this->assertSame( array( '1 connection paused for 30 minutes.', '1 connection resumed.' ), $this->lines( 'success' ) );
	}

	public function test_a_worker_with_nothing_to_do_runs_the_real_worker_waits_and_stops_when_told(): void {
		$this->commands()->worker( array(), array( 'concurrency' => '1' ) );
		$this->assertStringContainsString( 'Worker stopped: stop signal received. 0 items handled.', $this->lines( 'success' )[0] );
		$this->assertStringContainsString( 'Worker started: 1 at a time', $this->lines( 'log' )[0] );
	}

	public function test_status_prints_through_format_items(): void {
		$this->replace( JobServices::DASHBOARD, new RestFake( array( 'status' => array( 'mode' => 'normal', 'synced_today' => 4, 'waiting' => 1, 'needs_attention' => 0, 'skipped' => 0 ) ) ) );
		$this->replace( JobServices::CONNECTIONS, new RestFake( array( 'summaries' => array( array( 'id' => $this->conn, 'label' => 'Fake ERP', 'status' => 'active' ) ) ) ) );
		$this->commands()->status( array(), array( 'format' => 'csv' ) );
		$printed = \WP_CLI::$printed[0];
		$this->assertSame( 'items', $printed[0] );
		$this->assertSame( 'csv', $printed[1] );
		$this->assertSame( array( 'item', 'value' ), $printed[3] );
		$this->assertSame( 'Mode', $printed[2][0]['item'] );
		$this->assertSame( 'normal', $printed[2][0]['value'] );
	}

	public function test_the_json_format_goes_to_print_value(): void {
		$this->replace( JobServices::DASHBOARD, new RestFake( array( 'status' => array( 'mode' => 'normal' ) ) ) );
		$this->replace( JobServices::CONNECTIONS, new RestFake( array( 'summaries' => array() ) ) );
		$this->commands()->status( array(), array( 'format' => 'json' ) );
		$this->assertSame( array( 'value', 'json', array( 'status' => array( 'mode' => 'normal' ), 'connections' => array() ) ), \WP_CLI::$printed[0] );
	}

	public function test_the_module_registers_the_command_only_while_wp_cli_runs(): void {
		( new CliModule() )->hooks( $this->container );
		$this->assertSame( array(), \WP_CLI::$commands, 'WP_CLI is not defined as a constant here, so nothing is registered' );
		$this->assertTrue( method_exists( Commands::class, 'worker' ) );
		foreach ( array( 'worker', 'status', 'retry', 'pause', 'resume', 'reconcile', 'readiness' ) as $command ) {
			$this->assertTrue( ( new \ReflectionMethod( Commands::class, $command ) )->isPublic(), $command );
		}
		$public = array_map( static fn( \ReflectionMethod $method ): string => $method->getName(), ( new \ReflectionClass( Commands::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) );
		sort( $public );
		$this->assertSame( array( '__construct', 'pause', 'readiness', 'reconcile', 'resume', 'retry', 'status', 'worker' ), $public, 'every public method is a subcommand, so there are no stray ones' );
	}
}
