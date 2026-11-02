---
id: holded
name: Holded
catalog_row: Holded
connection_method: key
available: true
verified: false
hosts: [api.holded.com]
terms_url: https://www.holded.com/tac            # fetched 2026-11-02: "Terms and Conditions | Holded"
privacy_url: https://www.holded.com/privacy      # fetched 2026-11-02: "Privacy Policy | Holded" (HOLDED TECHNOLOGIES, S.L.)
developer_url: https://help.holded.com/en/articles/6896051-how-to-build-and-use-the-holded-api
variants: []
---

# Holded connector

Connector id `holded`, type ERP, contract version 1. Code: `includes/Connectors/Holded/`. Spanish and European business suite. The connector sends WooCommerce orders as invoices or sales orders, refunds as credit notes, and customers as contacts, using Holded's invoicing API with the `key` header.

Honesty note. Holded's own reference pages (developers.holded.com) were not reachable from the build session. The request and response shapes below come from the list of operations of the Holded invoicing API as published in an API catalogue, from open-source client libraries, and from Holded's help and rate-limit pages. They are marked `[third-party]` or `[inferred]`. The connector checks every created document by reading it back, and nothing about stock or payments is flagged because the field names could not be confirmed. Read "Not verified" before relying on this connector.

Dependency on API v1. Every call uses the v1 interface and the `key` header. Holded's help article says v1 keys remain available "until further notice" and that the newer tokens (Bearer, `/api/v2/`) are a different interface [documented]. If Holded retires v1, this connector stops working and has to be moved to v2; nothing here is a promise that v1 will last. The Test connection result shows which interface it used (`API v1`).

Tags: `[documented]` official Holded page (URL, fetched 2026-11-02), `[third-party]`, `[inferred]`.

## 1. Sources

All fetched 2026-11-02.

- How to build and use the Holded API (help centre): https://help.holded.com/en/articles/6896051-how-to-build-and-use-the-holded-api [documented] (API not on the Free plan; Settings, Developers, Credentials; per-module token permissions; owner and admin roles reach API v1; v1 keys remain "until further notice"; base `https://api.holded.com/api/v2/` for the new tokens; 100 requests a minute per key)
- Rate limits: https://www.holded.com/es/desarrolladores/limite-de-tasa [documented] (per-minute and per-month limits by plan, `Retry-After`, `X-RateLimit-*`, 429 problem-details body)
- Getting started: https://www.holded.com/es/desarrolladores/primeros-pasos [documented] (base `https://api.holded.com`, Bearer example for v2)
- API reference introduction: https://developers.holded.com/reference/api-key [documented] (REST, JSON, list methods paginated with `?page=2`, key in the platform)
- Operations of the invoicing API (30 operations, base `https://api.holded.com/api`, header `key`, paths `/invoicing/v1/documents/{docType}`, `/invoicing/v1/contacts`, `/invoicing/v1/products`, `/invoicing/v1/warehouse`, `/invoicing/v1/payments`, `/invoicing/v1/treasury`): https://apis.apievangelist.com/store/holded-invoicing-api/ [third-party]
- Open-source PHP client and its README (document types, create-document body, pay call): https://github.com/vshopes/holded and https://packagist.org/packages/montanez50/holded [third-party]
- MCP server listing for the v2 API (document types, field notes such as `code` for the tax id): https://glama.ai/mcp/servers/c7dd9edni2 [third-party]
- Connection guides: https://developers.apideck.com/connectors/holded/docs/consumer+connection, https://www.chift.eu/blog/how-to-get-your-holded-api-key [third-party] (paid plan, per-module token permissions)
- Repo research: `docs/research/integration-research-vendors.md` section 4.13 (Holded: v2 at `/api/v2`, OpenAPI at `api.holded.com/openapi/es.json`, webhooks, VeriFactu)

## 2. How the merchant connects

