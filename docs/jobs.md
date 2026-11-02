# Jobs module

`includes/Jobs/**`. Background jobs and the services behind the admin screens: stock, tracking, invoices, reconciliation,
shadow mode and go-live, readiness, support report, settings export and import, notifications, capacity test, the signed
inbound route, the dashboard numbers, connections and the setup wizard state. The REST, Admin and Cli layers call these services
and nothing else from this module (the contract is `phaseB-contract.md`; the container constants are `Jobs\JobServices`).

## Service map

| Container constant (`JobServices::`) | Class (`Estasoft\SyncEngine\Jobs\...`) | Spec |
|---|---|---|
| `STOCK` | `Stock\StockService` (+ `StockStore`) | STK-1..8 |
| `TRACKING` | `Tracking\TrackingService` (+ `TrackingSettings`, `TrackingLog`) | TRK-1..3 |
| `INVOICES` | `Invoices\InvoiceService` (+ `InvoiceStatus`) | INV-1..6 |
| `RECONCILIATION` | `Reconciliation\ReconService` (+ `ReconSettings`) | REC-1..6 |
| `SHADOW` | `Shadow\ShadowService` | SHD-1..3 |
| `GO_LIVE` | `Shadow\GoLiveService` | ONB-9 |
| `READINESS` | `Readiness\ReadinessService` | ONB-3 |
| `SUPPORT_REPORT` | `Support\SupportReportBuilder` | LOG-6 |
| `PORTER` | `Porter\SettingsPorter` | SET-4, SET-5 |
| `NOTIFICATIONS` | `Notifications\NotificationService` | SET-2 |
| `CAPACITY` | `Capacity\CapacityTest` | PRF-7 |
| `INBOUND` | `Inbound\InboundHandler` | spec 7.2 |
| `DASHBOARD` | `Dashboard\DashboardService` | UI-1, UI-4, UI-11 |
| `CONNECTIONS` | `Connections\ConnectionService` | CON-1..4, CON-9, CON-10, MAP-1, ONB-5 |
| `ONBOARDING` | `Onboarding\OnboardingService` | ONB-4 |

Every service is also registered under its class name. Internal keys (`ENVIRONMENT`, `SHOP_ORDERS`, `SHOP_STOCK`, `SHOP_CATALOG`,
`SHOP_CUSTOMERS`, `GATEWAY`, `URLS`) are for tests and the module.

Errors: field errors are `Core\Support\ValidationException`; every other refusal is `Jobs\JobException` (`httpStatus()`,
`errorCode()` for catalog codes, `context()` with `test` for a failed connection test and `key_line` for SYNC-CFG-002).
`Inbound\InboundRefused` extends it and carries the status of an inbound refusal.

## Ports and adapters

