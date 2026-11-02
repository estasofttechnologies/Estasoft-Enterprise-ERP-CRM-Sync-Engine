# Building a connector on the shared base

This page takes you from nothing to a connector that passes the contract tests, in about fifteen minutes of typing. The worked example is `tests/php/Unit/Connectors/Support/ReferenceConnectors/` (a key-based accounting connector, an OAuth sign-in CRM connector and a file export connector, each with a fake vendor server). Read `ReferenceBooksConnector.php` next to this page.

The shared base is in `includes/Connectors/Support/`. Its public names are fixed by the connector contract (`vendors-contract.md`); `includes/Connectors/Support/CHANGES.md` lists every clarification and addition.

## 1. The five files

```
includes/Connectors/Acme/AcmeProvider.php     announces the connector (the only file the generator reads)
includes/Connectors/Acme/AcmeConnector.php    extends AbstractApiConnector
tests/php/Unit/Connectors/Acme/AcmeConnectorContractTest.php
tests/php/Support/FakeAcme*.php               the vendor's wire behaviour on ApiFakeServer
docs/connectors/acme.md                       the document (front matter + sections, section 8 below)
```

```php
final class AcmeProvider implements ConnectorProvider {
	public function id(): string { return 'acme'; }              // lower case [a-z0-9-], also the document name
	public function create( ConnectorDependencies $deps ): ConnectorInterface { return new AcmeConnector( $deps ); }
}
```

Then run `php bin/generate-connector-index.php`. It rewrites `includes/Connectors/providers.php` (sorted, deterministic, safe to run from several places at once). A provider whose connector class does not load yet is skipped with a message; run it again when your code compiles. `bin/lint-php.sh` (and so CI) fails when the file is out of date. `ConnectorsModule` registers each provider as a Closure under its id, so a site builds only the connectors it uses.

## 2. The connector class

```php
class AcmeConnector extends AbstractApiConnector {
	protected function meta(): ConnectorMeta {
		return new ConnectorMeta(
			id: 'acme', label: 'Acme Books', type: ConnectorType::Accounting,
			capabilities: array( Capability::Orders, Capability::Customers ),   // only what the connector really does
			entities: array( EntityType::Order, EntityType::Customer ),
			auth: AuthStyle::Key,
			regions: array( 'AE' ),
			rateLimit: new RateLimitHints( requestsPerSecond: 5.0, requestsPerMinute: 300, requestsPerDay: 5000 ),
			hosts: array( 'api.acme.test' ),         // every host the plugin contacts; '*.acme.test' allowed
			merchantHost: false,                     // true when the merchant types the address (hosts then stays empty)
			pingPath: '/me',                         // one cheap GET that answers 2xx for a working connection; the circuit-breaker probe uses it
		);
	}
	protected function baseUrl( Connection $c ): string { return 'https://api.acme.test/v1'; }
	protected function authStrategy( Connection $c ): AuthStrategy { return new HeaderKeyAuth( 'X-Api-Key', 'api_key' ); }
	protected function identify( Connection $c ): TestResult { /* what Test connection shows */ }
	protected function fieldCatalog( string $type ): array { return FieldCatalogBuilder::make()->group( 'Customer' )->text( 'contact.name', 'Name', required: true )->build(); }
	protected function mappings( string $type ): array { /* version-1 rules, docs/ARCHITECTURE.md */ }
	public function findExisting( Connection $c, Item $item ): ExternalRef|ConnectorError|null { /* search WC-{id} on the server, page through */ }
	public function send( Connection $c, Item $item, array $payload ): SendResult { /* find before create */ }
}
```

What the base gives you for free: `id()`, `label()`, `type()`, `capabilities()` (a flag is true only when listed in `meta()`), `supportedEntities()`, `rateLimitHints()`, `contractVersion()`, `credentialFields()` (from `credentialDefinition()`, which defaults to the fields of the sign-in style), `describeFields()`, `defaultMapping()`, `test()` (calls your `identify()` and turns any failure into a result), `ping()`, empty answers for the lookups, stock, tracking, invoice and reconcile methods of capabilities you did not flag, `listTaxes()` on top of your `listTaxCodes()`, `authStyle()`.

