---
id: smacc
name: SMACC
catalog_row: SMACC
connection_method: none
available: false
unavailable_reason: SMACC has not published an API reference, so there is nothing for the plugin to connect to yet.
verified: false
hosts: []
terms_url: https://smacc.com/ww/en/terms-and-conditions
privacy_url: https://smacc.com/ww/en/privacy-policy
developer_url: https://smacc.com/ww/en/docs
variants: []
---

# SMACC connector (listed, not selectable)

Connector id `smacc`, type accounting, contract version 1. Code: `includes/Connectors/Smacc/`. SMACC publishes no API, so this connector is a provider and a minimal connector that **cannot be selected** (`available: false`). It sends nothing, reads nothing and contacts no host. Every attempt to send is refused with a plain explanation. Nothing is invented: there is no endpoint, field name or limit in this connector.

Decision source: `docs/spec/scope-update.md` (SMACC is listed but cannot be selected until SMACC opens an API) and `docs/research/integration-research-vendors-2.md` section 6.4.

## 1. Sources

All fetched on 2026-11-02 unless marked.

| Page | URL | What it settled |
| --- | --- | --- |
| SMACC documentation page | https://smacc.com/ww/en/docs | `[documented]` The new documentation portal is "In Development" and "Coming Soon". It lists a "REST API reference" ("Every endpoint with copy-ready request/response samples, auth, and rate limits") only as a promise. Until then SMACC offers personal training sessions and documentation on request to customers |
| SMACC privacy policy | https://smacc.com/ww/en/privacy-policy | `[documented]` Title "Privacy Policy \| SMACC"; front matter `privacy_url` |
| SMACC terms and conditions | https://smacc.com/ww/en/terms-and-conditions | `[documented]` Title "Terms and Conditions \| SMACC", the terms for using the SMACC website; front matter `terms_url` |
| Research, second round | `docs/research/integration-research-vendors-2.md` 6.4 (read 2 Nov 2026) | `[documented]` docs.smacc.com has ten sections and none covers an API; SMACC markets a built-in one-click WooCommerce connection with "no coding or API work" and names no credentials; `[third-party]` a competitor says SMACC has an API feature but advises internal tools; an aggregator lists SMACC as "On our radar"; `smacc.io` is a different company |

`https://docs.smacc.com` was not fetchable through the allow-list in this session; the finding about its sections is from the research document, not re-read.

## 2. How the merchant connects

1. Ask SMACC whether your account can use an API; SMACC gives documentation on request.
2. Or use SMACC's own WooCommerce connection, set up inside SMACC.
3. Come back when SMACC publishes its API reference; this system cannot be selected yet.

The Choose a system screen lists SMACC with a note and no Select button.

## 3. Authentication

None. `AuthStyle::None`, `connection_method: none`. No credential field exists.

## 4. Data sent and when (WordPress.org "External services")

Nothing. The connector makes no request; `hosts` is empty. Because it cannot be selected, no connection for it can exist and no order or customer data is ever prepared for SMACC.

## 5. Objects and field mapping

None. `describeFields()` and `defaultMapping()` return empty lists for every mapping type, and `supportedEntities()` is empty. No SMACC object, field or tax code is named anywhere in the code.

## 6. Duplicate protection, taxes, payments, refunds, read-back

Not applicable. There is no reference field to search because there is no API. `findExisting()` returns nothing, `lookupCustomer()`, `lookupProduct()`, `searchProducts()`, `fetchStock()`, `fetchTracking()`, `fetchInvoice()` and `listForReconcile()` return empty results (they are never called: no capability is set).

## 7. Rate limits and pacing

None. `rateLimitHints()` returns 1 request a second and concurrency 1 only because the contract requires a value.

## 8. Error mapping

| Call | Result |
| --- | --- |
| `send()` and `findExisting()` | `SendResult::failure` / `ConnectorError` with SYNC-VAL-002, field `connection`, reason "SMACC publishes no public API yet, so the plugin cannot send orders to it." Class: needs a person (not retried) |
| `test()` | `TestResult` with `ok = false` and the same error |
| `ping()` | false |

The message the person reads is the code's template with the reason filled in: "SMACC rejected a field: connection. SMACC publishes no public API yet, so the plugin cannot send orders to it." `findExisting()` gives the same error, so a retry cannot reach a made-up record. SYNC-VAL-002 is the nearest catalog code; no code for "system not available" exists, and a dedicated code is among the requested changes of the build report. The connector also has `available(): false` and `unavailableReason()` for the screens that list systems.

## 9. Capabilities

Every flag is false: there is no API to support any of them. Catalog capabilities: `order_document = none`, `refund_document = none`, `reference_field = none`, `requires = SMACC publishes no API`.

Tests: `tests/php/Unit/Connectors/Smacc/SmaccConnectorTest.php` (the connector refuses everything and explains why) and, against the real plugin, `tests/php/Integration/Connectors/SmaccRoutesTest.php`: the catalog lists SMACC as not available with no form, `POST /connections` for SMACC answers 422 and saves nothing, `POST /connections/test` answers a failed result with SYNC-VAL-002 and the reason, and no request leaves the site.

## 10. Not verified

- Whether SMACC gives customers private API documentation (the page says documentation is available on request; nothing about its content could be read).
- The contents of docs.smacc.com in this session (research document only).
- Whether SMACC's WooCommerce one-click connection is a public interface a third party could use. It is not documented as one.

## 11. Manual smoke test

1. Open Choose a system: SMACC is listed without a Select button and with the reason.
2. Call the REST route that creates a connection for `smacc`: it must be refused.
3. In a test site, force a queue item for `smacc` (for example through a filter in a test plugin): it fails once with the explanation and is not retried.
