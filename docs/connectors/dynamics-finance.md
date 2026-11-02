---
id: dynamics-finance
name: Dynamics 365 Finance and Supply Chain
catalog_row: Dynamics 365 Finance and Supply Chain
connection_method: cloud_app
available: true
verified: false
hosts: ["*.dynamics.com", "login.microsoftonline.com"]
terms_url: https://learn.microsoft.com/en-us/legal/microsoft-apis/terms-of-use
privacy_url: https://privacy.microsoft.com/privacystatement
developer_url: https://entra.microsoft.com
variants: []
---

# Dynamics 365 Finance and Supply Chain connector

Connector id `dynamics-finance`, type ERP, contract version 1. Code: `includes/Connectors/DynamicsFinance/`, shared Microsoft helpers in `includes/Connectors/Microsoft/` (also used by `business-central` and `dynamics-sales`). It talks to the OData data entities of one Finance and Operations environment, pinned to one legal entity.

The connector sends WooCommerce orders as **sales orders** (a header in `SalesOrderHeadersV2`, then its lines in `SalesOrderLines`), and customers as `CustomersV3` records. It does not confirm, pick, ship or invoice: the order is left open for the company's own process. It sends no refunds (see Refunds), reads no stock or tracking and does not run the nightly check (see Capabilities).

## Sources

Tags: `[documented]` official Microsoft page read on 2026-11-02, `[third-party]`, `[inferred]`. Base: https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/dev-itpro/data-entities/

