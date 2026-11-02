# REST API

Namespace `estasoft-sync/v1`, so every address starts with `/wp-json/estasoft-sync/v1/` (or `?rest_route=/estasoft-sync/v1/` on a site with plain permalinks). The admin app is the only client the plugin ships, and `src/api/types.ts` is the contract between the two: this page says how each route behaves, `types.ts` says what the bodies look like.

The code is in `includes/Rest/`: `RestModule` registers the routes on `rest_api_init`, `BaseController` does the work common to all of them, and there is one controller per group in `includes/Rest/Controllers/`. A controller reads its parameters, calls one service, and returns the array that `types.ts` describes. It holds no business logic. Services come from the container when the route runs (`Core\Services` for the engine, `Jobs\JobServices` for the Jobs module, `mapping.*` for the mapping module), so a request builds only what it needs.

## How every route behaves

**Signing in.** WordPress' cookie and the `X-WP-Nonce` header (a `wp_rest` nonce; the page hands it to the app in `window.estasoftSync.nonce`). Core drops the user when the nonce is missing, so such a request is answered 401. There is no other way in, except the two routes marked below.

**Capabilities.** Every route has a `permission_callback`. `manage` means `estasoft_sync_manage` (administrators and shop managers; `estasoft_sync_configure` includes it). `configure` means `estasoft_sync_configure` (administrators). The rule is `Core\Capabilities::userCanManage()` everywhere: the routes, the admin bar, the dashboard box, the notices and the menu entry. A role that holds only `estasoft_sync_configure` therefore sees the page: the menu entry is registered with the capability the current person has (`estasoft_sync_manage`, or `estasoft_sync_configure` for such a role), because WordPress takes one capability per menu entry. A visitor who is not signed in gets 401 and a signed-in person without the capability gets 403, with the error body below. WordPress normally reports a parameter error before it asks about permission; for this plugin's routes the permission answer comes first (`RestModule::permissionFirst`), so a visitor learns nothing about the shape of a route. Two routes differ:

- `POST /inbound/{connection}` allows everyone, because it checks an HMAC signature instead (see below). It is the only route whose permission callback is `__return_true`.
- `GET /connections/{id}/oauth/callback` is allowed when `state` has the shape of a state this plugin makes; the state itself is checked in the callback (see "Sign-in routes").

**Secrets.** No route returns a secret. A saved key can only be replaced: send the new value in `secrets`, or an empty string to keep the saved one. The one answer that contains a secret is the signing secret returned once by `POST /connections/{id}/inbound`; it is never stored for replay.

**Parameters.** Each route declares its arguments with a type, ranges, an enumeration or a pattern, and a sanitise callback. Plain text has tags removed and is cut to a length; secrets and uploaded JSON files are kept exactly as sent. The services validate in depth afterwards and report one message per field.

**Errors.** Always `{ "code": "SYNC-...", "message": "...", "data": { "status": 422, "class": "needs_person" } }`.

- `class` is one of `retry`, `needs_person`, `handled`, `info` (the error catalog).
- `data.context` holds the values of the `{placeholders}` of a catalog message, `data.fields` holds one message per field for validation errors, and `data.details` holds extra structure: `errors` (every problem of a mapping that cannot be published, each with `rule_id`, `field`, `code`, `message`), `test` (the failed connection test) and `key_line` (the line to add to `wp-config.php` when the encryption key is missing).
- Catalog codes (`SYNC-MAP-001` and the rest, see `docs/error-catalog.md`) are used wherever one fits. WordPress codes are used where WordPress has one: `rest_not_found` (404), `rest_invalid_param` (400 from the argument check, 422 from a service), `rest_forbidden` (401 or 403).
- Codes of this layer that are not in the catalog yet: `SYNC-REST-001` (an unexpected failure, status 500, class `retry`; the real cause is in the log, never in the answer), `SYNC-REST-002` (the test email was not accepted, 502), `SYNC-CFG-004` (a sign-in route for a connector that connects with a key, 400), `estasoft_sync_conflict` and the more specific `estasoft_sync_cannot_retry`, `_cannot_skip`, `_cannot_restore`, `_cannot_pause`, `_cannot_resume`, `_run_failed`, `_no_connection` (409 or 503: the record is not in a state that allows it), `estasoft_sync_idempotency_conflict`, `estasoft_sync_bad_idempotency_key`, `estasoft_sync_credentials_unreadable`.
- The known kinds are the plugin's own: `ApiException`, `Core\Support\ValidationException` (422, one message per field), `Mapping\RuleValidationException` (422, every rule problem), `Mapping\MappingException`, `Core\Crypto\CryptoException` and `Jobs\JobException` with its subclasses. Every other exception, a plain `InvalidArgumentException`, `OutOfBoundsException` or `LogicException` included (any library can throw those, and their messages are not written for a person), becomes `SYNC-REST-001`: status 500, nothing but the generic text in the answer, and one line in the log that names the exception class and nothing else (no message, code, file or trace).
- The mistakes a person can make are therefore checked before a service is called and answered with their own code: a tax row or buyer rule without a two-letter country, or with a rate outside 0 to 100, is a 422 with a message for that row (`rows.2.country`, `buyer_tax_rules.0.country`) and nothing is saved; publishing without a draft is a 409 `SYNC-CFG-001`; rolling back to a version that does not exist is a 404.

