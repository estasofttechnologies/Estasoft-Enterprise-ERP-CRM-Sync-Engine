<?php
/**
 * Container keys of the core services.
 *
 * @package Estasoft\SyncEngine
 */

declare(strict_types=1);

namespace Estasoft\SyncEngine\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Names of the services CoreModule registers. Each is also registered under its class name, so
 * `$container->get( QueueRepository::class )` returns the same object as `$container->get( Services::QUEUE_REPOSITORY )`.
 * The key `payload_builder` is not registered here: the Mapping module sets it.
 */
final class Services {

	public const CLOCK                  = 'core.clock';
	public const WPDB                   = 'core.wpdb';
	public const OPTIONS                = 'core.options';
	public const TABLES                 = 'core.tables';
	public const SCHEMA                 = 'core.schema';
	public const MIGRATOR               = 'core.migrator';
	public const RETENTION_JOB          = 'core.retention_job';
	public const DATA_ERASER            = 'core.data_eraser';
	public const SECRET_BOX             = 'core.secret_box';
	public const SETTINGS               = 'core.settings';
	public const CONNECTION_SETTINGS    = 'core.connection_settings';
	public const ONBOARDING             = 'core.onboarding';
	public const HTTP_CLIENT            = 'core.http_client';
	public const CONNECTOR_REGISTRY     = 'core.connector_registry';

	public const CONNECTION_REPOSITORY  = 'core.connection_repository';
	public const QUEUE_REPOSITORY       = 'core.queue_repository';
	public const LOG_REPOSITORY         = 'core.log_repository';
	public const MAPPING_REPOSITORY     = 'core.mapping_repository';
	public const ID_MAP_REPOSITORY      = 'core.id_map_repository';
	public const STATE_REPOSITORY       = 'core.state_repository';
	public const IDEMPOTENCY_REPOSITORY = 'core.idempotency_repository';
	public const RECON_REPOSITORY       = 'core.recon_repository';
	public const RATE_STATE_REPOSITORY  = 'core.rate_state_repository';

	public const REDACTOR               = 'core.redactor';
	public const LOGGER                 = 'core.logger';
	public const LOG_PRUNER             = 'core.log_pruner';

	public const ENQUEUER               = 'core.enqueuer';
	public const WORKER                 = 'core.worker';
	public const ITEM_PROCESSOR         = 'core.item_processor';
	public const RETRY_POLICY           = 'core.retry_policy';
	public const CIRCUIT_BREAKER        = 'core.circuit_breaker';
	public const RATE_LIMITER           = 'core.rate_limiter';
	public const REAPER                 = 'core.reaper';
	public const ERROR_CLASSIFIER       = 'core.error_classifier';
	public const SCHEDULER              = 'core.scheduler';
	public const PAUSE_CONTROLLER       = 'core.pause_controller';
	public const QUEUE_SERVICE          = 'core.queue_service';

	/**
	 * Set by the Mapping module; the queue resolves it lazily and fails an item with SYNC-CFG-001 when it is absent.
	 */
	public const PAYLOAD_BUILDER        = 'payload_builder';
}
