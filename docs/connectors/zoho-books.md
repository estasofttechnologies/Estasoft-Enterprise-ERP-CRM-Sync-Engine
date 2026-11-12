---
id: zoho-books
name: Zoho Books
catalog_row: Zoho Books
connection_method: own_app
available: true
verified: false
hosts: [accounts.zoho.com, accounts.zoho.eu, accounts.zoho.in, accounts.zoho.com.au, accounts.zoho.jp, accounts.zoho.sa, accounts.zohocloud.ca, accounts.zoho.com.cn, www.zohoapis.com, www.zohoapis.eu, www.zohoapis.in, www.zohoapis.com.au, www.zohoapis.jp, www.zohoapis.sa, www.zohoapis.ca, www.zohoapis.com.cn]
terms_url: https://www.zoho.com/terms.html   # Zoho Terms of Service (general terms for all Zoho services); the old address /books/terms.html returned 404 on 12 Nov 2026
privacy_url: https://www.zoho.com/privacy.html
developer_url: https://api-console.zoho.com
variants: []
---

# Zoho Books connector

Connector id `zoho-books`, type accounting, contract version 1. Code: `includes/Connectors/ZohoBooks/` with the sign-in and data-centre code shared with Zoho CRM in `includes/Connectors/Zoho/`. Sends WooCommerce orders as invoices (optionally with a payment), refunds as credit notes and customers as contacts; reads an invoice's status back. Stock and tracking are not read (Zoho Books documents no per-item stock or shipment tracking in the pages checked).

## 1. Sources

