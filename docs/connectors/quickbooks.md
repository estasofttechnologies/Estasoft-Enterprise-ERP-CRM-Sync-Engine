---
id: quickbooks
name: QuickBooks Online
catalog_row: QuickBooks Online
connection_method: own_app
available: true
verified: false
hosts: [quickbooks.api.intuit.com, sandbox-quickbooks.api.intuit.com, appcenter.intuit.com, oauth.platform.intuit.com]
terms_url: https://www.intuit.com/legal/terms/en-us/intuit-developer/
privacy_url: https://www.intuit.com/privacy/statement/
developer_url: https://developer.intuit.com/app/developer/dashboard
variants: []
---

# QuickBooks Online connector

Connector id `quickbooks`, type accounting, contract version 1. Code: `includes/Connectors/QuickBooks/`. Registration: `QuickBooksProvider`.

The connector sends WooCommerce orders to QuickBooks Online as invoices, optionally records the payment, sends refunds as credit memos, finds or creates the customer, and reads item stock, invoice facts and a reconciliation list back. It works for QuickBooks Online companies in the US, UK, Canada and Australia (the countries Intuit meters; the research found no tax-authority integration for the Gulf, so the connector is not offered there). Production and sandbox companies are both supported; the merchant uses their own Intuit app.

Tags: `[documented]` official Intuit source, `[third-party]` partner or community page, `[inferred]` conclusion without a page that states it. Fetched or read on 2026-11-02. Nobody has run this against a live company.

## 1. Sources

Intuit's developer site (`developer.intuit.com`) returns only a loading shell to the fetch tool, so the narrative pages (OAuth 2.0, entity pages, error codes, limits) could not be read. The facts come from Intuit's own open-source SDK, which is generated from the same schema, plus Intuit blog posts.

| Source | Used for | Read |
| --- | --- | --- |
| Intuit PHP SDK `intuit/QuickBooks-V3-PHP-SDK` on GitHub (`src/Core/CoreConstants.php`, `src/Core/OAuth/OAuth2/OAuth2LoginHelper.php`, `src/Core/HttpClients/FaultHandler.php`, `src/Data/IPPInvoice.php`, `IPPSalesTransaction.php`, `IPPTransaction.php`, `IPPLine.php`, `IPPSalesItemLineDetail.php`, `IPPCustomer.php`, `IPPNameBase.php`, `IPPItem.php`, `IPPTaxCode.php`, `IPPTaxRate.php`, `IPPPhysicalAddress.php`, `IPPPayment.php`, `IPPCreditMemo.php`, `IPPPreferences` and `IPPSalesFormsPrefs`, `IPPTaxPrefs`, `IPPCurrencyPrefs`, `IPPCompanyInfo.php`) | production and sandbox hosts, authorize and token URLs, Basic client authentication, default minor version 75, fault shape, field names, field lengths, which fields are filterable, `CustomTxnNumbers`, `MultiCurrencyEnabled`, `HomeCurrency` | 2026-11-02 |
| https://blogs.a.intuit.com/2016/08/08/idg-concurrent-api-use-changes-coming-soon | 500 requests a minute per realm including batch, at most 10 concurrent requests, 429 on excess, `requestid` replay | 2026-11-02 |
| https://help.gozynta.com/support/solutions/articles/44002519554-message-throttleexceeded-errorcode-003001-statuscode-429 [third-party] | the throttle error text `ThrottleExceeded`, code `003001` | 2026-11-02 |
| https://www.apideck.com/blog/quickbooks-invoice-api-integration.md [third-party] | endpoint shapes, query language limits (no OR, `LIKE` with `%`, `STARTPOSITION`, `MAXRESULTS` default 100 and maximum 1000), error codes 6000, 6140, 6240, 610, 5010, 2500, `SyncToken`, delete operation | 2026-11-02 |
| Research in the repository: `docs/research/integration-research-vendors-2.md` section 7.1 | minor versions below 75 treated as 75, token lifetimes (access 60 minutes, refresh 100 days rolling, about daily rotation), per-app production assessment, no SalesOrder, Gulf not supported | repository |

## 2. How the merchant connects

