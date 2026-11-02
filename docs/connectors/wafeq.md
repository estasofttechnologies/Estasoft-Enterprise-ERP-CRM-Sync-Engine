---
id: wafeq
name: Wafeq
catalog_row: Wafeq
connection_method: key
available: true
verified: false
hosts: [api.wafeq.com]
terms_url: https://wafeq.com/terms        # not verified by fetch (page not reachable from the build session)
privacy_url: https://wafeq.com/privacy    # not verified by fetch (page not reachable from the build session)
developer_url: https://developer.wafeq.com/reference/get-your-api-key
variants: []
---

# Wafeq connector

Connector id `wafeq`, type accounting, contract version 1. Code: `includes/Connectors/Wafeq/`. Gulf accounting system (UAE and Saudi Arabia). The connector sends WooCommerce orders as sales invoices (optionally with the payment), refunds as credit notes applied to the invoice, and customers as contacts. Nothing is read back in bulk: Wafeq documents no stock quantity and no shipment record, so those capabilities are not flagged.

Fact tags: `[documented]` official page (URL, fetched 2026-11-02), `[third-party]` a partner or integrator page, `[inferred]` reasoned from other documented behaviour. "Not verified" lists what could not be confirmed. Nobody has run this against a live Wafeq organisation.

## 1. Sources

All fetched 2026-11-02.

- Get your API key: https://developer.wafeq.com/reference/get-your-api-key [documented]
- Authenticate and make your first request: https://developer.wafeq.com/docs/authenticate-and-make-your-first-request [documented]
- Manage the invoice lifecycle: https://developer.wafeq.com/docs/manage-the-invoice-lifecycle [documented]
- Design a reliable integration (retries, idempotency, pagination, incremental sync): https://developer.wafeq.com/docs/design-a-reliable-integration [documented]
- Invoice creation example: https://developer.wafeq.com/docs/invoice-creation-example [documented]
- Wafeq Public API vs ZATCA API: https://developer.wafeq.com/docs/wafeq-public-api-vs-zatca-api [documented]
- Reference pages: `invoices_create`, `invoices_list`, `invoices_retrieve`, `invoices_partial_update`, `invoices_line_items_create`, `invoices_tax_authority_report_create`, `credit_notes_create`, `credit-notes`, `credit_notes_list`, `invoice_credit_notes_create`, `contacts_create`, `contacts_list`, `contacts_partial_update`, `items_create`, `items_list`, `payments_create`, `payments_list`, `tax_rates_list`, `accounts_list`, `accounts_retrieve`, `organization_retrieve`, all under https://developer.wafeq.com/reference/<name> [documented]. The reference pages render request parameters; they do not render response schemas.
- Wafeq API integration article (Apideck): https://apideck.com/blog/wafeq-api-integration [third-party] (idempotency cache of one hour, `X-Wafeq-Idempotent-Replayed`, entity id prefixes, ZATCA webhooks only, invoice number uniqueness)
- Connection guide (Apideck): https://developers.apideck.com/apis/accounting/wafeq/connection [third-party] (Settings, Developer, Public API toggle, no separate sandbox)
- Repo research: `docs/research/integration-research-vendors-2.md` section 6.1 (60 requests a minute per organisation and record type; demo organisation) [documented in the research, the rate-limit page itself was not reachable on 2026-11-02]

## 2. How the merchant connects

1. Sign in to Wafeq and open the Developer section in the left menu.
2. Press `Enable API`. It needs the Starter plan or higher; a free trial works.
3. Open API Keys, create a new key and copy it.
4. Paste the key here and press Test connection.
5. Under Advanced, enter the sales account (its ID, code or exact name) that invoice lines are booked to.
6. For payments, also enter the account the money arrives in (a bank or cash account) and switch Record payment on.

Plan gate: Starter or higher [documented]. Without the API switched on every request answers 403 [third-party]. Wafeq has no separate sandbox, so a key is a production key [third-party]; test with a trial organisation.

## 3. Authentication

Header `Authorization: Api-Key <key>` on every request [documented]. The key is the only secret (`api_key`, stored encrypted, redacted from every message). Keys belong to one organisation [third-party], so no organisation id is needed or sent. Base address `https://api.wafeq.com/v1/` [documented]. HostPolicy allows `api.wafeq.com` only.

