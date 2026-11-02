---
id: sage-accounting
name: Sage Business Cloud Accounting
catalog_row: Sage Business Cloud Accounting and Sage 50
connection_method: own_app
available: true
verified: false
hosts: [api.accounting.sage.com, oauth.accounting.sage.com, www.sageone.com]
terms_url: https://www.sage.com/en-gb/legal/                                 # Sage's legal hub; the URL is in the search index of sage.com on 2026-11-02, but sage.com refuses automated fetches, so the content was not read: the owner confirms it
privacy_url: https://www.sage.com/en-gb/legal/privacy-and-cookies/           # same note: the page was not read, the owner confirms it
developer_url: https://developerselfservice.sageone.com/
variants: [{id: sage-accounting, label: Sage Business Cloud Accounting}, {id: sage50-export, label: Sage 50 (export file)}]
---

# Sage Business Cloud Accounting connector

Connector id `sage-accounting`, type accounting, contract version 1. Code: `includes/Connectors/SageAccounting/`. Registration: `SageAccountingProvider`. This is the API half of the catalog row "Sage Business Cloud Accounting and Sage 50"; Sage 50 is a desktop program and is handled by the separate export connector `sage50-export`.

The connector uses the Sage Accounting API version 3.1 (UK, Ireland, Canada, Spain and France editions). It sends WooCommerce orders as sales invoices, optionally records the payment, sends refunds as sales credit notes, finds or creates the customer contact, and reads invoice facts and a reconciliation list back. The merchant uses their own Sage developer app.

Tags: `[documented]` official Sage page (URL, fetched 2026-11-02), `[third-party]`, `[inferred]`. Nobody has run this against a live business.

## 1. Sources

| Source | Used for |
| --- | --- |
| https://developer.sage.com/accounting/guides/authenticating/authentication/ | authorize and token URLs, scopes `readonly` and `full_access`, parameters (`filter=apiv3.1`, `country`, `state`, PKCE), token lifetimes (300 seconds, 2,678,400 seconds), client credentials in the form body, refresh, revoke, `X-Business`, token errors |
| https://developer.sage.com/accounting/guides/concepts/overview/ | v3.1 recommended, 1,296,000 requests a day and 150 concurrent per app, 429, `page` and `items_per_page`, `updated_or_created_since`, trial accounts (30 days, one per region, no sandbox) |
| https://developer.sage.com/accounting/guides/concepts/best_practices | page size up to 200, avoid parallel POST to contacts, products and sales documents, an HTTP 500 on POST may have succeeded, log `x_request_id`, 403 and 422 handling, caching of static endpoints |
| https://developer.sage.com/accounting/guides/concepts/idempotency | `idempotency_id`, 32-character hyphenless GUID, in the body, 7 days, same request returns the same result, `IdempotencyResourceMismatch`, the 36 resources that support it (contact, contact_allocation, contact_payment, sales_credit_note, sales_invoice, ...) |
| https://developer.sage.com/accounting/guides/concepts/invoicing/ | `POST /sales_invoices`, required fields, line fields, status values and transitions (`release`), decimals as strings, `tax_amount` rules, shipping fields, void, allocations |
| https://developer.sage.com/accounting/guides/concepts/payments/ | `POST /contact_payments` receipt and refund bodies, `allocated_artefacts`, `reference` up to 25 characters, no payment on account for Accounting Start |
| https://developer.sage.com/accounting/guides/concepts/contact_and_address_relationship | `POST /contacts` body (`contact_type_ids`, `name`, `reference`, `main_address`) |
| https://developer-community.sage.com/topic/846-how-to-link-invoice-and-credit-note-using-sage-api/ [community] | credit notes are linked to invoices with `POST /contact_allocations` (`CUSTOMER_ALLOCATION`, invoice positive, credit negative) |
| https://www.withone.ai/knowledge/sage-accounting/... (contacts list and contact persons) [third-party, generated from Sage's API description] | query parameters of `GET /contacts` (`search`, `email`, `contact_type_id`, `attributes`, `items_per_page`, `page`); plans (Accounting Start, Standard and Plus) and role needs |
| https://developer.sage.com/accounting/apis/sagebusinesscloudaccounting/3.1.0/accounting.md | the single server `https://api.accounting.sage.com/v3.1`; `/businesses` |
| Research in the repository: `docs/research/integration-research-vendors.md` section 4.9 | v3 retirement 31 July 2026, regions |

