<?php
/**
 * The Jobs services wired to the Core test harness (real repositories, real database) and fake shop ports.
 *
 * @package Estasoft\SyncEngine\Tests
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Tests\Support;

use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Jobs\Capacity\CapacityTest;
use Estasoft\SyncEngine\Jobs\Capacity\MemoryCapacityStore;
use Estasoft\SyncEngine\Jobs\Connections\ConnectionService;
use Estasoft\SyncEngine\Jobs\Connections\SignInPauses;
use Estasoft\SyncEngine\Jobs\Dashboard\DashboardService;
use Estasoft\SyncEngine\Jobs\Inbound\InboundHandler;
use Estasoft\SyncEngine\Jobs\Invoices\InvoiceService;
use Estasoft\SyncEngine\Jobs\Notifications\NotificationService;
use Estasoft\SyncEngine\Jobs\Onboarding\OnboardingService;
use Estasoft\SyncEngine\Jobs\Porter\SettingsPorter;
use Estasoft\SyncEngine\Jobs\Readiness\ReadinessService;
use Estasoft\SyncEngine\Jobs\Reconciliation\ReconService;
use Estasoft\SyncEngine\Jobs\Reconciliation\ReconSettings;
use Estasoft\SyncEngine\Jobs\Shadow\GoLiveService;
use Estasoft\SyncEngine\Jobs\Shadow\ShadowService;
use Estasoft\SyncEngine\Jobs\Stock\StockService;
use Estasoft\SyncEngine\Jobs\Stock\StockStore;
use Estasoft\SyncEngine\Jobs\Support\ConnectorGateway;
use Estasoft\SyncEngine\Jobs\Support\JobConfig;
use Estasoft\SyncEngine\Jobs\Support\SupportReportBuilder;
use Estasoft\SyncEngine\Jobs\Tracking\TrackingLog;
use Estasoft\SyncEngine\Jobs\Tracking\TrackingService;
use Estasoft\SyncEngine\Jobs\Tracking\TrackingSettings;
use Estasoft\SyncEngine\Jobs\Urls;

/**
 * Builds by hand (no container) every service of the Jobs module over a CoreHarness. The connector is a JobsFakeConnector with the
 * id `fake`, so a connection made with `CoreHarness::connection()` belongs to it.
 */
final class JobsHarness {

	public CoreHarness $core;
	public JobsFakeConnector $connector;
	public JobsFakeOrders $orders;
	public JobsFakeStock $stock;
	public JobsFakeEnvironment $env;
	public JobsFakeCatalog $catalog;
	public JobsFakeMailer $mailer;
	public JobsArrayTransients $transients;
	public Urls $urls;
	public ConnectorGateway $gateway;
	public JobConfig $jobConfig;
	public TrackingSettings $trackingSettings;
	public TrackingLog $trackingLog;
	public StockStore $stockStore;
	public ReconSettings $reconSettings;
	public StockService $stockService;
	public TrackingService $trackingService;
	public InvoiceService $invoiceService;
	public ReconService $reconService;
	public ShadowService $shadowService;
	public GoLiveService $goLive;
	public OnboardingService $onboardingService;
	public DashboardService $dashboard;
	public ConnectionService $connectionService;
	public SignInPauses $signIns;
	public NotificationService $notifications;
	public InboundHandler $inbound;
	public SettingsPorter $porter;
	public ReadinessService $readiness;
	public SupportReportBuilder $support;
	public CapacityTest $capacity;

	/**
	 * Continuations and refreshes the services asked to be scheduled.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	public array $scheduled = array();

	/**
	 * Time of the last queue run the readiness check sees (MySQL datetime), or null.
	 */
	public ?string $lastQueueRun = null;

	/**
	 * Whether Action Scheduler runs the tasks.
	 */
	public bool $actionScheduler = false;

	/**
	 * The address the inbound handler sees the request coming from.
	 */
	public string $remoteAddress = '203.0.113.7';

	/**
	 * What the mapping validator the porter uses answers: a list of problems (each with a `message`), empty for valid rules.
	 *
	 * @var array<int,object>
	 */
	public array $ruleProblems = array();

	/**
	 * Mapping drafts saved by the porter: array( connection id, type, rules count ).
	 *
	 * @var array<int,array{0:int,1:string,2:int}>
	 */
	public array $drafts = array();

