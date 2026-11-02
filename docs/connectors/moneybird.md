---
id: moneybird
name: Moneybird
catalog_row: Moneybird
connection_method: key
available: true
verified: false
hosts: [moneybird.com]
terms_url: https://www.moneybird.nl/terms/                      # fetched 2026-11-02 (Algemene voorwaarden Moneybird B.V., version 7.0 of 1 July 2026; article 5 says Moneybird is not responsible for software used with its API)
privacy_url: https://www.moneybird.nl/privacy/                 # fetched 2026-11-02 (privacyverklaring, version 6 of July 2025)
developer_url: https://moneybird.com/user/applications/new    # named on https://developer.moneybird.com/authentication (fetched 2026-11-02); a login page
variants: []
---

# Moneybird connector

Connector id `moneybird`, type accounting, contract version 1. Code: `includes/Connectors/Moneybird/`. Moneybird (Moneybird B.V., Netherlands) is a cloud invoicing and bookkeeping product. The connector sends WooCommerce orders as sales invoices (draft by default), full refunds as credit invoices and customers as contacts. It reads back the invoice number and state. Moneybird has no stock fields and no shipment records, so those are not flagged. Code shared by the Lexware Office, sevDesk, Moneybird and Pennylane connectors (payload reading, exact money arithmetic, local dates, option reading and the standard refusals) lives in `includes/Connectors/EuAccountingCommon/`; a change there affects all four.

Fact tags: `[documented]` official page (URL, fetched 2026-11-02), `[third-party]` a page that mirrors the official reference, `[recalled]` from the connector author's knowledge of the Moneybird API where the official page could not be fetched (treated like `[inferred]`: implemented conservatively and listed in section 16). Nobody has run this against a live Moneybird administration.

## 1. Sources

- Introduction (base address, administration id, rate limits, status codes, pagination, sandbox): https://developer.moneybird.com/introduction [documented]
- Authentication (personal API token, OAuth 2, scopes): https://developer.moneybird.com/authentication [documented]
- Contacts: https://developer.moneybird.com/api/contacts [documented]
- Products: https://developer.moneybird.com/api/products [documented]
- Sales invoices (lookup, synchronisation, credit invoice, response fields, states): https://developer.moneybird.com/api/sales-invoices [documented]. The create, update, send and payment request bodies are listed there without detail.
- Tax rates: https://developer.moneybird.com/api/tax-rates [documented]; ledger accounts: .../api/ledger-accounts; administrations: .../api/administrations; workflows: .../api/workflows; document styles: .../api/document-styles; financial accounts: .../api/financial-accounts; custom fields: .../api/custom-fields; webhooks: .../webhooks and .../api/webhooks [documented]
- Operation mirrors (withone.ai): `find_by_reference`, `duplicate_creditinvoice`, `register_payment_creditinvoice`, `resume` [third-party]
- Repo research: `docs/research/integration-research-vendors.md` section 4.15.

