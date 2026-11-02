# Estasoft Enterprise ERP/CRM Sync Engine

[![CI](https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/actions/workflows/ci.yml/badge.svg)](https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/actions/workflows/ci.yml)

A free WordPress plugin that keeps WooCommerce in step with your ERP, accounting system or CRM. Orders,
customers and refunds go out through a durable queue, so checkout never waits for the other system; stock,
delivery tracking and invoice status come back. Inside WordPress the plugin is labelled **ESTASOFT SYNC**.

- Every order event is written to a queue in the order's own database transaction. A background worker delivers
  it with retries, a circuit breaker and a rate limiter, and looks the order up by its reference (`WC-{id}`) before
  creating anything, so retries do not make duplicates.
- You see what will be sent before anything is sent: mapping rules are readable, versioned, previewable and can be
  rolled back. **Shadow mode** (a dry run, seven days by default) runs the whole process without changing anything
  in either system.
- No telemetry, no Estasoft server, no licence or upsell code. The plugin contacts only the system you connect, and
  only after you have entered its credentials ([External services](docs/release/EXTERNAL-SERVICES.md)).
- Credentials are encrypted with libsodium; the key lives in `wp-config.php`.
- It never signs, issues or certifies a tax invoice. The connected system does that.

## Status

Pre-release, version 0.1.0. Not yet on the WordPress.org plugin directory; the submission is being prepared
([docs/release/CHECKLIST.md](docs/release/CHECKLIST.md), and what only the owner can do:
[docs/release/OWNER-ACTIONS.md](docs/release/OWNER-ACTIONS.md)).

The engine, every admin screen and **35 connectors** are in this version (the setup screen lists 34 systems, because
the Sage entries hold several products). **No connector has been tried with a live vendor account yet.** Each was built
from the vendor's public documentation and tested against a simulated copy of its API; each document in
[docs/connectors/](docs/connectors/README.md) lists what is not verified and ends with the manual smoke test to run
on a real account. Use Shadow mode first. A connector only runs when a merchant sets it up.

### Supported systems

<!-- supported-systems:start -->
| System | Type | How it connects | Not yet tested with a live account |
|---|---|---|---|
| [Acumatica](docs/connectors/acumatica.md) | ERP | Your own app and sign-in page | Yes |
| [Dynamics 365 Finance and Supply Chain](docs/connectors/dynamics-finance.md) | ERP | Cloud app (no sign-in page) | Yes |
| [ERPNext](docs/connectors/erpnext.md) | ERP | API key or login | Yes |
| [Holded](docs/connectors/holded.md) | ERP | API key or login | Yes |
| [Microsoft Dynamics 365 Business Central](docs/connectors/business-central.md) | ERP | Cloud app (no sign-in page) | Yes |
| [NetSuite](docs/connectors/netsuite.md) | ERP | Cloud app (no sign-in page) | Yes |
| [Odoo](docs/connectors/odoo.md) | ERP | API key or login | Yes |
| [Oracle Fusion Cloud ERP](docs/connectors/oracle-fusion.md) | ERP | Cloud app (no sign-in page) | Yes |
| [Sage 200](docs/connectors/sage200.md) | ERP | Your own app and sign-in page | Yes |
| [Sage 300](docs/connectors/sage300.md) | ERP | Server login | Yes |
| [Sage X3](docs/connectors/sagex3.md) | ERP | See the document | Yes |
| [SAP Business One](docs/connectors/sap-b1.md) | ERP | Server login | Yes |
| [SAP S/4HANA](docs/connectors/sap-s4hana.md) | ERP | Cloud app (no sign-in page) | Yes |
| [Daftra](docs/connectors/daftra.md) | Accounting | API key or login | Yes |
| [DATEV](docs/connectors/datev-export.md) | Accounting | File you import | Yes |
| [Exact Online](docs/connectors/exact-online.md) | Accounting | Your own app and sign-in page | Yes |
| [Fortnox](docs/connectors/fortnox.md) | Accounting | Your own app and sign-in page | Yes |
| [Lexware Office](docs/connectors/lexware-office.md) | Accounting | API key or login | Yes |
| [Moneybird](docs/connectors/moneybird.md) | Accounting | API key or login | Yes |
| [MYOB](docs/connectors/myob.md) | Accounting | Your own app and sign-in page | Yes |
| [Pennylane](docs/connectors/pennylane.md) | Accounting | Cannot be selected yet | Not available yet |
| [Qoyod](docs/connectors/qoyod.md) | Accounting | API key or login | Yes |
| [QuickBooks Online](docs/connectors/quickbooks.md) | Accounting | Your own app and sign-in page | Yes |
| [Sage 50](docs/connectors/sage50-export.md) | Accounting | File you import | Yes |
| [Sage Business Cloud Accounting](docs/connectors/sage-accounting.md) | Accounting | Your own app and sign-in page | Yes |
| [sevDesk](docs/connectors/sevdesk.md) | Accounting | API key or login | Yes |
| [SMACC](docs/connectors/smacc.md) | Accounting | Cannot be selected yet | Not available yet |
| [TallyPrime](docs/connectors/tallyprime-export.md) | Accounting | File you import | Yes |
| [Wafeq](docs/connectors/wafeq.md) | Accounting | API key or login | Yes |
| [Xero](docs/connectors/xero.md) | Accounting | Your own app and sign-in page | Yes |
| [Zoho Books](docs/connectors/zoho-books.md) | Accounting | Your own app and sign-in page | Yes |
| [Dynamics 365 Sales](docs/connectors/dynamics-sales.md) | CRM | Cloud app (no sign-in page) | Yes |
| [HubSpot](docs/connectors/hubspot.md) | CRM | API key or login | Yes |
| [Pipedrive](docs/connectors/pipedrive.md) | CRM | API key or login | Yes |
| [Salesforce](docs/connectors/salesforce.md) | CRM | Your own app and sign-in page | Yes |
| [SuiteCRM](docs/connectors/suitecrm.md) | CRM | API key or login | Yes |
| [Zoho CRM](docs/connectors/zoho-crm.md) | CRM | Your own app and sign-in page | Yes |
<!-- supported-systems:end -->

