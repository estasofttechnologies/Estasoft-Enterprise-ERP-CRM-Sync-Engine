---
id: pennylane
name: Pennylane
catalog_row: Pennylane
connection_method: key
available: false
unavailable_reason: "Pennylane requires OAuth for production integrations (its company tokens are for sandbox testing) and its API terms limit use to internal business purposes, so this connector cannot be offered until both are cleared with Pennylane."
verified: false
hosts: []
terms_url: https://pennylane.readme.io/page/api-contract-terms     # fetched 2026-11-02; read the legal note in section 16 before publishing
privacy_url: https://www.pennylane.com/fr/legal/privacy            # fetched 2026-11-02 (Politique de confidentialité, last updated 30 January 2026)
developer_url: https://pennylane.readme.io/docs/generating-my-api-token   # fetched 2026-11-02; the token is made in the Pennylane app under Settings, Connectivity, Developers
variants: []
---

# Pennylane connector

Connector id `pennylane`, type accounting, contract version 1. Code: `includes/Connectors/Pennylane/` holds only a placeholder that makes no request (see the note below); the connector described in this document was removed from the plugin on 12 Nov 2026 and is in git history at commit `7a076a8` (`includes/Connectors/Pennylane/`, `tests/php/Unit/Connectors/Pennylane/`, `tests/php/Support/FakePennylaneServer.php`). Pennylane (Pennylane SAS, France) is an accounting and invoicing platform. The connector sends WooCommerce orders as customer invoices (draft by default) and customers as company or individual customers. It reads back the invoice number and status and a night's list of invoices. **Refunds are not sent in this release**: the endpoint that creates a credit note is documented, but its request body could not be read (section 9). Pennylane has no stock and no shipment records. Code shared by the Lexware Office, sevDesk, Moneybird and Pennylane connectors (payload reading, exact money arithmetic, local dates, option reading and the standard refusals) lives in `includes/Connectors/EuAccountingCommon/`; a change there affects all four.

Fact tags: `[documented]` official page (URL, fetched 2026-11-02), `[third-party]`, `[inferred]`. Section 16 lists what is unconfirmed. Nobody has run this against a live Pennylane company.

**This connector is not offered yet (`available: false`).** The plugin contains no code that talks to Pennylane (the Plugin Review team asked for every contacted service to be documented, and this one cannot be offered), and the Choose a system screen shows Pennylane as not available, for two reasons that the owner has to resolve with Pennylane first. (1) Pennylane's own guide (`docs/create-a-customer-invoice-use-case`) says a company API token is for testing in the sandbox and that production integrations must use OAuth; this connector takes a pasted company token. (2) Pennylane's API contract terms (section 16, Legal) limit use to the client's internal business purposes, forbid putting API components under open-source licence terms and grant Pennylane rights over "integrations". What must happen before it can ship: (a) written confirmation from Pennylane that a free open-source plugin may be used with merchants' own company tokens in production, or an OAuth 2 sign-in version of this connector (a registered Pennylane app, a different connection method and the token refresh of the base; not written); (b) a decision by the owner on the contract terms; (c) a run of the smoke test in section 17 against a Pennylane sandbox, which also settles the unconfirmed paths and bodies in section 16. Then set `available: true`, remove `unavailable_reason` and run `php bin/generate-catalog-from-docs.php`.

## 1. Sources

All under https://pennylane.readme.io/, fetched 2026-11-02 [documented]:

- `docs/getting-started`, `docs/generating-my-api-token`, `docs/v2-scopes`, `docs/rate-limiting-1`, `docs/error-handling-status-codes`, `docs/using-cursor-based-pagination`, `docs/2026-api-changes-guide`, `docs/string-characters-limit`, `docs/handle-vat-rates-by-ledger-accounts`
- `docs/create-a-customer-invoice-use-case`, `docs/import-vs-create-a-customer-invoice-whats-the-difference`, `docs/customer-invoicing`, `docs/supported-use-cases`, `docs/automating-payment-matching`, `docs/how-to-update-individual-and-company-customers`
- `docs/filter-my-customers`, `docs/filter-my-customer-invoices`, `docs/filter-my-credit-notes`, `docs/filter-my-products`
- `reference/postcustomerinvoices` (endpoint and scope only; the body tabs did not render), `reference/getcustomerinvoices`, `reference/getcustomerinvoice`, `reference/linkcreditnote`, `reference/getproducts`
- API contract terms: `page/api-contract-terms`
- Repo research: `docs/research/integration-research-vendors.md` section 4.16.