The changelog (https://developer.moneybird.com/changelog, which the research cites for scope changes on contacts and ledger accounts in June 2026) could not be fetched. The page "creating sales invoices" could not be fetched either.

## 2. How the merchant connects

1. Sign in to Moneybird. API access needs a paid subscription (the free plan ended on 1 July 2026 [third-party]).
2. Open `moneybird.com/user/applications/new`, choose Personal API token and name it `WooCommerce`.
3. Tick the scopes `sales_invoices` and `settings` (also `documents` if the test asks for it), then create the token and copy it.
4. Find the administration number in the address bar when you are in the administration: the digits after `moneybird.com/`.
5. Paste the number and the token here and press Test connection.
6. Under Advanced, choose draft or sent invoices and the sales ledger account.

Plan gate: a paid Moneybird plan; an account over its limit answers 402 "Account limit reached" [documented]. A personal token gives access to the whole account of its user and is shown once [documented]. Tokens do not expire today, "we might change this in the future" [documented]; the connector asks for no end date, and a token that stops working is SYNC-AUTH-001. A sandbox administration exists (full features without a paid plan, watermarked invoices) [documented]: use it for the smoke test. OAuth 2 (client id and secret, authorization code, tokens that may need a refresh) is documented as the alternative; this connector uses the personal token only.

## 3. Authentication

Header `Authorization: Bearer <token>` [documented]. Base address `https://moneybird.com/api/v2/{administration_id}/{resource}.json` [documented; v2 is the only version]. HostPolicy allows `moneybird.com` only. The administration id is always part of the path, so no call can reach another administration; `GET /api/v2/administrations.json` (no administration in the path) lists the ones the token reaches [documented] and the test checks that the typed id is among them.

Credential fields: `administration_id` (text, required, digits only, checked before any request), `api_token` (secret, required, write-only). Advanced options: section 12.

## 4. Data sent and when (WordPress.org "External services")

Host: `moneybird.com` (Moneybird B.V.), only after the merchant saved a token.

| Action | Requests | Data sent |
| --- | --- | --- |
| Test connection | `GET /api/v2/administrations.json`, `GET /{adm}/tax_rates.json`, `GET /{adm}/workflows.json`, `GET /{adm}/ledger_accounts.json`, `GET /{adm}/financial_accounts.json` | The token only |
| Order synced | `GET /{adm}/sales_invoices/find_by_reference/{ref}.json`, `GET /{adm}/contacts.json?query=`, `POST /{adm}/contacts.json`, `GET /{adm}/products/identifier/{sku}.json`, `POST /{adm}/sales_invoices.json`, `GET /{adm}/sales_invoices/{id}.json`, `PATCH /{adm}/sales_invoices/{id}/send_invoice.json` (sent invoices), `POST /{adm}/sales_invoices/{id}/payments.json` (payment, if switched on), `DELETE /{adm}/sales_invoices/{id}.json` (a draft the total check rejected) | Customer name, company, email, phone, address, tax number; order number as reference; order date, currency; SKU, name, quantity, price, tax rate; shipping, fees |
| Refund synced | `GET .../find_by_reference/{ref}.json`, `PATCH .../sales_invoices/{id}/duplicate_creditinvoice.json`, `PATCH .../sales_invoices/{credit id}.json` | The ids of the invoices and the refund reference |
| Customer lookup | `GET /{adm}/contacts.json?query=` | The email address as a search term |
| Invoice status read | `GET /{adm}/sales_invoices/{id}.json` | Nothing but the id |

Card data is never part of the canonical model and is never sent.

## 5. Objects and field mapping

All ids are numeric strings (digits only) [documented]. Money and quantities in requests are decimal strings with `.`, built from integer minor units and the currency exponent without floats. Moneybird's own example posts `"50,50"` and reads back `"50.5"` [third-party], so the connector sends `.` decimals and checks the totals on read-back (section 7).

Contact (`POST /{adm}/contacts.json`, body `{"contact": {...}}`) [documented: at least `company_name` or `firstname` with `lastname`; custom fields need `id` and `value`]:

| Payload path | Moneybird field | Notes |
| --- | --- | --- |
| `customer.company` | `company_name` | |
| `customer.first_name`, `customer.last_name` | `firstname`, `lastname` | Sent next to `company_name` too, as the contact person |
| `customer.street`, `customer.supplement` | `address1`, `address2` | [documented response fields] |
| `customer.zip`, `customer.city`, `customer.country` | `zipcode`, `city`, `country` (ISO alpha-2) | |
| `customer.phone` | `phone` | |
| `customer.email` | `send_invoices_to_email`, `email` | [documented response fields; writable assumed] |
| `customer.vat_id` | `tax_number` | |
| `customer.language` | none | The invoice language comes from the workflow |

Sales invoice (`POST /{adm}/sales_invoices.json`, body `{"sales_invoice": {...}}`) [recalled; the response fields are documented]:

| Payload path | Moneybird field | Notes |
| --- | --- | --- |
| `order.client_ref` | `reference` | `WC-{order id}` |
| (contact) | `contact_id` | |
| `invoice_date` | `invoice_date` | `YYYY-MM-DD`, in the administration's time zone (option `timezone`, default `Europe/Amsterdam`) |
| `currency` | `currency` | Order currency, read back and compared |
| `prices_include_tax` | `prices_are_incl_tax` | |
| (option) | `workflow_id`, `document_style_id` | Moneybird's defaults when absent |
| `order_line[]` | `details_attributes[]` `{description, price, amount, tax_rate_id, ledger_account_id, product_id}` | `price` is a unit price string, `amount` the quantity; `product_id` when the SKU matched; a line whose total cannot be divided exactly by its quantity becomes one line of quantity 1 with `N x name` in the description |
| shipping, fee | `details_attributes[]` | Shipping and fee lines with their own tax rate id |
| `note` | `details_attributes` description or invoice text | The order note is not sent unless the mapping provides `remark` |

Tax rate id per line, ledger account id per line (option `sales_ledger_account`, `shipping_ledger_account`; absent: Moneybird applies the workflow or product default). SKU lookup: `GET /{adm}/products/identifier/{identifier}.json` returns the product or 404 [documented]. Contacts: `GET /{adm}/contacts.json?query=<text>` is a free-text search over name, contact people, email, phone and customer id, with `page`, `per_page` (1 to 100) and `include_archived` [documented]; `GET /{adm}/contacts/customer_id/{customer_id}.json` finds a contact by its customer number [documented].

## 6. Duplicate protection

`reference` = `WC-{order id}`, searched before every create with `GET /{adm}/sales_invoices/find_by_reference/{reference}.json` [documented; answers the invoice, or 404 `{"error": "Sales invoice with reference: ... was not found", "symbolic": {"reference": "not_found"}}`]. Nothing documents that `find_by_reference` finds **drafts** (its sibling `find_by_invoice_id` never does, because a draft has no invoice id [documented]), nor which document it returns when an invoice and its credit invoice share a reference. So a miss, or an answer of the wrong kind (a credit invoice when an invoice is wanted, and the reverse), is checked against the list endpoint, `GET /{adm}/sales_invoices.json?filter=reference:WC-{id}&page=n&per_page=100` (`filter` is a comma-separated list of `key:value` terms [documented for the synchronisation call]; that `reference` is a list filter key is [inferred], section 16), keeping documents whose reference is exactly the wanted one. That costs one more call for every new order. Credit invoices carry `WC-{refund id}` (set by the connector right after they are made, section 9).

`findExisting` reports an invoice only when `send()` finished it for the chosen options: a draft is finished in `draft` mode; in `sent` mode only a non-draft one; with a payment wanted only a paid one. Otherwise `send()` carries on from the invoice's state (check totals, send, pay). `findExisting` asks for an invoice for an order item and for a credit invoice for a refund item. Replays: create-invoice is never repeated; send and pay steps read the state first (`state`, `total_unpaid`) and run only when missing. A timeout after `POST /sales_invoices.json` is an unknown outcome, so the next pass finds the draft by its reference.

Weakness: the reference is a visible field; a person who edits it breaks the link and a retry creates another invoice.

## 7. Order flow, taxes, currency and the total check

1. Check the payload (email, lines, amounts), then `find_by_reference`.
2. Customer (section 8); products by SKU (unknown SKU: SYNC-MAP-001, or `unknown_sku = custom`: a free-text line).
3. Taxes. The tax mapping code is a Moneybird tax rate id (digits); `listTaxCodes` returns the active sales tax rates (`GET tax_rates.json` with `filter=tax_rate_type:sales_invoice,active:true`; a filter replaces the defaults entirely [documented]) with their `percentage`. A code that is not a sales tax rate of this administration stops the order with SYNC-MAP-002. `prices_are_incl_tax` follows the payload; prices are never converted by the connector (Moneybird does it).
4. Create the draft: `POST sales_invoices.json`. Read it back: `currency`, `total_price_excl_tax`, `total_price_incl_tax`, `state`.
5. Currency: the read-back `currency` must equal the order's. A different currency (an administration that does not offer it) deletes the draft and fails with SYNC-VAL-002 naming both.
6. Total check: `total_price_incl_tax` against `total - excluded_total` in minor units. WooCommerce rounds the tax of each line and Moneybird the tax of each rate group, so the default tolerance is one minor unit per document row (lines, shipping, fees), at most 10 (ten lines of 0.99 EUR at 21 % differ by 2 cents); the option `total_tolerance` (0 to 50; empty means the default) replaces it. More: option `total_mismatch` `fail` (default) deletes the draft (`DELETE`, only a draft this pass created) and fails with SYNC-VAL-002 naming both totals; `warn` goes on with a warning.
7. `invoice_state = sent`: `PATCH send_invoice.json` with `{"sales_invoice_sending": {"delivery_method": "Manual", "sending_scheduled": false}}`, which makes the invoice open and gives it its number without e-mailing the customer [recalled]. The state is read back; the date is written once at creation (`invoice_date` is the order date). The read-back `invoice_date` is compared with the day sent, after creation and again after sending; if it is another day the result carries the warning `date_differs`. The date is never written back.
8. Payment (option `payment_account` = a financial account id, and the order is paid, and the invoice is open): `total_unpaid` is read first; when it is 0 nothing is booked; else `POST payments.json` with `{"payment": {"payment_date", "price", "financial_account_id"}}` [recalled].

## 8. Customers

`lookupCustomer( email )`: `GET contacts.json?query=<email>&per_page=100` paged to the end, keeping contacts whose `email` or `send_invoices_to_email` equals the address (case-insensitive) [the contact response carries both, documented]. Several matches: option `duplicate_customer` `oldest` (smallest id, default) or `fail`. None: create, per `customer_policy` (`match_or_create`, `match_only`, `always_create`; the invoice search is by reference, not by contact, so a new contact per pass cannot hide an invoice). The customer number (`customer_id`) is left to Moneybird.

## 9. Refunds

`duplicate_creditinvoice` [documented]: `PATCH /{adm}/sales_invoices/{id}/duplicate_creditinvoice.json` with the body `{}` creates a draft credit invoice that copies the original (`original_sales_invoice_id` set, description referencing the original number); an optional `detail_ids` limits it to some lines.

1. Find an existing credit invoice for this refund (a credit invoice with the reference `WC-{refund id}`, section 6). Found: carry on from its state (send when asked), done.
2. Find the original (an invoice, not a credit invoice, with the reference `WC-{order id}`). Missing: SYNC-VAL-002 "the order has no invoice in Moneybird". A draft original is refused too: a draft has no invoice number to credit.
3. Only a refund of the whole order (amount equal to the invoice's `total_price_incl_tax`, within the tolerance of section 7 step 6 for the invoice's number of lines) is created: SYNC-VAL-002 "Moneybird credit invoices copy whole lines; this refund covers part of the order. Create the credit invoice in Moneybird and skip this item." for any other refund. Partial refunds through `detail_ids` need a reliable match between WooCommerce refund lines and invoice lines, which Moneybird's data does not give (no SKU on lines without a product).
4. Duplicate, then `PATCH sales_invoices/{credit id}.json` with `{"sales_invoice": {"reference": "WC-{refund id}"}}` [recalled]. The copy keeps the original's reference (the example in the documentation shows the credit invoice with the same reference as its source, though the page does not say it is copied). The two calls cannot be one step, so before duplicating the connector lists the documents that carry the order's reference and adopts a **draft credit invoice that points at the original** (`original_sales_invoice_id`): the copy a pass made before its second call failed. Only that one is adopted, once, and it gets the refund's reference; no second copy is made. A draft credit invoice a person made by hand from the same original would be adopted the same way.
5. `refund_state = sent` sends it like an invoice (`delivery_method` `Manual`); the credit invoice's payment is not registered (`register_payment_creditinvoice` exists [third-party] but a refund is the money going back through the shop's payment provider).