1. Use a paid Holded plan; the API is not available on the Free plan.
2. As an owner or administrator open Settings, Developers, Credentials.
3. Open the link to the API keys v1 (the older key), create a key and copy it.
4. Paste the key here and press Test connection.
5. Under Advanced, choose whether orders become invoices or sales orders.
6. Under Tax mapping, type each tax rate as a percentage, for example `21`.

Plan gate: a paid plan [documented]; each plan has a monthly call quota (Plus 500, Basic 2,000, Standard 7,500, Advanced 30,000, Premium 100,000 and more) [documented]. A small plan can run out in the month: the connector then returns SYNC-RATE-001 with the wait Holded sends. Because of this quota the connector keeps an index of the contacts and of the products in a transient for an hour, reads them only as far as it needs, and stops list reads with SYNC-RATE-001 when fewer than 10 calls are left (see section 10 for what one order costs). The key is a v1 key: the new tokens (Bearer, `/api/v2`) are a different interface that this connector does not use (see Not verified).

## 3. Authentication

Header `key: <API key>` [third-party; the repo research records the same]. The key is the only secret (`api_key`, encrypted, redacted). Base address `https://api.holded.com/api/` [third-party]. HostPolicy allows `api.holded.com` only. Credential fields: `api_key` (secret). Advanced options: `document_type`, `warehouse_id`, `sales_channel_id`, `payment_method_id`, `unknown_sku`, `total_mismatch` (section 11).

## 4. Data sent and when (WordPress.org "External services")

Host: `api.holded.com`, only after the merchant connected a key.

| Action | Request | Data sent |
| --- | --- | --- |
| Test connection | `GET /api/invoicing/v1/warehouse?page=1`, `GET /api/invoicing/v1/contacts?page=1` | The key |
| Health probe | `GET /api/invoicing/v1/warehouse?page=1` | The key |
| Order synced | `GET /api/invoicing/v1/documents/{type}` (date window), `GET /api/invoicing/v1/products`, `GET /api/invoicing/v1/contacts`, `POST /api/invoicing/v1/contacts`, `POST /api/invoicing/v1/documents/{type}`, `GET /api/invoicing/v1/documents/{type}/{id}`, `DELETE` of a sales order that failed its check | Customer name, email, phone, address, tax number; order number and total inside the notes; date, currency; product name, SKU, quantity, unit price, tax |
| Refund synced | `GET .../documents/creditnote`, `GET .../documents/{type}` (to find the order), `GET .../documents/{type}/{id}`, `POST .../documents/creditnote`, `GET .../documents/creditnote/{id}` | Refund lines, amounts, the order reference, the reason |

No card data is sent.

## 5. Objects and field mapping

Dates in Holded are Unix timestamps in seconds [third-party]. The connector writes a document's date as noon UTC of the order's day, so it shows as the same day from Spain to the Americas. Money in requests is a JSON number built from integer minor units with the currency exponent.

Contact (`POST /api/invoicing/v1/contacts`) [third-party; field names `[inferred]` from the document-create body of the PHP client and the MCP listing]:

| Payload path | Holded field | Notes |
| --- | --- | --- |
| `customer.company`, else `customer.name` | `name` (required) | Kept as typed, Arabic and accents included |
| `customer.email` | `email` | The match key, compared in lower case |
| `customer.phone` | `phone` | |
| `customer.tax_number` | `code` | The tax id (NIF/VAT); the MCP listing notes that `vatnumber` does not exist and is ignored |
| `customer.address`, `.city`, `.state`, `.postcode`, `.country` | `billAddress`: `address`, `city`, `province`, `postalCode`, `countryCode` | Only fields with a value are sent; `countryCode` only when it is two letters |
| (fixed) | `type` | `client` |

Document (`POST /api/invoicing/v1/documents/{docType}`; `docType` is `invoice`, `salesorder` or `creditnote`) [third-party]:

