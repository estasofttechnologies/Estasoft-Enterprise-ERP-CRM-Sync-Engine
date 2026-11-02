---
id: dynamics-sales
name: Dynamics 365 Sales
catalog_row: Dynamics 365 Sales
connection_method: cloud_app
available: true
verified: false
hosts: ["*.dynamics.com", "login.microsoftonline.com"]
terms_url: https://learn.microsoft.com/en-us/legal/microsoft-apis/terms-of-use
privacy_url: https://privacy.microsoft.com/privacystatement
developer_url: https://entra.microsoft.com
variants: []
---

# Dynamics 365 Sales connector

Connector id `dynamics-sales`, type CRM, contract version 1. Code: `includes/Connectors/DynamicsSales/`, shared Microsoft helpers in `includes/Connectors/Microsoft/` (also used by `business-central` and `dynamics-finance`). It talks to the Dataverse Web API v9.2 of one Dynamics 365 Sales environment.

A WooCommerce order becomes a **deal**: an account (only when the buyer gave a company name), a contact, an opportunity with its products, and the order reference. A refund becomes a **note on that opportunity**. Customers are contacts. The connector reads nothing back about stock, tracking or invoices (a CRM has none).

## Sources

Tags: `[documented]` official Microsoft page read on 2026-11-02, `[third-party]`, `[inferred]`. Base for Power Apps pages: https://learn.microsoft.com/en-us/power-apps/developer/data-platform/

| Page | Used for |
| --- | --- |
| `authenticate-oauth` | Application user bound to a registered app, no licence consumed, confidential client scope `<environment-url>/.default`, token lifetime default 60 minutes |
| `webapi/create-entity-web-api` | `POST /api/data/v9.2/accounts`, 204 with `OData-EntityId`, `Prefer: return=representation` gives 201 and the row, `@odata.bind` for lookups, deep insert is atomic |
| `webapi/compose-http-requests-handle-errors` | Error body `{"error":{"code","message"}}` (code often empty), required headers (`Accept`, `OData-MaxVersion`, `OData-Version`, `If-None-Match: null`), status table (412 = ConcurrencyVersionMismatch or DuplicateRecord, 429, 503) |
| `webapi/query/page-results` | Default 5,000 rows a page, `Prefer: odata.maxpagesize` (up to 5,000), `@odata.nextLink` used unchanged, `$top` ignored with maxpagesize, no `$skip` |
| `webapi/query/filter-rows` | "All filters that use string values are case insensitive", `startswith`/`endswith`/`contains`, a single quote in a value is doubled |
| `use-upsert-insert-update-record` | Alternate-key `PATCH`, `If-Match: *`, `If-None-Match: *` (not used, see Duplicate protection) |
| `api-limits` | Service protection: 6,000 requests, 20 minutes execution, 52 concurrent per user per 5-minute window; 429 with `Retry-After` in seconds; hex error codes `0x80072322`, `0x80072321`, `0x80072326` |
| `reference/entities/annotation`, `contact` (and Dynamics 365 `entities/opportunity`, `opportunityproduct`, `product`) | Table and column names, required levels, entity set names `annotations`, `contacts`, `opportunities`, `opportunityproducts`, `products` |
| `webapi/reference/whoami` | `WhoAmI` function exists (its response fields were not on the page read) |

Not fetched in this session (refused or out of search budget): the Microsoft Entra error-code list, the application-user admin page, the `transactioncurrency` and `uom` references, the `WinOpportunity` action page. Facts from them are `[inferred]` or `[third-party]` and are listed under Not verified.

## How the merchant connects

1. In the Microsoft Entra admin center, register an app and copy its Application (client) ID and Directory (tenant) ID.
2. Create a client secret for the app and copy its value at once. Note its end date.
3. In the Power Platform admin center open the environment, Settings, Application users, New app user, and pick your app.
4. Give the app user a security role: create, read and write Opportunity, Opportunity Product, Account, Contact and Note; read Product.
5. Paste the tenant ID, client ID, secret and the environment URL, for example `https://contoso.crm4.dynamics.com`.
6. Press Test connection. Dynamics 365 Sales must be installed in that environment.