## 10. Payments

Opt-in, section 7 step 8. Refunds record no payment.

## 11. Stock, tracking, invoice read-back, reconciliation

- Stock and tracking: Moneybird's product fields are description, price, currency, tax rate, ledger account and identifier [documented]; no quantity, no shipment. Not flagged.
- Invoice read-back (`InvoiceReadback`): `GET sales_invoices/{id}.json`: `invoice_id` (the number; absent on a draft), `state` (`draft`, `scheduled`, `open`, `pending_payment`, `reminded`, `late`, `paid`, `uncollectible` [documented]), `total_price_incl_tax`, `paid_at`, `sent_at`, `url`. `authority_status` empty. `pdf_url` = `url` (the invoice page in Moneybird).
- Reconciliation: not flagged. The keys of the invoice list filter and of `synchronization` are not documented on the page that was read, so a window by date could not be verified.
- `ShadowLookup`: flagged (`find_by_reference` is read-only).

## 12. Options (connection config, `options` block)

| Key | Values | Default |
| --- | --- | --- |
| `invoice_state` | `draft`, `sent` | `draft` |
| `refund_state` | `draft`, `sent` | `draft` |
| `customer_policy` | `match_or_create`, `match_only`, `always_create` | `match_or_create` |
| `duplicate_customer` | `oldest`, `fail` | `oldest` |
| `unknown_sku` | `refuse`, `custom` | `refuse` |
| `total_mismatch` | `fail`, `warn` | `fail` |
| `sales_ledger_account` | ledger account id | empty |
| `shipping_ledger_account` | ledger account id | empty |
| `workflow_id`, `document_style_id` | ids | empty |
| `payment_account` | financial account id | empty (no payment) |
| `timezone` | PHP time zone | `Europe/Amsterdam` |

