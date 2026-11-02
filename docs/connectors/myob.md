---
id: myob
name: MYOB
catalog_row: MYOB
connection_method: own_app
available: true
verified: false
hosts: [api.myob.com, arl2.api.myob.com, secure.myob.com]
terms_url: https://developer.myob.com/program/terms-conditions/
privacy_url: https://www.myob.com/au/legal/privacy-policy
developer_url: https://my.myob.com.au
variants: []
---

# MYOB connector

Connector id `myob`, type accounting, contract version 1. Code: `includes/Connectors/Myob/`. Registration: `MyobProvider`.

The connector uses the MYOB Business API (the "AccountRight" API, version `v2`) of MYOB AccountRight online files, MYOB Business and Essentials. It sends WooCommerce orders as item-layout sales invoices, optionally records the payment, sends refunds as credit invoices, finds or creates the customer card, and reads item stock (by location), invoice facts and a reconciliation list back. The merchant uses their own MYOB developer key.

Tags: `[documented]` official MYOB page (URL, fetched 2026-11-02), `[third-party]`, `[inferred]`. Nobody has run this against a live company file.

## 1. Sources

| Source | Used for |
| --- | --- |
| https://developer.myob.com/api/myob-business-api/api-overview/getting-started/ | base URLs, key and secret, Register App in the Developer tab of my.MYOB, sandbox invitation, request form for API access |
| https://developer.myob.com/api/myob-business-api/api-overview/authentication/ | authorize URL, token URL, parameters, `businessId` as the company file id, headers, `expires_in` 1200, refresh returns a new refresh token |
| https://developer.myob.com/api/myob-business-api/api-overview/headers/ | `x-myobapi-key`, `x-myobapi-version: v2`, `x-myobapi-cftoken` (base64 of `user:password`), `Authorization: Bearer`, ETag and conditional headers |
| https://developer.myob.com/api/myob-business-api/api-overview/error-messages/ and `.../error-codes/` | error body (`Name`, `Message`, `AdditionalDetails`, `ErrorCode`, `Severity`), the list of error names and codes, status codes |
| https://developer.myob.com/api/myob-business-api/api-overview/granular_data_scopes/ and `.../scopes/` | the 13 `sme-*` scope names |
| https://developer.myob.com/api/accountright/v2/sale/invoice/invoice_item/ | invoice fields, lengths, line fields, statuses `Open`/`Closed`/`Credit`, `IsTaxInclusive`, paging 400 default and 1,000 maximum |
| https://developer.myob.com/api/accountright/v2/sale/order/order_item/ | order number (13 characters, set by the caller or auto-incremented), `CustomerPurchaseOrderNumber` (255) |
| https://developer.myob.com/api/accountright/v2/contact/customer/ | customer fields and lengths, `Addresses[]` up to five, paging default 400 and maximum 5,000 |
| https://developer.myob.com/api/accountright/api-overview/retrieving-data/ | `$top` (default 400, maximum 1,000), `$skip` (a multiple of `$top` advised), `$orderby`, `$filter` with OData 3, the `Eq` and `Gt` examples, the `any` operator and the example `Addresses/any(x: x/Email eq 'Davis@yahoo.com.au')`, `datetime'2013-04-01'`, `NextPageLink` |
| https://developer.myob.com/api/accountright/v2/sale/customerpayment/ | the fields of a customer payment: `DepositTo` (`Account` or `UndepositedFunds`), `Account`, `Customer`, `ReceiptNumber` (13), `Date` (`YYYY-MM-DD HH:MM:SS`), `AmountReceived` (13.2), `PaymentMethod`, `Memo` (255), `Invoices[]` with `UID`, `Number`, `AmountApplied` and `Type` (`Invoice` or `Order`); the page does not describe the POST answer |
| https://developer.myob.com/api/accountright/v2/inventory/item/ | item `Number` (30), `QuantityOnHand`, `LocationDetails[]` per location |
| https://developer.myob.com/api/myob-business-api/v2/sale | the sale entities (CustomerPayment, CreditSettlement, CreditRefund, Invoice, Order, Quote) |
| https://apideck.com/blog/how-to-integrate-with-the-myob-api [third-party] | `businessId` returned in the redirect only with `prompt=consent`, 8 requests a second and 1,000,000 a day, over-limit answered 403, 29-second query timeout, two active keys per developer account, OData examples, no sandbox demo data, no webhooks |
| https://myob.lithium.com/discussions/accountrightapiquestions/api-key-has-exceeded-the-per-second-rate-limit/860953 [third-party] | the per-second 403 text, 8 per second |
| Research in the repository: `docs/research/integration-research-vendors.md` section 4.13 | `CompanyFile` scope removed 1 September 2026, no tracking field, no webhooks |