| Payload path | Holded field | Notes |
| --- | --- | --- |
| (contact id) | `contactId` | Found or created by email |
| `date` | `date` | Noon UTC of the order date (today when the order has none) |
| `currency` | `currency` | Sent in lower case, as Holded's examples show; compared case-insensitively on read-back |
| marker + `number` + `note` | `notes` | Line 1 `[WC-{order id}] {total} {CURRENCY}`, then `Order number: N` when it differs from the id, then the order note; at most 2,000 characters, the marker is never cut |
| (option) | `warehouseId`, `salesChannelId`, `paymentMethodId` | Sent only when set and made of letters, digits, hyphen, underscore |
| `lines[]`, `shipping`, `fees[]` | `items[]` | `name`, `units`, `subtotal` (net unit price), `tax` (percent number), and `sku` for a line linked to a Holded product |

The mapped tax code is the percentage ("21", "5.5", "10", "4", "0"; a trailing `%` is accepted). A code that is not a number from 0 to 100 is SYNC-MAP-002 naming it. A line without a code is sent with `tax: 0` explicitly, so a Holded product default cannot add tax the shop did not charge. Holded's v1 operation list has no tax resource [third-party], so `listTaxCodes()` offers 21, 10, 4 and 0 percent (Spanish VAT) as suggestions without calling Holded.

Refund lines that carry only the tax amount have their rate found from the amounts: the first of a fixed list of usual European rates (21, 10, 4, 0, 20, 19, 7, 22, 23 ... and halves such as 5.5) whose tax on the line net is within one minor unit of the tax given. If none fits, SYNC-MAP-002 names the line.

## 6. Order flow

1. Parse and check the payload (email, name, lines, amounts, currency). A missing email is SYNC-MAP-003.
2. Search for the order's document: `GET .../documents/{type}?starttmp=&endtmp=` over all pages for the window from one day before the order date to the end of the third day after it [window parameters `[third-party]`]. A document whose notes or description contain `[WC-{id}]` is the order. If one is found, `send()` reads it back, checks currency and total against the payload (`total_mismatch` decides between an error and a warning) and returns it as already existing. It never makes a second document.
3. Products: found through the product index, an `SKU => product` list kept in a transient (an hour, one per connection) and built page by page: a read stops as soon as the SKU is found, remembers the page it reached, and the next read, in this request or a later one, goes on from there. The SKU is matched exactly, case-insensitively. A known SKU is sent as `sku` so Holded links the product. A SKU missing from a finished index is unknown for five minutes, then the list is read again from the start, so a product added in Holded is found soon. An unknown SKU is SYNC-MAP-001 naming it and nothing is created, or, with `unknown_sku = free_text`, a text line without `sku`. A line with no SKU is a text line.
4. Prices: `lines[].total` (after discounts, tax-exclusive) divided by the quantity, split in two lines when it does not divide (3 units for 10.00 are 2 at 3.33 and 1 at 3.34); a quantity with a fraction is one line of quantity 1 whose name says the quantity. Shipping and fees are text lines. When the payload says its totals include tax, each line is converted to net with whole-number arithmetic, then Holded adds the percentage.
5. Local check, before anything is sent: the lines are added up (net plus tax rounded per line) and compared with `order.total - order.excluded_total`. A difference of more than one minor unit per document line is SYNC-VAL-002 naming both figures; nothing is created, not even the contact. `total_mismatch = warn` goes on and records a warning.
6. Contact: found through the contact index, an `email => contact id` list kept in a transient (an hour, one per connection) and built the same way, page by page, matching the email case-insensitively and exactly. A finished index is trusted for a missing email for the hour it lives, because the connector adds every contact it creates and a new customer is the usual case; a contact made in Holded by hand during that hour may be made a second time. No match creates the contact. If the answer to a contact create is lost, the index is dropped so the retry reads the list again and finds the contact.
7. `POST .../documents/{docType}`. There is no draft step in v1, so the document is complete at once.
8. Read back `GET .../documents/{docType}/{id}`: the currency must be the order's and the total must equal the expected total within one minor unit per line. If not:
   - a sales order is deleted (`DELETE`) and the error says why; if the delete is refused the error says "Delete it in Holded";
   - an invoice is not deleted (a numbered invoice is corrected with a credit note under Spanish rules): the error names the invoice number and both totals, and the item stops for a person. The next pass finds this invoice by its marker, fails the same check and reports it again; it never makes a second invoice.
