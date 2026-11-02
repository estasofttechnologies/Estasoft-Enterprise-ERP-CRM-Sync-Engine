---
id: business-central
name: Microsoft Dynamics 365 Business Central
catalog_row: Microsoft Dynamics 365 Business Central
connection_method: cloud_app
available: true
verified: false
hosts: [api.businesscentral.dynamics.com, login.microsoftonline.com]
terms_url: https://learn.microsoft.com/en-us/legal/microsoft-apis/terms-of-use
privacy_url: https://privacy.microsoft.com/privacystatement
developer_url: https://entra.microsoft.com
variants: []
---

# Microsoft Dynamics 365 Business Central connector

Connector id `business-central`, type ERP, contract version 1. Code: `includes/Connectors/BusinessCentral/`, shared Microsoft helpers in `includes/Connectors/Microsoft/` (also used by `dynamics-sales` and `dynamics-finance`). Business Central online only (SaaS). On-premises servers and the Microsoft sovereign clouds are not covered.

The connector sends WooCommerce orders as sales orders (optionally shipped and invoiced, or as posted sales invoices), refunds as sales credit memos, and customers as BC customers. It reads posted invoices back and lists documents for the nightly check. It does not read stock or tracking (BC has neither in its standard API, scope-update item 4) and records no payments (see Payments).

## Sources