1. At `developer.intuit.com` create an app for QuickBooks Online and select the Accounting scope.
2. In Keys and credentials add the Redirect address shown here to the redirect URIs of the key set you will use.
3. Paste that key set's Client ID and Client secret here and choose Production or Sandbox to match it.
4. Press Sign in with QuickBooks and choose the company to sync.
5. In QuickBooks open Account and settings, Sales, and turn on Custom transaction numbers.
6. Press Test connection, then map each tax in Tax mapping.

Gates: development keys work with sandbox companies only; production keys need Intuit's app assessment (legal, technical and security questionnaire), even for an app used by its author only [research, documented]. The Builder tier is free up to 500,000 metered calls a month (reads, queries, batches; writes are not metered) [research, documented]. Production redirect URIs must be exact https addresses, at most 25 per app [research, third-party]. The signed-in user must be able to use the company's sales forms; a restricted user shows as SYNC-AUTH-004.

## 3. Authentication

OAuth 2 authorization code with the merchant's own app.

| Item | Value |
| --- | --- |
| Authorize | `https://appcenter.intuit.com/connect/oauth2` with `client_id`, `scope`, `redirect_uri`, `response_type=code`, `state` [documented, SDK] |
| Token and refresh | `https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer`, form body, client id and secret in a Basic header, `Accept: application/json` [documented, SDK] |
| Scope | `com.intuit.quickbooks.accounting` [documented, research] |
| PKCE | not used |
| Access token | 60 minutes [research, documented] |
| Refresh token | 100 days rolling with a 5-year hard cap; the value changes about daily, the newest is kept [research]. The header `x-include-refresh-token-hard-expires-in` exists for the hard cap [SDK]; it is not sent |
| Company | the sign-in answer carries `realmId`; it is stored as `realm_id` and used in every path: `/v3/company/{realmId}/...` [documented, SDK/research] |
| API hosts | production `https://quickbooks.api.intuit.com`, sandbox `https://sandbox-quickbooks.api.intuit.com` [documented, SDK constants]; chosen by the credential field `environment` |
| Minor version | query parameter `minorversion=75` on every call (option `minor_version`, 75 or higher); Intuit treats lower values as 75 [research, documented] |

Stored: client id (config), client secret and tokens (encrypted), `environment`, `realm_id`, `company_name` (config). The realm id and company name are removed from every message and log line.

## 4. Data sent and when (for the WordPress.org "External services" disclosure)

| Host | When | Data |
| --- | --- | --- |
| `appcenter.intuit.com` | the person presses Sign in (their browser is sent there) | client id, redirect address, scope, state |
| `oauth.platform.intuit.com` | after sign-in and before an access token ends | client id and secret (Basic header), the one-time code or refresh token |
| `quickbooks.api.intuit.com` or `sandbox-quickbooks.api.intuit.com` | test connection, each queued order, refund and customer, scheduled reads | company facts and preferences (read); customer name, email, phone, addresses; invoice lines (SKU, description, quantity, unit price, tax code), totals, dates, the shop's reference `WC-{id}`; payments and credit memos; reads of items, tax codes, invoices |

Nothing is sent to Estasoft or anyone else.

## 5. Connection settings

Credential fields: `client_id`, `client_secret`, `environment` (production or sandbox), the redirect address (display only).

Options (`QuickBooksOptions`):

| Key | Values | Default |
| --- | --- | --- |
| `documents` | `invoice` (invoice only), `invoice_paid` (invoice and payment) | `invoice` |
| `generic_item` | name or SKU of a service item used for shipping, fees, discounts and free-text lines | empty (those lines are refused, naming this option) |
| `shipping_item`, `fee_item`, `discount_item` | name or SKU per kind of line | `generic_item` |
| `deposit_account` | name or id of the account that receives payments | empty (QuickBooks uses Undeposited Funds) |
| `unknown_sku` | `refuse` (SYNC-MAP-001) or `free_text` (uses `generic_item`) | `refuse` |
| `due_days` | 0 to 365 | 0 |
| `minor_version` | 75 to 99 | 75 |
| `total_mismatch` | `fail` (delete the invoice and stop) or `warn` | `fail` |
| `no_tax_code` | tax code id QuickBooks uses for a line the shop did not tax (an empty `tax` key) | `NON` |

## 6. Objects and field mapping

Payload paths are the names the Odoo connector uses. QuickBooks has no sales-order entity [research, third-party]; an order becomes an `Invoice`.

