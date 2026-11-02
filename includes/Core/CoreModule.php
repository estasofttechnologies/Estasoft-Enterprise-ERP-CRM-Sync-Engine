<?php
/**
 * Wires the core engine into WordPress.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Container;
use Estasoft\SyncEngine\Contract\ConnectorRegistry;
use Estasoft\SyncEngine\Contract\HttpClientInterface;
use Estasoft\SyncEngine\Core\Crypto\SecretBox;
use Estasoft\SyncEngine\Core\Database\Migrator;
use Estasoft\SyncEngine\Core\Database\RetentionJob;
use Estasoft\SyncEngine\Core\Database\Schema;
use Estasoft\SyncEngine\Core\Database\Tables;
use Estasoft\SyncEngine\Core\Database\WpdbProvider;
use Estasoft\SyncEngine\Core\Logging\LogPruner;
use Estasoft\SyncEngine\Core\Logging\Logger;
use Estasoft\SyncEngine\Core\Logging\Redactor;
use Estasoft\SyncEngine\Core\Queue\CircuitBreaker;
use Estasoft\SyncEngine\Core\Queue\Enqueuer;
use Estasoft\SyncEngine\Core\Queue\ErrorClassifier;
use Estasoft\SyncEngine\Core\Queue\ItemProcessor;
use Estasoft\SyncEngine\Core\Queue\ObjectCacheRateStore;
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
use Estasoft\SyncEngine\Core\Repositories\RateStateRepository;
use Estasoft\SyncEngine\Core\Repositories\ReconRepository;
use Estasoft\SyncEngine\Core\Repositories\StateRepository;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\SystemClock;
use Estasoft\SyncEngine\Core\Support\WpHttpClient;
use Estasoft\SyncEngine\Core\Support\WpOptionStore;
use Estasoft\SyncEngine\ModuleInterface;
use Estasoft\SyncEngine\Sync\PayloadBuilderInterface;

/**
 * Registers every core service in the container, lazily, and adds the hooks the engine needs: the migrator, the capability
 * grant on upgrade, the background task handlers, new-site setup on multisite, and the log entry for turning redaction off.
 *
 * Container keys are the constants of Services (for example Services::QUEUE_REPOSITORY, 'core.queue_repository'); every service is also
 * registered under its class name. `docs/core-services.md` lists them with their public methods. The Mapping module sets
 * `payload_builder` (Services::PAYLOAD_BUILDER).
 */
final class CoreModule implements ModuleInterface {

	/**
	 * Register services.
	 *
	 * @param Container $container Service container.
	 */
	public function services( Container $container ): void {
		$this->infrastructure( $container );
		$this->repositories( $container );
		$this->logging( $container );
		$this->queue( $container );
	}

	/**
	 * Add hooks.
	 *
	 * @param Container $container Service container.
	 */
	public function hooks( Container $container ): void {
		add_action(
			'plugins_loaded',
			static function () use ( $container ): void {
				$container->get( Services::MIGRATOR )->maybeMigrate();
			},
			21
		);
		add_action(
			'admin_init',
			static function () use ( $container ): void {
				$container->get( Services::MIGRATOR )->maybeMigrate();
			},
			1
		);
		add_action( 'estasoft_sync_migrated', array( Capabilities::class, 'grant' ) );
		add_action( 'wp_initialize_site', array( Installer::class, 'installNewSite' ), 20 );
		add_action(
			'estasoft_sync_settings_updated',
			function ( $after, $before ) use ( $container ): void {
				$this->logSettingsChange( $container, (array) $after, (array) $before );
			},
			10,
			2
		);
		Scheduler::registerHooks( static fn(): Scheduler => $container->get( Services::SCHEDULER ) );
	}