## 2. How the merchant connects

1. Ask MYOB for API access at `apisupport.myob.com`, then open the Developer tab in `my.myob.com.au` and choose Register App.
2. Register the app with the Redirect address shown here as its redirect URL, then copy its API key and secret.
3. Paste the API key as Client ID and the secret here, and set the currency of your company file (AUD or NZD).
4. Press Sign in with MYOB as an administrator of the file, approve access, then choose the company file.
5. If your file asks for a user name and password, enter them under Company file login in the advanced fields.
6. Press Test connection, then map each tax code in Tax mapping.

Gates: MYOB creates a my.MYOB login and a sandbox company file invitation after the access form; there is no review described [research, documented]. A developer account can have two active keys; a third deactivates one [third-party]. Essentials files have API access "but not all features" [research]. AccountRight Classic files (version 19 and older) become read-only after 28 February 2026 [third-party], so the API cannot create invoices there. Only a company-file administrator can approve the consent, and one consent covers one company file [research, third-party]. The redirect address registered for the key must match exactly [documented].

## 3. Authentication

OAuth 2 authorization code plus a developer key.

| Item | Value |
| --- | --- |
| Authorize | `https://secure.myob.com/oauth2/account/authorize` with `client_id`, `redirect_uri`, `response_type=code`, `scope`, `prompt=consent` (needed for `businessId`) [documented, third-party] |
| Token and refresh | `POST https://secure.myob.com/oauth2/v1/authorize`, form body with `client_id`, `client_secret`, `grant_type`, `code` or `refresh_token`, `redirect_uri`, `scope` [documented] |
| Scopes | `sme-company-file sme-general-ledger sme-sales sme-inventory sme-contacts-customer` [documented names]. Which endpoints each covers is not stated on the scope pages; tax codes and accounts are under General Ledger, payments and invoices under Sales [inferred] |
| PKCE | not used (not mentioned by MYOB) |
| Access token | `expires_in` 1200 (seconds [inferred]); the refresh answer carries a new refresh token, which the engine stores |
| Refresh token life | not stated |
| Key header | `x-myobapi-key` = the Client ID, `x-myobapi-version: v2`, `Authorization: Bearer ...` on every call [documented] |
| Company file | The files the person can open are read from `GET {host}/accountright/` (id, name, country, product version) and the person chooses one; the file named in the redirect (`businessId`, only with `prompt=consent`) comes first and is used when the callback hands it over. The API root of the file is `https://api.myob.com/accountright/{id}` [documented: `{cf_uri}` comes from `businessId`]. The advanced field `api_host` switches the host to `arl2.api.myob.com`, which one third-party article names as the cloud host [third-party] |
| Company file login | optional: `x-myobapi-cftoken` = base64 of `user:password` of the file [documented]. Not needed for files that accept the administrator's sign-in [third-party] |

Stored: client id (config), client secret, tokens and the optional file password (encrypted), `company_file_id` and `company_file_name` (config), `currency`. The company file id, user name and password are removed from every message and log line.

## 4. Data sent and when (for the WordPress.org "External services" disclosure)