Overridable hooks: `defaultHeaders()`, `vendorError()`, `idempotencyHeader()`, `credentialDefinition()`, `redactionValues()`. Do not define methods named `api`, `connectorMeta` or `listTaxes`.

### Calling the API

```php
$response = $this->api( $connection )->get( '/invoices', array( 'reference' => 'WC-9001', 'cursor' => $cursor ) );
$response = $this->api( $connection )->post( '/invoices', array(), $body, array( 'idempotency_key' => $item->idempotencyKey ) );
$response->json();  $response->status();  $response->header( 'X-Rate' );  $response->body();  $response->retryAfterSeconds();
```

`get/post/put/patch/delete( $path, $query = [], $body = null, $options = [] )`. Options: `format` (`json` default, `form`, `xml`, `raw`), `content_type`, `accept`, `headers`, `timeout` (1 to 30), `allow` (statuses returned as normal answers: `array( 404 )`), `idempotency_key`, `action` (what a 403 refused, for the message). A path may be an absolute `https://` link the other side sent (a next page, a `Location` header). It is accepted only when its scheme, host and port equal the connection's base address, or its host is one of the connector's declared fixed hosts (https, the usual port or the base port); anything else is refused with SYNC-VAL-002 before the request is built, so no credential is ever applied to a request for a stranger's address. A merchant-typed host follows links on its own origin only. The client never retries; the queue does.

The circuit-breaker probe, `ping()`, is one `GET` of `meta()->pingPath` when the connector declares one, and falls back to `identify()` (often four to six calls) when it does not. **Every connector should declare `pingPath` (a cheap read: the account, the current user, one record) or override `ping()`.** A probe that fails for any reason, including a 404 on a wrong path, is `false`.

What it does for you: https and host check (`HostPolicy`), authentication, pacing shared by all workers (`RateLimitHints`; a wait of more than 5 seconds stops with SYNC-RATE-001 and the wait; a daily cap stops until midnight UTC), the 30 second cap, a 5 MB answer cap, JSON/form/XML encoding (`Xml::encode`), `Retry-After` in seconds or as a date, and redaction of keys, tokens, secrets, tenant ids and hosts from every message.

Hosts, fail closed. The connector's `meta()` must declare `hosts` (the vendor's own hosts; a leading `*.` matches subdomains) or `merchantHost: true` (the merchant types the address of a server of their own). A policy with neither allows nothing, in `api()` and in the OAuth sign-in and token requests (`OAuthConfig( allowedHosts: [...] )` or `merchantHost: true`; an empty list no longer means "any public host"). For a merchant-typed host the name is resolved (A and AAAA) and every address must be public: 0/8, 10/8, 100.64/10, 127/8, 169.254/16, 172.16/12, 192.168/16, 198.18/15, multicast and reserved ranges, `::1`, `fc00::/7`, `fe80::/10`, `ff00::/8` and IPv4-mapped, NAT64 and 6to4 addresses (judged by the IPv4 address inside) are refused. Decimal, hexadecimal and octal IP forms (`2130706433`, `0x7f.1`, `0177.0.0.1`, `127.1`), a trailing dot, a zone id and a user name in the address are refused. The filter `estasoft_sync_allow_private_hosts` still lets a site owner allow private hosts. DNS rebinding: a name could be pointed elsewhere between the check and the connection. `HostPolicy` therefore remembers the addresses that passed for 30 seconds (`HostPins`) and the `http_api_curl` action (registered by `ConnectorsModule`) sets `CURLOPT_RESOLVE` for a request to that host and port, so cURL connects to a checked address. This works with the cURL transport that WordPress prefers; with the streams transport the gap remains, and nothing can close it from a plugin. Only hosts that passed this check are pinned; vendor hosts and every other request are left alone. In tests the bootstrap sets a resolver (`HostPolicy::resolveWith()`), so no test needs DNS; pass your own resolver to `new HostPolicy( ..., resolver: fn )` to test a policy.