Who is needed: a Microsoft Entra administrator (steps 1 and 2) and an environment administrator (steps 3 and 4); the research expects two people and the pages do not name the roles `[inferred]`. The application user needs no paid licence `[documented]`. Dynamics 365 Sales must be installed: the free Developer Plan cannot install Dynamics 365 apps `[documented in the research]`. The admin-centre click path in step 3 is from the research round, not re-read today.

## Authentication

- Grant `client_credentials` against `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` with scope `{environment URL}/.default` `[documented]` (the environment URL without a trailing slash). Built by `Microsoft\EntraTokenProvider` for the engine's `OAuthTokenManager`; the secret goes in the form body.
- Stored: `tenant_id`, `client_id`, `environment_url` in the config; `client_secret` encrypted. Tokens are kept by the token manager (`oauth_access_token`, `oauth_expires_at`); there is no refresh token.
- The environment URL is checked before use: https, a `*.dynamics.com` host, no credentials, no path (a path is dropped), trailing slash removed. Regional hosts (`crm`, `crm3`, `crm4`, `crm8`, `crm9`, `crm11`, `crm12`, `crm14`, `crm15`, `crm16`) all end in `.dynamics.com` `[documented in the research]`. China and US government clouds use other domains and are not supported.
- Every request sends `Accept: application/json`, `OData-MaxVersion: 4.0`, `OData-Version: 4.0`, `If-None-Match: null` `[documented]`.

## Data sent and when (WordPress.org "External services")

| Host | When | What is sent |
| --- | --- | --- |
| `login.microsoftonline.com` | Before the first call and about once an hour | Tenant id, client id, client secret, the scope |
| `{your environment}.crm*.dynamics.com` | Test connection | The token; reads who the app user is, the organisation name, the version |
| the same host | An order is synced | Buyer name, email, phone, billing address, company name, tax number (only in the description of the account when mapped), the order date, currency, line items (SKU, name, quantity, price, tax amount), shipping, fees, discount, the reference `WC-{order id}`, the order note and payment reference |
| the same host | A refund is synced | A note: the refund id, the order reference, the amount, the reason |
| the same host | Nightly check, SKU matching, shadow lookups | Reads (opportunities, notes, products, contacts) |

Nothing is sent to Estasoft or anyone else. The merchant registers their own Entra app; the plugin contains no Microsoft app.

## Objects and field mapping

Base: `{environment URL}/api/data/v9.2/`. Money fields are in the record's currency (`transactioncurrencyid`), decimals with the currency's exponent `[documented: Money columns]`.

| Canonical field | Target path | Dataverse |
| --- | --- | --- |
| `billing.email` / `customer.email` | `partner.email` | `contacts.emailaddress1` (search, first by `createdon`) |
| `billing.first_name`, `last_name` | `partner.first_name`, `partner.last_name` | `contacts.firstname`, `lastname` (last name required; a single name goes to `lastname`) |
| `billing.phone` | `partner.phone` | `contacts.telephone1` |
| `billing.address_1/2, city, state, postcode, country` | `partner.street`, `.street2`, `.city`, `.state_id`, `.zip`, `.country_id` | `address1_line1`, `address1_line2`, `address1_city`, `address1_stateorprovince`, `address1_postalcode`, `address1_country` `[documented: columns and lengths 250, 250, 80, 50, 20, 80]` |
| `billing.company` | `partner.company_name` | `accounts.name`; the contact gets `parentcustomerid_account@odata.bind` |
| `order.buyer_tax_number` | `partner.vat` | appended to the opportunity description (Dataverse accounts have no standard tax number column) |
| `order.client_ref` | none (from the queue item) | opportunity `name` begins `WC-{id} - ` (or the column of option `reference_column`) |
| `order.created_at` | `order_date` | `estimatedclosedate` (date) |
| `order.currency` | `currency` | `transactioncurrencyid@odata.bind` to the `transactioncurrencies` row whose `isocurrencycode` matches |
| `order.note`, `order.payment.transaction_id` | `note`, `payment.reference` | `description` (cut to 2,000 characters with an ellipsis before the marker) |
| `order.total`, `order.excluded_total` | `total`, `excluded_total` | `estimatedvalue`; compared with `totalamount` |
| `lines[].sku` | `order_line[].product` | `opportunityproducts.productid@odata.bind` (existing product, found by `products.productnumber`) or write-in (`productdescription`, `isproductoverridden = true`) |
| `lines[].name` | `order_line[].name` | `productdescription` (write-in) / `opportunityproductname` |
| `lines[].quantity`, `unit_price` | `order_line[].quantity`, `order_line[].price_unit` | `quantity`, `priceperunit` with `ispriceoverridden = true` |
| `lines[].line_tax` | `order_line[].tax_amount` | `tax` (money) |
| `order.shipping_total`, `shipping_lines[].tax` | `shipping.total`, `shipping.tax_amount`, `shipping.name` | one write-in line "Shipping" |
| `fees[]` | `fee[].name`, `.total`, `.tax_amount` | write-in lines |
| `order.discount_total` | `discount.total` | `opportunities.discountamount` (money, not negative) |
| `refund.*` | `order_ref`, `refund_id`, `date`, `reason`, `amount` | `annotations.subject`, `notetext`, bound to the opportunity |

