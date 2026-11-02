---
id: qoyod
name: Qoyod
catalog_row: Qoyod
connection_method: key
available: true
verified: false
hosts: [api.qoyod.com, www.qoyod.com]
terms_url: https://www.qoyod.com/en/terms-and-conditions/
privacy_url: https://www.qoyod.com/en/privacy-policy/    # fetched; the page title reads "Qoyod Surveys Privacy Policy", check it is the policy you want to cite
developer_url: https://www.qoyod.com/tenant/tenant_settings/general_settings
variants: []
---

# Qoyod connector

Connector id `qoyod`, type accounting, contract version 1. Code: `includes/Connectors/Qoyod/`. Saudi accounting system (ZATCA phase 2 certified). The connector sends WooCommerce orders as sales invoices (optionally with the payment), refunds as credit notes allocated to the invoice, and customers as contacts, and it reads product stock back.

Qoyod publishes its API as a Postman collection whose endpoint pages could not be rendered from the build session. The facts below come from the overview page of the collection, Qoyod help articles and a mirror of the collection's OpenAPI definitions (marked `[third-party]`). Every field of the invoice and credit note request bodies is therefore `[inferred]` from the documented request shapes of sibling resources, and the connector guards against a wrong guess by reading the document back and refusing to approve it when the total does not match. See "Not verified" before relying on this connector.

Tags: `[documented]` official page (URL, fetched 2026-11-02), `[third-party]`, `[inferred]`.

## 1. Sources

All fetched 2026-11-02.

- Collection overview: https://apidoc.qoyod.com/ and https://apidoc.qoyod.com/view/metadata/RzZ6HLRA [documented] (19 resources, `API-KEY` header, Ransack search and sort, status codes, base `https://api.qoyod.com/2.0/`)
- API integrations page: https://www.qoyod.com/en/api-integrations/ [documented] (key in general settings, v2.0, fair use, no numeric limit; "available on Pro plan and above" for ready-made integrations)
- WooCommerce integration help article: https://www.qoyod.com/en/knowledge-base/integrations/integration-woocommerce/ [documented] (Settings, General Settings, API key; Qoyod's own connector sends completed orders as invoices)
- Sandbox article: https://www.qoyod.com/en/knowledge-base/is-there-a-sandbox-testing-environment-for-the-api/ [documented] ("there is no Sandbox environment for the API"; calls hit live data)
- Custom fields article: https://www.qoyod.com/en/knowledge-base/how-to-use-custom-fields-in-qoyod-api/ [documented] (`custom_fields` object, fields created in Settings first)
- Sales invoice statuses: https://www.qoyod.com/en/knowledge-base/how-to-create-sales-invoices-in-qoyod-steps-statuses-payment/ [documented] (Draft, Approved, Paid, Partially Paid, Overdue, Returned; approved invoices cannot be edited; number unique)
- Allocating credit notes: https://www.qoyod.com/en/knowledge-base/how-to-allocate-a-debit-note-or-credit-note-to-an-invoice-us/ [documented] (source_type, source id, date, amount; invoice must still be unpaid)
- Tech partnership page: https://www.qoyod.com/en/partnerships/tech-partnership/ [documented, conflicts: it speaks of OAuth 2.0, webhooks and a sandbox for approved partners; the API reference and the sandbox article say API key and no sandbox]
- Zapier app actions: https://zapier.com/apps/qoyod/integrations/woocommerce/1621306/create-new-woocommerce-orders-for-new-qoyod-invoices [third-party] (labels of the create-invoice, create-credit-note, create-customer, find-product actions)
- OpenAPI mirror pages (One / withone.ai knowledge base) [third-party]: list invoices, create customer, get customer, get product, create product, list inventories, create invoice payment, allocate to invoice, create simple bill (request shapes and response fields, host shown as `www.qoyod.com/2.0/`)
- Terms: https://www.qoyod.com/en/terms-and-conditions/ [documented] (no separate API terms; general terms cover the services). Privacy: https://www.qoyod.com/en/privacy-policy/ [documented]
- Repo research: `docs/research/integration-research-vendors-2.md` section 6.2