Default error mapping: 401 SYNC-AUTH-001 (SYNC-AUTH-002 for OAuth after one refresh), 403 SYNC-AUTH-004, 408 and transport failures SYNC-NET-001, certificate failures SYNC-NET-003, 429 SYNC-RATE-001 with the wait, 5xx SYNC-NET-002, anything else SYNC-VAL-002 with the vendor's message cut and cleaned. Refine in `vendorError( ApiResponse ): ?ConnectorError`, matching the vendor's documented error codes (plan gate AUTH-006, unknown app AUTH-007, expired key AUTH-005, duplicate, validation). Return `null` for the default.

Failures are `ApiException` (`->error()` is the `ConnectorError`). Let them propagate: the registry gives out `GuardedConnector`, which turns them into the answers the engine expects. In your own tests call `GuardedConnector::wrap( $connector )`.

### The twelve pitfalls

The list in `vendors-contract.md` (currency, tax-included prices, dates, replays, e-invoicing re-triggers, tenant pinning, server-side search, error order, secrets in messages, odd text, call caps, unfinished records) applies to every connector. The base helps with 6 (declare `redactionValues()`), 7 (paging helpers: `ApiFakeServer::page()` in tests), 8 (the default mapping order), 9 (redaction), 11 (`RateLimitHints`).

## 3. Sign-in styles

| `AuthStyle` | You write | The base does |
|---|---|---|
| `Key`, `Basic` | `HeaderKeyAuth`, `BearerAuth`, `BasicAuth` or `QueryKeyAuth` in `authStrategy()` | adds the credential, redacts it |
| `OAuthAuthorizationCode` (`own_app`) | implement `OAuthConnector` + `OAuthProvider`, return `new OAuth2Auth( $this->oauthProvider() )` | sign-in routes, PKCE, tokens encrypted, refresh under a lock, scheduled refresh, reconnect |
| `OAuthClientCredentials`, `OAuthJwtBearer` (`cloud_app`) | the same without the sign-in page | token request, refresh, the same storage |
| `ServerLogin` | `new SessionAuth( fn( $c ) => $this->login( $c ), cookie: 'B1SESSION' )` | keeps the session id per connection, one re-login on 401 |
| `ExportFile` | extend `AbstractExportConnector` (section 5) | batches, files, the Exports screen |

### OAuth in detail

The merchant registers their own app with the vendor and types its client id and secret; the plugin holds no secret. The redirect address to register is one stable address for the whole site: `OAuthRedirect::uri()` (`https://shop.example/wp-json/estasoft-sync/v1/oauth/callback`). The Connect screen shows it (a read-only credential field named `redirect_uri`, type `display`, added by the base).

```php
public function config( Connection $c ): OAuthConfig {
	return new OAuthConfig(
		authorizeUrl: 'https://login.acme.test/authorize', tokenUrl: 'https://login.acme.test/token',
		scopes: array( 'invoices.write', 'offline_access' ), usePkce: true, clientAuth: 'basic',
		extraAuthorizeParams: array( 'access_type' => 'offline' ),
		refreshTokenLifetimeDays: 60, allowedHosts: array( 'login.acme.test' ),
	);
}
```

Grants: `authorization_code` (default), `client_credentials`, `jwt_bearer`, `password`. `clientAuth` is `basic` (header) or `body`. JWT: `jwtAlgorithm` (RS256 default; RS/PS/ES 256, 384, 512), `jwtMode` (`assertion`: `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion=`, or `client_assertion`: `grant_type=client_credentials&client_assertion_type=...&client_assertion=`), `jwtSubject`, `jwtAudience`, `jwtClaims`, `jwtHeaders` (`kid`), `jwtLifetimeSeconds`; the PEM key is the secret `private_key` (field type `textarea`). Data-centre or tenant specific addresses come from `$connection->config()` inside `config()` and `baseUrl()`.