## 13. Rate limits and pacing

150 requests per 5 minutes per IP address (50 per 5 minutes on `/reports/`, not used) [documented]. The limit is per IP: a shared host's other applications count too. `rateLimitHints()` returns 0.4 requests per second, 24 per minute, concurrency 1 (interval 2.5 s): 120 calls in 5 minutes, below the 150, so other applications on the same address have room. `ping()` is one call, `GET /{adm}/workflows.json` (it also proves the administration number). 429 → SYNC-RATE-001. A 429 carries `Retry-After`, `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset` [documented, https://developer.moneybird.com/introduction, fetched 2026-11-02], so the wait is the `Retry-After` value, else 30 seconds. A full order is about 7 to 10 calls, i.e. 18 to 25 seconds of pacing; a burst of orders queues behind it.

## 14. Error mapping

Moneybird returns the error in the body in the request's format: `{"error": "...", "symbolic": {...}}` [documented examples]; validation errors (422) carry field messages [documented status; body shape `[recalled]`].

| Situation | SYNC code | Notes |
| --- | --- | --- |
| Timeout, reset, name not resolved | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | |
| 401 | SYNC-AUTH-001 | missing or invalid token, or a missing scope; the message says to check the scopes |
| 402 | SYNC-AUTH-006 | "Account limit reached" [documented] |
| 403 | SYNC-AUTH-004 | "IP blacklisted" [documented]: Moneybird refuses this server's address |
| 404 on `find_by_reference`, `products/identifier` | normal "not found" | |
| 404 elsewhere | SYNC-VAL-002 | record deleted between steps |
| 400, 405, 406, 422 | SYNC-VAL-002 | short sanitised reason with the field names |
| 429 | SYNC-RATE-001 | the `Retry-After` seconds, else 30 |
| 500 | SYNC-NET-002 | retry |
| Unknown SKU, tax rate not a sales rate, missing email | SYNC-MAP-001, SYNC-MAP-002, SYNC-MAP-003 | before sending |