9. Result: id = Holded document id, ref = `docNumber` when present, else `WC-{id}`, `extra` = `total_minor`, `currency`, `document` (the type), `state` = `issued`, and `warnings`.

Holded handles VeriFactu, Facturae and FACe itself; the connector neither drives nor reads them.

## 7. Refund flow

1. Parse and check the refund (order reference, amounts). A missing order reference is SYNC-VAL-002 naming `order_ref`.
2. Search `documents/creditnote` in the window around the refund date for `[WC-R-{refund id}]`. If found, check it as in order step 2 and return it as already existing.
3. Find the order's document (of the type chosen for orders) by `[WC-{order id}]`: in the window around the order date when the refund carries it (`order_date`, from the order's creation date in the default rules), otherwise in the 90 days before the refund. If it is not there, SYNC-VAL-002 "The order of this refund is not in Holded yet" and nothing is written. The credit note is made for the contact and in the currency of that document. With `salesorder` as the order type the credit note is still made and carries a warning that it has no invoice to correct.
4. Rows: refunded product lines (net amount, quantity, SKU linked when Holded has the product, else a text line; the name of the Holded product when the refund gives none), shipping and other refunded amounts as text lines without tax, or one row for a refund without lines. The tax rate of a product line is found from its tax amount (section 5).
5. Local check against `refund.amount - refund.excluded_amount` as in order step 5, then `POST .../documents/creditnote` with `notes` = `[WC-R-{refund id}] {total} {CURRENCY}`, then `Refund of WC-{order id}: {reason}`. Holded v1 documents no field that links a credit note to its invoice, so the link is the note.
6. Read back and check the total. A credit note that fails is not deleted (it is numbered): the error names it, and the next pass reports the same credit note instead of making another.

## 8. Duplicate protection

- Field: the first line of `notes`, `[WC-{order id}] {total} {CURRENCY}` (credit notes `[WC-R-{refund id}]`). Holded v1 has no external-id field and no search by reference [third-party; the repo research found only `find-by-number` in v2], so the search lists a window of documents and matches the marker locally. The marker is matched with its closing bracket, so `[WC-104901]` is not `[WC-10490]`. The description is also searched in case a person moved the text.
- If the list rows carry no `notes` or `desc` key at all, each document of the window is read on its own, up to 50; with more the order stops with SYNC-VAL-002 rather than risk a second document.
- No idempotency key exists.
- `send()` finds the document by the window around the order date (the date the connector wrote into the document). `findExisting()` has no payload and so no date: it follows the id the engine already linked (`GET .../documents/{type}/{id}`), and otherwise searches documents dated in the last 30 days. An order older than that and not linked is not found by `findExisting()`, but `send()` still finds it by its own date.
- Finished or not: the amount and currency in the marker line are what the document was meant to show. `findExisting()` returns a document only if its total still equals that amount within one minor unit per line. A document that failed its check (an invoice or credit note left in Holded, or a sales order whose delete was refused) is therefore not taken for a finished record. A marker line without an amount (edited by a person) is returned as it is.
- A document made by another tool cannot be matched to an order, because the marker is the connector's own.

## 9. Stock, tracking, payments, invoice read-back, reconciliation