Fetched 2026-11-02. **The Zoho Books API pages (zoho.com/books/api/v3/...) are rendered by script: WebFetch returned only the navigation, no object pages.** Everything about Books objects is therefore `[third-party]` (Apideck, Truto and unified.to guides, CData and Databricks connector references, Zoho's Deluge help) or `[inferred]` from the Books API as I know it, and is listed under "Not verified". Zoho CRM's static developer pages were read and give the shared OAuth facts.

| Page | Used for |
| --- | --- |
| https://zoho.com/books/api/v3/oauth and /introduction/ (navigation only) | Section titles: scopes, steps, token validity, multi data centre, API call limit |
| https://www.apideck.com/blog/how-to-integrate-with-the-zoho-books-api `[third-party]` | Base URL, `organization_id` on every call, scope pattern `ZohoBooks.<resource>.<OP>`, limits and 429 codes 44, 45, 1070, `GET /organizations`, `/contacts`, `/invoices`, `/invoices/fromsalesorder`, no sandbox |
| https://preview.truto.one/blog/how-to-integrate-with-the-zoho-books-api-2026-engineering-guide `[third-party]` | `page`, `per_page` (max 200), `page_context.has_more_page`, `last_modified_time`, no `Retry-After`, `invalid_grant`, 20 refresh tokens, 10 access tokens per 10 minutes |
| https://docs.unified.to/skills/zoho-books-oauth-app/SKILL.md `[third-party]` | 15 scope modules, Canada accounts host, "documentation lists `/billing/` base paths; `/books/v3/` is right", concurrency 5 (Free) / 10 |
| https://cdn.cdata.com/help/GZN/jdbc/pg_table-contacts.htm `[third-party]` | Contact fields; server-side contact filters: `contact_name`, `company_name`, `status`, `first_name`, `last_name`, `email`, `phone`, `address`, `place_of_contact` |
| https://docs.databricks.com/aws/en/ingestion/lakeflow-connect/zoho-books-reference `[third-party]` | Object names and primary keys (`invoice_id`, `creditnote_id`, `payment_id`, `item_id`, `tax_id`), `last_modified_time` |
| https://www.zoho.com/deluge/help/books/update-record.html | An invoice answer: `code: 0`, `invoice_id`, `invoice_number`, `total`, `balance`, `status`, `currency_code`, `line_items[item_id, rate, quantity, tax_id]`, `invoice_url`; error code `6041` for a wrong organisation id and `-1` "Resource does not exist" |
| Zoho CRM static pages (see the Zoho CRM document) | Token endpoints per data centre, `invalid_client`, `invalid_code`, redirect rules |
| https://help.zoho.com/portal/en/community/topic/invoice-api-create-customer-response-on-duplicate?page=9 `[third-party, Zoho community]` | The refusal of a customer name that exists: code `1001`, "The customer "xxxxx" already exists. Please specify a different name." (a Zoho Invoice thread; Books shares the contact engine, so the connector matches the code and the wording) |
| https://hub.windmill.dev/scripts/zoho/21720/list-items-zoho `[third-party]` | The query parameters of the item list as Zoho documents them: `name`, `description`, `rate`, `tax_id`, `tax_name`, `is_taxable`, `tax_exemption_id`, `account_id`, `filter_by`, `search_text`, `sort_column`. **No `sku` parameter.** |
| https://cdn.cdata.com/help/GZK/ado/pg_table-items.htm `[third-party]` | The server-side item filters a connector vendor found: `Name`, `Description`, `Rate`, `TaxId`, `TaxName`, `AccountId`, `Status`; SKU is "executed client-side" |
| https://help.zoho.com/portal/id/community/topic/add-sku-to-query-options-in-items-api-endpoint?page=198 `[third-party, Zoho community]` | A customer asking for SKU as a query option of the items endpoint (no staff answer on the page) |
| https://pipedream.com/apps/zoho-books/actions/create-customer-payment.md `[third-party]` | Payment parameters: `amount`, `invoices[].amount_applied`, `date` (yyyy-mm-dd), `payment_mode`, `account_id`, `reference_number`; each applied invoice shows `balance_amount` |

## 2. How the merchant connects

1. Open the Zoho API Console for your data centre and choose Add Client, then Server-based Applications.
2. Paste the redirect address shown on this screen into Authorized Redirect URIs of the new client.
3. Copy the Client ID and Client Secret into the fields here and choose the data centre your Zoho account uses.
4. Press Sign in with Zoho and approve the access.
5. Under Advanced choose your Zoho Books plan, so the daily call allowance is right (Free is assumed).
6. Choose the Zoho Books organisation if your account has several, then press Test connection.

Plan gate: none; the API is open on every Books plan including Free, but the daily call allowance is 1,000 (Free), 2,000 (Standard), 5,000 (Professional), 10,000 (Premium, Elite, Ultimate), shared with every other integration of the organisation `[third-party]`. The connector counts its own calls and stops at 90 percent of the allowance of the plan chosen in step 5 (900, 1,800, 4,500, 9,000), leaving room for the other integrations; it cannot see their calls. The signing-in user needs rights to create invoices, contacts and credit notes. Books has no sandbox: use a test organisation or a trial `[third-party]`. Country editions fix country, currency and time zone at sign-up.

## 3. Authentication

OAuth 2.0 authorization code with the merchant's own client, same endpoints and parameters as Zoho CRM (`access_type=offline`, `prompt=consent`, secret in the body, comma-separated scopes, no PKCE). Header `Authorization: Zoho-oauthtoken <token>`; the base's bearer strategy is not used. Scopes: `ZohoBooks.contacts.CREATE,READ`, `ZohoBooks.invoices.CREATE,READ,UPDATE`, `ZohoBooks.creditnotes.CREATE,READ,UPDATE`, `ZohoBooks.settings.READ` (organisations, taxes, items `[inferred]`); with option `record_payment` also `ZohoBooks.customerpayments.CREATE,READ`. Credential fields: `data_centre`, `client_id`, `client_secret`; the chosen organisation is stored as config `organization_id` by the sign-in step (the plugin lists `GET /organizations`). Every call carries `organization_id`; a call without a stored id is refused locally.

Options: `plan` (`free` default, `standard`, `professional`, `premium`; a select on the Connect screen), `unknown_sku` (`refuse` default, `free_text`), `invoice_state` (`sent` default, `draft`), `refund_state` (`draft` default, `open`), `record_payment` (off), `payment_account`, `payment_mode` (`Others`), `refund_tax_id`, `total_mismatch` (`fail` default, `warn`); `organization_id` and `organization_currency` are stored by the sign-in step.

## 4. Data sent and when (External services disclosure)

Hosts `accounts.zoho.<dc>` and `www.zohoapis.<dc>` of the chosen data centre, only after sign-in.

| Action | Data sent | Data read |
| --- | --- | --- |
| Sign in / refresh | Client id and secret, code or refresh token | Access token |
| Test connection | Nothing | Organisations, taxes, one contact and invoice and credit-note page |
| Order synced | Buyer name, email, phone, company, billing and shipping address, order number, date, currency, line names, SKUs, prices, quantities, tax codes, shipping, fees, discount, note | Contact by email, items (searched by text, and the item list when the text search does not hold the SKU), invoice by order number, totals back |
| Payment (option) | The invoice balance as amount, the paid date, order number | The invoice balance |
| Refund synced | Refund lines, amount, reason, order number | Credit note by reference |
| Health probe (circuit breaker) | Nothing | The organisation list (one call) |

## 5. Objects and field mapping

Target paths of the mapping (shared by the CRM connectors, `includes/Connectors/CrmCommon/CrmFields.php`): `reference`, `currency`, `date`, `paid_at`, `note`, `total`, `excluded_total`, `prices_include_tax`, `payment.reference`; `contact.email`, `.first_name`, `.last_name`, `.company`, `.phone`, `.street`, `.city`, `.state`, `.postcode`, `.country`; `lines[].sku`, `.name`, `.quantity`, `.unit_price`, `.total`, `.tax`; `shipping.total`; `fees[].name`, `.total`; `discount.total`, `.codes`. Refunds: `order_ref`, `amount`, `currency`, `date`, `reason`. Products: `product.sku`. Money fields are integer minor units. Accounting adds `lines[].tax_code`, `shipping.tax_code` and `fees[].tax_code` (filled by the tax mapping, `tax_lookup`). The connector turns them into Zoho Books fields: `customer_id`, `reference_number`, `date`, `currency_id`, `is_inclusive_tax`, `line_items[item_id, name, description, rate, quantity, tax_id]`, `discount`, `notes`.

| Canonical | Zoho Books |
| --- | --- |
| `WC-123` | `reference_number` of the invoice (searched with the list filter `reference_number`) |
| order date | `date` (`yyyy-MM-dd`; the UTC date) |
| currency | invoice `currency_id` (looked up in `GET /settings/currencies` by code) |
| customer | contact `contact_name`, `company_name`, `contact_type: customer`, `contact_persons[{first_name, last_name, email, phone, is_primary_contact}]`, `billing_address{address, street2, city, state, zip, country}`, `shipping_address`, `currency_id` |
| line | `line_items[{item_id, name, description, rate, quantity, tax_id}]`: with a matching item `item_id` is sent; else, under `unknown_sku = free_text`, only `name`, `description`, `rate`, `quantity` |
| shipping, fee | extra line items named "Shipping" / the fee name, with their tax ids |
| discount | invoice `discount` (amount) with `is_discount_before_tax: true`, `discount_type: entity_level` |
| price basis | `is_inclusive_tax` from `prices_include_tax` (default false: lines are net) |

Items and `unknown_sku`. An item is matched by its SKU, exactly and ignoring case (`sku` of the item list answer `[inferred]`; an item with no SKU never matches). Zoho documents **no SKU filter** on `GET /items` (its parameters are `name`, `description`, `rate`, `tax_id`, `tax_name`, `is_taxable`, `tax_exemption_id`, `account_id`, `filter_by`, `search_text`, `sort_column` `[third-party]`), and `search_text` is documented for names and descriptions only. So each SKU is looked up in two steps: `GET /items?search_text=<sku>` (cheap, right when the SKU is part of the name), and when that holds no exact SKU match, the item list is read page by page (200 a page) and compared here; the list is read once per order however many lines it has. The SKU mapping screen search does the same when the text search finds nothing. A shop with thousands of items pays a few calls per order for this (200 items per call). When no item has the SKU: `refuse` (default) stops the order with SYNC-MAP-001 naming the SKU before anything is created; `free_text` sends a plain line with the product name and the SKU in its description and links no item.

## 6. Duplicate protection and resuming

`findExisting`: `GET /invoices?reference_number=WC-123` (exact), all pages; the oldest wins. It reports a record only when the connector's own steps are complete according to the options: status is not `draft` when `invoice_state = sent`; and, when `record_payment` is on and the order is paid, the invoice is `paid`. `send()` always starts with that read and continues from what is missing: create the invoice (once), mark it sent (`POST /invoices/{id}/status/sent`, safe to repeat), record the payment (only after re-reading the invoice's `balance`; never blind-replayed). A create that timed out is looked up by reference before anything else. Contacts: `GET /contacts?email=<email>` all pages, case-insensitive comparison; the oldest wins; a contact has one currency and its documents must use it `[inferred]`, so a buyer who pays in a currency none of their contacts uses gets a second contact named "Name (CUR)" in that currency. Zoho refuses a contact name that another contact has (code `1001`, "The customer ... already exists. Please specify a different name." `[third-party]`), and two different buyers can both be "John Smith": the search by email has said this buyer has no contact, so the create is repeated once with the email after the name ("John Smith (john@example.com)"; the email is cut at 120 characters inside the 200 character name limit). The next order of that buyer finds the contact by email first. If the second name is refused too, the order stops with SYNC-VAL-002 and Zoho's wording.

