---
id: fortnox
name: Fortnox
catalog_row: Fortnox
connection_method: own_app
available: true
verified: false
hosts: [api.fortnox.se, apps.fortnox.se]
terms_url: https://www.fortnox.se/developer/guides-and-good-to-know/legal-and-change-policies
privacy_url: https://apps.fortnox.se/api/eula/document-v1/user
developer_url: https://www.fortnox.se/developer/developer-portal
variants: []
---

# Fortnox connector

Connector id `fortnox`, type accounting (Sweden), contract version 1. Code: `includes/Connectors/Fortnox/`. Registration: `FortnoxProvider`.

The connector sends WooCommerce orders to Fortnox as customer invoices (default) or orders, creates the customer when none matches, sends refunds as credit invoices, and reads stock, invoice facts and the nightly reconcile list back. Hosts: `api.fortnox.se` (API) and `apps.fortnox.se` (sign-in and token endpoint). The merchant uses an integration made in Fortnox's Developer Portal under their own account; the plugin contains no client id or secret.

## 1. Sources

All fetched 2 Nov 2026. The Redoc reference at `apps.fortnox.se/apidocs` is a script-driven page: the fetch returned only its navigation and a few conventions. Field lists, filter names and enumerations therefore come from the open-source `softwerkab/fortnox-csharp-api-sdk`, whose entity and search classes copy Fortnox's published reference (its `BaseSearch.cs` links `developer.fortnox.se/general/parameters/`); they are marked [third-party] mirror of [documented].

