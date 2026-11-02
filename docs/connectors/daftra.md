---
id: daftra
name: Daftra
catalog_row: Daftra
connection_method: key
available: true
verified: false
hosts: ["*.daftara.com", "*.daftra.com"]
terms_url: https://www.daftra.com/en/terms-conditions/    # fetched 2026-11-02: "Daftra's Terms and Conditions"
privacy_url: https://www.daftra.com/en/privacy-policy/    # linked from the footer of the terms page; the page itself could not be fetched
developer_url: https://docs.daftara.dev/933385m0
variants: []
---

# Daftra connector

Connector id `daftra`, type accounting, contract version 1. Code: `includes/Connectors/Daftra/`. Egyptian and Gulf cloud accounting and ERP system. The connector sends WooCommerce orders as invoices (optionally issued and paid), refunds as credit notes, and customers as clients, and it reads the product stock balance back.

Daftra publishes its API reference at docs.daftara.dev. The pages describe the request bodies well and the responses only by example. Tags: `[documented]` official page (URL, fetched 2026-11-02), `[inferred]` reasoned from documented behaviour. Nobody has run this against a live account.

## 1. Sources

All fetched 2026-11-02 from https://docs.daftara.dev (page ids in brackets).

- How to authorize [933385m0]: API key or OAuth2 token; API key roles. [documented]
- Generate access token [40311723e0]: `POST /v2/oauth/token` (password grant). Not used. [documented]
- Filtering [1258824m0]: operators `eq`, `not_equal`, `gt`, `gte`, `lt`, `lte`, `from`, `to`, `like`, `in`, `not_in`, `is_null`. [documented]
- Validation errors [1264814m0]: a sample message about missing client email; format shown as an image only. [documented]
- Site info [40960577e0]: `GET /api2/site_info.json`. [documented]
- Invoices: add [15115242e0], list [15115241e0], single [15115238e0], edit [15115239e0], issue or return to draft [38758418e0]. [documented]
- Clients: add [15115262e0], list [15115261e0], single [15115258e0]. [documented]
- Products: add [15115317e0], list [15115316e0], single [15115313e0]. [documented]
- Invoice payments add [15115307e0]; credit notes add [15115252e0], list [15115251e0], single [15115248e0]; taxes list [15115346e0]; stock transactions list [15115361e0]; stores list [15115366e0]. [documented]
- Repo research: `docs/research/integration-research-vendors-2.md` section 6.3.

## 2. How the merchant connects

1. Sign in to Daftra as the account owner or a user with access to Settings.
2. Open Settings, API and copy the API key. Check that the key may reach clients, products, invoices and credit notes.
3. Enter your Daftra address name (the part before `.daftara.com` or `.daftra.com`) and choose the domain.
4. Paste the key here and press Test connection.
5. Under Advanced, choose the warehouse and the payment method name your account uses.
6. Map your tax codes (Daftra tax ids, listed on this screen) under Tax mapping.

Plan gate: not documented. API key roles can limit a key to some entities ("Only Allow" or "Only Block"), and a refused entity answers 403 [documented]. The address is `https://<name>.daftara.com/api2/...` in every example [documented]; example responses also show `.daftra.com` hosts, so the domain is a choice (default `daftara.com`).

## 3. Authentication

Header `apikey: <key>` [documented in the curl examples; the authorization page says to leave the `Authorization` header off when using the key because an empty Bearer header causes an authentication error]. The key is the only secret (`api_key`, encrypted, redacted). Credential fields: `subdomain` (text, letters, digits and hyphens, checked before any request), `domain` (select), `api_key` (secret). The OAuth2 password grant is not used because it needs the owner's password.

Base address: `https://{subdomain}.{domain}/api2/`; resources use the `.json` suffix (`/api2/invoices.json`) [documented examples]. HostPolicy allows `*.daftara.com` and `*.daftra.com`.

Advanced options: `store_id`, `invoice_state`, `record_payment`, `payment_method`, `unknown_sku`, `link_credit_notes`, `total_mismatch` (section 12).

## 4. Data sent and when (WordPress.org "External services")

Host: `<name>.daftara.com` or `<name>.daftra.com`, the address the merchant typed, only after connecting.

