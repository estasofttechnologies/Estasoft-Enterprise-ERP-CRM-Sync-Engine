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
- Odoo connector: JSON-RPC for Odoo 17 and 18, JSON-2 for Odoo 19 and later, version detection, connection
  test, orders, customers, refunds, stock and tracking read-back, invoice read-back and reconciliation lists.
- Mapping engine: ordered rules over a canonical order model, a fixed library of transforms, tax mapping, SKU
  matching, live preview, versioned publishing and rollback.
- Shadow mode that runs the whole process without changing anything in either system.
- Admin app (React and TypeScript, WordPress components): setup wizard, dashboard, queue and Needs attention,
  invoices, connections, mapping, stock, reconciliation, logs and settings.
- WooCommerce integration: declared compatible with high-performance order storage and the block checkout;
  order box, Sync column on the orders list, product box.
- Credentials encrypted with libsodium using a key from `wp-config.php` (`ESTASOFT_SYNC_KEY`); secrets are never
  returned by the REST API or written to logs.
- Logs with redaction, a support report that leaves secrets out, readiness check, email alerts, settings export
  and import.
- Thirty documented error codes (`SYNC-...`), shown the same way on screen, in the log and in the support
  report.
- Connector contract version 1 and the `estasoft_sync_register_connectors` filter, so other developers can add
  connectors.
- Repository tooling: CI on PHP 8.1 to 8.4 and two WordPress versions, Plugin Check, release workflow with a
  manual approval before WordPress.org, and scripts to build and verify the release zip.

[Unreleased]: https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/releases/tag/v0.1.0