Credential fields: `api_key` (secret, required). Advanced (stored in the connection config, not secret): `sales_account`, `payment_account`, `record_payment`, `invoice_state`, `unknown_sku`, `invoice_number_prefix`, `place_of_supply`, `total_mismatch`, `language` (section 12).

## 4. Data sent and when (WordPress.org "External services")

Host: `api.wafeq.com` (Wafeq), only the host above, only after the merchant connected a key. Nothing is sent before.

| Action | Request | Data sent |
| --- | --- | --- |
| Test connection | `GET /v1/organization/`, `GET /v1/accounts/`, `GET /v1/tax-rates/` | The key only |
| Order synced | `GET /v1/invoices/?external_id=`, `GET /v1/contacts/`, `POST /v1/contacts/` (new customer), `GET /v1/items/`, `POST /v1/invoices/`, `GET /v1/invoices/{id}/`, `PATCH /v1/invoices/{id}/`, `POST /v1/payments/` (if switched on) | Customer name, email, phone, address, company and tax number; order number as reference; order date, currency, notes; product SKU, name, quantity, unit price, tax; shipping, fees and discount; for a payment the amount, date and bank reference |
| Refund synced | `GET /v1/credit-notes/?external_id=`, `POST /v1/credit-notes/`, `POST /v1/invoice-credit-notes/` | Refund amounts and lines, the order reference, the reason as a note |
| Customer lookup | `GET /v1/contacts/` | Email, as a search term |

Card data is never part of the canonical model and is never sent.

## 5. Objects and field mapping

Wafeq ids are opaque strings [third-party: prefixes like `cnt_`, `acc_`]. List responses are `{count,next,previous,results}` [documented]. Money in requests is a JSON number (double) [documented as `double`]; the connector builds it from integer minor units with the currency exponent (KWD 3, JPY 0, else 2) and writes it as a number with exactly that many decimals; nothing is computed with floats.

Contact (`POST /v1/contacts/`) [documented]:

| Payload path | Wafeq field | Notes |
| --- | --- | --- |
| `customer.name` | `name` (required) | Company name when present, else first and last name |
| `customer.email` | `email` | |
| `customer.phone` | `phone` | E.164 |
| `customer.address` | `address` | |
| `customer.city` | `city` | |
| `customer.postcode` | `postal_code` | KSA addresses should have 5 digits |
| `customer.country` | `country` | ISO 3166 alpha-2 |
| `customer.tax_number` | `tax_registration_number` | Buyer VAT number (15 digits for Saudi Arabia) |
| (generated) | `external_id` | `WC-C-` plus the first 24 hex characters of the SHA-256 of the lower-cased email; at most 255 characters. No personal data in the id |
| (fixed) | `relationship` | `["customer"]`, see the note below |
| (fixed) | `party_type` | `business` when a company or tax number is present, else `individual` |

Note: `relationship` is documented as an array of tags named Customer, Supplier, Investor, Partner and Other [documented]. The wire spelling was not shown (the `party_type` enum is lower case, so the connector sends lower case `customer`); this is under Not verified. A wrong spelling would be a 400 with a field error and stops the first order with a readable reason.

Invoice (`POST /v1/invoices/`) [documented]:

| Payload path | Wafeq field | Notes |
| --- | --- | --- |
| (contact id) | `contact` (required) | |
| `currency` | `currency` (required, enum of 115 codes) | The order's currency; read back and compared |
| `date` | `invoice_date` (required) | The order date in the shop's time zone as `YYYY-MM-DD` (Wafeq has no time of day) |
| (same date) | `invoice_due_date` (required) | The order date (paid orders are due at once) |
| (item reference) | `invoice_number` (required, "unique") | `invoice_number_prefix` (default `WC-`) plus the order number. Apideck says Wafeq does not enforce uniqueness [third-party]; the official page says unique [documented]. The connector never relies on it |
| (item reference) | `external_id` | `WC-{order id}`, at most 255 characters. This is the duplicate-protection field |
| (item reference) | `reference` | `WC-{order id} (unchecked)` at creation, so a person sees it in the Wafeq screens; the single `PATCH` that follows a passed check writes `WC-{order id}` (the unfinished-record mark, section 9) |
| `note` | `notes` | Order note |
| (fixed) | `status` | `DRAFT` at creation; `FINALIZED` after the total check (section 6) unless `invoice_state = draft` |
| (fixed) | `tax_amount_type` | `TAX_EXCLUSIVE`; the connector sends tax-exclusive unit prices |
| `lines[]` | `line_items[]` | `account`, `description`, `quantity`, `unit_amount` (from `lines[].total` / quantity), `tax_rate`, `item` |
| `language` option | `language` | `en` (default) or `ar` |
| `place_of_supply` option | `place_of_supply` | UAE organisations only (enum of emirates) |

