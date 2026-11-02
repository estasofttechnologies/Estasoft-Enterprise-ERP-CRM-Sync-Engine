# Contributing

Thank you for helping. This plugin handles orders, customer data and credentials for real shops, so the rules
below are strict on purpose. Please read the [Code of Conduct](CODE_OF_CONDUCT.md) first. Report security
problems privately, as described in [SECURITY.md](SECURITY.md), never in a public issue.

## Ground rules

These come from the plugin's promise to merchants and from the WordPress.org plugin guidelines. A pull request
that breaks one will not be merged.

- **No telemetry and no outside calls.** The plugin contacts only the system the merchant connected, and only
  after the merchant entered its credentials. No analytics, no update server, no relay.
- **All features free.** No licence key, tier, trial, quota or upsell code.
- **No obfuscation and no run-time code.** No `eval`, `base64_decode` of code, `extract()`, `@` suppression,
  `error_reporting()` or `ini_set()`. Nothing downloads or runs code from elsewhere.
- **Secrets stay secret.** Never in options, logs, REST responses, exceptions, fixtures or screenshots.
- **GPL-2.0-or-later compatible only.** Any code, image or data you add must be under a compatible licence.
- **Nothing runs when a file loads** except `defined( 'ABSPATH' ) || exit;` and declarations.
- **Commits carry no co-author or tool attribution trailers.**

## Set up