| Payload path | QuickBooks field |
| --- | --- |
| `client_order_ref` (always the item's `WC-{id}`) | `DocNumber` (max 21 characters) [documented, SDK]; `PrivateNote` also starts with it |
| `date_order` | `TxnDate` (YYYY-MM-DD); `DueDate` = date + `due_days` |
| `currency` | `CurrencyRef.value` (only when it differs from the home currency) |
| `prices_include_tax` | `GlobalTaxCalculation` `TaxInclusive` or `TaxExcluded` (outside the US) |
| `total`, `excluded_total` | not sent; the expected total for the check |
| `partner.name`, `company_name` | Customer `DisplayName` (company if given), `GivenName` (25), `FamilyName` (15), `CompanyName` |
| `partner.email`, `phone` | `PrimaryEmailAddr.Address`, `PrimaryPhone.FreeFormNumber`; also `BillEmail` on the invoice |
| `partner.street`, `city`, `state_id`, `zip`, `country_id` | `BillAddr`: `Line1` (500), `City` (255), `CountrySubDivisionCode`, `PostalCode`, `Country` |
| `delivery.*` | `ShipAddr` |
| `order_line[].product` | `SalesItemLineDetail.ItemRef.value` (item found by `Sku`, max 100) |
| `order_line[].name`, `quantity`, `price_unit` | `Description` (4,000), `Qty`, `UnitPrice`; `Amount` = quantity x unit price |
| `order_line[].tax` | `SalesItemLineDetail.TaxCodeRef.value` |
| `shipping.*`, `fee[].*`, `discount.*` | lines on the shipping, fee or discount item (discount negative) |
| `payment.reference`, `payment.date` | Payment `PaymentRefNum` (max 21), `TxnDate` |

Refund paths are the same as for the other accounting connectors: `order_ref`, `date`, `reason`, `amount`, `prices_include_tax`, `refund_line[]`, `shipping.*`.

## 7. Flow of an order

QuickBooks has no draft state: an invoice is posted when it is created. That is why the checks come first and the only undo is a delete.

1. Check the payload before any request.
2. Find the invoice: `select * from Invoice where DocNumber = 'WC-{id}'` with `STARTPOSITION` and `MAXRESULTS 100`, all pages. A hit whose total is 0 while the order is not 0 is a voided invoice and stops the item with SYNC-VAL-002 (the merchant voided it on purpose).
3. If none exists: read `GET .../companyinfo/{realm}` (country, name) and `GET .../preferences` (`SalesFormsPrefs.CustomTxnNumbers`, `CurrencyPrefs.MultiCurrencyEnabled`, `HomeCurrency`) once per request. A currency other than the home currency needs multi-currency to be on (else SYNC-VAL-002). In the US, `prices_include_tax` is refused (QuickBooks US adds sales tax on top).
4. Resolve items: `select * from Item where Sku in ('A','B')` (20 SKUs a time); a missing one follows `unknown_sku`; an item of type Category is refused. Resolve the customer (below).
5. Create the invoice with `POST /invoice?minorversion=75&requestid=...` (see section 8). Its `PrivateNote` is `WC-{id} [unchecked]`: the invoice exists in QuickBooks from this moment, and the marker says the connector has not yet read it back.
6. Read the answer: currency and `TotalAmt`. A different currency, or a total that differs from `total - excluded_total` by more than one minor unit, is SYNC-VAL-002 naming both values; with `total_mismatch = fail` the connector first deletes the invoice (`POST /invoice?operation=delete` with `Id` and `SyncToken`). When all agrees, a sparse update (`sparse: true`, `Id`, `SyncToken`, `PrivateNote` without the marker) marks the invoice as checked. If QuickBooks answers 5010 (stale `SyncToken`, for example when a replayed answer is older than the invoice) the invoice is read again and the update is tried once more.
7. For `invoice_paid` with a payment date: `POST /payment` with `CustomerRef`, `TotalAmt` = the invoice `Balance` read just before, `PaymentRefNum` `WC-{id}`, `DepositToAccountRef` when set, and a `Line` linked to the invoice (`LinkedTxn` `TxnType Invoice`). If `Balance` is already 0 nothing is created.
8. Result: external id = invoice `Id`, reference = `DocNumber`, `extra` = total in minor units, currency, balance.

Replays: a create or payment that may have reached QuickBooks (time-out, 5xx) ends the pass; the next pass starts at step 2. An invoice that is found with the marker still in its note is never reported as finished (`findExisting()` returns nothing for it): the next pass reads it back, checks it and removes the marker, so a pass that stopped between create and check cannot leave an unchecked invoice behind as done. `DocNumber` is checked by QuickBooks for duplicates (error 6140 [third-party]); when 6140 comes back the invoice is searched again and, if found, treated as existing.

Customers: `select * from Customer where PrimaryEmailAddr = 'x'` [inferred filter], else `POST /customer`. QuickBooks needs a `DisplayName` that is unique among customers, vendors and employees and without a colon; error 6240 makes the connector retry once with the email in brackets after the name.

## 8. Duplicate protection

- Reference field: `DocNumber` = `WC-{order id}` on invoices, `WC-{refund id}` on credit memos. This only works when the company has Custom transaction numbers on (`SalesFormsPrefs.CustomTxnNumbers` [documented, SDK]); Test connection fails with a clear message when it is off, because the invoice number would then be chosen by QuickBooks and the search could not find it again.
- `requestid` query parameter: Intuit says resending a request with the same `requestid` replays the earlier one [documented, Intuit blog]. The value is `wc-{first 40 hex of sha1 of connection, reference, step, try number and body}` (50 characters at most [third-party]). The try number (the queue item's `attempt`) is part of the value on purpose: a retry that follows a deleted invoice must not be handed the answer about the invoice that was deleted. Across tries the duplicate protection is the document number, not the request id.
- Search by `DocNumber` before every create; `findExisting()` returns an invoice only when the steps the settings ask for are done.

## 9. Taxes

Outside the US each line carries `TaxCodeRef.value` = the mapped tax code id (for example `5`, or `TAX` / `NON` in Australia). `listTaxCodes()` reads `TaxCode` (active) and `TaxRate`, and shows the sum of the sales rate components as the percentage. In the US the lines carry `TAX` or `NON`: map the WooCommerce rate to one of those; QuickBooks works out the sales tax from the address (automated sales tax) [documented, SDK: `UseAutomatedSalesTax`]. A line without a tax key is sent without `TaxCodeRef` and QuickBooks applies the item's default.

Tax-inclusive prices: `GlobalTaxCalculation` follows `prices_include_tax` outside the US. No conversion by us; the total check catches any difference over one minor unit.

## 10. Payments

Off unless `documents = invoice_paid`. Needs no extra scope. The amount is the invoice balance, never the order total.

## 11. Refunds

A refund finds the order's invoice (`WC-{order id}`; missing: SYNC-VAL-002 first), takes its customer, currency and tax model, and creates a `CreditMemo` with `DocNumber` `WC-{refund id}` and `PrivateNote` `Refund of WC-{order id}` plus the reason and the same `[unchecked]` marker as an invoice, checks the total against `amount` when given (a wrong credit memo is deleted), then removes the marker. Existing credit memos are found by `DocNumber`. The credit memo is not applied to the invoice: QuickBooks outside India has no field on the credit memo that links an invoice (`InvoiceRef` is documented for the IN region only [SDK]), and applying one needs a payment object that was not verified; the person applies it in QuickBooks. This is listed under known gaps.

## 12. Reading from QuickBooks

- `fetchStock`: `Item` by `Sku` with `TrackQtyOnHand`; the quantity is `QtyOnHand` (floored, not below 0), held 0. No warehouses (QuickBooks has one location per item unless location tracking is used).
- `fetchInvoice`: number, state (`unpaid`, `partial`, `paid` from `Balance`), date; no tax-authority status; `pdf_url` is empty (the PDF needs the access token).
- `listForReconcile`: `select * from Invoice where TxnDate >= '{date}' and DocNumber like 'WC-%'` paged; total; refunded amount from credit memos whose `PrivateNote` we wrote (`Refund of WC-{order id}`).
- Tracking, prices and webhooks: not built (QuickBooks webhooks must be CloudEvents and need a public endpoint).

## 13. Rate limits and pacing

500 requests a minute per realm including batch, 10 concurrent requests [documented, Intuit blog]; 429 `ThrottleExceeded` code 003001 [third-party]. The connector runs one call at a time at most 4 a second and 450 a minute, and waits as told on a 429 (30 seconds when there is no `Retry-After`). There is no documented daily cap, so none is applied. One order costs about 7 calls (invoice search, company info, preferences, items, customer search, create).

## 14. Error mapping

QuickBooks error body: `{"Fault":{"Error":[{"Message","Detail","code","element"}],"type":"ValidationFault"},"time":"..."}` [documented, SDK].

| What QuickBooks answers | SYNC code | Notes |
| --- | --- | --- |
| 400, fault code 6000, 2010, 2020, 2050, 2500, 610 and other validation faults | SYNC-VAL-002 | message and element, redacted |
| 400/200 fault 6140 (duplicate document number) | handled in the flow | invoice searched again |
| fault 6240 (duplicate name) | handled in the flow | customer retried with a suffix |
| 401, fault 3200 (token) | SYNC-AUTH-002 after one refresh | base behaviour |
| 403, fault 120 (no permission) | SYNC-AUTH-004 | |
| fault 3100 (`ApplicationAuthorizationFailed`) | SYNC-AUTH-007 | `[inferred]`: the app (a development key against production) is not authorised for the company |
| fault 5010 (stale object) | SYNC-NET-002 (retry) | when removing the marker the document is read again once before this is reported |
| 429 | SYNC-RATE-001 | |
| 5xx, time-out | SYNC-NET-002 / SYNC-NET-001 | retry |
| certificate failure | SYNC-NET-003 | |
| unknown SKU, `refuse` | SYNC-MAP-001 | |
| Custom transaction numbers off, multi-currency off for a foreign order, US tax-inclusive, totals differ, no generic item | SYNC-VAL-002 | |

## 15. Capabilities

| Flag | Value | Why |
| --- | --- | --- |
| orders, customers, refunds | yes | Invoice, Customer, CreditMemo |
| stock_in | yes | `QtyOnHand` on tracked inventory items [documented, SDK] |
| tracking_in | no | no delivery records |
| prices_in, webhooks | no | not built |
| invoice_readback | yes | number, balance state |
| reconciliation | yes | query with `LIKE` |
| shadow_lookup | yes | `findExisting()` only reads |

`docs/spec/scope-update.md` item 4 says QuickBooks has "no stock or tracking to read"; the SDK documents `QtyOnHand`, so `stock_in` is set (see the note under Xero).

## 16. Not verified

- All Intuit narrative pages (OAuth page, entity pages, error codes, limits) could not be read; the SDK and blog posts stand in.
- Whether `Sku in (...)` and `DocNumber = ...` ignore letter case; the fake compares exactly, so a SKU that differs in case counts as unknown (SYNC-MAP-001) and no wrong invoice can result.
- That a query sees an invoice the moment it was created; the unchecked marker and error 6140 cover a delay.
- `PrimaryEmailAddr` as a query filter (the SDK marks only names as filterable); if QuickBooks answers a fault for that query the connector falls back to `DisplayName`.
- Whether `DocNumber` is kept when Custom transaction numbers is off (assumed not; Test connection requires it on).
- The exchange rate used when a foreign-currency invoice has no `ExchangeRate`.
- The `x_refresh_token_expires_in` handling is left to the engine's `refreshTokenLifetimeDays = 100`.
- Escaping in the query language (`\'` for a quote) follows common practice.
- Linking a credit memo to an invoice through a payment (not built).
- Privacy and terms URLs and the developer dashboard URL are the usual Intuit addresses; the fetch tool could not open them.
- The 25-redirect-URI limit and the CorePlus metering come from the research file.
- No live company was available. Behaviour above the wire is tested against `FakeQuickBooksServer` and fixtures written from the SDK schema.

## 17. Manual smoke test

1. In the Intuit developer dashboard create an app, use the development key set, and add the redirect address. In Intuit's sandbox open the sample company, and switch on Custom transaction numbers in its sales settings.
2. Choose Sandbox, paste the key set, sign in, pick the sample company, Test connection. Expected: company name, country, home currency, tax codes, a pass on Custom transaction numbers.
3. Create a service item named like `generic_item` and a product with a `Sku` equal to a WooCommerce SKU. Place an order: one invoice with `DocNumber` `WC-{order id}` and the right total.
4. Retry the order: no second invoice. Void the invoice in QuickBooks and retry: SYNC-VAL-002.
5. `documents = invoice_paid`: the invoice shows paid.
6. Refund: a credit memo `WC-{refund id}` with the note.
7. Order in a currency the company does not use with multi-currency off: SYNC-VAL-002 before anything is created.
