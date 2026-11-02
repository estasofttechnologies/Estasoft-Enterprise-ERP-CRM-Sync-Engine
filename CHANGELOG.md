# Changelog

All notable changes to Estasoft Enterprise ERP/CRM Sync Engine are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). The `readme.txt` file carries the short version
that WordPress.org shows.

Releases are prepared with `bin/bump-version.sh`, which moves the notes under [Unreleased] into a new section.

## [Unreleased]

## [0.1.0] - Unreleased

<!-- Replace "Unreleased" in the heading above with the release date when the tag is created. -->

First release.

### Added

- Durable queue: an order event is written to the queue in the order's own database transaction; a background
  worker delivers it with retries, a circuit breaker and a rate limiter. Checkout makes no request to the
  connected system.
- Idempotent delivery: every order carries the reference `WC-{order id}`, which is looked up before anything is
  created, plus an id map and a state table with data hashes.
- Connectors for 35 systems (the setup screen lists 34, because the Sage entries hold several products), all built on
  one shared base and one connector contract (version 1):
  - ERP: Odoo (JSON-RPC for 17 and 18, JSON-2 for 19 and later), ERPNext, Microsoft Dynamics 365 Business Central,
    Dynamics 365 Finance and Supply Chain, NetSuite, SAP Business One, SAP S/4HANA, Oracle Fusion Cloud ERP,
    Sage 200, Sage 300, Sage X3, Acumatica, Holded.
  - Accounting: QuickBooks Online, Xero, Zoho Books, MYOB, Sage Business Cloud Accounting, Qoyod, Wafeq, Daftra,
    Lexware Office, sevDesk, Exact Online, Moneybird, Fortnox.
  - CRM: HubSpot, Zoho CRM, Salesforce, Dynamics 365 Sales, Pipedrive, SuiteCRM.
  - Files you import (nothing is sent): DATEV, Sage 50, TallyPrime.
  - Listed and not selectable yet: SMACC (no public API) and Pennylane (needs a sign-in app for production, and its
    API terms are not cleared).
  - No connector has been tried with a live vendor account yet; each document in `docs/connectors/` says what is
    not verified.
- Sign-in styles for the connectors: API key or login, an app of your own with a sign-in page (OAuth 2 with PKCE
  where the vendor supports it), a cloud app without a sign-in page (client credentials or a signed assertion), a
  server login, and export files. Tokens are stored encrypted and refreshed on a schedule.
- Mapping engine: ordered rules over a canonical order model, a fixed library of transforms, tax mapping, SKU
  matching, live preview, versioned publishing and rollback.
- Shadow mode that runs the whole process without changing anything in either system.
- Stock, tracking and invoice-status read-back, and a nightly reconciliation that lists differences and can repair
  them, where the connected system supports them.
- Admin app (React and TypeScript, WordPress components): setup wizard, dashboard, queue and Needs attention,
  invoices, connections, mapping, stock, reconciliation, logs and settings.
- WooCommerce integration: declared compatible with high-performance order storage and the block checkout; order
  box, Sync column on the orders list, product box.
- Credentials encrypted with libsodium using a key from `wp-config.php` (`ESTASOFT_SYNC_KEY`); secrets are never
  returned by the REST API or written to logs.
- Logs with redaction, a support report that leaves secrets out, readiness check, email alerts, settings export
  and import.
- Thirty-three documented error codes (`SYNC-...`), shown the same way on screen, in the log and in the support
  report.
- Connector contract version 1 and the `estasoft_sync_register_connectors` filter, so other developers can add
  connectors.
- WordPress.org submission material: an External services section in `readme.txt` for every service the plugin
  can contact, generated from the connector documents (`bin/generate-external-services.php`), the guideline audit,
  Plugin Check notes, and an install test of the release zip (`bin/smoke-install.sh`).
- Repository tooling: CI on PHP 8.1 to 8.4 and two WordPress versions, Plugin Check, release workflow with a
  manual approval before WordPress.org, and scripts to build and verify the release zip.

[Unreleased]: https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/releases/tag/v0.1.0