A token that lives 300 seconds or less is not refreshed on every call: the margin is five minutes, or half the token's life when it lives ten minutes or less (`oauth_lifetime` keeps the last `expires_in`). Only the vendor's own refusal holds a connection: a JSON answer whose OAuth `error` is `invalid_grant`, `invalid_client` or `unauthorized_client` (and `access_denied` when a sign-in code is traded). A firewall, Cloudflare or proxy page (401, 403, 5xx, HTML), an empty body, a JSON answer without an error code, `server_error` and `temporarily_unavailable` are SYNC-NET-002 and are retried; they never change the connection. A 404, 405 or 410 page for the token address means the address is wrong: SYNC-AUTH-001 for a person to look at, and still no hold. The refresh lock lives 90 seconds, longer than the 30 second token request. `OAuthConfig` reads `allowedHosts` or `merchantHost`.

Token errors: `invalid_grant` on a refresh is SYNC-AUTH-002 (the connection becomes `needs_reconnect` and the existing notification fires); on a sign-in code SYNC-AUTH-003; `invalid_client` is SYNC-AUTH-001 (the connection is paused), or SYNC-AUTH-005 when the vendor says the secret expired; 5xx, 429 and time-outs change nothing and are retried. Tokens live in the connection secrets under `oauth_access_token`, `oauth_refresh_token`, `oauth_expires_at`, `oauth_refresh_expires_at`. `credential_expires_at` follows the refresh token (from the answer, else `refreshTokenLifetimeDays`), so the existing key-expiry warning works. The scheduled task `estasoft_sync_refresh_tokens` (every 5 minutes) refreshes tokens that expire within 10 minutes.

The extra values of the vendor's redirect (QuickBooks `realmId`, MYOB `businessId`) are saved under `$connection->config( 'oauth_callback' )` before `organisations()` is called. `OAuthConfig( minTokenAgeSeconds: 570 )` is for a vendor that refuses a refresh before the token is that old (Exact Online refuses before 570 seconds for its 600 second tokens): the time to refresh is the later of "the margin before expiry" and "the minimum age", a token younger than that is used as it is until it runs out, a 401 before that age is not answered with a refresh (`OAuth2Auth::onUnauthorized()` returns false, `OAuthTokenManager::mayRefreshNow()` says so; `refreshNow()` hands back the token in hand), and the scheduled task leaves it alone. A connector with its own 401 handler should ask `mayRefreshNow()` first. `OAuthConfig( refreshOnDemand: true )` suits tokens that live minutes (the scheduled task then only keeps the refresh token alive). A connector that can also connect without a sign-in page (a signed token) defines `requiresSignIn( Connection ): bool`; when it says false, `POST /connections` tests and activates it at once. `limitsFor( Connection )` gives plan-dependent rate caps.

`organisations( Connection ): array` returns `array{id,name}[]` (the tenants the signed-in person can use; a vendor without that idea returns one entry); `selectOrganisation( Connection, $id ): array` returns the config keys to store (`tenant_id`, ...).

### The flow the screens follow (REST)

1. `POST /connections` `{ connector, label?, config: {client_id, data_center, ...}, secrets: {client_secret, ...} }`. For a sign-in connector the connection is saved with status `needs_reconnect` and the vendor is not called; the answer is the `Connection`. For a key, client-credentials or JWT connector the connection is tested first (for OAuth: token request, then `identify()`) and saved only when the test passes.
2. `POST /connections/{id}/oauth/start` answers `{ url }`. The browser goes to the vendor.
3. The vendor sends the browser to `GET /oauth/callback?state=&code=` (the stable address; the older `GET /connections/{id}/oauth/callback` still works). The answer is always a 302 to the admin screen: `#/connections/{id}?signin=ok|cancelled|failed&connection={id}`. A state that is unknown, used, expired or of a person who may no longer configure ends in `#/connections?signin=failed`.
4. `GET /connections/{id}/oauth/organisations` answers `[{ id, name }]`.
5. `POST /connections/{id}/oauth/organisation` `{ id }` stores the choice, runs `identify()` and, when it passes, makes the connection active. It answers the `Connection`. A person who reconnects an expired sign-in (an organisation was chosen before) is activated at step 3 without being asked again.