You need PHP 8.1 or later (with `sodium`, `mysqli`, `mbstring`, `intl`), [Composer](https://getcomposer.org/) 2,
Node.js 22 with npm, and `rsync`, `zip` and `unzip` for packaging. For the integration tests you also need MySQL 8
or MariaDB and a copy of WordPress. `shellcheck`, `actionlint` and WP-CLI are optional.

```bash
git clone https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine.git
cd estasoft-enterprise-erp-crm-sync-engine
composer install
npm ci
npm run build
```

To try it in WordPress, link or copy the folder into `wp-content/plugins/`, activate WooCommerce 8.2 or later and
then the plugin, and add the line the setup screen shows to `wp-config.php`:
`define( 'ESTASOFT_SYNC_KEY', '<a long random value>' );`

`npm start` rebuilds the admin app when you change `src/`.

## Run the checks

`bin/check-all.sh` runs everything that can run on your machine and lists what it skipped. CI runs the full set.

| What | Command |
| --- | --- |
| Everything possible locally | `bin/check-all.sh` (`--fast` skips integration tests and the JS build) |
| PHP syntax and the reviewer red-flag scan | `bin/lint-php.sh` |
| Coding standards | `composer lint` (fix what can be fixed: `composer lint:fix`) |
| Static analysis | `composer analyse` |
| PHP unit tests (no WordPress, no database) | `composer test` |
| PHP integration tests | see below |
| Admin app | `npm run lint:js`, `npm run lint:css`, `npm run typecheck`, `npm run test:js`, `npm run build` |
| readme.txt against the WordPress.org rules | `php bin/check-readme.php` |
| Documentation links and the document index | `php bin/check-docs.php` |
| `.gitattributes` and `.distignore` agree | `bin/check-dist-parity.sh` |
| Release zip (file selection only) | `bin/build-zip.sh --dry-run` |
| Release zip | `bin/build-zip.sh` |

### Integration tests

They run inside a real WordPress against a real database, each test in a transaction that is rolled back.
`php bin/install-test-wp.php` **drops and recreates** the database named by `WP_DB_NAME`, so never point it at a
database you care about.

```bash
bin/install-wp-core.sh latest /tmp/wordpress      # or 6.4, the minimum supported version
export WP_CORE_DIR=/tmp/wordpress WP_TEST_CONTENT=/tmp/wp-content
export WP_DB_NAME=estasoft_test WP_DB_USER=root WP_DB_PASSWORD=secret WP_DB_HOST=127.0.0.1
php bin/install-test-wp.php
vendor/bin/phpunit -c phpunit-integration.xml.dist
```

WooCommerce itself is not installed in the tests. `tests/php/stubs/woocommerce` is a minimal stand-in, and code
that needs `WC_Order` and friends is written against thin adapters and tested with fakes.
See [tests/README.md](tests/README.md).

## Coding standards

**PHP**

- PHP 8.1 syntax only. Every file starts with `<?php`, a docblock with `@package Estasoft\SyncEngine`,
  `declare( strict_types=1 );`, and in `includes/` the `defined( 'ABSPATH' ) || exit;` guard.
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
  as configured in `phpcs.xml.dist`: tabs, spaces inside parentheses, Yoda conditions. Namespaces follow the
  folders (`Estasoft\SyncEngine\Core\Queue`), one class per file, the file name is the class name.
- Small, single-purpose classes. Dependencies come in through the constructor. No static state, no globals.
- Docblocks on every class and public method: purpose, parameters, return value, what it throws.
- Sanitise early, escape late. Use `$wpdb->prepare()` for every query with a variable. Check a nonce and a
  capability (`Capabilities::MANAGE` or `Capabilities::CONFIGURE`) on every state-changing path.
- Money is an integer in minor units. Times are UTC strings. Text is utf8mb4.
- Every visible string goes through `__()`, `_n()` or a sibling with the text domain
  `estasoft-erp-crm-sync-engine`. Use placeholders, never concatenation, and add a `/* translators: */` comment.

**TypeScript and CSS**

- Strict TypeScript, no `any`. `src/api/types.ts` is the contract with the PHP REST controllers; change it in the
  same pull request as the controller.
- Use WordPress components. CSS classes start with `estasoft-sync-`; use logical properties so right-to-left
  works; keep 44 px touch targets on small screens and respect `prefers-reduced-motion`.
- Status is always an icon and a word, never colour alone.

## Tests

- Bug fix: add a test that fails without the fix.
- New behaviour: unit tests first (`tests/php/Unit/<Area>/<Name>Test.php`); integration tests
  (`tests/php/Integration/<Area>/<Name>Test.php`) when the code touches WordPress or the database.
- Assert behaviour, not implementation. Never sleep in a test; inject a `Clock`.
- JavaScript tests live in `tests/js` and run with Jest through `wp-scripts`.

## Commit messages

- One logical change per commit.
- A short subject in the imperative, 72 characters or fewer: `Retry a held item after the breaker closes`.
- A body that says **why**, not what the diff already shows. Reference an issue (`Fixes #123`) when there is one.
- No `WIP`, no merge commits in a pull request, no co-author or tool attribution trailers.

## Pull requests

1. Fork, branch from `main`, keep the branch focused.
2. Fill in the pull request template. Its checklist is the definition of done.
3. CI must be green: PHP lint (8.1 to 8.4), PHPCS, PHPStan, unit tests, integration tests (WordPress 6.4 and
   latest), the admin app checks, the release zip build and Plugin Check.
4. Update `docs/`, `readme.txt` (Features or FAQ, if what the plugin does changed) and `CHANGELOG.md` under
   `[Unreleased]`.

Maintainers cut releases with `bin/bump-version.sh`, then a `v<version>` tag; see [docs/release/CHECKLIST.md](docs/release/CHECKLIST.md).

## Build a connector

The setup screen lists 34 systems and version 0.1.0 can connect to Odoo only. Other developers are welcome to
build connectors, either as a pull request against this repository or as a separate add-on plugin.
Start with the [New connector request](https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/issues/new?template=new_connector_request.yml)
form so nobody builds the same thing twice.

1. **Read the contract.** [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) explains the seams and the canonical
   order model; `includes/Contract/ConnectorInterface.php` is the interface (contract version 1). Read
   [docs/spec/connection-methods-spec.md](docs/spec/connection-methods-spec.md) for the five ways a system can
   connect and the order of checks `test()` must run, and [docs/error-catalog.md](docs/error-catalog.md) for the
   error codes your failures map to.
2. **Research the vendor's real API.** `docs/research/` records what each of the 34 vendors documents, tagged as
   documented, third-party or inferred. Confirm against the vendor's current documentation and record what you
   verified, with links, in `docs/connectors/<id>.md` (see the Odoo connector for the pattern).
3. **Implement `ConnectorInterface`.**
   - Declare only the capabilities you really have. Methods for a capability you do not declare are never called.
   - Send nothing except through `HttpClientInterface`; never call `wp_remote_*` directly. Honour the 30 second
     timeout and `rateLimitHints()`.
   - Look the record up by the client reference `WC-{order id}` in `findExisting()` before anything is created.
   - Return `SendResult` values, classify failures into the catalog's error codes, and keep secrets out of every
     message.
4. **Register it** with the filter, from your own plugin or from `Connectors\ConnectorsModule`:

   ```php
   add_filter(
   	'estasoft_sync_register_connectors',
   	static function ( array $connectors ): array {
   		$connectors[] = new \Your_Prefix\Your_Connector( /* your dependencies */ );
   		return $connectors;
   	}
   );
   ```

   The core refuses a connector whose contract major version differs from its own.
5. **Test it.** Extend the shared contract test case and use the fake HTTP client in `tests/php/` so the
   connector is checked against the rules every connector must follow: same key, same reference; unknown errors
   classified; secrets never in output; Arabic text and money by currency exponent round-trip.
6. **Trademarks and logos.** Use the vendor's name as text only. Do not add logos or artwork unless the vendor's
   brand terms allow it, and say where the permission is recorded.
7. **Catalog.** `includes/Catalog/catalog.php` marks a system available only when a connector for it ships in
   the same release.

## Questions

Open a discussion or an issue. For help using the plugin, the WordPress.org support forum is the place once the
plugin is listed there.