| Page | Used for |
| --- | --- |
| `services-home-page` | OData supports OAuth 2.0 with client credentials (shared secret or certificate); register the Entra app, then in F&O System administration, Setup, Microsoft Entra applications add the client id and a service user; the resource is the environment URL "do not add a trailing slash"; delegated permissions on Microsoft Dynamics ERP |
| `odata` | Service root `[organization root URL]/data/`, `?cross-company=true`, default company only otherwise, server-driven paging with a maximum page size of 10,000, `$filter`, `$count`, `$orderby`, `$skip`, `$top`, `$select`, first-level `$expand`, wildcard `*` in a string `eq`, batch with atomic change sets, POST/PATCH/PUT/DELETE, all key fields needed |
| `entity-sales-order-headers-v2-salesorderheaderv2` | OData collection `SalesOrderHeadersV2`; create headers first, then lines; performance pattern "Single thread recommended"; mandatory `ORDERINGCUSTOMERACCOUNTNUMBER`, `INVOICECUSTOMERACCOUNTNUMBER`, `REQUESTEDSHIPPINGDATE`; the sales order number field is the primary key and the page says "This field is required" (it spells the column name `SALESDORDERNUMBER`; the OData property is `SalesOrderNumber`), which is why the connection has the option `order_numbering` (see Duplicate protection); the page gives no data types, so the date fields are not typed there; an update needs an open order status |
| `entity-sales-order-lines-v2-salesorderline` | OData collection `SalesOrderLines`; mandatory `ITEMNUMBER`, `SALESORDERNUMBER`, `REQUESTEDSHIPPINGDATE`, `DELIVERYADDRESSLOCATIONID` (see Not verified); `ORDEREDSALESQUANTITY`, `SALESPRICE`, `SALESUNITSYMBOL`, `LINEAMOUNT` |
| `entity-customers-v3-customerv3` | OData collection `CustomersV3`; `CUSTOMERGROUPID` and `SALECURRENCYCODE` required, `ORGANIZATIONNAME` for an organisation, person name fields for a person; "Single thread only"; not for high volume |
| Common Data Model pages `SalesOrderHeaderV2Entity`, `SalesOrderLineV2Entity`, `CustCustomerV3Entity` (https://learn.microsoft.com/en-us/common-data-model/schema/core/operationscommon/entities/) | The complete attribute lists (about 178 header, 160 line, 290 customer attributes): `CustomersOrderReference`, `SalesOrderName`, `CurrencyCode`, `ArePricesIncludingSalesTax`, `DeliveryAddress*`, `RequestedShippingDate`, `OrderTotalAmount`, `LineCreationSequenceNumber`, `PrimaryContactEmail`, `PartyType` and more. Types are all shown as strings there, so they are not used. |
| `service-protection-api-limits`, `service-protection-retry-operations`, `priority-based-throttling` | User limits 6,000 requests, 20 minutes, 52 concurrent per user per application id per web server in 5 minutes; resource-based limits with 429 and `Retry-After` in seconds (30 when absent in Microsoft's sample); priority mapping per Entra app |

Not fetched (refused or search budget used up): the ReleasedProductsV2 entity page, the sales tax group entity pages, the Microsoft Entra error list, the authentication page with the token request. Facts from them are `[inferred]` and listed under Not verified.

## How the merchant connects

1. In the Microsoft Entra admin center, register an app and copy its Application (client) ID and Directory (tenant) ID.
2. Create a client secret for the app and copy its value at once. Note its end date.
3. In Finance and Operations open System administration, Setup, Microsoft Entra applications. Add the client ID and a service user.
4. Give that user a role that creates sales orders and reads customers and released products in your legal entity.
5. Paste the tenant ID, client ID, secret, environment URL and legal entity ID (for example `usmf`) here.
6. Press Test connection. To let the plugin create customers, choose a customer group under Advanced.

Who is needed: a Microsoft Entra administrator (steps 1 and 2) and a Finance and Operations system administrator (steps 3 and 4) `[documented]`. The environment URL is the address you open the application at, for example `https://contoso.operations.dynamics.com` or a sandbox `https://contoso-uat.sandbox.operations.dynamics.com`. No redirect address is needed (client credentials). Optional: a throttling priority for the app under System administration, Setup, Throttling priority mapping, so the sync is not throttled first `[documented]`.

## Authentication

- Grant `client_credentials` against `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` with scope `{environment URL}/.default`. The F&O page documents the environment URL as the token's resource (no trailing slash) and client credentials as supported; the `.default` scope form is the Microsoft Entra v2 way to ask for the same thing `[inferred]`. Built by `Microsoft\EntraTokenProvider`.
- Stored: `tenant_id`, `client_id`, `environment_url`, `legal_entity` in the config; `client_secret` encrypted; the token and its end time under the reserved secrets. No refresh token.
- The environment URL is checked: https, a `*.dynamics.com` host, no credentials, the path dropped, trailing slash removed. Sovereign clouds (other login hosts) are not supported.
- The service user decides what the app may do; the connector never asks for more than the data it writes.

## Data sent and when (WordPress.org "External services")

| Host | When | What is sent |
| --- | --- | --- |
| `login.microsoftonline.com` | Before the first call and about once an hour | Tenant id, client id, client secret, the scope |
| your environment host (`*.operations.dynamics.com`, `*.sandbox.operations.dynamics.com`, `*.dynamics.com`) | Test connection | The token; reads the legal entity |
| the same host | An order is synced | Customer account, delivery address (name, street, city, postcode, country), currency, the reference `WC-{order id}`, requested shipping date, lines (SKU, quantity, price, sales tax item group), shipping, fee and discount lines |
| the same host | A customer is synced, or an order needs a new customer | Name, email, phone, address, customer group, currency |
| the same host | SKU matching, shadow lookups | Reads (customers by email, released products) |

Nothing else leaves the site. The merchant registers their own Entra app.

## Objects and field mapping

Base: `{environment URL}/data/`. Reads use `?cross-company=true` together with `$filter=dataAreaId eq '{legal entity}'`, so the answer never depends on the service user's default company. Writes carry `dataAreaId` in the body.

| Canonical field | Target path | Finance and Operations |
| --- | --- | --- |
| `order.client_ref` (queue item) | none | `SalesOrderHeadersV2.CustomersOrderReference` = `WC-{order id}` |
| `order.created_at` | `order_date` | `RequestedShippingDate` and `RequestedReceiptDate` (the sales order date itself is set by the system). The date is the shop's date (the default rule uses `output_timezone: store`) and is written as `YYYY-MM-DDT12:00:00Z`, the form of an Edm.DateTimeOffset at midday UTC, so that no time zone on the other side moves it to another day (see Not verified) |
| `order.currency` | `currency` | `CurrencyCode` |
| `order.note` | none | not sent: no free-text note column on the header was found in the attribute list |
| `order.total`, `excluded_total` | `total`, `excluded_total` | not sent; compared with `OrderTotalAmount` |
| `billing.email` / `customer.email` | `partner.email` | `CustomersV3.PrimaryContactEmail` (search) |
| `billing.company` / names | `partner.name`, `partner.company_name` | `OrganizationName` (PartyType Organization) or `PersonFirstName`/`PersonLastName` (Person) |
| `billing.phone` | `partner.phone` | `PrimaryContactPhone` |
| `billing.address_1/2, city, state, postcode, country` | `partner.street`, `.city`, `.state_id`, `.zip`, `.country_id` | `AddressStreet`, `AddressCity`, `AddressState`, `AddressZipCode`, `AddressCountryRegionId` (three-letter, the mapping uses `iso_country` with `alpha3`) |
| `shipping.*` | `delivery.name`, `.street`, `.city`, `.state_id`, `.zip`, `.country_id` | `DeliveryAddressName`, `DeliveryAddressStreet`, `DeliveryAddressCity`, `DeliveryAddressStateId`, `DeliveryAddressZipCode`, `DeliveryAddressCountryRegionId` |
| `lines[].sku` | `order_line[].product` | `SalesOrderLines.ItemNumber` (item found in `ReleasedProductsV2`) |
| `lines[].name` | `order_line[].name` | `LineDescription` |
| `lines[].quantity`, `unit_price` | `order_line[].quantity`, `.price_unit` | `OrderedSalesQuantity`, `SalesPrice` |
| `lines[].tax_rate` via tax map | `order_line[].tax` | `SalesTaxItemGroupCode` |
| `prices_include_tax` | `prices_include_tax` | header `ArePricesIncludingSalesTax` |
| shipping, fees | `shipping.*`, `fee[].*` | lines on `shipping_item` / `fee_item` |
| discount | `discount.total` | header `TotalDiscountAmount` |
| warehouse, site | `warehouse`, `site` | `DefaultShippingWarehouseId`, `DefaultShippingSiteId` on the header (options give defaults) |

Money: minor units inside, `Core\Support\Money` at the edge, string arithmetic only. F&O amounts are sent as JSON numbers with the currency's exponent.

## Duplicate protection

- Reference: `CustomersOrderReference = WC-{order id}`. Search before every create: `GET SalesOrderHeadersV2?cross-company=true&$filter=dataAreaId eq 'usmf' and CustomersOrderReference eq 'WC-123'`, server side, following `@odata.nextLink`; the rows are compared again in PHP for the exact reference (a string `eq` treats `*` as a wildcard `[documented]`, so a value containing `*` is never put in a filter; references are `WC-` and digits).
- Completion marker: the header's `SalesOrderName` is `WC-123 (syncing)` when the header is created and becomes `WC-123 (3 lines)` only after the lines were read back and the total was checked (or a difference was accepted with `total_mismatch = warn`). `findExisting()` reports the order only when the name carries the count and the order has at least that many lines (`SalesOrderLines?$filter=dataAreaId eq 'usmf' and SalesOrderNumber eq '…'`, paged), so an order that failed its check, or whose total could not be read, is never linked by a retry. `send()` finds an unfinished header, compares the lines it has (ordered by `LineCreationSequenceNumber`) with the first lines of the payload, creates the missing ones, reads the lines back and compares them with what was sent (F&O may price a line from its own agreements: a line stored with another item, quantity or price stops the order with SYNC-VAL-002), checks the total, and only then rewrites the name. A header whose lines differ from the payload is SYNC-VAL-002 ("the order was changed in Finance and Operations").
- Order numbers: the option `order_numbering` is `automatic` (default: the connector sends no `SalesOrderNumber` and the number sequence of the legal entity assigns it, which needs an automatic sequence) or `shop_reference` (the connector sends `WC-123` as the number, at most 20 characters, for a legal entity whose sales order sequence is manual). In `automatic` mode a 400 answer that names the sales order number or the number sequence is turned into SYNC-VAL-002 naming `order_numbering`, and nothing is created.
- Header and lines are separate calls and not one transaction `[documented: related entity, create headers first]`; Microsoft recommends a single thread for both entities `[documented]`. OData batch with a single change set is atomic `[documented]` but the connector does not use it: it writes in order and resumes, so every step can be repeated after a crash.
- Customers: found by `PrimaryContactEmail` before any create.

## Taxes

- Sales tax in F&O comes from the order's sales tax group (from the customer or delivery address) and each line's item sales tax group. The mapping chooses the **item sales tax group** per line (`SalesTaxItemGroupCode`); a line without a mapped code gets the item's own default.
- `listTaxCodes()` returns nothing: the entity that lists sales tax groups could not be read in this session, and F&O does not hold rates in a single field. Type the group code in the tax mapping.
- Prices including tax: the header's `ArePricesIncludingSalesTax` is set from the payload (`prices_include_tax`) so the document treats the prices the way the shop does `[documented: attribute exists; that it is writable at create is inferred]`. Then, when `OrderTotalAmount` is read back and non-zero, it is compared with `total - excluded_total` (one minor unit of tolerance); a difference stops with SYNC-VAL-002 naming both totals (option `total_mismatch = warn` goes on). A total of 0 or an absent total cannot be compared, and an order that was not compared is not reported as synced: by default (`total_mismatch = fail`) the order is refused with SYNC-VAL-002 ("gave no total … set the option total_mismatch to warn"), keeps the name `WC-123 (syncing)` and is not linked; the next try looks again. With `total_mismatch = warn` the order is finished and the result carries a warning. Whether F&O fills `OrderTotalAmount` for an order created over OData is not documented (`AreTotalsCalculated` is an attribute of the header), so on an environment that never fills it the option has to be `warn`; the lines read back are still compared with what was sent.

## Payments

Not recorded. Customer payment journals are not part of this connector.

## Refunds

Not sent. The pages read name no entity for sales credit notes or returns, and a return order needs a return reason code, a disposition and a warehouse decision that belong to the company's process. `refunds` is not flagged; a refund item fails with SYNC-VAL-002 "this connector does not send this kind of item" and is shown to a person.

## Stock, tracking, invoice read-back

Not available. Per-warehouse stock is not on the public OData entities read; Microsoft's documented route is the Inventory Visibility add-in, which is a separate service with its own authentication and is not part of this connector. Delivery tracking and invoices are not exposed by an entity read for this purpose. `stock_in`, `tracking_in`, `invoice_readback` are false.

## Rate limits and pacing

`[documented]` User limits per user, per application id, per web server, within a 5-minute sliding window: 6,000 requests, 20 minutes of execution time, 52 concurrent. Resource-based limits answer 429 "Too Many Requests" with `Retry-After` in whole seconds; the retry interval grows if the client keeps pushing; a retried request has no priority over new ones; Microsoft's sample waits 30 seconds when the header is absent. Messages: "Number of requests exceeded the limit of 6000 over the time window of 300 seconds", "Combined execution time … exceeded", "Number of concurrent requests exceeded the limit of 52", "This request could not be processed at this time due to system experiencing high resource utilization".

The connector paces to 2 requests a second and 600 a minute, one request at a time (both sales order entities are "single thread recommended"), and returns SYNC-RATE-001 with the header's wait on a 429. Each request has a 30 second timeout.

## Error mapping

| F&O answer | Code | Class |
| --- | --- | --- |
| Token endpoint wrong secret or client | SYNC-AUTH-001 | needs a person |
| Token endpoint secret expired (AADSTS7000222 `[inferred]`) | SYNC-AUTH-005 | needs a person |
| API 401 after one refresh (the app is not in System administration, Microsoft Entra applications, or has no service user) `[inferred]` | SYNC-AUTH-007 | needs a person |
| 403 | SYNC-AUTH-004 | needs a person |
| 429 | SYNC-RATE-001 with `Retry-After` | retry after the wait |
| 408, 504, timeout, reset | SYNC-NET-001 | retry |
| 500, 502, 503 | SYNC-NET-002 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| 400 with `{"error":{"code","message"}}` (validation: unknown customer, currency not set up, missing mandatory field) | SYNC-VAL-002 with the short message, redacted | needs a person |
| 404 on the key (the record is gone) when reading the order again | SYNC-VAL-002 | needs a person |
| SKU not a released product | SYNC-MAP-001 | needs a person |
| Tax code not mapped | SYNC-MAP-002 | needs a person |
| Customer email missing | SYNC-MAP-003 | needs a person |
| Currency came back different, totals differ, order changed by hand | SYNC-VAL-002 | needs a person |

The error body shape of F&O OData is the usual OData one (`{"error":{"code","message"}}`) `[inferred]`: the pages read do not show it.

## Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | true | `SalesOrderHeadersV2` and `SalesOrderLines` |
| `customers` | true | `CustomersV3` |
| `refunds` | false | no documented credit note or return entity read |
| `stock_in` | false | per-warehouse stock is not on public OData (Inventory Visibility add-in) |
| `tracking_in` | false | not found |
| `prices_in` | false | not built |
| `invoice_readback` | false | not found |
| `reconciliation` | false | `OrderTotalAmount` may be empty for orders created over OData; a nightly check that reports a false difference for every order would be worse than none (and a total that is empty stops the order unless `total_mismatch = warn`) |
| `webhooks` | false | F&O business events need a broker (research) |
| `shadow_lookup` | true | customer by email, released product by `ItemNumber` |

## Connection options

| Key | Values | Default |
| --- | --- | --- |
| `customer_policy` | `match_or_create`, `match_only`, `fixed` | `match_or_create` |
| `fixed_customer` | customer account used for every order when policy is `fixed` | empty |
| `customer_group` | `CustomerGroupId` for customers the connector creates (required for creating) | empty |
| `shipping_item`, `fee_item` | item number of a service or non-stock item | empty |
| `shipping_warehouse`, `shipping_site` | warehouse and site ids | empty (the customer's or item's default) |
| `shipping_date` | `order` (the shop's order date), `today` (today in the shop's time zone) | `order` |
| `order_numbering` | `automatic` (the number sequence assigns the number), `shop_reference` (`WC-123` is the number) | `automatic` |
| `total_mismatch` | `fail`, `warn` | `fail`; also decides what happens when F&O gives no total |

A SKU that is not a released product is always refused (SYNC-MAP-001): a sales order line in F&O needs an item, so there is no write-in option. A shipping or fee amount without its item option is refused with SYNC-VAL-002 naming the option. The connector never creates items.

## Not verified

- No live Finance and Operations environment was available. All behaviour is tested against a fake (`FakeDynamicsFinanceServer`, with the shared `FakeMicrosoftEntra` and `FakeMicrosoftOData`) that follows the pages above. The wire fixtures in `tests/php/fixtures/dynamics-finance/` and `tests/php/fixtures/microsoft/` use the shapes of the pages cited in `MicrosoftWireFixturesTest` with synthetic values; the token error texts (AADSTS codes) are `[inferred]` because the Entra error list could not be read. Its filters are case-insensitive and treat `*` in a string `eq` as a wildcard, which is how the pages read for F&O; the connector also compares emails and item numbers again in PHP, so a case-sensitive service would only cost a missed match, never a wrong one.
- The documentation lists `DELIVERYADDRESSLOCATIONID` as mandatory on a line; the connector does not set it (it would need the customer's address book), so on environments where it is not defaulted the first line is refused with F&O's own message (SYNC-VAL-002).
- Whether the order number comes from the number sequence when `SalesOrderNumber` is not sent (the entity page marks the field required; the default `order_numbering = automatic` relies on the usual behaviour of number sequences, and the wording of F&O's refusal for a manual sequence is a guess: the connector looks for "SalesOrderNumber", "sales order number" or "number sequence" in a 400 answer), whether `dataAreaId` is accepted in the body, and the composite key forms `SalesOrderHeadersV2(dataAreaId='…',SalesOrderNumber='…')`: the `odata` page says all key fields are needed but gives no example for these entities.
- The field names `ReleasedProductsV2.ItemNumber`, `ReleasedProductsV2.SearchName`/`ProductName`, `LegalEntities.LegalEntityId`/`Name` (used by Test connection and the SKU lookup) come from the usual public metadata, not from a page read today. If an environment does not have them the lookup fails with F&O's message.
- Whether the delivery address fields on the header take effect without `IsDeliveryAddressOrderSpecific`; whether `TotalDiscountAmount` is writable and what it means for tax (the fake takes it off the total after tax; if F&O treats it differently the total check stops the order with both totals shown, and `total_mismatch = warn` lets it through); whether `ArePricesIncludingSalesTax` is writable at create.
- Whether a string `eq` on `PrimaryContactEmail`, `ItemNumber` or `CustomerAccount` is case-insensitive on this service (SQL Server collation usually makes it so, the pages do not say).
- The three-letter country ids (`USA`, `SAU`, `ARE`) and state ids are the F&O convention `[third-party]`.
- Whether `OrderTotalAmount` is filled for orders created over OData.
- The type of the date fields. The entity pages give none; F&O normally exposes them as `Edm.DateTimeOffset` (third-party examples write `2020-07-14T12:00:00Z`), so that form is sent. If an environment's `$metadata` says `Edm.Date`, a plain `YYYY-MM-DD` would be needed and the order is refused by F&O with its own message (SYNC-VAL-002).
- `SalesTaxItemGroupCode` on a line is an attribute of `SalesOrderLineV2Entity`; its behaviour with a header sales tax group was not read.
- The error body of the OData service, and the exact wording of a 401 for an app that is not registered in F&O.
- No tax e-invoicing step exists in this connector. For Saudi Arabia the research found phase 2 e-invoicing through the Electronic invoicing service; this connector does not touch it and makes no compliance claim. Sales orders are created open; invoicing and e-invoicing are the company's own process.
- `$batch` is not used; its limits on this service are not documented on the page read.

## Manual smoke test

No credentials are stored in the repository. Use a sandbox environment with the demo legal entity (`USMF`).

1. Follow the six steps. Register the app in System administration, Setup, Microsoft Entra applications with a test service user that has the sales order clerk and customer roles.
2. In WordPress add a Dynamics 365 Finance and Supply Chain connection with the tenant ID, client ID, secret, environment URL and the legal entity. Press Test connection. Expected: the legal entity name. A wrong secret gives SYNC-AUTH-001; an app that is not registered in F&O gives SYNC-AUTH-007 (unconfirmed).
3. Use a released product whose item number equals a WooCommerce SKU, and a customer whose primary email equals the test buyer's email.
4. Place a WooCommerce order. Expected: one open sales order whose Customer reference is `WC-{order id}` with its lines and the order name `WC-… (N lines)`. Sync again: no second order. If F&O refuses the order for want of a sales order number, set `order_numbering` to `shop_reference`. If the order stays named `WC-… (syncing)` with SYNC-VAL-002 "gave no total", this environment does not fill `OrderTotalAmount`: open the order, check its total by hand and set `total_mismatch` to `warn`.
5. Place an order for a new buyer with no customer group set: expected SYNC-VAL-002 naming `customer_group`. Set a group and retry: a customer is created.
6. Place an order in a currency the legal entity has not set up: expected SYNC-VAL-002 from F&O.
