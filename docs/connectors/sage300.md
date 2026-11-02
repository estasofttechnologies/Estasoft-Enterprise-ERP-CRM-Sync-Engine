---
id: sage300
name: Sage 300
catalog_row: Sage 200, Sage 300 and Sage X3
connection_method: server_login
available: true
verified: false
hosts: []
terms_url: https://developer.sage.com/300/
privacy_url: https://www.sage.com/en-gb/legal/privacy-and-cookies/
developer_url: https://github.com/SageNADev/Sage300-SDK/tree/master/docs/webapi
variants: [{ id: sage300, label: "Sage 300 (Web API on your own web server)" }]
---

# Sage 300 connector

Connector id `sage300`, type ERP, contract version 1. Code: `includes/Connectors/Sage300/` (shared order and money helpers in `includes/Connectors/ErpCommon/`). One of the three products of the catalog row "Sage 200, Sage 300 and Sage X3". This is Sage 300 ERP (formerly Accpac), not Sage 300 CRE or Sage 300 People, which have different products and APIs.

The connector sends WooCommerce orders to Sage 300 as Order Entry orders (`OE/OEOrders`) and refunds as Order Entry credit notes (`OE/OECreditDebitNotes`). Totals are compared with the shop on both: an order whose answer holds no total, and a refund without the setting `refund_total_property`, are refused unless the person chose "Continue with a warning". By default every order goes on one customer account chosen by the merchant. It does not read stock, tracking or invoices back (no documented endpoint, section 14).

The Sage 300 Web API is installed by the merchant on their own IIS server (the "Web Screens" option of the Sage 300 setup). **Whether Sage allows a free, open-source plugin to use the Web API without partner status is not answered in any document read. Ask Sage before launch** (docs/spec/scope-update.md, open item 5). The Technical Partner Program Agreement and the Partner Code of Conduct are linked from https://developer.sage.com/300/ and were not readable; the plugin never says or implies that Sage endorses it.

## 1. Sources

Fetched or read on 2026-11-02.

| Source | Used for |
| --- | --- |
| https://github.com/SageNADev/Sage300-SDK, folder `docs/webapi`: `Sage300_WebAPI_EndpointReference.docx` and `Sage300SDK_DeveloperReference.docx` (August 2022), cloned and read `[documented]` | URL `{protocol}://{host-application-path}/{version}/-/{company}/{module}/{resource}`, example `http://localhost/Sage300WebApi/v1.0/-/SAMLTD/AR/ARCustomers`; Basic authentication on every request, `Content-Type: application/json` (otherwise HTTP 500); `$filter` with `eq ne gt lt le and or`, `substringof`, `startswith`, `endswith`, `length`; literals (string `'1200'`, decimal `99.99m`, `datetime'2015-12-31T12:00'`); `$skip`, `$top`, `$count=true` (`odata.count`); default maximum page 100 (`PageSize` in `Web.config`, 1,000 recommended); keys `ARCustomers('1200')`, composite keys; POST one record, DELETE one record, PUT replace, PATCH with `UpdateOperation`; `$metadata` per company and module; `($template)` and `($process)`; partial payloads; property order matters; error bodies `{"error": {"code": ..., "message": {"lang", "value"}}}` with the code list (`Unauthorized`, `Forbidden`, `TooManyRequests`, `InvalidParameters`, `ResourceNotFound`, `RecordNotFound`, `InvalidPayload`, `RecordDuplicate`, `RecordInvalid`, `General`, ...); IIS idle time-out 20 minutes |
| Endpoint reference tables, same files `[documented]` | Transaction endpoints: `OEOrders` GET and POST, `OEInvoices` GET only, `OECreditDebitNotes` GET and POST; master data: `ARCustomers`, `ICItems`, `ICLocations`, `OEMiscellaneousCharges`, `TXTaxGroups`, `TXTaxRates` (GET only) |
| `samples/WebApi_SamplePostPayloads/` in the same repository `[documented]` | `OE/OEOrders_OrdersOnly.txt` (`CustomerNumber`, `OrderDescription`, `OrderDate`, `OrderDetails[LineType, Item, Location, QuantityOrdered]` and a Miscellaneous line with `MiscellaneousChargesCode`, `ExtendedAmount`), `OEOrders_WithShipmentsandInvoice.txt` (`InvoiceWillBeProduced`, `QuantityShipped`), `OE/OECreditDebitNotes.txt` (`CustomerNumber`, `Reference`, `Description`, `CreditDebitNoteDate`, `CreditDebitNoteType: CreditNote`, `CreditDebitDetails[LineType, Item, Location, QuantityReturned, CreditDebitNoteUOM, UnitPrice]` and a Miscellaneous line with `ExtendedAmountMiscellaneousCharge`), `AR/ARCustomers.txt`, `IC/ICItems.txt` |
| `resources/Sage300Resources/.../OECommonResx.resx` in the same repository `[documented]` | The UI label keys `PONumber`, `Reference`, `OrderNumber`, `OrderDate`, `OrderUniquifier` of the Order Entry module; the Web API property names follow the same naming, which is why `PONumber` and `Reference` are the reference candidates |
| https://developer.sage.com/300/ `[documented]` | The partner programme documents, Web SDK, Product SDK (restricted to registered partners); no REST API pages |
| https://github.com/omniboost/go-sage300 (a Go client) and https://github.com/agenticledger/sage300-mcp-http `[third-party]` | Responses as `{"@odata.context", "value": [...], "@odata.nextLink"}`; `TX/TXTaxGroups` fields `TaxGroupKey`, `Description`; `TX/TXTaxRates`; entity keys encoded as `ARCustomers('1200')`; no live test in either project |
| docs/research/integration-research-vendors.md section 4.12 | Earlier research: no sandbox, sample companies SAMLTD and SAMINC, Sage R&D advises exposing only ports 80/443 with Require SSL or using a VPN |

