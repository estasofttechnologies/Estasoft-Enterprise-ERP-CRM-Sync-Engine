---
id: sap-b1
name: SAP Business One
catalog_row: SAP Business One
connection_method: server_login
available: true
verified: false
hosts: []
terms_url: https://www.sap.com/about/legal/terms-of-use.html
privacy_url: https://www.sap.com/about/legal/privacy.html
developer_url: https://learning.sap.com/courses/leveraging-the-sap-business-one-service-layer/logging-in-and-logging-out-using-service-layer-api
variants: []
---

# SAP Business One connector

Connector id `sap-b1`, type ERP, contract version 1. Code: `includes/Connectors/SapBusinessOne/` (shared ERP helpers in `includes/Connectors/EnterpriseCommon/`). The connector talks to the Service Layer that the merchant's own SAP Business One installation runs (host is the merchant's, so `hosts` in the front matter is empty and private addresses are refused unless the filter `estasoft_sync_allow_private_hosts` allows them, the rule the Odoo connector follows). It sends WooCommerce orders as sales orders (optionally copied to an A/R invoice), refunds as A/R credit notes and customers as business partners, and reads stock, invoice status and a nightly check list back.

## 1. Sources

Fetched with WebFetch on 2026-11-02. `[documented]` is an SAP page, `[third-party]` is a partner or community page, `[inferred]` is reasoned and not stated. SAP's help portal (`help.sap.com`, including the PDF "Working with SAP Business One Service Layer" and the DI API help) answers `robots_disallowed` to the fetch tool, so the official API reference could not be read; every entity and property name below that is not on a page in this table is [inferred] from the public metadata the Service Layer exposes and from the research file.

| Page | Used for |
| --- | --- |
| https://learning.sap.com/courses/leveraging-the-sap-business-one-service-layer/logging-in-and-logging-out-using-service-layer-api | [documented] `POST https://<server>:<port>/b1s/v2/Login` with `{"CompanyDB","UserName","Password"}`; `CompanyDB` is case-sensitive and comes from the SLD; success is HTTP 200 with a `SessionId`; logout `GET /b1s/v2/Logout` answers 204; an invalid or expired session answers HTTP 401 "Invalid session or session already timeout."; a session of one version does not authorise another (v1 against a v2 session is 401); default certificate is self-signed, TLS 1.2 or 1.3 |
| https://www.apideck.com/blog/sap-business-one-api-integration-guide.md | [third-party] `/b1s/v2/` is OData v4 and `/b1s/v1/` is OData v3, deprecated since Feature Pack 2405; default session timeout 30 minutes of inactivity; error body `{"error":{"code","message"}}`; default page size 20, `@odata.nextLink`, `$top`/`$skip`, `Prefer: odata.maxpagesize=N`; filter operators `eq ne gt lt ge le and or startswith() contains()`; `U_` user-defined fields can be read, written and filtered; `/$batch`; invoice body `{"CardCode","DocDate","DocumentLines":[{"ItemCode","Quantity","UnitPrice"}]}` |
| https://central.ballerina.io/ballerinax/sap.businessone/1.0.2 | [third-party] the client keeps the `B1SESSION` and `ROUTEID` cookies, logs in again once on 401 and replays the request; default session timeout 30 minutes; endpoint form `https://<host>:50000/b1s/v1` |
| https://answers.sap.com/questions/545695/problem-connecting-to-b1-service-layer.html | [third-party] port 50000 is https; a login with the credentials in the URL instead of the body answered error code 301 "Invalid session." |
| `docs/research/integration-research-vendors.md` 4.6 | Indirect Access licence plus a dedicated user (2018 source, may be outdated); duplicate card code returns -10; large documents (500+ lines) can return 502 unless the proxy timeout is raised; webhooks only from FP 2602; SAP's April 2026 API policy limits integrations to published APIs |

## 2. How the merchant connects

The Connect screen shows these steps (V0 reads the list into the catalog field `steps`). No redirect address is needed: this is a server sign-in.

1. Ask your SAP administrator for the Service Layer address, for example `https://sap.example.com:50000`, reachable from this website's server.
2. Create a licensed user for the shop with rights to sales orders, invoices, credit notes, business partners and items.
3. Optional: add a user field to sales orders for the shop order number and enter its name below; the Customer Ref. No. is used otherwise.
4. Enter the address, the company database name (case-sensitive, from the SLD), the user and the password here.
5. Use a certificate that WordPress trusts; the plugin does not connect to a server whose certificate fails verification.
6. Press Test connection.

