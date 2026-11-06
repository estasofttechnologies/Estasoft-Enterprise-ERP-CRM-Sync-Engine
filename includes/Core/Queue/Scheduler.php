<?php
/**
 * Background tasks: Action Scheduler first, WP-Cron when it is not available.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core\Queue;

defined( 'ABSPATH' ) || exit;

use Estasoft\SyncEngine\Core\Database\RetentionJob;
use Estasoft\SyncEngine\Core\Settings;
use Estasoft\SyncEngine\Core\Support\Clock;
use Estasoft\SyncEngine\Core\Support\OptionStore;
use Estasoft\SyncEngine\Core\Support\Time;

/**
 * Five recurring tasks in the Action Scheduler group `estasoft-sync`: the queue runner and the reaper every minute,
 * the circuit probe every minute (it acts only when a probe is due), the data clean-up daily, and the purge of finished
 * scheduler rows daily. WooCommerce ships Action Scheduler, so it is normally there; every call to an `as_*` function is
 * guarded, and when it is missing the same tasks run from WP-Cron.
 *
 * Scheduling is static so the activation hook can use it before the plugin has booted. The handlers are instance methods
 * registered by `registerHooks()`.
 */
final class Scheduler {

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'estasoft-sync';

	/**
	 * Claim and send items.
	 */
	public const HOOK_RUN = 'estasoft_sync_run_queue';

	/**
	 * Take back dead locks and end timed pauses.
	 */
	public const HOOK_REAP = 'estasoft_sync_reap_locks';

	/**
	 * Probe connections whose circuit is open.
	 */
	public const HOOK_PROBE = 'estasoft_sync_probe_connections';

	/**
	 * Apply retention times.
	 */
	public const HOOK_PRUNE = 'estasoft_sync_prune_data';

	/**
	 * Delete finished Action Scheduler rows of this group.
	 */
	public const HOOK_PURGE = 'estasoft_sync_purge_actions';

	/**
	 * Days a finished Action Scheduler row of this group is kept (PRF-6).
	 */
	public const PURGE_AFTER_DAYS = 7;

	/**
	 * Rows read and deleted per batch when purging.
	 */
	private const PURGE_BATCH = 500;

	/**
	 * Most batches per status in one run, so a huge backlog is worked off over several days instead of in one request.
	 */
	private const PURGE_MAX_BATCHES = 20;

	/**
	 * WP-Cron interval used when Action Scheduler is missing.
	 */
	public const CRON_SCHEDULE = 'estasoft_sync_minute';

	/**
	 * Option that holds the time of the last queue run.
	 */
	public const OPTION_LAST_RUN = 'estasoft_sync_last_run';

	/**
	 * Transient that says the tasks were checked recently.
	 */
	private const CHECKED = 'estasoft_sync_scheduled';

	/**
	 * Hooks and their interval in seconds.
	 *
	 * @var array<string,int>
	 */
	private const TASKS = array(
		self::HOOK_RUN   => 60,
		self::HOOK_REAP  => 60,
		self::HOOK_PROBE => 60,
		self::HOOK_PRUNE => 86400,
		self::HOOK_PURGE => 86400,
	);

	/**
	 * Set up the background task scheduler.
	 *
	 * @param Worker          $worker      Queue runner.
	 * @param Reaper          $reaper      Dead lock reaper.
	 * @param CircuitBreaker  $breaker     Circuit probes.
	 * @param PauseController $pause       Timed pauses.
	 * @param RetentionJob    $retention   Clean-up.
	 * @param Settings        $settings    Settings.
	 * @param OptionStore     $options     Options.
	 * @param Clock           $clock       Time source.
	 */
	public function __construct(
		private readonly Worker $worker,
		private readonly Reaper $reaper,
		private readonly CircuitBreaker $breaker,
		private readonly PauseController $pause,
		private readonly RetentionJob $retention,
		private readonly Settings $settings,
		private readonly OptionStore $options,
		private readonly Clock $clock
	) {
	}

