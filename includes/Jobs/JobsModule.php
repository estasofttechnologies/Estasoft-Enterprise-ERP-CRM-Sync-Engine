<?php
/**
 * Jobs module: stock, tracking, invoices, reconciliation, shadow mode, notifications and the tools behind the admin screens.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Jobs;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Core\Queue\Scheduler;
use Estasoft\SyncEngine\Core\Services;
use Estasoft\SyncEngine\Jobs\Adapters\WooShopCatalog;
use Estasoft\SyncEngine\Jobs\Adapters\WooShopCustomers;
use Estasoft\SyncEngine\Jobs\Adapters\WooShopEnvironment;
use Estasoft\SyncEngine\Jobs\Adapters\WooShopOrders;
use Estasoft\SyncEngine\Jobs\Adapters\WooShopStock;
use Estasoft\SyncEngine\Jobs\Adapters\WpMailer;
use Estasoft\SyncEngine\Jobs\Adapters\WpTransients;
use Estasoft\SyncEngine\Jobs\Capacity\CapacityTest;
use Estasoft\SyncEngine\Jobs\Capacity\TemporaryTableCapacityStore;
use Estasoft\SyncEngine\Jobs\Connections\CatalogForms;
use Estasoft\SyncEngine\Jobs\Connections\ConnectionService;
use Estasoft\SyncEngine\Jobs\Connections\SignInPauses;
use Estasoft\SyncEngine\Jobs\Dashboard\DashboardService;
use Estasoft\SyncEngine\Jobs\Inbound\InboundHandler;
use Estasoft\SyncEngine\Jobs\Invoices\InvoiceService;
use Estasoft\SyncEngine\Jobs\Notifications\NotificationService;
use Estasoft\SyncEngine\Jobs\Onboarding\OnboardingService;
use Estasoft\SyncEngine\Jobs\Ports\Mailer;
use Estasoft\SyncEngine\Jobs\Ports\ShopCatalog;
use Estasoft\SyncEngine\Jobs\Ports\ShopCustomers;
use Estasoft\SyncEngine\Jobs\Ports\ShopEnvironment;
use Estasoft\SyncEngine\Jobs\Ports\ShopOrders;
use Estasoft\SyncEngine\Jobs\Ports\ShopStock;
use Estasoft\SyncEngine\Jobs\Ports\Transients;
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
use Estasoft\SyncEngine\ModuleInterface;

/**
 * Registers the services of `JobServices` (each also under its class name), adds the recurring tasks through the filter
 * `estasoft_sync_scheduled_tasks` and their handlers, attaches the notification handlers and the failed-count cache bust, and adds
 * the meta keys this module writes to the list uninstall removes.
 *
 * Recurring tasks (hook => seconds): stock 300, tracking 600, invoices 600, reconciliation tick 3600, digest tick 900. Two single
 * events complete the set: `estasoft_sync_recon_continue` (a run that ran out of time carries on) and `estasoft_sync_inbound_refresh`
 * (a signed hint arrived).
 */
final class JobsModule implements ModuleInterface {

	public const HOOK_STOCK     = 'estasoft_sync_job_stock';
	public const HOOK_TRACKING  = 'estasoft_sync_job_tracking';
	public const HOOK_INVOICES  = 'estasoft_sync_job_invoices';
	public const HOOK_RECONCILE = 'estasoft_sync_job_reconcile';
	public const HOOK_DIGEST    = 'estasoft_sync_job_digest';
	public const HOOK_CONTINUE  = 'estasoft_sync_recon_continue';
	public const HOOK_INBOUND   = 'estasoft_sync_inbound_refresh';

	/**
	 * When the hourly reconcile tick began (microtime), or null.
	 *
	 * @var float|null
	 */
	private ?float $tickStartedAt = null;

	/**
	 * Order meta keys the Jobs module writes (tracking and invoice facts).
	 */
	public const ORDER_KEYS_WRITTEN = array(
		WooShopOrders::TRACKING,
		WooShopOrders::TRACKING_NUMBER,
		WooShopOrders::TRACKING_CARRIER,
		WooShopOrders::INVOICE,
		WooShopOrders::INVOICE_NUMBER,
		WooShopOrders::INVOICE_STATUS,
		WooShopOrders::INVOICE_PDF,
	);