| Action | Request | Data sent |
| --- | --- | --- |
| Test connection | `GET /api2/site_info.json`, `GET /api2/taxes.json`, `GET /api2/stores.json` | The key |
| Order synced | `GET /api2/invoices.json?po_number=`, `GET /api2/taxes.json`, `GET /api2/clients.json?filter[email]=` and, when that finds nothing, `?keywords=`, `POST /api2/clients.json`, `GET /api2/products.json?product_code=`, `POST /api2/invoices.json`, `GET /api2/invoices/{id}.json`, `GET /api2/invoices/update_draft/{id}/0.json`, `DELETE /api2/invoices/{id}.json` (a draft that failed its check), `POST /api2/invoice_payments.json` (if switched on) | Customer name, email, phone, address, tax number; order number as purchase-order reference; date, currency; product id, name, quantity, unit price, tax; payment amount, method, date and transaction id |
| Refund synced | `GET /api2/invoices.json?po_number=`, `GET /api2/credit_notes.json?client_id=`, `GET /api2/invoices/{id}.json`, `GET /api2/taxes.json`, `GET /api2/products.json?product_code=`, `POST /api2/credit_notes.json`, `GET /api2/credit_notes/{id}.json`, `DELETE /api2/credit_notes/{id}.json` (a credit note that failed its check) | Refund lines, amounts, the order reference, the reason |
| Stock read | `GET /api2/products.json?product_code=` | SKUs |

No card data is sent.

## 5. Objects and field mapping

Every answer is `{result, code, data, pagination}`; list items are wrapped in an object named after the record (`{"Invoice": {...}}`) and `pagination` has `prev`, `next`, `page`, `page_count`, `total_results` [documented]. A create returns HTTP 202 with `{"result":"successful","code":202,"id":…}` [documented]. Amounts in answers are numbers; in requests they are numbers too. The connector writes numbers from integer minor units using the currency exponent and never computes with floats.

Client (`POST /api2/clients.json`, body `{"Client": {...}}`) [documented example]: `business_name` (required), `email`, `phone1`, `country_code`, `default_currency_code`, `type`. The list response also names `first_name`, `last_name`, `address1`, `address2`, `city`, `state`, `postal_code`, `bn1` (business number).

| Payload path | Daftra field | Notes |
| --- | --- | --- |
| `customer.name` | `Client.business_name` (required) | Company, else first and last name |
| `customer.email` | `Client.email` | Required when the account invoices by email; without it the client is created offline [documented] |
| `customer.phone` | `Client.phone1` | |
| `customer.address` | `Client.address1` | `[inferred]` from the response field names |
| `customer.city`, `.state`, `.postcode` | `Client.city`, `state`, `postal_code` | `[inferred]` |
| `customer.country` | `Client.country_code` | ISO alpha-2 |
| `customer.tax_number` | `Client.bn1` | Business number; `[inferred]`: the list response has `bn1` and `bn1_label` |
| `currency` | `Client.default_currency_code` | |
| (fixed) | `Client.type` | `3` as in the documented example (the meaning of the values is not documented) |

Invoice (`POST /api2/invoices.json`, body `{"Invoice": {...}, "InvoiceItem": [...]}`) [documented]; only `Invoice.client_id` is required.

| Payload path | Daftra field | Notes |
| --- | --- | --- |
| (client id) | `Invoice.client_id` (required) | |
| (item reference) | `Invoice.po_number` | `WC-{order id}`; the list endpoint filters by `po_number` [documented]. Duplicate-protection field |
| `date` | `Invoice.date` | `YYYY-MM-DD` in the shop's time zone |
| `currency` | `Invoice.currency_code` | The order's currency |
| `note` | `Invoice.notes` | |
| (option) | `Invoice.store_id` | The warehouse |
| (fixed) | `Invoice.draft` | `1` at creation (section 6) |
| (fixed) | `Invoice.is_offline` | not sent |
| `lines[]` | `InvoiceItem[]` | `item` (name), `description`, `unit_price`, `quantity`, `product_id`, `tax1`, `store_id` |

`InvoiceItem.tax1` and `tax2` carry the tax; the list of taxes (`GET /api2/taxes.json`) gives `Tax.id`, `name`, `value`, `included` [documented]. The mapped tax code is the tax id and is sent as `tax1` `[inferred]` (the single-invoice page calls `tax1` and `tax2` "tax rates" and the add page does not type them; the post-create total check guards the guess).

