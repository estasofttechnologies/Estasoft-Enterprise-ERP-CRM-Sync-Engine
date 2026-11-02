---
id: exact-online
name: Exact Online
catalog_row: Exact Online
connection_method: own_app
available: true
verified: false
hosts: [start.exactonline.nl, start.exactonline.be, start.exactonline.de, start.exactonline.fr, start.exactonline.co.uk, start.exactonline.es, start.exactonline.com]
terms_url: https://files.exact.com/static/downloads/terms-and-conditions/2018/Exact-Exact-Online-App-Center-Terms-and-Conditions-of-Use-October-2018.pdf
privacy_url: https://files.exact.com/static/web/pdf/Privacy-Policy-for-Exact-Services-EN.pdf
developer_url: https://www.exact.com/app-store/developer
variants: []
---

# Exact Online connector

Connector id `exact-online`, type accounting, contract version 1. Code: `includes/Connectors/Exact/`. Registration: `ExactProvider`, picked up by `bin/generate-connector-index.php`.

The connector sends WooCommerce orders to Exact Online as sales invoices (default) or sales orders, creates the customer account when none matches, sends refunds as sales credit notes, and reads stock (item warehouses) and invoice facts back. Every call goes through the shared `ApiClient`; nothing contacts any host except the Exact Online host of the merchant's country.

## 1. Sources

Exact's own REST reference (`start.exactonline.nl/docs/HlpRestAPIResources.aspx`) could not be fetched: the host is outside what this build environment may reach, and the page is a script-driven application. The field lists below come from the open-source client `picqer/exact-php-client`, whose entity files copy the property descriptions of the official reference page by page (each file names its reference page). They are [third-party] mirrors of [documented] facts and are marked so. Fetched 2 Nov 2026 unless stated.