## 7. Taxes, currency, totals

Tax codes come from `GET /settings/taxes` (`tax_id`, `tax_name`, `tax_percentage`); the tax mapping (`tax_lookup`) picks the id; an unmapped rate stops the item with SYNC-MAP-002 before sending. Lines are net by default; with `prices_include_tax` the document is sent inclusive. After creating, the invoice's `currency_code` and `total` are read from the answer and compared with the order (`order.total` minus `excluded_total`, one minor unit tolerance); a different currency or total leaves the invoice as a draft and returns SYNC-VAL-002 naming both totals (`total_mismatch = fail`, default; `warn` continues). A later pass finds that draft by reference and checks it again; the person deletes it in Zoho Books and the next pass makes a new one. Conversions use `Money` with the currency exponent (KWD 3 decimals); decimal strings are sent as JSON numbers built from the exact decimal text.

## 8. Payments

Option `record_payment` (default off) with `payment_account` (deposit-to account id) and `payment_mode` (default `Others`): `POST /customerpayments` with `customer_id`, `payment_mode`, `amount`, `date`, `reference_number: WC-123`, `account_id` and `invoices[{invoice_id, amount_applied}]`. Done only when the order is paid, the invoice is open and its `balance` is above zero. The amount, and `amount_applied`, is the invoice **balance read just before** (not the order total): an invoice that is part paid or has a credit applied owes less than the order total, and applying more than the balance is refused by Zoho. The date is the day the order was paid (`paid_at`), the order date when none is known. A payment found by its reference is not made again; a paid or zero-balance invoice gets none.

