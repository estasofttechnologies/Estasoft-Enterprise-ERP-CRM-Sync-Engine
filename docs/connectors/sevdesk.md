---
id: sevdesk
name: sevDesk
catalog_row: sevDesk
connection_method: key
available: true
verified: false
hosts: [my.sevdesk.de]
terms_url: https://sevdesk.de/agb/                       # fetched 2026-11-02 (general terms, section 4 on interface use)
privacy_url: https://sevdesk.de/datenschutz/             # fetched 2026-11-02 (Datenschutzerklärung; links to separate policies for the website, the web app and the mobile app)
developer_url: https://my.sevdesk.de/                    # the API token is created inside the app (Settings, Users, API token [third-party]); a login page, not fetched
variants: []
---

# sevDesk connector

Connector id `sevdesk`, type accounting, contract version 1. Code: `includes/Connectors/Sevdesk/`. sevDesk (sevDesk GmbH, Germany) is a cloud bookkeeping and invoicing product. The connector sends WooCommerce orders as invoices (draft by default), refunds as credit notes and customers as contacts; it can read the stock of parts, the invoice number and status, and a night's list of invoices. Code shared by the Lexware Office, sevDesk, Moneybird and Pennylane connectors (payload reading, exact money arithmetic, local dates, option reading and the standard refusals) lives in `includes/Connectors/EuAccountingCommon/`; a change there affects all four.