Line item fields [documented in `invoices_line_items_create`]: `account` (required), `description` (required), `quantity` (required), `unit_amount` (required), `tax_rate`, `item`, `discount` (percent), `cost_center`, `order`.

Credit note (`POST /v1/credit-notes/`) [documented]: required `contact`, `credit_note_date`, `credit_note_number`, `currency`, `line_items`; also `external_id` (`WC-R-{refund id}`), `reference`, `notes`, `status`, `tax_amount_type`, `place_of_supply` (required for UAE organisations).

Payment (`POST /v1/payments/`) [documented]: required `amount`, `currency`, `date`, `paid_through_account`; `contact`, `external_id` (`WC-{order id}-PAY`), `reference` (the gateway transaction id when present), `invoice_payments[]` with `invoice` and `amount` (the object's fields are not described on the reference page; the Invoice creation example page shows `invoice`, `amount`, `amount_to_pcy` [documented]).

## 6. Order flow

Every pass reads before it writes; every step can be resumed.

1. Parse and check the mapped payload before any request (email, name, lines, amounts as integer minor units). Missing email is SYNC-MAP-003.
2. `findExisting`: `GET /v1/invoices/?external_id=WC-{id}`, all pages. A hit that is `FINALIZED` (or `SENT`), or a `DRAFT` whose `reference` is exactly `WC-{id}` when `invoice_state = draft`, is a finished record and is returned. A `DRAFT` that carries the `(unchecked)` mark, or that should be finalized, is an unfinished record: `findExisting` returns null and `send()` continues with it (see Duplicate protection).
3. Contact: `GET /v1/contacts/?external_id=WC-C-...`; if none, `GET /v1/contacts/?keyword={email}` over all pages and compare `email` case-insensitively; if none, `POST /v1/contacts/` with an idempotency key derived from the queue item. The order is only read as far as this step before the lines are built, so an unknown SKU or tax code stops it before a contact is created.
4. Lines: each SKU is matched to a Wafeq item (section 7). The revenue account is the item's `revenue_account`, else the `sales_account` option. Tax rate id from the tax mapping (`system_code` = Wafeq tax rate id).
5. Prices come from the line total after discounts (`lines[].total`, tax-exclusive, minor units) divided by the quantity, so a coupon is already inside the price and no negative line is needed. When the total does not divide evenly the line is split in two (for example 3 units for 10.00 become 2 at 3.33 and 1 at 3.34), so every amount stays exact. Shipping and fees become lines without an item on the sales account, with their mapped tax.
6. `POST /v1/invoices/` with status `DRAFT` and the header `X-Wafeq-Idempotency-Key` (a UUID derived from the item's idempotency key and the step name; honoured one hour [third-party]). A lost answer is handled by step 2 on the next pass (search by `external_id`), not by replaying.
7. Read back `GET /v1/invoices/{id}/`: the currency must equal the order's currency and the total must equal `order.total - order.excluded_total` within one minor unit (the total check, section 10). A mismatch with `total_mismatch = fail` (default) stops before finalizing and leaves the draft with its `(unchecked)` mark; the next pass finds the draft, checks it again, and a person fixes the cause (a draft is not posted to the ledger). It never makes a second invoice.
8. One `PATCH /v1/invoices/{id}/`: `reference` without the mark, the invoice and due date when Wafeq shows another date than the order's, and `{status: FINALIZED}` unless `invoice_state = draft`. A repeat on a finalized invoice is not sent (status is read first). The invoice is read again: if it is not finalized the order stops with SYNC-VAL-002 "Wafeq did not finalize the invoice"; a date Wafeq still shows differently is a warning.
9. Payment (`record_payment = yes`, order paid, invoice finalized): search `GET /v1/payments/?external_id=WC-{id}-PAY`; if none, `POST /v1/payments/` for the invoice total with the idempotency header. The payment account comes from the `payment_account` option; without it the order stops with SYNC-VAL-002.
10. Result reference: id = Wafeq invoice id, ref = `invoice_number`; `extra` has `total_minor`, `currency`, `state` (`finalized` or `draft`) and `warnings`.

Saudi and UAE e-invoicing: reporting to the tax authority is a separate Wafeq call (`POST /v1/invoices/{id}/tax-authority/report/`, `409 report_in_progress` while running) [documented]. The connector does not call it: Wafeq reports automatically on the Plus and Premium plans [documented in the research; repo research 6.1] and the status field of the invoice is not described on the reference pages, so a status read-back or a retry loop would be a guess. `InvoiceReadback` is not flagged. See Not verified.

## 7. Customers, products and taxes

- Customers: found by `external_id` (documented exact filter), then by `keyword` search verified against `email` (no documented email filter, so the keyword result is checked locally for an exact case-insensitive match; all pages are read). A found contact is used as it is (names as typed, no overwrite). `customer_policy` is fixed to match-or-create.
- Products: matched by SKU. Wafeq's item list filters only by `external_id`, `created_ts_*`, `modified_ts_*` [documented]; there is no SKU filter. The connector reads the item list page by page (all pages, `page_size` as large as the server allows, cached for 10 minutes per connection) and matches `sku` exactly, case-insensitively. An unknown SKU is SYNC-MAP-001 before anything is sent, unless `unknown_sku = free_text` (a line without `item`, on the sales account, with the product name).
- Taxes: `GET /v1/tax-rates/` lists the rates [documented]; the response schema is not rendered, so `id`, `name` and `rate` are read defensively (`rate`, then `percentage`, then `rate_percent`). `listTaxCodes()` returns `code` = tax rate id, `name`, `rate_percent`. The tax mapping rows (`country + rate -> system_code`) store the id. A mapped code Wafeq does not know is SYNC-MAP-002.
- Tax-inclusive prices: Wafeq has a document-level `tax_amount_type` [documented]. The connector sends tax-exclusive unit prices and `TAX_EXCLUSIVE`. When the payload says `prices_include_tax`, the unit price is converted to net with whole-number arithmetic per line for a single percentage rate, or refused with SYNC-VAL-002 naming the code.
- Accounts: `GET /v1/accounts/` (all pages, cached) resolves the `sales_account` and `payment_account` options by id, then code, then exact name; with no option set the one `REVENUE` account is used when there is exactly one, otherwise SYNC-VAL-002 asks the person to choose.

## 8. Refund flow

A refund item creates a credit note linked to the original invoice.

1. The order's invoice must exist (found by `external_id`); otherwise the item fails before anything is written with SYNC-VAL-002 "The order of this refund is not in Wafeq" naming `order`. The queue does not retry this automatically; once the order has synced, the refund is retried by a person or the next scheduled retry.
2. Credit note search: `GET /v1/credit-notes/?external_id=WC-R-{refund id}`; a hit is continued from where it stopped (its status, then apply, then the mark), never created again.
3. `POST /v1/credit-notes/` with `DRAFT`, `reference` = `WC-R-{refund id} (unchecked)`, lines from the refund lines (quantity, net amount, item by SKU when Wafeq has it, tax resolved from the ratio of `tax` to `amount` against the rates from `GET /v1/tax-rates/` in integer basis points; a ratio no rate matches is SYNC-MAP-002), shipping and other amounts as separate lines, the contact and currency of the invoice, `notes` naming the order and the reason.
4. Total check against `refund.amount - refund.excluded_amount`; a mismatch leaves the draft marked and returns SYNC-VAL-002. Then `PATCH` to `FINALIZED` and read again.
5. Apply: Wafeq requires both documents posted, the same customer and currency; the same credit note can be applied to the same invoice more than once [documented], so the connector first reads the invoice's credit-note applications (the `credit_notes` field of `GET /v1/invoices/{id}/`) and applies only if this credit note is not there, through `POST /v1/invoice-credit-notes/` `{credit_note, invoice, amount}` with an idempotency header. If the invoice is not finalized, or its applications cannot be read, the apply step is skipped with a warning rather than risking a double application. A refusal (400) is also a warning: the credit note is posted and can be applied in Wafeq.
6. Last, `PATCH` the credit note's `reference` to `WC-R-{refund id}` to remove the mark. A crash between finalizing and applying is finished by the next pass, which applies once and removes the mark.

## 9. Duplicate protection

- Reference field: `external_id` on invoices (`WC-{order id}`), credit notes (`WC-R-{refund id}`), payments (`WC-{order id}-PAY`) and contacts [documented as a filterable field on each list].
- Search: `GET .../?external_id=...` over all pages before every create.
- `X-Wafeq-Idempotency-Key` is sent as a second line of defence and is honoured one hour only [third-party], so it never replaces the search.
- Unfinished records: a document made by the connector carries `reference` = `WC-… (unchecked)` until every step is done. `findExisting` returns a record only when `send()` would have nothing left to do: a finalized invoice (or a draft with a clean reference when `invoice_state = draft`), with the payment present when payment is wanted; for a credit note, one whose `reference` has no mark. A draft that is waiting to be checked and finalized, or a finalized invoice still missing its payment, makes `findExisting` return null; `send()` then finds the same invoice by `external_id` and finishes the steps (`SendResult::existing`). Shadow checks see an unfinished invoice as "not found".

## 10. The total check and currency

- Currency: the invoice is created in the order's currency (`currency`) and, for a currency other than the organisation's base currency, `exchange_rate` is left empty so Wafeq applies its own rate [documented as optional]. After creation the connector reads the document back and compares the currency; a mismatch is SYNC-VAL-002 and the invoice stays a draft.
- Total: Wafeq's total is read from the invoice (`amount`, else `total`, else `total_amount`; the lifecycle page says the response has a "calculated amount" [documented] but the field name is not rendered, so this is under Not verified). It is converted to minor units with the currency exponent by string arithmetic and compared with `order.total - order.excluded_total` (one minor unit allowed). If no total field is present and `total_mismatch = fail`, the invoice is not finalized and the error says Wafeq did not return a total; set `total_mismatch = warn` to finalize anyway.
- Dates: Wafeq stores `invoice_date` as the date it was given; the connector reads it back and rewrites it in the finishing `PATCH` if it differs from the order date; a date that is still different afterwards is a warning.

## 11. Stock, tracking, invoice read-back, reconciliation

- Stock: Wafeq has no stock quantity field [documented: items carry `is_tracked_inventory` but no quantity]. `StockIn` is not flagged.
- Tracking: no shipment records. `TrackingIn` is not flagged.
- Invoice read-back: not flagged (see section 6). `fetchInvoice` returns null.
- Reconciliation: not flagged. The invoice list has `modified_ts_after` and `external_id` filters [documented] but the invoice response fields that hold totals are not rendered on the reference, so a nightly total comparison would be a guess.
- Webhooks: Wafeq's webhooks cover ZATCA events only [third-party]. Not used.
- Shadow lookup: flagged (`findExisting` is a read).

## 12. Options (connection config, advanced fields)

| Key | Values | Default |
| --- | --- | --- |
| `sales_account` | account id, code or exact name | empty: the single revenue account, else the person must choose |
| `invoice_state` | `finalized`, `draft` | `finalized` |
| `record_payment` | `no`, `yes` | `no` |
| `payment_account` | account id, code or exact name (a payment-enabled account) | empty (required when `record_payment = yes`) |
| `unknown_sku` | `refuse`, `free_text` | `refuse` |
| `invoice_number_prefix` | up to 12 characters | `WC-` |
| `place_of_supply` | `none`, `ABU_DHABI`, `AJMAN`, `DUBAI`, `FUJAIRAH`, `RAS_AL_KHAIMAH`, `SHARJAH`, `UMM_AL_QUWAIN`, `OUTSIDE_UAE` | `none` (UAE organisations must choose) |
| `language` | `en`, `ar` | `en` |
| `total_mismatch` | `fail`, `warn` | `fail` |

## 13. Rate limits and pacing

Documented: 429 means back off and retry; the number is not given on the pages read [documented: Authenticate page and Design a reliable integration]. The repo research records 60 requests a minute per organisation and record type [documented in the research]. The connector therefore paces at one request per second (`RateLimitHints( requestsPerSecond: 1.0, concurrency: 1, requestsPerMinute: 60 )`), reads `Retry-After` when present (default wait 30 s when absent, the Apideck advice), and returns SYNC-RATE-001 with the wait. The item, account and tax-rate lists are kept in memory for 10 minutes per connection to save calls.

## 14. Error mapping

| Wafeq answer | SYNC code | Class |
| --- | --- | --- |
| Transport time-out, reset, DNS | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| 5xx | SYNC-NET-002 | retry |
| 429 (with `Retry-After`) | SYNC-RATE-001 | retry after the wait |
| 401 (wrong, missing or malformed `Api-Key`) | SYNC-AUTH-001 | needs a person |
| 403 on an authenticated key | SYNC-AUTH-006 when the body says the API is not enabled or the plan does not include it (narrow patterns: the phrases "public api", "not enabled", "upgrade" together with "api"), else SYNC-AUTH-004 | needs a person |
| 400 with field errors | SYNC-VAL-002 with the field name and a short reason, redacted | needs a person |
| 404 on a relationship id (contact, account, tax rate, item) | SYNC-VAL-002 naming the object | needs a person |
| 409 `report_in_progress` | not used (no report call) | n/a |
| Unknown SKU | SYNC-MAP-001 | needs a person |
| Tax rate id unknown to Wafeq | SYNC-MAP-002 | needs a person |
| Missing email | SYNC-MAP-003 | needs a person |
| Invoice found by `external_id` | SYNC-DUP-001 (handled) | handled |

The 403 plan gate is a heuristic and is listed under Not verified: Wafeq does not document the body of the refusal.

## 15. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | yes | `POST /v1/invoices/` |
| `customers` | yes | `POST /v1/contacts/` |
| `refunds` | yes | `POST /v1/credit-notes/` and `/v1/invoice-credit-notes/` |
| `shadow_lookup` | yes | `external_id` search |
| `stock_in` | no | no quantity in the documented item fields |
| `tracking_in` | no | no shipment records |
| `prices_in` | no | not offered |
| `invoice_readback` | no | status field of the e-invoicing report not documented |
| `reconciliation` | no | total fields not documented |
| `webhooks` | no | ZATCA events only |

Entities: order, refund, customer. Regions: AE, SA, plus the other Gulf states Wafeq lists. Rate limit hints: 1 request a second, 1 at a time.

## 16. Not verified

- The rate-limit page was not reachable; the figure 60 a minute per organisation and record type comes from the repo research.
- Response schemas are not rendered on the reference: the names of the total (`amount`/`total`), the `credit_notes` applications on an invoice, the tax-rate fields (`id`, `name`, `rate`), the account fields (`code`, `name`, `classification`) and the item fields (`sku`, `revenue_account`) are read defensively and were not seen in a real answer.
- Spelling of the `relationship` tag in the contact body, and whether `keyword` searches the email field.
- Whether Wafeq rounds line totals itself (unit amount times quantity at its own precision); the total check would report a difference.
- Whether `invoice_number` must be unique across the organisation (official: yes; Apideck: not enforced).
- The body of a 403 for a key whose API is not enabled; the AUTH-006 patterns are a guess.
- Tax-authority status and reporting: not driven by the connector.
- Terms and privacy URLs in the front matter were not fetched.
- Page size limit of list endpoints (`page_size` maximum).

## 17. Manual smoke test (real organisation, no secrets in the repository)

1. Start a Wafeq trial, press Enable API, create a key. Create a revenue account (or use the default), a bank account, a tax rate of 5 or 15 percent and an item with SKU `TEST-1`.
2. In WordPress add a Wafeq connection with the key; press Test connection. Expected: the organisation name, base currency, the tax rates found and whether the sales account is resolved. A wrong key gives SYNC-AUTH-001.
3. Map the tax rate under Tax mapping to the Wafeq tax rate id.
4. Place a WooCommerce order for `TEST-1`. Expected: one invoice with `external_id` and `reference` `WC-{order id}`, status Finalized, the same total as the shop. Run the sync again: no second invoice.
5. Place an order with an Arabic customer name and address; expected: the contact shows the Arabic text unchanged.
6. Place an order with a coupon, 3 units of one product and a shipping fee; expected: totals equal to the shop, the discounted price on the lines, the 3 units split in two lines when the price does not divide.
7. Switch Record payment on with the bank account; place a paid order; expected: one payment applied to the invoice.
8. Refund part of an order; expected: a finalized credit note applied to the invoice, the invoice balance reduced once.
9. Stop the network after the invoice request and retry: expected no second invoice (search by `external_id`).
