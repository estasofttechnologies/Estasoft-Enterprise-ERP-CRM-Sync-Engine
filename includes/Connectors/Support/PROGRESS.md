# V0 progress

- Step 1 (signatures): done. Public names of the contract are in `includes/Connectors/Support/`. Read CHANGES.md first.
- Step 2 (provider discovery): done. `php bin/generate-connector-index.php` writes `includes/Connectors/providers.php` (a provider that does not load yet is skipped with a message: run it again when your connector compiles). `bin/lint-php.sh` fails CI when the file is out of date. `ConnectorsModule` registers every provider lazily; `ConnectorRegistry` accepts a Closure per id.
- Step 3 (OAuth 2 engine): done and tested. Grants authorization_code (PKCE), refresh_token, client_credentials, jwt_bearer (RS256, PS256, ES256 families), password; refresh under a database lock; 5-minute margin; `credential_expires_at`; scheduled refresh every 5 minutes; sign-in routes for any `OAuthConnector` (stable redirect address `OAuthRedirect::uri()`, `POST /connections/{id}/oauth/organisation`).
- Step 4 (server login): done. `Auth\SessionAuth` (cookie, header or query session; one re-login on 401).
- Step 5 (export engine): done and tested. `Export\*`, REST `/exports`, files in `uploads/estasoft-sync/exports/{random32}/`.
- Step 5b (API client): done. Pacing (shared across workers), Retry-After, redaction, 5 MB cap, JSON/XML/form, allow list, idempotency header, 30 s cap.
- Test helpers: `Tests\Support\ApiTestKit::make()`, `ApiFakeServer`, `ApiFakeTokens`, `Tests\Contract\ApiConnectorContractTestCase`; working examples in `tests/php/Unit/Connectors/Support/ReferenceConnectors/` (key, OAuth, export).
- Step 6 (catalog generator): done. `bin/generate-catalog-from-docs.php` (front matter incl. `developer_url`, `steps` from "How the merchant connects", max 6 of 140 characters, warns on violation; README index; `--check` in `bin/lint-php.sh`).
- Step 7 (REST and UI contract): done. Stable id-less `GET /oauth/callback`, `POST /connections/{id}/oauth/organisation`, `needs_reconnect` on create for sign-in connectors, `CredentialFieldDef` with `type`, `options`, `group`, catalog row fields, exports shapes in `src/api/types.ts`.
- Step 8 (BUILDING.md, README.md): done.
- Late requests (V1, V2, V8): done, see CHANGES.md items 16 and 17 and REQUESTS.md. Lazy `ExportBatch::iterate()`, `ExportFile::fromPath()`, attachments, `hasItem()`, Refund, `oauth_callback`, `refreshOnDemand`, `limitsFor()`, `requiresSignIn()`.
- Catalog forms and 12 review fixes (2026-11-02): done, CHANGES.md items 18 to 20 (the list of CSV writers V8 must switch is in item 20).
- Review-fix batch from briefs/review-fixes/v0.md (2026-11-02): done, CHANGES.md item 21. `OAuthConfig::$minTokenAgeSeconds` (for V7), same-origin rule for absolute links in `ApiClient`, `ConnectorMeta::$pingPath` and the single-GET `ping()`, `unavailable_reason` in the catalog, wildcard hosts as patterns in the generator.
- Last full run (2026-11-02): unit 5,161 tests green, integration 982 tests green; php -l, red-flags, PHP 8.1 scan, tsc, generators `--check` and `--strict` clean; phpcs clean for Support, Catalog, Jobs/Connections; phpstan reports nothing new in V0 files.
