# Core services

Reference for the modules built on top of `includes/Core`. Keys are constants of `Estasoft\SyncEngine\Core\Services`; every service is also registered under its class name, so `$container->get( QueueRepository::class )` and `$container->get( Services::QUEUE_REPOSITORY )` return the same object. Namespace for everything below: `Estasoft\SyncEngine\Core\...`. Detail lives in the class docblocks.

The Mapping module sets `Services::PAYLOAD_BUILDER` (`'payload_builder'`) to a `Sync\PayloadBuilderInterface`. The queue resolves it lazily; when it is missing an item fails with `SYNC-CFG-001` and nothing is sent.

## Container keys

| Key (`Services::`) | Value | Class |
|---|---|---|
| `CLOCK` | `core.clock` | `Support\Clock` (`SystemClock`) |
| `WPDB`, `OPTIONS`, `TABLES`, `SCHEMA`, `MIGRATOR` | `core.wpdb` ... | `\wpdb`, `Support\OptionStore`, `Database\Tables`, `Database\Schema`, `Database\Migrator` |
| `DATA_ERASER`, `RETENTION_JOB` | `core.data_eraser`, `core.retention_job` | `DataEraser`, `Database\RetentionJob` |
| `SECRET_BOX` | `core.secret_box` | `Crypto\SecretBox` |
| `SETTINGS`, `CONNECTION_SETTINGS`, `ONBOARDING` | `core.settings` ... | `Settings`, `ConnectionSettings`, `OnboardingState` |
| `HTTP_CLIENT` | `core.http_client` | `Contract\HttpClientInterface` (`Support\WpHttpClient`) |
| `CONNECTOR_REGISTRY` | `core.connector_registry` | `Contract\ConnectorRegistry`, built lazily from the filter `estasoft_sync_register_connectors` |
| `CONNECTION_REPOSITORY`, `QUEUE_REPOSITORY`, `LOG_REPOSITORY`, `MAPPING_REPOSITORY`, `ID_MAP_REPOSITORY`, `STATE_REPOSITORY`, `IDEMPOTENCY_REPOSITORY`, `RECON_REPOSITORY` | `core.<name>` | `Repositories\<Name>Repository` |
| `REDACTOR`, `LOGGER`, `LOG_PRUNER` | `core.redactor` ... | `Logging\Redactor`, `Logging\Logger`, `Logging\LogPruner` |
| `ENQUEUER`, `WORKER`, `ITEM_PROCESSOR`, `RETRY_POLICY`, `CIRCUIT_BREAKER`, `RATE_LIMITER`, `REAPER`, `ERROR_CLASSIFIER`, `SCHEDULER`, `PAUSE_CONTROLLER`, `QUEUE_SERVICE` | `core.<name>` | `Queue\<Name>` |

## Database

Nine tables named `{prefix}estasoft_sync_{connections,queue,logs,mappings,id_map,state,idempotency,recon_runs,recon_diffs}` (utf8mb4, created with `dbDelta`). `Schema::VERSION` is stored in the autoloaded option `estasoft_sync_db_version`; `Migrator::maybeMigrate()` runs on `plugins_loaded` (21) and `admin_init` (1) and is idempotent. All other options are not autoloaded. Time is UTC `Y-m-d H:i:s`; the repositories return ISO `...Z` strings in the shapes of `src/api/types.ts`.

## Repositories

Only repositories touch `$wpdb`. Every query is prepared; table names come from `Tables`.