Opportunity: `name` is the topic (300 characters at most `[documented]`), `customerid` is the account for a company buyer else the contact (`customerid_account@odata.bind` or `customerid_contact@odata.bind`, polymorphic lookup `[documented: the page's example uses customerid_account; the contact form is inferred]`), `estimatedvalue` the expected total with tax.

Lines of an existing product need a unit of measure: the product's `defaultuomid` is read with the product and bound as `uomid@odata.bind` `[inferred]`. The opportunity's price list is not forced: with `ispriceoverridden = true` the order's own price wins; option `price_list` binds `pricelevelid` for environments that insist on one.

Money: minor units inside, `Core\Support\Money` at the edge, string arithmetic only; the currency's decimal places (`currencyprecision` of the `transactioncurrency` row) are read, and when they are fewer than the ISO exponent (a KWD currency set up with 2 places) the order is refused with SYNC-VAL-002 naming the currency, instead of storing a rounded amount.

## Duplicate protection

- Reference: the opportunity `name` starts with `WC-{order id} - ` and is searched with `startswith(name,'WC-123 - ')` (string filters are case-insensitive `[documented]`) before every create; the hits are compared in PHP for the exact prefix, so `WC-12` never matches `WC-123`. The search follows `@odata.nextLink`.
- Option `reference_column`: the logical name of a custom text column that holds exactly `WC-{order id}` (the merchant adds it in Power Apps, Tables, Opportunity, New column). When set, the search is `$filter=<column> eq 'WC-123'` and the column is written. It survives someone renaming the topic. The Dataverse docs support alternate keys (`PATCH entityset(key=value)`, `If-None-Match: *`) `[documented]`; the connector does not use them because a key needs a solution or a column and index the plugin cannot ship, and search-before-create is what the contract asks for.
- Account and contact: found before creating (contact by `emailaddress1`, account by `name`), so a retry never makes a second one. Several matches: the oldest by `createdon`.
- Completion marker: the opportunity description ends with a line `Estasoft Sync WC-123 lines=3 email=buyer@example.test`, written in the same POST that creates the opportunity. `findExisting()` reports the opportunity only when the marker is there and the number of `opportunityproducts` of the opportunity (paged) is at least the number in the marker. `send()` finds an unfinished opportunity, creates the products that are missing (in payload order, matched by line number), and then checks the total. A marker that a person removed makes the record unfinished: `send()` completes it from the payload and writes the marker again.
- Refund: the note subject is `Refund WC-{refund id} of WC-{order id}`; a note on the same opportunity whose subject starts with `Refund WC-{refund id} ` is returned instead of creating another.

## Taxes