| Fact | Tag | Source |
| --- | --- | --- |
| Authorization code flow: code valid 10 minutes, access token 1 hour, refresh token 45 days (raised from 31); each refresh returns a new refresh token and the old one stops working; API calls do not extend expiry; scopes are approved by the user at activation | [documented] | https://www.fortnox.se/developer/authorization ; https://www.fortnox.se/developer/blog/a-slight-adjustment-to-lifetime-of-refresh-tokens |
| Authorize: `GET https://apps.fortnox.se/oauth-v1/auth` with `client_id`, `response_type=code`, `state` (required), `scope` (required, space separated, case sensitive), `redirect_uri` (optional, must match the portal), `access_type=offline` (optional, for refresh tokens), `account_type=service` (service account, system administrators only), `code_challenge` + `code_challenge_method=S256` (required when the app enforces PKCE) | [documented] | https://www.fortnox.se/developer/authorization/get-authorization-code |
| Token: `POST https://apps.fortnox.se/oauth-v1/token`, `Content-type: application/x-www-form-urlencoded`, `Authorization: Basic base64(ClientId:ClientSecret)`; code exchange body `grant_type=authorization_code&code=&redirect_uri=` (+ `code_verifier` when a challenge was sent); refresh body `grant_type=refresh_token&refresh_token=`; answer `access_token`, `refresh_token`, `scope`, `expires_in` (3600), `token_type` (`bearer`) | [documented] | https://fortnox.se/developer/authorization/get-access-token ; https://www.fortnox.se/developer/authorization/get-refresh-token |
| Service account (client credentials) flow: needs `account_type=service` consent and a `TenantId` header; no refresh token. Not used here: it needs a second consent and the tenant id; a normal sign-in with a rotating refresh token is built instead | [documented] | https://www.fortnox.se/developer/authorization/get-access-token-using-client-credentials |
| Scopes (each read and write) and the licence each needs: `customer` (Kundfaktura or Order), `article` (Order or Kundfaktura), `invoice` (Order or Kundfaktura), `order` (Order), `payment` (Bokföring, Order or Kundfaktura), `warehouse` (Lager or Lager Plus), `companyinformation` (any), `bookkeeping` (Bokföring or Kundfaktura) | [documented] | https://www.fortnox.se/developer/guides-and-good-to-know/scopes |
| Scopes are fixed when the authorization code is created; changing scopes needs new authorization codes; every scope the integration needs must be ticked in the portal; the Redirect URI in the portal must equal the one in the activation link; missing licence gives "Missing license"; activation needs a user with system administrator rights | [documented] | https://www.fortnox.se/developer/faq |
| Developer Portal: Menu, Developer Portal; My Integrations, Create new integration; name and scopes; Client-Id, Client-Secret, Redirect URI; hidden integrations are found by Client ID; up to 30 sandboxes (test companies); needs a developer licence; publishing needs the integration partner agreement | [documented] | https://www.fortnox.se/developer/developer-portal |
| Rate limit: 300 requests a minute per client id and tenant, enforced as 25 requests per 5 seconds on a sliding window; HTTP 429; no `Retry-After` or other limit header documented | [documented] | https://www.fortnox.se/developer/guides-and-good-to-know/rate-limits-for-fortnox-api |
| Errors: body `ErrorInformation` with `error`, `message` (Swedish text) and `code`; 2000310, 2000311 and 2003275 invalid login/token; 2000663 no access to the scope; 2001101 no active licence for the scope; 2001103 no API licence; 2000204/2000433 customer not found; 2000637 customer number in use; 2000588 invalid parameter; 2001392 empty or wrong body; 2001399 wrong field name; 1000003 system exception; 1000030/1000031 wrong Accept or Content-Type; OAuth `error_missing_license`, `error_missing_app_license` | [documented] | https://www.fortnox.se/developer/guides-and-good-to-know/errors ; https://fortnox.se/developer/guides-and-good-to-know/responses (status codes 200, 201, 204, 400, 403, 404, 500) |
| Base `https://api.fortnox.se/3/`; `Authorization: Bearer`; legacy `Client-Secret` and `Access-Token` headers retired 30 April 2025; list answers have `MetaInformation` (`@TotalResources`, `@TotalPages`, `@CurrentPage`), `limit` default 100 and maximum 500, `page` and `offset`; single answers are wrapped (`{"Invoice": {…}}`); invoice rows `ArticleNumber`, `DeliveredQuantity`, `Price`; the customer must exist before the invoice | [third-party] | https://www.apideck.com/blog/how-to-integrate-with-the-fortnox-api.md |
| Invoice search parameters `externalinvoicereference1`, `externalinvoicereference2`, `yourordernumber`, `customernumber`, `ourreference`, `documentnumber`, `fromdate`, `todate`, `lastmodified`, `filter`; customer search `email`, `customernumber`, `name`, `organisationnumber`; article search `articlenumber`, `description`, `ean` | [third-party] mirror of [documented]; the customer `email` filter is also in the Fortnox action list of https://pipedream.com/apps/fortnox/actions/list-customers.md | `InvoiceSearch.cs`, `CustomerSearch.cs`, `ArticleSearch.cs`, `BaseSearch.cs` of the SDK |
| Entities: `Invoice` (`ExternalInvoiceReference1/2`, `YourOrderNumber`, `VATIncluded`, `Currency`, `CurrencyRate`, `InvoiceDate`, `DueDate`, `DeliveryDate`, `Remarks`, `Comments`, `InvoiceRows`, read-only `Total`, `Balance`, `Booked`, `Cancelled`, `Credit`, `CreditInvoiceReference`), `InvoiceRow` (`ArticleNumber`, `Description`, `DeliveredQuantity`, `Price`, `VAT`, `VATCode`, `Discount`, `DiscountType`, `AccountNumber`), `Order` and `OrderRow`, `Customer` (`ExternalReference`, `Email`, `Type` PRIVATE or COMPANY, `VATNumber`, `CountryCode`), `Article` (`QuantityInStock`, `ReservedQuantity`, `DisposableQuantity`, `StockGoods`, `Type` STOCK or SERVICE), `InvoicePayment` (`InvoiceNumber`, `Amount`, `PaymentDate`, `ModeOfPayment`) | [third-party] mirror of [documented] | `Invoice.cs`, `InvoiceRow.cs`, `Order.cs`, `OrderRow.cs`, `Customer.cs`, `Article.cs`, `InvoicePayment.cs` |
| Action endpoints (`bookkeep`, `cancel`, `credit` …): "Credit an invoice" is listed in the reference sidebar; the SDK calls it `PUT /3/invoices/{DocumentNumber}/credit` | [documented] (name) / [third-party] (path) | https://api.fortnox.se/apidocs ; `InvoiceConnector.cs` |
| Warehouse (Lager) stock: `GET /api/warehouse/status-v1/stockbalance?itemIds=&stockPointCodes=` returning `itemId`, `stockPointCode`, `inStock`, `availableStock`; Lager is activated by a system administrator and cannot be activated through the API; outbound documents affect stock when saved | [third-party] (path, fields) / [documented] (Lager rules) | `StockBalanceConnector.cs`, `Endpoints.cs`, `StockBalance.cs`; https://fortnox.se/developer/guides-and-good-to-know/best-practices/warehouse-information |
| Terms: developer agreement and change policies; privacy notice of Fortnox Group | [documented] | https://www.fortnox.se/developer/guides-and-good-to-know/legal-and-change-policies ; https://apps.fortnox.se/api/eula/document-v1/user |