	/**
	 * Clock, database, options, encryption, settings, HTTP and the connector registry.
	 *
	 * @param Container $c Container.
	 */
	private function infrastructure( Container $c ): void {
		$this->add( $c, Services::CLOCK, Clock::class, static fn(): Clock => new SystemClock() );
		$this->add( $c, Services::WPDB, \wpdb::class, static fn(): \wpdb => ( new WpdbProvider() )->get() );
		$this->add( $c, Services::OPTIONS, OptionStore::class, static fn(): OptionStore => new WpOptionStore() );
		$this->add( $c, Services::TABLES, Tables::class, static fn( Container $c ): Tables => new Tables( $c->get( Services::WPDB )->prefix ) );
		$this->add(
			$c,
			Services::SCHEMA,
			Schema::class,
			static fn( Container $c ): Schema => new Schema( $c->get( Services::TABLES ), $c->get( Services::WPDB )->get_charset_collate() )
		);
		$this->add(
			$c,
			Services::MIGRATOR,
			Migrator::class,
			static fn( Container $c ): Migrator => new Migrator( $c->get( Services::SCHEMA ), $c->get( Services::TABLES ), $c->get( Services::OPTIONS ), $c->get( Services::WPDB ) )
		);
		$this->add(
			$c,
			Services::DATA_ERASER,
			DataEraser::class,
			static fn( Container $c ): DataEraser => new DataEraser( $c->get( Services::WPDB ), $c->get( Services::TABLES ) )
		);
		$this->add( $c, Services::SECRET_BOX, SecretBox::class, static fn(): SecretBox => SecretBox::fromConstant() );
		$this->add(
			$c,
			Services::SETTINGS,
			Settings::class,
			static fn( Container $c ): Settings => new Settings( $c->get( Services::OPTIONS ), (string) get_option( 'admin_email', '' ) )
		);
		$this->add( $c, Services::CONNECTION_SETTINGS, ConnectionSettings::class, static fn(): ConnectionSettings => new ConnectionSettings() );
		$this->add( $c, Services::ONBOARDING, OnboardingState::class, static fn( Container $c ): OnboardingState => new OnboardingState( $c->get( Services::OPTIONS ) ) );
		$this->add( $c, Services::HTTP_CLIENT, HttpClientInterface::class, static fn(): HttpClientInterface => new WpHttpClient() );
		$this->add(
			$c,
			Services::CONNECTOR_REGISTRY,
			ConnectorRegistry::class,
			static function (): ConnectorRegistry {
				/**
				 * Register connectors. Add objects that implement ConnectorInterface.
				 *
				 * @param array<int,object> $connectors Connector objects.
				 */
				return new ConnectorRegistry( (array) apply_filters( 'estasoft_sync_register_connectors', array() ) );
			}
		);
	}

	/**
	 * The eight repositories (and the rate counter store).
	 *
	 * @param Container $c Container.
	 */
	private function repositories( Container $c ): void {
		$base = static fn( Container $c ): array => array( $c->get( Services::WPDB ), $c->get( Services::TABLES ), $c->get( Services::CLOCK ) );

		$this->add(
			$c,
			Services::CONNECTION_REPOSITORY,
			ConnectionRepository::class,
			static fn( Container $c ): ConnectionRepository => new ConnectionRepository( ...$base( $c ), ...array( $c->get( Services::SECRET_BOX ), $c->get( Services::CONNECTION_SETTINGS ) ) )
		);
		$this->add(
			$c,
			Services::QUEUE_REPOSITORY,
			QueueRepository::class,
			static fn( Container $c ): QueueRepository => new QueueRepository( ...$base( $c ), ...array( wp_timezone() ) )
		);
		$this->add( $c, Services::LOG_REPOSITORY, LogRepository::class, static fn( Container $c ): LogRepository => new LogRepository( ...$base( $c ) ) );
		$this->add( $c, Services::MAPPING_REPOSITORY, MappingRepository::class, static fn( Container $c ): MappingRepository => new MappingRepository( ...$base( $c ) ) );
		$this->add( $c, Services::ID_MAP_REPOSITORY, IdMapRepository::class, static fn( Container $c ): IdMapRepository => new IdMapRepository( ...$base( $c ) ) );
		$this->add( $c, Services::STATE_REPOSITORY, StateRepository::class, static fn( Container $c ): StateRepository => new StateRepository( ...$base( $c ) ) );
		$this->add( $c, Services::IDEMPOTENCY_REPOSITORY, IdempotencyRepository::class, static fn( Container $c ): IdempotencyRepository => new IdempotencyRepository( ...$base( $c ) ) );
		$this->add( $c, Services::RECON_REPOSITORY, ReconRepository::class, static fn( Container $c ): ReconRepository => new ReconRepository( ...$base( $c ) ) );
		$this->add( $c, Services::RATE_STATE_REPOSITORY, RateStateRepository::class, static fn( Container $c ): RateStateRepository => new RateStateRepository( ...$base( $c ) ) );
	}