| Host | When | Data |
| --- | --- | --- |
| `secure.myob.com` | sign-in (the person's browser), token exchange and refresh | client id and secret, the one-time code or refresh token, scopes |
| `api.myob.com` (or `arl2.api.myob.com` when chosen) | test connection, each queued order, refund and customer, scheduled reads | company file list or name (read); customer name, email, phone, address; invoice lines (item number, description, quantity, price, tax code), freight, totals, dates, the shop's reference `WC-{id}`; payments; reads of tax codes, items, locations, invoices |

Nothing is sent to Estasoft or anyone else.

## 5. Connection settings

Credential fields: `client_id`, `client_secret`, `currency` (AUD or NZD; the currency of the company file), `company_file_user`, `company_file_password` (both optional, advanced), `api_host` (select, advanced), `company_file_id` (advanced; filled in by sign-in), the redirect address (display only).

Options (`MyobOptions`):

| Key | Values | Default |
| --- | --- | --- |
| `documents` | `invoice`, `invoice_paid` | `invoice` |
| `generic_item` | item number (sold) used for fee and discount lines that have no item of their own, and for a refund that names no product | empty (such lines are refused, naming the option) |
| `fee_item`, `discount_item` | item number (sold) used for fee and discount lines | `generic_item` |
| `payment_account` | display id of the account the payment is deposited to (for example `1-1110`); empty uses Undeposited Funds | empty |
| `due_days` | 0 to 365 | 0 |
| `total_mismatch` | `fail` (delete the invoice and stop) or `warn` | `fail` |

Unknown SKU: always SYNC-MAP-001; an item-layout invoice needs an item on every product line [documented], so there is no free-text mode.

## 6. Objects and field mapping

Payload paths follow the Odoo connector's names. An order becomes `Sale/Invoice/Item`; MYOB also has `Sale/Order/Item`, which is not used.

| Payload path | MYOB field |
| --- | --- |
| `client_order_ref` (always the item's `WC-{id}`) | `CustomerPurchaseOrderNumber` (255) |
| (not sent) | `Number` (13 characters); MYOB numbers the invoice [documented: blank auto-increments] |
| `date_order` | `Date` as `YYYY-MM-DD 00:00:00` (the shop's date from `date_utc`); an order without a date is refused |
| `currency` | must equal the connection's `currency`; otherwise SYNC-VAL-002 (foreign-currency documents are not built) |
| `prices_include_tax` | `IsTaxInclusive` |
| `note` | `Comment` (2,000) |
| `total`, `excluded_total` | not sent; the expected total for the check |
| `partner.*` | customer card: with a company name `IsIndividual` false and `CompanyName` (50); else `IsIndividual` true, `LastName` (30) and `FirstName` (20) from the first and last name, or from the name split at its last space (a single word is the last name). `Addresses[]`: location 1 with `Street` 255 (street and street 2 on two lines), `City`, `State`, `PostCode` 11, `Country`, `Phone1` 21, `Email` 255, `ContactName` 25; location 2 for a different delivery address |
| `delivery.*` | invoice `ShipToAddress` (255, street, street 2, city state postcode and country on separate lines) |
| `order_line[].product` | `Lines[].Item.UID` (item found by `Number`, max 30) |
| `order_line[].name`, `quantity`, `price_unit`, `discount` | `Description` (1,000; the item's name when the line has none), `ShipQuantity`, `UnitPrice`, `DiscountPercent`; `Total` is quantity x unit price less the discount, in whole cents, rounded half away from zero [the rounding is inferred; the total check catches a difference over one cent] |
| `order_line[].tax` | `Lines[].TaxCode.UID` (found by the 3-character `Code`) |
| `shipping.total`, `shipping.tax` | `Freight`, `FreightTaxCode.UID` (the freight is not an item line) |
| `fee[]`, `discount.*` | lines on `fee_item` / `discount_item` (else `generic_item`); a discount has `ShipQuantity` -1, a positive `UnitPrice` and a negative `Total` [inferred from error 12101] |
| `payment.reference`, `payment.date` | customer payment `Memo` (`WC-{id} {reference}`), `Date` |
| `due_days` option | `Terms` `PaymentIsDue` `InAGivenNumberOfDays`, `BalanceDueDate` = the days [third-party, inferred] |
| `note` | `Comment` (2,000; MYOB prints it on the invoice) |

Refunds: `order_ref`, `date`, `reason`, `amount`, `prices_include_tax`, `refund_line[]`, `shipping.*` become a credit invoice (section 11).

## 7. Flow of an order

MYOB posts an invoice when it is created; there is no draft. Checks come first and the undo is a delete.

1. Check the payload before any request. The order currency must equal the connection's `currency`.
2. Find the invoice: `GET {file}/Sale/Invoice?$filter=CustomerPurchaseOrderNumber eq 'WC-{id}'` [filter syntax inferred], all pages (`$top` 1000, `$skip`). A hit is finished when it exists; with `invoice_paid` it is finished when `Status` is `Closed` or its balance is 0.
3. If none: read the tax codes once (`GET {file}/GeneralLedger/TaxCode`, all pages, cached for the request), resolve items (`GET {file}/Inventory/Item?$filter=Number eq 'A' or Number eq 'B'`, 20 numbers a call), build and check the body, then resolve the customer (below). Unknown SKU stops with SYNC-MAP-001, an item that is not marked as sold or an unknown tax code with SYNC-VAL-002 naming it, all before anything is created (no customer card either).
4. `POST {file}/Sale/Invoice/Item`; MYOB answers 201 with the new address in the `Location` header and no body [third-party; the response body is not documented], then the connector reads the invoice with `GET`.
5. Compare the invoice: no `ForeignCurrency`, `TotalAmount` against `total - excluded_total` (one minor unit allowed), `IsTaxInclusive` as sent. A difference is SYNC-VAL-002 naming both totals; with `fail` the invoice is first deleted (`DELETE`; refused by MYOB once it has a payment, error 10001).
6. With `invoice_paid` and a payment date: `POST {file}/Sale/CustomerPayment` (section 10; `AmountReceived` = the invoice's balance read just before, the invoice with `AmountApplied`), then the invoice is read again. The pass succeeds only when `BalanceDueAmount` is 0; any other balance, or an invoice that cannot be read, ends the pass with SYNC-VAL-002 naming the balance. If the balance is already 0 before the payment, nothing is created.
7. Result: external id = invoice `UID`, reference = `Number`, `extra` = total in minor units, status.

Replays: invoice and payment are created once per pass; a failure that may have reached MYOB ends the pass, and the next pass starts at step 2.

Known gap: MYOB has no draft state and no field the connector could mark and clear without rewriting the whole invoice. If a pass is killed between the create (step 4) and the read-back (step 5), the next pass finds the invoice and accepts it without checking its total. The nightly check compares the total of every `WC-` invoice with the shop's (`listForReconcile` reports `total_minor`).

Customers: `GET {file}/Contact/Customer?$filter=Addresses/any(x: x/Email eq 'x')&$top=1000&$skip=0`, exactly the form MYOB shows on its Retrieving data page (the OData 3 `any` operator) [documented]. The email is always stored on the card's first address, even when the order has no street, so the card is found again. The page does not say whether `eq` ignores case, so when nothing is found and the email has capitals the lookup is repeated once with the email in lower case; the cards are then compared without regard to case. The answer is read page by page until a page is short (three pages at most, a stop for a server that ignores the filter) and every row is compared with the email. If MYOB answers that the filter is not supported (400 or 501), the connector remembers that for the rest of the request and reads the customer list instead: three pages of 1,000 at most, not twenty, comparing the email itself; beyond that it stops with SYNC-VAL-002 (the message says more than 3,000 customers). No match: `POST {file}/Contact/Customer`, then `GET` the card. An invoice never goes to a different customer than the email says.

## 8. Duplicate protection

- Reference field: `CustomerPurchaseOrderNumber` = `WC-{order id}` for invoices and `WC-{refund id}` for credit invoices; searched before every create.
- MYOB offers no idempotency key and no duplicate check on that field [documented: none found]. The protection is the search plus the rule that the connector never repeats a create after a failure that may have reached MYOB without reading first.
- `findExisting()` returns an invoice only when it is finished for the connection's settings.

## 9. Taxes

Tax codes are the 3-character MYOB codes (`GST`, `FRE`, `N-T`, ...). `listTaxCodes()` reads `GeneralLedger/TaxCode` (`Code`, `Description`, `Rate`). `tax_lookup` returns the code; the connector turns it into a `UID`. `IsTaxInclusive` follows `prices_include_tax`, so the connector does no tax arithmetic; the total check catches differences. A line whose tax key is present but empty is sent with the code `N-T` when the file has it [inferred], else without a tax code; a line with no tax key at all is sent without one and MYOB uses the item's default. A code the file does not have stops the item with SYNC-VAL-002 naming the code (choose another in Tax mapping).

## 10. Payments

Off unless `documents = invoice_paid`. The body follows MYOB's CustomerPayment page [documented, fetched 2026-11-02]: `Date` (`YYYY-MM-DD 00:00:00`), `Customer.UID`, `Memo` (`WC-{id}` and the payment reference, 255), `AmountReceived` (the balance, in the file's currency), `DepositTo` (`Account` with `Account.UID`, or `UndepositedFunds` when `payment_account` is empty) and `Invoices[]` with `UID`, `Type` `Invoice` and `AmountApplied` (the same amount). Nothing else is sent. (Earlier drafts had `AmountPaid`, `Amount` and `PayFrom`; they are not fields of the page.) MYOB can answer 201 and still leave a balance (a payment that is applied in part), so the invoice is read again and the pass fails unless the balance is 0; the message names the balance and the next try pays only what is still due, never the whole total again. An account number that MYOB does not know stops the pass with SYNC-VAL-002 naming `payment_account`; the invoice is kept.

## 11. Refunds

MYOB has no credit note endpoint of its own on the sale pages. Its error list names a status `Credit` for invoices and the entities CreditSettlement and CreditRefund that work on invoices "in Credit status" [documented], and it refuses a negative unit price with a non-zero total (error 12101) [documented]. So the connector creates a credit as an item invoice with negative quantities and positive prices, `CustomerPurchaseOrderNumber` `WC-{refund id}`, and checks that MYOB reports `Status` `Credit` [inferred]. It is not applied to the original invoice (CreditSettlement is documented but its fields were not read); the person applies it in MYOB. If the check shows another status the credit is deleted and the item fails with SYNC-VAL-002.

## 12. Reading from MYOB

- `fetchStock`: `Inventory/Item` by `Number`; inventoried items only; on hand is `QuantityOnHand` of the item (not below 0), or the sum over the chosen locations from `LocationDetails[]` (matched by identifier or name, ignoring case) when locations are given; held is `QuantityCommitted` [documented fields].
- `searchProducts`: `$filter=substringof('text',Number) or substringof('text',Name)` [OData 3, inferred], at most 25.
- `fetchInvoice`: number, status, date, balance state; no tax-authority status.
- `listForReconcile`: invoices with `CustomerPurchaseOrderNumber` starting `WC-` from the date on (`$filter=startswith(CustomerPurchaseOrderNumber,'WC-') and Date ge datetime'2026-11-01'` [inferred]), paged. Credits (status `Credit`) are not orders; their `JournalMemo` `Refund of WC-{order id}` adds them up as the refunded amount. The invoice list carries no email, so `email` is empty.
- Tracking: MYOB has no tracking field (closest: `ShippingMethod`, 20 characters); not built. Prices and webhooks: not built; MYOB has no webhooks.

## 13. Rate limits and pacing

The circuit breaker's health probe (`ping()`) is one `GET https://{host}/accountright/` (the list of company files; it needs no company file and no file login), made fresh every time; it is false on any failure.

8 requests a second and 1,000,000 a day per key [third-party]. Over the limit MYOB answers 403 with `Name` `RateLimitError` and `AdditionalDetails` `Header: x-myobapi-key` [documented], not 429. The connector reads that as SYNC-RATE-001 (a "per-second" text waits 5 seconds; a "daily" text waits until the next UTC day, at most 24 hours). Pacing: one call at a time, at most 4 a second. A query longer than about 29 seconds is cut by MYOB [third-party]; the connector asks for 1,000 rows at most.

## 14. Error mapping

| What MYOB answers | SYNC code |
| --- | --- |
| 403 `RateLimitError` | SYNC-RATE-001 |
| 403 `DeveloperInactive` | SYNC-AUTH-001 (the key is inactive or missing) |
| 403 `AccessDenied` (check `x-myobapi-cftoken`) | SYNC-AUTH-004; the message names the company file login |
| 401 | SYNC-AUTH-002 after one refresh |
| 400 `NotFound` (150), `Required` (100), `SerializationError` (50), `UnitPriceNotValid` (12101), `ValidationError`, `DatePriorToBeginningOfFinancialYear` (25008), `DateInLockedPeriod` (25009) | SYNC-VAL-002 with the message |
| a tax code, item or account that the connector looks up and does not find | SYNC-VAL-002 (tax, account, item not sold) or SYNC-MAP-001 (item) before anything is created |
| 400 `InsufficientItemQuantity` (25002) | SYNC-VAL-002 (the sale would make stock negative; MYOB has no API switch for this) |
| 409 `IncorrectRowVersionSupplied` (111) | SYNC-NET-002 (retry) |
| 504 `GatewayTimeout`, time-out, reset | SYNC-NET-001 |
| 429 (not documented for MYOB, handled anyway) | SYNC-RATE-001 with `Retry-After` |
| 500, 501, other 5xx | SYNC-NET-002 |
| certificate failure | SYNC-NET-003 |
| unknown SKU | SYNC-MAP-001 |
| currency differs, totals differ, credit not in Credit status | SYNC-VAL-002 |

## 15. Capabilities

| Flag | Value | Why |
| --- | --- | --- |
| orders, customers | yes | item invoices, customer cards |
| refunds | yes | credit invoice (inferred, see section 11) |
| stock_in | yes | `QuantityOnHand`, per location [documented] |
| tracking_in | no | no tracking field |
| prices_in, webhooks | no | not built / none |
| invoice_readback | yes | number, status, balance |
| reconciliation | yes | filtered, paged |
| shadow_lookup | yes | `findExisting()` only reads |

## 16. Not verified

- The Retrieving data page lists only `Eq` and `Gt` and the `any`/`all` operators and refers to the OData 3 specification. The email filter is the page's own example. The filters on `CustomerPurchaseOrderNumber`, `Number`, `startswith`, `substringof` and `ge datetime'...'` use OData 3 syntax that the page does not show; the file's behaviour for them is untested. Whether `eq` ignores case is not stated (the lookup tries both spellings). Whether the filter on a child collection is fast on a large file is unknown.
- The answer to `POST CustomerPayment` (the `Location` header, as for the other creates) and whether MYOB applies a payment to the invoice in full on the first call; the read-back guards the second.
- The `Location` header on POST and an empty body (third-party), and `NextPageLink` / `Count` in lists.
- Whether `api.myob.com` or `arl2.api.myob.com` serves the new keys; the first is the one documented.
- The credit-invoice representation, and the discount-line shape. (The `CustomerPayment` fields are from the page; whether `PaymentMethod` is required by a company file is not stated, so none is sent.)
- Whether `GeneralLedger/TaxCode`, `Inventory/Location` and `Contact/Customer` are inside the scopes requested.
- Refresh token lifetime; whether a refresh token rotates every time (the sample shows a new one).
- Whether a file that needs a file password also needs it for administrator sign-ins.
- Foreign-currency invoices (not built: the order currency must equal the file's).
- Terms URL (`MYOB Developer Program Terms`) and privacy URL (`MYOB Privacy Policy (Australia)`) were opened on 2026-11-02 and are the right pages. The privacy policy is the Australian one; New Zealand files have their own.
- No live file was available. Behaviour above the wire is tested against `FakeMyobServer` and fixtures written from the field lists above.

## 17. Manual smoke test

1. Request API access, accept the sandbox invitation, register an app in my.MYOB with the plugin's redirect address.
2. Paste key and secret, set the currency, sign in as the sandbox file's administrator, press Test connection. Expected: the file name, tax codes found, locations.
3. Create an item whose number equals a WooCommerce SKU. Place an order: one invoice, `Customer Purchase Order No.` `WC-{order id}`, the shop's date, the right total. Retry: no second invoice.
4. `invoice_paid` with `payment_account`: the invoice shows as paid.
5. Refund: check the credit appears with status Credit.
6. Change an item's quantity and run the stock read; set a location and read it per location.
7. Send an order in another currency: SYNC-VAL-002 before anything is created.
8. Press Test connection twice in a minute to see the pacing; if MYOB answers 403 RateLimitError the item waits.