## 2. How the merchant connects

1. In Fortnox open Menu, Developer Portal, My Integrations, and create a new integration (needs a developer licence; sandboxes are free).
2. Paste the redirect address shown on this screen as the Redirect URI of the integration.
3. Tick the scopes Customer, Article, Invoice and Company Information, plus Order, Warehouse or Payment if used. Copy the Client ID and Secret.
4. Paste the Client ID and Client Secret here and press Sign in.
5. Sign in to Fortnox as a system administrator and approve the integration for your company.
6. Press Test connection, then choose the VAT rates in Tax mapping.

Notes for the readme: each Fortnox area needs its own licence (orders need Order, stock needs Lager, the API needs the integration add-on); the plugin asks only for the scopes the connection options need, so changing an option such as `document` or `record_payment` means signing in again; a refresh token lapses after 45 days without a refresh (the plugin refreshes on a schedule).

## 3. Authentication

OAuth 2 authorization code, shared `OAuthTokenManager`: client id and secret in a Basic header, PKCE S256 sent (accepted whether or not the app enforces it), `access_type=offline`, refresh under a lock, the new refresh token saved in the same write that stores the new access token.

| What | Where |
| --- | --- |
| Client ID | connection config `client_id` |
| Client Secret | encrypted secret |
| Access and refresh tokens, expiry | encrypted `oauth_*` secrets; the credential-expiry date follows the 45 day refresh window |
| Company name and database number | config `company_name`, `tenant_id` (set after sign-in) |
| Options (section 5) | config `options` |

Scopes requested: `companyinformation customer article` plus `invoice` (document `invoice`) or `order` (document `order`), plus `warehouse` when `stock_source = warehouse`, plus `payment` when `record_payment = yes`.

## 4. Data sent and when (External services disclosure)

| Host | Action | Data | When |
| --- | --- | --- | --- |
| `apps.fortnox.se` | Sign in, refresh | Client ID, Client Secret, code or refresh token | Connect, then at most once an hour of work and on a schedule |
| `api.fortnox.se` | Test connection | Reads company information, one customer, one article, one invoice | On request |
| `api.fortnox.se` | Send order | Customer name, email, phone, billing address, VAT number; order date, currency, order number `WC-{id}`, note; lines (article number, text, quantity, price, VAT percentage); shipping, fees, discount | When an order is queued |
| `api.fortnox.se` | Send refund | Customer number of the original, refund number `WC-{refund id}`, reason, refunded lines | When a refund is queued |
| `api.fortnox.se` | Read stock, invoice facts, reconcile list | Article numbers, order references | On the schedules |

## 5. Options