The reference pages of the individual endpoints (`developer.sage.com/accounting/reference/...`) were not on the fetch allow-list, so list envelopes and many field names below are `[inferred]` from the guides and are repeated under "Not verified".

## 2. How the merchant connects

1. Create a free developer account at `developer.sage.com`, open the self-service portal and add a new app.
2. Add the Redirect address shown here as the callback URL of that app and save it.
3. Copy the app's Client ID and Client secret into the two fields here and choose your country.
4. Press Sign in with Sage, sign in as the owner or a full-access user of the business and approve full access.
5. Choose the business to sync, then press Test connection.
6. In Sage note the nominal code of your sales account and enter it under the options; map each tax rate in Tax mapping.

Gates: the developer account is self-service and the API is free [research, documented]. The signed-in user needs a role with access to Contacts, Sales and Banking (as needed); a user without it gets 403 [documented]. Sage has no sandbox: a trial business lasts 30 days and must be created per region [documented], so a trial is the only way to test. The country chosen at sign-in must match the region of the business or authorization fails [documented]. Accounting Start allows invoices and payments but no payment on account [documented], which this connector never needs because every payment is fully allocated.

## 3. Authentication

OAuth 2 authorization code with the merchant's own app.

| Item | Value |
| --- | --- |
| Authorize | `https://www.sageone.com/oauth2/auth/central?filter=apiv3.1` with `client_id`, `response_type=code`, `redirect_uri`, `scope=full_access`, `state`, `country`, PKCE `code_challenge` (S256) [documented] |
| Token and refresh | `POST https://oauth.accounting.sage.com/token`, form body with `client_id` and `client_secret` in the body (not a Basic header), `grant_type`, `code` / `refresh_token`, `redirect_uri`, `code_verifier` [documented] |
| Code | single use, valid 60 seconds [documented] |
| Scope | `full_access` (`readonly` cannot write) |
| Access token | 300 seconds; refreshed by the engine |
| Refresh token | 2,678,400 seconds (31 days), a new one with every refresh, which the engine stores; idle for 31 days means signing in again [documented] |
| Business | `GET https://api.accounting.sage.com/v3.1/businesses` lists the businesses of the signed-in user [documented path]; the chosen id is stored as `business_id` and sent as `X-Business` on every call [documented]. Without the header Sage uses the lead business, which this connector never relies on |
| API host | `https://api.accounting.sage.com/v3.1`; no regional API hosts are documented (the regions differ in the sign-in `country`) |

Stored: client id (config), client secret and tokens (encrypted), `country`, `business_id`, `business_name` (config). The business id is removed from every message and log line.

## 4. Data sent and when (for the WordPress.org "External services" disclosure)

| Host | When | Data |
| --- | --- | --- |
| `www.sageone.com` | the person presses Sign in (their browser is sent there) | client id, redirect address, scope, country, state, PKCE challenge |
| `oauth.accounting.sage.com` | after sign-in and before an access token ends | client id and secret, the one-time code and verifier or the refresh token |
| `api.accounting.sage.com` | test connection, each queued order, refund and customer, scheduled reads | business list and facts (read); contact name, email, address; invoice lines (product, description, quantity, unit price, tax rate, tax amount), shipping, totals, dates, the shop's reference `WC-{id}`; payments, credit notes, allocations; reads of tax rates, ledger accounts, products, invoices |

Nothing is sent to Estasoft or anyone else.

## 5. Connection settings

Credential fields: `client_id`, `client_secret`, `country` (gb, ie, ca, es, fr), `business_id` (advanced; filled in by sign-in), the redirect address (display only). The United States edition is not offered: Sage's overview lists older API versions for it [documented].

Options (`SageAccountingOptions`):