**Idempotency-Key.** State-changing routes (`POST`, `PUT`, `DELETE`) accept an `Idempotency-Key` header of up to 128 letters, digits and `- _ . :`. The first successful answer (status below 400) is kept for ten minutes for that person, method, route and key. The same key again with the same request, that is the same body, the same query string and the same route parameters (the order of parameters does not matter), returns the stored answer with the header `Idempotent-Replayed: true` and runs nothing. The same key with anything different, a body or a query value, is a 409, so a key can never replay the answer to a question that was not asked; a bad key is a 400. Error answers are not kept, so a retry after a failure runs again. Only a keyed hash of the request is stored, never the body or the query, because they can carry a credential. Not honoured on: the test routes (`/connections/test`, `/connections/{id}/test`, `/notifications/test`, `/capacity-test`, `/tools/export`), `/connections/{id}/inbound` (its answer holds a secret), the preview, the sign-in start and the signed inbound route.

**Pagination.** List routes answer `Paged<T>` (`items`, `total`, `total_pages`, `page`, `per_page`) and send `X-WP-Total` and `X-WP-TotalPages` as WordPress lists do.

**Connection.** Routes that work on one connection take an optional `connection_id`. Without it the first connection that is not disconnected is used; with no connection at all the answer is a 409 `estasoft_sync_no_connection`.

## Routes