Corrections to the repo research: it says "no reference field found" for invoices and "credit-note creation not found". The invoice list documents the filter `external_reference` (`eq`), `credit_note` and `draft` (booleans), and the create endpoint is described as creating "a draft or finalized customer invoice or credit note" [documented, `reference/getcustomerinvoices`, `reference/postcustomerinvoices`].

## 2. How the merchant connects

1. Sign in to Pennylane as an administrator of a company on the Essential plan or higher.
2. Open Management, Settings, Connectivity, Developers and press `Generate an API Token`.
3. Choose API V2, `Read and write`, and the scopes `customers:all`, `customer_invoices:all` and `products:readonly`.
4. Choose how long the token lasts: 1, 6 or 12 months or unlimited. Copy the token now; Pennylane shows it once.
5. Paste the token here, choose the same lifetime, and press Test connection.
6. Under Advanced, choose draft or finalised invoices and the days to pay.

Plan gate: Essential plan or higher (a Starter company must upgrade) [documented]. Who may create a token: users with admin access to the company workspace, listed as the Executive, Internal Accountant and External Accountant roles [documented]. The token is for one company; a firm token additionally needs an `X-Company-Id` header and is not used. Lifetime options 1 month, 6 months, 12 months or unlimited [documented]; what happens when it ends is not described. The connector turns the chosen lifetime into `credential_expires_at` (the moment of the test plus 1, 6 or 12 calendar months; nothing for unlimited), so the 14-day warning and the Replace key flow work. Deleting a token in Pennylane stops it at once and cannot be undone [documented]. Pennylane's own guide says partner integrations must use OAuth 2 and "a Company API token works only in sandbox" [documented, `create-a-customer-invoice-use-case`]; the Getting started page assigns the Company API token to companies connecting their own data. See the legal note in section 16.

## 3. Authentication

Header `Authorization: Bearer <token>` [documented]. Base address `https://app.pennylane.com/api/external/v2` [documented]. HostPolicy allows `app.pennylane.com` only. `GET /me` returns the active scopes and needs no scope [documented]; the test reads it. Test connection lists a scope that `/me` does not show as a missing permission and stays green, because the shape of `/me` and the scope names it uses could not be verified (section 16); the first write with such a token is what fails. A missing scope answers 403 with a message such as "Missing scope: customer_invoices:all" [documented]; a bad or expired token answers 401.

Credential fields: `api_token` (secret, required, write-only), `token_lifetime` (select: `1`, `6`, `12`, `unlimited`; default `12`). Pennylane shows no end date, so the end date is the day of the first test plus the lifetime. A later test of the same token and lifetime keeps that first date instead of sliding it forward with every test (so the warning before it still comes); a new token or another lifetime starts again from the day of the test. To tell the tokens apart the test stores a fingerprint, the first 16 hex characters of the SHA-256 of the token, next to the date in the connection's detected facts; the token itself is never stored there. Advanced options: section 12. The 2026 behaviour change (14 Jan to 1 Jul 2026; cursor pagination on ledger endpoints, `ledger` scope replaced) is complete on 2 Nov 2026 [documented]; the connector uses no ledger endpoint and sends no opt-in header.

## 4. Data sent and when (WordPress.org "External services")

Host: `app.pennylane.com` (Pennylane SAS), only after the merchant saved a token.

| Action | Requests | Data sent |
| --- | --- | --- |
| Test connection | `GET /api/external/v2/me`, `GET /customers?limit=1`, `GET /customer_invoices?limit=1`, `GET /products?limit=1` | The token only |
| Order synced | `GET /customer_invoices` (duplicate check), `GET /customers` (by reference), `POST /company_customers` or `POST /individual_customers`, `GET /products` (by reference), `POST /customer_invoices`, `GET /customer_invoices/{id}`, finalise (finalised mode) | Customer name, company, email, phone, billing address, VAT number; order number as external reference; order date and due date; product name, quantity, unit price, VAT code; shipping, fees |
| Customer lookup | `GET /customers?filter=` | A hash-derived reference, never the email |
| Invoice status, reconciliation | `GET /customer_invoices/{id}`, `GET /customer_invoices` | Ids and dates |