	/**
	 * Product meta keys the Jobs module writes (the last stock the system reported) or owns (the person's exclude and buffer).
	 */
	public const PRODUCT_KEYS_OWNED = array(
		WooShopStock::LAST_STOCK,
		WooShopStock::LAST_STOCK_AT,
		WooShopStock::EXCLUDE,
		WooShopStock::BUFFER,
	);

	/**
	 * Recurring tasks: hook to seconds.
	 */
	public const TASKS = array(
		self::HOOK_STOCK     => 300,
		self::HOOK_TRACKING  => 600,
		self::HOOK_INVOICES  => 600,
		self::HOOK_RECONCILE => 3600,
		self::HOOK_DIGEST    => 900,
	);

	/**
	 * Register services.
	 *
	 * @param Container $container Service container.
	 */
	public function services( Container $container ): void {
		$this->ports( $container );
		$this->jobs( $container );
		$this->tools( $container );
		$this->screens( $container );
	}

	/**
	 * Add hooks.
	 *
	 * @param Container $container Service container.
	 */
	public function hooks( Container $container ): void {
		add_filter( 'estasoft_sync_scheduled_tasks', array( $this, 'addTasks' ) );
		add_filter( 'estasoft_sync_meta_keys', array( $this, 'addMetaKeys' ), 10, 2 );

		$this->handle( $container, self::HOOK_STOCK, JobServices::STOCK, 'runDue' );
		$this->handle( $container, self::HOOK_TRACKING, JobServices::TRACKING, 'run' );
		$this->handle( $container, self::HOOK_INVOICES, JobServices::INVOICES, 'run' );
		$this->handle( $container, self::HOOK_RECONCILE, JobServices::RECONCILIATION, 'tick' );
		$this->handle( $container, self::HOOK_CONTINUE, JobServices::RECONCILIATION, 'continueRun' );
		add_action(
			self::HOOK_DIGEST,
			function () use ( $container ): void {
				$this->guard(
					$container,
					static function () use ( $container ): void {
						$container->get( JobServices::NOTIFICATIONS )->tick();
					}
				);
			}
		);
		// The shadow comparison shares the hourly tick with reconciliation. It starts after it and gets only what is left of one
		// time budget, so a long reconciliation run is never followed by a long run of shadow reads in the same request.
		add_action(
			self::HOOK_RECONCILE,
			function (): void {
				$this->tickStartedAt = microtime( true );
			},
			1
		);
		add_action(
			self::HOOK_RECONCILE,
			function () use ( $container ): void {
				$this->guard(
					$container,
					function () use ( $container ): void {
						$left = self::budgetLeft( ShadowService::BUDGET_SECONDS, $this->tickStartedAt, microtime( true ) );
						if ( $left >= 1.0 ) {
							$container->get( JobServices::SHADOW )->compare( null, 100, $left );
						}
					}
				);
			},
			20
		);
		add_action(
			self::HOOK_INBOUND,
			function ( $connectionId = 0, $entity = 'all' ) use ( $container ): void {
				$this->guard(
					$container,
					function () use ( $container, $connectionId, $entity ): void {
						$this->refresh( $container, (int) $connectionId, (string) $entity );
					}
				);
			},
			10,
			2
		);

		add_action(
			'estasoft_sync_connection_paused',
			static function ( $connectionId = 0, $reason = 'circuit' ) use ( $container ): void {
				$container->get( JobServices::NOTIFICATIONS )->onPaused( $connectionId, $reason );
			},
			10,
			2
		);
		add_action(
			'estasoft_sync_connection_resumed',
			static function ( $connectionId = 0 ) use ( $container ): void {
				$container->get( JobServices::NOTIFICATIONS )->onResumed( $connectionId );
			}
		);
		add_action(
			'estasoft_sync_connection_paused',
			static function ( $connectionId = 0, $reason = '' ) use ( $container ): void {
				$container->get( SignInPauses::class )->onPaused( $connectionId, $reason );
			},
			5,
			2
		);
		add_action(
			'estasoft_sync_connection_resumed',
			static function ( $connectionId = 0 ) use ( $container ): void {
				$container->get( SignInPauses::class )->onResumed( $connectionId );
			},
			5
		);
		foreach ( array( 'estasoft_sync_item_synced', 'estasoft_sync_item_failed', 'estasoft_sync_connection_resumed', 'estasoft_sync_shadow_processed' ) as $hook ) {
			add_action( $hook, array( self::class, 'bustFailedCount' ) );
		}
	}

