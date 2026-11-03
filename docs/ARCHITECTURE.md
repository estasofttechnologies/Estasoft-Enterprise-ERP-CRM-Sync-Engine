# Architecture

Estasoft Enterprise ERP/CRM Sync Engine (ESTASOFT SYNC). WordPress plugin, PHP 8.1+, WordPress 6.4+, WooCommerce 8.2+ (HPOS first). The product specification is `docs/spec/phase1-spec.md`; where `docs/spec/connection-methods-spec.md` or `docs/spec/scope-update.md` disagree with it, they win.

## Principles

1. **Checkout never waits.** An order event writes one row to the queue table in the order's own database transaction. No network call in a customer request.
2. **At least once, exactly once in effect.** Idempotency key per event, lookup by client reference `WC-{order id}` before any create, an id map, and a state table with data hashes.
3. **WooCommerce through its CRUD layer only.** `wc_get_order`, `wc_get_orders`, `$order->update_meta_data()`. No SQL on `posts`, `postmeta` or HPOS tables.
4. **Secrets are encrypted** with libsodium `secretbox`. The key is the constant `ESTASOFT_SYNC_KEY` in `wp-config.php` when the site defines one, otherwise it is derived from the WordPress security keys and salts in the same file. Never in options, logs, REST responses or exceptions.
5. **Everything the person sees comes through the REST API** (`estasoft-sync/v1`) into a React app. PHP renders only the pieces WordPress requires it to render (order box, orders list column, product box, admin bar, dashboard widget, notices, emails).
6. **No telemetry, no relay, no outside server.** The plugin contacts only the system the merchant connected.
7. **All features free.** No licence, tier or upsell code anywhere.

## Layout

```
estasoft-erp-crm-sync-engine.php   plugin header, early requirement guard, boot on plugins_loaded
uninstall.php                      calls Core\Installer::uninstall()
includes/                          PHP, namespace Estasoft\SyncEngine (PSR-4, own autoloader, no vendor dir)
  Plugin.php Container.php ModuleInterface.php Lifecycle.php Autoloader.php requirements.php
  Domain/       enums and value objects (ErrorCode, ItemState, Connection, Item ...)
  Contract/     connector contract v1 (ConnectorInterface, SendResult, TestResult, HttpClientInterface ...)
  Sync/         PayloadBuilderInterface, PayloadResult (the seam between queue and mapping)
  Core/         CoreModule, Installer, Database (Schema, Migrator), Repositories, Settings, Capabilities,
                Logging, Crypto, Queue (Enqueuer, Claimer, ItemProcessor, Worker, RetryPolicy, CircuitBreaker,
                RateLimiter, Reaper, ErrorClassifier, Scheduler), Support (Clock, Money, Redactor, Uuid, WpHttpClient)
  Connectors/   ConnectorsModule, Odoo/ (OdooConnector, transports, fixtures)
  Mapping/      MappingModule, RuleEngine, Transforms, Presets, TaxMap, SkuMatcher, PayloadBuilder, CanonicalOrder
  Woo/          WooModule, Compatibility (HPOS, blocks), OrderHooks, OrderMeta, OrderBox, OrdersList, ProductBox
  Jobs/         JobsModule, StockSync, TrackingSync, InvoiceSync, Reconciler, Shadow, Notifications, Digest,
                Readiness, SupportReport, SettingsPorter (export/import), CapacityTest
  Rest/         RestModule + one controller per route group
  Admin/        AdminModule, Menu, AdminBar, DashboardWidget, Notices, Assets, Help, PluginLinks, Welcome
  Cli/          CliModule, Commands
  Catalog/      catalog.php (34 systems, static, filterable)
src/            React app (TypeScript, @wordpress/scripts); src/api/types.ts is the REST contract
tests/php/      Unit (no WordPress), Integration (real WordPress + MySQL), Support, stubs
tests/js/       Jest tests
bin/            build, i18n, install-test-wp.php, check-all.sh
docs/           spec, research, decisions, design status, this file
.wordpress-org/ icon and banner for the WordPress.org assets folder
```

A **module** implements `ModuleInterface`: `services(Container)` registers lazily created services, then `hooks(Container)` adds WordPress hooks. `Plugin::moduleClasses()` lists them; a module whose class is missing is skipped. Nothing runs at file load time except the guard.

## Seams between modules (do not break these)