"Cannot be selected yet" means the system is listed on the setup screen with the reason, and the connector does
nothing. The table is generated from the connector documents (`php bin/generate-external-services.php`).

## Requirements

- WordPress 6.4 or later, PHP 8.1 or later (with the `sodium` extension), WooCommerce 8.2 or later
- A database that meets your WordPress version's requirements (MySQL or MariaDB, utf8mb4)
- A system to connect to, and what it needs (a key, an app of your own, a server login or nothing for a file export).
  The setup screen says what each one needs first.

## Install

**From a release.** Download `estasoft-erp-crm-sync-engine-<version>.zip` from the
[releases page](https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/releases), then
in WordPress choose Plugins > Add New > Upload Plugin. Activate WooCommerce first. Add the encryption line that the
setup screen shows to `wp-config.php` (`define( 'ESTASOFT_SYNC_KEY', 'a long random value' );`), then open
WooCommerce > ESTASOFT SYNC.

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
| PHP unit tests (no WordPress, no database) | `composer test`, or `phpunit -c phpunit.xml.dist` |
| PHP integration tests (real WordPress and database; drops and recreates the named database) | `php bin/install-test-wp.php && vendor/bin/phpunit -c phpunit-integration.xml.dist` |
| Coding standards (WordPress) and static analysis (level 6) | `composer lint`, `composer analyse` |
| The same two without Composer (the build machine) | `vendor/bin/phpcs [paths]`, `vendor/bin/phpstan analyse [paths]` |
| Red-flag scan and PHP 8.1 compatibility scan of the runtime code | `php bin/red-flags.php`, `php bin/check-php-version.php` |
| Files written from other files (must be committed) | `php bin/generate-connector-index.php`, `php bin/generate-catalog-from-docs.php`, `php bin/generate-external-services.php` (each has `--check`) |
| `readme.txt` against the WordPress.org rules | `php bin/check-readme.php` |
| Admin app | `npm run lint:js`, `npm run lint:css`, `npm run typecheck`, `npm run test:js`, `npm run build` |
| Release zip | `bin/build-zip.sh` (`--dry-run` shows the file list only) |
| Install the release zip into a fresh WordPress and exercise it | `bin/smoke-install.sh` ([last result](docs/release/SMOKE-TEST.md)) |
| Version bump | `bin/bump-version.sh 0.2.0` |

Setup, standards, the integration-test environment and the pull request checklist are in
[CONTRIBUTING.md](CONTRIBUTING.md) and [tests/README.md](tests/README.md).

## Architecture and connectors

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): layout, the seams between modules, the queue rules, the mapping
  rule model and the canonical order model.
- [docs/connectors/README.md](docs/connectors/README.md): one document per connector, and
  [docs/connectors/BUILDING.md](docs/connectors/BUILDING.md) for writing a new one on the shared base.
- **Connector contract:** `includes/Contract/ConnectorInterface.php` (contract version 1) and the
  `estasoft_sync_register_connectors` filter.
- [docs/rest-api.md](docs/rest-api.md): the REST routes of the admin app. [docs/error-catalog.md](docs/error-catalog.md):
  the `SYNC-...` error codes.
- [docs/release/](docs/release/CHECKLIST.md): checklist, guideline audit, Plugin Check notes, submission notes.
- [docs/spec/README.md](docs/spec/README.md): the index of all documents and which one wins when they disagree.

## Roadmap

No dates are promised.

1. **0.1.0 (this release):** the engine, the connectors above and all admin screens.
2. **Next:** run every connector against a vendor sandbox and mark it verified (a connector that needs a change
   from its vendor, such as Pennylane's production sign-in, stays unavailable until it is cleared).
3. **Later:** more systems and community connectors through the connector contract. Whether to offer an optional
   sign-in relay is undecided; this version has none, which is why systems with a sign-in page need an app of
   your own.

Ask for a system with the
[New connector request](https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/issues/new?template=new_connector_request.yml)
form.

## Security

Please report vulnerabilities privately through GitHub, as described in [SECURITY.md](SECURITY.md).

## Licence and trademarks

GPL-2.0-or-later. See [LICENSE](LICENSE). All product and company names are trademarks of their owners, used only
to say which systems the plugin works with. This project is independent and is not made, endorsed or sponsored by
any of them, and it includes no vendor logos.