A CRM has no tax codes. Tax is carried as amounts: each line's `tax` is the WooCommerce line tax; shipping and fees carry theirs; nothing is mapped to a Dataverse tax object, so `listTaxCodes()` is empty and SYNC-MAP-002 is never raised. Tax-inclusive prices do not exist here: prices are always sent tax-exclusive and the tax separately. After the lines exist the connector reads `totalamount` and compares it with `total - excluded_total` (one minor unit of tolerance). A larger difference stops with SYNC-VAL-002 naming both totals (option `total_mismatch = warn` lets it go on with a warning). `totalamount` being the sum of the lines plus tax plus freight minus discount is `[inferred]` from the column descriptions, not stated on the pages read; a total of 0 is reported as "could not be read" and treated as a warning, not a failure.

## Payments

None. A CRM has no payment records. The payment reference is text in the description.

## Refunds

A refund item adds a note (`annotations`) to the opportunity of the original order: subject `Refund WC-{refund id} of WC-{order id}`, text with the amount in the order's currency, the reason, and the marker `[estasoft:refund WC-456 minor=1250 AED]`. It is bound with `objectid_opportunity@odata.bind` `[inferred]`: the Note reference lists the navigation property pattern `objectid_<table>` for account, contact, task and others, and its target list did not show opportunity (the page read was cut); opportunities do take notes in Dynamics 365, so this is expected to work and is listed under Not verified. The opportunity must exist (otherwise SYNC-VAL-002 before anything is sent). The deal's value is not changed.

## Stock, tracking, invoice read-back

Not applicable (CRM). Flags false.

Reconciliation (flagged): `listForReconcile()` reads opportunities whose name starts `WC-` and were created on or after the window start (server side, paged), with `estimatedvalue`; `total_minor` is `estimatedvalue` in the order's currency; `refunded_minor` is the sum of the `minor=` markers of the refund notes of those opportunities (notes read with `startswith(subject,'Refund WC-')`, paged); `email` comes from the marker.

## Rate limits and pacing

`[documented]` 6,000 requests, 20 minutes of execution time and 52 concurrent requests per user within a 5-minute sliding window; error codes `0x80072322` (requests), `0x80072321` (execution time), `0x80072326` (concurrent); HTTP 429 with `Retry-After` in seconds. The connector sends one request at a time, at most 4 a second and 1,200 a minute; a 429 returns SYNC-RATE-001 with the `Retry-After` seconds. Each request has a 30 second timeout.

## Error mapping

| Dataverse answer | Code | Class |
| --- | --- | --- |
| Token endpoint wrong secret or client | SYNC-AUTH-001 | needs a person |
| Token endpoint secret expired (AADSTS7000222 `[inferred]`) | SYNC-AUTH-005 | needs a person |
| API 401 after one token refresh (no application user in the environment, message "not a member of the organization" `[third-party]`) | SYNC-AUTH-007 | needs a person |
| 403 (`PrivilegeDenied`, `AccessDenied`, `CrmSecurityError` `[documented]`); the privilege named in the message (`prvCreateOpportunity` `[third-party]`) is turned into "create opportunities" | SYNC-AUTH-004 | needs a person |
| 404 on a table name ("Resource not found for the segment" `[third-party]`): Sales is not installed | SYNC-VAL-002 (field table, reason "Dynamics 365 Sales is not installed in this environment") | needs a person |
| 412 `ConcurrencyVersionMismatch` | SYNC-NET-002 (status 412) | retry |
| 412 `DuplicateRecord`, 400, other 4xx | SYNC-VAL-002 with the short message, redacted | needs a person |
| 429 | SYNC-RATE-001 with `Retry-After` | retry after the wait |
| 408, 504, timeout, reset | SYNC-NET-001 | retry |
| 500, 501, 502, 503 | SYNC-NET-002 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| SKU not a product with `unknown_sku = refuse` | SYNC-MAP-001 | needs a person |
| Customer email missing | SYNC-MAP-003 | needs a person |
| Currency missing in Dataverse, too few decimals, total differs, original opportunity missing | SYNC-VAL-002 | needs a person |

The hex code in `error.code` is not used to decide anything except the three service-protection codes, which only confirm a 429.

## Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | true | opportunity and products |
| `customers` | true | contacts |
| `refunds` | true | note on the opportunity |
| `stock_in`, `tracking_in`, `prices_in`, `webhooks` | false | CRM has none of the first two; prices and webhooks are not built |
| `invoice_readback` | false | the opportunity is not an invoice |
| `reconciliation` | true | opportunities and refund notes are listed server side |
| `shadow_lookup` | true | contact by email, product by `productnumber`, product search |

## Connection options

| Key | Values | Default |
| --- | --- | --- |
| `customer_policy` | `match_or_create`, `match_only` | `match_or_create` |
| `create_accounts` | `yes`, `no`: make an account for a buyer with a company name | `yes` |
| `unknown_sku` | `refuse`, `write_in` | `refuse` |
| `reference_column` | logical name of a custom text column | empty (the name prefix is used) |
| `price_list` | name of a price list (`pricelevels.name`) | empty |
| `total_mismatch` | `fail`, `warn` | `fail` |

## Not verified

- No live Dynamics 365 Sales environment was available (a trial needs two administrators and a work account). All behaviour is tested against a fake (`FakeDynamicsSalesServer`, with the shared `FakeMicrosoftEntra` and `FakeMicrosoftOData`) that follows the pages above. The wire fixtures in `tests/php/fixtures/dynamics-sales/` and `tests/php/fixtures/microsoft/` use the shapes of the pages cited in `MicrosoftWireFixturesTest` with synthetic values; the token error texts (AADSTS codes) are `[inferred]` because the Entra error list could not be read.
- Which columns Dataverse requires when an opportunity product is created from an existing product (price list, unit of measure) was not on the pages read; the research says `pricelevelid` and `transactioncurrencyid` may be required. The connector sets the currency and a unit of measure, and the price list when `price_list` is set.
- The names `transactioncurrencies` (`isocurrencycode`, `currencyprecision`), `uoms`, `pricelevels`, `organizations`, the `_defaultuomid_value` lookup property, `RetrieveVersion` and `WhoAmI`'s response fields come from the usual Dataverse conventions, not from a page read today.
- `objectid_opportunity` for notes, and `customerid_contact` for the polymorphic lookup (see above).
- That `totalamount` equals lines plus tax plus freight minus discount, and whether it is calculated at once or later.
- The wording of a missing-application-user answer and of a missing-privilege answer (third-party reports only).
- Whether notes (`annotations`) may be attached to an opportunity by a role that only has Append on Opportunity: `append` and `append to` are in the role advice in step 4 `[inferred]`.
- Win/close of the opportunity is not done: deals stay open; the `WinOpportunity` action exists `[documented: listed on the Opportunity page]` but its body was not read, and a replayed win is an error. Close them in Dynamics.
- Duplicate detection rules of the environment (suppressed by default over the Web API `[documented]`) are not switched on.
- Rounding: an order of several units at a price that does not divide evenly can differ from the shop by more than one minor unit; the total check then reports it (`total_mismatch = warn` accepts it).
- A tax number and the delivery address have no standard Dataverse column on a contact/opportunity; they are not sent as fields (the tax number goes into the description).

## Manual smoke test

No credentials are stored in the repository. Use a 30-day Dynamics 365 Sales trial.

1. Follow the six steps. In the trial's Power Platform admin center create the application user and a role as in step 4.
2. In WordPress add a Dynamics 365 Sales connection with the tenant ID, client ID, secret and the environment URL. Press Test connection. Expected: the organisation name, the user the app runs as, and the version when `RetrieveVersion` answers. A wrong secret gives SYNC-AUTH-001; a missing application user SYNC-AUTH-007; a role without a privilege SYNC-AUTH-004 on the first write.
3. Create a product whose Product ID equals a WooCommerce SKU and publish it.
4. Place a WooCommerce order. Expected: a contact (and an account when a company name was given), one open opportunity named `WC-{id} - {name}` with its products and a description whose last line starts `Estasoft Sync`. Sync it again: no second opportunity.
5. Place an order in a currency the environment does not have: expected SYNC-VAL-002 naming the currency.
6. Refund the order: expected a note on the opportunity.
7. Run the nightly check from the Reconciliation screen.
