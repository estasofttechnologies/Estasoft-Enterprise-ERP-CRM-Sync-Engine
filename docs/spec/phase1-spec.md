# ESTASOFT SYNC: Phase 1 development specification

> Copy of the project document `estasoft-erp-sync-engine-phase1-spec.md`. Where `review-2-changes.md`, `scope-update.md` or `connection-methods-spec.md` disagree with this file, they win (see `README.md` in this folder). Decisions taken since this draft: the admin is a React app (open question 1 answered "React"); there is no relay and no outside server (open question 2).

Product: Estasoft Enterprise ERP/CRM Sync Engine (label inside WordPress: ESTASOFT SYNC). Draft 1, written 2 Nov 2026 from `docs/DECISIONS.md` and `docs/design/uiux-status.md`, with no outside sources opened for this document. Anything marked **Assumed** is a proposal that the owner or the first developer must confirm. Anything marked **Verify** names a fact about WordPress, WooCommerce or a third-party system that was not checked.

How to use: feature IDs (QUE-6 and so on) are stable; change the text, never the number. Each feature has acceptance criteria in Given, When, Then form and the milestone (M1 to M6, section 13) that delivers it. Screens are the board IDs on the UI/UX canvas (section 12). Names, prefixes and tables come from the decisions doc.

## 1. Purpose, scope and non-goals

**Purpose.** A free WordPress plugin that keeps a WooCommerce store in step with an ERP, accounting system or CRM through a durable queue, so checkout never waits for an external system and no order is lost when the system is slow or down.

**In scope (Phase 1).**
- The engine: outbox, queue, workers, ordering, idempotency, retries, circuit breaker, rate limit, failed items, reconciliation, shadow mode, logs, settings, notifications.
- One official connector: Odoo (status Included).
- The connector contract, so other connectors can be built on the same engine.
- Every admin screen on the canvas, including those whose options appear only for accounting or CRM connectors (they are driven by capability flags and ship working once such a connector exists).
- WordPress.org distribution assets (icon and banner).

**Non-goals.**
- No licenses, license keys, tiers, trials, paid version, upsell, telemetry or hosted relay. All features are free (decided 2 Nov 2026).
- The plugin never creates, signs, stamps, hashes or submits tax invoices. The ERP or an accredited provider does. The plugin sends the fields an invoice needs and reads back the result.
- No visual drag-and-drop mapper (later).
- No right-to-left interface and no languages other than English shipped (strings stay translatable).
- No inventory write-back from WooCommerce to the system. The system is the source of truth for stock.
- No payment tokens or card data leave WooCommerce (send transaction ID and last four digits only).
- No other system's data model is promised beyond the capability table (S04), which is intended scope, not confirmed API behaviour.

**Distribution.** WordPress.org plus a public GitHub repository, GPLv2 or later, updates only through WordPress.org, no calls to any server except the connected system and only after the user configures it.

## 2. Requirements

| Item | Value | State |
| --- | --- | --- |
| WordPress | 6.4 or newer | Proposed in the decisions doc |
| WooCommerce | 8.2 or newer, HPOS first | Proposed |
| PHP | 8.1 or newer | Proposed |
| HPOS | Declare compatibility with custom order tables; work with legacy post storage too | Required |
| Block checkout | Declare compatibility with cart and checkout blocks; hook the Store API checkout event as well as the classic one | Assumed, Verify hook names at M1 |
| Database | MySQL or MariaDB with InnoDB and utf8mb4 | Assumed |
| Odoo | Versions 17, 18 and 19 (Community and Enterprise) over the external JSON-RPC or XML-RPC API with a dedicated user and API key | Assumed, Verify per version at M1 |
| PHP extensions | sodium (bundled with PHP 7.2 and later), json, mbstring | Assumed |
| Permalinks | Pretty permalinks on (REST routes and the sign-in callback need them) | Assumed |

If WooCommerce is missing or the PHP version is too low, the plugin loads nothing except an admin notice (ONB-1).

## 3. Phases and connector scope

| Phase | Connectors | Notes |
| --- | --- | --- |
| Phase 1 | **Odoo** (Included) | Engine, contract, all screens, WordPress.org release |
| Phase 2 | Microsoft Dynamics 365 Business Central, Zoho Books, ERPNext, QuickBooks Online, Xero, HubSpot, Zoho CRM (7, Coming next) | First sign-in (OAuth) connectors and the first accounting and CRM types. Screens B03b, B04b, B04c, E03, E05 work for them |
| Phase 3 | NetSuite, SAP Business One, Salesforce, MYOB, Sage Business Cloud Accounting and Sage 50, Dynamics 365 Sales (6, Planned) | Official, built after Phase 2 |
| Community | 20 systems (17 accounting or ERP or CRM listed in D03 as Wanted, plus the rest of the 34-system catalog) | Built by anyone through the contract. Catalog statuses are proposals awaiting owner confirmation |

Catalog data (name, type, regions, status) ships as a static file in the plugin and is filterable (`estasoft_sync_catalog`). It is never fetched from the network.

## 4. Features and acceptance criteria

Conventions: "the person" is a user with the needed capability (section 7.1). "The system" is the connected ERP, accounting system or CRM. Times are shown in store time and stored in UTC.

### 4.1 ONB: setup wizard and readiness

| ID | Feature | Acceptance | M |
| --- | --- | --- | --- |
| ONB-1 | Requirements guard | Given WooCommerce is inactive or PHP is below 8.1, when the plugin is active, then nothing else loads, no fatal error occurs, and a notice names the missing requirement | M1 |
| ONB-2 | Welcome redirect | Given a single-site activation by an administrator, when activation finishes, then the browser opens the setup guide once; bulk activation, network activation and WP-CLI activation do not redirect | M5 |
| ONB-3 | Readiness check | Given the store, when the checks run, then ten checks report Passed, Worth fixing or Needs fixing with a link to the cause: HPOS compatible, background tasks running, scheduled tasks on visits versus real cron, duplicate SKUs, products without a SKU, customers with a free-text state, currency decimals match (after a connection exists), utf8mb4 storage, encryption key in wp-config.php, PHP 8.1 or newer. Worth fixing never blocks setup | M5 |
| ONB-4 | Resumable wizard | Given the person leaves setup at step 3, when they return, then the wizard opens at step 3 with saved answers; nothing is sent to any system before step 5 | M5 |
| ONB-5 | Choose a system | Given the catalog, when the person filters (All, ERP, Accounting, CRM, Gulf) or types in search, then the 34 systems filter live with status words Included, Coming next, Planned, Community; the suggestion row follows the store's base country (Assumed table, for example AE suggests Odoo, Zoho Books, Xero) | M5 |
| ONB-6 | Connect step | Given a chosen system, when the person continues, then the connect screen for its auth style opens (address and key, or sign-in page) | M2 |
| ONB-7 | What to sync, by type | Given a connector's capability flags, when the screen opens, then only supported options show; unsupported ones are replaced by a "Not offered" card that says why | M2 |
| ONB-8 | Preview an order | Given a chosen order, when the person previews, then MAP-4 runs and shows source and target with changed lines highlighted | M2 |
| ONB-9 | Go live | Given setup is complete, when the person starts, then shadow mode begins for 7 days by default (SHD-1) or live starts immediately; orders already in the queue before going live are not sent | M4 |

### 4.2 CON: connections

| ID | Feature | Acceptance | M |
| --- | --- | --- | --- |
| CON-1 | Add a connection by address and key (Odoo) | Given address, database, user and API key, when the person presses Test connection, then success saves the encrypted connection; failure saves nothing and shows the matching error (SYNC-AUTH-001 or SYNC-NET-001) | M1 |
| CON-2 | Encrypted secrets | Given the constant ESTASOFT_SYNC_KEY in wp-config.php, when a secret is saved, then it is stored as a libsodium secretbox (random nonce, key from the constant) in the connections table, never in options or logs, and never sent back to the browser after saving | M1 |
| CON-3 | Missing key | Given the constant is absent, when the person opens Connections, then a notice shows a freshly generated line to paste into wp-config.php, and saving credentials is refused with SYNC-CFG-002 | M1 |
| CON-4 | Test connection | Given a saved connection, when tested, then the connector returns the system version, company, warehouses and a permission check list within 30 seconds; any missing permission is listed by name | M1 |
| CON-5 | Sign-in connection (OAuth 2) | Given a sign-in connector (Phase 2), when the person presses Sign in, then the browser goes to the provider with a one-time `state` bound to the user (valid 10 minutes), PKCE where the provider supports it, minimal scopes; the callback stores encrypted tokens and shows the organisation to pick | M2 (contract), Phase 2 (connectors) |
| CON-6 | Token refresh and Reconnect | Given a stored refresh token, when an access token is within 5 minutes of expiry, then a scheduled job refreshes it; if refresh fails the connection becomes Needs reconnect, items wait, SYNC-AUTH-002 is logged and the person is notified | Phase 2 |
| CON-7 | Pause and resume a connection | Given an active connection, when the person pauses for 1 hour or until resumed, then workers stop claiming its items, items stay Queued, and the dashboard shows Paused; resume or the timer restores syncing | M3 |
| CON-8 | Reliability settings | Given a connection, when the person edits retries, jitter, circuit-breaker threshold and probe interval, speed limit, Retry-After obedience and concurrency, then the new values apply to items claimed after saving; out-of-range values are rejected with a field message | M3 |
| CON-9 | Disconnect | Given a connection, when the person confirms Disconnect, then secrets are wiped, queued items are kept 30 days, mapping versions are kept, and nothing in the system or WooCommerce is deleted | M3 |
| CON-10 | More than one connection | Given the data model is multi-connection, when a second connection of a different connector is added, then each has its own mapping, limits and pause switch. Phase 1 UI allows one connection per connector | M3 |
| CON-11 | Connection health | Given a connection, when workers run, then last success, last failure, typical response time and failures in a row are updated and shown | M3 |
| CON-12 | Reconnect and problems screen | Given a connection error (invalid credentials, expired sign-in, rate limited, system version changed), when shown, then the screen names the problem, the fix and the button to fix it (board D04) | M5 |