Credit note (`POST /api2/credit_notes.json`) [documented]: same shape as an invoice (`Invoice` object, `InvoiceItem` array); the type is set automatically. `Invoice.subscription_id` can hold the source invoice id (the page says "optional" and to verify against your account, so linking is the option `link_credit_notes`, off by default).

Payment (`POST /api2/invoice_payments.json`, body `{"InvoicePayment": {...}}`) [documented]: `invoice_id`, `amount`, `payment_method` (an active method such as `cash`, `bank`, `cheque` or a gateway key), `date`, `transaction_id`.

## 6. Order flow

1. Parse and check the mapped payload (email, name, lines). Missing email is SYNC-MAP-003.
2. `findExisting`: `GET /api2/invoices.json?po_number=WC-{id}` over all pages; match `Invoice.po_number` exactly.
3. Client: first `GET /api2/clients.json?filter[email]={email}` (the filtering page documents `filter[field]=value` on list endpoints [documented]; the page says nothing about case, so a stored `Noor@Example.com` may not match), over all pages with an exact case-insensitive match on `Client.email`; if that finds no exact match, `GET /api2/clients.json?keywords={email}` the same way; else `POST /api2/clients.json`. A new customer therefore costs two searches. A second client with the same email is the worst case, see Not verified.
4. Products: `GET /api2/products.json?product_code={sku}` over all pages with an exact match on `Product.product_code`. The Daftra product id goes in `product_id`. An unknown SKU is SYNC-MAP-001, or with `unknown_sku = free_text` a line with only `item`, `description`, `unit_price`, `quantity` and the mapped tax.
5. Prices: `lines[].total` (after discounts, tax-exclusive) divided by the quantity, with the line split in two when it does not divide; when the mapped tax has `included = 1` in Daftra (prices include tax) the gross price is sent instead: per line, one percentage tax, whole-number arithmetic, otherwise SYNC-VAL-002 naming the tax. Shipping and fees become lines with `item` text and no product.
6. `POST /api2/invoices.json` with `draft = 1`. A lost answer is handled by step 2 on the next pass.
7. Read back `GET /api2/invoices/{id}.json`: `currency_code` must equal the order's, `summary_total` must equal `order.total - order.excluded_total` within one minor unit, and the number of items must equal the lines sent. A mismatch **deletes the draft** (`DELETE /api2/invoices/{id}.json`; a draft has no official number and no ledger entries [documented]) and returns SYNC-VAL-002 with both totals, so a failed draft is never taken for a finished invoice. If the delete is refused the error says "Delete it in Daftra before syncing the order again". With `total_mismatch = warn` the draft is kept and issued, with a warning.
8. Issue: `invoice_state = issued` (default) calls `GET /api2/invoices/update_draft/{id}/0.json`, which assigns the official sequential number and posts the entries. The draft flag is read first, so an issued invoice is never issued again; the invoice is read again afterwards and, if it is still a draft, the order stops with SYNC-VAL-002 "Daftra did not issue the invoice". If Daftra gave the issued invoice another date than the order's, the result carries a warning (the connector does not edit the date back). A payload can override the option with `flow.invoice` (`issued` or `draft`) and `flow.payment`; the default rules do not set them.
9. Payment (`record_payment = yes`, paid order, `payment_method` set): the invoice's nested payments are read for a `transaction_id` equal to `WC-{id}-PAY`; if absent, `POST /api2/invoice_payments.json` with the invoice total. A paid order with no `payment_method` stops with SYNC-VAL-002 naming `payment_method`.
10. Result: id = invoice id, ref = `Invoice.no` (the official number when issued, else `WC-{id}`), `extra` = `total_minor`, `currency`, `state` (`issued` or `draft`), and `warnings`.

A retry after a failure picks up where the first pass stopped: an invoice found by `po_number` that is still a draft is checked and issued, one that is issued only gets its payment if that is missing; the client is found by email, so it is not created again. A lost answer to the invoice create is found by step 2.

E-invoicing (ZATCA/ETA): Daftra has an `einvoice_status` filter on the invoice list [documented] and a ZATCA submission report [documented], but no documented call to submit or to read the status of one invoice. The connector does not drive it. `InvoiceReadback` is not flagged.

## 7. Refund flow