| Key | Values | Default |
| --- | --- | --- |
| `sales_ledger_account` | nominal code or id of the income account for every line | empty (orders are refused, naming this option) |
| `fee_ledger_account`, `discount_ledger_account` | per kind of line | `sales_ledger_account` (shipping uses Sage's own shipping fields, which take no account) |
| `no_tax_rate` | id of the tax rate for a line the shop did not tax (an empty `tax` key) | the rate whose id ends in `_NO_TAX`; if there is none such a line is refused, naming this option |
| `documents` | `invoice` (released), `invoice_draft` (left as a draft), `invoice_paid` (released and paid) | `invoice` |
| `payment_bank_account` | id or name of the bank account that receives payments (needed for `invoice_paid`) | empty |
| `refund_allocation` | `none` or `invoice` (apply the credit to the invoice's open amount) | `none` |
| `unknown_sku` | `refuse` (a SKU Sage does not have, or a line without any SKU, is SYNC-MAP-001) or `free_text` (a line without `product_id`) | `refuse` |
| `due_days` | 0 to 365 | 0 |
| `total_mismatch` | `fail` (delete the draft and stop) or `warn` | `fail` |

## 6. Objects and field mapping

Payload paths follow the Odoo connector's names.

| Payload path | Sage field |
| --- | --- |
| `client_order_ref` (always the item's `WC-{id}`) | `sales_invoice.reference` |
| `date_order` | `date` (YYYY-MM-DD); `due_date` = date + `due_days` |
| `currency` | must equal the business currency; otherwise SYNC-VAL-002 (foreign-currency documents are not built because the exchange-rate rules are not documented) |
| `prices_include_tax` | supported, per country (section 9): in the UK, Ireland and France each line carries `unit_price_includes_tax: true` and Sage works the tax out; in Canada and Spain the connector converts to net itself |
| `note` | `notes` |
| `total`, `excluded_total` | not sent; the expected total for the check |
| `partner.name`, `email`, `company_name` | contact `name` (company if given, 200), `email`, `reference` (the email when it is 50 characters or fewer, so the next search finds the contact) |
| `partner.street`, `city`, `state_id`, `zip`, `country_id` | contact `main_address` (`address_type_id` `ACCOUNTS`, `address_line_1`, `address_line_2`, `city`, `region`, `postal_code`, `country_group_id`) |
| `order_line[].product` | `invoice_lines[].product_id` (product found by `item_code`) |
| `order_line[].name`, `quantity`, `price_unit`, `discount` | `description`, `quantity`, `unit_price` (decimal strings), `discount_amount` |
| `order_line[].tax` | `tax_rate_id`; `tax_amount` is calculated by the connector (section 9) |
| `shipping.total`, `shipping.tax` | `shipping_net_amount`, `shipping_tax_rate_id`, `shipping_tax_amount` [documented fields]; a shipping tax key that is missing or empty uses the no-tax rate |
| `fee[]`, `discount.*` | extra lines (quantity 1) on the fee and discount ledger accounts; a discount has a negative `unit_price` and a negative `tax_amount` [inferred: negative lines are not shown in the guides] |
| `order_line[].discount` (percent) | `discount_amount`, worked out in whole minor units; the tax is on what is left |
| `payment.reference`, `payment.date` | `contact_payment.reference` (25), `date` |

Refunds: `order_ref`, `date`, `reason`, `amount`, `prices_include_tax`, `refund_line[]`, `shipping.*` become the credit note.

## 7. Flow of an order

1. Check the payload before any request: customer, date, product lines, whole-number minor units, a sales ledger account option.
2. Find the invoice: `GET /sales_invoices?search=WC-{id}` with `items_per_page=200` and all pages, then an exact comparison of `reference` [search syntax inferred]. A voided invoice (status `VOID`) stops the item with SYNC-VAL-002.
3. If none: read the base currency (`GET /financial_settings`, `base_currency.id`; a different order currency is SYNC-VAL-002), resolve the tax rates (`GET /tax_rates`, cached, retired rates left out), the ledger accounts (`GET /ledger_accounts`, matched on `nominal_code` or `id`), products (`GET /products?search={sku}`, exact `item_code`, retired products unknown), build the body, and only then the contact (below). Unknown SKU stops with SYNC-MAP-001; an unknown tax rate with SYNC-MAP-002; an unknown ledger account or a line with no tax key with SYNC-VAL-002; all before the contact or the invoice is created.
4. Create the invoice as a draft: `POST /sales_invoices` with `status_id` `DRAFT` and `idempotency_id` (section 8).
5. Compare the draft's total with `total - excluded_total` (one minor unit allowed; with prices that include tax, one minor unit per line, shipping included, because every line is rounded on its own). The invoice currency is compared too. A difference is SYNC-VAL-002 naming both totals. What happens to the draft then:
   - With `total_mismatch = fail` and a draft made by this pass, the connector asks Sage to void it: `DELETE /sales_invoices/{id}` with the body `{"void_reason": "Created with the wrong total by the WooCommerce sync"}` [the invoicing guide documents `DELETE` with a `void_reason` for an invoice that is `UNPAID`; whether Sage accepts it for a `DRAFT` is not documented].
   - Sage's refusal (a 4xx) is not swallowed and not turned into a claim. The draft is read again, and the failure is reported as "removed" only when Sage no longer has it or shows it as `VOID`. Otherwise the same SYNC-VAL-002 gets the note "The draft invoice WC-{id} is still in Sage (nothing is posted for a draft). Delete it in Sage, then retry."
   - A draft found by a later pass with a wrong total is never touched by the connector: that pass fails with the same note, so a person decides.
   - If the draft is gone or `VOID` when it is checked or released (the replayed answer of an earlier create named a draft that was voided), the connector creates again with a new `idempotency_id` (section 8); three rounds at most.
6. Release it (`POST /sales_invoices/{id}/release`) unless `documents = invoice_draft`.
7. `invoice_paid` with a payment date: `POST /contact_payments` (`CUSTOMER_RECEIPT`, the bank account, `total_amount` = the invoice's outstanding amount read just before, one allocation to the invoice, `reference` `WC-{id}`, `idempotency_id`). If nothing is outstanding nothing is created.
8. Result: external id = invoice `id`, reference = `invoice_number` (the `reference` while still a draft), `extra` = total in minor units, status.

Replays: create and payment run once per pass. An HTTP 500 or a time-out on a POST may have succeeded [documented], so the pass ends and the next one starts at step 2; if the search does not show the first invoice yet, the retry sends the same `idempotency_id` with the same body and Sage replays the first answer instead of making a second invoice (section 8). Release is safe to repeat after a read.

Contacts: `GET /contacts?search={email}&contact_type_id=CUSTOMER&attributes=email,reference` (all pages); an exact case-insensitive match on `email` or `reference` is used; none: `POST /contacts` (`contact_type_ids` `["CUSTOMER"]`, `name`, `email`, `reference`, `main_address`) with an `idempotency_id`. Sage's `email` filter looks at contact people only [third-party], so the connector searches by `search` and compares itself.

## 8. Duplicate protection

- Reference field: `reference` on `sales_invoice` and `sales_credit_note` (`WC-{order id}`, `WC-{refund id}`), searched before every create.
- `idempotency_id`: 32 hex characters (md5 of connection id, reference, step, the ids of the documents of this reference that the connector voided itself, and the body), in the resource's object, kept 7 days, replaying the first answer for the same request [documented]. The queue item's `attempt` is not in it: a retry after a time-out sends the same id with the same body and gets the first result, which matters because Sage references are not unique. A different body gets a different id, so a corrected order is not blocked. The id changes after the connector voided its own document: the search lists `VOID` rows whose `void_reason` is the connector's own text, and their ids go into the hash, so a create after that void is not handed the answer about the voided document (a document voided for another reason stops the item instead). The credit note and payment ids follow the same rule. A reused id on another resource type is `IdempotencyResourceMismatch` and shows as SYNC-VAL-002.
- `findExisting()` returns an invoice only when it is finished for the connection's settings (released, and paid for `invoice_paid`); a draft returns null so `send()` finishes it.

## 9. Taxes

Tax codes are Sage tax rate ids (for example `GB_STANDARD`). `listTaxCodes()` reads `GET /tax_rates` (id, name, percentage). Sage does not work the tax out for you: `tax_amount` is sent by the caller, calculated as `net_amount x percentage / 100` rounded to two decimals, and must be left out for zero, no-tax and exempt rates [documented]. The connector calculates it in whole numbers (cents, the rate in ten-thousandths, half away from zero) per line, and the same for shipping. The total check compares the draft with the shop's total, so a rounding difference over the allowed units is reported, not hidden.

Prices that include tax (`prices_include_tax`). The invoicing guide shows `unit_price_includes_tax` on invoice lines [documented in the guide's examples; as a request property it is in a third-party API description, and a community answer says it works for the UK, Ireland, the US and France, so it is not relied on elsewhere]. By country of the business:
- United Kingdom, Ireland, France: each line is sent with `unit_price` as the shop's price (tax included), `unit_price_includes_tax: true`, and no `tax_amount`; Sage works the tax out. A percentage discount becomes `discount_amount` on the tax-inclusive line amount.
- Canada, Spain: the connector converts to net itself with integer arithmetic. Rule: line amount (after any discount) in minor units, `net = round_half_away_from_zero(gross x 1,000,000 / (1,000,000 + rate))`, the rate in ten-thousandths of a percent (20.00 percent is 200,000). If `net + tax(net)` does not give the gross back, the neighbour `net - 1`, then `net + 1`, that does is taken; if none does, the rounded net stays and the line is one minor unit off. `unit_price` is the net divided by the quantity when it divides exactly, otherwise the line is sent with quantity 1 and the whole net as its price, and the quantity is added to the description. In Canada `tax_amount` is worked out on the net as before; in Spain no `tax_amount` is sent at all.
- Shipping has no such flag in the documented fields, so its amount is always converted to net, in every country.
- After the create the draft's total is compared with the shop's: one minor unit per line is allowed (the rule above can leave a line one unit off), and a larger difference is the usual mismatch failure. Refunds follow the same rule per credit note line.

## 10. Payments

Off unless `documents = invoice_paid` and `payment_bank_account` is set. Needs a released invoice (drafts take no payments [documented]).

## 11. Refunds

A refund finds the order's invoice, takes its contact, and creates `POST /sales_credit_notes` with `credit_note_lines` (same line fields as invoices), `reference` `WC-{refund id}` and `notes` `Refund of WC-{order id}` plus the reason, checking the total against `amount`. Amounts with tax in them are sent as for orders (section 9); an amount-only refund is one line with the no-tax rate. Sage has no draft credit note, and the guides document no way to delete or void one [unverified]. So a credit note with a wrong total is handled like this: the connector calls `DELETE /sales_credit_notes/{id}` with the body `{"void_reason": "Created with the wrong total by the WooCommerce sync"}` once, reads the credit note again, and says it was removed only if Sage no longer has it or shows it as `VOID`. Otherwise the item fails with SYNC-VAL-002 naming both totals and the note "The credit note WC-{id} is still in Sage and could not be removed by the connector. Void it in Sage, then retry." The wrong credit note is never accepted: every pass that finds an existing credit note compares its total again (without a second `DELETE`), so a retry fails the same way until the person has voided or corrected it, and it is never counted as done. A credit note voided with the connector's own reason does not stop a later try (a new one is created with a new `idempotency_id`), while one voided for another reason, or with no reason shown, was a person's choice and stops the item. With `refund_allocation = invoice`, the connector then reads the invoice's open amount and, if it is above 0, creates `POST /contact_allocations` (`CUSTOMER_ALLOCATION`, the invoice with a positive and the credit note with a negative amount of the smaller value) [documented in the community answer and the guide]. Every pass reads first and does only the missing step.

## 12. Reading from Sage

- `fetchInvoice`: invoice number, status (`DRAFT`, `UNPAID`, `PART_PAID`, `PAID`, `VOID`), date; no tax-authority status; no PDF link (the PDF needs the token).
- `listForReconcile`: invoices from the date on (`from_date`), all pages, those with a `reference` of the form `WC-{number}` that are not void; `total_minor`, and `refunded_minor` added up from the credit notes of the same window whose `notes` start `Refund of WC-{number}` [the list rows are assumed to carry `notes`]. The invoice list carries no email, so `email` is empty.
- Stock: Sage Accounting documents stock movements but no stock level read the research could confirm, so `stock_in` is off and `fetchStock` returns nothing. Tracking, prices, webhooks: none.
- `searchProducts`: `GET /products?search={text}`, at most 25.

## 13. Rate limits and pacing

The circuit breaker's health probe (`ping()`) is one `GET /businesses` (a page of up to 200), made fresh every time; it is false on any failure. It does not need a chosen business.

1,296,000 requests a day and 150 concurrent per app, shared by the user's businesses; 429 when exceeded [documented]. Sage asks not to POST contacts, products and sales documents in parallel [documented], so the connector runs one call at a time, at most 3 a second. A 429 waits for `Retry-After` or 30 seconds.

Token refresh: Sage's access token lives 300 seconds. The engine's margin follows the token's life (half of it for a short token), the connector sets `refreshOnDemand`, so the scheduled task leaves the token to the next call and only keeps the 31-day refresh token from running out. Whether Sage invalidates the old refresh token the moment a new one is issued is not stated; the engine stores the new one under a lock.

## 14. Error mapping

Sage's error list: `[{"$severity":"error","$dataCode":"...","$message":"...","$source":"..."}]` [inferred from the idempotency page, which names severity, data code and message].

| What Sage answers | SYNC code |
| --- | --- |
| 401 | SYNC-AUTH-002 after one refresh |
| 403 | SYNC-AUTH-004 (the user lacks a role in the business) |
| 400/422 with `$message` (for example "can't find customer"); the list is read as a top-level list or under `$errors` | SYNC-VAL-002 with the messages and the first `$source` |
| `IdempotencyResourceMismatch` | SYNC-VAL-002 |
| 404 on a read | normal answer |
| 429 | SYNC-RATE-001 |
| 5xx, time-out, reset | SYNC-NET-002 / SYNC-NET-001 |
| certificate failure | SYNC-NET-003 |
| token endpoint `invalid_grant` / `invalid_client` | handled by the engine (SYNC-AUTH-002 / SYNC-AUTH-001) |
| unknown SKU, `refuse` | SYNC-MAP-001 |
| tax rate not in Sage (or retired) | SYNC-MAP-002 |
| currency differs, a line without a tax key, totals differ (also when the wrong document could not be removed), no or unknown ledger account, no or unknown bank account | SYNC-VAL-002 |

## 15. Capabilities

| Flag | Value | Why |
| --- | --- | --- |
| orders, customers, refunds | yes | sales invoices, contacts, credit notes |
| stock_in | no | only stock movements are documented |
| tracking_in, prices_in, webhooks | no | no delivery object, not built, no webhooks |
| invoice_readback | yes | number, status |
| reconciliation | yes | paged by date |
| shadow_lookup | yes | `findExisting()` only reads |

## 16. Not verified

- The envelope of list answers (`$items`, `$next`, `$total`), the `status`, `total_amount`, `outstanding_amount`, `invoice_number` and `currency` fields of the invoice answer, and the fields of businesses, tax rates (`percentage`), ledger accounts (`nominal_code`) and products (`item_code`): the endpoint reference pages could not be fetched.
- `search` on `/sales_invoices` and `/products`, `from_date` on invoices, and the `financial_settings` field that holds the base currency.
- `status_id` `DRAFT` on create and `POST .../release` (documented as transitions; the request body for create with a draft status was not shown).
- Negative lines (discounts), and whether a discount together with `unit_price_includes_tax` is worked out the way the connector assumes (the discount is taken off the tax-inclusive amount).
- Whether `unit_price_includes_tax` is accepted on a create request (it appears in the guide's examples and a third-party API description; the country list comes from a community answer), and whether it also works on credit note lines. In Canada and Spain the connector converts to net instead; whether Sage Spain refuses `tax_amount` is from the guide, and the connector sends none there.
- Whether Sage accepts `DELETE /sales_invoices/{id}` with a `void_reason` for a `DRAFT` (documented for `UNPAID` only). If not, a wrong draft stays in Sage and the failure says so.
- Voiding a credit note: no page documents `DELETE` or a void for `sales_credit_notes`. The call is made once and its result is read back; the connector never claims a removal it did not see. That a voided credit note is still listed and shows its `void_reason` is also unverified.
- That the list rows of `/sales_credit_notes` carry `notes` (the nightly refund total depends on it) and that `DELETE` of a draft invoice removes it.
- The body of `POST /contact_payments` beyond what the payments guide shows (no `payment_method_id` is sent), and `POST /contact_allocations` as written in the community answer.
- Contact `reference` length, and that a duplicate reference is refused.
- Whether `description` and names have length limits.
- Whether the access token refresh invalidates the old refresh token at once (the page does not say).
- Terms and privacy URLs are left empty: Sage's UK privacy page could not be fetched (the site refused the request) and no page with the API terms was found. The developer-portal URL is the usual Sage address and was not opened.
- No live business was available. Behaviour above the wire is tested against `FakeSageAccountingServer` and fixtures written from the guides.

## 17. Manual smoke test

1. Create a Sage developer account and an app with the plugin's redirect address, and start a trial business for your region.
2. Paste the key and secret, choose the country, sign in, pick the business, press Test connection. Expected: business name, country, currency, tax rates found.
3. Enter the nominal code of a sales account. Place an order for a product whose `item_code` equals the SKU: one released invoice, reference `WC-{order id}`, the shop's date, the right total with tax. Retry: no second invoice.
4. `invoice_draft`: a draft with no number. `invoice_paid` with a bank account: the invoice shows paid.
5. Refund with `refund_allocation = invoice`: a credit note allocated to the invoice.
6. Place an order with `prices_include_tax` mapped on (UK, Ireland or France business): the draft's total equals the shop's and each line shows the tax Sage worked out. In a Canadian or Spanish business the lines are sent net and the total still equals the shop's.
7. Map a tax rate whose percentage differs from the shop's, with `total_mismatch = fail`, and place an order: the item fails with the two totals, and the note says whether the draft was voided or is still in Sage.