### 4.3 QUE: queue and delivery

| ID | Feature | Acceptance | M |
| --- | --- | --- | --- |
| QUE-1 | Outbox enqueue | Given an order event (placed, paid, status change, refund), when the hook fires, then one row is inserted in the queue table in the same database transaction as the order save, with no network call in the request; if the insert fails the failure is logged and the order is never blocked | M1 |
| QUE-2 | Latest data wins | Given an item waiting while the order changes, when a worker sends it, then the payload is built at send time from the current order and the live mapping, and SYNC-STATE-001 is noted | M1 |
| QUE-3 | Workers (standard profile) | Given Action Scheduler group estasoft-sync, when the recurring runner fires every minute, then it claims due items up to the concurrency limit, each claim an atomic update that sets a lock for 60 seconds (Assumed), and stops after 20 seconds of work (Assumed) so overlapping runs are safe | M1 |
| QUE-4 | Ordering per order | Given items for the same partition key (`order:{id}`), when a worker claims, then an item is claimed only if no earlier item for that partition is unfinished or Failed; a later item shows "Waiting for an earlier step of this order" | M1 |
| QUE-5 | Idempotency | Given a retry after a crash or timeout, when the connector asks `find_existing`, then it finds the system's record by the client reference `WC-{order id}` and links it (SYNC-DUP-001) instead of creating a second one | M1 |
| QUE-6 | Retries, backoff, jitter, Retry-After | Given a retryable error, when an item fails, then it becomes Retrying with delays 1 minute, 5 minutes, 1 hour, 24 hours (4 retries, 5 tries in all) plus up to 20 seconds of random jitter; a Retry-After value longer than the schedule wins (capped at 24 hours); after the last retry the item is Failed | M3 |
| QUE-7 | Error classification | Given any failure, when classified, then the class is retry, needs a person, handled or info according to the error catalog (section 11); timeouts, connection errors, 5xx and 429 are retry; authentication, validation, mapping and configuration errors are needs a person; the connector may override through `classify_error` | M3 |
| QUE-8 | Circuit breaker | Given 5 retryable failures in a row on a connection (Assumed that 429 does not count), when the fifth fails, then the connection becomes Paused (circuit open), workers stop claiming, a probe runs every 2 minutes, and the first successful probe resumes it | M3 |
| QUE-9 | Rate limit and concurrency | Given defaults of 4 requests per second and 2 items at once per connection and a global cap of 2 (Assumed), when workers run, then a token bucket enforces the rate and the lower of the two concurrency limits applies | M3 |
| QUE-10 | Failed items (dead letter) | Given a needs-a-person error or exhausted retries, when the item fails, then it stops, keeps its error code and message, appears in Needs attention grouped by code and cause, and is never retried automatically | M3 |
| QUE-11 | Retry one or many | Given Failed or Retrying items, when the person presses Retry, then the items return to Queued with attempts reset, same idempotency key, and are sent oldest first; Retry selected and Retry the group work in bulk (section 7: POST /queue/retry) | M3 |
| QUE-12 | Skip and restore | Given a non-terminal item, when the person skips it with a reason, then it is never sent, stays in the log, and can be restored to Queued | M3 |
| QUE-13 | Pause all, resume, sync now | Given the dashboard, when the person presses Pause syncing or Sync now, then workers stop (items wait) or run immediately with no change to the schedule | M3 |
| QUE-14 | Stale lock reaper | Given a worker that died, when a lock has expired, then a job (every minute) returns the item to Queued without counting a try and logs SYNC-LOCK-001 | M1 |
| QUE-15 | Queue screen | Given the queue, when the person filters by state, type or day, searches by order number, SKU or email, selects rows and pages through, then results come from indexed server-side queries (25, 50 or 100 per page) and bulk actions apply to the selection | M3 |
| QUE-16 | Backlog warning | Given more than 200 items waiting (setting), when the threshold is crossed, then a notice shows and, if enabled, an email is sent once per incident | M5 |

### 4.4 MAP: mapping engine