The Web API is stateless: **there is no login call and no session**. The brief for this connector said "session login"; the documentation says Basic authentication per request, so that is what is built. No session id is stored and no logout is needed.

## 2. How the merchant connects

1. Make sure the Sage 300 Web API is installed on a web server that your WordPress site can reach over https.
2. In Sage 300 create a user for the sync and give its security group the Web API, Order Entry and Accounts Receivable rights.
3. Paste the https address of the Web API (it ends with `/Sage300WebApi`), the company ID, the user name and the password here.
4. Enter the customer number that should receive the web orders, then press Test connection.
5. In Sage 300 create the items with the same item number as the WooCommerce SKU, and a miscellaneous charge for shipping.

Plan gate: none stated. The Web API must be installed and the IIS site must allow the host of the shop. The plugin refuses an `http` address, because Basic sign-in sends the password with every request (docs/spec/scope-update.md section 11, item 7), and refuses private or loopback addresses unless the filter `estasoft_sync_allow_private_hosts` allows them. Sage R&D advises exposing only ports 80 and 443 with a binding that requires SSL, or a VPN `[research]`; a VPN is outside what the plugin can do.

## 3. Authentication

The connector never follows an address the server sends back (no `@odata.nextLink`, no `Location`); lists are paged with `$top` and `$skip`, so the Basic credentials only go to the address the merchant typed. HTTP Basic on every request: `Authorization: Basic base64(user:password)` (`BasicAuth` of the shared base). If security is switched off for the system database, the password is empty; the plugin requires a password. The company ID (org ID) is part of every URL. Stored: `address`, `company`, `username` (config), `password` (secret). IIS stops an idle application after 20 minutes, so the first request after a pause can be slow (the 30 s timeout still applies).

## 4. Data sent and when (External services disclosure)

Host: the web server address the merchant typed. Nothing else is contacted.

| Action | Data sent | Data read |
| --- | --- | --- |
| Test connection | Nothing | `$metadata` of the Order Entry module, one customer, one order, locations, tax groups |
| Order synced | Order number, order date, line item numbers, quantities, prices, shipping and fee amounts, order note, tax group | The customer, the items by SKU, the order by order number, totals back |
| Refund synced | Refund lines, quantities, amounts, refund number | Items, credit note by refund number |

## 5. Objects and field mapping

Order payload paths are the shared ones of the ERP connectors (without the partner address, which Sage 300 takes from the customer record); the default rules are `ErpCommon\DefaultRules` (the same list the ERPNext connector uses).