- **ConnectionRepository**: `find(id): ?Connection` (decrypted, for sending; throws `Crypto\CryptoException`), `findWithoutSecrets(id)`, `findPublic(id): ?array` (`Connection` of types.ts minus `credentials`; adds `secret_hints`, `secrets_readable`, `config`), `all(): array` (public arrays), `allWithoutSecrets()`, `forConnector(id)`, `activeIds()`, `pausesDue()`, `create(config, secrets, settings): int` (`config` carries `connector`, `type`, `label`, the rest is stored as config), `update(id, changes)` (keys `label`, `config`, `secrets` where `''` keeps and `null` removes, `settings`, `credential_expires_at`, `credential_warn_days`, `inbound_enabled`), `setStatus(id, ConnectionStatus, ?pausedUntil, ?reason)`, `incrementFailures`, `resetFailures`, `tripCircuit`, `scheduleProbe`, `resumeFromCircuit`, `recordSuccess(id, ms)`, `recordFailure(id, ms)`, `delete(id)` (disconnects: wipes secrets, keeps the row and its history).
- **QueueRepository**: `enqueue(...)`, `claim(limit, lockSeconds, workerId, ?connectionId): Item[]`, `markSynced`, `markRetrying`, `markFailed`, `release` (back to queued without a try), `skip`, `restore`, `retry`, `failedIdsByGroup`, `find(id): ?Item`, `findRow(id): ?array` (`QueueItem` plus idempotency_key, partition_key, mapping_version, log_id), `siblings(id)`, `query(QueueQuery): Paged`, `countsByState(?connectionId, ?mode = 'live')`, `syncedToday`, `countSyncing`, `extendLock`, `hourly()` (24 buckets), `oldestWaiting()`, `attentionGroups()`, `reapStaleLocks(): int` / `reapStaleLockIds()`, `prune()`, `setDisplay`, `setLastLog`, `findUnfinished`, `noteChangedWhileWaiting`.
- **LogRepository**: `write(entry)`, `query(LogQuery): Paged`, `find(id)`, `latest(n)`, `timeline(queueId)`, `prune(days)`, `clear()`, `csv(LogQuery): string`.
- **MappingRepository**: `live`, `draft`, `find`, `versions`, `saveDraft`, `discardDraft`, `publish`, `rollback` (new live version copied from an old one), `seedLive` (version 1), `replaceLive` (unversioned types such as `tax`). Publish and rollback run in a transaction that locks the connection and type.
- **IdMapRepository**: `get`, `link` (a person's `is_override` link is never replaced by automatic matching), `forget`, `findBySku`, `findWcId`. Type is an `EntityType` or the string `product`.
- **StateRepository**: `get`, `isUnchanged(hash)`, `save`, `mergeExtra`, `forget`.
- **IdempotencyRepository**: `reserve`, `attach`, `release`, `queueIdFor`, `exists`, `markDone`, `get`, `countFor`, `prune`.
- **ReconRepository**: `startRun`, `finishRun`, `addDiff`, `findRun`, `runs`, `lastRun`, `diffs`, `diff`, `markRepaired`, `ignore`, `openCount`, `prune`.

`QueueQuery::fromArray()` and `LogQuery::fromArray()` take request parameters named as in types.ts and drop anything not recognised. `Paged::toArray()` is the `Paged<T>` shape.

## Queue

- **Enqueuer::enqueue(EntityType, entityId, event, ?connectionId, array $meta = []): ?int**. Never throws, no network, one row. `$meta` keys: `partition` (a refund passes `order:{parent id}`), `label`, `customer`, `total_minor`, `currency`, `search`. Same event twice returns the same item. `updated` joins a waiting item. Shadow window gives `mode = shadow`.
- **Worker::run(maxSeconds = 20, concurrency = 2): WorkerReport**. Never more than 20 seconds. Resumes ended pauses, probes open circuits and reaps dead locks first. Honours per-connection concurrency and requests per second, the global concurrency setting, pauses and the breaker.
- **ItemProcessor::process(Item): Outcome**. Build payload, skip a no-op by hash, look the record up before the first send (SYNC-DUP-001), send, classify, write the result. Shadow items are built and looked up read-only but nothing is sent or stored in either system. A refused sign-in (AUTH-001) pauses the connection and leaves the item queued; AUTH-002/003/005 mark it needs-reconnect; a missing key (CFG-002) pauses it.
- **RetryPolicy**: 60 s, 300 s, 3600 s, 86400 s plus 0 to 20 s jitter, `Retry-After` wins when longer (cap 24 h), five tries. **CircuitBreaker**: threshold and probe interval per connection (`paused_until` holds the next probe time). **RateLimiter**: one slot per `1 / rate` seconds per connection, shared by all workers through the object cache or an options row. **Reaper**: locks last 60 s.
- **QueueService** (REST façade): `retry`, `retryMany`, `retryGroup(code, ?cause)`, `skip(id, reason)`, `skipMany`, `restore`, `restoreMany`, `bulk(ids, action, ?reason)`, `syncNow()`, `detail(id)` (`QueueItemDetail`).
- **PauseController**: `pause(id, ?minutes)`, `resume(id)`, `pauseAll`, `resumeAll`, `resumeDue`, `holdForPerson`.
- **Scheduler** (static `schedule()`, `unschedule()`, `ensureScheduled()`, `cronRunsOnVisits()`, `usesActionScheduler()`): five recurring tasks in the group `estasoft-sync`: `estasoft_sync_run_queue`, `_reap_locks`, `_probe_connections` every minute, `_prune_data`, `_purge_actions` daily; WP-Cron when Action Scheduler is missing. `lastRunAt()` for the readiness check.
- **ErrorClassifier**: `classify`, `fromResponse(HttpResponse, system): ?ConnectorError` (default HTTP mapping: 401 AUTH-001, 403 AUTH-004, 429 RATE-001, 408/425/transport NET-001, certificate NET-003, 5xx NET-002, other 4xx VAL-002), `cause`.

## Settings, capabilities, logging, crypto

- **Settings**: `get()` is the `Settings` of types.ts; `update(changes)` throws `Support\ValidationException` with one message per field (`errors()`); `validate(changes)` only reports. Getters for each field. Ranges are in `Support\Ranges` (also `SAFETY_BUFFER` for the stock job). **ConnectionSettings** does the same for the reliability settings of a connection, and `defaults($connector->rateLimitHints())` gives the starting values.
- **Capabilities**: `MANAGE`, `CONFIGURE`; static `grant()`, `revoke()`, `userCanManage()` (true for configure too), `userCanConfigure()`.
- **Logger** `info|warning|error(message, context)` returns the entry id or null and never throws. Context: `connection_id`, `queue_id`, `entity_ref`, `code`, `http_status`, `duration_ms`, `attempt`, `mapping_version`, `request`, `response`, `sensitive_paths`. Messages and excerpts are redacted first (`Redactor::namesFromSourcePaths()` turns mapping source paths into field names); secrets are removed even when personal-data redaction is off; excerpts are cut to the 2 KB / 8 KB / none setting and a payload over `payload_cap_bytes` is replaced by a note.
- **SecretBox** (`ESTASOFT_SYNC_KEY`): `keyAvailable()`, `fingerprint()`, `encrypt(array)`, `decrypt(string)`, `generateKeyLine()`. Missing key throws `CryptoException` with `ErrorCode::Cfg002`; a changed key throws one with no code.
- **Support**: `Money` (minor units, exponent table), `Time`, `Uuid`, `Json::canonical()`, `DayRange`, `WpHttpClient` (30 s, TLS verified, unsafe URLs refused, no redirects).

## Lifecycle

`Installer::activate(networkWide)`, `deactivate()`, `uninstall()` (loops over sites on multisite; erases data only when `delete_on_uninstall` is on), `installSite()`. `DataEraser::erase(revokeCapabilities)` is also what the Delete data tool should call. Meta keys removed on uninstall come from `MetaKeys` and the filter `estasoft_sync_meta_keys( array $keys, string $kind )` (`order`, `product`, `user`); other modules must add the keys they write.

## Hooks

Actions: `estasoft_sync_before_send( Item, array $payload, int $connectionId )`, `estasoft_sync_after_send( Item, SendResult, int $ms )`, `estasoft_sync_item_synced( Item, ExternalRef, bool $alreadyExisted )`, `estasoft_sync_item_failed( Item, ConnectorError, bool $willRetry )`, `estasoft_sync_connection_paused( int $id, 'person'|'circuit'|'sign_in' )`, `estasoft_sync_connection_resumed( int $id, 'person'|'timer'|'probe'|'success' )`, `estasoft_sync_shadow_processed( Item, PayloadResult, ExternalRef|ConnectorError|null )`, `estasoft_sync_settings_updated( array $after, array $before )`, `estasoft_sync_migrated( string $from, string $to )`.

Filters: `estasoft_sync_register_connectors( array )`, `estasoft_sync_should_enqueue( bool, EntityType, string $id, string $event, int $connectionId )`, `estasoft_sync_queue_display( array, EntityType, string $id, string $event )`, `estasoft_sync_retry_schedule( array $seconds, array $settings )`, `estasoft_sync_classify_error( ErrorClass, ConnectorError, string $connectorId )`, `estasoft_sync_log_redact( string, array $context )`, `estasoft_sync_allow_private_hosts( bool, string $url )`, `estasoft_sync_meta_keys`.