	/**
	 * Wire everything.
	 *
	 * @param CoreHarness|null $core Core harness; a new one when null.
	 */
	public function __construct( ?CoreHarness $core = null ) {
		$this->core       = $core ?? new CoreHarness();
		$core             = $this->core;
		$this->connector  = new JobsFakeConnector();
		$core->registry   = new ConnectorRegistry( array( $this->connector ) );
		$this->orders     = new JobsFakeOrders();
		$this->stock      = new JobsFakeStock();
		$this->env        = new JobsFakeEnvironment();
		$this->catalog    = new JobsFakeCatalog();
		$this->mailer     = new JobsFakeMailer();
		$this->transients = new JobsArrayTransients( $core->clock );
		$this->urls       = new Urls( $this->env );
		$registry         = fn(): ConnectorRegistry => $core->registry;

		$this->gateway          = new ConnectorGateway(
			$core->connections,
			$registry,
			$core->limiter,
			$core->classifier,
			$core->pause,
			$core->logger,
			$core->clock,
			function ( float $seconds ) use ( $core ): void {
				$core->clock->advanceMilliseconds( (int) round( $seconds * 1000 ) );
			}
		);
		$this->jobConfig        = new JobConfig( $core->connections );
		$this->trackingSettings = new TrackingSettings( $this->jobConfig, $this->orders );
		$this->trackingLog      = new TrackingLog( $core->options );
		$this->stockStore       = new StockStore( $core->options );
		$this->reconSettings    = new ReconSettings( $core->options );

		$this->stockService = new StockService( $this->jobConfig, $this->trackingSettings, $core->connections, $this->gateway, $this->stock, $core->idMap, $this->stockStore, $this->trackingLog, $core->onboarding, $core->logger, $core->clock );
		$this->trackingService = new TrackingService( $this->trackingSettings, $this->gateway, $this->orders, $core->state, $core->enqueuer, $this->trackingLog, $core->options, $core->onboarding, $core->logger, $core->clock );
		$this->invoiceService  = new InvoiceService( $this->orders, $core->state, $core->idMap, $core->queue, $core->enqueuer, $this->gateway, $core->redactor, $core->logger, $core->options, $core->clock );
		$this->reconService    = new ReconService(
			$core->recon,
			$this->reconSettings,
			$core->connections,
			$this->gateway,
			$this->orders,
			$this->env,
			$core->enqueuer,
			$core->idMap,
			$core->state,
			$core->onboarding,
			$core->options,
			$core->logger,
			$core->clock,
			static fn( int $userId ): string => 'User ' . $userId,
			function (): void {
				$this->scheduled[] = array( 'recon_continue' );
			}
		);
		$this->shadowService = new ShadowService( $core->onboarding, $core->queue, $core->connections, $this->gateway, $this->orders, $this->reconSettings, $core->options, $core->logger, $core->clock );
		$this->goLive        = new GoLiveService( $core->onboarding, $core->queue, $core->connections, $this->shadowService, $core->logger, $core->clock );
		$this->onboardingService = new OnboardingService( $core->onboarding, $this->orders );
		$this->dashboard     = new DashboardService( $core->queue, $core->connections, $registry, $core->logs, $core->onboarding, $core->box, $this->env, $this->shadowService, $this->transients, $core->clock );
		$this->signIns           = new SignInPauses( $core->options );
		$this->connectionService = new ConnectionService(
			$core->connections,
			$core->connectionSettings,
			$core->mappings,
			$registry,
			$core->box,
			$core->logger,
			$core->clock,
			$core->pause,
			$this->signIns,
			static fn(): array => array(
				array(
					'name'              => 'Odoo',
					'type'              => 'erp',
					'regions'           => 'Global',
					'connector'         => 'fake',
					'connection_method' => 'key',
					'needs'             => 'An API key.',
					'available'         => false,
					'wave'              => 1,
				),
				array(
					'name'              => 'Xero',
					'type'              => 'accounting',
					'regions'           => 'AU',
					'connector'         => null,
					'connection_method' => 'own_app',
					'needs'             => 'An app.',
					'available'         => false,
					'wave'              => 2,
				),
			)
		);
		$this->notifications = new NotificationService( $core->settings, $this->mailer, $this->transients, $core->connections, $core->queue, $this->env, $this->urls, $core->options, $core->logger, $core->clock );
		$this->inbound       = new InboundHandler(
			$core->connections,
			$core->box,
			$this->transients,
			$core->logger,
			$core->clock,
			function ( int $connectionId, string $entity ): void {
				$this->scheduled[] = array( 'inbound', $connectionId, $entity );
			},
			fn(): string => $this->remoteAddress
		);
		$this->porter        = new SettingsPorter(
			$core->settings,
			$core->connectionSettings,
			$core->connections,
			$core->mappings,
			$this->stockService,
			$this->trackingSettings,
			$this->env,
			$core->logger,
			$core->clock,
			function ( int $connectionId, string $type, array $rules, string $note, string $by ): array {
				$this->drafts[] = array( $connectionId, $type, count( $rules ) );
				return $this->core->mappings->saveDraft( $connectionId, $type, $rules, $note, $by );
			},
			static fn( int $userId ): string => 'User ' . $userId,
			fn( int $connectionId, string $type, array $rules ): array => $this->ruleProblems
		);
		$this->readiness     = new ReadinessService( $this->env, $this->catalog, $this->catalog, $core->box, $core->connections, $this->urls, $core->options, $core->clock, fn(): ?string => $this->lastQueueRun );
		$this->support       = new SupportReportBuilder( $this->env, $core->connections, $core->queue, $core->logs, $this->readiness, $core->redactor, $core->box, $core->clock, fn(): bool => $this->actionScheduler );
		$this->capacity      = new CapacityTest( $core->options, $core->clock, static fn() => new MemoryCapacityStore() );
	}

	/**
	 * Create an active connection to the fake connector, with two warehouses in its config.
	 *
	 * @param array<string,mixed> $settings Reliability settings.
	 * @return int Connection id.
	 */
	public function connect( array $settings = array() ): int {
		$id = $this->core->connection(
			$settings,
			array( 'api_key' => 'sk-test-abcdefghijklmnop' ),
			array(
				'version'    => 'Fakesystem 18.0',
				'warehouses' => array(
					array(
						'id'                  => '1',
						'name'                => 'Dubai warehouse',
						'counts_toward_stock' => true,
						'countries'           => array(),
					),
					array(
						'id'                  => '2',
						'name'                => 'Riyadh warehouse',
						'counts_toward_stock' => true,
						'countries'           => array( 'SA' ),
					),
				),
			)
		);
		return $id;
	}
}