| Key | Values | Default |
| --- | --- | --- |
| `document` | `invoice`, `order` | `invoice` |
| `unknown_sku` | `fail` (SYNC-MAP-001), `free_text` (a row without an article number) | `fail` |
| `customer_policy` | `match_or_create`, `match_only` | `match_or_create` |
| `due_days` | days from the invoice date to the due date (0 to 90) | 0 |
| `bookkeep` | `no`, `yes` (`PUT …/bookkeep` after create) | `no` |
| `record_payment` | `no`, `yes` (needs `bookkeep = yes` and `payment_mode`) | `no` |
| `payment_mode` | Fortnox mode-of-payment code for the payment | empty |
| `stock_source` | `article` (stock fields of the article), `warehouse` (Lager stock balance) | `article` |
| `total_mismatch` | `fail`, `warn` | `fail` |

## 6. Objects and field mapping

| Payload path | Fortnox field |
| --- | --- |
| `partner.name` | `Customer.Name` |
| `partner.email` | `Customer.Email` (search key) |
| `partner.company_name` | sets `Customer.Type = COMPANY` (else `PRIVATE`) and `Name` |
| `partner.phone` | `Customer.Phone1` |
| `partner.street`, `street2`, `zip`, `city`, `country_id` | `Address1`, `Address2`, `ZipCode`, `City`, `CountryCode` (ISO alpha-2) |
| `partner.vat` | `Customer.VATNumber` |
| `currency` | `Invoice.Currency` / `Order.Currency` (omitted for SEK) |
| `date_order` | `InvoiceDate` or `OrderDate` (date part of the shop's order date); `DueDate = InvoiceDate + due_days` |
| `note` | `Remarks` |
| item `client_ref` | `ExternalInvoiceReference1 = WC-{order id}` (queue item, never the payload) |
| `order_line[].product` | `ArticleNumber` |
| `order_line[].name` | `Description` |
| `order_line[].quantity` | `DeliveredQuantity` (invoice) or `OrderedQuantity` (order) |
| `order_line[].price_unit` | `Price` (excluding VAT, or including when `VATIncluded` is true) |
| `order_line[].tax` | `VAT` (percentage from Tax mapping) |
| `prices_include_tax` | `VATIncluded` |
| `shipping`, `fee[]`, `discount` | rows without an article number (text, price, VAT) |

Money is converted from minor units with the currency exponent at the edge only. Row `Description` is cut at 50 characters with an ellipsis and a warning [not verified: the limit is Fortnox's usual row text length]. `ExternalInvoiceReference1` is never cut; a reference longer than 80 characters is refused. Arabic and other scripts are sent as UTF-8 unchanged. The delivery address (`DeliveryAddress1`, …) is sent from the shipping block when the mapping fills it.

Not sent: `VATType` of the customer (Fortnox applies its default; the total check shows a difference), cost centres and projects.

## 7. Duplicate protection

`ExternalInvoiceReference1` carries `WC-{order id}` (or `WC-{refund id}`) on the invoice, order or credit invoice. Before every create: `GET /3/invoices?externalinvoicereference1=WC-123` (or `/3/orders?…`), all pages, then an exact comparison of the returned `ExternalInvoiceReference1` in code (the server filter may be a "contains"). Several hits: the lowest document number. A new document is written with `ExternalInvoiceReference2 = sync-pending`. After the create the connector reads the record and compares currency and total with the order; only when that passes is the marker replaced (`WooCommerce` for an order, the order reference for a credit invoice). `findExisting()` returns a record only when it is complete: it exists, is not cancelled, carries no pending marker and, with `bookkeep = yes`, is booked, and, with `record_payment = yes`, its balance is 0. Otherwise it returns nothing and `send()` checks it again and finishes the missing steps, each of which is read first and run only when it is missing; a document with a wrong total stays marked in Fortnox and is never linked unchecked (a person cancels it there).

Customers: `GET /3/customers?email=<email>`, all pages, exact case-insensitive comparison of `Email` in code; none: `POST /3/customers`. Several: the lowest customer number.

## 8. Taxes

The tax mapping code is the VAT percentage as Fortnox row `VAT` ("25", "12", "6", "0"). `listTaxCodes()` returns those four Swedish statutory rates [inferred; Fortnox's list of valid percentages is account data and no endpoint for it was documented in the pages read]. A rate that is not mapped stops the order with SYNC-MAP-002; Fortnox itself rejects a percentage it does not know ("The percentage needs to be of an existing VAT percentage"), which comes back as SYNC-VAL-002.

Prices: `VATIncluded` follows `prices_include_tax`, so no conversion is needed; the price goes to Fortnox exactly as the shop shows it. After the create the connector reads `Total` and `Currency` and compares `Total` with the shop's total less the excluded products (one minor unit tolerance); a larger difference fails (`total_mismatch = fail`) or warns. [Not verified: whether `Total` is in the invoice currency or in SEK for a foreign-currency invoice; for a foreign currency the check compares in the invoice currency and fails loudly if Fortnox reports SEK.]

## 9. Payments

Off by default. `record_payment = yes` (with `bookkeep = yes`, because a payment is booked against a booked invoice) sends `POST /3/invoicepayments` with `InvoiceNumber`, `Amount`, `PaymentDate` (the date the order was paid), `ModeOfPayment` (option `payment_mode`) when the order is paid. Before it, the invoice is read: if its `Balance` is 0 nothing is sent, so a replay never pays twice. Foreign currencies are not paid automatically (Fortnox wants `AmountCurrency` and a rate); the order then fails with SYNC-VAL-002 naming the currency.

## 10. Refunds

A refund creates a second invoice with the refunded rows as negative quantities (`DeliveredQuantity` below zero), `ExternalInvoiceReference1 = WC-{refund id}`, `ExternalInvoiceReference2 = WC-{order id}` and `Remarks` naming the original, for the customer of the original document (found by `WC-{order id}`; none: the item fails before anything is created). After the create the total is read and must equal the negative refund amount (one minor unit). Not used: `PUT /3/invoices/{n}/credit`, which credits a whole invoice, cannot take a reference in the same call and so cannot be made safe against a lost answer; the SDK shows no way to set `CreditInvoiceReference`, which is read-only. [Not verified: whether Fortnox flags an invoice with negative rows as a credit invoice (`Credit`); the total check is what protects the books.]

## 11. Stock, invoice and reconcile reads

- `fetchStock()`: `stock_source = article`: `GET /3/articles?articlenumber=<sku>` per SKU (exact comparison in code), `QuantityInStock` and `ReservedQuantity` (held); articles that are services are left out. `stock_source = warehouse`: `GET /api/warehouse/status-v1/stockbalance?itemIds=…&stockPointCodes=…`, quantity = sum of `inStock`, held = `inStock - availableStock`, over the chosen stock points (all when none). Needs the Lager licence and scope `warehouse`.
- `fetchInvoice()`: `GET /3/invoices/{DocumentNumber}`: number, state (`cancelled`, `paid` when booked and balance 0, `booked`, `open`), no tax authority step (`authority_status` empty), no PDF link.
- `listForReconcile( $from )`: `GET /3/invoices?fromdate=<date>&limit=500`, every page, rows whose `ExternalInvoiceReference1` starts with `WC-` and that are not credit refunds of those orders; `total_minor` from `Total`; refunds are the credit rows whose `ExternalInvoiceReference2` is the order.
- Tracking: not offered (no shipment records in the Fortnox API pages read).

## 12. Rate limits and pacing

`RateLimitHints( requestsPerSecond: 4.0, concurrency: 1, requestsPerMinute: 300 )`: 4 calls a second stays under the 25 per 5 seconds window (5 a second). On 429 the connector returns SYNC-RATE-001 with a 5 second wait (the window length; Fortnox sends no `Retry-After`), which the queue honours.

## 13. Error mapping

| Fortnox answer | SYNC code | Class |
| --- | --- | --- |
| Token endpoint `invalid_grant` | SYNC-AUTH-002 (connection held for sign-in) | needs a person |
| Token endpoint `invalid_client` | SYNC-AUTH-001 | needs a person |
| 401, or error code 2000310, 2000311, 2003275 | SYNC-AUTH-002 | needs a person |
| Error code 2000663 (no access to the scope) | SYNC-AUTH-004 naming the area; "sign in again after changing options" | needs a person |
| Error code 2001101, 2001103, OAuth `error_missing_license`, `error_missing_app_license` | SYNC-AUTH-006 | needs a person |
| Other 403 | SYNC-AUTH-004 | needs a person |
| 429 | SYNC-RATE-001, 5 s | retry after the wait |
| Codes 2000204, 2000433, 2000637, 2000588, 2001392, 2001399, 2000106, 2000108, 2000134, 2000359, 2000729, and any other 400 | SYNC-VAL-002 with the message (Swedish text kept, cut to 200 characters) | needs a person |
| Code 1000003, 5xx, timeout | SYNC-NET-002 / SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| Unknown article | SYNC-MAP-001 | needs a person |
| Unmapped VAT rate | SYNC-MAP-002 | needs a person |
| Missing customer email | SYNC-MAP-003 | needs a person |
| Total or currency differs | SYNC-VAL-002 | needs a person |

## 14. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| Orders | true | invoices or orders |
| Customers | true | `/3/customers` |
| Refunds | true | negative-row credit invoice |
| StockIn | true | article stock fields, or Lager stock balance |
| TrackingIn | false | no shipment records read |
| PricesIn | false | not built |
| InvoiceReadback | true | invoice facts |
| Reconciliation | true | date-window list |
| Webhooks | false | Fortnox has websocket events only; the plugin polls |
| ShadowLookup | true | `findExisting()` is read-only |

## 15. Not verified

- The Redoc reference could not be read as text; endpoint paths other than the OAuth ones, the rate limit and the error codes come from the SDK mirror and a third-party guide.
- Whether the `email` filter of customers and the `externalinvoicereference1` filter of invoices match exactly or as "contains" (the connector compares exactly in code and pages through all pages).
- Maximum lengths of `ExternalInvoiceReference1`, row `Description` and `Remarks`.
- Whether a negative-quantity invoice is booked as a credit invoice by Fortnox, and whether `Total` of a foreign-currency invoice is in the invoice currency; whether `CurrencyRate` may be left empty.
- The shape of the Lager `stockbalance` answer (the connector accepts a list or an object holding a list, in either letter case).
- Whether an invoice must be booked before a payment is accepted (assumed; a refusal comes back as SYNC-VAL-002).
- Whether the VAT percentages in `listTaxCodes()` (25, 12, 6, 0) are all present in a given Fortnox company.
- `companyinformation` carrying `DatabaseNumber` (the documentation says to read the tenant id from there or from `/3/settings/company`).
- No live Fortnox company or sandbox was available. Behaviour above the wire is tested against `FakeFortnoxServer`, written from the shapes in section 1.

## 16. Manual smoke test

Use a Fortnox sandbox from the Developer Portal (free, up to 30).

1. Create an integration with the scopes of section 2 and the redirect address of the Connect screen; copy Client ID and Secret.
2. Add a Fortnox connection, paste them, press Sign in, approve as system administrator.
3. Press Test connection. Expected: company name, and a tick for customers, articles and invoices.
4. Create an article whose number equals a WooCommerce SKU. Choose the VAT rates in Tax mapping.
5. Place an order. Expected: one customer (email as the order), one invoice with `ExternalInvoiceReference1 = WC-{order id}`, a total equal to the shop total, the shop's order date as invoice date. Run the sync again: no second invoice.
6. Refund: expected one invoice with negative rows and reference `WC-{refund id}`.
7. With `bookkeep` and `record_payment` on (and a payment mode code), expect a booked invoice with balance 0.
8. Change an article's stock and run the stock read.
9. Remove the integration for the company in Fortnox and press Test connection: expected SYNC-AUTH-002.