## 9. Refunds

A credit note: `POST /creditnotes` with `customer_id` and `currency_id` of the order's invoice, `reference_number: WC-45` (the refund), `date`, one line named after the order for the refund amount (tax-inclusive, with the tax code of option `refund_tax_id` when set), `notes: Refund of order WC-123 (invoice <number>)`. Per-line refund detail is not sent (a known gap). Idempotent by `GET /creditnotes?reference_number=WC-45`. `refund_state`: `draft` (default) or `open` (`POST /creditnotes/{id}/status/open`). The note is not applied to the invoice (that is an accounting choice). The link to the original order is the note text and the reference; Zoho's own invoice link is not set (see "Not verified").

## 10. Invoice read-back

`fetchInvoice`: `GET /invoices/{id}` → number, status, `invoice_url` (a link to the customer-portal copy, returned as `pdf_url`), issue date. No tax-authority status: Saudi Zoho Books is ZATCA approved on the Standard plan `[documented in docs/research]` but nothing documents whether API-created invoices are reported; `authority_status` stays empty and the research item stays open. Stock and tracking: not read.

## 11. Rate limits and pacing

Per organisation: 100 requests a minute (the research found a Zoho page saying 30; the connector paces at 60 a minute, one at a time), daily by plan (above), concurrency 5 / 10. **Daily allowance:** the pacer counts this connection's calls per UTC day and returns SYNC-RATE-001 with the wait until midnight UTC before the call that would pass 90 percent of the plan's allowance (the plan is the `plan` option, Free when none); an order takes about 8 to 12 calls, so the Free plan carries roughly 75 to 110 orders a day. 429 codes: `44` per minute (wait 60 s), `45` per day (wait 3600 s, SYNC-RATE-001; the item waits rather than hammering), `1070` concurrency (wait 10 s). Zoho sends no `Retry-After` `[third-party]`.