Card data is never part of the canonical model and is never sent.

## 5. Objects and field mapping

ids are integers. All amounts are **strings** (`"100.00"`), also `quantity` is a number; numbers sent as JSON numbers are a 400 [documented]. Strings have a general limit of 2000 characters; exceptions are documented per attribute [documented]. The connector builds decimal strings from minor units with `Money` and never uses floats.

Customer (`POST /company_customers` or `POST /individual_customers`; the company path is [documented], the individual path is [inferred] from the update path) [documented field names from the update guide]:

| Payload path | Pennylane field | Notes |
| --- | --- | --- |
| `customer.company` | `name` (company) | A company customer when a company name is present |
| `customer.first_name`, `customer.last_name` | `first_name`, `last_name` (individual) | Individual customer otherwise |
| `customer.vat_id` | `vat_number` (company) | |
| `customer.email` | `emails` (list) | |
| `customer.phone` | `phone` | |
| `customer.street` (+ supplement), `customer.zip`, `customer.city`, `customer.country` | `billing_address` `{address, postal_code, city, country_alpha2}` | |
| (derived) | `external_reference` | `WC-C-` plus 16 hex characters of `sha256(lowercase email)`; the lookup key, section 8 |

Wrong-type calls corrupt data: calling the individual endpoint with a company id writes names onto the company record [documented]; the connector always creates with the right endpoint and never updates a customer.

Customer invoice (`POST /customer_invoices`) [documented from the use-case guide]:

| Payload path | Pennylane field | Notes |
| --- | --- | --- |
| (customer) | `customer_id` | Integer, required |
| `invoice_date` | `date` | `YYYY-MM-DD` in `Europe/Paris` (option `timezone`) |
| (derived) | `deadline` | Date plus `payment_days` (option, default 30) |
| `order.client_ref` | `external_reference` | `WC-{order id}` |
| (option) | `draft` | `true` in draft mode; omitted means the invoice is finalised at once [documented] |
| (option) | `customer_invoice_template_id` | Only when the option is set |
| `order_line[]` | `invoice_lines[]` `{label, quantity, unit, raw_currency_unit_price, vat_rate}` | `raw_currency_unit_price` the net unit price string; `vat_rate` a VAT code such as `FR_200`; `unit` default `piece` |
| shipping, fee | `invoice_lines[]` | `unit` `service` |
| (option) | `invoice_lines[].ledger_account_id` | From `sales_ledger_account` when set; else Pennylane's default mapping [documented] |