Tags: `[documented]` official Microsoft page read on 2026-11-02, `[third-party]`, `[inferred]` (read from the documentation's shape, not confirmed by a page or a live run). Base: https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/

| Page | Used for |
| --- | --- |
| `administration/automation-apis-using-s2s-authentication` | Entra app, `API.ReadWrite.All`, BC "Microsoft Entra applications" page, token request, scope `https://api.businesscentral.dynamics.com/.default`, base URL `https://api.businesscentral.dynamics.com/v2.0/{environment}/api/v2.0` |
| `api-reference/v2.0/endpoints-apis-for-dynamics` | Common endpoint `.../v2.0/<environment name>/api/v2.0` and the direct-tenant form; the environment name must be in the URI |
| `webservices/api-endpoint-structure` | Entra ID optional in the path; company by `companies(<guid>)` in the path or `?company=<guid>` |
| `api-reference/v2.0/resources/dynamics_salesorder`, `..._salesorderline`, `api/dynamics_salesorder_create`, `api/dynamics_salesorderline_create` | Order and line properties, POST URLs, `shipAndInvoice` action, 201 answers |
| `api-reference/v2.0/resources/dynamics_salesinvoice`, `api/dynamics_salesinvoice_create` | Invoice properties, `post` action, status values, note on `id` after posting |
| `api-reference/v2.0/resources/dynamics_salescreditmemo` | Credit memo properties, `post` action, status values |
| `api-reference/v2.0/resources/dynamics_customer`, `dynamics_item`, `dynamics_taxgroup`, `dynamics_taxarea`, `api/dynamics_company_get` | Customer, item (incl. `inventory`, `gtin`, `blocked`), tax group, company |
| `api-reference/v2.0/resources/dynamics_customerpayment`, `dynamics_journal` | Payment journal lines and the `post` action of a journal (why payments are not recorded) |
| `administration/operational-limits-online`, `api-reference/v2.0/dynamics-rate-limits` | Limits, 429 and 503/504 behaviour, `$batch` limit 100, page size 20,000 |
| `webservices/web-service-troubleshooting` | HTTP status meanings (408, 409, 412, 429, 503 with `retry-after`, 504) |
| `webservices/server-driven-paging-in-odata-web-services` | `Prefer: odata.maxpagesize`, 20,000 maximum online |
| `webservices/use-odata-to-modify-data` | PATCH needs `If-Match` (ETag or `*`) |
| `webservices/use-filter-expressions-in-odata-uris` | `eq`, `and`, `startswith`, `contains`, `tolower`, `toupper`; OR across two different fields is not allowed; `tolower`/`toupper` disable case sensitivity |

Not fetchable in this session (the fetch tool refused them): the "Troubleshooting OData/API calls" error-code page (`webservices/dynamics-error-codes`), the API overview, and the Microsoft Entra error-code list. Facts from them are `[inferred]`.

## How the merchant connects

1. In the Microsoft Entra admin center, register an app and copy its Application (client) ID and Directory (tenant) ID.
2. Under Certificates and secrets, create a client secret and copy its value at once. Note its end date.
3. Add the API permission Dynamics 365 Business Central, Application, `API.ReadWrite.All`, then grant admin consent.
4. In Business Central, open Microsoft Entra applications, add the client ID, set State to Enabled, and assign permission sets (not SUPER).
5. Paste the tenant ID, client ID, secret and environment name here. Leave Company empty if the environment has one company.
6. Press Test connection. If there are several companies, type the name or ID of the one that gets the orders.

Who is needed: a Microsoft Entra administrator (steps 1 to 3) and a Business Central administrator (step 4). `[documented]` No Business Central licence is required for the app account itself; the page refers to the licensing guide for scenarios `[documented]`. No redirect address is needed: this is the client credentials grant, nobody signs in on a Microsoft page.

Permission sets: the S2S page names `D365 AUTOMATION` and `EXTEN. MGT. - ADMIN` and says the SUPER set cannot be assigned `[documented]`. What the app needs to read and write customers, items, sales documents and posting is `[inferred]`: a set that includes sales order entry, customer and item read, and (for posting) posting rights; the smoke test below finds out what is missing (SYNC-AUTH-004).

## Authentication

- Grant `client_credentials` against `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`, scope `https://api.businesscentral.dynamics.com/.default` `[documented]`. The shared `Microsoft\EntraTokenProvider` builds this for the engine's `OAuthTokenManager`, client secret in the form body.
- Stored: `tenant_id`, `client_id`, `environment`, `company` in the connection config (not secret); `client_secret` encrypted. The access token and its end time are kept by the token manager in the reserved secrets `oauth_access_token` and `oauth_expires_at`. There is no refresh token; a new token is requested when less than five minutes remain `[documented: token lifetime of one hour is the Entra default; the S2S page does not state it]`.
- The tenant is checked before use (GUID or a domain name) so it cannot change the address.
- A client secret ends on the date the Entra administrator picked. A refused expired secret is SYNC-AUTH-005.

## Data sent and when (WordPress.org "External services")

| Host | When | What is sent |
| --- | --- | --- |
| `login.microsoftonline.com` | Before the first call and about once an hour | Tenant id in the address, client id, client secret, the scope. Microsoft's identity service. |
| `api.businesscentral.dynamics.com` | Test connection | Nothing but the token; reads the company list, a customer, an item, tax groups and currencies |
| `api.businesscentral.dynamics.com` | An order is synced | Customer name, email, phone, billing and delivery address, tax registration number, the order date, currency, line items (SKU, name, quantity, price, tax code), shipping, fees, discount, the reference `WC-{order id}`; the order is shipped and invoiced or posted only when the option asks for it |
| `api.businesscentral.dynamics.com` | A refund is synced | The refund lines, amounts and reason, the reference `WC-{refund id}` and `WC-{order id}` |
| `api.businesscentral.dynamics.com` | Nightly check, invoice read-back, SKU matching | Reads only (document lists, an invoice, items) |

No data goes anywhere else. The plugin contains no Microsoft app: each merchant registers their own, so nothing is shared with Estasoft.

## Objects and field mapping

Endpoint base: `https://api.businesscentral.dynamics.com/v2.0/{environment}/api/v2.0/companies({company id})/`. The environment name defaults to `production` `[documented as an example name, not as a default]`.

| Canonical field | Target path (mapping) | Business Central |
| --- | --- | --- |
| `order.client_ref` (taken from the queue item) | none | `salesOrders.externalDocumentNumber` / `salesInvoices.externalDocumentNumber` = `WC-{order id}` |
| `order.created_at` | `order_date` | `orderDate` and (option `posting_date`) `postingDate` / `invoiceDate` |
| `order.currency` | `currency` | `currencyCode` |
| `order.note` | `note` | a Comment line (see Duplicate protection) |
| `order.total`, `order.excluded_total` | `total`, `excluded_total` | not sent; compared with `totalAmountIncludingTax` |
| `billing.email` / `customer.email` | `partner.email` | `customers.email` (search) |
| `billing.first_name + last_name` | `partner.name` | `customers.displayName` |
| `billing.company` | `partner.company_name` | `customers.displayName` (type Company) |
| `billing.phone` | `partner.phone` | `customers.phoneNumber` |
| `billing.address_1, address_2` | `partner.street`, `partner.street2` | `addressLine1`, `addressLine2` |
| `billing.city, state, postcode, country` | `partner.city`, `partner.state_id`, `partner.zip`, `partner.country_id` | `city`, `state`, `postalCode`, `country` (ISO 2 letters, as the page's example shows) |
| `order.buyer_tax_number` | `partner.vat` | `customers.taxRegistrationNumber` |
| `shipping.*` | `delivery.name`, `.phone`, `.street`, `.street2`, `.city`, `.state_id`, `.zip`, `.country_id` | `shipToName`, `shipToAddressLine1/2`, `shipToCity`, `shipToState`, `shipToPostCode`, `shipToCountry` |
| `lines[].sku` | `order_line[].product` | `salesOrderLines.lineObjectNumber` (`lineType` Item); the item is found by `items.number` (or `gtin` with option `sku_field`) |
| `lines[].name` | `order_line[].name` | `description` |
| `lines[].quantity` | `order_line[].quantity` | `quantity` |
| `lines[].unit_price` | `order_line[].price_unit` | `unitPrice` (decimal from minor units) |
| `lines[].tax_rate` through the tax map | `order_line[].tax` | `taxCode` |
| `order.shipping_total`, `shipping_lines[].tax_rate` | `shipping.total`, `shipping.tax`, `shipping.name` | a line on the item or G/L account of options `shipping_item` / `shipping_account` |
| `fees[]` | `fee[].name`, `fee[].total`, `fee[].tax` | lines on `fee_item` / `fee_account` |
| `order.discount_total`, `coupons[]` | `discount.total`, `discount.name`, `discount.tax` | one negative line on `discount_item` / `discount_account` |
| `order.payment.transaction_id` | `payment.reference` | appended to the Comment line |
| `refund.*` | `order_ref`, `date`, `reason`, `amount`, `refund_line[].product/.name/.quantity/.amount/.tax`, `shipping.total/.tax` | `salesCreditMemos` and `salesCreditMemoLines` |

A sales order line needs an item or a G/L account in BC. `lineType` values Item and Account are used; Comment is used for notes. `[documented]` list: Comment, Account, Item, Resource, Value, Fixed Asset, Charge.

Money: integer minor units inside the plugin; at the edge `Core\Support\Money` converts with the currency's exponent (KWD, BHD, OMR 3 decimals, JPY 0) using string arithmetic only. BC amounts are JSON numbers (decimal) `[documented]`. Values read back (floats from JSON) are rounded at the currency's precision with `number_format` before they become integers.

Dates: BC takes `date` values (`2023-09-01`) `[documented]`. The mapping's date rule sends the UTC date of the order; add `output_timezone: store` to send the shop's own date.

Field lengths `[inferred from AL, not on the pages]`: `externalDocumentNumber` is Code[35], so `WC-` plus the id is always short enough; names and addresses longer than BC accepts make BC answer 400, shown as SYNC-VAL-002 with BC's own short reason. The connector shortens `description` (100 characters) and the Comment line (100 characters) with an ellipsis and logs a warning; it never shortens the reference.

## Duplicate protection

- Reference: `externalDocumentNumber = WC-{order id}` on the sales order (or sales invoice). BC does not make it unique `[documented in the research]`, so the connector searches before every create: `GET salesOrders?$filter=externalDocumentNumber eq 'WC-123'` (and `salesInvoices` for the invoice modes). The search is server side and follows `@odata.nextLink`; it never takes the first rows and filters in PHP, except for the final exact comparison of the returned references.
- A refund uses `externalDocumentNumber = WC-{refund id}` on `salesCreditMemos`.
- An unfinished record is never reported by `findExisting()`. A record counts as finished when the connector's own steps are done: a completion marker (a Comment line written as the last line, `WC-123 synced, N lines`) exists, and for `documents = order_ship_invoice` or `invoice` the document is posted. `send()` finds the unfinished record and continues; it never creates a second header.
- Resumable steps (every pass starts from a read): find document, read its lines, create only the lines that are missing (lines are created in order, so the lines present must equal the first lines of the payload, otherwise SYNC-VAL-002 "the document was changed in Business Central"), write the marker, check totals, then ship-and-invoice or post. Posting steps are never repeated blindly: before `shipAndInvoice` or `post` the state is read (an order that is gone and a posted invoice with the reference means done).
- If a posting call times out, the next pass reads first: the order is still there (call again) or the posted invoice exists (done).
- Customer: found by email, case-insensitively, before any create (below).

## Taxes

- BC computes tax from the customer's tax or VAT business group and the line's tax group (`taxCode`). The tax groups are listed by `taxGroups` (code, display name, `taxType` "Sales Tax" or "VAT") `[documented]`. **The rates are not in the API**, so `listTaxCodes()` returns code and name with an empty rate and the name says "rate set in Business Central".
- The plugin's tax mapping (country + rate to code) chooses `taxCode` per line, shipping, fee and discount. A line without a mapped code gets no `taxCode`, so BC uses the item's tax group.
- Tax-included prices (pitfall 2): `pricesIncludeTax` of an order is read-only and follows the customer `[documented]`. WooCommerce sends tax-exclusive line prices unless the mapping sets `prices_include_tax`. After the lines exist the connector reads the header, compares the payload mode with `pricesIncludeTax`, and compares `totalAmountIncludingTax` with `total - excluded_total` in minor units. One minor unit is rounding. A larger difference stops the order before shipping or posting with SYNC-VAL-002 that names both totals and, when the modes differ, says so (option `total_mismatch = warn` lets it go on with a warning in the result). The unfinished draft stays in BC; correct the customer or tax setting, delete the draft and retry.
- Discounts: an order discount is sent as a negative line; give it a tax group like the goods, otherwise BC does not tax it and the totals differ.

## Payments

Not recorded. BC's API records a customer payment as a journal line (`customerPayments`) that a journal's `post` action posts; the pages disagree about the URL (the "HTTP request" section and the example differ), a balancing account must be set on the journal, and posting an unreviewed payment into the ledger is the merchant's accounting decision. The connector does not flag it and has no option for it. `[documented]` pages: `dynamics_customerpayment`, `dynamics_journal`.

## Refunds

A refund item creates a sales credit memo for the same customer and currency as the order (found by `WC-{order id}` among open orders and posted invoices; if the order is not in BC the item fails before anything is created, SYNC-VAL-002). Header: `customerNumber`, `externalDocumentNumber = WC-{refund id}`, `creditMemoDate`, `postingDate`, `currencyCode`. Lines: one Item line per refunded product line, one line for refunded shipping (`shipping_item`/`shipping_account`) and, when the refund has an amount no line explains (a manual amount, fees), one line on `refund_item`/`refund_account`; a Comment line `Refund of WC-{order id}` first and the marker last. The line amount is divided by the quantity only when it divides exactly, otherwise one line of quantity 1 carries the amount and the quantity goes into the description.

Option `refund_state`: `draft` (default) leaves the credit memo for a person to post; `post` calls `Microsoft.NAV.post` after the totals check. A posted credit memo cannot be undone, so posting is off by default. The credit memo is not linked to the invoice (`invoiceNumber`/`invoiceId` are not written): whether BC accepts them at create time is `[inferred]`.

## Stock, tracking, invoice read-back

- Stock: not available per location. `items.inventory` is one decimal "how many units of the item are in inventory" `[documented]`, with no location and no reserved quantity. The scope update says BC has no per-location stock, so `stock_in` is not flagged. (A total-only read could be added later; it would ignore the warehouse choice.)
- Tracking: BC shipments carry no tracking number field in the standard API (scope-update item 4), so `tracking_in` is not flagged.
- Invoice read-back (`invoice_readback`, flagged): `fetchInvoice()` returns the posted invoice of an order: number, `status` ("Open", "Paid", "Canceled", "Corrective"), `invoiceDate`. `authority_status`, `pdf_url` and `qr` are empty: BC has no tax-authority state in this API. Found by `orderNumber` (order modes) or `id`.
- Reconciliation (flagged): `listForReconcile()` reads, server side, `salesOrders` and `salesInvoices` with `startswith(externalDocumentNumber,'WC-')` and a document date on or after the window start, and credit memos with the same prefix; `total_minor` is `totalAmountIncludingTax`; `refunded_minor` is the sum of the credit memos whose Comment line names the order; `email` is the document email.

## Rate limits and pacing

Documented `[documented]`: per environment 600 requests per minute (production) or 300 (sandbox); per user 6,000 requests in a 5-minute sliding window; 5 concurrent requests (more wait in a queue, 503 after 8 minutes); 95 queued requests, 100 connections (429); request timeout 8 minutes online (408) or 10 minutes (the rate-limits page says 504); `$batch` of at most 100 operations; page size at most 20,000. The limits apply to service principals the same way as to users.

The connector paces to 4 requests a second and at most 300 a minute (the sandbox figure, because the environment type is not known), one request at a time. A 429 or 503 returns SYNC-RATE-001 or SYNC-NET-002 with the `Retry-After` value when BC sends one (the 503 page says to use the `retry-after` header `[documented]`; for 429 the pages do not mention the header, `[inferred]` that it may be absent, then 30 seconds). Each request has a 30 second timeout. The connector does not use `$batch`.

## Error mapping

| BC answer | Code | Class |
| --- | --- | --- |
| Token endpoint: wrong secret or client (`invalid_client`) | SYNC-AUTH-001 | needs a person |
| Token endpoint: secret expired (`invalid_client` with "expired", AADSTS7000222 `[inferred]`) | SYNC-AUTH-005 | needs a person |
| API 401 after one token refresh (the Entra app is not enabled in "Microsoft Entra applications") `[inferred]` | SYNC-AUTH-007 | needs a person |
| API 403 (permission set too small) | SYNC-AUTH-004 | needs a person |
| 404 on the company or environment | SYNC-VAL-002 (field company / environment) | needs a person |
| 400 and other 4xx with `{"error":{"code","message"}}` | SYNC-VAL-002 with BC's short reason, redacted | needs a person |
| 409 (edit conflict `[documented]`), 412 (precondition) | SYNC-NET-002 (status 409 or 412) | retry |
| 408, 504, timeout, reset | SYNC-NET-001 | retry |
| 429 | SYNC-RATE-001 with the wait | retry after the wait |
| 500, 502, 503 (with `retry-after` where sent) | SYNC-NET-002 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| SKU not an item, or a blocked item | SYNC-MAP-001 | needs a person |
| Tax code not mapped | SYNC-MAP-002 | needs a person |
| Customer email missing | SYNC-MAP-003 | needs a person |
| Currency came back different, totals differ, document changed by hand, order of a refund not in BC | SYNC-VAL-002 | needs a person |
| A plan or licence gate | not used: BC online has no "outside access" plan switch in the documents read (SYNC-AUTH-006 is never raised) | |

Secrets, tenant id, environment name and company id are removed from every message and log entry (`Redaction` is given them).

## Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | true | sales orders and sales invoices |
| `customers` | true | find or create customers |
| `refunds` | true | sales credit memos |
| `stock_in` | false | no per-location stock in the standard API |
| `tracking_in` | false | no tracking field |
| `prices_in` | false | not built |
| `invoice_readback` | true | `salesInvoices` |
| `reconciliation` | true | server-side document lists |
| `webhooks` | false | BC webhook subscriptions expire after three days and need a handshake; not used, the connector polls |
| `shadow_lookup` | true | customer by email, item by number, product search |

## Connection options

Shown on the connect screen under Advanced; read from `config.options` or the same keys in the config.

| Key | Values | Default |
| --- | --- | --- |
| `documents` | `order`, `order_ship_invoice`, `invoice` | `order` |
| `customer_policy` | `match_or_create`, `match_only`, `fixed` | `match_or_create` |
| `fixed_customer` | customer number used when policy is `fixed` | empty |
| `unknown_sku` | `refuse`, `account` | `refuse` |
| `unknown_sku_account` | G/L account number for the `account` policy | empty |
| `sku_field` | `number`, `gtin` | `number` |
| `posting_date` | `order` (the shop's order date), `today` | `order` |
| `total_mismatch` | `fail`, `warn` | `fail` |
| `refund_state` | `draft`, `post` | `draft` |
| `shipping_item`, `fee_item`, `discount_item`, `refund_item` | an item number (a service item) | empty |
| `shipping_account`, `fee_account`, `discount_account`, `refund_account` | a G/L account number | empty |

A shipping, fee, discount or refund amount with neither an item nor an account configured is refused with SYNC-VAL-002 that names the option. The connector never creates items or accounts.

## Not verified

- Everything below the wire is untested against a live Business Central: no account was available. All behaviour is tested against a fake (`FakeBusinessCentralServer`, with the shared `FakeMicrosoftEntra` and `FakeMicrosoftOData`) that follows the pages above. The wire fixtures in `tests/php/fixtures/business-central/` and `tests/php/fixtures/microsoft/` use the shapes of the pages cited in `MicrosoftWireFixturesTest` with synthetic values; the token error texts (AADSTS codes) are `[inferred]` because the Entra error list could not be read.
- The status a freshly created order has ("Draft"), and whether comment lines may be created through the line endpoint, are read from the pages' enumerations and examples, not run.
- Whether BC filters `eq` on text case-insensitively is not stated; the page says `tolower`/`toupper` "disable case sensitivity", so the customer search uses `tolower(email) eq '…'`, falls back to a plain `eq` when BC answers 400 to it, and always compares the returned email in PHP.
- Quote escaping inside a filter value (a doubled `'`) is the OData rule `[inferred]`; the BC pages say only "delimit string values by single quotation marks".
- Date literals in filters (`orderDate ge 2026-01-01`) are the OData form `[inferred]`.
- Whether an API-created customer gets posting groups and a tax area from a template. If not, posting an order fails with BC's own message (SYNC-VAL-002). Use `customer_policy = fixed` or `match_only` with prepared customers.
- The 429 answer's `Retry-After` header is not described by the pages.
- The field length limits above come from the AL tables, not from these pages.
- `pricesIncludeTax` is read-only on documents; how BC picks it for an API-created customer is not documented. The total check is what protects the books.
- The permission sets an app needs beyond `D365 AUTOMATION` are not listed by the page.
- Whether `post` on a credit memo or invoice returns 204 only after posting finished (synchronous) is `[inferred]`; the connector reads the state again afterwards.
- Regional availability: Microsoft localises BC for many countries; the research found no localisation for Saudi Arabia or the UAE. Tax and e-invoicing rules of a country are BC's, not this connector's; the connector makes no compliance claim.

## Manual smoke test

No credentials are stored in the repository. Use a BC sandbox environment and an Entra app you create.

1. Follow the six steps above in a sandbox environment; choose a client secret with a short end date.
2. In WordPress add a Business Central connection with the tenant ID, client ID, secret and the environment name (for example `sandbox`). Press Test connection. Expected: the company name, the BC version, and the tax groups found. A wrong secret gives SYNC-AUTH-001; an app not enabled in BC gives SYNC-AUTH-007; a missing permission set gives SYNC-AUTH-004 on the first write.
3. Create an item in BC whose number equals a WooCommerce SKU. Map a tax group in Tax mapping.
4. Place a WooCommerce order. Expected: one sales order whose External Document No. is `WC-{order id}` with the lines and a last Comment line `WC-… synced`. Sync it again: no second order.
5. Set `documents = order_ship_invoice`, place an order, and check that BC shows a posted sales invoice with the same External Document No. and that stock fell.
6. Refund the order in WooCommerce. Expected: a sales credit memo (draft) whose External Document No. starts `WC-`.
7. Place an order in a currency your BC does not have: expected SYNC-VAL-002 naming the currency.
8. Run the nightly check from the Reconciliation screen.