	/**
	 * Add the recurring tasks to the scheduler's list.
	 *
	 * @param mixed $tasks Hook to seconds.
	 * @return array<string,int>
	 */
	public function addTasks( $tasks ): array {
		return array_merge( is_array( $tasks ) ? $tasks : array(), self::TASKS );
	}

	/**
	 * Add the meta keys this module writes or owns, so uninstall removes them (Core lists them too; it removes duplicates).
	 *
	 * @param mixed  $keys Keys.
	 * @param string $kind order, product or user.
	 * @return array<int,string>
	 */
	public function addMetaKeys( $keys, $kind = '' ): array {
		$keys = is_array( $keys ) ? $keys : array();
		if ( 'order' === $kind ) {
			array_push( $keys, ...self::ORDER_KEYS_WRITTEN );
		}
		if ( 'product' === $kind ) {
			array_push( $keys, ...self::PRODUCT_KEYS_OWNED );
		}
		return $keys;
	}

	/**
	 * What is left of a time budget.
	 *
	 * @param float      $total     The whole budget in seconds.
	 * @param float|null $startedAt When the work began (microtime), or null when it is not known.
	 * @param float      $now       The current microtime.
	 */
	public static function budgetLeft( float $total, ?float $startedAt, float $now ): float {
		return null === $startedAt ? $total : max( 0.0, $total - max( 0.0, $now - $startedAt ) );
	}

	/**
	 * Drop the cached failed count (the transient behind the menu bubble).
	 */
	public static function bustFailedCount(): void {
		delete_transient( DashboardService::FAILED_KEY );
	}

	/**
	 * The shop-side ports and the small shared pieces.
	 *
	 * @param Container $container Container.
	 */
	private function ports( Container $container ): void {
		$this->add( $container, JobServices::ENVIRONMENT, ShopEnvironment::class, static fn( Container $c ): ShopEnvironment => new WooShopEnvironment( $c->get( Services::WPDB ), $c->get( Services::TABLES ) ) );
		$this->add( $container, JobServices::SHOP_ORDERS, ShopOrders::class, static fn(): ShopOrders => new WooShopOrders() );
		$this->add( $container, JobServices::SHOP_STOCK, ShopStock::class, static fn(): ShopStock => new WooShopStock() );
		$this->add( $container, JobServices::SHOP_CATALOG, ShopCatalog::class, static fn(): ShopCatalog => new WooShopCatalog() );
		$this->add( $container, JobServices::SHOP_CUSTOMERS, ShopCustomers::class, static fn(): ShopCustomers => new WooShopCustomers() );
		$container->set( Mailer::class, static fn(): Mailer => new WpMailer() );
		$container->set( Transients::class, static fn(): Transients => new WpTransients() );
		$container->set( Urls::class, static fn( Container $c ): Urls => new Urls( $c->get( JobServices::ENVIRONMENT ) ) );
		$container->set( JobServices::URLS, static fn( Container $c ): Urls => $c->get( Urls::class ) );
		$container->set( SignInPauses::class, static fn( Container $c ): SignInPauses => new SignInPauses( $c->get( Services::OPTIONS ) ) );
		$container->set( JobConfig::class, static fn( Container $c ): JobConfig => new JobConfig( $c->get( Services::CONNECTION_REPOSITORY ) ) );
		$this->add(
			$container,
			JobServices::GATEWAY,
			ConnectorGateway::class,
			static fn( Container $c ): ConnectorGateway => new ConnectorGateway(
				$c->get( Services::CONNECTION_REPOSITORY ),
				self::registry( $c ),
				$c->get( Services::RATE_LIMITER ),
				$c->get( Services::ERROR_CLASSIFIER ),
				$c->get( Services::PAUSE_CONTROLLER ),
				$c->get( Services::LOGGER ),
				$c->get( Services::CLOCK )
			)
		);
	}