| Payload path | Sage 300 property | Notes |
| --- | --- | --- |
| client reference (queue item) | `OEOrders.PONumber` (or the option `reference_property`, for example `Reference`) | `WC-{order id}`; 22 characters is the usual PO number length `[inferred]`, longer is refused |
| (option `customer_number`) | `CustomerNumber` | The customer account of the web orders |
| `date_order` | `OrderDate` | `YYYY-MM-DD`, the shop's date |
| `note` | `OrderDescription` | |
| `order_line[].product` | `OrderDetails[].Item` with `LineType: Item` | Item number equals SKU; unknown SKU: SYNC-MAP-001 |
| `order_line[].quantity`, `price_unit` | `QuantityOrdered`, `UnitPrice` | `UnitPrice` on order lines is **not** in the sample; Test connection checks that the property exists in `$metadata`; if not, the order is refused |
| option `location` | `Location` | Inventory location of stock items (the sample sets it) |
| `shipping.total`, `fee[].total` | `OrderDetails[]` with `LineType: Miscellaneous`, `MiscellaneousChargesCode`, `ExtendedAmount` | Codes from the options `shipping_charge_code`, `fee_charge_code` |
| `discount.total` | a Miscellaneous line with `discount_charge_code` and a negative `ExtendedAmount` | Refused when the option is empty |
| `order_line[].tax` | `TaxGroup` of the order | One tax group per order |

Options (config): `refund_total_property`, `customer_policy` (`fixed` default, `match_only`), `customer_number`, `location`, `shipping_charge_code`, `fee_charge_code`, `discount_charge_code`, `refund_charge_code`, `reference_property`, `total_property`, `total_mismatch` (`fail`, `warn`).

Customer policy `fixed`: every order goes on `customer_number`; the buyer's name and address are not sent (Sage 300 customer records need an account set, a group and terms that differ per company). `match_only`: the buyer's email is searched in the property of `ARCustomers` whose name contains "Email" (discovered from `$metadata`; the first one); no customer is created; an unknown buyer stops the item with SYNC-VAL-002 naming `partner.email`, and an order without an email stops it with SYNC-MAP-003. With the `fixed` policy the buyer's name, email and address are not needed and are not sent. The connector does not create customers: the required fields (account set, group code, terms, tax group, currency) are company setup and the sample does not show address or email property names.

Currency: Sage 300 orders follow the customer's currency. The customer's `CurrencyCode` is read (`GET AR/ARCustomers('{number}')`); an order in another currency is refused with SYNC-VAL-002 naming both, before anything is created.

## 6. Order flow

1. Parse the payload; check email policy, currency (against the customer), lines and options.
2. Read `$metadata` of the OE module once (cached for the request) and choose the reference property (`reference_property`, else `PONumber`, else `Reference`); stop with SYNC-VAL-002 if neither exists on the order entity.
3. Find the order: `GET OE/OEOrders?$filter=<reference> eq 'WC-{id}'`. If it exists it is never created again; the pass goes to step 6.
4. Resolve each SKU: `GET IC/ICItems('{sku}')`. A `RecordNotFound` is SYNC-MAP-001, before the order. The tax group (if any) is checked against `TX/TXTaxGroups`.
5. `POST OE/OEOrders` with the header and `OrderDetails`, partial payload, properties in the documented order (`CustomerNumber`, `OrderDate`, reference, description, details). The answer holds the inserted record with its system-generated keys (`OrderUniquifier`).
6. Check: `total_property` (default: the first of `OrderTotal`, `TotalOrderAmount` present in the answer) against `total - excluded_total` within one minor unit. A difference stops the item (`fail` default) or goes on with a warning (`warn`); the order stays in Sage 300 for the person, and the next pass finds it and checks again. When none of the total properties is present the check cannot run, because price lists in Sage 300 can replace the `UnitPrice` that was sent without anyone noticing: the item stops with SYNC-VAL-002 (field `total_property`), the order stays in Sage 300, and every later pass checks it again; only when the person chose `total_mismatch = warn` does the result carry a warning "total not verified" instead.

