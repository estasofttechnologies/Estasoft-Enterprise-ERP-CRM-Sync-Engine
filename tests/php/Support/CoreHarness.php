<?php
/**
 * Real repositories and queue services wired to fakes, for the Core integration tests.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\ConnectionSettings;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\Database\RetentionJob;
use Estasoft\SyncEngine\Core\Database\Tables;
use Estasoft\SyncEngine\Core\Logging\LogPruner;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Logging\Redactor;
use Estasoft\SyncEngine\Core\OnboardingState;
use Estasoft\SyncEngine\Core\Queue\CircuitBreaker;
use Estasoft\SyncEngine\Core\Queue\Enqueuer;
use Estasoft\SyncEngine\Core\Queue\ErrorClassifier;
use Estasoft\SyncEngine\Core\Queue\IdempotencyKey;
use Estasoft\SyncEngine\Core\Queue\ItemProcessor;
use Estasoft\SyncEngine\Core\Queue\PauseController;
use Estasoft\SyncEngine\Core\Queue\QueueService;
use Estasoft\SyncEngine\Core\Queue\RateLimiter;
use Estasoft\SyncEngine\Core\Queue\Reaper;
use Estasoft\SyncEngine\Core\Queue\RetryPolicy;
use Estasoft\SyncEngine\Core\Queue\Scheduler;
use Estasoft\SyncEngine\Core\Queue\Worker;
use Estasoft\SyncEngine\Core\Repositories\ConnectionRepository;
use Estasoft\SyncEngine\Core\Repositories\IdempotencyRepository;
use Estasoft\SyncEngine\Core\Repositories\IdMapRepository;
use Estasoft\SyncEngine\Core\Repositories\LogRepository;
use Estasoft\SyncEngine\Core\Repositories\MappingRepository;
use Estasoft\SyncEngine\Core\Repositories\QueueRepository;
use Estasoft\SyncEngine\Core\Repositories\ReconRepository;
use Estasoft\SyncEngine\Core\Repositories\StateRepository;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Domain\EntityType;
use Estasoft\SyncEngine\Domain\ItemMode;
use Estasoft\SyncEngine\Sync\PayloadBuilderInterface;

/**
 * Builds the object graph by hand (no container), with a fake clock, a fake connector, a fake payload builder, in-memory
 * options and rate counters, and a sleep that moves the fake clock instead of waiting.
 */
final class CoreHarness {

	public \wpdb $wpdb;
	public CoreFakeClock $clock;
	public Tables $tables;
	public CoreArrayOptionStore $options;
	public SecretBox $box;
	public Settings $settings;
	public ConnectionSettings $connectionSettings;
	public OnboardingState $onboarding;
	public ConnectionRepository $connections;
	public QueueRepository $queue;
	public LogRepository $logs;
	public MappingRepository $mappings;
	public IdMapRepository $idMap;
	public StateRepository $state;
	public IdempotencyRepository $idempotency;
	public ReconRepository $recon;
	public Redactor $redactor;
	public Logger $logger;
	public RetryPolicy $retry;
	public ErrorClassifier $classifier;
	public CoreArrayRateStore $rateStore;
	public RateLimiter $limiter;
	public PauseController $pause;
	public CircuitBreaker $breaker;
	public Reaper $reaper;
	public ItemProcessor $processor;
	public Worker $worker;
	public Enqueuer $enqueuer;
	public QueueService $service;
	public Scheduler $scheduler;
	public RetentionJob $retention;
	public CoreFakeConnector $connector;
	public ?CoreFakePayloadBuilder $builder;
	public ConnectorRegistry $registry;

	/**
	 * Jitter the retry policy adds, in seconds.
	 */
	public int $jitter = 0;

	/**
	 * Waits the worker asked for.
	 *
	 * @var array<int,float>
	 */
	public array $sleeps = array();