WooCommerce is never called from a service. Services use interfaces in `Ports\` (`ShopOrders`, `ShopStock`, `ShopCatalog`,
`ShopCustomers`, `ShopEnvironment`, plus `Mailer` and `Transients`); the adapters in `Adapters\` are thin and use only the
WooCommerce data layer: `wc_get_orders`, `wc_get_products`, product and order objects, `update_meta_data()` with
`save_meta_data()` (never `save()` on an order), `add_order_note()`, `update_status()`. No SQL on posts, post meta or order
tables. The one place that touches `$wpdb` besides the Core repositories is `Capacity\TemporaryTableCapacityStore` (see below)
and `WooShopEnvironment::databaseCharset()` (one `SHOW TABLE STATUS`).

`Support\ConnectorGateway` is the one door from a job to a connector: connections that are active, whose connector declares the
capability; the shared `RateLimiter` before every call; every throwable caught and turned into a `ConnectorError`; classified by
the same `ErrorClassifier`, logged (redacted), and a refused sign-in pauses the connection exactly as the queue does. One
connection failing never stops the others.

## Schedules

Added through the filter `estasoft_sync_scheduled_tasks` (hook => seconds); the handlers are registered by `JobsModule`.

| Hook | Every | Does |
|---|---|---|
| `estasoft_sync_job_stock` | 300 s | `StockService::runDue()`: connections whose `interval_minutes` has passed (or whose pass is half done) |
| `estasoft_sync_job_tracking` | 600 s | `TrackingService::run()` |
| `estasoft_sync_job_invoices` | 600 s | `InvoiceService::run()` |
| `estasoft_sync_job_reconcile` | 3600 s | `ReconService::tick()` (runs once per store date after the configured hour, or carries on a run in progress), then, with what is left of one 15 second budget for the whole tick (`JobsModule::budgetLeft()`), refreshes up to 100 shadow verdicts; no budget left, no shadow reads |
| `estasoft_sync_job_digest` | 900 s | `NotificationService::tick()`: the daily list of failed orders and the backlog check |
| `estasoft_sync_recon_continue` | single event | a reconciliation run that used its time budget carries on |
| `estasoft_sync_inbound_refresh` | single event (args: connection id, entity) | a signed inbound hint was accepted: stock, tracking and/or invoices are read again |

A task that throws is caught, logged once with the exception class only, and never reaches the scheduler.

## Options and transients (all start with `estasoft_sync_`, so uninstall removes them)

| Name | Holds |
|---|---|
| `estasoft_sync_stock_last` | per connection: time of the last full pass, counts, and `next_page` plus the counts so far when a pass ran out of time |
| `estasoft_sync_stock_changes` | last 100 changes of shop quantity (newest first) |
| `estasoft_sync_stock_conflicts` | open conflicts (at most 200) and the person's accept/hold decisions (at most 500) |
| `estasoft_sync_tracking_cursor` | per connection: where reading deliveries continues from |
| `estasoft_sync_tracking_recent` | last 50 tracking numbers saved (Stock screen) |
| `estasoft_sync_signin_paused` | ids of connections paused by a refused sign-in (at most 50), so new tested credentials can resume them |
| `estasoft_sync_invoice_settings` | `window_hours` (1 to 168, default 24; filter `estasoft_sync_invoice_window_hours`) |
| `estasoft_sync_recon_settings` | `schedule_time` (02:00), `window_days` (7), `tolerance_minor` (1), `auto_repair_missing` (off), date of the last nightly run |
| `estasoft_sync_recon_progress`, `estasoft_sync_recon_system` | the run in progress and the system's answer for it; deleted when the run ends |
| `estasoft_sync_recon_lock` | the run lock: a token and when it expires (budget + 60 s); only the request that holds it advances a run |
| `estasoft_sync_shadow_verdicts` | Matches / Differs / Not found per shadow item (at most 500; refreshed after 6 hours while the window is open, final once it is closed) |
| `estasoft_sync_readiness` | last readiness result |
| `estasoft_sync_capacity` | last capacity result |
| `estasoft_sync_notifications_state` | date of the last daily list that was sent, when to try it again after a mail failure, backlog incident flag (set only after the alert was sent) |
| transient `estasoft_sync_n_paused_{id}` | "already told" flag of a pause incident (12 h, or until the connection resumes) |
| transient `estasoft_sync_failed_count` | cached count behind the menu bubble (60 s; dropped on item synced/failed, connection resumed, shadow processed) |
| transients `estasoft_sync_inb_rl_{id}_{source}_{minute}`, `estasoft_sync_inb_rt_{id}_{minute}`, `estasoft_sync_inb_seen_{hash}`, `estasoft_sync_inb_log_{status}_{id}` | inbound rate counters per source address and per connection (2 min), replay cache (10 min), and the once-a-minute cap on logging 404 and 429 refusals |

Per-connection settings that belong to the jobs (stock rules, tracking settings, the person's warehouse choices) live in the
connection's `config_json` under `jobs`, `warehouses`, so they travel with the connection and need no table. Facts found by the
connection test are kept there too: `version`, `detected_company`, `capabilities`, `contract_version`, `detected`, `tested_at`.
See "Meta keys" below for every order and product key Jobs touches.

## How shadow mode affects the jobs

The window is owned by `ShadowService` (`start`, `end`) and by `GoLiveService`. It is stored in `Core\OnboardingState`
(`shadow_started_at`; `shadow_ends_at` stays empty while the window is open). **The window never closes by itself.** Review-2 says
nothing switches at day 7; the screens get a planned end (`started + days`) computed by `OnboardingView`. `end()` and going live
are recorded with `Core\OnboardingState::endShadow()` (`shadow_ended_at`, `shadow_end_reason` of `person` or `go_live`, `mode` `live`); the old marker `answers.shadow_ended_by` is no longer written but is still read as "closed" for state saved earlier.

- Queue: new items are `mode = shadow` (Core). Nothing is sent.
- Stock and tracking: the connector is read, the changes are counted (`changed`, `conflicts`, `deliveries`), nothing is written to
  WooCommerce, no conflict is raised, no order note is added, no event is queued. Accepting a conflict during the window is refused (409).
- Invoices: read-only on orders that were really synced, so they are not held back.
- Reconciliation: read-only as always; shadow orders are not compared (they are not expected in the system).
- Shadow report: for each shadow order the connector is asked, read-only, for the record under `WC-{id}`: Matches, Differs
  (fields `total`, `currency`, `email`, total within the reconciliation tolerance) or Not found. `ShadowService::compare( $connectionId,
  $limit, $budgetSeconds = 15.0 )` stops at the limit or at the time budget (the result says `timed_out`), keeps each verdict as soon
  as the system answers, and once the window is closed treats the verdicts that exist as final: the system is not asked about them
  again (orders that never got a verdict are still compared, a few at a time). While the window is open a verdict older than 6 hours
  is checked again.
- Go live: `GoLiveService::goLive()` takes the time first. Every item that was created at or before that moment and is still queued
  or retrying is skipped with the reason "Placed before go-live" (nothing is back-filled; an order created while the call is running
  is a real order and is left alone), the moment is recorded (`answers.went_live_at`), and the window is closed (`live`, reason
  `go_live`) or opened for 7 days (`shadow`). **It is idempotent**: once the setup is complete and `went_live_at` is set, another call
  returns the state as it is, whatever mode it names, and does not touch the queue. To end a shadow window later use
  `ShadowService::end()`.

## Stock

`shop quantity = system quantity - buffer - sold-not-yet-synced - held`, never below zero. Buffer: the product's own
`_estasoft_sync_stock_buffer`, otherwise the rule (0 to 999, default 2). Sold-not-yet-synced: units in processing/on-hold orders the
system has not received (queued, syncing, retrying, failed), so a pull between a sale and its sync cannot raise the shop quantity.
All such orders are counted, 100 per page (`ShopStock::countUnsyncedSoldUnits()`); if a safety cap is reached (10,000 orders or 10
seconds) the count is flagged incomplete and that pass writes nothing: no shop quantity changes, a warning is logged, the summary
has `complete` false and `unsynced_unknown` true, and the connection is tried again after 15 minutes. A short count could set a
quantity too high.
Held (only with `subtract_held` on): units unpaid orders hold in WooCommerce plus units the system has reserved for other documents
(`fetchStock` returns `held`). AS-19: 42 in Odoo, buffer 2, 1 unit held by an unpaid order gives 39.

Matching: a product is matched when the system reports its SKU. A person's own SKU match (id map, `is_override`) is asked for under
the system's reference. Products without a SKU, excluded products and products that do not manage stock are skipped; products the
system does not know are counted as unmatched. Warehouses: only those that count toward shop stock are asked for (all of them, or
the list when some do not count); at least one must count. Stock sync is off until the person turns it on. A full pass is cursor-less
(pages of 100 SKUs, one connector call per page) but a pass that uses its 20 second budget resumes at the next page next time.

Conflicts (SYNC-STK-001): the system quantity is lower than the units sold-not-yet-synced. The product is left alone and listed.
Accept sets the shop quantity from the system quantity now, with the same sum as a normal pass: the product's own buffer when it has
one, the units the system had held back when the conflict was found (kept on the conflict as `system_held`), and the units WooCommerce
holds when `subtract_held` is on; Hold leaves it. Either decision is remembered for that system quantity,
so the product is not raised again until the quantity changes; a conflict that resolves itself (the orders synced) disappears.

## Tracking

`TrackingSettings` keeps three settings per connection: `tracking_enabled`, `delivered_becomes` and `cod_payment_on_delivery`.
`delivered_becomes` is `none` (the order status is left alone; the tracking number and the order note are still saved) or a status of the
shop that an order can be moved up to: `pending`, `on-hold`, `processing` or `completed` (the ladder in `Support\StatusRank`, and the order
only ever moves forward). Cancelled, refunded, failed, draft and custom statuses are refused with "Choose none (leave the order status
alone) or a status an order can be moved up to: ...". A delivery whose `done_at` is empty or not a time is skipped (counted in `skipped`,
one warning in the log) and the cursor does not move, so it is read again next time; an empty time is never taken as "now".

## Notifications

An "already told" flag is set only after `wp_mail` returned true: the pause flag of an incident, the backlog flag, and the date of the
daily list. A message the mail system refused is tried again (the pause alert at the next pause event, the backlog alert at the next
check, the daily list after an hour). The backlog flag is not set while `on_backlog` is off, so switching it on while the queue is
still backed up sends the alert.

## Reconciliation

Read-only comparison of paid orders in the window with the system's records (`WC-{id}`): existence, total (within `tolerance_minor`
minor units of the order currency, so 0.001 KWD and 0.01 AED), refund presence (refunded total) and cancelled status. The
customer's e-mail is not compared because the difference types in `types.ts` have no place for it.
Expected in the system: orders whose sync state is `synced`, and, after go-live, orders the plugin has not touched yet. Queued, retrying,
failed, skipped and shadow orders, and everything placed before go-live, are left out.

Bounded work: the system is read once per run; orders are compared in chunks of 100 until a 15 second budget is used; the place is
kept in `estasoft_sync_recon_progress` and the run carries on from `estasoft_sync_recon_continue` or the hourly tick. `run()` returns
the `ReconRun` with `progress: { done, total }` and status `running` until it is complete. A difference already open for the same
order and type is not stored twice.

Only one request advances a run at a time: `run()`, `continueRun()` and the tick take the run lock (`estasoft_sync_recon_lock`) and a
request that finds it taken returns the run as it stands (or, when no run exists yet, a 409 asking to wait). The lock expires, so a
request that died does not block the check for long.

Each difference carries the connection it was found in (`connection_id`) and a repair, manual or automatic, goes to that connection
and no other. No repair of any kind happens while a
shadow window is open on that connection (409 for a person; the automatic repair leaves the difference open and the next run after
the window repairs it). Copying a cancellation first checks that the order still has the status the check saw (`wc_value`);
otherwise it is refused (409) and nothing is cancelled.

Repair directions: `wc_to_system` forgets the id-map link (when the order is missing) and the state hash, then queues the order (or
its refunds) again with the repeatable event `updated`. `system_to_wc` is allowed for one thing only: a cancellation in the system
cancels the order in WooCommerce. Amounts and refunds are never copied into WooCommerce. With `auto_repair_missing` on, orders
missing in the system are queued the same way during the run (amounts and refunds always wait for a person).

## Invoices

No signing, hashing or submission code anywhere (a test greps for it). Orders synced in the last 30 days whose invoice is not final
(reported, or not required for a posted invoice) are polled, longest unchecked first, at most once in 5 minutes. A rejection fails an
item of type `invoice` (SYNC-COMP-001) in its own partition `invoice:{order}`, so it appears under Needs attention but never blocks
the order's other items; it is resolved when the invoice is reported. A rejection that does not change is noted once: the log line and
the failed item are written again only when the status, the reason or any invoice fact changes, or when the queue item is no longer
failed (a person retried it). Every synced order of the last 30 days is looked at (100 per page, up to 100 pages), not only the newest
few hundred, so an old open invoice is still polled; reading stops at half the call's time budget and the summary then says
`complete` false. `due_at` is issued + window, computed when listed, so a changed window applies at once.

The window is the only setting. `InvoiceService::settings()` returns `{ window_hours }` (default 24, or the value of the filter
`estasoft_sync_invoice_window_hours` when nothing is saved). `InvoiceService::saveWindow( int $hours )` accepts 1 to 168, stores it in
`estasoft_sync_invoice_settings` and returns `{ window_hours }` (the new value); anything else throws a `ValidationException` on
`window_hours` and changes nothing. `window()` returns the same number for code that needs only that. The REST layer exposes the setting as
`PUT /invoices/settings { window_hours }` (it calls `saveWindow()` and returns its array) and reads it with `settings()`.

## Capacity test

`Capacity\CapacityTest` never reads or writes the real queue tables and has no connection, connector or HTTP client. Items live in a
MySQL `TEMPORARY` table (`TemporaryTableCapacityStore`): visible to this database connection only, no implicit commit on create or
drop, removed by the server when the connection ends even if the request dies, random name that starts with the table prefix.
If the database user may not create one, the test falls back to memory and says so in the verdict. The real `IdempotencyKey`,
`RateLimiter` (over private counters) and `RetryPolicy` do the work; the "null connector" is a closure that accepts everything. It
stops after 8 seconds or 400 items and drops the store in `finally`.

## Inbound route

Off by default. `enable()` returns a 64-hex secret once (random_bytes) and stores it encrypted among the connection's secrets under
the reserved name `inbound_secret`; enabling again replaces it.

**Signing.** The sender computes `HMAC-SHA256( secret, "{timestamp}.{rawbody}" )` and sends it as lower-case hex in
`X-Estasoft-Signature` (an optional `sha256=` prefix is accepted). `{timestamp}` is the exact text of `X-Estasoft-Timestamp`: Unix
seconds as digits (`1793534400`), or a strict ISO 8601 time with a `T`, whole seconds (fractions allowed) and `Z` or an offset
(`2026-11-02T12:00:00Z`, `2026-11-02T16:00:00+04:00`). Relative or loose text such as `now`, `+1 hour`, `@1793534400`, a date with a
space or a date alone is not a time and is refused (401), signed or not. Then comes a dot, then the raw request body byte for byte. The timestamp is inside the
signed string, so a captured message cannot be replayed with a fresh timestamp: changing the timestamp changes the signature. A
signature of the body alone is refused.

```
X-Estasoft-Timestamp: 1793534400
X-Estasoft-Signature: hex( hmac_sha256( secret, "1793534400." + body ) )
```

**Order of checks.** Connection exists and inbound is on (404), rate limits (429, counted before anything else): 30 a minute per
connection **and source address**, and 300 a minute for the connection from all addresses together; an address that is over its own
limit does not use up the shared 300, so one noisy address cannot lock the real sender out. The address is `REMOTE_ADDR` (a forwarded
header is never trusted); `handle()` takes it as an optional fourth argument for callers that know better. The counters use
`Transients::increment()`, which is atomic when an external object cache is in use (`wp_cache_incr`) and a plain read and write
otherwise. Then: body at most 64 KB (413), timestamp present, strict and within 5 minutes of the server clock either way, then the
signature (401 for either; compared with `hash_equals`), replay of the same signature within 10 minutes (409). Every refusal is logged
as SYNC-INB-001 (info); 404 and 429 are logged at most once a minute each (404 once for all unknown connections, 429 per connection),
so a flood cannot fill the log. The body is only a hint (`entity`: stock, tracking, invoice, order); one refresh is scheduled and it re-reads
the data through the authenticated connector.

## Settings port

Schema `{ "estasoft_sync": 1, "exported_at", "plugin_version", "settings", "mappings": { connector: { type: rules } },
"connection_settings": { connector: { reliability, stock, tracking } } }`. No credentials, addresses, order data or logs. Import
rejects bad JSON, another schema version, files over 1 MB and any key it does not know. **Applying is all or nothing**: before the first
write every group is checked: the plugin settings, each connection's reliability limits, the stock rules and tracking settings
(`StockService::validateRules()`, which uses `TrackingSettings::check()` and `Ranges`) and the rules of each mapping that would
become a draft (the mapping service's `validate()`); one bad value refuses the whole file (`json` error, "Nothing was imported") and
nothing is written. Then it updates settings and limits; saves mappings as **drafts** of the connection with the same connector (a mapping equal to the live
one makes no draft; a connector that is not connected here is skipped and said so in the preview).

## Connections

Phase 1 allows one connection per connector; the check is `ConnectionService::assertFree()` and nothing else depends on it (a
disconnected connection does not count). A person may send only the connector's own non-secret fields (plus a small `options` map
of short text values) as config and its secret fields as secrets. Fixing the credentials of a connection that needs reconnecting
activates it.

**Saved secrets never follow a changed address.** `ConnectionService::test()` and `update()` fill a secret the person left empty from the
saved one only while every non-secret credential field they sent (address, database, user, company, any field of the connector) equals
the saved value. If one differs, nothing saved is reused: every secret that is required or was saved must be entered again, and the
refusal is a field error on that secret ("Enter the API key again because the address changed."). The connector is not called, so a
request cannot make the plugin send a saved key to an address the request supplied, and `update()` cannot save a new address with the
old key.

**After an encryption key change.** `test()` no longer needs the saved secrets when the request supplies all of them: it reads the
connection without secrets and uses the supplied values. `update()` of a connection whose saved secrets cannot be opened with the current
key (`secrets_readable` false) saves the new secrets with `inbound_secret` removed and `inbound_enabled` false (the repository refuses a
partial save of secrets it cannot read; the inbound secret cannot be typed again, so inbound stays off until a person enables it).
If the request does not cover the secrets, the answer is 409 saying they cannot be read. Like `create()`, `update()` stores the
credential end date the new test found (or clears it when the test finds none).

**Refused sign-in.** When credentials (any config field or secret) change on a connection that needs reconnecting, or that is paused
because the system refused the sign-in (`PauseController::pauseForRefusedSignIn`, announced as
`estasoft_sync_connection_paused( id, 'sign_in' )`), `ConnectionService::update()` tests the new credentials through the connector
first. If the test passes, they are saved, `PauseController::resume()` makes the connection active (action
`estasoft_sync_connection_resumed( id, 'person' )`) and the log says "New credentials for X passed the test. Syncing resumed." If the
test fails, nothing is saved: the old credentials stay, the connection stays paused and the `JobException` carries the test result
(the same refusal as on create). A pause a person asked for, a timed pause and the circuit breaker's pause are never lifted by
new credentials, and changing only the label or settings never resumes anything.

Core's public shape reports every pause that is not the circuit breaker's as `person`, so Jobs remembers sign-in pauses itself:
`Connections\SignInPauses` keeps the ids in the option `estasoft_sync_signin_paused` (at most 50), marks one on
`estasoft_sync_connection_paused( id, 'sign_in' )` and forgets it on any other pause reason or on resume. Both hooks are attached by
`JobsModule` at priority 5.

## Meta keys

All keys are written through the WooCommerce CRUD objects (`update_meta_data()` then `save_meta_data()`; orders are never saved
whole) and every one is in `Core\MetaKeys` (orders and products) and in the `estasoft_sync_meta_keys` filter callback
`JobsModule::addMetaKeys()` (constants `ORDER_KEYS_WRITTEN`, `PRODUCT_KEYS_OWNED`). `tests/php/Unit/Jobs/MetaKeysAuditTest.php` scans
`includes/Jobs` for `_estasoft_sync_*` strings and fails if one is missing from `Core\MetaKeys`.

| Object | Key | Jobs |
|---|---|---|
| order | `_estasoft_sync_tracking` | writes (JSON list of shipments) |
| order | `_estasoft_sync_tracking_number`, `_estasoft_sync_tracking_carrier` | writes (flat copy of the latest shipment) |
| order | `_estasoft_sync_invoice` | writes (JSON invoice facts) |
| order | `_estasoft_sync_invoice_number`, `_estasoft_sync_invoice_status`, `_estasoft_sync_invoice_pdf` | writes (flat copy for the order box) |
| order | `_estasoft_sync_state` | reads only (the Woo module writes it) |
| product | `_estasoft_sync_last_stock`, `_estasoft_sync_last_stock_at` | writes (last quantity the system reported, UTC time) |
| product | `_estasoft_sync_exclude`, `_estasoft_sync_stock_buffer` | reads only (a person sets them) |
| user | none | Jobs writes no user meta (the customer port reads WooCommerce customers only) |