Plan gate: [third-party] an Indirect Access instance licence and a licensed user; the merchant's administrator knows whether the installation has it. A refusal because of licensing is returned as a normal SAP error (section 12); no vendor error code for it was found, so SYNC-AUTH-006 is not used.

## 3. Authentication

Credential fields:

| Key | Where | Secret | Notes |
| --- | --- | --- | --- |
| `address` | config | no | `https://host[:port]`. A pasted path such as `/b1s/v2` or `/b1s/v1/Login` is cut off. Credentials in the address, other schemes and private-network hosts are refused (SYNC-VAL-002). |
| `api_version` | config | no | `v2` (default, OData v4) or `v1` (OData v3, for versions without v2). |
| `database` | config | no | The `CompanyDB`. Letters, digits, `_`, `-`, `.`, `$`, up to 100 characters. |
| `user` | config | no | The `UserName` (the SAP user code). |
| `password` | secrets | yes | Write-only. |

Session: `POST {address}/b1s/{v}/Login` returns JSON with `SessionId`. Every later request carries the cookie `B1SESSION=<SessionId>` (and `ROUTEID` when the login answer set it, which a load balancer needs). The session id is cached per connection in the base's session store (a transient) for the `SessionTimeout` the login answer states (30 minutes when it states none), never logged, and a cache flush only costs one more login. On HTTP 401 or a body error code 301 the connector logs in again once and replays the request. Logout is not called: it would end a session another worker is using; the session ends by its own timeout. A login answered with an error is SYNC-AUTH-001 (or SYNC-CFG-003 when the text says the company database is unknown, section 12). The connection is paused by the engine after a refused sign-in.

Each login uses a licence seat of the SAP user until the session times out [third-party]; this is why one session is reused.

## 4. Data sent and when (for the WordPress.org "External services" disclosure)

Only the Service Layer address the merchant typed is contacted. Nothing goes to Estasoft.

| Action | Request | Data |
| --- | --- | --- |
| Test connection | `POST /Login`; `GET` on `Orders`, `Invoices`, `CreditNotes`, `BusinessPartners`, `Items` with `$top=1`; `GET Warehouses` | Company database, user and password (once per session). A user that cannot read one of the five objects gets SYNC-AUTH-004 naming the missing ones in the log detail |
| Send an order | `GET BusinessPartners` (by email), `POST BusinessPartners`, `GET Items`, `GET Orders` (by reference), `POST Orders`, optional `POST Invoices` | Buyer name, email, phone, billing and delivery address, tax number, order lines, prices, order number and date, note |
| Send a refund | `GET Orders`, `GET CreditNotes`, `POST CreditNotes` | Refunded lines and amounts, reason |
| Read back | `GET Items`, `GET Invoices`, `GET Orders`, `GET CreditNotes`, `GET BusinessPartners` | Item codes, order references, totals, buyer email |

## 5. Objects and field mapping

| Canonical (source) | Payload path | Service Layer field |
| --- | --- | --- |
| `order.client_ref` (from the queue item) | `client_ref` | `NumAtCard` (100 characters) and, when `reference_field` names a user field, that field |
| `order.created_at` | `order_date` | `DocDate`, `TaxDate`; `DocDueDate` = date + `due_days` |
| `order.currency` | `currency` | `DocCurrency` |
| `order.note`, payment reference | `note`, `payment.reference` | `Comments`: note, then "Payment reference: ...", cut with an ellipsis and a warning to 215 characters, then the last line `Shop total 59.97 AED` (section 6); 254 in all |
| `order.total`, `order.excluded_total` | `total` | Not sent as a field; written as the last line of `Comments` and compared with the document total |
| buyer: name, email, phone, company, VAT number, address | `partner.*` (`name`, `email`, `phone`, `company_name`, `vat`, `street`, `street2`, `city`, `state`, `zip`, `country`) | business partner `CardName`, `EmailAddress`, `Phone1`, `FederalTaxID`, `BPAddresses[]` (`bo_BillTo`) |
| `shipping.*` | `delivery.*` | `BPAddresses[]` (`bo_ShipTo`), document `Address2` (254, cut with a warning) |
| `lines[].sku` | `order_line[].product` | `DocumentLines[].ItemCode` (item found by `ItemCode`) |
| `lines[].quantity` | `order_line[].quantity` | `DocumentLines[].Quantity` |
| `lines[].unit_price` | `order_line[].price_unit` | `DocumentLines[].UnitPrice` (before tax), or `PriceAfterVAT` when the payload sets `prices_include_tax` |
| `lines[].tax_rate` through tax mapping | `order_line[].tax` | `DocumentLines[].VatGroup` (or `TaxCode` by `tax_field`; tax code of the SAP company) |
| `order.shipping_total`, `fees[]`, `order.discount_total` | `shipping.total`, `fee[]`, `discount.total` | lines with the items named by `shipping_item`, `fee_item`, `discount_item` (the discount line has a negative price) |