| Method and route | Capability | Parameters | Answer |
| --- | --- | --- | --- |
| `GET /status` | manage | `connection_id?` | `Status` |
| `GET /queue` | manage | `state?` (queued, syncing, synced, retrying, failed, skipped, waiting, all), `entity_type?`, `day?` (today, yesterday, 7d, 30d), `mode?` (live by default, shadow, all), `search?` (100 characters), `error_code?` (`SYNC-XXX-000`), `connection_id?`, `page?`, `per_page?` (25, 50 or 100; without it the person's Screen Options value) | `Paged<QueueItem>` |
| `GET /queue/attention` | manage | | `AttentionGroup[]` |
| `GET /queue/{id}` | manage | | `QueueItemDetail`; 404 |
| `POST /queue/{id}/retry` | manage | | `QueueItem`; 409 unless failed or retrying |
| `POST /queue/{id}/skip` | manage | `reason?` (500 characters) | `QueueItem`; 409 when already finished |
| `POST /queue/{id}/restore` | manage | | `QueueItem`; 409 unless skipped |
| `POST /queue/retry` | manage | `ids?` (up to 500), or `error_code` with `cause?` | `{ retried }`; 422 with neither |
| `POST /queue/bulk` | manage | `ids` (required), `action` (skip, retry, restore; required), `reason?` | `{ done }` |
| `POST /sync/run` | manage | | `{ started: true }`; 503 when no run could be scheduled |
| `POST /pause` | manage | `connection_id?` (all when absent), `minutes?` (1 to 43200) | `ConnectionSummary`; 409 when nothing can be paused |
| `POST /resume` | manage | `connection_id?` | `ConnectionSummary`; 409 when nothing is paused |
| `POST /connections/{id}/pause` | manage | `minutes?` | `ConnectionSummary` |
| `POST /connections/{id}/resume` | manage | | `ConnectionSummary` |
| `GET /connections` | manage | | `ConnectionSummary[]` |
| `POST /connections` | configure | `connector` (required), `label?`, `config?`, `secrets?`, `settings?` | `Connection` (status 201); the connection is tested first and a failed test saves nothing (422 with the catalog code and `data.details.test`); 409 without an encryption key |
| `GET /connections/{id}` | manage | | `Connection` |
| `POST /connections/{id}` | configure | any of `label`, `config`, `secrets`, `settings` | `Connection`; new credentials are tested first |
| `DELETE /connections/{id}` | configure | | `{ disconnected: true }` |
| `POST /connections/{id}/test` | configure | | `ConnectionTestResult`, exactly its keys: what the connector detected for its own use is not returned |
| `POST /connections/test` | configure | as `POST /connections`, plus `connection_id?` (empty secrets then keep the saved ones) | `ConnectionTestResult` |
| `POST /connections/{id}/inbound` | configure | `enabled` (required) | `{ enabled: false }` or `{ enabled: true, secret, url }`; the secret is shown once |
| `POST /connections/{id}/oauth/start` | configure | | `{ url }`; SYNC-CFG-004 for key-style connectors |
| `GET /connections/{id}/oauth/organisations` | configure | | `{ id, name }[]`; SYNC-CFG-004 for key-style connectors |
| `GET /connections/{id}/oauth/callback` | one-time state | `state` (required), `code?`, `error?` | 302 to the admin screen with `signin=ok`, `cancelled` or `failed` |
| `GET /oauth/callback` | one-time state | `state` (required), `code?`, `error?` | The stable address a merchant registers in the vendor's developer portal (no connection id in it); the state finds the connection and the person. 302 to the admin screen with `signin=ok`, `cancelled` or `failed` and `connection=<id>` |
| `POST /connections/{id}/oauth/organisation` | configure | `id` (required) | `Connection`; stores the chosen organisation, runs the connection test and activates the connection when it passes |
| `GET /exports` | manage | `connection_id?` | `{ files: ExportFileInfo[], open_batch: { connection_id, orders, since } or null }` |
| `POST /exports` | configure | `connection_id` (required) | `ExportFileInfo`; SYNC-EXP-001 when the file cannot be written, SYNC-EXP-002 when orders were left out (listed in `left_out`) |
| `GET /exports/{id}/download` | manage | | The file as an attachment (`Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`); only through this signed-in request, never by a web address |
| `DELETE /exports/{id}` | configure | | `{ deleted: true }` |
| `GET /catalog` | manage | | `CatalogEntry[]` |
| `GET /mappings/{type}` | manage | `connection_id?`; type is orders, customers, products, refunds, stock or tracking | `MappingSet` |
| `PUT /mappings/{type}` | configure | `rules` (required, up to 500), `note?`, `connection_id?` | `MappingSet` (saves the draft) |
| `GET /mappings/{type}/versions/{version}` | manage | `connection_id?` | `MappingVersion` with its rules; 404 |
| `POST /mappings/{type}/publish` | configure | `note?`, `connection_id?` | `MappingSet`; 409 `SYNC-CFG-001` without a draft; 422 with every rule problem |
| `POST /mappings/{type}/discard` | configure | `connection_id?` | `MappingSet` |
| `POST /mappings/{type}/rollback` | configure | `version` (required), `connection_id?` | `MappingSet`; a new live version, a copy of the old one |
| `POST /mappings/preview` | configure | `type`, `order_id` (required), `use_draft?`, `lookups?`, `connection_id?` | `MappingPreview`; nothing is sent |
| `GET /tax-map` | manage | `connection_id?` | `TaxMap` |
| `PUT /tax-map` | configure | `rows` (required: `country`, `tax_name`, `rate_percent` 0 to 100, `system_code`), `buyer_tax_rules?`, `connection_id?` | `TaxMap` |
| `GET /products/unmatched` | manage | `page?`, `connection_id?` | `Paged<ProductMatch>` |
| `GET /products/search` | configure | `q` (required), `connection_id?` | `{ id, ref, name }[]`; 502 with the catalog code when the system fails |
| `POST /products/map` | configure | `product_id` (required), `external_id` (null clears), `external_ref?`, `connection_id?` | `ProductMatch` |
| `GET /stock/rules` | manage | `connection_id?` | `StockRules` |
| `PUT /stock/rules` | configure | any of `enabled`, `interval_minutes` (5 to 1440), `safety_buffer` (0 to 999), `subtract_held`, `warehouses`, `tracking_enabled`, `delivered_becomes`, `cod_payment_on_delivery`, `connection_id?` | `StockRules` |
| `GET /stock/overview` | manage | `connection_id?` | `StockOverview` |
| `POST /stock/conflicts/{id}/accept` | manage | | `{ ok: true }` |
| `POST /stock/conflicts/{id}/hold` | manage | | `{ ok: true }` |
| `GET /reconciliation` | manage | `connection_id?` | `ReconOverview` |
| `POST /reconciliation/run` | manage | `window_days` (1 to 30, required), `connection_id?` | `ReconRun`; a long run is carried on in bounded steps, status `running` until done |
| `POST /reconciliation/differences/{id}/repair` | manage | `direction` (wc_to_system, system_to_wc) | `ReconDiff` |
| `POST /reconciliation/differences/{id}/ignore` | manage | `reason` (required) | `ReconDiff` |
| `PUT /reconciliation/settings` | configure | any of `schedule_time` (`HH:MM`), `window_days`, `tolerance_minor` (0 to 1000), `auto_repair_missing` | `ReconOverview` |
| `GET /invoices` | manage | `status?` (waiting, rejected, reported, not_required), `search?`, `page?`, `per_page?` (1 to 100) | `Paged<InvoiceRow>` |
| `GET /logs` | manage | `level?`, `search?`, `connection_id?`, `queue_id?`, `code?` (`SYNC-XXX-000`), `range?` (`1h`, `24h`, `7d`, `30d` or `all`; without it everything that is kept), `page?`, `per_page?` (10 to 200); send `Accept: text/csv` for a download that applies the same filters | `Paged<LogEntry>`, or the CSV (cells that start with `=`, `+`, `-` or `@` are made harmless) |
| `GET /logs/{id}` | manage | | `LogEntryDetail`; 404 |
| `GET /readiness` | manage | | `Readiness` |
| `POST /readiness/run` | manage | | `Readiness` |
| `GET /support-report` | manage | `order_numbers?` (0 or 1) | `SupportReport` |
| `GET /settings` | manage | | `Settings` |
| `PUT /settings` | configure | any field of `Settings`; all or nothing | `Settings`; 422 with a message per field |
| `POST /notifications/test` | configure | `recipient?` | `{ sent_to: string[] }`; 502 `SYNC-REST-002` when WordPress did not accept the message |
| `POST /tools/export` | configure | | `{ filename, json }` |
| `POST /tools/import` | configure | `json` (required, up to 1 MB), `confirm?` | `{ preview: string[] }`, or with `confirm` `{ applied: true, drafts, skipped }`: `drafts` is the number of mapping drafts created, `skipped` lists what was left out because it is not connected here (`xero`) or could not be saved as a draft (`odoo:orders`). Nothing else is returned |
| `POST /tools/clear-logs` | configure | | `{ cleared }` |
| `POST /tools/delete-data` | configure | `word` (must be `DELETE`) | `{ deleted: true }`; the capabilities stay |
| `POST /capacity-test` | configure | | `CapacityResult` |
| `GET /onboarding` | manage | | `Onboarding` |
| `PUT /onboarding` | configure | any of `step` (1 to 5), `connector`, `answers` | `Onboarding` |
| `POST /go-live` | configure | `mode` (shadow or live) | `Onboarding` |
| `POST /shadow/start` | configure | `days?` (1 to 30, default 7), `connection_id?` | `Onboarding` |
| `GET /shadow/report` | manage | `connection_id?` | `ShadowReport` |
| `POST /shadow/end` | configure | | `Onboarding` |
| `GET /orders/sample` | manage | | `SampleOrder[]` |
| `POST /inbound/{connection}` | signature | the raw body and two headers | `{ accepted: true }` |

Routes the contract lists that this release does not ship: `GET /connections/{id}/pause-history`.

The routes `GET /connections/{id}/oauth/...` and `POST /connections/{id}/inbound` are not in the endpoint list of `src/api/types.ts`; the inbound switch is needed to turn the signed route on, and the app does not call it yet.

### Stock rules: `delivered_becomes`

The tracking setting that sits with the stock rules. It is the order status a delivered order moves to when the tracking job reads a completed delivery from the system. The value is the slug of any order status of the shop, with or without the `wc-` prefix, for example `completed` (the default), `processing` or a custom status; `Jobs\Tracking\TrackingSettings` refuses a slug the shop does not have (422 with a message on the field). An order only ever moves forward on this ladder: `pending`, `on-hold`, `processing`, `completed`. A status that is not on the ladder (cancelled, refunded, failed, draft, a custom status) is never moved, and nothing is moved into one, so choosing a custom status means delivered orders are left as they are.

### The signed inbound route

`POST /inbound/{connection}` takes a hint from the connected system ("stock, tracking or an invoice changed"). Send the raw body, the header `X-Estasoft-Timestamp` (Unix seconds, or a date, within five minutes of the server) and the header `X-Estasoft-Signature`: the hex HMAC-SHA256 of the timestamp exactly as sent, a dot and the raw body (`hash_hmac( 'sha256', $timestamp . '.' . $body, $secret )`), with the secret shown once when inbound was switched on; an optional `sha256=` prefix is accepted. The time is inside the signature, so an old message cannot be given a new time. `Jobs\Inbound\InboundHandler` checks the signature in constant time, refuses a replay (the signature is remembered for ten minutes), rate-limits per connection and logs `SYNC-INB-001` on every refusal. The body is only a hint: the plugin reads the data again from the system. Refusals use the status the handler chose (401, 404 for an unknown or switched-off connection, 409, 413, 429) and the code `SYNC-INB-001`; the answer never says which check failed beyond what the handler's message says.

### Sign-in routes

The first release ships no connector that signs in on a provider's page, so for Odoo the three sign-in routes answer `SYNC-CFG-004`. The plumbing is complete and tested, so a connector can be added without touching the routes: a connector that implements `Rest\OAuth\SignInConnector` next to `ConnectorInterface` is used by `oauth/start` (a random `state` bound to the person and the connection, valid ten minutes, with a PKCE S256 challenge), `oauth/callback` and `oauth/organisations`.

The callback is a browser redirect from the provider. It carries the login cookie but no REST nonce, so WordPress treats it as logged out. Its permission callback therefore does not decide anything about the person: it lets a request through when `state` is text of the shape this plugin makes (letters, digits, `-` and `_`, 20 to 128 characters). A request without such a `state` is refused like any unauthorised REST call (401, or 403 for a signed-in person) with the generic message, because it cannot be a return from a provider.

Everything else is decided inside the callback. It first uses the state up (the state is random, 256 bits, stored only as a hash, valid ten minutes and for one use), then checks that it belongs to the connection in the address and to a person who still holds `estasoft_sync_configure` at that moment. Then it passes the code to the connector. The browser is always sent on with a 302 to `admin.php?page=estasoft-sync#/connections/{id}?signin=ok|cancelled|failed` and gets no body: `cancelled` when the provider reported an error or sent no code, `failed` for everything else. An unknown, used, expired or foreign state, a connection that does not exist, a person who lost the capability, a code that is too long (over 2048 characters) or not text, and an exception in the connector all end in the same `failed` redirect to `#/connections?signin=failed`, so a visitor who is not signed in learns nothing about which of them it was. An unexpected exception is logged by class only.

## Boot data

`window.estasoftSync` (`BootData` of `types.ts`) is built by `Admin\BootData` and printed with `wp_add_inline_script( ..., 'before' )`, encoded so that no value can close the script element. Besides the fields of the contract it carries `perPage`, the person's Screen Options page size (25, 50 or 100) so that the queue opens with it. `notices` holds the notices that belong inside the app (failed orders, queue backlog, a connection that needs reconnecting, the missing encryption key, the end of shadow mode); the welcome notice is printed by PHP on other screens only.

## Admin redirects

Two admin requests end in a redirect: the one-time jump to the setup guide after activation (`Admin\Welcome`, on `admin_init`) and the "Not now" button of the welcome notice (`Admin\Notices::dismiss`, on `admin_post_estasoft_sync_dismiss_notice`). Both call `wp_safe_redirect()` and then `exit`, as WordPress core does, so nothing else in the request can send output or a second redirect. The `exit` sits in a closure that a test can replace, and `bin/red-flags-allowlist.txt` allows it for those two files only.

The welcome jump happens once, on the first ordinary admin page an administrator opens within a minute of activating. It is skipped for bulk and network activation, WP-CLI, cron, AJAX and `admin-post.php`, and it is used up only by a person who may configure: someone else opening an admin page first (a shop manager, say) leaves it for the administrator.