| Seam | Defined in | Implemented by | Used by |
|---|---|---|---|
| `PayloadBuilderInterface::build(Item, Connection): PayloadResult` | `Sync/` | `Mapping\PayloadBuilder` | `Core\Queue\ItemProcessor` |
| `ConnectorInterface` | `Contract/` | `Connectors\Odoo\OdooConnector`, add-on plugins | `ItemProcessor`, Jobs, Rest (test, lookups) |
| `HttpClientInterface` | `Contract/` | `Core\Support\WpHttpClient` (wp_remote_request), `Tests\Support\FakeHttpClient` | every connector |
| `ConnectorRegistry` | `Contract/` | built from the `estasoft_sync_register_connectors` filter | everything that needs a connector by id |
| Repositories (`Core\Repositories\*`) | `Core/` | `Core` | Mapping, Woo, Jobs, Rest, Cli, Admin |
| `Core\Installer::activate/deactivate/uninstall` | `Core/` | `Core` | `Lifecycle`, `uninstall.php` |
| Hooks `estasoft_sync_*` | spec section 8 | | add-ons |

Repository and service method names are listed in the section "Core services" below; `docs/core-services.md` is the full reference (container keys, every public method, hooks) and is the contract for the modules built later.

## Core services (names the other modules call)

All in `Estasoft\SyncEngine\Core\...`. Methods return domain objects or plain arrays shaped like `src/api/types.ts`; nothing returns `wpdb` rows.

- `Repositories\ConnectionRepository`: `find(int): ?Connection` (with decrypted secrets, only for sending), `findPublic(int): ?array` (no secrets), `all(): array`, `forConnector(string): ?Connection`, `create(array $config, array $secrets, array $settings): int`, `update(int, array $changes): void` (secrets write-only), `setStatus(int, ConnectionStatus, ?string $pausedUntil = null): void`, `recordSuccess(int, int $ms)`, `recordFailure(int, int $ms)`, `delete(int)` (wipes secrets).
- `Repositories\QueueRepository`: `enqueue(...)`, `claim(int $limit, int $lockSeconds, string $workerId): array<Item>` (atomic, ordering and breaker aware), `markSynced`, `markRetrying`, `markFailed`, `skip`, `restore`, `retry`, `find(int)`, `query(QueueQuery): Paged`, `countsByState()`, `hourly()`, `oldestWaiting()`, `attentionGroups()`, `reapStaleLocks(): int`, `prune()`.
- `Repositories\LogRepository`: `write(...)`, `query`, `find`, `prune(int $days)`, `csv(query)`.
- `Repositories\MappingRepository`: `live(int $connectionId, string $type)`, `draft(...)`, `saveDraft(...)`, `publish(...)`, `discardDraft(...)`, `rollback(...)`, `versions(...)`.
- `Repositories\IdMapRepository`, `StateRepository`, `IdempotencyRepository`, `ReconRepository`.
- `Settings`: typed getters with defaults and validation (`Settings::get()` returns the shape of `Settings` in `types.ts`), `update(array)`.
- `Capabilities`: `MANAGE = 'estasoft_sync_manage'`, `CONFIGURE = 'estasoft_sync_configure'`; `grant()`, `revoke()`.
- `Queue\Enqueuer::enqueue(EntityType, string $entityId, string $event, ?int $connectionId = null, array $meta = []): ?int`. Never throws; failure is logged. `$meta` keys: `partition` (a refund passes `order:{parent id}`), `label`, `customer`, `total_minor`, `currency`, `search`.
- `Queue\Worker::run(int $maxSeconds = 20, int $concurrency = 2): WorkerReport`.
- `Queue\ItemProcessor::process(Item): Outcome` (build payload, find existing, send, classify, write result; `Outcome` says what happened so the worker can count it).
- `Logging\Logger`: `info/warning/error(string $message, array $context)` with redaction.

Modules add recurring background tasks through the filter `estasoft_sync_scheduled_tasks` (a map of hook name to interval in seconds, 60 or more; the module also adds the handler for that hook). Modules that write order, product or user meta add the keys through the filter `estasoft_sync_meta_keys( array $keys, string $kind )` so uninstall can remove them.

## Mapping rule model

A mapping is an ordered list of rules saved as `rules_json` in `estasoft_sync_mappings`. Source paths read the **canonical model** below. Target paths are the connector's field paths from `describeFields()`. Arrays use `[]`: `lines[].sku` to `order_line[].sku`.