Refund payload: `order_ref` (`WC-{order id}`), `date`, `reason`, `amount`, `refund_line[].{product, quantity, amount}`. The paths are the ones the shared ERP parser reads (`includes/Connectors/EnterpriseCommon/OrderParser.php`, `RefundParser.php`).

A line uses `WarehouseCode` from the option `warehouse` when it is set, else SAP uses the item's default warehouse. Arabic and other scripts are sent as UTF-8 unchanged. Money is an integer in minor units inside the plugin; decimals are written with the currency's exponent (KWD three decimals, JPY none) by string arithmetic.

Options (`config.options`): `documents` (`order` default, `order_invoice`), `customer_policy` (`match_or_create` default, `match_only`), `total_mismatch` (`fail` default, `warn`), `reference_field` (`NumAtCard` default, or a user field such as `U_WCRef`), `warehouse`, `due_days` (0), `shipping_item`, `fee_item`, `discount_item`, `refund_item` (item code for a refund with no products), `bp_series` (number series of new business partners; 0 or empty makes the connector send a card code), `tax_field` (`vatgroup` default, `taxcode`), `api_version` (also a connection setting). An unknown SKU always stops the order with SYNC-MAP-001.

## 6. Duplicate protection

`NumAtCard = WC-{order id}` is written to every sales order, invoice and credit note (`WC-{refund id}` on a credit note). Before every create the connector searches `GET Orders?$filter=NumAtCard eq 'WC-123' and Cancelled eq 'tNO'` (same for `Invoices`, `CreditNotes`; a cancelled document does not count, so a retry after a cancelled one makes a new one). When `reference_field` names a user field, orders are searched and written through that field (the `U_` name must match `^U_[A-Za-z0-9_]{1,19}$`); invoices and credit notes always use `NumAtCard`, because user fields belong to one document type.

- Business partners: searched by email, case-insensitive; a new one gets a deterministic card code `WC` + the first 13 hex digits of the SHA-1 of the lower-cased email (15 characters) unless `bp_series` is set. A create that answers "already exists" (error -10) makes the connector look the card code up again instead of failing [third-party for -10].
- No vendor idempotency key exists.
- `findExisting()` reports an order as found only when its steps are done for the chosen `documents`: the order exists and, with `order_invoice`, so does the invoice. A credit note is found when it exists.
- The engine links whatever `findExisting()` returns without calling `send()`, and `findExisting()` has no payload. So the shop's total is the last line of `Comments` ("Shop total 59.97 AED") and `findExisting()` compares it with the document total (`DocTotalFc`, else `DocTotal`) and its currency: a difference with `total_mismatch = fail` is returned as SYNC-VAL-002 instead of a reference. Editing `Comments` by hand removes the marker and switches that comparison off for that order.

## 7. Taxes, currency, prices and the total check

- Tax: each line carries `VatGroup` (or `TaxCode` by `tax_field`) chosen through the tax mapping (country and rate to a SAP tax code). A line without a mapped code gets none and SAP uses the item's or business partner's default; the total check catches a difference. `listTaxCodes` reads `VatGroups` (output tax only).
- Prices: WooCommerce prices before tax go to `UnitPrice`. A payload with `prices_include_tax` sends `PriceAfterVAT` instead. [inferred property name]
- Currency: `DocCurrency` is the order's currency; new business partners get currency `##` (all currencies) so a foreign order is accepted. After creation the document's `DocCurrency` is read back and a different one fails the order. The exchange rate is SAP's own table; a missing rate makes SAP refuse the document and the message is SYNC-VAL-002.
- Total check: SAP answers a create with the new document. `DocTotalFc` (foreign currency total) is used when it is not zero, else `DocTotal`. It is compared with `total - excluded_total` in minor units (one unit tolerated). More: with `total_mismatch = fail` the item fails with SYNC-VAL-002 naming both totals and the order stays in SAP for a person (the connector never cancels or deletes a document; a posted document often cannot be deleted); `findExisting()` keeps reporting the error (section 6) so no second order is made and the wrong one is not linked. `warn` goes on with a warning in the result. A credit note's total is not compared.
- Dates: `DocDate` is the order date (UTC date); SAP keeps the date it is given.

