# Estasoft Enterprise ERP/CRM Sync Engine

[![CI](https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/actions/workflows/ci.yml/badge.svg)](https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/actions/workflows/ci.yml)

A free WordPress plugin that keeps WooCommerce in step with your ERP, accounting system or CRM. Orders,
customers and refunds go out through a durable queue, so checkout never waits for the other system; stock and
delivery tracking come back. Inside WordPress the plugin is labelled **ESTASOFT SYNC**.

- Every order event is written to a queue in the order's own database transaction. A background worker delivers
  it with retries, a circuit breaker and a rate limiter, and looks the order up by its reference (`WC-{id}`) before
  creating anything, so retries do not make duplicates.
- You see what will be sent before anything is sent: mapping rules are readable, versioned, previewable and can be
  rolled back. Shadow mode runs the whole process without changing anything in either system.
- No telemetry, no Estasoft server, no licence or upsell code. The plugin contacts only the system you connect.
- Credentials are encrypted with libsodium; the key lives in `wp-config.php`.

## Status

Pre-release, version 0.1.0. Not yet on the WordPress.org plugin directory; the submission is being prepared
(see [docs/release/CHECKLIST.md](docs/release/CHECKLIST.md)).

Phase 1 is the engine, the **Odoo** connector and every admin screen. Odoo 17 and 18 connect through JSON-RPC,
Odoo 19 and later through JSON-2. The setup screen lists 34 accounting, ERP and CRM systems; connectors for the
other 33 are planned and are not part of this version.

## Requirements

- WordPress 6.4 or later, PHP 8.1 or later (with the `sodium` extension), WooCommerce 8.2 or later
- A database that meets your WordPress version's requirements (MySQL or MariaDB, utf8mb4)
- A system to connect to. For Odoo: an API key (Odoo Online needs the Custom plan to allow API access)

## Install

**From a release.** Download `estasoft-erp-crm-sync-engine-<version>.zip` from the
[releases page](https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/releases), then
in WordPress choose Plugins > Add New > Upload Plugin. Activate WooCommerce first. Add the encryption line that the
setup screen shows to `wp-config.php`, then open WooCommerce > ESTASOFT SYNC.

**From source.** The compiled admin app (`build/`) is not committed, so build it:

```bash
git clone https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine.git
cd estasoft-enterprise-erp-crm-sync-engine
npm ci && npm run build
bin/build-zip.sh          # writes dist/estasoft-erp-crm-sync-engine-<version>.zip
```

## Development

```bash
composer install          # PHPUnit, PHPCS (WordPress standard), PHPStan
npm ci                    # the admin app and its tooling
npm start                 # rebuild the admin app when src/ changes
bin/check-all.sh          # everything that can run on this machine; lists what it skipped
```

| Task | Command |
| --- | --- |
| PHP unit tests | `composer test` |
| PHP integration tests (real WordPress and database) | `php bin/install-test-wp.php && vendor/bin/phpunit -c phpunit-integration.xml.dist` |
| Coding standards, static analysis | `composer lint`, `composer analyse` |
| Admin app | `npm run lint:js`, `npm run lint:css`, `npm run typecheck`, `npm run test:js`, `npm run build` |
| Release zip | `bin/build-zip.sh` (`--dry-run` shows the file list only) |
| Version bump | `bin/bump-version.sh 0.2.0` |

Setup, standards, the integration-test environment and the pull request checklist are in
[CONTRIBUTING.md](CONTRIBUTING.md) and [tests/README.md](tests/README.md).

## Architecture and connectors

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): layout, the seams between modules, the queue rules, the mapping
  rule model and the canonical order model.
- **Connector contract:** `includes/Contract/ConnectorInterface.php` (contract version 1) and the
  `estasoft_sync_register_connectors` filter. "Build a connector" in [CONTRIBUTING.md](CONTRIBUTING.md) walks
  through it.
- [docs/error-catalog.md](docs/error-catalog.md): the thirty `SYNC-...` error codes.
- [docs/spec/README.md](docs/spec/README.md): the index of all documents and which one wins when they disagree.

## Roadmap

No dates are promised. A connector ships only when it has been tested against a real account of that system.

1. **Phase 1 (this release):** the engine, the Odoo connector, and all admin screens.
2. **Phase 2:** connectors for the other systems on the list, starting with those that connect with a key and have
   clear documentation. Systems with no web API (TallyPrime, DATEV, Sage 50) get export files written inside
   WordPress, with no helper program.
3. **Later:** more systems and community connectors through the connector contract. Whether to offer an optional
   sign-in relay is undecided; the first release has none.

Ask for a system with the
[New connector request](https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/issues/new?template=new_connector_request.yml)
form.

## Security

Please report vulnerabilities privately through GitHub, as described in [SECURITY.md](SECURITY.md).

## Licence and trademarks

GPL-2.0-or-later. See [LICENSE](LICENSE). WooCommerce is a trademark of Automattic Inc. Odoo is a trademark of
Odoo S.A. All other product names belong to their owners. They are named here only to say which systems the
plugin works with. This project is independent and is not made, endorsed or sponsored by any of them.