	/**
	 * @param string      $now      Start of the fake clock (UTC).
	 * @param string|null $key      Encryption key material; null for "no key defined".
	 * @param string      $timezone Store time zone.
	 */
	public function __construct( string $now = '2026-11-02 12:00:00', ?string $key = 'unit-test-key-0123456789abcdef-core', string $timezone = 'Asia/Dubai' ) {
		global $wpdb;
		$this->wpdb               = $wpdb;
		$this->clock              = new CoreFakeClock( $now );
		$this->tables             = new Tables( $wpdb->prefix );
		$this->options            = new CoreArrayOptionStore();
		$this->box                = new SecretBox( $key );
		$this->settings           = new Settings( $this->options, 'admin@example.test' );
		$this->connectionSettings = new ConnectionSettings();
		$this->onboarding         = new OnboardingState( $this->options );

		$this->redactor = new Redactor();

		$this->connections = new ConnectionRepository( $wpdb, $this->tables, $this->clock, $this->box, $this->connectionSettings );
		$this->queue       = new QueueRepository( $wpdb, $this->tables, $this->clock, new \DateTimeZone( $timezone ), $this->redactor, $this->settings );
		$this->logs        = new LogRepository( $wpdb, $this->tables, $this->clock );
		$this->mappings    = new MappingRepository( $wpdb, $this->tables, $this->clock );
		$this->idMap       = new IdMapRepository( $wpdb, $this->tables, $this->clock );
		$this->state       = new StateRepository( $wpdb, $this->tables, $this->clock );
		$this->idempotency = new IdempotencyRepository( $wpdb, $this->tables, $this->clock );
		$this->recon       = new ReconRepository( $wpdb, $this->tables, $this->clock );

		$this->logger = new Logger( $this->logs, $this->settings, $this->redactor );

		$this->connector = new CoreFakeConnector( $this->clock );
		$this->builder   = new CoreFakePayloadBuilder();
		$this->registry  = new ConnectorRegistry( array( $this->connector ) );

		$registry = fn(): ConnectorRegistry => $this->registry;
		$builder  = fn(): ?PayloadBuilderInterface => $this->builder;

		$this->retry      = new RetryPolicy( $this->clock, fn(): int => $this->jitter );
		$this->classifier = new ErrorClassifier( $this->redactor, $this->settings );
		$this->rateStore  = new CoreArrayRateStore();
		$this->limiter    = new RateLimiter( $this->rateStore, $this->clock );
		$this->pause      = new PauseController( $this->connections, $this->logger, $this->clock );
		$this->breaker    = new CircuitBreaker( $this->connections, $registry, $this->logger, $this->clock );
		$this->reaper     = new Reaper( $this->queue, $this->logger );
		$this->processor  = new ItemProcessor(
			$this->connections,
			$this->queue,
			$this->idMap,
			$this->state,
			$this->idempotency,
			$this->logger,
			$this->retry,
			$this->classifier,
			$this->breaker,
			$this->pause,
			$this->clock,
			$registry,
			$builder
		);
		$this->worker    = new Worker(
			$this->queue,
			$this->connections,
			$this->processor,
			$this->limiter,
			$this->reaper,
			$this->breaker,
			$this->pause,
			$this->settings,
			$this->clock,
			function ( float $seconds ): void {
				$this->sleeps[] = $seconds;
				$this->clock->advanceMilliseconds( (int) round( $seconds * 1000 ) );
			}
		);
		$this->enqueuer  = new Enqueuer( $this->connections, $this->queue, $this->idempotency, $this->onboarding, $this->logger, $this->clock, $registry );
		$this->retention = new RetentionJob( $this->queue, $this->idempotency, $this->recon, new LogPruner( $this->logs, $this->settings ) );
		$this->scheduler = new Scheduler( $this->worker, $this->reaper, $this->breaker, $this->pause, $this->retention, $this->settings, $this->options, $this->clock );
		$this->service   = new QueueService( $this->queue, $this->logs, $this->logger, $this->scheduler );
	}

	/**
	 * Remove every row from the nine tables, the rate counters and the plugin's transients.
	 *
	 * The Core tests (and the Rest, Admin and Woo tests built on them) do not run inside a transaction that is rolled back, so
	 * what one test writes would still be there for the next. The transients matter most: a cached failed count left by one test
	 * is read by the next test that opens the dashboard. They are deleted one by one through delete_option(), which also clears
	 * their copy in the object cache.
	 */
	public function reset(): void {
		foreach ( $this->tables->all() as $table ) {
			$this->wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore
		}
		$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->wpdb->options} WHERE option_name LIKE %s", $this->wpdb->esc_like( 'estasoft_sync_rl_' ) . '%' ) ); // phpcs:ignore
		$names = $this->wpdb->get_col( // phpcs:ignore
			$this->wpdb->prepare(
				"SELECT option_name FROM {$this->wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore
				$this->wpdb->esc_like( '_transient_estasoft_sync_' ) . '%',
				$this->wpdb->esc_like( '_transient_timeout_estasoft_sync_' ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			delete_option( (string) $name );
		}
	}

	/**
	 * Create an active connection to the fake connector.
	 *
	 * @param array<string,mixed>  $settings Reliability settings.
	 * @param array<string,string> $secrets  Secrets.
	 * @param array<string,mixed>  $config   Extra config.
	 * @return int Connection id.
	 */
	public function connection( array $settings = array(), array $secrets = array( 'api_key' => 'sk-test-abcdefghijklmnop' ), array $config = array() ): int {
		return $this->connections->create(
			array_merge(
				array(
					'connector' => 'fake',
					'type'      => 'erp',
					'label'     => 'Fake ERP',
					'address'   => 'https://erp.example.test',
				),
				$config
			),
			$secrets,
			$settings
		);
	}

	/**
	 * Put an item straight into the queue table.
	 *
	 * @param int    $connectionId Connection.
	 * @param string $entityId     Order id.
	 * @param string $event        Event.
	 * @param string $partition    Partition key; defaults to order:{id}.
	 * @param ItemMode $mode       Mode.
	 * @param array<string,mixed> $display Display values.
	 * @return int Queue id.
	 */
	public function item( int $connectionId, string $entityId = '10490', string $event = 'created', ?string $partition = null, ItemMode $mode = ItemMode::Live, array $display = array() ): int {
		static $serial = 0;
		++$serial;
		$key = IdempotencyKey::make( $connectionId, EntityType::Order, $entityId, $event, $serial );
		$this->idempotency->reserve( $key, $connectionId, EntityType::Order, $entityId, $event, $serial );
		$id = $this->queue->enqueue( $connectionId, EntityType::Order, $entityId, $event, $partition ?? 'order:' . $entityId, $key, $mode, 5, $display );
		$this->idempotency->attach( $key, $id );
		return $id;
	}

	/**
	 * The raw queue row.
	 *
	 * @param int $id Queue id.
	 * @return array<string,mixed>
	 */
	public function row( int $id ): array {
		return (array) $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->tables->queue()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * The raw connection row.
	 *
	 * @param int $id Connection id.
	 * @return array<string,mixed>
	 */
	public function connectionRow( int $id ): array {
		return (array) $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->tables->connections()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Run a raw statement (test set-up).
	 *
	 * @param string $sql Statement.
	 */
	public function sql( string $sql ): void {
		$this->wpdb->query( $sql ); // phpcs:ignore
	}

	/**
	 * Count rows of a table.
	 *
	 * @param string $table Table name.
	 */
	public function count( string $table ): int {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore
	}
}