| Fact | Tag | Source |
| --- | --- | --- |
| Exact Online Developer plan EUR 15 a month (excl. VAT), includes test administrations with all functions and 2 users; registering an app needs it; the customer can instead register the app inside their own Exact Online (Partners, Exact App Store, Manage my apps, the plus icon, Register the app) and hand over the API key (Client ID) and secret; App Store publication is optional and needs Exact's approval | [documented] | https://www.exact.com/app-store/developer |
| Client ID is required for every API call; the developer must protect the client secret; apps are subject to Exact's review (clause 3.1); the test environment may not be used for real bookkeeping (clause 4); users' Exact login credentials must not be stored (clause 3.2) | [documented] | App Center Terms and Conditions of Use, October 2018, https://files.exact.com/static/downloads/terms-and-conditions/2018/Exact-Exact-Online-App-Center-Terms-and-Conditions-of-Use-October-2018.pdf |
| Country hosts `start.exactonline.nl`, `.be`, `.de`, `.co.uk`, `.com`, `.es`, `.fr`; OAuth and REST on the same host; an app registered in one country cannot connect customers in another | [third-party] | https://www.apideck.com/blog/guide-to-exact-online-api-integration.md and https://developers.apideck.com/connectors/exact-online/docs/application_owner+oauth_credentials |
| Authorize `GET /api/oauth2/auth` (`client_id`, `redirect_uri`, `response_type=code`, `force_login=0`); token `POST /api/oauth2/token` form-encoded with `client_id` and `client_secret` in the body; authorization code and implicit only (no client credentials, no password grant); bearer token; code lives 3 minutes, single use; access token `expires_in` 600; a refresh earlier than 9 min 30 s answers 401 "Access Token not expired"; every refresh returns a new refresh token and invalidates the old one; refresh token lapses after 30 days unused | [third-party] | Apideck guide above; refresh behaviour also in `picqer/exact-php-client` README ("Each refresh issues a new refresh token and invalidates the old one") |
| Redirect URI must be public https and match exactly; localhost and http give 401; at most four registrations per app | [third-party] | Apideck guide and Apideck OAuth credentials page above |
| Production apps for other companies need Exact's Data & Security review (purpose, scopes per API domain, security questions); reviews usually 5 working days; a new app works for the instance that created it | [third-party] | https://docs.codat.io/integrations/accounting/exact-online/accounting-exact-setup and the Apideck page above |
| 60 calls a minute and 5,000 a day per company per app; 429 when exceeded; headers `X-RateLimit-Minutely-Limit`, `X-RateLimit-Minutely-Remaining`, `X-RateLimit-Minutely-Reset`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` (UTC epoch milliseconds); at most 10 errors per key, user, company, endpoint and hour (client errors count), then a one hour block; Exact offline daily 04:00 to 04:30 local time | [third-party] | Apideck guide, Codat page, `picqer/exact-php-client` `Connection.php` (header names) |
| JSON wrapper `{"d": {"results": [...], "__next": "<url>"}}` for lists, `{"d": {...}}` for one record; 60 rows a page; `$skip` is not supported on endpoints released after 1 March 2017, follow `__next`; error body `{"error": {"code": "", "message": {"lang": "", "value": "<text>"}}}` and an optional `Reason` header; request headers `Accept: application/json`, `Content-Type: application/json`, `Prefer: return=representation` | [third-party] | Apideck guide; `picqer/exact-php-client` `Connection.php` |
| `current/Me` needs no division in the URL and returns `CurrentDivision`; `system/Divisions` lists the divisions the sign-in may use; division errors "User division is not within division scope" and "Wrong division" | [third-party] | Apideck guide; `Me.php` |
| Entities and fields: `crm/Accounts`, `logistics/Items`, `salesinvoice/SalesInvoices` and `SalesInvoiceLines`, `salesorder/SalesOrders` and `SalesOrderLines`, `inventory/ItemWarehouses`, `read/logistics/StockPosition`, `vat/VATCodes`, `financial/Journals`, `salesinvoice/PrintedSalesInvoices`, `hrm/Divisions`, `salesorder/GoodsDeliveries` | [third-party] mirror of [documented] | `picqer/exact-php-client` `src/Picqer/Financials/Exact/*.php`, each file links its official page |
| `SalesInvoices.Type`: 8020 sales invoice, 8021 sales credit note, 8023 direct sales invoice, 8024 direct credit note (the last two need Wholesale and Manufacturing editions); `Status`: 10 draft, 20 open (default for new invoices, can still change), 50 processed (cannot change, done by printing); `GAccountAmountFC`: a credit invoice carries a negative amount when read or posted | [third-party] mirror of [documented] | `SalesInvoice.php` |
| Posting a credit note: `Type = 8021` set on `POST salesinvoice/SalesInvoices`, lines copied from the invoice that is credited, no field for the original invoice number in the REST entity (the GUI calls it `OrderNumberFrom`) | [third-party] | GitHub issue picqer/exact-php-client #464 "How to create a credit invoice?" (question; no maintainer answer was readable) |
| Processing an invoice (number and financial entries) is `POST salesinvoice/PrintedSalesInvoices` with `InvoiceID`; `InvoiceDate` can be given there; mail and Peppol sending are separate boolean fields | [third-party] | Apideck guide; `PrintedSalesInvoice.php` |
| Payment matching is not in the REST API (older XML API, or in Exact itself) | [third-party] | Apideck guide |

## 2. How the merchant connects

1. In Exact Online open Partners, Exact App Store, Manage my apps, and register a new app for your country.
2. Paste the redirect address shown on this screen as the Redirect URI of the app, then copy the app's Client ID and Client Secret.
3. Allow Manage for CRM accounts, sales invoices and orders, and Read for items, VAT codes, journals and stock.
4. Choose your country in the Region box here, paste the Client ID and Client Secret, then press Sign in.
5. Sign in to Exact Online, approve the app, then choose the administration (division) that should receive the orders.
6. Press Test connection, then set the sales journal and the VAT codes in Tax mapping.

Notes for the readme: one app per country (the Region box must match the country the app was made in); the person who approves must be allowed to connect apps; each refresh token is used once and the plugin stores the new one every time; a connection unused for 30 days must be signed in again (the plugin keeps it alive with its scheduled refresh).

## 3. Authentication

OAuth 2 authorization code, handled by the shared `OAuthTokenManager` (no PKCE: none documented; client secret in the form body; refresh under a lock; the new refresh token replaces the old one in the same save; if the save fails the connection needs a new sign-in, SYNC-AUTH-002).

| What | Where |
| --- | --- |
| Region (nl, be, de, fr, co.uk, es, com), Client ID | connection config |
| Client Secret | encrypted secret `client_secret` |
| Access token, refresh token, expiry | encrypted secrets `oauth_*` (reserved keys) |
| Division (administration) id and name | connection config `division`, `division_name`, chosen after sign-in (`organisations()` / `selectOrganisation()`) |
| Options (section 5) | connection config `options` |

`OAuthConfig`: authorize `https://start.exactonline.{region}/api/oauth2/auth` with `force_login=0`; token `https://start.exactonline.{region}/api/oauth2/token`; no scopes (Exact sets them on the app); access token fallback 600 s; refresh token lifetime 30 days (credential expiry warning); sign-in and token hosts restricted to the seven Exact hosts.

## 4. Data sent and when (External services disclosure)

Host: `start.exactonline.{region}` (the one the merchant picks). Nothing else.

| Action | Data sent | When |
| --- | --- | --- |
| Sign in and refresh | Client ID, Client Secret, authorization code or refresh token | Connect; every 10 minutes at most while the plugin works; a scheduled refresh keeps the 30 day window open |
| Test connection | Reads: current user and divisions, a one-row read of accounts, items and the chosen document type, VAT codes, journals | When the merchant presses Test connection |
| Send order | Customer name, email, phone, billing address, VAT number; order date, currency, WooCommerce order number (`WC-{id}`), note, product lines (item code, quantity, price, VAT code), shipping, fee and discount amounts | When an order is queued (created, paid or as set in the connection) |
| Send refund | Same customer reference, refund number (`WC-{refund id}`), reason, refunded lines and amounts | When a refund is queued |
| Read stock | Item codes of the shop's products | On the stock schedule |
| Read invoice facts, reconcile | Order references `WC-...` and dates | On the invoice and nightly check schedules |

## 5. Options (`options` in the connection config)

| Key | Values | Default |
| --- | --- | --- |
| `document` | `invoice` (SalesInvoice, needs the invoicing module), `order` (SalesOrder, needs the Sales order module) | `invoice` |
| `journal` | code of the sales journal for invoices | first journal of type 20 (Sales) that is not blocked |
| `credit_journal` | code of the journal for credit notes | first journal of type 21 (Return invoice), else `journal` |
| `process_invoice` | `no`, `yes` (processes the invoice through PrintedSalesInvoices so it gets its number and entries) | `no` |
| `customer_policy` | `match_or_create`, `match_only` | `match_or_create` |
| `shipping_item`, `fee_item`, `discount_item`, `refund_item` | item codes used for those lines; an order that needs one that is not set is refused naming the option | empty |
| `total_mismatch` | `fail`, `warn` | `fail` |

## 6. Objects and field mapping

Canonical fields reach the connector through the version 1 mapping rules (`ExactDefaultMappings`); the payload paths are the Exact fields below.

| Payload path | Exact field |
| --- | --- |
| `partner.name` | `Accounts.Name` (company name when given, else first and last name as typed) |
| `partner.email` | `Accounts.Email` (also the search key) |
| `partner.phone` | `Accounts.Phone` |
| `partner.street`, `partner.city`, `partner.zip`, `partner.country_id` | `Accounts.AddressLine1`, `City`, `Postcode`, `Country` (ISO 3166 alpha-2) |
| `partner.vat` | `Accounts.VATNumber` |
| (fixed) | `Accounts.Status = "C"` (customer), `IsSales = true` |
| `currency` | `SalesInvoices.Currency` / `SalesOrders.Currency` (always sent, read back) |
| `date_order` | `InvoiceDate` and `OrderDate` (invoice), `OrderDate` (order); the shop's order date, date part |
| `note` | `Remarks` |
| item `YourRef` | `WC-{order id}`, always taken from the queue item |
| `order_line[].product` | `Item` (GUID of `logistics/Items` found by `Code`) |
| `order_line[].quantity` | `Quantity` |
| `order_line[].price_unit` | `UnitPrice` (excluding VAT) |
| `order_line[].tax` | `VATCode` (from Tax mapping) |
| `order_line[].name` | `Description` |
| header | `OrderedBy` (account GUID), `Journal` (invoice) |
| `shipping`, `fee[]`, `discount` | extra lines on the items named in the options |

Money is converted from integer minor units to a decimal with the currency exponent at the edge (`ExactAmounts`, whole-number arithmetic; KWD, BHD and OMR use 3 decimals). The JSON number is built from the exact decimal string. Dates are sent as `YYYY-MM-DDT00:00:00` of the shop's order date (UTC).

Delivery address: not sent (an Exact invoice has one invoicing account; a separate delivery account is not created). Arabic and other scripts are sent as UTF-8 unchanged. Field lengths: names are sent as typed up to 60 characters of description (line `Description` is cut at 60 with an ellipsis and a warning in the result; the reference field `YourRef` is never cut, an order number longer than 30 characters is refused). [not verified: the lengths are Exact's usual limits, the reference page was not readable.]

## 7. Duplicate protection

`YourRef` ("the reference number of the customer", on SalesInvoices and SalesOrders) carries `WC-{order id}` for an order and `WC-{refund id}` for a credit note. Before every create the connector reads `salesinvoice/SalesInvoices?$filter=YourRef eq 'WC-123'` (or `salesorder/SalesOrders`) and follows `__next` to the end. Several hits: the oldest is used. A new document is written with ` [sync pending]` at the end of its `Description`. After the create the connector reads it back and compares currency and total with the order; only when that passes is the marker taken off (the final description, cut to 60 characters) and, when `process_invoice = yes`, the invoice processed. `findExisting()` returns a record only when it is complete: it exists, carries no pending marker and, when `process_invoice = yes`, is processed. A marked document, or an open invoice that should be processed, is reported as not found, so a crash or a lost answer between the create and the check is picked up by the next `send()`, which checks it again and finishes it or fails the same way; a document with a wrong total is never linked unchecked.

Customers: `crm/Accounts?$filter=Email eq '<email>' and IsSales eq true` (first as typed, then lower case), paged; none: create. Several: the first returned (the account with the lowest `Code` is not guaranteed; documented as a gap).

## 8. Taxes

The tax mapping rows (country + rate to code) hold Exact VAT codes. `listTaxCodes()` reads `vat/VATCodes` (`Code`, `Description`, `Percentage`, `Type`, `VATTransactionType`) for sales (`S` or `B`) codes that are not blocked, and shows the percentage ×100 (Exact stores the percentage as a fraction, like discounts) [inferred]. A line whose code is unknown to Exact stops with SYNC-MAP-002 before anything is created.

Prices: lines are sent excluding VAT. When the shop's prices include tax (`prices_include_tax`), a line with one percentage code is converted to net with whole-number arithmetic (rate read from the VAT code, rounding half away from zero); a code of another kind is refused with SYNC-VAL-002 naming the code. After the create the connector reads `AmountFC` (header total including VAT) and `Currency` and compares them with the shop's total less the excluded products, one minor unit of tolerance; more than that fails (`total_mismatch = fail`) or goes on with a warning. VAT rounding differences of a many-line order can exceed one unit; the check then reports them instead of letting them pass.

## 9. Payments

Not sent. Exact's REST API has no payment matching endpoint ([third-party]); recording a payment would need a bank or cash entry with ledger accounts the plugin cannot know. The order's payment method and transaction id go into `Remarks`.

## 10. Refunds

A refund creates `POST salesinvoice/SalesInvoices` with `Type = 8021` (sales credit note), the credit journal, the customer of the original document (read from the invoice or order with `YourRef = WC-{order id}`; if there is none the item fails before anything is created) and `YourRef = WC-{refund id}`. Lines come from the refund payload with positive quantities and prices, as in the only public example of a credit invoice posted through the REST API. After creating, the connector reads `Type`, `Currency` and `AmountFC`: the type must be 8021 and the absolute total must equal the refund amount (one minor unit); otherwise the open document is deleted again when Exact allows it and the item fails with SYNC-VAL-002. The link to the original is in `Description` ("Refund of WC-123") because the REST entity has no field for it.

## 11. Stock, invoice and reconcile reads

- `fetchStock( $skus, $warehouses )`: `inventory/ItemWarehouses?$filter=ItemCode eq '<sku>'` (one request per SKU, paged), `$select=ItemCode,WarehouseCode,CurrentStock,PlannedStockOut,ItemIsStockItem`; quantity = sum of `CurrentStock` over the chosen warehouse codes (all when none), held = sum of `PlannedStockOut`. Items that are not stock items are left out. Needs the stock features of the licence; without them Exact answers 403, which becomes SYNC-AUTH-004 naming the stock read.
- `fetchInvoice( $ref )`: the document by GUID: `InvoiceNumber`, `StatusDescription` (status 10 draft, 20 open, 50 processed), `InvoiceDate`; `authority_status` is empty (Exact has no tax-authority step in this API), `pdf_url` and `qr` empty.
- `listForReconcile( $from )`: `SalesInvoices` (or orders) with `startswith(YourRef,'WC-') eq true` and `InvoiceDate ge datetime'…'`, paged, keyed by `YourRef`, with `total_minor` from `AmountFC` and `refunded_minor` from the credit notes found in the same window by their `Description`.
- Tracking: not offered. Exact's `salesorder/GoodsDeliveries` has a `TrackingNumber`, but linking a delivery back to the order needs sales order mode and an extra lookup per delivery; it was not built.

## 12. Rate limits and pacing

`RateLimitHints( requestsPerSecond: 1.0, concurrency: 1, requestsPerMinute: 60, requestsPerDay: 5000 )`: one call a second, the shared pacer counts the 5,000 a day per UTC day and stops with SYNC-RATE-001 and the wait when used up. Answers carry the real counters: on 429 the connector computes the wait from `X-RateLimit-Minutely-Reset` (when the minute counter is 0) or `X-RateLimit-Reset` (epoch milliseconds), at least 1 and at most 86,400 seconds. The connector never probes with a request that is expected to fail (404 or 400), because 10 errors an hour block the key for an hour. Exact is offline from 04:00 to 04:30 local time; calls then fail with 5xx or a timeout and the queue retries them.

## 13. Error mapping

| Exact answer | SYNC code | Class |
| --- | --- | --- |
| Token refused: `invalid_grant` (refresh token used, expired or revoked) | SYNC-AUTH-002 (connection held for sign-in) | needs a person |
| `invalid_client` | SYNC-AUTH-001 | needs a person |
| 401 on the API after one refresh | SYNC-AUTH-002 | needs a person |
| 403, no message about the division | SYNC-AUTH-004 naming the action (module or right missing) | needs a person |
| 400 or 403 whose message names the division ("Wrong division", "not within division scope") | SYNC-VAL-002 field `division` | needs a person |
| 400 with `error.message.value` | SYNC-VAL-002 with the text (cut to 200 characters, secrets and host removed) | needs a person |
| 429 | SYNC-RATE-001 with the wait from the headers | retry after the wait |
| 5xx, 408, 504, timeout, reset | SYNC-NET-002 / SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| Unknown SKU | SYNC-MAP-001 | needs a person |
| Unknown VAT code | SYNC-MAP-002 | needs a person |
| Missing customer email | SYNC-MAP-003 | needs a person |
| Total or currency differs, credit note not a credit note | SYNC-VAL-002 | needs a person |

SYNC-AUTH-006 (plan gate) is not used: no Exact answer was found that names a missing licence in a way that can be told from a missing right.

## 14. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| Orders | true | SalesInvoices or SalesOrders |
| Customers | true | `crm/Accounts` |
| Refunds | true | SalesInvoices with `Type = 8021` (see Not verified) |
| StockIn | true | `inventory/ItemWarehouses` |
| TrackingIn | false | not built |
| PricesIn | false | not built |
| InvoiceReadback | true | number, status and date of the document |
| Reconciliation | true | `YourRef` search window |
| Webhooks | false | Exact has webhooks, the plugin polls |
| ShadowLookup | true | `findExisting()` is read-only |

## 15. Not verified

- Exact's official REST reference could not be read; every field name and enumeration comes from a mirror of it (section 1). A field that Exact renamed or a limit that Exact changed would show up only against a real administration.
- Whether a sales invoice line may omit `Item` (the connector always sends an item), and the exact length limits of `YourRef`, `Description` and `Remarks`.
- Whether `Type = 8021` on POST with positive quantities creates a credit note whose `AmountFC` is negative or positive; the check compares absolute values and the type. Whether the credit note can carry a reference to the original beyond the `Description`.
- Whether `DELETE salesinvoice/SalesInvoices(guid'…')` works for an open invoice with this app's rights (listed in a third-party description of the API).
- Filter functions `startswith(...)` and string equality on `Email` and `YourRef` (case sensitivity, trimming); `Account.Code` has leading spaces and is never used.
- `Percentage` of a VAT code as a fraction (0.21 for 21 percent).
- `system/Divisions` returning `Code` and `Description` to a sign-in that has only the rights listed in section 2.
- Whether PKCE is accepted (not used).
- Whether a customer-side registered app needs no review for the merchant's own company (the developer page says the customer supplies the key and secret; the review text is about apps for other companies).
- The SalesOrder mode: fields were taken from the order entity but never exercised end to end.
- No live Exact Online account, test administration or sandbox was available. All behaviour above the wire is tested against `FakeExactServer`, written from the shapes in section 1.

## 16. Manual smoke test

Use an Exact Online test administration from a Developer plan.

1. Make an app in your country (section 2), with the redirect address from the Connect screen; allow Manage for accounts and sales invoices, Read for items, VAT codes, journals and stock.
2. Add an Exact Online connection, choose the region, paste the Client ID and Secret, press Sign in, choose the test administration.
3. Press Test connection. Expected: user, administration and currency, the number of VAT codes, the sales journal chosen, and each read permission ticked.
4. In Exact create an item whose code equals a WooCommerce SKU; in Tax mapping choose the VAT codes.
5. Place an order. Expected: one account (Email as the order), one open invoice with `YourRef = WC-{order id}` in the order's currency, total equal to the shop total, invoice date equal to the shop's order date. Run the sync again: no second invoice.
6. Refund the order: expected one credit note (type "Sales credit note") with reference `WC-{refund id}`.
7. Change the stock of the item in Exact and run the stock read.
8. Sign out of the app in Exact (Manage my apps) and run Test connection: expected SYNC-AUTH-002 and a Reconnect button.