The reference returned is `OrderUniquifier` (else the order number) with the order number as its number; `extra` has `currency` (the customer's) and `total_minor` when the answer has a total. `findExisting()` returns a reference for an existing order (there are no later steps); `send()` reads an existing order back and checks its total again, so a wrong total is reported on every pass until the person deletes the order in Sage 300. Dates: `OrderDate` as sent. Test connection runs the same checks of the property names (reference, `UnitPrice`, the customer) and fails with the setting named.

## 7. Duplicate protection

The PO number (or Reference) property holds `WC-{id}` and is searched before every create. Sage 300 offers no idempotency key; the Web API answers `RecordDuplicate` only for a repeated key, and order keys are generated by Sage, so the search is the only protection. A credit note holds `WC-{refund id}` in `Reference` and is searched in `OECreditDebitNotes`.

## 8. Taxes

The tax code of an order is the **tax group key** (`TaxGroupKey` of `TX/TXTaxGroups`, for example `USDTAX`); `listTaxCodes()` lists them with their description. The rate is not given in the group record (it is the sum of the authority rates by item tax class in `TXTaxRates`), so `rate_percent` is `0` and the name says so. Sage 300 works out the tax from the tax group and the item tax class. All lines of an order must name the same group, or none; the connector sends it as `TaxGroup` of the order only if `$metadata` shows that property. Prices are sent without tax; a mapping with `prices_include_tax` is refused naming the setting (Sage 300 option "tax included" is not read). The total check catches a wrong tax setup.

## 9. Payments

Not implemented. Receipts go through `AR/ARReceiptAndAdjustmentBatches`, which are batches that need a bank, a batch number series and posting; the sample payloads were not read for it. No flag is set.

## 10. Refunds

A refund is a `POST OE/OECreditDebitNotes` with `CreditDebitNoteType: CreditNote`, `CustomerNumber` of the order's customer (read from the order), `Reference = WC-{refund id}`, `Description` ("Refund of WC-{order id}" and the reason, 60 characters), `CreditDebitNoteDate`, and `CreditDebitDetails` per refunded product (`LineType: Item`, `Item`, `Location`, `QuantityReturned`, `CreditDebitNoteUOM` = the item's stocking unit read from `ICItems.StockingUnitOfMeasure`, `UnitPrice` the line amount divided by the quantity). Refunded shipping is a Miscellaneous line with the `shipping_charge_code`, a refund without lines one with the `refund_charge_code` (`ExtendedAmountMiscellaneousCharge`, the amount as the shop gives it). The total of the credit note is compared with the refund amount (one minor unit). The property that holds it is not documented, so it is the setting `refund_total_property`: without it a refund is refused before anything is made (SYNC-VAL-002, field `refund_total_property`) unless the person chose `total_mismatch = warn`; a credit note whose answer has no value there, or a different total, is reported on that pass and on every later one until the person deletes it in Sage 300 (`warn` only warns). The note is not linked to the original invoice. The original order must exist (`WC-{order id}`), otherwise SYNC-VAL-002. Posting of the credit note is done by a person in Sage 300 (the posting process endpoints exist but are not used).

## 11. Reading from Sage 300

- **No nightly list.** `listForReconcile` is not provided and the `reconciliation` flag is off: credit notes are not tied to an order, so a list with `refunded_minor` always 0 would raise a false `refund_missing` every night for every refunded order.
- `lookupCustomer` (when the email property is known), `lookupProduct` (`ICItems('{sku}')`), `searchProducts` (`substringof('{q}',ItemNumber)`; falls back to the key lookup), `listTaxCodes`: read-only helpers.
- **No stock read**: the endpoint list has no stock quantity resource (`ICItems` holds item master data). **No tracking read**: no shipment resource with a tracking number in the lists. **No invoice read-back**: `OEInvoices` is listed as GET only but its property names are not documented in anything read.

## 12. Rate limits and pacing

None stated. Page size is 100 by default (`PageSize` in `Web.config`). `TooManyRequests` is a documented error code ("server flooded with too many concurrent requests"), so the connector sends one request at a time, two a second at most, with a 30 s timeout. `TooManyRequests` and HTTP 429 give SYNC-RATE-001 (60 s).

## 13. Error mapping

The body is `{"error": {"code": "...", "message": {"value": "..."}}}`; the code decides, the HTTP status second.

| Sage 300 answer | SYNC code | Class |
| --- | --- | --- |
| Time-out, reset, DNS, IIS warming up | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| 5xx, `InternalServerError`, `DBLinkError`, `SessionError` | SYNC-NET-002 | retry |
| `Unauthorized` / 401 | SYNC-AUTH-001 | needs a person |
| `Forbidden` / 403 | SYNC-AUTH-004 (security group lacks Web API or module rights) | needs a person |
| `TooManyRequests` / 429 | SYNC-RATE-001 | retry |
| `ResourceNotFound` | SYNC-VAL-002 "the Web API does not offer {resource}" | needs a person |
| `RecordNotFound` on an optional lookup | normal "not found" | |
| `RecordNotFound` on a required item | SYNC-MAP-001 | needs a person |
| `RecordDuplicate` | SYNC-DUP-001 | handled |
| `InvalidPayload`, `InvalidParameters`, `InvalidEntityKey`, `RecordInvalid`, `General`, `MethodNotAllowed`, `InvalidAction` | SYNC-VAL-002 with the cleaned message | needs a person |
| Non-JSON body or HTML (not the Web API) | SYNC-VAL-002 "this address is not a Sage 300 Web API" | needs a person |
| Customer, currency, total, option problems | SYNC-VAL-002, SYNC-MAP-003 | needs a person |