	/**
	 * Redactor, logger and the log pruner.
	 *
	 * @param Container $c Container.
	 */
	private function logging( Container $c ): void {
		$this->add( $c, Services::REDACTOR, Redactor::class, static fn(): Redactor => new Redactor() );
		$this->add(
			$c,
			Services::LOGGER,
			Logger::class,
			static fn( Container $c ): Logger => new Logger( $c->get( Services::LOG_REPOSITORY ), $c->get( Services::SETTINGS ), $c->get( Services::REDACTOR ) )
		);
		$this->add(
			$c,
			Services::LOG_PRUNER,
			LogPruner::class,
			static fn( Container $c ): LogPruner => new LogPruner( $c->get( Services::LOG_REPOSITORY ), $c->get( Services::SETTINGS ) )
		);
		$this->add(
			$c,
			Services::RETENTION_JOB,
			RetentionJob::class,
			static fn( Container $c ): RetentionJob => new RetentionJob(
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::IDEMPOTENCY_REPOSITORY ),
				$c->get( Services::RECON_REPOSITORY ),
				$c->get( Services::LOG_PRUNER )
			)
		);
	}

	/**
	 * The queue: enqueuer, worker, pipeline, retry, breaker, limiter, reaper, classifier, scheduler, pause, service.
	 *
	 * @param Container $c Container.
	 */
	private function queue( Container $c ): void {
		$registry = static fn( Container $c ): \Closure => static fn(): ConnectorRegistry => $c->get( Services::CONNECTOR_REGISTRY );

		$this->add( $c, Services::RETRY_POLICY, RetryPolicy::class, static fn( Container $c ): RetryPolicy => new RetryPolicy( $c->get( Services::CLOCK ) ) );
		$this->add( $c, Services::ERROR_CLASSIFIER, ErrorClassifier::class, static fn(): ErrorClassifier => new ErrorClassifier() );
		$this->add(
			$c,
			Services::RATE_LIMITER,
			RateLimiter::class,
			static fn( Container $c ): RateLimiter => new RateLimiter(
				wp_using_ext_object_cache() ? new ObjectCacheRateStore() : $c->get( Services::RATE_STATE_REPOSITORY ),
				$c->get( Services::CLOCK )
			)
		);
		$this->add(
			$c,
			Services::PAUSE_CONTROLLER,
			PauseController::class,
			static fn( Container $c ): PauseController => new PauseController( $c->get( Services::CONNECTION_REPOSITORY ), $c->get( Services::LOGGER ), $c->get( Services::CLOCK ) )
		);
		$this->add(
			$c,
			Services::CIRCUIT_BREAKER,
			CircuitBreaker::class,
			static fn( Container $c ): CircuitBreaker => new CircuitBreaker( $c->get( Services::CONNECTION_REPOSITORY ), $registry( $c ), $c->get( Services::LOGGER ), $c->get( Services::CLOCK ) )
		);
		$this->add(
			$c,
			Services::REAPER,
			Reaper::class,
			static fn( Container $c ): Reaper => new Reaper( $c->get( Services::QUEUE_REPOSITORY ), $c->get( Services::LOGGER ) )
		);
		$this->add(
			$c,
			Services::ENQUEUER,
			Enqueuer::class,
			static fn( Container $c ): Enqueuer => new Enqueuer(
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::IDEMPOTENCY_REPOSITORY ),
				$c->get( Services::ONBOARDING ),
				$c->get( Services::LOGGER ),
				$c->get( Services::CLOCK ),
				$registry( $c )
			)
		);
		$this->add(
			$c,
			Services::ITEM_PROCESSOR,
			ItemProcessor::class,
			static fn( Container $c ): ItemProcessor => new ItemProcessor(
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::ID_MAP_REPOSITORY ),
				$c->get( Services::STATE_REPOSITORY ),
				$c->get( Services::IDEMPOTENCY_REPOSITORY ),
				$c->get( Services::LOGGER ),
				$c->get( Services::RETRY_POLICY ),
				$c->get( Services::ERROR_CLASSIFIER ),
				$c->get( Services::CIRCUIT_BREAKER ),
				$c->get( Services::PAUSE_CONTROLLER ),
				$c->get( Services::CLOCK ),
				$registry( $c ),
				static function () use ( $c ): ?PayloadBuilderInterface {
					if ( ! $c->has( Services::PAYLOAD_BUILDER ) ) {
						return null;
					}
					$builder = $c->get( Services::PAYLOAD_BUILDER );
					return $builder instanceof PayloadBuilderInterface ? $builder : null;
				}
			)
		);
		$this->add(
			$c,
			Services::WORKER,
			Worker::class,
			static fn( Container $c ): Worker => new Worker(
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::CONNECTION_REPOSITORY ),
				$c->get( Services::ITEM_PROCESSOR ),
				$c->get( Services::RATE_LIMITER ),
				$c->get( Services::REAPER ),
				$c->get( Services::CIRCUIT_BREAKER ),
				$c->get( Services::PAUSE_CONTROLLER ),
				$c->get( Services::SETTINGS ),
				$c->get( Services::CLOCK )
			)
		);
		$this->add(
			$c,
			Services::SCHEDULER,
			Scheduler::class,
			static fn( Container $c ): Scheduler => new Scheduler(
				$c->get( Services::WORKER ),
				$c->get( Services::REAPER ),
				$c->get( Services::CIRCUIT_BREAKER ),
				$c->get( Services::PAUSE_CONTROLLER ),
				$c->get( Services::RETENTION_JOB ),
				$c->get( Services::SETTINGS ),
				$c->get( Services::OPTIONS ),
				$c->get( Services::CLOCK )
			)
		);
		$this->add(
			$c,
			Services::QUEUE_SERVICE,
			QueueService::class,
			static fn( Container $c ): QueueService => new QueueService(
				$c->get( Services::QUEUE_REPOSITORY ),
				$c->get( Services::LOG_REPOSITORY ),
				$c->get( Services::LOGGER ),
				$c->get( Services::SCHEDULER )
			)
		);
	}

	/**
	 * Register a service under its key and under its class name.
	 *
	 * @param Container $c       Container.
	 * @param string    $key     Key from Services.
	 * @param string    $class   Class or interface name used as the alias.
	 * @param callable  $factory Receives the container.
	 */
	private function add( Container $c, string $key, string $class, callable $factory ): void {
		$c->set( $key, $factory );
		$c->set( $class, static fn( Container $c ): mixed => $c->get( $key ) );
	}

	/**
	 * Write the log entry for turning redaction off (or back on). The entry carries no personal data.
	 *
	 * @param Container           $c      Container.
	 * @param array<string,mixed> $after  Settings now.
	 * @param array<string,mixed> $before Settings before.
	 */
	private function logSettingsChange( Container $c, array $after, array $before ): void {
		if ( ( $before['redaction'] ?? true ) === ( $after['redaction'] ?? true ) ) {
			return;
		}
		$user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( empty( $after['redaction'] ) ) {
			$message = $user > 0
				/* translators: %d: user id. */
				? sprintf( __( 'Redaction was turned off by user #%d. Names, emails and addresses can now appear in log entries.', 'estasoft-erp-crm-sync-engine' ), $user )
				: __( 'Redaction was turned off. Names, emails and addresses can now appear in log entries.', 'estasoft-erp-crm-sync-engine' );
			$c->get( Services::LOGGER )->warning( $message );
			return;
		}
		$message = $user > 0
			/* translators: %d: user id. */
			? sprintf( __( 'Redaction was turned on by user #%d.', 'estasoft-erp-crm-sync-engine' ), $user )
			: __( 'Redaction was turned on.', 'estasoft-erp-crm-sync-engine' );
		$c->get( Services::LOGGER )->info( $message );
	}
}