	/**
	 * Add the WordPress hooks: the task handlers, the WP-Cron interval, and the check that the tasks are scheduled. The
	 * Scheduler object itself (and everything it needs) is created only when a task actually runs.
	 *
	 * @param \Closure $resolve Returns the shared Scheduler.
	 *
	 * @phpstan-param \Closure():Scheduler $resolve
	 */
	public static function registerHooks( \Closure $resolve ): void {
		$handlers = array(
			self::HOOK_RUN   => 'handleRun',
			self::HOOK_REAP  => 'handleReap',
			self::HOOK_PROBE => 'handleProbe',
			self::HOOK_PRUNE => 'handlePrune',
			self::HOOK_PURGE => 'handlePurge',
		);
		foreach ( $handlers as $hook => $method ) {
			add_action(
				$hook,
				static function () use ( $resolve, $method ): void {
					$resolve()->$method();
				}
			);
		}
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- the intervals come from the task list; the queue worker runs every minute by design, and this fallback is used only when Action Scheduler is not available.
		add_filter( 'cron_schedules', array( self::class, 'cronSchedules' ) );
		add_action( 'init', array( self::class, 'ensureScheduled' ), 20 );
	}

	/**
	 * Add the intervals that the tasks need to WP-Cron (the one-minute interval and any other interval another module
	 * registered through `estasoft_sync_scheduled_tasks`).
	 *
	 * @param array<string,array<string,mixed>> $schedules Existing intervals.
	 * @return array<string,array<string,mixed>>
	 */
	public static function cronSchedules( $schedules ) {
		$all = (array) $schedules;
		foreach ( array_unique( array_values( self::tasks() ) ) as $interval ) {
			$name = self::recurrence( $interval );
			if ( 'daily' === $name || isset( $all[ $name ] ) ) {
				continue;
			}
			$all[ $name ] = array(
				'interval' => $interval,
				'display'  => 60 === $interval
					? __( 'Every minute (Estasoft Sync)', 'estasoft-enterprise-erpcrm-sync-engine' )
					/* translators: %d: number of seconds between two runs. */
					: sprintf( __( 'Every %d seconds (Estasoft Sync)', 'estasoft-enterprise-erpcrm-sync-engine' ), $interval ),
			);
		}
		return $all;
	}

	/**
	 * Every recurring task: the five of this class plus the ones other modules add. The filter receives and returns a map
	 * of hook name to interval in seconds (60 or more). The module that adds a task also adds the handler for its hook.
	 *
	 * @return array<string,int>
	 */
	public static function tasks(): array {
		$tasks    = self::TASKS;
		$filtered = apply_filters( 'estasoft_sync_scheduled_tasks', $tasks );
		if ( ! is_array( $filtered ) ) {
			return $tasks;
		}
		foreach ( $filtered as $hook => $interval ) {
			if ( is_string( $hook ) && '' !== $hook && is_int( $interval ) && $interval >= 60 ) {
				$tasks[ $hook ] = $interval;
			}
		}
		return $tasks;
	}

	/**
	 * The WP-Cron recurrence name for an interval.
	 *
	 * @param int $interval Seconds between runs.
	 */
	private static function recurrence( int $interval ): string {
		if ( 60 === $interval ) {
			return self::CRON_SCHEDULE;
		}
		return DAY_IN_SECONDS === $interval ? 'daily' : 'estasoft_sync_every_' . $interval;
	}

	/**
	 * Schedule every recurring task that is not scheduled yet. Safe to call again.
	 */
	public static function schedule(): void {
		$use_scheduler = self::actionSchedulerReady();
		foreach ( self::tasks() as $hook => $interval ) {
			if ( $use_scheduler ) {
				if ( false === as_next_scheduled_action( $hook, array(), self::GROUP ) ) {
					as_schedule_recurring_action( time() + 15, $interval, $hook, array(), self::GROUP );
				}
				continue;
			}
			if ( false === wp_next_scheduled( $hook ) ) {
				// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- same task-list intervals as in registerHooks(); needed so WP-Cron knows the recurrence before the event is scheduled.
				add_filter( 'cron_schedules', array( self::class, 'cronSchedules' ) );
				wp_schedule_event( time() + 30, self::recurrence( $interval ), $hook );
			}
		}
	}