## 12. Error mapping

| Zoho answer | SYNC code |
| --- | --- |
| 401 after one refresh (`code 57`, `invalid_token`) | SYNC-AUTH-002 |
| 401/403 scope or permission text ("not authorized") | SYNC-AUTH-004 |
| `code 6041` organisation not found | SYNC-VAL-002 naming the organisation |
| 429 `44`, `45`, `1070` | SYNC-RATE-001 |
| `code -1` Resource does not exist (on a record just found) | SYNC-VAL-002, retried |
| `code 1001` customer name exists | handled once (the email is added to the name); if refused again SYNC-VAL-002 |
| other `code` greater than 0 on 4xx | SYNC-VAL-002 with a short reason |
| 5xx, timeouts | SYNC-NET-002 / SYNC-NET-001 |
| unknown SKU | SYNC-MAP-001; unmapped tax SYNC-MAP-002; missing email SYNC-MAP-003 |

SYNC-AUTH-006 is not produced: no plan gate is documented. SYNC-AUTH-005: none (refresh tokens do not expire).

## 13. Capabilities

`orders` (invoices), `customers`, `refunds` (credit notes), `invoice_readback`, `reconciliation` (`GET /invoices?reference_number_startswith=WC-&date_start=<date>`, all pages: total, balance; refunded amounts are not summed), `shadow_lookup`. Not flagged: `stock_in`, `tracking_in`, `prices_in`, `webhooks` (workflow rules only).

## 14. Not verified

- Every Books object parameter (invoice, contact, credit note, customer payment bodies; the list filters `reference_number`, `reference_number_startswith`, `date_start`, `email`; item `sku` and `search_text`): the official pages could not be read. Wrong names will show as SYNC-VAL-002 from Zoho's own message.
- **Item SKU filter.** The official item list page could not be read. Third-party copies of its parameter list show none for the SKU, and the connector therefore reads the item list when the text search misses (section 5). What `search_text` searches beyond name and description is unconfirmed; the fallback makes the result correct either way, at a cost in calls. That the item list answer carries `sku` per item is `[inferred]`.
- **Duplicate contact name.** The code `1001` and wording come from a Zoho community thread about Zoho Invoice, not from a Books page; whether the comparison is case-insensitive is assumed (the connector treats a matching refusal the same either way). The 200 character limit of a contact name is `[inferred]`.
- **Payments.** That Zoho refuses an `amount_applied` above the invoice balance is `[inferred]` from the parameter lists (`balance_amount` per invoice); the connector never sends more than the balance either way. That `amount` equal to `amount_applied` suits a payment for one invoice is the same inference.
- The daily allowance figures per plan come from third-party guides; the 90 percent margin is the connector's own. Zoho's day (the time its allowance resets) is not stated, so the connector counts per UTC day and also stops on Zoho's own code `45`.
- That the Books API takes a JSON body (the connector sends JSON; the older form is the `JSONString` parameter).
- Scope names for items and taxes, and whether credit-note status calls need `UPDATE`.
- The per-minute limit (30 versus 100), and concurrency numbers.
- The `invoice_id` query parameter to create a credit note against an invoice: not used.
- Saudi e-invoicing of API-created invoices (see section 10).
- No live account: tested against `FakeZohoBooks`.

## 15. Manual smoke test

1. Create a Zoho Books trial organisation and a Server-based client with the redirect address from the screen.
2. Add the connection, sign in, choose the organisation, Test connection: name, currency and tax count appear.
3. Place an order whose SKU matches an item; expect an invoice with reference `WC-<id>`, status Sent, and the order total. Retry: no second invoice.
4. Turn on `record_payment` and pay an order: the invoice becomes Paid.
5. Refund an order: a credit note with reference `WC-<refund id>`.
6. Place two orders for different buyers who share a name: the second contact is named "Name (email)".
7. Apply a credit to an invoice before it is paid, then pay the order with `record_payment` on: the payment is for the balance.