Passwords, user names, the company ID and the host are scrubbed from every message; the message kept is at most 160 characters.

## 14. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| orders | yes | `OEOrders` (GET and POST documented) |
| customers | no | The connector does not create customers; see section 5 |
| refunds | yes | `OECreditDebitNotes` (GET and POST documented, sample payload) |
| stock_in | no | No stock quantity endpoint in the endpoint reference |
| tracking_in | no | No shipment/tracking endpoint |
| prices_in | no | |
| invoice_readback | no | Property names undocumented |
| reconciliation | no | Credit notes cannot be tied to their order, so the nightly check would report `refund_missing` for every refunded order; the flag is off rather than wrong |
| webhooks | no | None |
| shadow_lookup | yes | Read-only |

## 15. Not verified

- **Partner status**: whether Sage lets a free third-party plugin call the Sage 300 Web API (open item 5). Still not answered. What was read on 2026-11-02: the Sage 300 developer page (https://developer.sage.com/300) lists a "Technical Partner Program Agreement" and says the Product SDK is "available for registered partners" (partner ID required); the North America developer partner programme sheet (https://developer.sage.com/300/guides/Sage%20NA%20Developer%20Partner%20Program.pdf) says joining for Sage 300 needs an application, a signed Developer Partner Program Agreement, a $2,000 sign-up fee and a $1,000 annual renewal, and lists the SDK as a programme benefit; neither mentions the Web API.
- The Order Entry property names beyond the official sample: `PONumber`, `Reference` (as order reference), `UnitPrice` on order lines, `TaxGroup` on the order, the total property, the customer email property. The connector discovers them from `$metadata` and refuses with a clear message when they are absent; their types and lengths were not read.
- The key of `ICItems` (formatted or unformatted item number) for `ICItems('{sku}')`.
- The property names `ItemNumber`, `StockingUnitOfMeasure`, `CustomerNumber`, `CustomerName`, `CurrencyCode` on the customer and the item, `CreditDebitNoteNumber` and `CreditDebitNoteUniquifier` on the credit note, `OrderNumber` and `OrderUniquifier` on the order, `Location` and `Description` of `ICLocations`; the shape of the `$metadata` document (read as OData CSDL with `EntityType`, `Property`, `NavigationProperty` and `EntitySet`; a server that does not describe itself is tried with the documented names); and the `Accept: application/json` header.
- Creating customers is not built (section 5); the shop buyer's details are not stored in Sage 300 with the `fixed` policy.
- Whether `startswith` is accepted on non-indexed properties and on every Sage 300 release; the text shows `startswith` on `CustomerNumber`, a key.
- Sage 300 versions before 2020 may not expose `OECreditDebitNotes`; the endpoint reference read is from August 2022.
- Behaviour with several companies on one server beyond the company ID in the URL; with Sage 300 multicurrency; with inventory locations that need lot or serial numbers.
- Latest release and support dates (the research said 2027).
- The privacy address in the front matter is listed by web search but was not read (the fetch tool failed on it); the terms address is the Sage 300 developer page, which was read (it lists the Technical Partner Program Agreement without its text).

## 16. Manual smoke test against a real Sage 300

1. Install Sage 300 with the sample companies (SAMLTD), choose the Web Screens option so IIS publishes `Sage300WebApi`, and bind https (a self-signed certificate is refused by the plugin; use a trusted one or a test CA on the shop's host).
2. Create a user in a security group with Web API, Order Entry and Accounts Receivable rights, an item whose number is a WooCommerce SKU, and a miscellaneous charge for shipping.
3. Add the connection with the address, company, user, password and the customer number. Press Test connection: expect the version, the company, the locations, the tax groups, and the `$metadata` property checks.
4. Place an order: expect one Order Entry order with PO Number `WC-{id}`. Run it again: no second order.
5. Refund it: expect an Order Entry credit note with Reference `WC-{refund id}`.