`CredentialFieldDef` (what the screens read to draw the form): `name, label, secret, required, hint, default, type (text|password|select|textarea|url|number|display), options (value => label), group (connection|app|advanced)`.

### Server-login sessions

`SessionAuth` logs in under a lock per connection (`session:{id}`, 90 seconds): workers that need a session together wait for the one that logs in and use its session. A 401 refers to the session that request sent; when another worker has replaced it meanwhile, the newer session is used and nobody is logged out. Pass `lock:` and `sleep:` in tests; by default the plugin's database lock is used when there is a database.

Header values never carry a line break (`ApiRequest::withHeader()` and the client remove CR, LF and NUL), and secrets are trimmed when they are saved.

## 4. Taxes, mapping and fields

`fieldCatalog( 'orders' )` and friends return `FieldDef` lists (`FieldCatalogBuilder::make()->group( 'Customer' )->text( 'contact.name', 'Name', required: true )->sku()->taxCode()->money()->build()`). `mappings()` returns version-1 rules in the rule model of `docs/ARCHITECTURE.md`. Every default rule must target a path from the catalog (the contract test checks it). `listTaxCodes( Connection ): array{code,name,rate_percent}[]` feeds the tax mapping screen.

## 5. Export connectors (a file the merchant imports)

```php
final class AcmeLedgerExportConnector extends AbstractExportConnector {
	protected function meta(): ConnectorMeta { /* auth: AuthStyle::ExportFile, hosts: [] */ }
	public function exportFormat(): ExportFormat { return ExportFormat::csv( 'Acme Ledger CSV import', bom: true ); }
	public function appendOrder( ExportBatch $batch, Item $item, array $payload ): void {
		$batch->add( $item->id, $item->entityId, $row );                      // or:
		$batch->leaveOut( $item->id, $item->entityId, 'no ledger code for ' . $sku );  // left out of the file, listed to the person
	}
	public function finish( ExportBatch $batch ): ExportFile {
		return new ExportFile( contents: $text, format: $this->exportFormat() );   // use $batch->included()
	}
}
```

`send()` (provided) adds the order (or the refund, when `meta()` declares `EntityType::Refund`) to the open batch of the connection and reports it created (reference "Waiting for export"); a retry of the same item is found, not added twice. The batch handed to `appendOrder()` holds only that order, and `$batch->connection()` is the connection (company name, ledger names and account numbers from its config).

For a big file read the rows with `$batch->iterate()` (one at a time) and write to a stream: `$work = $this->deps->exports->workFile(); ... return ExportFile::fromPath( $work, $this->exportFormat() );`. A format whose bytes the connector encodes itself (Windows-1252, UTF-16LE) sets `rawBytes: true`: `new ExportFormat( 'csv', 'text/csv; charset=windows-1252', 'DATEV', false, 'windows-1252', true )`. A second file that goes with the first (a list of customers and items to import first) goes in `ExportFile( attachments: [ [ 'name' => 'Masters', 'filename' => 'masters.csv', 'mime' => 'text/csv; charset=utf-8', 'contents' => $text ] ] )`. When the person presses Create export (or `POST /exports`), `finish()` builds the file; the queue items of the included orders get the file name as their reference; an order that was left out is marked failed with SYNC-EXP-002 and appears under Needs attention (mapping the missing code and retrying puts it in the next file). SYNC-EXP-001 is raised when the folder cannot be written.

