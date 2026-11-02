---
id: xero
name: Xero
catalog_row: Xero
connection_method: own_app
available: true
verified: false
hosts: [api.xero.com, identity.xero.com, login.xero.com]
terms_url: https://developer.xero.com/xero-developer-platform-terms-conditions/
privacy_url: https://www.xero.com/legal/privacy/
developer_url: https://developer.xero.com/app/manage
variants: []
---

# Xero connector

Connector id `xero`, type accounting, contract version 1. Code: `includes/Connectors/Xero/`. Registration: `XeroProvider` (found by `bin/generate-connector-index.php`).

The connector sends WooCommerce orders to Xero as sales invoices (type `ACCREC`), optionally approves them and records the payment, sends refunds as credit notes (type `ACCRECCREDIT`, optionally allocated to the invoice), finds or creates the customer as a Xero contact, and reads item stock, invoice facts and a reconciliation list back. Every call goes through the engine's HTTP client; nothing reads or writes WordPress data.

Tags used below: `[documented]` official Xero page or file (URL and fetch date given), `[third-party]` a vendor-neutral or partner page, `[inferred]` a conclusion from the documents that no page states. All fetching was done on 2026-11-02. Nobody has run this against a live Xero organisation.

## 1. Sources

| Source | Used for | Fetched |
| --- | --- | --- |
| https://raw.githubusercontent.com/XeroAPI/Xero-OpenAPI/master/xero_accounting.yaml (Xero's own OpenAPI file, version 19.1.0, server `https://api.xero.com/api.xro/2.0`) | endpoints, query parameters, headers, field names, field lengths, enums, scopes of the security scheme | 2026-11-02 |
| https://raw.githubusercontent.com/XeroAPI/Xero-OpenAPI/master/xero-identity.yaml | `GET /Connections` shape | 2026-11-02 |
| `xero-node` 20.0.0 from the npm registry (Xero's official SDK, `dist/XeroClient.js`) | token endpoint, Basic client authentication, form body, `Xero-tenant-id` header | 2026-11-02 |
| https://developer.xero.com/faq/limits | 5 concurrent, 60 per minute, 5,000 per day per organisation, 10,000 per minute per app, headers `X-DayLimit-Remaining`, `X-MinLimit-Remaining`, `X-AppMinLimit-Remaining`, 429 with `Retry-After`, 3.5 MB request size | 2026-11-02 |
| https://developer.xero.com/faq/oauth2 | redirect URI rules (absolute, no wildcard, 50 per app), PKCE for native apps only, access token 30 minutes, refresh token 60 days unused, rotate on every refresh, 30-minute retry window | 2026-11-02 |
| https://developer.xero.com/faq/granular-scopes and https://devblog.xero.com/upcoming-changes-to-xero-accounting-api-scopes-705c5a9621a0 | granular scopes (`accounting.invoices`, `accounting.payments`, `accounting.contacts`, `accounting.settings`), dates | 2026-11-02 |
| https://devblog.xero.com/xero-api-token-management-best-practices-and-troubleshooting-01853f4244a9 | refresh behaviour, causes of `invalid_grant` | 2026-11-02 |
| https://developer.xero.com/xero-developer-platform-terms-conditions/ | terms | 2026-11-02 |
| Research already in the repository: `docs/research/integration-research-vendors-2.md` section 7.2 | plan tiers (Starter 5 connections and 1,000 calls a day), no sales-order endpoint | repository |

The developer pages `developer.xero.com/documentation/...` return only a shell to the fetch tool, so the narrative guides (invoice status rules, where-filter syntax, error pages) could not be read. Everything the connector sends is taken from the OpenAPI file; what the guides would add is listed in "Not verified".

## 2. How the merchant connects

1. Open `developer.xero.com/app/manage`, choose New app and pick Web app (any company name and URL will do).
2. Paste the Redirect address shown here into the Redirect URIs of that Xero app and save.
3. Copy the app's Client ID, create a Client secret, and paste both in the two fields on this screen.
4. Press Sign in with Xero, sign in as a Standard or Advisor user and allow access to your organisation.
5. Choose the organisation to sync, then press Test connection.
6. In Xero make sure the sales tax rates and the sales account you need exist, then map them in Tax mapping.

Plan gates: the free Starter tier of a Xero developer app allows 5 connected organisations and 1,000 calls a day for each [documented, research]. Xero says an app created on or after 2 March 2026 can only use granular scopes [documented]; the connector asks for granular scopes only, so it works for old and new apps. The user who signs in needs the Standard or Advisor role: lowering the role below that, removing the user, or disconnecting the app under Connected apps ends the connection and needs a new sign-in [documented].

## 3. Authentication

OAuth 2 authorization code with the merchant's own app. The plugin contains no secret.

| Item | Value |
| --- | --- |
| Authorize | `https://login.xero.com/identity/connect/authorize` [documented, OpenAPI security scheme] |
| Token and refresh | `https://identity.xero.com/connect/token`, form body, client id and secret in a Basic header [documented, xero-node] |
| PKCE | not used: Xero offers it for native apps only [documented]; the web flow uses the secret |
| Scopes | `offline_access accounting.invoices accounting.payments accounting.contacts accounting.settings.read` [documented: granular scopes; `.read` variants exist for transactions and settings per the OpenAPI scheme]. Items are covered by `accounting.invoices` (and by settings) [documented] |
| Access token | 30 minutes; refreshed by the engine when less than 5 minutes remain |
| Refresh token | 60 days if unused, replaced by a new one on every refresh; the old one still works for 30 minutes if the answer was lost [documented]. The engine stores the new value under a lock |
| Tenant | `GET https://api.xero.com/connections` lists the organisations the person allowed (`tenantId`, `tenantName`, `tenantType`); only `ORGANISATION` rows are offered. The chosen id is stored as `tenant_id` and sent as `Xero-tenant-id` on every call [documented] |

Stored: client id (config), client secret (encrypted secret), access and refresh tokens (encrypted, reserved keys), `tenant_id` and `organisation_name` (config). The tenant id and the organisation name are removed from every message and log line.

## 4. Data sent and when (for the WordPress.org "External services" disclosure)

| Host | When | Data |
| --- | --- | --- |
| `login.xero.com` | the person presses Sign in (their own browser is sent there; the server does not call it) | client id, redirect address, scopes, a random state value |
| `identity.xero.com` | after sign-in, and about 5 minutes before an access token ends | client id and secret (Basic header), the one-time code or the refresh token |
| `api.xero.com` | test connection, every queued order, refund and customer, and the scheduled reads (stock, invoice facts, nightly check) | organisation facts (read); contact name, email, phone, billing and delivery address, buyer tax number; invoice lines (SKU, description, quantity, unit price, tax type), totals, dates, the shop's order reference `WC-{id}`, the payment reference; credit notes of refunds; reads of tax rates, accounts, items, invoices and credit notes |

Nothing is sent to Estasoft or anyone else.

## 5. Connection settings

Credential fields: `client_id`, `client_secret`, and the redirect address (display only).

Options (config `options` or the same keys in the config; `XeroOptions`):

| Key | Values | Default |
| --- | --- | --- |
| `documents` | `invoice` (create the invoice, leave it a draft), `invoice_approved` (approve it), `invoice_paid` (approve and record the payment) | `invoice_approved` |
| `sales_account` | account code for lines that have no Xero item (shipping, fees, free-text lines) | empty (such lines are refused, naming this option) |
| `shipping_account`, `fee_account`, `discount_account` | account code per kind of line | `sales_account` |
| `payment_account` | code of a bank account in Xero that accepts payments (needed for `invoice_paid`) | empty |
| `refund_state` | `draft`, `authorised`, `authorised_allocate` (approve and apply the credit to the invoice) | `draft` |
| `unknown_sku` | `refuse` (SYNC-MAP-001), `free_text` (a line without item code, needs `sales_account`) | `refuse` |
| `due_days` | 0 to 365 days after the order date | 0 |
| `no_tax_type` | tax type sent for an untaxed line | `NONE` |
| `total_mismatch` | `fail` (delete the draft and stop) or `warn` (keep going and report) | `fail` |
| `allow_backorders` | create the invoice even when a tracked item would go below zero [documented parameter `allowBackorders`] | yes |

Daily call cap: `rateLimitHints()` reports 1,000 calls a day, the Starter tier. A site on a paid tier raises it with the filter `estasoft_sync_xero_daily_calls`.

## 6. Objects and field mapping

Order payload paths are the same names the Odoo connector uses (so one mapping screen works for all connectors); right-hand column is the Xero field [documented, OpenAPI].

| Payload path | Xero field |
| --- | --- |
| `client_order_ref` (always replaced by the item's `WC-{id}`) | Invoice `Reference` |
| `date_order` | Invoice `Date` (YYYY-MM-DD; the mapping's `date_utc` with `format = date` and `output_timezone = store` gives the shop's date); `DueDate` = date + `due_days` |
| `currency` | `CurrencyCode`; `CurrencyRate` is not sent, so Xero uses its day rate [documented] |
| `prices_include_tax` | `LineAmountTypes` `Inclusive`; otherwise `Exclusive` |
| `total`, `excluded_total` | not sent; the expected total for the check |
| `partner.name`, `partner.company_name` | Contact `Name` (company if given, else the person; max 255), `FirstName`, `LastName` |
| `partner.email` | Contact `EmailAddress` (max 255; not sent when it has non-ASCII letters, Xero says umlauts are not supported) |
| `partner.phone` | Contact `Phones[]` (`DEFAULT`, `PhoneNumber` max 50) |
| `partner.vat` | Contact `TaxNumber` (max 50) |
| `partner.street`, `city`, `state_id`, `zip`, `country_id` | Contact `Addresses[]` type `POBOX` (the billing address): `AddressLine1` (max 500), `City` (255), `Region` (255), `PostalCode` (50), `Country` (letters only, ISO code) |
| `delivery.*` | Contact `Addresses[]` type `STREET`, only when it differs from the billing address |
| `order_line[].product` | LineItem `ItemCode` (Xero `Code`, max 30) |
| `order_line[].name` | LineItem `Description` (max 4,000 [third-party]) |
| `order_line[].quantity`, `price_unit` | `Quantity`, `UnitAmount` (sent with `unitdp=4`) |
| `order_line[].discount` | `DiscountRate` |
| `order_line[].tax` | `TaxType` (a code from the tax mapping) |
| `shipping.*`, `fee[].*` | extra lines with the account from `shipping_account` / `fee_account` |
| `discount.total` | one negative line with `discount_account` |
| `payment.reference`, `payment.date` | Payment `Reference`, `Date` |

Refunds: `order_ref`, `date`, `reason`, `amount`, `prices_include_tax`, `refund_line[].product / name / quantity / amount / tax`, `shipping.total`, `shipping.tax` become the credit note (`Reference` `WC-{refund id}`, plus the reason after a space).

Customers: `partner.*` only; the contact gets `ContactNumber` `WC-{customer id}` [documented: "used to identify contacts in external systems", max 50].

## 7. Flow of an order

`send()` for an order item. Every pass starts from a read and does only what is missing.

1. Check the payload before any request: customer name, product lines, whole-number minor units, a price for every line.
2. Find the invoice: `GET /Invoices?where=Type=="ACCREC" AND Reference=="WC-{id}"` with `page` and `pageSize=100`, all pages [documented parameters; filter syntax `[inferred]`]. A `DELETED` invoice is ignored for the result, but its id is remembered for the key in section 8; a `VOIDED` one stops the item with SYNC-VAL-002 (the merchant voided it on purpose; creating a second one could double the revenue).
3. If none exists: read `GET /Organisation` (base currency, region) and, when the order currency differs, `GET /Currencies` (the currency must be enabled in Xero, else SYNC-VAL-002 naming it). Resolve the items (one `GET /Items?where=Code=="A" OR Code=="B"` per 20 SKUs; an item that is not sold, or no match, follows `unknown_sku`), then the contact (below). All of this happens before anything is created.
4. Create the invoice as `DRAFT` with `PUT /Invoices?summarizeErrors=true&unitdp=4&allowBackorders=...` and the header `Idempotency-Key` (see section 8). The answer is checked for `HasErrors`/`ValidationErrors`.
5. Read back the currency and the total. A currency other than the order's, or a total that differs from `total - excluded_total` by more than one minor unit, is `SYNC-VAL-002` naming both totals; with `total_mismatch = fail` the draft is deleted (status `DELETED`) first. Nothing is approved before this check.
6. Approve (`POST /Invoices/{id}` with `Status AUTHORISED`) unless `documents = invoice`. The date is sent explicitly on create, and the date in the answer after approval is compared with it (a difference becomes a warning in the result).
7. For `invoice_paid`, when the payload has a payment date: `PUT /Payments` with the invoice, the account code, the date, the amount due and the reference. Before creating it the invoice is read: if `AmountDue` is already 0 or a payment with that reference exists, nothing is created.
8. The result: external id = `InvoiceID`, reference = `InvoiceNumber`, `extra` = total in minor units, currency, status.

Replays: create and payment run once per pass; if one fails in a way that may have reached Xero (time-out, lost answer, 5xx), the pass ends with that error and the next pass starts again from step 2, which finds what was created. Approve is safe to repeat.

`findExisting()` returns the invoice only when it is finished for the connection's `documents` setting (approved, and paid for `invoice_paid`). An unfinished invoice returns null, so the engine calls `send()` and finishes it.

Contacts: `GET /Contacts?searchTerm={email}` (documented: case-insensitive across Name, FirstName, LastName, ContactNumber, EmailAddress), all pages, exact case-insensitive match on `EmailAddress`; none found: `POST /Contacts`. Xero requires a unique contact name. A validation message that says the name is already assigned makes the connector retry once with the name followed by the email in brackets (`Jane Doe (jane@example.com)`); any other message stops the item.

## 8. Duplicate protection

- Reference field: Invoice `Reference` = `WC-{order id}`; credit note `Reference` = `WC-{refund id}` (a refund and an order never share an id). Searched before every create (section 7 step 2).
- Optional `Idempotency-Key` header, 128 characters at most, accepted on POST/PUT [documented in the OpenAPI file]. The retention time and the answer to a reused key with a different body are not documented. The key is `wc-{connection}-{reference}-{step}-{first 16 hex of sha1 of the ids of this order's DELETED drafts and the body}`. The queue item's `attempt` is not part of it: a retry after a time-out or a lost answer sends the same key with the same body, so Xero can replay the first create instead of making a second invoice (references are not unique in Xero, so the search alone cannot be trusted to see an invoice created a moment ago). The key changes only when the body changes (a corrected order) or after the connector itself deleted a draft of this reference (the deleted invoice's id goes into the hash), so a retry after that deletion is not handed the answer about the deleted draft. The payment key is built the same way from the payment body, which names the invoice. The refund's credit note follows the same rule.
- `findExisting()` and `send()` return a reference only for records `send()` finished or can safely finish (see above).

## 9. Taxes

Tax codes are Xero tax types (for example `OUTPUT2`, `NONE`). `listTaxCodes()` reads `GET /TaxRates?where=Status=="ACTIVE"` and keeps rates with `CanApplyToRevenue` (`DisplayTaxRate` is the percentage). The mapping's `tax_lookup` turns a WooCommerce rate into a type. A line whose tax key is present but empty gets `no_tax_type`. A line with no tax key at all gets Xero's default for the item or account.

Tax-inclusive prices: `LineAmountTypes` follows `prices_include_tax`, so no conversion happens on our side and no rounding is done by us. Xero rounds per line; the total check is what catches any difference of more than one minor unit.

## 10. Payments

Off unless `documents = invoice_paid` and `payment_account` is set. Needs the scope `accounting.payments` and an account that accepts payments. The amount is the invoice's `AmountDue` read just before, never the order total, so an invoice already (partly) paid is not paid twice.

## 11. Refunds

A refund item finds the order's invoice (`WC-{order id}`; not found: SYNC-VAL-002 before anything else), takes its contact, creates a credit note (`PUT /CreditNotes`, `Type ACCRECCREDIT`, same currency and `LineAmountTypes`, `Reference` `WC-{refund id}`), checks the total against `amount` when the mapping gives one, and then follows `refund_state`: `draft` leaves it; `authorised` approves it; `authorised_allocate` approves it and applies it to the invoice with `PUT /CreditNotes/{id}/Allocations` (the credit note and the invoice have the same contact, which Xero requires from 8 October 2026 [research, documented]). The amount allocated is the smaller of the credit and the invoice's amount due. Every pass reads the credit note first and does only the missing step.

## 12. Reading from Xero

- `fetchStock($skus)`: `GET /Items` filtered by code; for items with `IsTrackedAsInventory` the quantity is `QuantityOnHand` (floored, never below 0), held 0. Xero has one quantity per item, no warehouses [documented].
- `fetchInvoice($ref)`: invoice number, status, date and, for approved invoices, the online invoice link from `GET /Invoices/{id}/OnlineInvoice`. `authority_status` is empty: Xero has no tax-authority step in its API.
- `listForReconcile($from)`: invoices of type ACCREC with `Reference` starting `WC-` and `Date` from the day on (server-side `where`), paged 100 at a time; total, amount credited and the contact's email when present.
- `lookupCustomer`, `lookupProduct`, `searchProducts`, `listTaxCodes`: read-only helpers.
- Tracking is not available (Xero has no delivery records), prices are not read, webhooks are not used (they need a public endpoint and a signing key).

## 13. Rate limits and pacing

The circuit breaker's health probe (`ping()`) is one `GET /Organisation`, made fresh every time (nothing is answered from what an earlier read cached); it is false on any failure. Test connection makes several reads, the probe does not.

Limits per organisation: 5 concurrent calls, 60 a minute, 5,000 a day (1,000 on Starter); per app 10,000 a minute [documented]. The connector runs one call at a time, about one per second (`requestsPerMinute` 55, `requestsPerDay` 1,000), and stops with SYNC-RATE-001 and the wait when the daily cap is used up or Xero answers 429 (the `Retry-After` value is obeyed). Test connection shows `X-DayLimit-Remaining` when Xero sends it. One order costs about 6 to 9 calls (find invoice, find contact, items, organisation, create, approve), so the Starter tier covers roughly 110 orders a day.

## 14. Error mapping

| What Xero answers | SYNC code | Notes |
| --- | --- | --- |
| 400 `ValidationException` (body `Type`, `Message`, `Elements[].ValidationErrors[].Message`) | SYNC-VAL-002 | first message, redacted |
| 200 with `HasErrors` on the element | SYNC-VAL-002 | same |
| 401 | SYNC-AUTH-002 after one refresh | base behaviour |
| 401 with `WWW-Authenticate` naming `insufficient_scope` | SYNC-AUTH-004 | a scope is missing: sign in again after adding it |
| 403 | SYNC-AUTH-004 | |
| 404 | normal answer on reads | |
| 429 | SYNC-RATE-001 | `Retry-After`; the daily cap gives the wait until the next UTC day |
| 5xx, time-out, reset | SYNC-NET-002 / SYNC-NET-001 | retry |
| certificate failure | SYNC-NET-003 | |
| unknown SKU, `unknown_sku = refuse` | SYNC-MAP-001 | before anything is sent |
| tax rate not in the tax mapping | SYNC-MAP-002 | raised by the mapping |
| missing customer email | SYNC-MAP-003 | |
| currency not enabled, totals differ, voided invoice, missing account option | SYNC-VAL-002 | |

`classifyError()` keeps the catalogue class.

## 15. Capabilities

| Flag | Value | Why |
| --- | --- | --- |
| orders, customers, refunds | yes | invoices, contacts, credit notes |
| stock_in | yes | `QuantityOnHand` of tracked items [documented], no warehouses |
| tracking_in | no | Xero has no delivery records |
| prices_in | no | not built |
| invoice_readback | yes | invoice number, status, online link |
| reconciliation | yes | server-side `where` and `page` |
| webhooks | no | not built |
| shadow_lookup | yes | `findExisting()` only reads |

Note for the owner: `docs/spec/scope-update.md` item 4 says Xero has "no stock or tracking to read". The OpenAPI file documents `QuantityOnHand` on `Items`, so `stock_in` is set; switch the flag off in `XeroConnector::meta()` if the product decision stays "no stock".

## 16. Not verified

- The guides at `developer.xero.com/documentation/...` could not be fetched (shell page). The `where` filter syntax used (`Type=="ACCREC" AND Reference=="WC-1"`, `Reference.StartsWith("WC-")`, `Date>=DateTime(2026,1,1)`) follows the filter style of the OpenAPI descriptions and common SDK usage, not a page read.
- Whether `Reference` is filtered server-side efficiently, and whether string comparison in `where` is case-sensitive.
- The behaviour of `Idempotency-Key` (retention, reuse with a different body, whether a replay returns the first answer to a request that timed out on our side). The retry-after-time-out protection rests on it whenever the search for the reference does not yet show the first invoice.
- Whether `DELETED` can be set on a draft created by the same app in every organisation, and what Xero does with a draft that already has an attachment or a payment.
- Multi-decimal currencies (KWD, BHD, OMR): `unitdp=4` allows three decimals on the unit price, but how Xero rounds a line amount to the currency is not documented; the total check reports any difference.
- Whether the access token's scopes can be read (not attempted; a missing scope shows as SYNC-AUTH-004 when the call is made).
- The name-conflict message of contacts is matched on the words "already" and "assigned"; the exact text is not documented.
- Starter-tier numbers come from the research file and `faq/limits`; the cap used (1,000) is the Starter value.
- Terms URL opened and confirmed ("Xero Developer Platform Terms & Conditions"). Privacy URL `https://www.xero.com/legal/privacy/` is listed by a web search as "Privacy notice" but was not opened; the Australian page (`/au/about/legal/privacy/`) was opened and is Xero's privacy notice.
- No live Xero organisation was available. All behaviour above the wire is tested against `FakeXeroServer` and fixtures written from the OpenAPI examples.

## 17. Manual smoke test (real account, no secrets in the repository)

1. In Xero create a Demo Company (free) and, at `developer.xero.com/app/manage`, a Web app with the redirect address shown in the plugin.
2. Paste Client ID and Secret, sign in, choose the demo organisation, press Test connection. Expected: organisation name, base currency, tax rates found. A wrong secret gives SYNC-AUTH-001 or SYNC-AUTH-002.
3. Create an item with code equal to a WooCommerce SKU, set a sales account code in the options, place an order. Expected: one approved invoice with `Reference` `WC-{order id}`, the shop's date, the right total.
4. Retry the same order from the queue: no second invoice. Void the invoice in Xero and retry: SYNC-VAL-002.
5. Set `documents = invoice_paid` with a bank account code: the invoice shows paid.
6. Refund the order with `refund_state = authorised_allocate`: a credit note applied to the invoice.
7. Change a tracked item's quantity and run the stock read.
8. Order in a currency Xero does not have enabled: SYNC-VAL-002 before anything is created.