## 2. How the merchant connects

1. Use a Qoyod account on the Pro or Advanced plan; the Basic plan has no API.
2. In Qoyod open Settings, General Settings and generate the API key. Save the settings.
3. Paste the key here and press Test connection.
4. Under Advanced, choose the branch (inventory) that sales are booked to and the account currency.
5. For paid orders, switch Record payment on and enter the bank or cash account number that receives the money.
6. Map the tax codes (1 is VAT 15 percent, 2 is VAT 0 percent, 3 is exempt) under Tax mapping.

Plan gate: Pro or Advanced [documented in the repo research; Qoyod's page says "paid plans" and lists ready-made integrations on Pro and above, so confirm with Qoyod]. There is no sandbox: every call, including Test connection and any experiment, changes live company data [documented]. The connector's Test connection only reads.

## 3. Authentication

Header `API-KEY: <key>` on every request [documented]. One secret (`api_key`, encrypted, redacted). The key is tied to the business that generated it and to the permissions of the user [documented: "limited by the user's accounting permissions"]. Base address `https://api.qoyod.com/2.0/` [documented]. The OpenAPI mirror shows `https://www.qoyod.com/2.0/` [third-party]; the connector offers both through the advanced field `endpoint` (default `api.qoyod.com`). HostPolicy allows exactly these two hosts.

Credential fields: `api_key` (secret). Advanced: `endpoint`, `inventory_id`, `currency`, `approval`, `record_payment`, `payment_account_id`, `line_tax_field`, `total_mismatch` (section 12).

## 4. Data sent and when (WordPress.org "External services")

Host: `api.qoyod.com` (or `www.qoyod.com` when chosen), only after the merchant connected a key.

| Action | Request | Data sent |
| --- | --- | --- |
| Test connection | `GET /2.0/inventories` (read until a page adds nothing) | The key only |
| Order synced | `GET /2.0/invoices?q[reference_eq]=`, `GET /2.0/inventories`, `GET /2.0/customers?q[email_cont]=`, `POST /2.0/customers`, `GET /2.0/products?q[sku_cont]=`, `POST /2.0/invoices`, `GET /2.0/invoices/{id}`, `PATCH /2.0/invoices/{id}`, `GET /2.0/invoice_payments?q[reference_eq]=` and `POST /2.0/invoice_payments` (if switched on) | Customer name, email, phone, tax number, address; order number as reference; dates; product id, quantity, price, tax; payment amount, date and reference |
| Refund synced | `GET /2.0/invoices?q[reference_eq]=` and `GET /2.0/invoices/{id}` (the order), `GET /2.0/credit_notes?q[reference_eq]=`, `GET /2.0/products?q[sku_cont]=`, `POST /2.0/credit_notes`, `GET /2.0/credit_notes/{id}`, `PATCH /2.0/credit_notes/{id}`, `POST /2.0/invoices/{id}/allocations` | Refund lines and amounts, the order reference |
| Stock read | `GET /2.0/products?q[sku_in][]=` | SKUs |

No card data is ever sent.

## 5. Objects and field mapping

Money and quantities in Qoyod answers are decimal strings ("55.0") [third-party]; requests accept strings or numbers (the documented request examples use strings for amounts: `"amount": "10.51"`). The connector sends decimal strings built from integer minor units with the currency exponent (SAR 2 decimals).

Customer (`POST /2.0/customers`) [third-party]. The body wraps the customer in `contact`; `name` is required.

| Payload path | Qoyod field | Notes |
| --- | --- | --- |
| `customer.name` | `contact.name` (required) | |
| `customer.company` | `contact.organization` | |
| `customer.email` | `contact.email` | |
| `customer.phone` | `contact.phone_number` | |
| `customer.tax_number` | `contact.tax_number` | Buyer VAT number |
| `customer.address`, `.city`, `.state`, `.postcode`, `.country` | `contact.billing_address`, `billing_city`, `billing_state`, `billing_zip`, `billing_country` | The documented response shows the billing address as an object with these keys; the request shape of the address is `[inferred]` to use the same names |
| (fixed) | `contact.status` | `Active` |

Invoice (`POST /2.0/invoices`) `[inferred]` from the documented list-invoices response (`invoices[]` with `contact_id`, `reference`, `issue_date`, `due_date`, `status`, `description`, `notes`, `line_items[]`), the Zapier create-invoice labels (Description, Issue date, Due date, Customer id, Reference, Payment method, and line fields Product, Unit price, Quantity, Location id, Discount, Tax, Is tax inclusive) and the documented `*_attributes` request convention of simple bills, products and allocations:

| Payload path | Qoyod field | Notes |
| --- | --- | --- |
| (customer id) | `invoice.contact_id` | |
| (item reference) | `invoice.reference` | `WC-{order id}`. Qoyod treats the reference as unique ("must be unique") and shows it as the document number; it is the duplicate-protection field |
| `date` | `invoice.issue_date`, `invoice.due_date` | The order date in the shop's time zone, `YYYY-MM-DD`; due date equals issue date |
| (fixed) | `invoice.status` | `Draft` at creation, always |
| `inventory_id` option | `invoice.inventory_id` and each line's `inventory_id` | The branch. When unset the first branch of `GET /2.0/inventories` is used if there is only one |
| `note` | `invoice.description` | First line `WC-{order id} (unchecked)` while the document has not passed its checks (the unfinished-record mark, section 9), then the order note. The same `PATCH` that approves the invoice rewrites the description without the mark |
| `lines[]` | `invoice.line_items_attributes[]` | `product_id`, `description`, `unit_price`, `quantity`, tax, `is_inclusive`, `inventory_id` |

The line tax is sent as `tax_id` (the code from the tax mapping: "1", "2", "3" or a custom id [documented for products and simple bills]); the advanced field `line_tax_field = tax_percent` sends `tax_percent` instead, which is the field name of the invoice answer. A row with no tax code is sent with the standard zero-rate code `2` explicitly, so a product's default tax cannot add tax the shop did not charge. `is_inclusive` is `false` unless the payload says its prices include tax.

Credit note (`POST /2.0/credit_notes`) `[inferred]`: same structure as the invoice with `credit_note` as the wrapper, `reference` `WC-R-{refund id}`, `description` `WC-R-{refund id} (unchecked)` until the credit note is approved and allocated, then the order and the reason. Allocation (`POST /2.0/invoices/{id}/allocations`) [third-party, documented request]: `{"invoice":{"allocations_attributes":[{"amount":"…","date":"YYYY-MM-DD","source_id":<credit note id>,"source_type":"CreditNote"}]}}`.

Payment (`POST /2.0/invoice_payments`) [third-party, documented request]: `{"invoice_payment":{"account_id":"…","amount":"…","date":"YYYY-MM-DD","invoice_id":"…","reference":"WC-{order id}-PAY"}}`, all five required, `reference` unique.

Product read (`GET /2.0/products`) [third-party]: `id`, `sku`, `name_ar`, `name_en`, `selling_price`, `track_quantity`, `type`, `inventories[]` (`id`, `name_ar`, `name_en`, `stock`).

## 6. Order flow

1. Parse and check the mapped payload (email, name, lines). Order currency must equal the connection's `currency` option (default `SAR`): Qoyod invoices carry no currency field in their documented answer, so a foreign-currency order is refused with SYNC-VAL-002 naming both currencies, never converted silently.
2. `findExisting`: `GET /2.0/invoices?q[reference_eq]=WC-{id}` over all pages. Returns the invoice only when `send()` is finished with it (section 9).
3. Customer: `GET /2.0/customers?q[email_cont]={email}` over all pages, exact case-insensitive match on `email` locally; else `POST /2.0/customers`. A tax number on the payload is sent with the new customer; an existing customer is not changed.
4. Products: `GET /2.0/products?q[sku_cont]={sku}` (all pages) with an exact case-insensitive match on `sku` made locally, because `sku_cont` is the documented predicate and an `_eq` match can be case-sensitive; the product's `id` goes on the line. Unknown SKU is SYNC-MAP-001 before anything is sent. Qoyod lines need a product (Zapier shows a required product id or SKU), so there is no free-text option.
5. Prices: `lines[].total` (after discounts, tax-exclusive) divided by the quantity, split in two lines when it does not divide evenly. Shipping and fees become lines on products named in options `shipping_product_sku` and `fee_product_sku`; when not set the order is refused with SYNC-VAL-002 asking for a service product (Qoyod has no free-text line).
6. `POST /2.0/invoices` as `Draft` with the description mark `(unchecked)`. A timeout is not replayed: the next pass searches by `reference`. If Qoyod answers that the reference "has already been taken" (SYNC-DUP-001) the connector searches again and continues with the invoice it finds.
7. Read back `GET /2.0/invoices/{id}`: the total (`total`, a decimal string) must equal `order.total - order.excluded_total` within one minor unit, and the line count must equal the lines sent. A mismatch (including a tax key that Qoyod ignored) leaves the draft with its `(unchecked)` mark, returns SYNC-VAL-002 with both totals and does not approve. Drafts do not reach reports or ZATCA [documented]. A retry finds the same draft and checks it again; it never makes a second invoice.
8. Finish with one `PATCH /2.0/invoices/{id}`: the description without the mark, the issue and due date when Qoyod shows another date than the order's, and `{"status":"Approved"}` when `approval = after_check`. The status is read first; an Approved or Paid invoice is never patched again. The invoice is read again afterwards: if it is not approved the order stops with SYNC-VAL-002 "Qoyod did not approve the invoice", and a date that Qoyod still shows differently is a warning. `approval = draft` removes the mark and stops at the draft.
9. Payment (`record_payment = yes`, paid order, `payment_account_id` set, invoice Approved): `GET /2.0/invoice_payments?q[reference_eq]=WC-{id}-PAY`, else `POST /2.0/invoice_payments` with the invoice total. A paid order with no payment account stops with SYNC-VAL-002 naming `payment_account_id`.
10. The result reference: id = invoice id, ref = `reference`, `extra` = `total_minor`, `currency`, `state` (`approved` or `draft`), `warnings`.

ZATCA: Qoyod submits approved invoices itself (certified phase 2) [documented]. The connector does not drive or read the submission; the documented invoice fields carry no authority status. `InvoiceReadback` is not flagged.

## 7. Refund flow

1. The original invoice is found by `reference` and must be Approved or Paid; otherwise SYNC-VAL-002 "The order is not in Qoyod yet" (retried later).
2. `GET /2.0/credit_notes?q[reference_eq]=WC-R-{refund id}`; found means skip creating.
3. Create the credit note as `Draft` with the description mark `(unchecked)` and refund lines (net amount, quantity, product by SKU, the tax code resolved from the ratio of tax to amount against the rates of the three standard codes; a ratio nothing matches is SYNC-MAP-002). Shipping and other refunded amounts need the service products of the shipping and fee options; a refund of just an amount uses the fee product. Then check its total against `refund.amount - refund.excluded_amount` (a mismatch leaves the draft marked and returns SYNC-VAL-002), then approve with `PATCH` and read it again.
4. Allocate to the invoice with `POST /2.0/invoices/{id}/allocations` only when the invoice is still unpaid and the amount does not exceed what is due [documented]; the allocation list on the invoice (`payments[]` with `source_type CreditNote`) is read first so a credit note is never allocated twice. A paid invoice leaves the credit note unallocated and the result carries a warning; the money side is handled in Qoyod. A refused allocation is also a warning.
5. Last of all, `PATCH` the description to remove the `(unchecked)` mark. A crash before this leaves the mark, and the next pass finishes the steps that are missing (approve, allocate once, remove the mark).

## 8. Taxes

Qoyod has no tax resource among its 19 [documented], so `listTaxCodes()` returns the three standard codes [third-party: "1" VAT 15 percent, "2" VAT 0 percent, "3" tax exempt] and the mapping screen accepts a custom code typed by the merchant. Exempt and zero-rated sales may need a reason code in Qoyod (`special_tax_reason_id` on products) that the connector does not send. Tax-inclusive prices: the line flag `is_inclusive` follows the payload; nothing is converted.

## 9. Duplicate protection

- Field: `reference` of invoices (`WC-{order id}`), credit notes (`WC-R-{refund id}`), payments (`WC-{order id}-PAY`). Qoyod requires references to be unique [documented for payments and simple bills, stated for invoices in the help article about numbers], so a second create fails with a validation error that the connector reads as "already exists" and resolves by searching again.
- Search: Ransack `q[reference_eq]` [documented syntax: `q[sku_cont]=acc`]; pages are read until a page adds no new id (pagination parameters are not documented, see Not verified).
- No idempotency key exists.
- Unfinished records: while a document has not passed its checks its description starts with `WC-… (unchecked)`. `findExisting` never returns a document that carries the mark, and returns an invoice only when it is Approved (or Paid, or Draft when `approval = draft`) and, when payment is wanted, the payment exists. A Draft waiting for its check and approval, or an approved invoice missing its payment, returns null; `send()` then finds it by `reference` and finishes (`SendResult::existing`). The same rule applies to credit notes.

## 10. Currency, dates, totals

- Currency: only the account currency is supported (option `currency`, default `SAR`). Customers have a `currency_code` field [third-party]; the connector refuses an order in another currency instead of guessing how Qoyod converts it.
- Dates: Qoyod stores `issue_date` as given. The date read back must equal the order date; a difference is rewritten in the final `PATCH` while the document is a draft, and a date still different afterwards is a warning.
- Total check: string arithmetic with the currency exponent; one minor unit is rounding; more stops the document at Draft with its mark (`total_mismatch = fail`, default) or is accepted with a warning (`warn`).

## 11. Stock, tracking, invoice read-back, reconciliation

- Stock: flagged. `GET /2.0/products?q[sku_in][]=…` returns `inventories[]` with `stock` per branch for products that track quantity [third-party]. `fetchStock` returns the sum over the chosen branches (`$warehouses` are inventory ids; empty means all) as a whole number (fractions are rounded down); `held` is 0 because Qoyod documents no reserved quantity.
- Tracking: no shipment records documented. Not flagged.
- Invoice read-back, reconciliation, webhooks, prices in: not flagged (no authority status or PDF link in the documented answer; no documented total of credit notes; Qoyod documents no webhooks for API-key users, the partnership page mentions webhooks for approved partners only).
- Shadow lookup: flagged.

## 12. Options (advanced fields)

| Key | Values | Default |
| --- | --- | --- |
| `endpoint` | `api.qoyod.com`, `www.qoyod.com` | `api.qoyod.com` |
| `inventory_id` | the branch id | empty: the only branch, else the person must choose |
| `currency` | ISO code of the Qoyod account | `SAR` |
| `approval` | `after_check`, `draft` | `after_check` |
| `record_payment` | `no`, `yes` | `no` |
| `payment_account_id` | account id of the bank or cash account | empty (required when `record_payment = yes`) |
| `line_tax_field` | `tax_id`, `tax_percent` | `tax_id` |
| `shipping_product_sku`, `fee_product_sku` | SKU of a service product | empty |
| `total_mismatch` | `fail`, `warn` | `fail` |

## 13. Rate limits and pacing

"Fair use", no numeric limit [documented]. The connector paces at one request per second, one at a time, and obeys `Retry-After` and 429 (SYNC-RATE-001; default wait 30 s). The list of branches is kept in memory for 10 minutes per connection; products and customers are looked up for each order. Large stock reads are batched at 50 SKUs per request.

## 14. Error mapping

| Qoyod answer | SYNC code | Class |
| --- | --- | --- |
| Time-out, reset, DNS | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| 5xx | SYNC-NET-002 | retry |
| 429 | SYNC-RATE-001 | retry after the wait |
| 401 | SYNC-AUTH-001 | needs a person |
| 403 | SYNC-AUTH-006 only when the body names the plan or the API as unavailable ("plan", "subscription" and "API" together, or "upgrade"); otherwise SYNC-AUTH-004 (user without permission) | needs a person |
| 422/400 with "has already been taken" on `reference` | SYNC-DUP-001 handled by a new search | handled |
| 422/400 other | SYNC-VAL-002 with the field and a short redacted reason | needs a person |
| 404 on a referenced id | SYNC-VAL-002 | needs a person |
| Unknown SKU | SYNC-MAP-001 | needs a person |
| Unknown tax code | SYNC-MAP-002 | needs a person |
| Missing email | SYNC-MAP-003 | needs a person |
| Total or currency mismatch | SYNC-VAL-002 | needs a person |

The error body format is not documented ("Only standard HTTP status codes"); the connector reads `errors` (array or object), `error`, `message` in that order.

## 15. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | yes | invoices |
| `customers` | yes | contacts |
| `refunds` | yes | credit notes |
| `stock_in` | yes | `inventories[].stock` on products [third-party] |
| `shadow_lookup` | yes | `reference` search |
| `tracking_in`, `prices_in`, `invoice_readback`, `reconciliation`, `webhooks` | no | not documented for API-key users |

Entities: order, refund, customer. Regions: SA. Rate limit hints: 1 a second, 1 at a time.

## 16. Not verified

- The request bodies of invoices and credit notes (`line_items_attributes`, `tax_id` or `tax_percent`, `inventory_id` placement, `status` values) are inferred. The post-create total, line count and status check is the guard.
- Whether `PATCH /2.0/invoices/{id}` approves a draft; the update verb and field.
- Pagination parameters of list endpoints (the documentation says nothing); the connector asks for `page` and stops when a page adds no new id, with a cap of 100 pages.
- Ransack predicates on the invoice, customer and credit note indexes (`reference_eq`, `email_cont`, `sku_in`) are assumed from the documented `sku_cont` example.
- Whether an invoice reference may carry `WC-…` while e-invoicing is active (the help article says the invoice number cannot be changed manually once e-invoicing is active; creation through the API was not described).
- Host: `api.qoyod.com` versus `www.qoyod.com`.
- Plan gate (Pro/Advanced versus all paid plans) and whether a merchant's own key may be used by a third-party plugin without the partner programme.
- Credit note allocation conditions beyond "invoice still unpaid".
- Error body format.
- Terms URL fetched; the privacy URL fetched is titled as the surveys policy.

## 17. Manual smoke test (a real, non-production company; there is no sandbox)

1. Use a trial company. Create a product with SKU `TEST-1` that tracks quantity, a customer is created by the sync. Note the branch id from Settings or from Test connection.
2. Add the connection with the key and the branch; Test connection must show the branches. A wrong key is SYNC-AUTH-001.
3. Map tax codes 1, 2, 3 in Tax mapping.
4. Place an order for `TEST-1`. Expected: a Draft is created, checked, then Approved; the invoice reference is `WC-{order id}`; the total equals the shop total. Run the sync again: no second invoice.
5. Place an order whose name and address are Arabic: they appear unchanged.
6. With `approval = draft` place an order: the invoice stays Draft and the item is synced.
7. Switch Record payment on with a bank account id; a paid order creates one payment.
8. Refund an unpaid order's line: a credit note is created, approved and allocated; refund a paid order: a credit note without allocation and a warning.
9. Change the stock of `TEST-1` in Qoyod and run the stock read.
10. If step 4 fails with a validation error naming a field, copy the field name into the issue: the request shape is the least certain part.