Fact tags: `[documented]` official sevDesk page (URL, fetched 2026-11-02), `[third-party]` a page that mirrors or quotes the sevDesk API reference (the reference itself, https://api.sevdesk.de/, could not be fetched from the build session), `[inferred]`. **Most of this connector rests on third-party mirrors of the official reference.** Section 16 lists what is unconfirmed. Nobody has run this against a live sevDesk account.

## 1. Sources

- sevDesk Tech Blog / API news (official): https://tech.sevdesk.com/api_news [documented]. Posts read: removal of the API-token-in-URL method (29 Apr 2025), pagination limit 1 to 1000 (30 May 2025), invoice and credit-note status changes (April to June 2024), tax rule 17 "Nicht im Inland steuerbare Leistung", OSS rules 18 to 20, Reverse Charge §18b rule 21 (all under https://tech.sevdesk.com/api_news/posts/...).
- sevDesk general terms (AGB, January 2025), section 4 (interfaces; excessive use may be blocked without warning), section 14 (partner contracts): https://sevdesk.de/agb/ [documented]
- Reference mirrors (generated from the sevDesk OpenAPI file): withone.ai knowledge pages for `Invoice/Factory/saveInvoice`, `GET /Invoice`, `GET /Contact`, `POST /Contact`, `Part`, `Part/{id}/getStock`, `Tools/bookkeepingSystemVersion`, `Invoice/{id}/bookAmount`, `CreditNote/Factory/saveCreditNote`, `CreditNote/Factory/createFromInvoice` [third-party]; the PHP client documentation https://github.com/j-mastr/sevdesk-php-sdk (`InvoiceApi.md`, `CreditNoteApi.md`, `ModelInvoice.md`) [third-party]; an MCP wrapper that documents the 2.0 tax rule ids (glama.ai `sevdesk_create_draft_invoice`) [third-party].
- Chift integration article (auth header without prefix, pagination, errors as HTTP 500, numbering endpoints, no numeric rate limit): https://www.chift.eu/blog/how-to-succeed-with-your-sevdesk-api-integration [third-party]
- API Evangelist rate-limit note ("sevdesk does not publish fixed numeric rate limits"; 429; honour `Retry-After`): https://raw.githubusercontent.com/api-evangelist/sevdesk/refs/heads/main/rate-limits/sevdesk-rate-limits.yml [third-party]
- Repo research: `docs/research/integration-research-vendors.md` section 4.19 (mostly third-party; plan Buchhaltung Pro).

## 2. How the merchant connects

1. Sign in to sevDesk with the Buchhaltung Pro plan or higher; a 14-day trial works.
2. Open Settings, then Users, and open your own user.
3. Under `API Token` copy the token. Using the owner or an admin user is the safest choice.
4. Paste the token here and press Test connection.
5. Under Advanced, choose draft or open invoices and, if you want payments booked, the bank account.

Plan gate: Buchhaltung Pro (EUR 34.90 a month on a monthly term) [third-party]. The token belongs to the user who created it, gives full read and write access to the whole account (there are no scopes) and stops working if that user is deleted; creating a new token invalidates the old one at once [third-party]. Tokens do not expire [third-party], so the connector asks for no end date. There is no sandbox; test in a trial account [third-party].

## 3. Authentication

Header `Authorization: <token>` with the raw token and **no** `Bearer` prefix [third-party; documented that the header is the remaining method]. The older way, the token as a URL parameter, was removed on 29 Apr 2025 [documented]; the connector never puts the token in a URL. Base address `https://my.sevdesk.de/api/v1` [third-party]. HostPolicy allows `my.sevdesk.de` only. Header `Accept: application/json`; `Content-Type: application/json` on writes.

Credential fields: `api_token` (secret, required, write-only; 32 hex characters [third-party], not enforced). Advanced options: section 12.

sevDesk has two bookkeeping generations, "sevDesk-Update 2.0" and the older 1.0 [documented]. `GET /Tools/bookkeepingSystemVersion` answers `{"objects":{"version":"2.0"}}` [third-party]. 2.0 uses `taxRule`; 1.0 uses `taxType` [documented]. The test stores the version (`detected.bookkeeping_version`) and every send reads it from there, asking again when it is missing.

## 4. Data sent and when (WordPress.org "External services")

Host: `my.sevdesk.de` (sevDesk GmbH), only after the merchant saved a token.

| Action | Requests | Data sent |
| --- | --- | --- |
| Test connection | `GET /Tools/bookkeepingSystemVersion`, `GET /SevClient`, `GET /SevUser`, `GET /Unity`, `GET /CheckAccount`, `GET /Contact?limit=1` | The token only |
| Order synced | `GET /Contact?customerNumber=`, `POST /Contact`, `POST /ContactAddress`, `POST /CommunicationWay`, `GET /StaticCountry`, `GET /Part?partNumber=`, `GET /Invoice` (duplicate check), `POST /Invoice/Factory/saveInvoice`, `PUT /Invoice/{id}/sendBy` (open invoices), `GET /Invoice/{id}`, `GET /Invoice/{id}/getPositions`, `PUT /Invoice/{id}/bookAmount` (payment, if switched on), `DELETE /Invoice/{id}` (a draft the total check rejected) | Customer name, company, email, address, VAT id; order number as reference; order date; SKU, name, quantity, net price, tax rate; shipping, fees |
| Refund synced | `GET /CreditNote`, `POST /CreditNote/Factory/saveCreditNote`, `GET /CreditNote/{id}` | Refund lines and amounts, order and refund numbers, reason |
| Stock read | `GET /Part?partNumber=`, `GET /Part/{id}/getStock` | The SKU |
| Invoice status, reconciliation | `GET /Invoice/{id}`, `GET /Invoice`, `GET /CreditNote` | Dates and ids |

Card data is never part of the canonical model and is never sent.

## 5. Objects and field mapping

ids are integers (strings in some answers); many numeric and boolean values come back as strings (`"100.00"`, `"1"`) [third-party]; every object reference is `{ "id": n, "objectName": "Name" }`. The connector reads numbers from strings with `Money::fromDecimalString` and never uses floats. Money in requests is a JSON number with at most the decimals shown below, built from integer minor units.

Contact (`POST /Contact`) [third-party]:

| Payload path | sevDesk field | Notes |
| --- | --- | --- |
| (option) | `category` `{id: 3, objectName: "Category"}` | Required; 3 is the customer category [third-party, example values]; option `contact_category_id` |
| `customer.company` | `name` | Organisation when set |
| `customer.first_name`, `customer.last_name` | `surename`, `familyname` | Person when no company |
| (derived) | `customerNumber` | `WC-` plus the first 12 hex characters of `sha256(lowercase email)` (option `customer_number_prefix`), section 8 |
| `customer.vat_id` | `vatNumber` | |
| (fixed) | `status` 1000 | Active |

`POST /ContactAddress` (`contact`, `street`, `zip`, `city`, `country` `{id, objectName: "StaticCountry"}`) and `POST /CommunicationWay` (`contact`, `type` `EMAIL`, `value`, `main` true, `key`) store the postal address and the email on the contact. They are separate endpoints [documented in the `POST /Contact` description]; their field names and the communication-way key ids are [inferred]. A failure of these two calls never fails the order: the contact exists, the invoice carries the address as text, and the result gets a warning.

Invoice (`POST /Invoice/Factory/saveInvoice`) [third-party]:

| Payload path | sevDesk field | Notes |
| --- | --- | --- |
| (fixed) | `invoice.objectName` `Invoice`, `mapAll` true, `invoiceType` `RE`, `status` 100 (draft) | Creation is draft-only since 2024 [documented] |
| (contact) | `invoice.contact` `{id, objectName: "Contact"}` | Required |
| (user) | `invoice.contactPerson` `{id, objectName: "SevUser"}` | Required; the first user of `GET /SevUser` |
| `invoice_date` | `invoice.invoiceDate`, `invoice.deliveryDate` | `dd.mm.yyyy` in `Europe/Berlin` [documented formats: timestamp or `dd.mm.yyyy`]; a regular invoice's delivery date must not be later than its date [documented] |
| `header` | `invoice.header` | Default `Rechnung zu Bestellung {number}`; contains the marker |
| `head_text`, `foot_text` | `invoice.headText`, `invoice.footText` | Some HTML allowed [documented] |
| `order.client_ref` | `invoice.customerInternalNote` | The "Referenz/Bestellnummer" field [documented] |
| billing address | `invoice.address` (text with line breaks), `invoice.addressCountry` `{id, objectName: "StaticCountry"}` | `takeDefaultAddress` false |
| `currency` | `invoice.currency` | `EUR` only, section 7 |
| (tax) | `invoice.taxRule` `{id, objectName: "TaxRule"}` (2.0) or `invoice.taxType` (1.0), `invoice.taxText` | Section 7 |
| (fixed) | `invoice.discount` 0, `invoice.showNet` true, `invoice.smallSettlement` per option | |
| (option) | `invoice.timeToPay` | Default 14 days |
| `order_line[]` | `invoicePosSave[]` `{objectName: "InvoicePos", mapAll: true, quantity, unity {id, objectName: "Unity"}, taxRate, price, name, text, part {id, objectName: "Part"}, positionNumber}` | `price` is the net unit price; `part` when the SKU matched a part |
| (fixed) | `invoicePosDelete` null, `discountSave` `[]`, `discountDelete` null, `takeDefaultAddress` false | Required and in this order [documented] |

`GET /Invoice` filters [third-party]: `status` (100, 200, 1000), `invoiceNumber`, `startDate`, `endDate` (integer timestamps), `contact[id]` with `contact[objectName]=Contact`. Pagination: `limit` (1 to 1000, otherwise HTTP 400 "Invalid 'limit' parameter" since 30 May 2025 [documented]) and `offset`; the connector asks `limit=100` and stops on a short page.

## 6. Duplicate protection

The reference is written twice: `customerInternalNote` = `WC-{order id}` and the marker inside `header`. `send()` runs, before every create, `GET /Invoice?contact[id]=<contact>&contact[objectName]=Contact&startDate=<day start>&endDate=<day end>` for the order's day (Berlin), paged to the end, and accepts an invoice of type `RE` whose `customerInternalNote` equals the marker or whose `header` contains it as a whole word. sevDesk documents no filter on the reference, so the search is scoped by contact and day on the server and the marker is compared on the pages that come back, never on "the first N rows". With the collective-customer option all orders of a day are on one contact; the paging still reaches every one.

Weakness: an editor who changes both the reference field and the header of a draft makes a retry create a second invoice. Replays: create-invoice is never repeated; a timeout after `saveInvoice` is an unknown outcome and the next pass finds the draft by the marker. `findExisting( connection, item )` gets no payload, so it knows neither the contact nor the day and cannot run this search. It answers from the document id the engine stored after a successful `send()` (`GET /Invoice/{id}` or the credit note), or from the result of `send()` in the same run, and otherwise returns null after a cheap `GET /Tools/bookkeepingSystemVersion`. Such a record is one `send()` finished (draft or open as the option asks, total checked, positions present); an unfinished invoice is found by the search inside `send()`, which carries on from where it stopped.

Contacts: `GET /Contact?customerNumber=<number>&depth=1`. The number is deterministic (section 8), so a retry after a lost answer finds the contact it just created.

## 7. Order flow, taxes, currency and the total check

1. Refuse a currency other than `EUR` (sevDesk foreign-currency documents carry `...ForeignCurrency` totals whose meaning could not be confirmed, section 16) and an order without lines.
2. Version (2.0 or 1.0), contact person, country id (`GET /StaticCountry?limit=1000`, match the ISO code case-insensitively), unit id (option, default 1; checked at test time against `GET /Unity`).
3. Customer (section 8). Products: `GET /Part?partNumber=<SKU>` [third-party filter]; found: `part` on the position; not found: SYNC-MAP-001, or with `unknown_sku = custom` a free-text position.
4. Taxes. sevDesk takes one tax rule per document and the rate per position. The tax mapping code is the sevDesk rule id on 2.0 (`1` taxable sales, `2` exports, `3` intra-EU supply, `4` tax-free §4 UStG, `5` reverse charge §13b, `11` small business §19, `17` not taxable in Germany, `18`/`19`/`20` One-Stop-Shop goods/electronic services/other services, `21` reverse charge §18b [third-party, glama and the news posts]) and the type word on 1.0 (`default`, `eu`, `noteu`, `custom`, `ss`). Every line must resolve to the same code, otherwise SYNC-VAL-002 names the two codes. OSS rules are valid for normal invoices (`RE`) only and need the destination country as `addressCountry` [documented]. Rule 17 is not available on advance, partial and final invoices [documented]; the connector only makes `RE`. The position `taxRate` is the percentage of the line (0 for the rules that have none). `taxText` defaults per rule (`Umsatzsteuer 19%` for rule 1, a plain German sentence for the others, all mappable and to be confirmed by the merchant's accountant, [inferred]).
5. Prices are net with `showNet` true. When the payload prices include tax (`prices_include_tax`), each unit price is converted to net with whole-number arithmetic (rate in ten-thousandths of a percent, rounding half away from zero, four decimals); a line with more than one tax is refused. The discount is inside the line prices (line total after coupon divided by the quantity).
6. `saveInvoice` (draft). Read back `GET /Invoice/{id}` (`sumNet`, `sumTax`, `sumGross`, `currency`, `status`, `invoiceNumber`) and compare `sumGross` with `total - excluded_total` in minor units. WooCommerce rounds the tax of each line and sevDesk the tax of each rate group, so the default tolerance is one minor unit per document row (lines, shipping, fees), at most 10 (ten lines of 0.99 EUR at 19 % differ by 2 cents); the option `total_tolerance` (0 to 50; empty means the default) replaces it. A larger difference: option `total_mismatch` `fail` (default) deletes the draft (`DELETE /Invoice/{id}`, documented in the PHP client; only a draft that this pass created and that is not enshrined) and fails with SYNC-VAL-002 naming both totals; `warn` goes on. `currency` must read back as `EUR`.
7. `invoice_state = open`: `PUT /Invoice/{id}/sendBy` with `sendType` `VPR` and `sendDraft` false marks the invoice as sent and raises the status to 200; the status is read back. The connector never calls `enshrine`: it is irreversible (GoBD) and a decision for the accountant.
8. Payment (option `payment_account` = the sevDesk bank account id, and the order is paid, and the invoice is open): read the invoice first; when `paidAmount` already covers the total nothing is booked; else `PUT /Invoice/{id}/bookAmount` with `amount`, `date`, `type` `FULL_PAYMENT`, `checkAccount` `{id, objectName: "CheckAccount"}` [third-party]. `date` is an integer [documented as an integer with the example 20230418]: the connector sends the order's payment day as `yyyymmdd` (for example `20261103`); the option `payment_date_format` = `unix` sends the Unix timestamp of local midnight instead, because third-party tool wrappers describe the same field as a Unix timestamp (section 16). A payment date that cannot be read books nothing and adds the warning `payment_date_unreadable`. The invoice is read again right before the booking, so a booking that went through but whose answer was lost, or a payment a person entered meanwhile, is seen (`paidAmount`, status) and nothing is booked twice.

Date: `invoiceDate` is the order date (Berlin). The read-back date is compared with the day sent, after creation and again after `sendBy` (its UTC offset is honoured); if it is another day the result carries the warning `date_differs`. The date is never written back.

## 8. Customers

sevDesk documents no search by email: the `GET /Contact` filters are `depth` and `customerNumber` [third-party]; emails live in `CommunicationWay` records. So `lookupCustomer( email )` returns the contact whose customer number is the deterministic number of that email (`WC-` + 12 hex of the lowercase email's hash). That finds contacts this connector made, not contacts the merchant typed by hand: the same person may then exist twice in sevDesk, once as the merchant's own contact and once as `WC-…`.

Policies (option `customer_policy`): `match_or_create` (default), `collective` (every order goes on one existing contact whose id is `collective_contact_id`, the usual "Webshop-Kunde" pattern), `always_create` (one contact per order, numbered from the email and the order reference, so a retry finds the same contact). The collective contact avoids a contact per buyer; the buyer's name and address are on the invoice text.

## 9. Refunds

Credit notes are supported on sevDesk-Update 2.0 only. A refund becomes `POST /CreditNote/Factory/saveCreditNote` with `creditNote` (`objectName` `CreditNote`, `mapAll`, `contact`, `contactPerson`, `creditNoteDate`, `status` 100, `header` = `Gutschrift WC-{refund id} zu Bestellung WC-{order id}`, `addressCountry`, `taxRule`, `taxText`, `bookingCategory` `UNDERACHIEVEMENT`, `currency`, `address`), `creditNotePosSave[]` (`objectName` `CreditNotePos`, `mapAll`, `quantity`, `unity`, `taxRate`, `price`, `name`, `part`), then `creditNotePosDelete` null, `discountSave` null, `discountDelete` null, `takeDefaultAddress` false, `forCashRegister` false, in this order [third-party]. `bookingCategory`: the enumeration is PROVISION, ROYALTY_ASSIGNED, ROYALTY_UNASSIGNED, UNDERACHIEVEMENT, ACCOUNTING_TYPE, the last "not supported in sevdesk-Update 2.0" [third-party]; UNDERACHIEVEMENT is used because the OSS news post requires it for credit notes [documented]. On 1.0 the refund stops with SYNC-VAL-002 "credit notes need sevDesk-Update 2.0".

The credit note is not linked to the invoice by a field (no linking field is documented for `saveCreditNote`); the header names both numbers. `POST /CreditNote/Factory/createFromInvoice` (copies a whole invoice) is not used because WooCommerce refunds are often partial. Status stays draft (`refund_state = open` uses `PUT /CreditNote/{id}/sendBy`). Duplicate search: `GET /CreditNote?contact[id]=...&startDate/endDate` for the refund's day, marker `WC-{refund id}` in `header` or `customerInternalNote`. The credit note number is left to sevDesk (the third-party schema lists `creditNoteNumber` as required, section 16).

## 10. Payments

Opt-in as in step 8 of section 7. Refunds record no payment.

## 11. Stock, tracking, invoice read-back, reconciliation

- Stock (`StockIn`, flagged): `GET /Part?partNumber=<SKU>` gives `id`, `stock`, `stockEnabled`; for stock-enabled parts `GET /Part/{id}/getStock` answers `{"objects": <number>}` [third-party]. Total only, no warehouses; `held` is 0. A part without stock tracking is left out.
- Tracking: none (no shipment record in the API read). Not flagged.
- Invoice read-back: `GET /Invoice/{id}`: `invoiceNumber`, `status` (50 deactivated recurring, 100 draft, 200 open, 750 partially paid, 1000 paid [third-party]) mapped to `draft`, `open`, `partially_paid`, `paid`; `authority_status` empty (no tax-authority step); `pdf_url` empty (`getPdf` needs a token); `issued_at` = `sendDate`.
- Reconciliation (flagged): `GET /Invoice?startDate=<from>` paged, keeping invoices whose reference carries `WC-{n}`; totals from `sumGross`; refunded amount from `GET /CreditNote?startDate=` whose header names the order marker. The window uses `invoiceDate`, which is the order date.
- `ShadowLookup`: not flagged, for the reason in section 6: the reference cannot be searched without the contact and the day, which `findExisting` does not receive. `lookupProduct` (part number) and `searchProducts` work; `lookupCustomer` answers from the deterministic customer number.

## 12. Options (connection config, `options` block)

| Key | Values | Default |
| --- | --- | --- |
| `invoice_state` | `draft`, `open` | `draft` |
| `refund_state` | `draft`, `open` | `draft` |
| `customer_policy` | `match_or_create`, `collective`, `always_create` | `match_or_create` |
| `collective_contact_id` | sevDesk contact id | empty |
| `customer_number_prefix` | text | `WC-` |
| `contact_category_id` | number | 3 |
| `unknown_sku` | `refuse`, `custom` | `refuse` |
| `total_mismatch` | `fail`, `warn` | `fail` |
| `total_tolerance` | 0 to 50 minor units, or empty | empty: one per document row, at most 10 |
| `payment_date_format` | `ymd`, `unix` | `ymd` |
| `unity_id` | number | 1 |
| `time_to_pay` | days | 14 |
| `payment_account` | bank account id | empty (no payment) |
| `small_settlement` | yes, no | no |
| `timezone` | PHP time zone | `Europe/Berlin` |

## 13. Rate limits and pacing

sevDesk publishes no numeric limit [third-party]; it answers 429 under load and may block excessive interface use without warning [documented, AGB section 4]. The connector paces to two requests a second (`rateLimitHints()` 2.0, concurrency 1), pages with `limit` 100, and treats 429 as SYNC-RATE-001 with the `Retry-After` value when sent, else the base default of 30 seconds. `ping()` is one call, `GET /Tools/bookkeepingSystemVersion`. Reconciliation and stock reads run in the background after orders.

## 14. Error mapping

sevDesk answers errors as `{"message": "..."}` or `{"error": {"message": "..."}}` [third-party]; **a 400-class problem can arrive as HTTP 500** [third-party].

| Situation | SYNC code | Notes |
| --- | --- | --- |
| Timeout, reset, name not resolved | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | |
| 401 | SYNC-AUTH-001 | token wrong or revoked |
| 403 | SYNC-AUTH-004 | |
| 400, 404 with a message | SYNC-VAL-002 | short sanitised reason; `Invalid 'limit' parameter` is a bug and says so |
| 500 with a JSON `message` that complains about the request (it says something exists already, is missing, required, invalid, too long, not allowed and so on) | SYNC-VAL-002 | for example "debitor/creditor number already exists" [third-party]; needs a person. The words that count are listed in `SevdeskErrorMapper::PROBLEM` |
| any other 500 (no message, `Server Error`, "Internal error", "Something went wrong"), 502 to 504 | SYNC-NET-002 | retry with the queue's delays; a transient fault must not need a person |
| 429 | SYNC-RATE-001 | |
| Unknown product (SKU) | SYNC-MAP-001 | before anything is sent for the order |
| Tax rule not in the mapping | SYNC-MAP-002 | |
| Missing customer email | SYNC-MAP-003 | |

There is no documented plan-gate answer, so SYNC-AUTH-006 is never produced for sevDesk. Secrets: the token is scrubbed from every message; the sevDesk client id and names are not logged.

## 15. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | true | Invoices |
| `customers` | true | Contacts |
| `refunds` | true | Credit notes (2.0) |
| `stock_in` | true | `Part/{id}/getStock` [third-party mirror of the reference] |
| `tracking_in` | false | None |
| `prices_in` | false | Not read |
| `invoice_readback` | true | Number and status |
| `reconciliation` | true | Reference readable in the invoice list |
| `webhooks` | false | None found |
| `shadow_lookup` | false | `findExisting` cannot search without the contact and the day (section 6) |

Capability record: `order_document` `invoice`, `refund_document` `credit_note`, `stock_read` `total`, `tracking_read` `no`, `customer_lookup_by_email` `no` (deterministic customer number instead), `product_lookup_by_sku` `yes` (part number), `reference_field` `customerInternalNote`, `native_idempotency` `none`, `webhooks` `none`, `rate_limit` about 2 per second, `requires` "sevDesk Buchhaltung Pro".

## 16. Not verified

- The official reference (https://api.sevdesk.de/, https://tech.sevdesk.com/ guides and the 2.0 handbook) could not be fetched, except the API news posts. All request and response shapes above are from mirrors and client libraries. **Every one of them can be wrong in detail.**
- The `X-Version` header (the research mentions per-resource versions): not sent.
- Required-field lists in the mirror contradict themselves ("nothing is required"; `taxRate` required but unused; `takeDefaultAddress` with no schema; `creditNoteNumber` required). The connector sends the fields in section 5 and 9 and leaves numbers to sevDesk; if sevDesk demands a number the credit note fails with SYNC-VAL-002 and the message says which field.
- Whether `taxType` must be omitted on 2.0 (an MCP wrapper says so; the reference lists it as required) and whether `taxText` is needed.
- Category id 3, unit id 1, the `CommunicationWay` key ids, the `ContactAddress` and `CommunicationWay` field names.
- Filters: `customerNumber` on `GET /Contact`, `partNumber` on `GET /Part`, `contact[id]`, `startDate`, `endDate` on `GET /Invoice` and `GET /CreditNote` (timestamps or dates), `depth`.
- Whether `price` on a position is net when `showNet` is true, and the number of decimals allowed.
- The date format of `bookAmount`: `date` is an integer with the example 20230418 in the reference file, while the third-party tool pages (glama `mcp-sevdesk`, aident) call it a Unix timestamp. The connector sends `yyyymmdd`; if sevDesk rejects it or books the wrong day, set `payment_date_format` to `unix`. A wrong day would not be noticed by the connector.
- The list of messages sevDesk sends with a 500 for a bad request: only one is known ("debitor/creditor number already exists"); the others are matched by the words of `SevdeskErrorMapper::PROBLEM`, and a bad request worded otherwise is retried by the queue until its attempts run out.
- `sendBy` body (`sendType`, `sendDraft`) and that it raises the status to 200 and numbers the invoice; whether drafts already carry an invoice number.
- Foreign-currency documents (EUR only is supported here).
- The meaning of `CreditNote` `bookingCategory` for ordinary refunds (UNDERACHIEVEMENT is documented for OSS credit notes).
- That a draft can always be deleted and that the answer to a lost `saveInvoice` response is findable by the marker.
- `StaticCountry` field names (`code` is used).
- Rate limits.
- The privacy policy URL was not fetched.

## 17. Manual smoke test (real account, no secrets in the repository)

1. Start a sevDesk trial (Buchhaltung Pro); note the system version in Settings (2.0 expected). Create the API token.
2. Add the connection. Expected: Test connection shows the company, version 2.0, the user and the bank accounts. A wrong token: SYNC-AUTH-001.
3. Place an order for a SKU that exists as a part number with 19 % tax. Expected: contact `WC-…`, draft invoice with the reference `WC-{id}`, total equal to the shop's. Run the sync again: no second invoice. Delete the draft in sevDesk, run again: a new one.
4. Switch `invoice_state` to `open`: status 200 and a number after the next order. Set `payment_account` and place a paid order: the invoice becomes paid.
5. Refund an order: a draft credit note whose header names both numbers.
6. Change a part's stock in sevDesk and run the stock read.
7. Order with 0 % export rule (tax mapping code `2`) and an OSS rule: check the printed tax texts with your accountant.