1. The order's invoice must exist (found by `po_number` as in order step 2); otherwise SYNC-VAL-002 "The order of this refund is not in Daftra". The credit note search comes next (step 2), so a credit note that exists is returned even if the invoice was later changed; a draft invoice is refused after that with "still a draft" (retried later).
2. Credit note search: `GET /api2/credit_notes.json?client_id={the invoice's client}` over all pages; match `po_number` = `WC-R-{refund id}` locally, because the credit note list page documents `client_id`, `no`, `source_id` and date filters and no reference filter [documented]. There is no date limit, so a retry made months later still finds the credit note. `findExisting` has no payload: it follows the id the engine stored (`GET /api2/credit_notes/{id}.json`), otherwise finds the invoice from the refund's partition key (`order:{id}`) and searches the same way.
3. Rows: refunded product lines (net amount, quantity, product id by SKU when Daftra has the product), shipping and other refunded amounts as lines without tax, or one row for a refund without lines. The tax of a product line is resolved from the ratio of its tax amount to its net amount against the active Daftra tax values (within one minor unit), SYNC-MAP-002 when nothing matches.
4. `POST /api2/credit_notes.json` in one step (the documented draft call is for invoices): `client_id` of the invoice, `po_number = WC-R-{refund id}`, `date`, `currency_code` (the invoice's), `notes` naming the order and the reason, `subscription_id` = the invoice id when `link_credit_notes = yes`.
5. Read back and check currency and total against `refund.amount - refund.excluded_amount`. A mismatch deletes the credit note (`DELETE /api2/credit_notes/{id}.json`) and returns SYNC-VAL-002 with both totals; a refused delete is said in the error.
6. Result: id, ref = the credit note number when Daftra gives one, `extra` = `total_minor`, `currency`, `state` `issued`.

## 8. Taxes, currency, dates

- `listTaxCodes()` reads `GET /api2/taxes.json` (not paginated [documented]): `code` = `Tax.id`, `name`, `rate_percent` = `Tax.value`. Inactive taxes (`is_active = 0`) are left out.
- Currency: set per invoice with `currency_code`; read back and compared. The account's base currency is shown by Test connection (`Site.currency_code`).
- Dates: `date` is sent as a date; read back after issue and a change by Daftra is reported as a warning (order step 8).

## 9. Duplicate protection

- Field: `Invoice.po_number` = `WC-{order id}`, searched with the documented `po_number` filter before every create (the filter may match by containment, so the match is checked exactly). Credit notes: `po_number` = `WC-R-{refund id}` found in the credit notes of the invoice's client (section 7). Payments: `transaction_id` = `WC-{order id}-PAY`.
- No idempotency key exists.
- A failed draft or credit note is deleted, so no unfinished record is left behind to be found by mistake. `findExisting` returns the invoice only when it is issued (`draft = 0`), or when `invoice_state = draft` and it is a draft, and the payment is present when payments are wanted. A draft waiting to be issued, or an issued invoice missing its payment, returns null and `send()` finishes it (`SendResult::existing`). A refund's credit note is returned when found in the credit notes of the client.

## 10. Stock, tracking, invoice read-back, reconciliation

- Stock: flagged. `GET /api2/products.json?product_code=` returns `Product.stock_balance` and `track_stock` [documented]. The balance is one total per product: when the request names warehouses `fetchStock` returns SYNC-VAL-002 "Daftra reports one stock figure for all warehouses", otherwise the whole-number part of the balance with `held` 0. Products with `track_stock = 0` are skipped.
- Tracking, prices in, invoice read-back, reconciliation, webhooks: not flagged. Daftra has webhooks inside its developer portal apps [documented]; their payload, signing and retries are not documented, so they are not used. Reconciliation would need the invoice list's totals (`summary_total`, documented) and refunded amounts, which the list does not show, so it is left out rather than guessed.
- Shadow lookup: flagged.

## 11. Rate limits and pacing

The authorization page says limits exist but gives no numbers [documented]. The connector paces at one request per second, one at a time, and obeys 429 and `Retry-After` (default wait 30 s). The tax list is kept in memory for 10 minutes; products are looked up one SKU at a time (up to 10 SKUs, with an exact check because the `product_code` filter may match by containment), and the whole product list is read once when more SKUs are asked for. The health probe (`ping`) is one call, `GET /api2/site_info.json`.

## 12. Options (advanced fields)

| Key | Values | Default |
| --- | --- | --- |
| `domain` | `daftara.com`, `daftra.com` | `daftara.com` |
| `store_id` | warehouse id | empty (the account default) |
| `invoice_state` | `issued`, `draft` | `issued` |
| `record_payment` | `no`, `yes` | `no` |
| `payment_method` | method key, for example `bank` or `cash` | empty (required when `record_payment = yes`) |
| `unknown_sku` | `refuse`, `free_text` | `refuse` |
| `link_credit_notes` | `no`, `yes` | `no` |
| `total_mismatch` | `fail`, `warn` | `fail` |

## 13. Error mapping

| Daftra answer | SYNC code | Class |
| --- | --- | --- |
| Time-out, reset, DNS | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| 5xx | SYNC-NET-002 | retry |
| 429 | SYNC-RATE-001 | retry after the wait |
| 401 | SYNC-AUTH-001 | needs a person |
| 402 | SYNC-AUTH-006 (the documented 402 "Payment Required" on client creation) | needs a person |
| 403 | SYNC-AUTH-004 naming the action (key role or user permission) | needs a person |
| 400 with `validation`, `validationErrors`, `errors`, `error` or `message` | SYNC-VAL-002 naming the field when the answer names one, with a short redacted reason | needs a person |
| 404 on an id | SYNC-VAL-002 | needs a person |
| Unknown SKU / tax / email | SYNC-MAP-001 / 002 / 003 | needs a person |
| Total or currency mismatch | SYNC-VAL-002 | needs a person |

Error bodies are not documented in text (only an image); the connector reads `validation`, `validationErrors`, `errors`, `error` and `message` in that order, as a text, a list or a map of field to messages.

## 14. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders`, `customers`, `refunds` | yes | invoices, clients, credit notes |
| `stock_in` | yes | `stock_balance` |
| `shadow_lookup` | yes | `po_number` search |
| `tracking_in`, `prices_in`, `invoice_readback`, `reconciliation`, `webhooks` | no | see section 10 |

Entities: order, refund, customer. Regions: SA, AE, EG and the Gulf. Rate limit hints: 1 a second, 1 at a time.

## 15. Not verified

- The domain (`daftara.com` or `daftra.com`) and whether a key works on both.
- Plan gate for API access, and the HTTP code of a refused plan (402 is documented only as a response listed for client creation).
- That `keywords` finds a client by email. If it does not, every order creates a new client with the same email (the order still syncs; the clients can be merged in Daftra).
- That `tax1` takes a tax id; the meaning of `Client.type` values; the request names of the client address and tax number fields.
- That a credit note list can be searched by date and client in the way assumed, and that its items carry `po_number`.
- Whether `Invoice.draft = 1` is accepted on create (the field is listed on the add page) and whether the draft's `summary_total` is already calculated.
- That `po_number` accepts text such as `WC-1001` (the list example shows a number).
- Error body format; rate limits; webhooks.
- That the `client_id` filter of the credit note list narrows the list as the parameter list suggests (the page names the parameters in an example URL and does not describe them); the connector reads every page of whatever comes back and matches the reference itself, so a filter that is ignored costs calls, not correctness.
- That `filter[email]` is case-sensitive or not (the keyword search is the fallback), and that it works on clients at all.
- That a credit note can be deleted through the API (a refused delete is reported, not hidden).
- Terms and privacy URLs were not fetched.

## 16. Manual smoke test (a trial account)

1. Create a Daftra trial, add a product with code `TEST-1` that tracks stock, and a 15 percent tax.
2. Add the connection (name, domain, key). Test connection shows the business name, base currency, taxes and warehouses. A wrong key is SYNC-AUTH-001.
3. Map the tax to the Daftra tax id.
4. Place a WooCommerce order for `TEST-1`. Expected: an issued invoice whose purchase-order reference is `WC-{order id}` and whose total equals the shop total; syncing again makes no second invoice.
5. Arabic name and address come back unchanged. Place an order whose email matches an existing client: check whether a second client appears (see Not verified).
6. With `record_payment = yes` and a payment method, a paid order creates one payment.
7. Refund part of an order: a credit note appears with the refund reference; with `link_credit_notes = yes` it is linked to the invoice.
8. Change the stock of `TEST-1` in Daftra and run the stock read.