A line whose total cannot be divided exactly by its quantity becomes one line of quantity 1 with `N x name` in the label (Pennylane documents no decimal limit for the unit price, so the connector stays exact). Products: `GET /products` with `filter=[{"field":"reference","operator":"eq","value":"<SKU>"}]` [documented filter]; the product proves the SKU exists. Its id is **not** sent on the line (the guide says `product_id` is used instead of manual line details, and the shop's price must win).

Filters are a JSON array in the `filter` query parameter, URL-encoded: `[{"field":"external_reference","operator":"eq","value":"WC-1"}]` [documented; the guides write `filters`, the reference writes `filter`; the reference is followed].

## 6. Duplicate protection

`external_reference = WC-{order id}` on create, and before every create `GET /customer_invoices?filter=[{"field":"external_reference","operator":"eq","value":"WC-{id}"}]&limit=100` [documented filter]. The answer is cursor-paged (`items`, `has_more`, `next_cursor`) [documented]; the connector follows `next_cursor` to the end and re-sends the filter with every page (the cursor does not hold it [documented]). Credit notes (`credit_note` true) and the connector's own invoices are both visible; only invoices are matched, and only a row whose `external_reference` is present and exactly the wanted one counts (a row without the field, which a server that ignores the filter could return, is never taken for the invoice).

`findExisting` returns a record only when `send()` finished it: draft mode: the draft exists and its totals were checked; finalised mode: the invoice is finalised. Otherwise `send()` carries on from the state it finds. Replays: create is never repeated; a timeout after the `POST` is an unknown outcome and the next pass finds the invoice by its reference. Pennylane enforces idempotency only for supplier-invoice imports [documented], so the reference search is the only protection. Weakness: a person editing `external_reference` of a draft.

## 7. Order flow, taxes, currency and the total check

1. Refuse a currency other than `EUR` (Pennylane's multi-currency fields could not be confirmed, section 16), an order without lines and an email-less order.
2. `findExisting`; customer (section 8); product lookups (unknown SKU: SYNC-MAP-001, or with `unknown_sku = custom` a free-text line).
3. Taxes. The tax mapping code is a Pennylane VAT code (`FR_200`, `exempt`, ...). `FR_200` (20 %), `any` (not tied to a rate; shown as N/A in red in the Pennylane interface) and `exempt` are [documented]; the other French codes offered by `listTaxCodes` are labelled "to be confirmed" (section 16). A code Pennylane refuses answers 422; a message that names the VAT code is SYNC-MAP-002. Prices are sent net: when the payload prices include tax, the net unit price is derived with whole-number arithmetic (the rate comes from the tax mapping row); a line whose rate the code does not state (any code that is not `FR_nnn` or `exempt`) is refused with SYNC-VAL-002 unless the mapping supplies the rate in the field `lines[].tax_rate` (percent; `shipping.tax_rate` and `fees[].tax_rate` for those rows).
4. `POST /customer_invoices` with `draft` true. Read it back (`GET /customer_invoices/{id}`): `currency_amount` (with tax), `currency_tax`, `currency_amount_before_tax`, `status`, `invoice_number` [documented in the use-case example response].
5. Total check: `currency_amount` against `total - excluded_total` in minor units. WooCommerce rounds the tax of each line and Pennylane the tax of each rate group, so the default tolerance is one minor unit per document row (lines, shipping, fees), at most 10 (ten lines of 0.99 EUR at 20 % differ by 2 cents); the option `total_tolerance` (0 to 50; empty means the default) replaces it. More: option `total_mismatch` `fail` (default): the item fails with SYNC-VAL-002 naming both totals and the draft stays in Pennylane for a person to delete (no delete endpoint was documented); the next pass finds that draft by its reference, re-checks it and never makes a second one. `warn` goes on.
6. Finalised mode: after a passing check the draft is finalised by `PUT /customer_invoices/{id}/finalize` [inferred path; the 2026 guide names "finalizing invoices" as an operation]. If the call answers 404 or 405 the invoice stays a draft and the item fails with SYNC-VAL-002 saying so; nothing is issued by accident. The state is read back.
7. The invoice date is the order date (local day, Europe/Paris). The read-back `date` is compared with the day sent, after creation and again after finalising; if it is another day the result carries the warning `date_differs`. The date is never written back.

## 8. Customers

Pennylane's customer filter fields are `id`, `customer_type`, `ledger_account_id`, `name` (prefix only), `external_reference` and `reg_no`; **email is not filterable** [documented]. `lookupCustomer( email )` therefore searches `external_reference = WC-C-<hash>` (exact [documented]), which finds customers this connector created, not ones the merchant typed by hand; a returning buyer who is already a hand-made customer appears twice. The hash is the first 16 hex characters of the SHA-256 of the customer type (`individual` or `company`) and the lower-case email, because a company customer and an individual customer are different resources and updating through the wrong endpoint corrupts the record [documented]: the same email as a person and as a company gives two customers. `lookupCustomer` tries the individual reference first, then the company one. Policies (option `customer_policy`): `match_or_create` (default), `collective` (every order on one existing customer, id in `collective_customer_id`; the buyer's name goes into the invoice label of the first line), `always_create`. The customer is never updated.

## 9. Refunds

Not sent. `POST /customer_invoices` is documented as the endpoint that creates "a draft or finalized customer invoice or credit note", `GET /customer_invoices` filters credit notes with `credit_note`, and `POST /customer_invoices/{id}/link_credit_note` (body `credit_note_id`) links an existing credit note to an invoice [documented]. The request body of a credit note (a flag, negative lines, a reference to the original) is not in anything that could be read, so the connector does not guess it. The connector declares no refund entity, so the queue never offers it a refund; a refund in WooCommerce is entered by hand in Pennylane. When the body is confirmed, adding the refund flow is a small change (section 16).

## 10. Payments

Not recorded. Pennylane documents payment matching by a `transaction_reference` object on the invoice (bank label, Stripe or GoCardless ids) and a read of matched transactions; no endpoint that books a payment on an invoice is listed [documented, `automating-payment-matching`, `supported-use-cases`].

## 11. Stock, tracking, invoice read-back, reconciliation

- Stock, tracking: none documented. Not flagged.
- Invoice read-back (`InvoiceReadback`): `GET /customer_invoices/{id}`: `invoice_number`, `status` (`draft`, `imported`, other values passed through), totals. `authority_status` empty (French e-invoicing behaviour could not be confirmed); `pdf_url` empty; `issued_at` the invoice date for a non-draft.
- Reconciliation (flagged): `GET /customer_invoices` with the filter `date gteq <from>` (and `credit_note eq false`), cursor-paged, keeping items whose `external_reference` is `WC-` and digits; totals from `currency_amount`. Refunded amount is 0 (refunds are not synced).
- `ShadowLookup`: flagged (the reference search is read-only).

## 12. Options (connection config, `options` block)

| Key | Values | Default |
| --- | --- | --- |
| `invoice_state` | `draft`, `finalized` | `draft` |
| `customer_policy` | `match_or_create`, `match_only`, `always_create`, `collective` | `match_or_create` |
| `collective_customer_id` | Pennylane customer id | empty |
| `unknown_sku` | `refuse`, `custom` | `refuse` (set `custom` when you keep no product list in Pennylane) |
| `total_mismatch` | `fail`, `warn` | `fail` |
| `total_tolerance` | 0 to 50 minor units, or empty | empty: one per document row, at most 10 |
| `payment_days` | 0 to 90 | 30 |
| `sales_ledger_account` | ledger account id | empty |
| `shipping_ledger_account` | ledger account id | empty |
| `customer_invoice_template_id` | template id | empty |
| `timezone` | PHP time zone | `Europe/Paris` |

## 13. Rate limits and pacing

25 requests every 5 seconds per token, in production and sandbox, all endpoints [documented]. Over the limit: 429 with `retry-after` (seconds), `ratelimit-limit`, `ratelimit-remaining`, `ratelimit-reset` (Unix time) [documented]. `rateLimitHints()` returns 4.0 requests per second (a margin under 5), concurrency 1. `ping()` is one call, `GET /me` (it needs no scope). 429 → SYNC-RATE-001 with the `retry-after` value.

## 14. Error mapping

Error bodies: `{ "error": "<code>", "message": "...", "details": {...} }` and, for a duplicate supplier invoice, `{ "status": 409, "error": "<sentence>" }` [documented].

| Situation | SYNC code | Notes |
| --- | --- | --- |
| Timeout, reset, name not resolved | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | |
| 401 | SYNC-AUTH-001 | token missing, invalid or expired; a message containing "expired" is SYNC-AUTH-005 |
| 403 "Missing scope: X" | SYNC-AUTH-004 | the message names the scope X |
| 404 | SYNC-VAL-002 | a record of another company or deleted; `GET /customer_invoices/{id}` after create is retried once |
| 400 | SYNC-VAL-002 | malformed JSON, wrong types, amounts not strings (a bug in the plugin; says so) |
| 422 | SYNC-VAL-002 | totals or VAT mismatch, invalid VAT code (SYNC-MAP-002 when the message names the code), missing fields, duplicate reference; `details.field` and `details.issue` kept, short |
| 409 | SYNC-VAL-002 | |
| 429 | SYNC-RATE-001 | `retry-after` |
| 500 | SYNC-NET-002 | retry with the queue's delays |

There is no documented plan-gate answer, so SYNC-AUTH-006 is not produced. Secrets: the token is scrubbed; company ids and customer names stay out of messages.

## 15. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | true | Customer invoices |
| `customers` | true | Company and individual customers |
| `refunds` | false | Credit-note body not verified (section 9) |
| `stock_in`, `tracking_in`, `prices_in` | false | Not available |
| `invoice_readback` | true | Number and status |
| `reconciliation` | true | List filter by date, reference in the item |
| `webhooks` | false | Beta; the plugin polls |
| `shadow_lookup` | true | Read-only search |

Entities sent: order, customer. Capability record: `order_document` `invoice`, `refund_document` `none`, `stock_read` `none`, `tracking_read` `no`, `customer_lookup_by_email` `no` (reference instead), `product_lookup_by_sku` `yes` (product `reference`), `reference_field` `external_reference`, `native_idempotency` `none`, `webhooks` `none`, `rate_limit` 25 per 5 seconds per token, `requires` "Pennylane Essential plan or higher, and an administrator".

## 16. Not verified

- **Legal.** The API contract terms say the licence covers "solely your internal business purposes" (section 2.1), forbid actions that put Pennylane API components under open-source licence terms (3.4 l), grant Pennylane an exclusive, perpetual licence to "integrations" and require the source code on request (4.3), and let Pennylane refuse a Client ID (3.1) [documented, summary of `page/api-contract-terms`, not legal advice]. Whether these apply to a free GPL plugin that each merchant points at their own company with their own company token, and whether Pennylane accepts company tokens in production for that, is a question for the owner and Pennylane before this connector is released. The guide text "a Company API token works only in sandbox" is written for partner integrations.
- The request body of `POST /customer_invoices` is known only from the use-case guide's example (fields in section 5). Not read: `currency`, `language`, discount, `special_mention`, PDF text fields, the exact shape of a credit note, whether `deadline` is required, the `unit` values.
- The response fields of list items (`external_reference`, `currency_amount`, `status`, `invoice_number`, `date`) beyond the use-case example.
- The path of the finalise call (`PUT /customer_invoices/{id}/finalize` is inferred), whether a draft can be deleted or updated.
- The individual-customer create path and the customer create bodies.
- The `/me` response shape beyond "active scopes" (so a missing scope is a warning on the test, not a failure).
- When a draft invoice gets its number (assumed: at finalisation; a draft's number is read as empty).
- VAT codes other than `FR_200`, `any`, `exempt`; the codes offered with the label "to be confirmed" (`FR_100`, `FR_55`, `FR_21`) are recalled. Foreign VAT, OSS and reverse-charge codes.
- Whether `raw_currency_unit_price` is net (assumed) and how many decimals it takes.
- Per-attribute string limits.
- French e-invoicing (the reform dates are recalled in the research only) and its effect on finalised invoices.
- Multi-currency (EUR only here).
- What happens when a token reaches its end date (401 expected).
- The privacy policy (fetched, dated 30 January 2026) was read only to confirm what it is, not in full.

## 17. Manual smoke test (real company or sandbox, no secrets in the repository)

1. Create a sandbox company (profile menu, Test environment) and configure the invoice settings (company details, bank details, logo, sequential numbering [documented]). Create a read and write token with the three scopes.
2. Add the connection with the lifetime you chose. Expected: Test connection shows the scopes; a token without `customer_invoices:all` is listed as a missing permission and the first order sent with it gives SYNC-AUTH-004 naming the scope; a wrong token SYNC-AUTH-001; an expired one SYNC-AUTH-005. The Connections screen shows the end date.
3. Create a product with reference `TEST-1`. Place an order for `TEST-1` with a French address and 20 % VAT (tax mapping `FR` 20 to `FR_200`). Expected: a customer `WC-C-…`, a draft invoice with external reference `WC-{id}` and the shop's total. Run the sync again: no second invoice.
4. Switch to `finalized`, place an order: the invoice is finalised after the check (if the finalise call fails, the draft stays and the log says so).
5. Order with prices including tax and with a coupon: the totals match; an order in another currency is refused.
6. Run the nightly reconciliation and compare.
7. Refund an order in WooCommerce: nothing is sent; enter the credit note in Pennylane by hand.