## 8. Flow

Every pass starts from a read and nothing that creates a document is replayed blindly:

1. Parse the mapped payload (email, name, lines, amounts, currency).
2. `GET Orders` by reference. If found and complete, return it (SYNC-DUP-001).
3. Resolve every product (`GET Items`, `ItemCode`, not frozen), taxes and the shipping, fee and discount items. Unknown SKU is SYNC-MAP-001 before any document or partner is created (the items are checked before the business partner). An order with shipping, fees or a discount and no item set for it fails with SYNC-VAL-002 naming the option.
4. Find or create the business partner per `customer_policy`.
5. `POST Orders`. A lost answer is handled by the read in step 2 on the next pass.
6. Total and currency check (section 7).
7. With `order_invoice`: `GET Invoices` by reference; if none, `POST Invoices` whose lines copy the order (`BaseType` 17, `BaseEntry`, `BaseLine`); check the total again.

The result's id is the order's `DocEntry`, its reference the `DocNum`.

Refund: `GET Orders` for `WC-{order}` (must exist, else SYNC-VAL-002 before any request), `GET CreditNotes` for `WC-{refund}`; if none `POST CreditNotes` with the order's `CardCode` and `DocCurrency`, `NumAtCard = WC-{refund id}`, `Comments = "Refund {WC-refund} of order {WC-order}: reason"` and one line per refund line (`ItemCode`, `Quantity`, `LineTotal`, `VatGroup`); a refund with no products, or a line with none, needs the option `refund_item` and fails with SYNC-VAL-002 without it. The credit note is not linked to the invoice through base document fields; the link is the text in `Comments`, which the nightly check reads. Payments (incoming payments) are not built: they need a bank or cash account mapping and the fields were not verified.

## 9. Reading from SAP

- `fetchStock`: `GET Items?$filter=ItemCode eq ... or ...&$select=ItemCode,ItemWarehouseInfoCollection` in chunks of 20; on hand is the sum of `InStock`, held the sum of `Committed`, over the chosen warehouses (all when none chosen) [inferred property names]. Not tracked items are left out.
- `fetchInvoice`: the order's `NumAtCard`, then `Invoices` by it: `DocNum`, `DocumentStatus`, `PaidToDate`, totals, `DocDate`. No tax-authority status.
- `listForReconcile`: `Orders` with `startswith(NumAtCard,'WC-')`, `DocDate` from the day on, not cancelled, paged; email from `BusinessPartners` in chunks; refunded amount from `CreditNotes` whose `Comments` name the order.
- `lookupCustomer`, `lookupProduct`, `searchProducts`: read-only helpers.
- Tracking numbers: not built (section 11).

## 10. Rate limits and pacing