| ID | Feature | Acceptance | M |
| --- | --- | --- | --- |
| MAP-1 | Mapping types and presets | Given a connection, when it is created, then default mapping versions exist for orders, customers, products, refunds, stock and tracking from the connector's `default_mapping`, each as a live Version 1 | M2 |
| MAP-2 | Rule model | Given a field, when saved, then it holds source, ordered transforms and target; transforms are chosen from a fixed library, never code: add prefix or suffix, trim, change case, international phone format with a default country, country or state to ISO code, money to the currency's decimals, date to UTC, value map, if-then on a field, default value, required with error code, join and split, tax lookup, SKU match | M2 |
| MAP-3 | Edit a field rule | Given the rule dialog, when the person saves, then the rule validates (target exists in the connector's field list, transforms fit the source type) and errors show next to the field | M2 |
| MAP-4 | Preview with a real order | Given an order and a draft or live mapping, when previewed, then the payload is built and shown beside the order with changed lines highlighted and no request is sent; optional read-only lookups (customer found, SKU exists) are clearly labelled | M2 |
| MAP-5 | Publish a version | Given a draft, when published, then a change list is shown first, a new live version is created, the old one is retired, and items sent afterwards use it | M2 |
| MAP-6 | Roll back | Given an older version, when the person rolls back, then a new live version is created as a copy of it (history stays linear) | M2 |
| MAP-7 | Tax mapping | Given country, WooCommerce tax and rate, when mapped to a system tax code, then an order with an unmapped rate fails with SYNC-MAP-002 before sending; buyer tax number rules per country are configurable (default: Saudi Arabia business buyers need 15 digits) | M2 |
| MAP-8 | Product SKU matching | Given products, when matching by SKU against the system's internal reference, then unmatched and SKU-less products are listed, and the person can map one SKU to a chosen system product; the override is stored in the id map | M2 |
| MAP-9 | Customers | Given a customer, when sent, then the system contact is matched by email (case-insensitive) and created if missing (setting); names are sent as typed (Arabic stays Arabic); phone is formatted by MAP-2 | M2 |
| MAP-10 | Refunds | Given a refund, when it occurs, then a credit note (ERP or accounting) or a deal note (CRM) is created and linked to the original | M4 |
| MAP-11 | Country defaults | Given a country with no postcode or state, when a required target field would be empty, then a per-country default or an error with SYNC-VAL-002 is used according to the mapping | M2 |
| MAP-12 | Money | Given any amount, when mapped, then it is carried as integer minor units with the currency's exponent (KWD, BHD, OMR have three decimals) and never as a float | M2 |

### 4.5 STK, TRK: stock and tracking in

| ID | Feature | Acceptance | M |
| --- | --- | --- | --- |
| STK-1 | Pull stock | Given matched products, when the stock job runs every 5 minutes (Assumed) or an inbound hint arrives, then quantities are read per warehouse and WooCommerce stock is set through the WooCommerce CRUD layer only for products whose value changed | M4 |
| STK-2 | Source of truth | Given stock sync is on, when quantities differ, then the system wins; WooCommerce never writes stock to the system | M4 |
| STK-3 | Safety buffer | Given a buffer of 2, when the system has 42, then WooCommerce shows 40; never below 0; per-product override is possible (Assumed) | M4 |
| STK-4 | Held stock | Given unpaid orders hold units, when the option is on, then the shop quantity never exceeds the system quantity minus held units | M4 |
| STK-5 | Warehouses | Given warehouses found by the connector, when the person marks which count toward shop stock and which serve which country, then shop stock is the sum of the counting warehouses and order routing uses the country rule. WooCommerce holds one quantity per product, so per-warehouse stock is not shown in the shop (Assumed) | M4 |
| STK-6 | Conflicts | Given the system quantity is lower than units already sold in unsynced orders, when detected, then a conflict is listed (SYNC-STK-001) with Accept the system quantity or Hold; nothing changes until chosen | M4 |
| STK-7 | Unmatched products | Given products with no match, when stock runs, then they are skipped and counted | M4 |
| STK-8 | Recent changes | Given runs, when listed, then SKU, system quantity, shop before and after, and time show for the last 100 changes | M4 |
| TRK-1 | Delivery status in | Given shipment or delivery records, when the job runs every 10 minutes (Assumed) or an inbound hint arrives, then the matching order moves forward by the configured rule (default delivered becomes Completed) and never backward | M4 |
| TRK-2 | Tracking numbers | Given a carrier and number, when received, then they are saved on the order, added as an order note, and the action `estasoft_sync_tracking_saved` fires for tracking plugins | M4 |
| TRK-3 | Cash on delivery payment | Given a cash-on-delivery order marked delivered, when it completes, then a "payment collected" event is queued to the system | M4 |

### 4.6 REC, SHD, INV

| ID | Feature | Acceptance | M |
| --- | --- | --- | --- |
| REC-1 | Nightly check | Given 02:00 store time (setting) and a window of 7 days, when it runs, then orders are compared read-only with the system by reference for existence, total, refund presence and customer email | M3 |
| REC-2 | Tolerance | Given currency exponents, when totals are compared, then differences up to one minor unit (0.01 AED, 0.001 KWD) count as equal; the limit is editable | M3 |
| REC-3 | Differences | Given a run, when it finds problems, then each is stored with type (missing in system, total differs, refund missing, status differs) and shown with both values | M3 |
| REC-4 | Repair or ignore | Given a difference, when the person chooses a direction, then the change is queued and logged with who made it; Ignore stores a reason | M3 |
| REC-5 | Automatic repair | Given the setting, when a run finds orders missing in the system, then they are queued automatically; amounts and refunds always wait for a person | M3 |
| REC-6 | Run now | Given a window choice, when started, then progress shows and the result replaces the last run | M3 |
| SHD-1 | Shadow mode | Given shadow mode, when an order event occurs, then the item is built with the live mapping, stored (redacted) with mode shadow, and nothing is sent | M4 |
| SHD-2 | Shadow report | Given shadow items, when the nightly or manual compare runs, then each is matched to the system's own record for the same reference and marked Matches, Differs (with fields) or Not found; the report summarises counts and lists differences | M4 |
| SHD-3 | End shadow | Given day 7 or an early end, when the person goes live, then only new orders are sent; shadow items are not back-filled | M4 |
| INV-1 | Invoice read-back | Given a synced order in a system that issues invoices, when polled every 10 minutes while open (Assumed) or hinted, then invoice number, status, PDF link, QR payload and tax-authority status are stored on the order and in the state table | M4 |
| INV-2 | Invoices screen | Given invoices, when listed, then statuses Waiting to be reported, Rejected, Reported and Not required show with time left, filterable | M4 |
| INV-3 | 24-hour clock | Given a Saudi consumer invoice, when issued by the system, then due time is issue time plus 24 hours (window editable, Assumed rule, Verify with the authority); time left is neutral above 12 hours, warning from 3 to 12 hours, danger under 3 hours | M4 |
| INV-4 | Rejection | Given a tax-authority rejection, when read back, then an item fails with SYNC-COMP-001 and shows in Needs attention with the reason | M4 |
| INV-5 | Never signs | Given any invoice, when handled, then the plugin sends buyer tax number, tax category and exemption reason only, and contains no signing, hashing or submission code | M4 |
| INV-6 | Invoice on the order | Given a stored invoice, when the order screen opens, then number, status and link show; the QR payload is stored and exposed through a filter, and shown as an image only when the connector supplies one (Assumed) | M4 |

### 4.7 UI: WordPress integration

| ID | Feature | Acceptance | M |
| --- | --- | --- | --- |
| UI-1 | Admin menu | Given an authorised user, when the admin loads, then WooCommerce shows ESTASOFT SYNC with a bubble equal to the number of Failed items (cached 60 seconds) | M5 |
| UI-2 | App bar and sections | Given any plugin screen, when viewed at 1360 or 782 or 390 px, then nine tabs show (Dashboard, Queue, Invoices, Connections, Mapping, Stock, Reconciliation, Logs, Settings) or collapse into a sections menu at 782 px and below | M5 |
| UI-3 | Admin bar node | Given a user with view access, when any admin page loads, then "ESTASOFT SYNC: n waiting" shows with a coloured dot and the word (ok, waiting, paused); it links to the queue and can be hidden in settings | M5 |
| UI-4 | Dashboard and widget | Given the dashboard, when it loads, then synced today, waiting, needs attention and oldest waiting show with the queue by state, hourly chart, latest events and connection card; the WordPress dashboard widget shows synced, waiting and failed | M5 |
| UI-5 | Order screen box | Given an order (HPOS or legacy screen), when opened, then a box shows state, system number link, invoice, tax-authority status, last sync, mapping version, Sync again and View log; changing the order sends an update | M5 |
| UI-6 | Orders list column and bulk actions | Given the WooCommerce orders list, when displayed, then a Sync column shows the state chip, a state filter exists, and bulk actions Sync again and Skip work on the selection | M5 |
| UI-7 | Product box | Given a product, when edited, then Exclude from sync, the system product link and last stock time show; the products list can filter "Not matched" | M5 |
| UI-8 | Screen Options and Help | Given a list screen, when Screen Options or Help opens, then columns and rows per page persist per user and each screen has a Help tab with what it shows and how to fix common problems | M5 |
| UI-9 | Admin notices | Given conditions, when true, then notices show: welcome after activation, requirements missing, encryption key missing, connection needs reconnect, queue backlog; each says what to do; only the welcome notice is dismissible permanently | M5 |
| UI-10 | Plugins screen links | Given the plugins list, when it renders, then Settings and Setup guide links show under the plugin name | M5 |
| UI-11 | Dashboard states | Given conditions, when true, then first run, loading, normal, Odoo unreachable (paused) and shadow mode each show their own layout | M5 |
| UI-12 | Responsive and touch | Given widths 1360, 782 and 390 px, when tables render, then they become cards at 782 px and below, controls are 44 px high, dialogs rise from the bottom at 520 px and below, nothing scrolls sideways except designed table scrolling | M5 |
| UI-13 | Implementation approach | Assumed: React admin app built with WordPress scripts for Queue, Logs and Mapping (live updates, filtering); PHP-rendered pages for Settings and wizard. Open question 1 (answered: React for the whole admin) | M1 |

### 4.8 LOG, SET

| ID | Feature | Acceptance | M |
| --- | --- | --- | --- |
| LOG-1 | Log rules | Given an event, when written, then it has level (Info, Warning, Error), connection, item, code, message (500 characters at most), duration, HTTP status and redacted excerpts capped at 2 KB (setting: 2 KB, 8 KB, none) | M1 |
| LOG-2 | Redaction | Given payloads and messages, when stored, then names, emails, phones, addresses and tax numbers are removed using field paths from the mapping plus a pattern fallback; authorization headers and secrets are never logged; turning redaction off is itself logged | M1 |
| LOG-3 | Retention | Given 14 days (7 or 30 selectable), when the daily job runs, then older rows are deleted in batches | M1 |
| LOG-4 | Logs screen | Given the log, when filtered by level, search or connection and downloaded, then results are redacted and the CSV matches the screen | M3 |
| LOG-5 | Entry detail | Given an entry, when opened, then all fields, the excerpts and a link to the queue item show | M3 |
| LOG-6 | Support report | Given the report dialog, when opened, then versions, runtime, connection summary, queue counts, readiness summary and the last 20 errors show with order numbers masked (****) unless the person ticks them; copy and download work; nothing is sent anywhere | M5 |
| SET-1 | General settings | Given the settings, when saved, then time display, log retention, redaction, payload size and delete-on-uninstall validate and persist; there is no usage-data option because Phase 1 ships no telemetry code | M5 |
| SET-2 | Notifications | Given recipients (default the admin email) and triggers, when events occur, then wp_mail sends: connection paused (once per incident), a daily digest of failed orders at 08:00 store time when any exist, backlog over the threshold, sign-in expired; no external service is used | M5 |
| SET-3 | Access | Given roles, when capabilities are granted on activation, then administrator and shop manager can view and operate (retry, skip, pause), and only administrator can change connections, mapping and settings (capability model section 7.1) | M5 |
| SET-4 | Export settings | Given the tool, when exported, then a versioned JSON of mappings, rules, limits and notification settings downloads, with no credentials, no order data and no logs | M5 |
| SET-5 | Import settings | Given a file, when chosen, then a preview shows what will change; applying creates draft mapping versions (never live) and updates settings only after confirmation | M5 |
| SET-6 | Clear logs and delete all data | Given the tools, when the person confirms by typing the word shown, then logs clear, or all plugin tables, options and meta are removed; WooCommerce orders and the system are untouched; on uninstall data is removed only if delete-on-uninstall is on | M5 |

### 4.9 CMP, PRF, A11Y, I18N

| ID | Feature | Acceptance | M |
| --- | --- | --- | --- |
| CMP-1 | Connector registry | Given the filter `estasoft_sync_register_connectors`, when plugins load, then core registers Odoo and any installed community connector by id, with its manifest | M1 |
| CMP-2 | Contract version | Given a connector manifest, when its major contract version differs from the core's, then it is listed but not activated, with a notice | M2 |
| CMP-3 | Capability flags | Given flags (orders, customers, refunds, stock_in, tracking_in, prices_in, invoice_readback, reconciliation, webhooks, shadow_lookup), when a screen renders, then only flagged features show | M2 |
| CMP-4 | Community connectors page | Given installed community connectors, when listed, then each shows name, version, contract version and Community label; the Wanted list comes from the catalog; the guide link goes to the project's documentation | M5 |
| CMP-5 | Contract test suite | Given the suite package (Assumed name `estasoft/sync-connector-tests`), when a connector author runs it, then it checks section 10's rules and prints pass or fail per rule | M6 |
| CMP-6 | Isolation | Given a connector throws or hangs, when an item runs, then the exception is caught, the item is classified, other connections keep running, and a hard timeout of 30 seconds applies | M1 |
| PRF-1 | Runtime profiles | Given Standard or High volume, when set, then Standard uses Action Scheduler and High volume expects a running WP-CLI worker and falls back to Standard with a notice if none runs within 5 minutes (Assumed) | M3 |
| PRF-2 | CLI worker | Given `wp estasoft-sync worker --concurrency=2`, when running, then it claims items like QUE-3, stops cleanly on SIGTERM, and exits after a set number of items or high memory so a supervisor restarts it (Assumed) | M3 |
| PRF-3 | Real cron guidance | Given scheduled tasks run on visits, when the readiness check warns, then the settings screen shows a crontab line (Assumed `wp action-scheduler run --group=estasoft-sync`, Verify) | M5 |
| PRF-4 | Optional object cache | Given a persistent object cache, when present, then rate-limit tokens and locks use it; otherwise the database | M3 |
| PRF-5 | Checkout overhead | Given the enqueue, when measured with the published k6 method, then the extra time and database writes per checkout are reported; no figure is promised before it is measured | M6 |
| PRF-6 | Action Scheduler hygiene | Given completed actions in group estasoft-sync, when older than 7 days (Assumed), then they are purged in batches | M3 |
| PRF-7 | Capacity test | Given the settings, when the person runs a capacity test, then synthetic items go through a null connector that sends nothing and the screen reports items per minute on this server | M5 |
| A11Y-1 | WCAG 2.1 AA | Given every screen, when tested with axe, keyboard and a screen reader, then there are no AA violations; controls are real elements with labels; focus is visible; dialogs trap and return focus and close with Esc; status is never colour alone; queue updates use a polite live region; reduced motion is respected; 200 percent zoom loses nothing | M5 |
| I18N-1 | Translatable strings | Given any user-facing text, when written, then it uses `__()` or `_n()` with the text domain `estasoft-erp-crm-sync-engine`, with placeholders instead of concatenated sentences; only English is shipped | M1 |
| I18N-2 | Scripts and storage | Given Arabic or other scripts in names and addresses, when stored and sent, then tables use utf8mb4, text is never transliterated unless a rule enables it, and displayed values use `dir="auto"` | M1 |
| I18N-3 | Time and numbers | Given any time or amount, when stored, then times are UTC and amounts use minor units; display uses store time and WooCommerce formatting | M1 |
| I18N-4 | Right-to-left | Not in scope. Assumed: layouts use logical CSS properties so a right-to-left WordPress site does not break | M5 |

## 5. Data model

Table prefix is `$wpdb->prefix`; charset and collation from `$wpdb->get_charset_collate()`; created and upgraded with `dbDelta`; schema version in option `estasoft_sync_db_version`. The decisions doc names 7 tables; section 5.8 adds 2 (Assumed). No raw SQL touches WooCommerce order tables; orders are read and written through the WooCommerce CRUD layer (`wc_get_order`, `wc_get_orders`, `$order->update_meta_data`).

### 5.1 estasoft_sync_connections

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK auto | |
| connector | varchar(40) | connector id, for example odoo |
| type | varchar(10) | erp, accounting, crm |
| label | varchar(120) | |
| status | varchar(20) | active, paused, paused_circuit, needs_reconnect, disconnected |
| paused_until | datetime null | UTC |
| auth_style | varchar(10) | key or oauth |
| config_json | longtext | non-secret: address, database, company, warehouses, options |
| secret_blob | longblob null | sodium secretbox: nonce followed by ciphertext |
| key_fingerprint | varchar(16) null | first bytes of a hash of the key, to detect a changed key |
| token_expires_at | datetime null | OAuth |
| settings_json | longtext | retries, jitter, breaker, rate, concurrency |
| breaker_failures | smallint | |
| breaker_next_probe_at | datetime null | |
| last_success_at, last_failure_at | datetime null | |
| created_at, updated_at | datetime | |

Indexes: (connector), (status).

### 5.2 estasoft_sync_queue (also the outbox; Failed is a state)

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK auto | |
| connection_id | bigint unsigned | |
| entity_type | varchar(20) | order, refund, customer, stock, tracking, invoice |
| entity_id | varchar(64) | WooCommerce id |
| event | varchar(30) | created, paid, updated, refunded, cancelled, delivered |
| partition_key | varchar(80) | for example order:10490 |
| idempotency_key | char(64) | sha256 hex, section 6.3 |
| priority | tinyint | default 5 |
| state | varchar(10) | queued, syncing, synced, retrying, failed, skipped |
| mode | varchar(6) | live or shadow |
| attempt | tinyint | tries made |
| max_attempts | tinyint | default 5 |
| next_attempt_at | datetime null | UTC |
| locked_by | varchar(40) null | worker id |
| locked_until | datetime null | |
| mapping_version | int null | version used at the last send |
| error_code | varchar(20) null | SYNC-... |
| error_message | varchar(500) null | redacted |
| external_id, external_ref | varchar(80) null | the system's id and human number |
| skip_reason | varchar(120) null | |
| created_at, updated_at, finished_at | datetime | |

Indexes: (state, next_attempt_at, priority), (connection_id, state), (entity_type, entity_id), (partition_key, id), UNIQUE (connection_id, idempotency_key). Retention (Assumed): Synced rows 30 days after finishing; Skipped 90 days; Failed kept until resolved plus 90 days. `skipped` is a terminal queue state shown as a neutral tag; it is not a seventh sync-state chip.

### 5.3 estasoft_sync_logs

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK auto | |
| ts | datetime(3) | UTC |
| level | varchar(7) | info, warning, error |
| connection_id | bigint unsigned null | |
| queue_id | bigint unsigned null | |
| entity_ref | varchar(64) null | order number |
| code | varchar(20) null | |
| message | varchar(500) | redacted |
| request_excerpt, response_excerpt | text null | redacted, capped |
| http_status | smallint null | |
| duration_ms | int null | |

Indexes: (ts), (level, ts), (queue_id), (connection_id, ts). Retention: 14 days default, daily batch delete.

### 5.4 estasoft_sync_mappings

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK auto | |
| connection_id | bigint unsigned | |
| type | varchar(20) | orders, customers, products, refunds, stock, tracking, tax |
| version | int | per connection and type |
| status | varchar(8) | draft, live, retired |
| rules_json | longtext | rule list, section 4.4 |
| note | varchar(255) null | |
| created_by | bigint unsigned | user id |
| created_at, published_at | datetime | |

Indexes: UNIQUE (connection_id, type, version), (connection_id, type, status). Exactly one live version per connection and type, enforced in code and by a transaction on publish. Retention: all versions kept.

### 5.5 estasoft_sync_id_map

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK auto | |
| connection_id | bigint unsigned | |
| entity_type | varchar(20) | order, customer, product, refund, invoice, warehouse |
| wc_id | varchar(64) | |
| external_id | varchar(80) | |
| external_ref | varchar(120) null | human number such as S00490 |
| external_url | varchar(255) null | |
| is_override | tinyint(1) | 1 for a person's SKU mapping |
| created_at, updated_at | datetime | |

Indexes: UNIQUE (connection_id, entity_type, wc_id), (connection_id, entity_type, external_id). Kept for the life of the connection.

### 5.6 estasoft_sync_state

| Column | Type | Notes |
| --- | --- | --- |
| id | bigint unsigned PK auto | |
| connection_id | bigint unsigned | |
| entity_type, wc_id | varchar | as in the id map |
| data_hash | char(40) | hash of the last synced canonical data, so no-op updates are skipped |
| last_synced_at | datetime null | |
| last_state | varchar(10) | |
| extra_json | longtext null | invoice number, invoice status, due time, tracking |

Indexes: UNIQUE (connection_id, entity_type, wc_id), (last_synced_at). Kept for the life of the connection.

### 5.7 estasoft_sync_idempotency

| Column | Type | Notes |
| --- | --- | --- |
| idem_key | char(64) PK | |
| connection_id | bigint unsigned | |
| queue_id | bigint unsigned null | |
| result_ref | varchar(80) null | |
| created_at, expires_at | datetime | |

Index: (expires_at). Retention 90 days (Assumed), so a re-enqueued event after the queue row is gone still cannot create a duplicate.

### 5.8 Added tables (Assumed)

| Table | Columns | Why |
| --- | --- | --- |
| estasoft_sync_recon_runs | id, connection_id, started_at, finished_at, window_days, compared, matched, differences, repaired, status | The nightly result must persist for the dashboard and Reconciliation (90 days) |
| estasoft_sync_recon_diffs | id, run_id, connection_id, wc_id, type, wc_value, system_value, status (open, repaired, ignored), note, acted_by, acted_at | Differences with who acted. Without these two tables the results would be JSON blobs in options |

### 5.9 Options

| Option | Autoload | Content |
| --- | --- | --- |
| estasoft_sync_db_version | yes | schema version |
| estasoft_sync_settings | no | log retention, redaction, payload cap, display time, runtime profile, global concurrency, backlog threshold, admin bar on or off, delete on uninstall, notifications (recipients, triggers, digest time) |
| estasoft_sync_onboarding | no | step, answers, completed time, shadow start and end |
| estasoft_sync_readiness | no | last run time and results |
| estasoft_sync_stock_last | no | last stock run summary |
| estasoft_sync_capacity | no | last capacity test result |

Transients: OAuth `state` (10 minutes), failed-count cache (60 seconds), notification de-duplication keys.

### 5.10 Meta keys (written through CRUD, HPOS safe)

| Object | Key | Content |
| --- | --- | --- |
| Order | `_estasoft_sync_state` | queued, synced, failed, skipped, shadow |
| Order | `_estasoft_sync_external_id`, `_estasoft_sync_external_ref` | the system's id and number |
| Order | `_estasoft_sync_last_at` | UTC time |
| Order | `_estasoft_sync_invoice` | JSON: number, status, authority status, due time, pdf url, qr payload |
| Order | `_estasoft_sync_tracking` | JSON list: carrier, number, time |
| Product | `_estasoft_sync_exclude` | yes or no |
| Product | `_estasoft_sync_external_id` | the system's product id |
| Customer (user) | `estasoft_sync_external_id` | the system's contact id |

Orders list filters use `wc_get_orders` with `meta_query`, which works on HPOS; no `posts` or `postmeta` SQL.

## 6. Queue state machine and delivery rules

### 6.1 Item transitions

| From | Event | To | Rule |
| --- | --- | --- | --- |
| none | Order event hook | Queued | Insert in the order's transaction; shadow mode sets mode shadow |
| Queued | Worker claims | Syncing | Atomic update: state queued, due, connection active, no earlier unfinished or Failed item in the partition; sets lock |
| Syncing | Accepted | Synced | Store id map, hash and external ref; log Info |
| Syncing | Already there (SYNC-DUP-001) | Synced | Link the existing record |
| Syncing | Retryable error | Retrying | attempt plus 1; next time from the schedule plus jitter, or Retry-After if longer |
| Retrying | Due and claimed | Syncing | As above |
| Retrying | Last retry fails | Failed | The code of the last error |
| Syncing | Needs-a-person error | Failed | No retries |
| Syncing | Lock expired | Queued | Reaper; the try is not counted; SYNC-LOCK-001 |
| Failed | Person retries | Queued | attempt reset to 0, same idempotency key |
| Queued, Retrying, Failed | Person skips | Skipped | Reason stored |
| Skipped | Person restores | Queued | |
| Queued | Newer event, same order, still unsent | Queued | Latest data is built at send time (SYNC-STATE-001) |
| Queued, Retrying | Mode shadow | Synced (shadow) | Payload built and stored; nothing sent |

### 6.2 Connection transitions (the Paused state)

| From | Event | To | Rule |
| --- | --- | --- | --- |
| Active | Person pauses | Paused | Optional end time; items stay Queued |
| Active | 5 retryable failures in a row | Paused (circuit open) | SYNC-PAUSE-001; email once per incident if enabled |
| Paused (circuit open) | Probe succeeds (every 2 minutes) | Active | Reset failure count |
| Paused (circuit open) | Probe fails | Paused (circuit open) | Next probe in 2 minutes |
| Paused | Person resumes or time ends | Active | |
| Active | Token refresh fails | Needs reconnect | SYNC-AUTH-002; items wait |
| Needs reconnect | Person signs in again | Active | |
| Active | Sign-in refused (SYNC-AUTH-001; added by review-2) | Paused | Items wait as Queued; none are marked Failed |

### 6.3 Rules

- **Idempotency key:** `sha256("v1:" . connection_id . ":" . entity_type . ":" . wc_id . ":" . event . ":" . ordinal)` in hex, where ordinal counts events of the same kind for the same entity. Retries and person retries reuse the key. Where a system supports an idempotency header, the key is sent as well (Assumed per connector).
- **Delivery:** at least once, with an exactly-once effect through the key plus the lookup by client reference `WC-{order id}` before any create.
- **Ordering:** partition key `order:{id}`; a later step waits for an earlier one unless the earlier one is Skipped.
- **Retry schedule:** 1 minute, 5 minutes, 1 hour, 24 hours, plus a random 0 to 20 seconds when jitter is on. A Retry-After longer than the delay replaces it, up to 24 hours.
- **Circuit breaker defaults:** 5 failures in a row, probe every 2 minutes.
- **Rate limit defaults:** 4 requests per second, 2 items at once (also a global cap of 2).
- **Timeouts:** 30 seconds per request.
- **Lock:** 60 seconds, reaper every minute (Assumed).

## 7. REST API

Namespace `estasoft-sync/v1`. Authentication is the WordPress cookie with the `X-WP-Nonce` header; every route sets a `permission_callback`; nothing is public except the signed inbound route. JSON in and out; lists send `X-WP-Total` and `X-WP-TotalPages`. Errors: `{ "code": "SYNC-...", "message": "...", "data": { "status": 4xx, "class": "retry or needs_person or handled or info" } }` using the error catalog. Mutating routes accept an `Idempotency-Key` header and return the first result for a repeated key within 10 minutes (Assumed).

### 7.1 Capabilities (Assumed model)

| Capability | Granted to | Allows |
| --- | --- | --- |
| estasoft_sync_manage | administrator, shop manager | View everything except secrets; retry, skip, pause, resume, sync now, run reconciliation |
| estasoft_sync_configure | administrator | Connections, mapping, tax map, stock rules, settings, tools |
| (no capability) | nobody | The saved API key is never returned, even to administrators; it can only be replaced |

Map: `manage` is required by every route unless marked configure.

### 7.2 Routes

| Method and route | Cap | Purpose |
| --- | --- | --- |
| GET /status | manage | Counts, oldest waiting, connection state, shadow info |
| GET /queue | manage | Filter state, type, day, search; paginate |
| GET /queue/{id} | manage | Item with timeline, request and response excerpts |
| POST /queue/{id}/retry | manage | Retry one |
| POST /queue/{id}/skip | manage | Skip with reason |
| POST /queue/retry | manage | Bulk retry by ids or by error code and cause |
| POST /sync/run | manage | Run workers now |
| POST /pause | manage | Pause a connection or all, optional duration |
| POST /resume | manage | Resume |
| GET /connections | manage | List (no secrets) |
| POST /connections | configure | Create after a successful test |
| GET /connections/{id} | manage | One connection (no secrets) |
| POST /connections/{id} | configure | Update settings or credentials |
| DELETE /connections/{id} | configure | Disconnect |
| POST /connections/{id}/test | configure | Test; returns version, company, warehouses, permissions |
| POST /connections/{id}/oauth/start | configure | Returns the provider URL with state |
| GET /connections/{id}/oauth/callback | configure | Provider redirect target; validates state and user |
| GET /mappings/{type} | manage | Live and draft versions |
| PUT /mappings/{type} | configure | Save a draft |
| POST /mappings/{type}/publish | configure | Publish the draft |
| POST /mappings/{type}/rollback | configure | Roll back to a version |
| POST /mappings/preview | configure | Build a payload for an order, send nothing |
| GET /tax-map | manage | Read tax mapping |
| PUT /tax-map | configure | Save tax mapping |
| GET /stock/rules | manage | Read stock rules and warehouses |
| PUT /stock/rules | configure | Save stock rules |
| GET /stock/changes | manage | Recent stock changes and conflicts |
| GET /reconciliation/runs | manage | Runs and differences |
| POST /reconciliation/run | manage | Start a run with a window |
| POST /reconciliation/differences/{id}/repair | manage | Choose a direction |
| POST /reconciliation/differences/{id}/ignore | manage | Ignore with a reason |
| GET /logs | manage | Filter and paginate; CSV with `Accept: text/csv` |
| GET /logs/{id} | manage | One entry |
| GET /readiness | manage | Last results |
| POST /readiness/run | manage | Run the ten checks |
| GET /support-report | manage | Report text; query flag for order numbers |
| GET /settings | manage | Read |
| PUT /settings | configure | Save |
| POST /tools/export | configure | Settings file |
| POST /tools/import | configure | Preview, then apply with `confirm=true` |
| POST /tools/clear-logs | configure | |
| POST /tools/delete-data | configure | Requires the typed word |
| GET /invoices | manage | List with status and time left |
| POST /inbound/{connection} | signed | Hint from the system that stock, tracking or an invoice changed |

Additional (Assumed): GET /shadow/report (manage), POST /shadow/end (configure), GET /catalog (manage, static), POST /capacity-test (configure), GET /products/unmatched (manage), POST /products/map (configure), GET /products/search?connection= (configure; searches the system). The routes added by review-2 are listed in `review-2-changes.md` and in `src/api/types.ts`.

**Inbound route.** Header `X-Estasoft-Signature` is an HMAC-SHA256 of the raw body with a per-connection secret shown once when the person enables inbound; header `X-Estasoft-Timestamp` must be within 5 minutes; replays are refused. The body is only a hint (entity type and id): the plugin re-reads the entity through its authenticated API and trusts nothing in the body. Rate limited. Optional: off by default.

### 7.3 WP-CLI

| Command | Purpose |
| --- | --- |
| wp estasoft-sync worker [--concurrency=2] | Long-running worker |
| wp estasoft-sync status | Counts and connection state |
| wp estasoft-sync retry [--code=] [--ids=] | Retry failed items |
| wp estasoft-sync pause, resume | Pause or resume a connection |
| wp estasoft-sync reconcile [--window=7] | Run the nightly check |
| wp estasoft-sync readiness | Run the ten checks |

## 8. Hooks

Action Scheduler group `estasoft-sync`. Recurring actions: runner (every minute), stock (5 minutes), tracking and invoices (10 minutes), reaper (every minute), probe (2 minutes while a circuit is open), token refresh, reconciliation (nightly), log prune (daily), digest (daily), Action Scheduler purge (daily).

| Hook | Kind | Arguments | Use |
| --- | --- | --- | --- |
| estasoft_sync_register_connectors | filter | array of connectors | Add a connector |
| estasoft_sync_catalog | filter | catalog array | Change catalog entries |
| estasoft_sync_should_enqueue | filter | bool, event, order | Skip an order |
| estasoft_sync_payload | filter | payload, item, connection | Adjust a payload before sending |
| estasoft_sync_classify_error | filter | class, error, connection | Override classification |
| estasoft_sync_retry_schedule | filter | seconds array, connection | Change the delays |
| estasoft_sync_log_redact | filter | text or array | Extend redaction |
| estasoft_sync_readiness_checks | filter | checks | Add a check |
| estasoft_sync_before_send | action | item, request | Observe |
| estasoft_sync_after_send | action | item, result | Observe |
| estasoft_sync_item_synced, estasoft_sync_item_failed | action | item, (error) | Observe |
| estasoft_sync_connection_paused, estasoft_sync_connection_resumed | action | connection, reason | Observe |
| estasoft_sync_reconciliation_finished | action | run | Observe |
| estasoft_sync_invoice_updated | action | order, invoice | Observe |
| estasoft_sync_tracking_saved | action | order, tracking | Tracking plugins |

WooCommerce and WordPress hooks used (all Verify against the minimum versions at M1): `before_woocommerce_init` with `FeaturesUtil::declare_compatibility` for `custom_order_tables` and `cart_checkout_blocks`; `woocommerce_checkout_order_processed` and `woocommerce_store_api_checkout_order_processed`; `woocommerce_payment_complete`; `woocommerce_order_status_changed`; `woocommerce_order_refunded` or `woocommerce_refund_created`; `woocommerce_created_customer`; `add_meta_boxes` with the order screen id from `wc_get_page_screen_id`; list columns `manage_woocommerce_page_wc-orders_columns` and `manage_woocommerce_page_wc-orders_custom_column` (HPOS) plus `manage_edit-shop_order_columns` (legacy); bulk action filters for both screens; `admin_bar_menu`; `wp_dashboard_setup`; `plugin_action_links_{plugin}`; `register_uninstall_hook` or `uninstall.php`.

## 9. Admin screens and shared UI rules

Defined on the canvas: Z01 (colours), Z02 (names), Z03 (components). Rules: one filled primary button per view; status always an icon and a word; six state chips with fixed colours (decisions doc); tables become cards at 782 px and below; 44 px controls on touch; real elements; focus ring 2 px navy; dialogs trap focus. CSS prefix `estasoft-sync-`; custom properties `--estasoft-sync-*` as listed in the decisions doc. The WordPress frame is not recoloured; the plugin sets its canvas colour on its own wrapper only.

## 10. Connector contract (version 1)

A connector is a PHP class implementing `Estasoft\SyncEngine\Contract\ConnectorInterface`, registered through `estasoft_sync_register_connectors`, packaged as a plugin named `estasoft-sync-connector-{system}` (Odoo ships inside the core plugin).

| Method | Returns | Notes |
| --- | --- | --- |
| id(), label(), type() | string | type is erp, accounting or crm |
| contract_version() | semver | Major must match the core |
| capabilities() | map of flags | orders, customers, refunds, stock_in, tracking_in, prices_in, invoice_readback, reconciliation, webhooks, shadow_lookup |
| auth_style(), credential_fields(), oauth_config() | description | Key or user, or OAuth with scopes and endpoints |
| test(connection) | TestResult | Version, company, warehouses, permissions |
| ping(connection) | bool | Cheap health probe for the breaker |
| describe_fields(type) | field catalog | Drives the mapping screens |
| default_mapping(type) | rules | Version 1 presets |
| find_existing(item) | ExternalRef or null | Lookup by client reference; makes retries safe |
| send(item, payload) | Result | Success with external id and ref, or Error with code, class, HTTP status, retry-after |
| lookup_customer(email), lookup_product(sku), search_products(query) | refs | For preview and SKU mapping |
| fetch_stock(skus, warehouses), fetch_tracking(since), fetch_invoice(ref) | data | Only if flagged |
| list_for_reconcile(window) | refs with totals | Read-only |
| classify_error(error) | class | Optional override |
| rate_limit_hints() | requests per second, concurrency | Optional defaults |

Capability flags by type (intended, matches S04): ERP all flags; accounting orders (as invoices), customers, refunds (credit notes), invoice_readback, reconciliation, stock_in only where the system tracks inventory; CRM customers and orders as deals, limited reconciliation. Screens read these flags (CMP-3). Contract rules the suite checks (CMP-5): a send repeated with the same key returns the same reference; unknown errors are classified; the 30-second timeout is honoured; Retry-After is returned when the system gives it; secrets never appear in logs or exceptions; Arabic text round-trips unchanged; money rounds by the currency exponent; flags are honest (a flagged feature works); uninstall leaves no data.

**Connection styles.** Odoo and ERPNext: address plus API key or user. Sign-in connectors (QuickBooks Online, Xero, Zoho Books, Zoho CRM, HubSpot, Salesforce, Dynamics 365): OAuth 2 authorization code with `state`, PKCE where offered, minimal scopes, encrypted tokens, refresh job, expiry shown as Reconnect. Each connector confirms its real style when built. Who owns the OAuth application (the merchant's own or a shared one) is open question 2 (decision: the merchant's own; no relay).

**Enforcement of the contract test (Assumed).** The manifest declares the contract version it was tested against; core runs a light self-check at activation (methods present, flags valid, version). The full suite runs in the author's continuous integration. This cannot be enforced beyond that.

## 11. Error catalog

Classes: **retry** (automatic), **needs a person** (item Failed, no automatic retry), **handled** (treated as success or corrected without a person), **info** (a notice or log, not an item failure). Templates use `{system}`, `{sku}`, `{n}` and similar placeholders; the person-facing text never contains raw system errors without redaction. The full catalog, including the codes added in round 2 and by review-2, is generated in `docs/error-catalog.md` and `includes/Domain/ErrorCode.php`.

| Code | Class | Message template | What the person does | Auto retry |
| --- | --- | --- | --- | --- |
| SYNC-AUTH-001 | needs a person | {system} refused the sign-in. Check the user and the API key. | Edit the credentials and test again | No |
| SYNC-AUTH-002 | needs a person | The sign-in to {system} has expired. Reconnect. | Press Reconnect | No (items wait) |
| SYNC-NET-001 | retry | Couldn't reach {system}, or it didn't answer within 30 seconds. | Nothing, or check the connection | Yes |
| SYNC-NET-002 | retry | {system} answered with a server error ({status}). | Nothing | Yes |
| SYNC-RATE-001 | retry after the wait | {system} asked us to slow down. Waiting {n} seconds. | Nothing | Yes |
| SYNC-MAP-001 | needs a person | Product {sku} was not found in {system}. | Create it in the system or map the SKU, then retry the group | No |
| SYNC-MAP-002 | needs a person | Tax code not mapped for {country} {rate}. | Choose a code in Tax mapping, then retry | No |
| SYNC-MAP-003 | needs a person | Customer email is missing on order #{order}. | Add the email or skip the order | No |
| SYNC-VAL-001 | needs a person | Buyer tax number must be 15 digits for Saudi Arabia. | Correct the number on the order, then retry | No |
| SYNC-VAL-002 | needs a person | {system} rejected a field: {field}. {reason} | Fix the field or the mapping, then retry | No |
| SYNC-DUP-001 | handled (success) | {system} already has this order. Linked to {ref}. | Nothing | n/a |
| SYNC-STATE-001 | handled | The order changed while it waited. The latest version was sent. | Nothing | n/a |
| SYNC-CFG-001 | needs a person | There is no live mapping version for {type}. | Publish a mapping version | No |
| SYNC-CFG-002 | needs a person | The encryption key is missing from wp-config.php. | Add the line shown and reload | No |
| SYNC-PAUSE-001 | info | Syncing is paused. Items wait in the queue. | Resume, or check the connection | n/a |
| SYNC-RECON-001 | info | The nightly check found {n} differences. | Open Reconciliation | n/a |
| SYNC-COMP-001 | needs a person | The tax authority rejected invoice {invoice}: {reason}. | Fix the cause in the system, report again | No |
| SYNC-LOCK-001 (added) | handled | A worker stopped while sending. The item was queued again. | Nothing | Yes |
| SYNC-STK-001 (added) | info, needs a person | Stock for {sku} is lower than units already sold. | Accept the system quantity or hold | No |
| SYNC-INB-001 (added) | info | An inbound message was refused: the signature or time was wrong. | Check the secret | n/a |

Failures raised before sending (SYNC-MAP-002, SYNC-MAP-003, SYNC-VAL-001, SYNC-CFG-001) never reach the system. HTTP mapping default (Assumed): 408, 429 (Retry-After), 5xx, timeouts and refused connections are retry; 401 and 403 are SYNC-AUTH-001 (review-2: 401 only; 403 is SYNC-AUTH-004); other 4xx are SYNC-VAL-002 unless the connector says otherwise.

## 12. Screen to feature map

Board IDs are those of the UI/UX canvas (see `docs/design/uiux-status.md`). Boards marked new were added in round 2 and their IDs may shift.

| Board | Screen | Features |
| --- | --- | --- |
| Main, S01 | Cover, screen map | Reference |
| S02 | Sync states | QUE state machine, UI-11 |
| S03 | Sample data | Test fixtures for UI work |
| S04 | Connector capabilities | CMP-3, ONB-7 |
| Z01 | Colours and accessibility | A11Y-1 |
| Z02 | Handoff | Names, section 9 |
| Z03 (new) | Components | UI-12, A11Y-1 |
| Z04 (new) | Error catalog | Section 11, QUE-7 |
| Z05 (new) | Data and API map | Sections 5 and 7 |
| Z06 (new) | Queue state transitions | Section 6 |
| W01 (new) | WordPress.org icon and banner | Distribution |
| B01 | Check your store | ONB-1, ONB-3 |
| B02 | Choose a system (34) | ONB-5, CMP-1 |
| B03 | Connect to Odoo | CON-1 to CON-4 |
| B03b | Connect with a sign-in | CON-5, CON-6 (Phase 2) |
| B04 | What to sync (ERP) | ONB-7, CMP-3 |
| B04b | What to sync (accounting) | ONB-7 (Phase 2) |
| B04c | What to sync (CRM) | ONB-7 (Phase 2) |
| B05 | Preview an order | ONB-8, MAP-4 |
| B06 | Go live | ONB-9, SHD-1 |
| A01 | Where it lives in WordPress | UI-1, UI-3, UI-4, UI-5, UI-10 |
| A02 | Dashboard | UI-4, QUE-15, CON-11 |
| A03 | Dashboard, first run | UI-11, ONB-4 |
| A04 | Odoo unreachable | QUE-8, CON-7, SYNC-PAUSE-001 |
| A05 | Dashboard widget | UI-4 |
| A06 (new) | Screen Options and Help | UI-8 |
| A07 (new) | Admin notices | UI-9, ONB-1, CON-3 |
| A08 (new) | Dashboard loading | UI-11 |
| A09 (new) | Dashboard in shadow mode | SHD-1 to SHD-3, UI-11 |
| C01 | Queue | QUE-15, QUE-11, QUE-12 |
| C02 | Needs attention | QUE-10, QUE-11 |
| C03 | Order detail | QUE-11, LOG-5, QUE-4 |
| C04 | Dialogs: retry, skip, pause | QUE-11, QUE-12, CON-7 |
| C05 (new) | Empty and no-result states | UI-11, QUE-15 |
| C07 (new) | Shadow mode results | SHD-2, SHD-3 |
| H01 | Invoices | INV-2, INV-3, INV-4 |
| H02 | WooCommerce order screen | UI-5, INV-6 |
| H03 (new) | WooCommerce orders list | UI-6 |
| H04 (new) | WooCommerce product screen | UI-7, STK-3 |
| D01 | Connections | CON-10 |
| D02 | Connection detail | CON-1, CON-2, CON-8, CON-9, CON-11 |
| D03 | Community connectors | CMP-4 |
| D04 (new) | Connection problems and dialogs | CON-12, CON-6, CON-7, CON-9 |
| E01 | Mapping (orders) | MAP-1, MAP-2, MAP-5, MAP-6 |
| E02 | Tax mapping | MAP-7 |
| E03 (new) | Mapping (customers) | MAP-9 |
| E04 (new) | Mapping (products and SKU matching) | MAP-8 |
| E05 (new) | Mapping (refunds) | MAP-10 |
| E07 (new) | Mapping dialogs: edit a field, add a field, publish | MAP-3, MAP-5 |
| E08 (new) | More mapping dialogs: map a SKU, preview, roll back | MAP-4, MAP-6, MAP-8 |
| F01 | Stock | STK-1 to STK-5, STK-8 |
| F02 (new) | Stock conflicts | STK-6 |
| F03 (new) | Tracking and fulfilment | TRK-1 to TRK-3 |
| G01 | Reconciliation | REC-1 to REC-6 |
| G02 | Reconciliation dialogs | REC-4, REC-6 |
| I01 | Logs | LOG-4 |
| I02 | Readiness check | ONB-3 |
| I03 | Support report | LOG-6 |
| I04 | Settings: general | SET-1 |
| I05 | Settings: performance | PRF-1 to PRF-4, PRF-7 |
| I06 | Settings: access and tools | SET-3 to SET-6 |
| I07 (new) | Log entry detail | LOG-5 |
| I08 (new) | Settings: notifications | SET-2 |
| I09 (new) | Alert emails | SET-2 |
| I10 (new) | Tools dialogs | SET-5, SET-6, PRF-7 |
| R01, R02 | Dashboard, tablet and phone | UI-12 |
| R03 | Queue, phone | UI-12 |
| R04 | Order detail, phone | UI-12 |
| R05 to R09 (new) | Choose a system (phone), mapping (phone), settings (phone), phone sections menu and sheet, queue (tablet) | UI-2, UI-12 |

### Canvas notes to reconcile

1. I04 shows a "Share anonymous usage data" toggle. Phase 1 ships no telemetry (SET-1); remove the toggle from the board.
2. I06 has a "See credentials" column that says Yes for administrators while its note says credentials are never shown. Rename the column to "See connection details" (address and user) and keep the key hidden (section 7.1).
3. I06 lists four permission columns; the model in section 7.1 has two capabilities. The columns map to them (See the queue and Retry and skip: manage; Change mapping and details: configure).
4. I05 "Items at the same time" is the global cap; the connection screen has the per-connection cap. Label them "All connections" and "This connection".
5. The queue table has a terminal Skipped state (5.2). Show it as a neutral tag, not a seventh chip.
6. H01 time-left colours: neutral above 12 hours, warning 3 to 12, danger under 3 (INV-3); the board shows 5 h 40 and 9 h 05 as warning and 23 h 17 as neutral, which agrees.
7. B03b, B04b and B04c belong to Phase 2 connectors; label them so on the canvas.
8. Bubble count in the menu equals Failed items, which is the Needs attention KPI.

## 13. Build order

| Milestone | Scope | Exit criteria |
| --- | --- | --- |
| M1 Foundation | Plugin skeleton, HPOS and blocks declarations, requirements guard, tables and migrations, encrypted connections, connector registry and contract v1, Odoo connection and test, outbox enqueue, worker (standard profile), item transitions with ordering and idempotency, logs with redaction, translation scaffolding | An order placed in a test store is sent to a test Odoo and linked; killing the worker mid-send never creates a duplicate; checkout works with Odoo off; unit tests green; Plugin Check shows no errors |
| M2 Mapping | Presets, rule model and transform library, preview with a real order, publish and roll back, tax mapping, SKU matching, customers and money rules, contract suite draft | The sample order #10490 produces the documented payload; unmapped tax and SKU fail before or at send with the right codes; versions and rollback work |
| M3 Reliability | Retries with jitter and Retry-After, classification, circuit breaker, rate limit, failed items and bulk retry, skip, pause and resume, reconciliation, queue and logs screens, object cache option, CLI worker | Chaos tests pass (timeouts, 429, 503, auth failure, worker kill); reconciliation finds a deleted sale order and repairs it; queue screen handles 10,000 rows with paging |
| M4 Stock and compliance | Stock in with buffer and holds, warehouses, conflicts, tracking in, refunds, invoice read-back and the 24-hour clock, shadow mode and its report | Stock matches the system within the buffer after a run; tracking completes an order; a rejected invoice appears in Needs attention; shadow mode sends nothing and reports differences |
| M5 Admin experience | Wizard, readiness, dashboard and widget, admin bar, menu bubble, order and product boxes, orders list column and bulk actions, notices, Help and Screen Options, settings, notifications, support report, import and export, capacity test, accessibility pass | Every canvas board has a working screen; axe and keyboard and screen-reader passes done; emails render; phone and tablet layouts verified on real devices |
| M6 Hardening and release | Contract test suite package, k6 method and results, security review, uninstall cleanup, documentation, WordPress.org readme and assets, release candidate | Plugin Check clean; no known data-loss bug; k6 report published; readme and screenshots ready; slug and name checks done |

## 14. Test plan

- **Unit:** transforms, money and rounding by currency exponent, idempotency keys, retry schedule and jitter bounds, classification, redaction (emails, phones, addresses, tax numbers, secrets), state transitions, ordering claim query.
- **Integration (real Odoo):** run Odoo Community in a container for each supported version (Odoo is free to run; Assumed image availability, Verify). Cases: create, duplicate, product missing, tax missing, customer match, refund, stock read, delivery read, rate-limit and timeout through a fault-injecting proxy.
- **WooCommerce matrix:** HPOS on and off, classic and block checkout, minimum and latest versions, a multi-currency plugin, a tracking plugin.
- **Load (k6) method, to be published with results:** a staging site with the same stack as production, WooCommerce checkout driven by k6, a stub system with fixed latency; compare checkout request time with and without the plugin; measure queue throughput per concurrency setting; record server specification, versions, data volume and settings. No figure is promised before it is measured.
- **Accessibility:** axe on every board's real screen, full keyboard pass, one screen reader pass per major platform (Assumed: NVDA on Windows, VoiceOver on macOS), 200 percent zoom, forced colours, reduced motion.
- **WordPress.org:** Plugin Check, PHPCS with WordPress Coding Standards (pin versions as in earlier Estasoft plugins), escaping and nonce review, GPL file audit.
- **Security:** capability and nonce tests on every route, secret never returned, inbound signature and replay tests, SQL injection and XSS review, key rotation behaviour.

### 14.1 Acceptance scenarios

| ID | Scenario |
| --- | --- |
| AS-01 | Given WooCommerce is inactive, when the plugin is activated, then no fatal error occurs and a notice names WooCommerce |
| AS-02 | Given 6 products without a SKU, when readiness runs, then it reports Worth fixing with the 6 listed and setup can continue |
| AS-03 | Given a wrong API key, when Test connection runs, then SYNC-AUTH-001 shows and nothing is saved; with the right key the connection is saved encrypted |
| AS-04 | Given the encryption key constant is absent, when saving a connection, then SYNC-CFG-002 shows with the line to add |
| AS-05 | Given Odoo is off, when a customer checks out, then checkout completes at normal speed and the item waits as Queued |
| AS-06 | Given a paid order, when the worker runs, then the sale order exists in Odoo, the id map and order meta hold its number, and the state is Synced |
| AS-07 | Given the worker stops after Odoo accepted the order, when the item is retried, then no second sale order is created and it links as SYNC-DUP-001 |
| AS-08 | Given the created event failed with SYNC-MAP-001, when the paid event is due, then it waits and sends only after the first succeeds or is skipped |
| AS-09 | Given Odoo answers 429 with Retry-After 120, when the item fails, then it is Retrying and not tried again for at least 120 seconds |
| AS-10 | Given 5 consecutive 503 answers, when the fifth fails, then the connection is Paused with the circuit open, items stay Queued, and the first successful probe resumes it |
| AS-11 | Given three orders failed on the same SKU and the SKU is now mapped, when the person retries the group, then all three are sent oldest first |
| AS-12 | Given a queued item, when it is skipped with a reason and restored, then it returns to Queued and is sent |
| AS-13 | Given a draft mapping, when it is published and later rolled back, then versions form a straight history and items sent after each change use the then-live version |
| AS-14 | Given a preview of order #10490, when it runs, then changed lines are highlighted and no request reaches Odoo |
| AS-15 | Given an order with an unmapped tax rate, when it is processed, then SYNC-MAP-002 appears before sending; after mapping, retry succeeds |
| AS-16 | Given a Saudi business buyer with a 10-digit tax number, when processed, then SYNC-VAL-001 appears and nothing is sent |
| AS-17 | Given an Arabic customer name and address, when sent and read back, then the text is identical |
| AS-18 | Given a Kuwaiti dinar order with 3 decimals, when mapped and reconciled, then amounts match to 0.001 with no float error |
| AS-19 | Given a buffer of 2 and 42 units in Odoo with 1 unit held by an unpaid order, when stock syncs, then the shop shows 39 |
| AS-20 | Given a delivery with a tracking number in Odoo, when the tracking job runs, then the order becomes Completed with the number in a note |
| AS-21 | Given one order missing in Odoo and one total differing by 0.05 AED, when the nightly check runs, then the missing one is queued automatically and the amount waits for a person |
| AS-22 | Given shadow mode on, when 20 orders arrive, then nothing is sent, the report lists matches and differences, and Go live sends only new orders |
| AS-23 | Given a Saudi consumer invoice with 5 hours left and a rejected one, when Invoices opens, then times show with the right colours and the rejected one is in Needs attention (SYNC-COMP-001) |
| AS-24 | Given a failure message containing an email and a phone number, when logged and put in a support report, then neither appears and order numbers show as **** |
| AS-25 | Given a shop manager, when they try to change mapping or open credentials by route, then the request is refused; and when an administrator deletes all data, then plugin tables, options and meta are gone and WooCommerce orders remain |

## 15. Open questions and not verified

**Open questions.**
1. Admin implementation: React app built with WordPress scripts for Queue, Logs and Mapping and PHP pages elsewhere (Assumed, UI-13), or PHP list tables throughout. **Answered 2 Nov 2026: React for the whole admin.**
2. OAuth application ownership (Phase 2): each merchant registers their own application and enters its client id and secret (no secret in the plugin, no server of ours), or a shared application run by Estasoft (needs a token relay, which conflicts with "no hosted service"). Recommended: the merchant's own application. **Answered 2 Nov 2026: no relay, "make it ready from your side"; the merchant's own application is the default.**
3. Which ERP APIs the Odoo connector uses and which Odoo versions are supported (Assumed 17, 18, 19).
4. Whether shadow reports can match records the staff enter by hand (they rely on the system's client reference field; Assumed staff copy `WC-{id}`).
5. Warehouse model: shop stock as the sum of counting warehouses (Assumed) versus a per-country stock plugin integration.
6. Enforcement of the contract test (section 10).
7. Capability names and the split in 7.1.
8. Slug, name and trademark checks; public repository name; support channel; minimum versions (decisions doc open items 1, 3, 4, 5).
9. Status of the 34 catalog systems (decisions doc open item 2).
10. Whether the Invoices tab ships in the first release (UI status open item 3).

**Not verified (carry over, nothing here is confirmed).**
- Whether any system's API supports what the capability table (S04) promises; API access and sandbox cost for Business Central, Zoho Books, Zoho CRM, ERPNext, HubSpot, NetSuite, Salesforce and SAP Business One; the APIs of the Gulf-local systems.
- The WooCommerce and WordPress hook and function names in section 8 and the Action Scheduler command in PRF-3.
- Odoo container availability and the exact Odoo API behaviour per version.
- The Saudi 24-hour consumer-invoice rule and the UAE e-invoicing phases (tax-vendor summaries; verify with the authorities before any text like it ships); the 15-digit Saudi tax number rule.
- Market-share and popularity signals behind the catalog statuses (vendor, blog and website-detection pages).
- Whether the slug is free on WordPress.org, WooCommerce trademark rules for the name, and whether a slug can change after approval.
- Cash on delivery being common in the region (to confirm with merchants).
- The palette on a real WordPress admin screen, in each admin color scheme, with a screen reader and in high-contrast mode.