Orders sent while a file is being built are not lost: `create()` closes only the orders the file dealt with (included or reported as left out); an order added after the snapshot waits for the next file. Write every delimited file with `ExportFormat::writer()` (`CsvWriter`): it quotes and doubles quotes correctly, removes NUL bytes, uses the format's delimiter (`ExportFormat::csv( 'DATEV', delimiter: ';' )`) and neutralises a text that starts with `=`, `+`, `-`, `@`, a tab or a carriage return (a customer name that a spreadsheet would run as a formula), unless it is a plain number such as `-12.50`. Modes: `CsvWriter::QUOTE` (default, a leading single quote), `SPACE` (a leading space, for importers that would show the quote; use it for DATEV and Sage 50), `STRIP`, `NONE` (only for a column that holds a number or a code). `CsvWriter::encode( $text, 'windows-1252' )` converts the finished text. XML formats escape text and remove the control characters XML 1.0 forbids (`XmlBuilder::escape()`).

Files live in `wp-content/uploads/estasoft-sync/exports/{random 32 hex}/files/{connection}-{Ymd-His}-{random}.{ext}`; every folder has `index.php`, `.htaccess` and `web.config` that deny access; the web server never serves them; they are downloaded only through the REST route below. Files are kept 30 days (the daily clean-up removes them). The folder is inside `estasoft-sync`, which `Core\DataEraser` removes on uninstall.

### Export REST shapes

| Route | Capability | Answer |
|---|---|---|
| `GET /exports?connection_id=` | manage | `{ files: ExportFileInfo[], open_batch: { connection_id, orders, since } \| null }` |
| `POST /exports` `{ connection_id }` | configure | `201 ExportFileInfo`; 409 `SYNC-VAL-002` (nothing waits, or a connection that makes no files); 422 `SYNC-EXP-002` (every order left out); 500 `SYNC-EXP-001` |
| `GET /exports/{id}/download?part=` | manage | the file, `Content-Disposition: attachment`, the format's content type, `nosniff`; 404 when gone |
| `DELETE /exports/{id}` | configure | `{ deleted: true }` |

`ExportFileInfo = { id, connection_id, filename, size_bytes, orders, created_at, expires_at, created_by, format_label, left_out: [{ order, reason }], attachments: [{ name, filename, size_bytes }] }` (times are ISO 8601, UTC). `GET /exports/{id}/download?part=1` downloads the first attachment. The types are in `src/api/types.ts`.

## 6. Catalog

`GET /catalog` rows: `name, type, regions, connector, connection_method, needs, available, unavailable_reason, verified, variants, hosts, terms_url, privacy_url, developer_url, steps, wave, credential_fields, can_sign_in, auth_style`. The last three are built from your connector at request time (`credentialFields()`, whether it is a sign-in connector, `AuthStyle`) so the Connect screen draws without a second request; they are the same on every `variants` entry, which has its own connector. A system that is not available has no fields; its `unavailable_reason` (empty when the document gives none) says why, apart from the hand-written `needs`, which stays as written. An entry of `hosts` that starts with `*.` is a pattern. A secret field never carries a default, so do not rely on one. `available` is computed: the connector is registered and the document does not say `available: false`. `variants` is `[{ id, label, available, connection_method?, hosts?, terms_url?, privacy_url?, developer_url?, steps? }]` for the two rows that hold several products. You never edit `includes/Catalog/catalog.php`: run `php bin/generate-catalog-from-docs.php` after writing the front matter of your document (it also rewrites `docs/connectors/README.md`; `--check` fails when either file is out of date, `--strict` also fails on a broken step rule).

## 7. Tests

```php
final class AcmeConnectorContractTest extends ApiConnectorContractTestCase {
	protected function setUp(): void { parent::setUp(); $this->vendor = new FakeAcmeVendor( $this->kit->http ); }
	protected function apiConnector(): AbstractApiConnector { return new AcmeConnector( $this->kit->deps ); }
	protected function workingConnection(): Connection { ... }   protected function brokenConnection(): Connection { ... }
	protected function orderFixture( string $orderId, array $options = array() ): array { ... }
	protected function failNextWrite( string $kind, int $seconds = 0 ): void { ... }
	protected function secretValues(): array { ... }
}
```