	/**
	 * Stock, tracking, invoices, reconciliation and shadow mode.
	 *
	 * @param Container $container Container.
	 */
	private function jobs( Container $container ): void {
		$container->set( TrackingSettings::class, static fn( Container $c ): TrackingSettings => new TrackingSettings( $c->get( JobConfig::class ), $c->get( JobServices::SHOP_ORDERS ) ) );
		$container->set( TrackingLog::class, static fn( Container $c ): TrackingLog => new TrackingLog( $c->get( Services::OPTIONS ) ) );
		$container->set( StockStore::class, static fn( Container $c ): StockStore => new StockStore( $c->get( Services::OPTIONS ) ) );
		$container->set( ReconSettings::class, static fn( Container $c ): ReconSettings => new ReconSettings( $c->get( Services::OPTIONS ) ) );

		$this->add(
			$container,
			JobServices::STOCK,
			StockService::class,
			static fn( Container $c ): StockService => new StockService(
				$c->get( JobConfig::class ),
				$c->get( TrackingSettings::class ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( JobServices::GATEWAY ),
				$c->get( JobServices::SHOP_STOCK ),
				$c->get( Services::ID_MAP_REPOSITORY ),
				$c->get( StockStore::class ),
				$c->get( TrackingLog::class ),
				$c->get( Services::ONBOARDING ),
				$c->get( Services::LOGGER ),
				$c->get( Services::CLOCK )
			)
		);
		$this->add(
			$container,
			JobServices::TRACKING,
			TrackingService::class,
			static fn( Container $c ): TrackingService => new TrackingService(
				$c->get( TrackingSettings::class ),
				$c->get( JobServices::GATEWAY ),
				$c->get( JobServices::SHOP_ORDERS ),
				$c->get( Services::STATE_REPOSITORY ),
				$c->get( Services::ENQUEUER ),
				$c->get( TrackingLog::class ),
				$c->get( Services::OPTIONS ),
				$c->get( Services::ONBOARDING ),
				$c->get( Services::LOGGER ),
				$c->get( Services::CLOCK )
			)
		);
		$this->add(
			$container,
			JobServices::INVOICES,
			InvoiceService::class,
			static fn( Container $c ): InvoiceService => new InvoiceService(
				$c->get( JobServices::SHOP_ORDERS ),
				$c->get( Services::STATE_REPOSITORY ),
				$c->get( Services::ID_MAP_REPOSITORY ),
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::ENQUEUER ),
				$c->get( JobServices::GATEWAY ),
				$c->get( Services::REDACTOR ),
				$c->get( Services::LOGGER ),
				$c->get( Services::OPTIONS ),
				$c->get( Services::CLOCK )
			)
		);
		$this->add(
			$container,
			JobServices::RECONCILIATION,
			ReconService::class,
			static fn( Container $c ): ReconService => new ReconService(
				$c->get( Services::RECON_REPOSITORY ),
				$c->get( ReconSettings::class ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( JobServices::GATEWAY ),
				$c->get( JobServices::SHOP_ORDERS ),
				$c->get( JobServices::ENVIRONMENT ),
				$c->get( Services::ENQUEUER ),
				$c->get( Services::ID_MAP_REPOSITORY ),
				$c->get( Services::STATE_REPOSITORY ),
				$c->get( Services::ONBOARDING ),
				$c->get( Services::OPTIONS ),
				$c->get( Services::LOGGER ),
				$c->get( Services::CLOCK )
			)
		);
		$this->add(
			$container,
			JobServices::SHADOW,
			ShadowService::class,
			static fn( Container $c ): ShadowService => new ShadowService(
				$c->get( Services::ONBOARDING ),
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( JobServices::GATEWAY ),
				$c->get( JobServices::SHOP_ORDERS ),
				$c->get( ReconSettings::class ),
				$c->get( Services::OPTIONS ),
				$c->get( Services::LOGGER ),
				$c->get( Services::CLOCK )
			)
		);
		$this->add(
			$container,
			JobServices::GO_LIVE,
			GoLiveService::class,
			static fn( Container $c ): GoLiveService => new GoLiveService(
				$c->get( Services::ONBOARDING ),
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( JobServices::SHADOW ),
				$c->get( Services::LOGGER ),
				$c->get( Services::CLOCK ),
				static function ( int $connectionId, bool $stock, bool $tracking ) use ( $c ): void {
					$capabilities = array();
					foreach ( $c->get( Services::CONNECTION_REPOSITORY )->allWithoutSecrets() as $connection ) {
						if ( $connection->id === $connectionId ) {
							$connector    = $c->get( Services::CONNECTOR_REGISTRY )->get( $connection->connector );
							$capabilities = null === $connector ? array() : $connector->capabilities();
						}
					}
					if ( $stock && true === ( $capabilities['stock_in'] ?? false ) ) {
						$c->get( JobServices::STOCK )->saveRules( $connectionId, array( 'enabled' => true ) );
					}
					if ( $tracking && true === ( $capabilities['tracking_in'] ?? false ) ) {
						$c->get( JobServices::TRACKING )->saveSettings( $connectionId, array( 'tracking_enabled' => true ) );
					}
				}
			)
		);
	}

	/**
	 * Readiness, support report, settings port, notifications, capacity test and the inbound handler.
	 *
	 * @param Container $container Container.
	 */
	private function tools( Container $container ): void {
		$this->add(
			$container,
			JobServices::READINESS,
			ReadinessService::class,
			static fn( Container $c ): ReadinessService => new ReadinessService(
				$c->get( JobServices::ENVIRONMENT ),
				$c->get( JobServices::SHOP_CATALOG ),
				$c->get( JobServices::SHOP_CUSTOMERS ),
				$c->get( Services::SECRET_BOX ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( Urls::class ),
				$c->get( Services::OPTIONS ),
				$c->get( Services::CLOCK ),
				static fn(): ?string => $c->get( Services::SCHEDULER )->lastRunAt()
			)
		);
		$this->add(
			$container,
			JobServices::SUPPORT_REPORT,
			SupportReportBuilder::class,
			static fn( Container $c ): SupportReportBuilder => new SupportReportBuilder(
				$c->get( JobServices::ENVIRONMENT ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::LOG_REPOSITORY ),
				$c->get( JobServices::READINESS ),
				$c->get( Services::REDACTOR ),
				$c->get( Services::SECRET_BOX ),
				$c->get( Services::CLOCK ),
				static fn(): bool => Scheduler::usesActionScheduler()
			)
		);
		$this->add(
			$container,
			JobServices::PORTER,
			SettingsPorter::class,
			static fn( Container $c ): SettingsPorter => new SettingsPorter(
				$c->get( Services::SETTINGS ),
				$c->get( Services::CONNECTION_SETTINGS ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( Services::MAPPING_REPOSITORY ),
				$c->get( JobServices::STOCK ),
				$c->get( TrackingSettings::class ),
				$c->get( JobServices::ENVIRONMENT ),
				$c->get( Services::LOGGER ),
				$c->get( Services::CLOCK ),
				static fn( int $connectionId, string $type, array $rules, string $note, string $by ): mixed => $c->get( 'mapping.service' )->saveDraft( $connectionId, $type, $rules, $note, $by ),
				null,
				static fn( int $connectionId, string $type, array $rules ): array => $c->get( 'mapping.service' )->validate( $connectionId, $type, $rules )
			)
		);
		$this->add(
			$container,
			JobServices::NOTIFICATIONS,
			NotificationService::class,
			static fn( Container $c ): NotificationService => new NotificationService(
				$c->get( Services::SETTINGS ),
				$c->get( Mailer::class ),
				$c->get( Transients::class ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( JobServices::ENVIRONMENT ),
				$c->get( Urls::class ),
				$c->get( Services::OPTIONS ),
				$c->get( Services::LOGGER ),
				$c->get( Services::CLOCK )
			)
		);
		$this->add(
			$container,
			JobServices::CAPACITY,
			CapacityTest::class,
			static fn( Container $c ): CapacityTest => new CapacityTest(
				$c->get( Services::OPTIONS ),
				$c->get( Services::CLOCK ),
				static fn() => new TemporaryTableCapacityStore( $c->get( Services::WPDB ) )
			)
		);
		$this->add(
			$container,
			JobServices::INBOUND,
			InboundHandler::class,
			static fn( Container $c ): InboundHandler => new InboundHandler(
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( Services::SECRET_BOX ),
				$c->get( Transients::class ),
				$c->get( Services::LOGGER ),
				$c->get( Services::CLOCK )
			)
		);
	}

	/**
	 * Dashboard, connections and onboarding.
	 *
	 * @param Container $container Container.
	 */
	private function screens( Container $container ): void {
		$this->add(
			$container,
			JobServices::DASHBOARD,
			DashboardService::class,
			static fn( Container $c ): DashboardService => new DashboardService(
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				self::registry( $c ),
				$c->get( Services::LOG_REPOSITORY ),
				$c->get( Services::ONBOARDING ),
				$c->get( Services::SECRET_BOX ),
				$c->get( JobServices::ENVIRONMENT ),
				$c->get( JobServices::SHADOW ),
				$c->get( Transients::class ),
				$c->get( Services::CLOCK )
			)
		);
		$this->add(
			$container,
			JobServices::CONNECTIONS,
			ConnectionService::class,
			static fn( Container $c ): ConnectionService => new ConnectionService(
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( Services::CONNECTION_SETTINGS ),
				$c->get( Services::MAPPING_REPOSITORY ),
				self::registry( $c ),
				$c->get( Services::SECRET_BOX ),
				$c->get( Services::LOGGER ),
				$c->get( Services::CLOCK ),
				$c->get( Services::PAUSE_CONTROLLER ),
				$c->get( SignInPauses::class ),
				null,
				CatalogForms::cached( self::registry( $c ) )
			)
		);
		$this->add(
			$container,
			JobServices::ONBOARDING,
			OnboardingService::class,
			static fn( Container $c ): OnboardingService => new OnboardingService( $c->get( Services::ONBOARDING ), $c->get( JobServices::SHOP_ORDERS ) )
		);
	}

	/**
	 * Run the service method behind a recurring task or a single event.
	 *
	 * @param Container $container Container.
	 * @param string    $hook      Action name.
	 * @param string    $service   JobServices key.
	 * @param string    $method    Method to call.
	 */
	private function handle( Container $container, string $hook, string $service, string $method ): void {
		add_action(
			$hook,
			function () use ( $container, $service, $method ): void {
				$this->guard(
					$container,
					static function () use ( $container, $service, $method ): void {
						$container->get( $service )->$method();
					}
				);
			}
		);
	}

	/**
	 * Run one inbound refresh: stock, tracking, invoices, or all of them.
	 *
	 * @param Container $container    Container.
	 * @param int       $connectionId Connection.
	 * @param string    $entity       stock, tracking, invoice, order or all.
	 */
	private function refresh( Container $container, int $connectionId, string $entity ): void {
		if ( $connectionId <= 0 ) {
			return;
		}
		if ( in_array( $entity, array( 'stock', 'all' ), true ) ) {
			$container->get( JobServices::STOCK )->run( $connectionId );
		}
		if ( in_array( $entity, array( 'tracking', 'all' ), true ) ) {
			$container->get( JobServices::TRACKING )->run( $connectionId );
		}
		if ( in_array( $entity, array( 'invoice', 'order', 'all' ), true ) ) {
			$container->get( JobServices::INVOICES )->run( $connectionId );
		}
	}

	/**
	 * Run a task so that a failure in it is logged and never reaches the scheduler.
	 *
	 * @param Container $container Container.
	 * @param callable  $task      The work.
	 */
	private function guard( Container $container, callable $task ): void {
		try {
			$task();
		} catch ( JobException $e ) {
			unset( $e );
		} catch ( \Throwable $e ) {
			$container->get( Services::LOGGER )->error(
				sprintf(
					/* translators: %s: PHP error class name. */
					__( 'A background task stopped unexpectedly (%s). It will run again at its next time.', 'estasoft-erp-crm-sync-engine' ),
					( new \ReflectionClass( $e ) )->getShortName()
				)
			);
		}
	}

	/**
	 * A closure that returns the connector registry (resolved late, so add-ons are registered).
	 *
	 * @param Container $c Container.
	 * @return \Closure():ConnectorRegistry
	 */
	private static function registry( Container $c ): \Closure {
		return static fn(): ConnectorRegistry => $c->get( Services::CONNECTOR_REGISTRY );
	}

	/**
	 * Register a service under its key and under its class (or interface) name.
	 *
	 * @param Container $c       Container.
	 * @param string    $key     Key from JobServices.
	 * @param string    $alias   Class or interface name used as the alias.
	 * @param callable  $factory Receives the container.
	 */
	private function add( Container $c, string $key, string $alias, callable $factory ): void {
		$c->set( $key, $factory );
		$c->set( $alias, static fn( Container $c ): mixed => $c->get( $key ) );
	}
}