- Stock: not flagged. The research records stock levels in the v2 API [documented in the research]; the field names of the v1 product answer could not be read, so no stock read is shipped. A stock figure needs a documented schema before it is trusted.
- Tracking: v2 has a tracking update for sales orders [documented in the research]; no read is documented. Not flagged.
- Payments: not sent. The pay call (`POST .../documents/{docType}/{id}/pay` with a date and a treasury account) exists in client libraries [third-party] and the operation list shows `POST /invoicing/v1/payments`, but the document field that shows what has been paid could not be confirmed, so the connector cannot tell whether a payment already happened and would risk a second one on a retry.
- Invoice read-back, reconciliation, webhooks (v2 only), prices in: not flagged.
- Shadow lookup: not flagged. The match depends on the connector's own marker, so it cannot see a document made by the merchant's earlier process, and the shadow comparison would report every order as missing.

## 10. Rate limits and pacing

Per account, not per key: per-minute 60 (Plus, Basic), 120 (Standard, Advanced), 600 (Premium); per-month 500, 2,000, 7,500, 30,000, 100,000+ [documented]. Two counters, whichever fills first answers 429 with a problem-details body and the headers `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`, `X-RateLimit-Window` (`minute` or `month`) [documented]. The help article says 100 a minute; the rate-limit page says 60 to 600; the connector uses the lowest figure: one request a second (60 a minute), one at a time. On 429 it returns SYNC-RATE-001 with `Retry-After`; when the window is `month` the wait can be long and the queue caps it at 24 hours. Test connection reads `X-RateLimit-Remaining` and `X-RateLimit-Window` from its warehouse answer and shows them.

What an order costs: a list read is one request per page, and the end of a list is an empty page (the page size is not documented, so the connector does not guess where a list ends); an index that stops because it found its key does not read the empty page. The first invoice order on a cold store, with one product on the first page and no contacts or documents yet, makes 6 calls: the document window, the product, the contacts, creating the contact, creating the invoice, reading it back. The next order, even in a later request within the hour, makes 4: the indexes come from the store, and only the document window (a page and its empty page), the create and the read-back remain. A refund on a cold store makes 7: the credit note window, the order window and its empty page, reading the order, the product, creating the credit note, reading it back. A small plan (500 calls a month) therefore still covers only about a hundred orders: choose a plan to match, and watch the remaining calls on Test connection.

Quota guard. Every answer carries `X-RateLimit-Remaining` and `X-RateLimit-Window` and sometimes `X-RateLimit-Reset` [documented]. The connector remembers the last reading (in memory and in a transient, for a minute when the window is `minute` and an hour when it is `month`), and a list read (an index page, a document window) that would start with fewer than 10 calls left stops first with SYNC-RATE-001, without making the request. The wait is the reset time when Holded sends one (read as seconds from now, or as a Unix time, because the documentation does not say which), otherwise 60 seconds for a minute window and 6 hours for a month window. Creates and read-backs are not held back; Holded answers 429 to those itself. An index that was stopped keeps the pages it read and goes on from there.

## 11. Options (advanced fields)

| Key | Values | Default |
| --- | --- | --- |
| `document_type` | `invoice`, `salesorder` | `invoice` |
| `warehouse_id` | warehouse id (letters, digits, `-`, `_`) | empty |
| `sales_channel_id` | sales channel id | empty |
| `payment_method_id` | payment method id | empty |
| `unknown_sku` | `refuse`, `free_text` | `refuse` |
| `total_mismatch` | `fail`, `warn` | `fail` |

A value outside its list falls back to the default. Test connection checks that `warehouse_id`, when set, is one of the warehouses Holded lists.

## 12. Error mapping