No limit is documented. The connector makes one request at a time, about two a second (`rateLimitHints()` returns 2.0 and 1) and sends `Prefer: odata.maxpagesize=200` on lists; it follows `@odata.nextLink` (`odata.nextLink` on v1) up to 50 pages. 30 second timeout. A document of 500 or more lines can answer 502 when a reverse proxy times out [documented in the research file]; that is SYNC-NET-002 and the merchant raises the proxy timeout. A 429 is SYNC-RATE-001 with the `Retry-After` value (the base's default of 30 seconds when there is none); a 503 is SYNC-NET-002.

## 11. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| orders | true | `Orders`, `Invoices` |
| customers | true | `BusinessPartners` |
| refunds | true | `CreditNotes` |
| stock_in | true | `ItemWarehouseInfoCollection` (property names [inferred], see section 13) |
| tracking_in | false | `TrackingNumber` on delivery notes has only a third-party source and the link from a delivery to the shop order depends on how the merchant copies documents |
| prices_in | false | Not built |
| invoice_readback | true | `Invoices` |
| reconciliation | true | `Orders`, `CreditNotes` |
| webhooks | false | Only from FP 2602 and not built |
| shadow_lookup | true | `lookupCustomer`, `lookupProduct` and `searchProducts` are reads |

Entities: order, refund, customer.

## 12. Error mapping

| Service Layer | SYNC code |
| --- | --- |
| Login refused (401 or 400 with `error.code`), HTTP 401 after one new login | SYNC-AUTH-001 |
| Login text says the company/database is unknown or not found | SYNC-CFG-003 |
| HTTP 403, or `error.message.value` containing "not authorized", "no permission" or "authorization" | SYNC-AUTH-004 |
| HTTP 429 | SYNC-RATE-001 (the base: `Retry-After`, else 30 seconds) |
| HTTP 400 and other 4xx with `error.message.value` | SYNC-VAL-002 (text redacted, 200 characters) |
| Error -10 "already exists" on a business partner | looked up again; on another document VAL-002 |
| Text naming a locked object | SYNC-NET-002 (retried later) |
| HTTP 404 on a lookup | none found (not an error) |
| HTTP 5xx including 502 and 504 | SYNC-NET-002 |
| Time-out, reset, name not resolved | SYNC-NET-001 |
| Certificate that fails verification | SYNC-NET-003 |
| Unknown SKU / missing email / total or currency differs / item not set | SYNC-MAP-001 / SYNC-MAP-003 / SYNC-VAL-002 |
| Unexpected PHP error inside the connector | SYNC-VAL-002 "The connector stopped unexpectedly" (the base bridge) |

The password, the session id, the database name and the host are removed from every message; the database name is kept in the SYNC-CFG-003 text because the person typed it.

## 13. Not verified

- No live SAP Business One was available. Behaviour above the wire is tested against `FakeSapB1Server` and wire fixtures built from the pages in section 1.
- The official API reference could not be read (`robots_disallowed`). Entity and property names not on a page above are [inferred]: `Cancelled`, `DocumentStatus`, `DocTotalFc`, `PriceAfterVAT`, `VatGroup`/`TaxCode` on lines, `BPAddresses`, `Address2`, `Currency` = `##`, `VatGroups` and its rate property, `ItemWarehouseInfoCollection` (`InStock`, `Committed`, `WarehouseCode`), `PaidToDate`, the action `Orders(id)/Cancel`, and `LineTotal` on a line with a quantity.
- The `B1S-CaseInsensitive: true` header (sent on lookups by email and item code) is [third-party]; the connector also compares lower-cased values itself.
- Whether a negative price is allowed on a discount line in the merchant's company setup.
- Error codes: only 301 (invalid session) and -10 (duplicate card code) have a source, and both are third-party.
- v1 (OData v3) differences: `odata.nextLink`, date literals in quotes. They follow general OData 3 rules, not a page read.
- The Service Layer on older versions without `/b1s/v2`: not tested.
- Self-signed certificates are refused (HTTP client verifies certificates); the optional certificate authority file of the connection-methods spec is not supported by the engine's HTTP client.
- `terms_url` and `privacy_url` could not be fetched with the available tool (hosts not permitted); they are the standard SAP pages and need a check by a person.
- Whether the Indirect Access licence is still required in 2026 (source from 2018).

## 14. Manual smoke test against a real installation

No credentials are stored in the repository. Use a test company (SAP's demo database `SBODemoXX` or a copy).

1. Create the licensed user and, optionally, a user field on sales orders (Tools, Customization Tools, User-Defined Fields, Marketing Documents, Sales Order Header).
2. Add a SAP Business One connection: address, `database` = the company database, user, password. Press Test connection. Expected: the Service Layer version, the warehouses and a list of readable objects; a wrong password gives SYNC-AUTH-001, a wrong database SYNC-CFG-003.
3. Create items in SAP whose `ItemCode` equals a WooCommerce SKU. Place an order. Expected: one sales order whose Customer Ref. No. is `WC-{id}` and a new business partner; run the sync again and confirm that no second order appears.
4. Set `documents = order_invoice` and check the A/R invoice copies the order; compare totals including tax with WooCommerce.
5. Refund the order; expected: one A/R credit note with `NumAtCard = WC-{refund id}`.
6. Change stock in SAP and run the stock read.
7. Let the session expire (30 minutes idle) and send another order; the connector logs in again.