	/**
	 * Remove every task of this plugin from Action Scheduler and WP-Cron. Data stays.
	 */
	public static function unschedule(): void {
		foreach ( array_keys( self::tasks() ) as $hook ) {
			if ( self::actionSchedulerReady() && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
			wp_unschedule_hook( $hook );
		}
		delete_transient( self::CHECKED );
	}

	/**
	 * Make sure the tasks exist, at most once every 15 minutes, and only in admin, cron, AJAX and command-line requests. When Action Scheduler is available the WP-Cron copies are
	 * removed, so a site that gained Action Scheduler later does not run every task twice.
	 */
	public static function ensureScheduled(): void {
		if ( ! is_admin() && ! wp_doing_cron() && ! wp_doing_ajax() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( false !== get_transient( self::CHECKED ) ) {
			return;
		}
		if ( self::actionSchedulerReady() ) {
			foreach ( array_keys( self::tasks() ) as $hook ) {
				wp_unschedule_hook( $hook );
			}
		}
		self::schedule();
		set_transient( self::CHECKED, 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Whether tasks run through Action Scheduler (true) or WP-Cron (false).
	 */
	public static function usesActionScheduler(): bool {
		return self::actionSchedulerReady();
	}

	/**
	 * Whether background tasks wait for site visits: WP-Cron is on, so nothing runs unless someone loads a page.
	 * A real cron job on the server calls wp-cron.php and sets DISABLE_WP_CRON to stop this.
	 */
	public static function cronRunsOnVisits(): bool {
		return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/**
	 * Ask for a queue run as soon as possible ("Sync now").
	 *
	 * @return bool Whether a run was requested.
	 */
	public function runNow(): bool {
		if ( self::actionSchedulerReady() && function_exists( 'as_enqueue_async_action' ) ) {
			return 0 !== (int) as_enqueue_async_action( self::HOOK_RUN, array(), self::GROUP );
		}
		return false !== wp_schedule_single_event( time(), self::HOOK_RUN, array( (string) microtime( true ) ) );
	}

	/**
	 * The time of the last queue run, as a MySQL datetime (UTC).
	 */
	public function lastRunAt(): ?string {
		$value = (string) $this->options->get( self::OPTION_LAST_RUN, '' );
		return '' === $value ? null : $value;
	}

	/**
	 * Task: claim and send items.
	 */
	public function handleRun(): void {
		$this->options->set( self::OPTION_LAST_RUN, Time::sql( $this->clock->now() ) );
		$this->worker->run( Worker::MAX_SECONDS, $this->settings->globalConcurrency() );
	}

	/**
	 * Task: take back dead locks and end timed pauses.
	 */
	public function handleReap(): void {
		$this->reaper->run();
		$this->pause->resumeDue();
	}

	/**
	 * Task: probe connections whose circuit is open and whose probe is due.
	 */
	public function handleProbe(): void {
		$this->breaker->probeDue();
	}

	/**
	 * Task: apply retention times.
	 */
	public function handlePrune(): void {
		$this->retention->run();
	}

	/**
	 * Task: delete finished Action Scheduler rows of this group that are more than 7 days old (PRF-6), in batches.
	 *
	 * @return int Rows deleted.
	 */
	public function handlePurge(): int {
		if ( ! self::actionSchedulerReady() || ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler' ) ) {
			return 0;
		}
		$deleted = 0;
		$store   = \ActionScheduler::store();
		$before  = time() - self::PURGE_AFTER_DAYS * DAY_IN_SECONDS;
		foreach ( array( 'complete', 'canceled', 'failed' ) as $status ) {
			for ( $batch = 0; $batch < self::PURGE_MAX_BATCHES; ++$batch ) {
				$ids = (array) as_get_scheduled_actions(
					array(
						'group'        => self::GROUP,
						'status'       => $status,
						'date'         => $before,
						'date_compare' => '<',
						'per_page'     => self::PURGE_BATCH,
					),
					'ids'
				);
				foreach ( $ids as $id ) {
					$store->delete_action( (string) $id );
					++$deleted;
				}
				if ( count( $ids ) < self::PURGE_BATCH ) {
					break;
				}
			}
		}
		return $deleted;
	}

	/**
	 * Whether Action Scheduler is loaded and ready.
	 */
	private static function actionSchedulerReady(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_next_scheduled_action' )
			&& is_callable( array( 'ActionScheduler', 'is_initialized' ) )
			&& \ActionScheduler::is_initialized();
	}
}