```json
{
  "id": "r1",
  "target": "partner.name",
  "source": ["billing.first_name", "billing.last_name"],
  "transforms": [ { "type": "join", "args": { "separator": " " } }, { "type": "trim" } ],
  "required": true,
  "error_code": "SYNC-MAP-003",
  "when": { "source": "customer.is_guest", "equals": "false" }
}
```

`source` is a path, a list of paths (joined by a `join` transform or passed as list), or `{ "const": value }`. Transform library (fixed, no code): `prefix`, `suffix`, `trim`, `case` (upper, lower, title), `phone_e164` (arg `default_country`), `iso_country`, `iso_state`, `money_minor` (to the currency exponent), `date_utc`, `value_map`, `if_then`, `default`, `required`, `join`, `split`, `tax_lookup`, `sku_match`. Money is integer minor units end to end.

### Canonical model (source paths; built by `Mapping\CanonicalOrder` from WooCommerce)

```
order.id order.number order.status order.currency order.created_at order.paid_at order.note order.client_ref
order.total order.subtotal order.discount_total order.shipping_total order.tax_total   (integer minor units)
order.payment.method_id order.payment.method_title order.payment.transaction_id order.payment.last4 order.payment.is_cod
order.buyer_tax_number   (digits only, ASCII, spaces and dashes removed)
order.excluded_count order.excluded_total order.excluded_tax   (lines of products excluded from sync; order.total stays the WooCommerce figure)
customer.id customer.email customer.first_name customer.last_name customer.company customer.phone customer.is_guest
billing.first_name billing.last_name billing.company billing.address_1 billing.address_2 billing.city
billing.state billing.postcode billing.country billing.phone billing.email          (same keys under shipping.*)
lines[].product_id lines[].variation_id lines[].sku lines[].name lines[].quantity lines[].unit_price
lines[].line_total lines[].line_tax lines[].tax_rate lines[].tax_class
shipping_lines[].method_id shipping_lines[].method_title shipping_lines[].total shipping_lines[].tax shipping_lines[].tax_rate
fees[].name fees[].total fees[].tax        taxes[].rate_id taxes[].label taxes[].rate_percent taxes[].amount
coupons[].code coupons[].discount
refund.id refund.order_id refund.amount refund.reason refund.created_at refund.lines[].sku refund.lines[].quantity refund.lines[].amount refund.lines[].tax
refund.shipping_amount refund.excluded_amount refund.other_amount
```

Card data never enters the canonical model: only `transaction_id` and `last4`.

## Queue rules (from the spec, restated for implementers)

States `queued, syncing, synced, retrying, failed, skipped`; mode `live` or `shadow`. Partition key `order:{id}`; an item is claimable only when no earlier item of its partition is unfinished or failed (a skipped one does not block). Retry delays 1 min, 5 min, 1 h, 24 h (+ 0 to 20 s jitter; `Retry-After` wins when longer, capped at 24 h); five tries in all. Circuit breaker: 5 consecutive retryable failures (429 does not count) pause the connection, probe every 2 min. Defaults 4 requests per second, 2 items at once. Lock 60 s, reaper every minute. Per-request timeout 30 s. Idempotency key: `sha256("v1:" . connectionId . ":" . entityType . ":" . wcId . ":" . event . ":" . ordinal)`.

## Conventions

- `declare(strict_types=1)`; PHP 8.1 syntax (enums, readonly properties, match, constructor promotion). No syntax newer than 8.1.
- WordPress Coding Standards (`phpcs.xml.dist`); PSR-4 file names; camelCase methods; escape late, sanitise early, prepare every query with `$wpdb->prepare`, nonce and capability on every route.
- Every user-facing string uses `__()` or `_n()` with the text domain `estasoft-erp-crm-sync-engine`, placeholders instead of concatenation.
- UTC in storage, minor units for money, utf8mb4 everywhere, `dir="auto"` on displayed names.
- Classes are small and single purpose; no static state except enums; dependencies are passed in.
- Tests: unit tests need no WordPress (`phpunit`); integration tests use the real WordPress and database (`php bin/install-test-wp.php` then `phpunit -c phpunit-integration.xml.dist`). Every acceptance scenario in spec section 14.1 gets a test where it can be tested without a live ERP.
- Commits: small, imperative subject, body says why. The only GitHub contributor is `estasofttechnologies`.

## Definition of done for a module

`php -l` clean; `phpcs` clean (or each ignore justified); unit tests green; integration tests green where the module touches the database or WordPress; no TODO without an issue reference; public methods documented; no secret or personal data in logs.