Secrets: the token is scrubbed; the administration id is scrubbed from messages and logs.

## 15. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | true | Sales invoices |
| `customers` | true | Contacts |
| `refunds` | true | Credit invoices; full refunds only |
| `stock_in` | false | No stock |
| `tracking_in` | false | No shipments |
| `prices_in` | false | Not read |
| `invoice_readback` | true | Number and state |
| `reconciliation` | false | List filters not verified |
| `webhooks` | false | Documented (signed pushes with `Idempotency-Key`, 10 retries) but the plugin polls |
| `shadow_lookup` | true | `find_by_reference` is read-only |

Capability record: `order_document` `invoice`, `refund_document` `credit_note`, `stock_read` `none`, `tracking_read` `no`, `customer_lookup_by_email` `yes`, `product_lookup_by_sku` `yes` (product identifier), `reference_field` `reference`, `native_idempotency` `none` (an `Idempotency-Key` exists on incoming webhooks only), `webhooks` `none`, `rate_limit` 150 per 5 minutes per IP, `requires` "A paid Moneybird plan".

## 16. Not verified

- The request bodies of create, update, send and payment (the sales invoice page lists them without detail; the field names above are recalled from the API): `details_attributes` keys, `amount` and `price` string formats, `prices_are_incl_tax`, `currency`, `workflow_id`, `document_style_id`, `sales_invoice_sending`, `delivery_method` `Manual`, `payment` keys.
- Whether `currency` can be set on create and which currencies an administration offers.
- Whether sending rewrites `invoice_date` (the connector now compares and warns, `date_differs`, but does not write it back).
- Whether `email` is writable on a contact (documented only as a response field), whether `include_archived` should be set when matching.
- The 422 error body shape.
- Whether a draft can always be deleted by `DELETE`, and that deleting has no side effect on the invoice number sequence.
- What 403 "IP blacklisted" looks like in practice.
- Whether `find_by_reference` finds drafts and which document it returns when an invoice and its credit invoice share a reference (undocumented; the list cross-check of section 6 covers both). The `reference` key of the list filter and the `page` / `per_page` parameters of `GET sales_invoices.json` (the page read does not document list parameters). Sales invoice list filter keys and the changelog (scope changes on contacts and ledger accounts in June 2026: the research says they were swapped; if the contacts call answers 401 for a token that has `sales_invoices` and `settings`, add the scope the answer names). The authentication page says contacts need one of `sales_invoices`, `documents`, `estimates`, `bank`, `settings` [documented, 2026-11-02].
- Partial refunds: whether `original_sales_invoice_id` can be sent on create and which sign the amounts take. That `duplicate_creditinvoice` keeps the original's reference (the example suggests it; the page does not say so): if it does not, the adoption of a draft copy by its reference does not find an orphan and a failed second call can still leave an extra draft credit invoice for a person to delete.
- Terms: version 7.0 of 1 July 2026 and the privacy statement (version 6) were fetched; only article 5 (API and third-party software) was read for this connector, not the whole text.

## 17. Manual smoke test (real administration, no secrets in the repository)

1. Create a sandbox administration (or use a trial). Create a personal token with `sales_invoices` and `settings`; note the administration number.
2. Add the connection. Expected: Test connection shows the administration name, currency and the tax rates; a wrong token gives SYNC-AUTH-001; a wrong administration number is refused before any call.
3. Create a product with identifier `TEST-1`. Place an order for `TEST-1`. Expected: a contact and a draft invoice with reference `WC-{id}` and the shop's total. Run the sync again: no second invoice.
4. Switch `invoice_state` to `sent` and place another order: an open, numbered invoice, no e-mail sent. Set `payment_account` and place a paid order: the invoice becomes paid.
5. Refund the whole order: a draft credit invoice with reference `WC-{refund id}` and `original_sales_invoice_id`. Refund part of an order: SYNC-VAL-002 with the explanation.
6. Order with prices including tax and with a coupon: the totals match.
7. Send 20 orders at once and watch the pacing: no 429.