- `Tests\Support\ApiTestKit::make()` gives `http` (FakeHttpClient), `clock`, `storage` and `lock` (in memory), `tokens` (a real OAuthTokenManager), `exports` (a temporary folder), `pacer` (records its sleeps in `$kit->slept`), `deps`, and `connection()` / `unsaved()` helpers. `ApiTestKit::make( $provider )` lets the token manager find a provider.
- `Tests\Support\ApiFakeServer::on( $kit->http, 'api.acme.test' )->route( 'POST', '/v1/invoices/{id}', fn( ApiFakeRequest $r ) => array(...) )`: a handler returns an `HttpResponse`, an array (200 JSON) or null (404). `failNext()` scripts failures; `requests()` and `last()` record; `ApiFakeServer::page()` pages a list. `ApiFakeTokens` is a fake OAuth token endpoint (codes, rotating refresh tokens, `expireAccessTokens()`, `revoke()`) with `check( $request )` for the API side.
- `ApiConnectorContractTestCase` extends the connector contract tests, wraps your connector in `GuardedConnector`, and adds: the id uses only `[a-z0-9-]`; secret fields are marked secret; the connector declares its hosts (or `merchantHost`); fields have a valid type and group; a wrong key answers SYNC-AUTH-001 on every method of a capability that is flagged.
- Add your own tests for: every send, lookup and fetch path; each vendor error shape; pagination; token refresh; secrets absent from errors and logs; Arabic text; money with the right exponent (KWD three decimals) and no floats; find-before-create; tax-inclusive versus exclusive; multi-currency; refunds as credit notes.
- Never use the network. Fixtures contain synthetic data and follow the shapes in the vendor's documentation (cite the page in the document).

## 8. The document

`docs/connectors/{id}.md` starts with front matter (YAML subset: scalars, quoted text, `[a, b]` lists, `[{ id: x, label: "Y" }]` maps, `#` comments):

```
---
id: acme
name: Acme Books
catalog_row: Acme Books          # exact name of the catalog row
connection_method: own_app       # key | own_app | cloud_app | server_login | export_file | none
available: true                  # false only when the connector cannot be chosen
unavailable_reason: ...          # with available: false: why (one sentence); the screen shows it beside the hand-written `needs`
verified: false                  # always false: no live account test
hosts: [api.acme.test, "*.acme-cloud.test"]   # every host the plugin contacts (empty for a merchant-typed address); "*." is a pattern: any subdomain
terms_url: https://...
privacy_url: https://...
developer_url: https://...       # where the merchant makes an app or a key
variants: []                     # only for rows that hold several products
---
```

The sections of `vendors-contract.md` follow (Sources, **How the merchant connects**, Authentication, Data sent and when, Objects and field mapping, Duplicate protection, Taxes, Payments, Refunds, Read-back, Rate limits, Error mapping, Capabilities, Not verified, Manual smoke test). The numbered list under "How the merchant connects" becomes `steps` of the catalog: **at most 6 steps, each 140 characters or fewer**; a step may use `` `code` `` for values to copy. The generator warns when a document breaks the rule. It also warns when a `hosts` entry is not a host name or a clean pattern (a pattern is `*.` followed by a domain of two labels, such as `*.oraclecloud.com`; not `*.com`, not `a.*.com`, no scheme, path or port) and when a document says `available: false` without an `unavailable_reason`. Hosts are written to the catalog in lower case and a pattern keeps its `*.`, so the screens can tell it from a single host.

## 9. Checks before you report

```
php -l <files>   phpunit -c phpunit.xml.dist --filter Connectors   php bin/red-flags.php   php bin/check-php-version.php
bash /home/claude/tools/phpcs.sh includes/Connectors/Acme      bash /home/claude/tools/phpstan.sh includes/Connectors/Acme
php bin/generate-connector-index.php --check     php bin/generate-catalog-from-docs.php --check
```

Network code goes through `Contract\HttpClientInterface` only. `wp_remote_*` outside `WpHttpClient` fails the red-flag scan.