| Holded answer | SYNC code | Class |
| --- | --- | --- |
| Time-out, reset, DNS | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| 5xx | SYNC-NET-002 | retry |
| 429 | SYNC-RATE-001 with `Retry-After` | retry after the wait |
| 401 | SYNC-AUTH-001 | needs a person |
| 402 | SYNC-AUTH-006 | needs a person |
| 403 whose text contains both "api" and one of "plan", "upgrade", "subscription" | SYNC-AUTH-006 | needs a person |
| Any other 403 | SYNC-AUTH-004 | needs a person |
| 400/422 with `info`, `message`, `error` or `errors` | SYNC-VAL-002 with a short redacted reason (300 characters at most) | needs a person |
| 404 on an id | SYNC-VAL-002 | needs a person |
| A 200 whose body is an object with `status: 0` | SYNC-VAL-002 with the `info` text (also inside list reads) | needs a person |
| Unknown SKU / tax not a percentage / missing email | SYNC-MAP-001 / 002 / 003 | needs a person |
| Total or currency mismatch | SYNC-VAL-002 | needs a person |

Holded v1 answers success as `{"status":1,"info":"Created","id":"..."}` and failure inside a 200 or a 4xx as `{"status":0,"info":"..."}` `[inferred]`; the connector reads both.

## 13. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders`, `customers`, `refunds` | yes | documents, contacts, credit notes |
| `shadow_lookup` | no | see section 9 |
| `stock_in`, `tracking_in`, `prices_in`, `invoice_readback`, `reconciliation`, `webhooks` | no | see section 9 |

Entities: order, refund, customer. Regions: ES and the EU. Rate limit hints: 1 a second, 1 at a time, 60 a minute. Optional reads offered to the mapping screens: customer by email, product by SKU, product search, tax code suggestions.

## 14. Not verified

- Every request and response field name of the v1 interface (documents, contacts, products) is `[third-party]` or `[inferred]`; the official reference pages were not reachable. The pre-send total check and the read-back after creation are the guards, and the fake server used in the tests follows the same assumptions, so passing tests do not prove the live API agrees.
- Whether the `key` header is accepted for keys made after Holded's v2 launch; the help article says v1 keys remain available "until further notice" and are reached from a link in the token screen. If Holded retires v1 the connector must move to `/api/v2` with a Bearer token.
- The date-window parameters `starttmp` and `endtmp`, and whether the list returns `notes` (the connector reads each document when no row has a notes field, but only up to 50).
- The page size of lists and where `page` starts (the connector assumes 1 and ends a list at an empty page or a page whose first record is the one before it).
- The format of `X-RateLimit-Reset` (seconds or a Unix time); the connector accepts both.
- Whether Holded accepts the currency in lower case, a `billAddress` object on contacts, `sku` on an item as the link to a product, and `tax` as a plain percentage number.
- How Holded rounds tax (per line or on the whole); the connector allows one minor unit per document line.
- Whether a numbered invoice or credit note created through the API can be deleted (the connector does not try); whether a sales order can (it does, and reports a refusal).
- Contact search: the connector scans the contact list because no email filter is documented; large address books cost many calls against the monthly quota.
- Product and stock answer fields; the pay call; the link between a credit note and its invoice.
- Terms and privacy URLs were not fetched.

## 15. Manual smoke test (a trial or paid account)

1. In a Holded trial create a product with SKU `TEST-1`.
2. Create a v1 key (Settings, Developers, Credentials, link to the v1 keys). Add the connection; Test connection lists the warehouses and shows the remaining call quota. A wrong key is SYNC-AUTH-001.
3. Type tax `21` in Tax mapping.
4. Place a WooCommerce order for `TEST-1` with `document_type = salesorder` first: one sales order whose notes start with `[WC-{order id}] {total} {currency}`, the total equal to the shop; sync again and see no second document.
5. Repeat with `document_type = invoice`; check the invoice number and total.
6. Place an order with an Arabic or accented name: the contact keeps the text.
7. Refund part of the order: a credit note with `[WC-R-{refund id}]` in its notes, for the same contact.
8. If a step fails with a validation message, copy the field named in the message: the document body is the least certain part. If step 4 or 5 creates a second document on a second sync, the list does not return notes or the date window is not honoured: report it before using the connector.
