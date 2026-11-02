---
id: lexware-office
name: Lexware Office
catalog_row: Lexware Office
connection_method: key
available: true
verified: false
hosts: [api.lexware.io]
terms_url: https://agb.lexware.de/lexware-office/public-api-lizenz--und-nutzungsbedingungen   # named "API Lizenz- und Nutzungsbedingungen" on https://www.lexware.de/partner/public-api/ (fetched 2026-11-02); the licence page itself is not on the fetch allowlist, its text is unread
privacy_url: https://datenschutz.lexware.de/                                                  # named "Datenschutz" on https://www.lexware.de/partner/public-api/ (fetched 2026-11-02); the target page itself is not on the fetch allowlist
developer_url: https://app.lexware.de/addons/public-api                                       # named by the official help article and the API documentation; a login page, not fetched
variants: []
---

# Lexware Office connector

Connector id `lexware-office`, type accounting, contract version 1. Code: `includes/Connectors/LexwareOffice/`. Lexware Office (formerly lexoffice, Haufe-Lexware, Germany) is a cloud bookkeeping and invoicing product. The connector sends WooCommerce orders as invoices (draft by default), refunds as credit notes and customers as contacts. It reads back the invoice number and status. Lexware documents no stock, no shipment record and no way to record a payment through the API, so none of those is flagged. Code shared by the Lexware Office, sevDesk, Moneybird and Pennylane connectors (payload reading, exact money arithmetic, local dates, option reading and the standard refusals) lives in `includes/Connectors/EuAccountingCommon/`; a change there affects all four.

Fact tags: `[documented]` official Lexware page (URL and fetch date given), `[third-party]` partner, integrator or client-library page, `[inferred]` reasoned from other documented behaviour. Section 16 lists what could not be confirmed. Nobody has run this against a live Lexware Office organisation.

## 1. Sources

All fetched 2026-11-02 unless stated.

- API documentation (single page; only the part up to the middle of the Credit Notes chapter could be read, see section 16): https://developers.lexware.io/docs/ [documented]. Read: introduction, rate limits, Articles, Contacts, Countries, Credit Notes (properties, create, pursue to a credit note), the start of Delivery Notes. Not readable: Invoices chapter, Paging of Resources, Error Codes, FAQ (tax rates, text formatting, datetime), Voucherlist, Profile, Payments.
- Cookbooks index: https://developers.lexware.io/cookbooks/ [documented] (three cookbooks: Public API, Rechnungen, Buchhaltung; their pages were not reachable).
- Help article "Alles rund um die Public API" (plan, key creation, key lifetime): https://help.lexware.de/de-form/articles/548863-alles-rund-um-public-api [documented]
- Product page "Public API von Lexware Office" (plan, no extra cost, one user per application recommended): https://www.lexware.de/funktionen/public-api/ [documented]
- Microsoft Learn connector page for lexoffice (operation list, parameters, response schemas of Voucherlist, Profile, Payment conditions, Posting categories; 100 calls per 60 s is Microsoft's own connector limit and is ignored here): https://learn.microsoft.com/en-us/connectors/lexoffice [third-party]
- Invoice create schema mirrored from the Lexware reference (enums of `taxType`, `shippingType`, `lineItems[].type`; `finalize` is a query parameter): https://www.withone.ai/knowledge/lexware/conn_mod_def%3A%3AGLWE2Weqj6A%3A%3AtR1e5tYFRQylNqSZ1clwpA/md [third-party]
- Chift integration article (HTTP 406 for validation errors with an `IssueList`, no `Retry-After` on 429, `size` up to 250, `version` conflicts answer 409, vouchers cannot be rendered): https://www.chift.eu/blog/lexware-office-api-integration-best-practices-and-key-insights [third-party]
- npm client `@elbstack/lexoffice-client-js` (error status classes: regular 400 401 402 403 404 405 406 409 415 429, server 500 503 504, legacy 400 406 500 for files, profile and contacts): https://npmjs.com/package/@elbstack/lexoffice-client-js [third-party]
- Repo research: `docs/research/integration-research-vendors.md` section 4.18, `docs/research/integration-research.md`.

Corrections to the repo research: the research says keys do not expire [third-party]. The official help article says a key is valid for at most 24 months and is then deactivated and deleted [documented]. The research and `docs/spec/connection-methods-spec.md` say Lexware cannot search contacts by email; the API documents an `email` filter (at least three characters) [documented]. The research says there is no reference field; that stays true for invoices (section 6).

## 2. How the merchant connects

1. Sign in to Lexware Office. The Public API needs the Version XL plan; it costs nothing extra [documented].
2. Open `app.lexware.de/addons/public-api` (Weitere Apps, Public API) and press `API-Schlüssel erstellen`.
3. Name the key, for example `WooCommerce`, and give it read and write access to contacts, articles, invoices and credit notes.
4. Copy the key now. Lexware shows it only once.
5. Paste the key here, enter the date it expires, and press Test connection.
6. Under Advanced, choose whether invoices stay drafts (default) or are finalised at once.

Plan gate: Lexware Office Version XL [documented]. Lexware recommends one Lexware user per connected application and creating the key with that user [documented]. The help article does not say which user role may create keys. A key can be created with all permissions or with a chosen set; the permissions cannot be changed afterwards [documented]. Several keys can exist at the same time [documented].

## 3. Authentication

Header `Authorization: Bearer <key>` [documented]. Base address `https://api.lexware.io/v1` [documented]. The gateway moved on 26 May 2025; the old host `api.lexoffice.io` was kept only until December 2025 [documented] and is not used. HostPolicy allows `api.lexware.io` only. Keys belong to one organisation, so no organisation id is sent [third-party].

Credential fields: `api_key` (secret, required, write-only), `key_expires_on` (text, optional, `YYYY-MM-DD`; the day the key runs out, shown in the Public API list of the Lexware app). Advanced options are listed in section 12.

Key lifetime: at most 24 months [documented]. In the Lexware app a key can be copied (new key, same permissions, old key keeps working until its date) or extended by 24 months (same key) [documented]. The connector stores `key_expires_on` as the connection's `credential_expires_at` (through `TestResult::detected['credential_expires_at']`, end of that day UTC) so the existing 14-day warning and the Replace key flow work. With no date entered nothing is stored and nobody is warned; a key that has run out then answers 401 and shows as SYNC-AUTH-001.

## 4. Data sent and when (WordPress.org "External services")

Host: `api.lexware.io` (Lexware, Haufe-Lexware GmbH and Co. KG), only after the merchant has saved a key. Terms: see `terms_url`; privacy: see `privacy_url`.

| Action | Requests | Data sent |
| --- | --- | --- |
| Test connection | `GET /v1/profile`, `GET /v1/contacts?size=1`, `GET /v1/articles?size=1`, `GET /v1/voucherlist?size=1` | The key only |
| Order synced | `GET /v1/contacts` (by email), `POST /v1/contacts` (new customer), `GET /v1/articles` (by article number), `GET /v1/voucherlist` and `GET /v1/invoices/{id}` (duplicate check), `POST /v1/invoices[?finalize=true]`, `GET /v1/invoices/{id}` (check) | Customer name, company, email, phone, billing address and VAT id; order number as text on the invoice; order date; product SKU, name, quantity, unit price, tax rate; shipping, fees, discounts |
| Refund synced | `GET /v1/voucherlist`, `GET /v1/credit-notes/{id}`, `POST /v1/credit-notes[?precedingSalesVoucherId=][&finalize=true]` | Refund lines and amounts, the order and refund numbers as text, the reason |
| Customer lookup | `GET /v1/contacts?email=` | The email address as a search term |
| Invoice status read | `GET /v1/invoices/{id}` | Nothing but the id |

Card data is never part of the canonical model and is never sent.

What Test connection shows: `GET /v1/profile` gives `companyName`, `organizationId` (not displayed), `subscriptionStatus`, `taxType` (`net`, `gross`, `vatfree`) and `smallBusiness` [third-party: response schema from the Microsoft connector page]; the three list calls prove the key can read contacts, articles and invoices. Write permission cannot be tested without creating a record, so the test lists only the three read permissions; a key without write access fails later with SYNC-AUTH-004 and a message naming the area. A small-business (Kleinunternehmer) organisation gets a note, because its invoices carry no VAT: the connector then maps every line to the tax-free type only if the tax mapping says so.

## 5. Objects and field mapping

Lexware ids are UUIDs. Money in requests is a JSON number; the connector builds it from integer minor units with the currency exponent (2 for EUR) and writes it with `Money`/string arithmetic, never floats. Only EUR exists: `unitPrice.currency`, `totalPrice.currency` accept `EUR` only [documented, Credit Notes chapter; same for invoices by [inferred]]. A WooCommerce order in any other currency stops with SYNC-VAL-002 before anything is sent.

Contact (`POST /v1/contacts`) [documented]:

| Payload path | Lexware field | Notes |
| --- | --- | --- |
| (fixed) | `version` 0, `roles.customer` `{}` | Required on create |
| `customer.company` set | `company.name`, `company.vatRegistrationId`, `company.contactPersons[0]` (`firstName`, `lastName`, `emailAddress`, `phoneNumber`, `primary` true) | `lastName` required for the contact person |
| `customer.company` empty | `person.firstName`, `person.lastName` | `lastName` required and non-empty |
| `customer.email` | `emailAddresses.business[0]` for a company, `emailAddresses.private[0]` for a person | One entry per list on create |
| `customer.phone` | `phoneNumbers.business[0]` or `.private[0]` | |
| `customer.street`, `customer.supplement`, `customer.zip`, `customer.city`, `customer.country` | `addresses.billing[0]` (`street`, `supplement`, `zip`, `city`, `countryCode`) | `countryCode` required (ISO 3166 alpha-2); one billing entry on create |
| `customer.vat_id` | `company.vatRegistrationId` | Must follow German VAT id rules (a foreign id may be rejected, SYNC-VAL-002) |
| `customer.note` | `note` | At most 1000 characters |

Invoice (`POST /v1/invoices`) [third-party for the enums, documented for the equivalent Credit Notes chapter]:

| Payload path | Lexware field | Notes |
| --- | --- | --- |
| `voucher_date` | `voucherDate` | `yyyy-MM-ddTHH:mm:ss.SSSXXX` [documented]; the connector sends midnight of the order date in `Europe/Berlin` with its offset (option `timezone`), so the German calendar day is the order's day |
| (contact) | `address.contactId` | Always a contact; the one-time address form (`name` + `countryCode`) is not used (section 6) |
| `title` | `title` | Default `Rechnung` when empty |
| `introduction` | `introduction` | The connector always starts it with the line `Bestellung WC-{id}` (the duplicate marker), then the mapped text |
| `remark` | `remark` | Free text below the items |
| `order_line[].sku` / `.name` / `.quantity` / `.unit_price` / `.tax` | `lineItems[]` `type` `material` (SKU found) or `custom` (free text), `id` (article id, for `material`), `name`, `quantity` (up to 4 decimals), `unitName`, `unitPrice.netAmount` or `.grossAmount` (up to 4 decimals), `unitPrice.taxRatePercentage`, `unitPrice.currency` `EUR` | At most 300 line items |
| `shipping`, `fee[]`, `discount` | `lineItems[]` `type` `custom` | Shipping and fees positive; the discount is already inside the line prices (section 7), a separate negative line only when the mapping supplies `discount.total` |
| (tax) | `taxConditions.taxType` | `net`, `gross` or a special type, chosen once per document from the tax mapping (section 7) |
| (fixed) | `totalPrice.currency` `EUR` | Totals are computed by Lexware and read back |
| (option) | `paymentConditions`, `shippingConditions` | Not sent: the organisation's defaults apply [documented for payment conditions]. `shippingConditions` is required by the endpoint [third-party]: the connector sends `{ "shippingType": "delivery", "shippingDate": <order date> }` (option `shipping_type`: `delivery`, `service`, `none`) |

Article lookup: `GET /v1/articles?articleNumber=<SKU>` [documented]; `type` `PRODUCT` or `SERVICE`. Lines use the article's id so Lexware links the line to it; the price and name stay the shop's.

## 6. Duplicate protection

Lexware has no reference or external-id field on invoices and no idempotency key [documented absence in the field list; third-party]. The only free-text places are `title`, `introduction` and `remark`. The connector writes `Bestellung WC-{order id}` as the first line of `introduction` (credit notes: `Gutschrift WC-{refund id}`, after the preceding invoice text).

`send()` searches before every create, in this order, all scoped to the one customer contact (the contact is found by email first; if the customer does not exist there cannot be an invoice):

1. `GET /v1/voucherlist?voucherType=invoice&contactId=<contact>&voucherDateFrom=<day>&voucherDateTo=<day>&size=250&page=n` for the order's day in every status (`draft`, `open`, `paid`, `paidoff`, `voided`) [documented: the response fields `contactId`, `voucherDate`, `voucherStatus`, `voucherType`, `voucherNumber`, `totalAmount`; the filter names are [third-party]], paged until `last`.
2. For each hit `GET /v1/invoices/{id}` and a check that `introduction` contains the marker as a whole word (`WC-1234` does not match `WC-12345`). At 2 requests per second a day window of one customer is a handful of calls.

The window is the order's day (`voucherDate` is set from the order). If the order date later changes, the search misses the invoice. A merchant who edits the introduction of a **draft** removes the marker and a retry creates a second invoice; a finalised invoice cannot be edited. This is why the default `invoice_state = draft` is a trade-off: it is safer for tax (nothing is issued without a person) and slightly weaker for duplicates. `findExisting( connection, item )` gets no payload, so it knows neither the customer nor the day and cannot run this search. It answers from the document id the engine stored after a successful `send()` (`GET /v1/invoices/{id}` or `/v1/credit-notes/{id}`) or from the result of `send()` in the same run, and otherwise returns null after a cheap `GET /v1/profile` that proves the key still works. Such a record is one `send()` finished (draft or open as the option asks, total checked); an unfinished invoice is found by the search inside `send()`, which then carries on (section 7).

Replays: create-invoice is never repeated blindly. `send()` always starts with the search above; a timeout after `POST /v1/invoices` is an unknown outcome, so the next pass finds the draft by the marker and goes on.

## 7. Order flow, taxes and the total check

1. Read the payload; refuse a currency other than EUR; refuse an order without lines.
2. Resolve the customer (section 8), then every line's article (unknown SKU: SYNC-MAP-001 or, with `unknown_sku = custom`, a free-text line).
3. Taxes. Lexware's tax treatment is document-level (`taxConditions.taxType`), the rate is per line. The tax mapping (rows `country + rate -> system code`) yields per line either a percentage (`19`, `7`, `0`, any decimal Lexware accepts) or a special type: `vatfree`, `intraCommunitySupply`, `constructionService13b`, `externalService13b`, `thirdPartyCountryService`, `thirdPartyCountryDelivery`, `photovoltaicEquipment` [documented enum]. All lines (goods, shipping, fees) must resolve to percentages, or all to the same special type. A mix stops the order with SYNC-VAL-002 naming the two codes (never silently re-tax). Percentages use `taxType = net` when the payload prices are tax-exclusive and `gross` when `prices_include_tax` is true; `grossAmount` is then the unit price. A special type forces `taxRatePercentage = 0`. A rate Lexware does not support (the supported list is an FAQ page that could not be read, section 16) comes back as a 406 and is mapped to SYNC-MAP-002 only when an `IssueList` entry names `taxRatePercentage`; any other 406, including one whose field path merely contains "tax" such as `taxConditions.taxType`, is SYNC-VAL-002 with the short reason.
4. Discounts. WooCommerce coupons reduce the line totals; the connector prices every line from the line total after discount (net or gross as above), divided by the quantity to four decimals, so the invoice total equals the shop's without a negative line. The coupon codes are appended to `remark`.
5. Create `POST /v1/invoices` as a draft, or with `?finalize=true` when `invoice_state = open`. `finalize` is a query parameter of the create call [documented]; no endpoint that finalises an existing draft was found (section 16). A finalised invoice cannot be edited, is numbered, and is the document that is legally issued [documented].
6. Read it back (`GET /v1/invoices/{id}`): number, status, `totalPrice` (`totalNetAmount`, `totalGrossAmount`, `totalTaxAmount`) and `taxAmounts`. Compare `totalGrossAmount` with `total - excluded_total` in minor units. WooCommerce rounds the tax of each line and Lexware the tax of each rate group, so a few cents of difference are rounding: the default tolerance is one minor unit per document row (lines, shipping, fees), at most 10 (ten lines of 0.99 EUR at 19 % differ by 2 cents). The option `total_tolerance` (0 to 50 minor units; empty means the default) replaces it.
7. A larger difference (option `total_mismatch`):
   - `fail` (default): the item fails with SYNC-VAL-002 naming both totals. In `draft` mode the draft stays in Lexware (no delete endpoint was found) and the person deletes it there; the next pass finds that draft by its marker, checks it again and never creates a second one. In `open` mode the invoice is already issued and cannot be corrected: the item fails with the invoice number in the message, the person cancels it in Lexware with a credit note and skips the queue item. Nothing is corrected automatically.
   - `warn`: the order goes on and the result carries a warning.
   This is why the default is `draft`: `open` should be switched on only after a sample order of every tax situation matched to the cent.

Dates: `voucherDate` is the order date (local calendar day, Europe/Berlin) and Lexware keeps it on finalise [inferred, not documented]. The read-back `voucherDate` is compared with the day sent (its UTC offset is honoured); if it is another day the result carries the warning `date_differs`. The date is never written back (no call to change a finalised document). `shippingDate` is the order date.

Resumability: every pass starts from the search in section 6. Steps after creation (read-back, check) are reads, so they are safe to repeat.

## 8. Customers

`lookupCustomer( email )`: `GET /v1/contacts?email=<email>&customer=true` [documented: `email` is a case-insensitive substring match over contact emails and contact-person emails, minimum 3 characters, `_` and `%` act as wildcards unless escaped with a backslash]. The connector escapes `\`, `_` and `%`, then keeps only contacts with an email equal (case-insensitive) to the wanted one in `emailAddresses.*` or `company.contactPersons[].emailAddress`, paging with `page` until `last`. Several matches: option `duplicate_customer` `oldest` (default; lowest customer number) or `fail`. None: create (option `customer_policy` `match_or_create` or `match_only`). There is no "always create" choice: the invoice search is scoped by contact, and a new contact for every pass would hide an invoice whose read-back was lost (a retry would make a second one); a stored value `always_create` is read as `match_or_create`.

An existing contact keeps its stored billing address; the invoice prints the contact's address [inferred]. When the order's billing address differs from the contact's, the result carries a warning `address_differs`; the contact is never changed [Lexware updates need the contact's `version` and replace whole lists, so an automatic update could erase data]. Whether address fields next to `contactId` override the contact's address is not documented in the part read (section 16), so the connector does not send both.

## 9. Refunds

A refund is a credit note (`POST /v1/credit-notes`) [documented]. Linking: `?precedingSalesVoucherId=<invoice id>` links the credit note to the invoice; only one credit note can be linked to one invoice; a **draft** invoice cannot be referenced (HTTP 406); once finalised, a linked credit note is immediately `paidoff` and reduces the invoice's open amount; an unlinked finalised credit note stays `open` until a person assigns it [documented].

Flow:

1. Find the order's invoice by marker (section 6). Not found: SYNC-VAL-002 "the order has no invoice in Lexware".
2. Find an existing credit note of this refund (`GET /v1/voucherlist?voucherType=creditnote&contactId=...&voucherDateFrom/To=...`, then `GET /v1/credit-notes/{id}` and the marker `WC-{refund id}` in `introduction`). Found: done.
3. Create the credit note with the refund lines (no line discounts: credit notes have none [documented]). Link to the invoice only when the invoice is not a draft and has no related credit note yet (`relatedVouchers` is read from `GET /v1/invoices/{id}`); otherwise create it unlinked and write the invoice number into `introduction` (the printed credit note does not show the invoice number [documented]). `finalize` follows `invoice_state`.
4. Read back and compare the total with the refund amount; the same one-minor-unit rule as for orders.

Only the first credit note of an invoice can be linked, so a second partial refund of the same order is created unlinked and the result says so. The connector never cancels an invoice.

## 10. Payments

None. The Payments endpoint is read-only (`GET /v1/payments/{voucherId}`) [documented in the endpoint list; the chapter was not read], so the connector records no payment and has no payment option.

## 11. Stock, tracking, invoice read-back, reconciliation

- Stock and tracking: Lexware documents none (articles have no quantity field in the chapter read) [documented]. Not flagged.
- Invoice read-back (`InvoiceReadback`): `fetchInvoice( ref )` reads `GET /v1/invoices/{id}`: `voucherNumber`, `voucherStatus` (`draft`, `open`, `paid`, `paidoff`, `voided`, ...), `electronicDocumentProfile`. `authority_status` is empty (Lexware has no tax-authority submission step for ordinary invoices; an XRechnung/ZUGFeRD profile is read-only on the organisation [research]). `pdf_url` is the deeplink `https://app.lexware.de/permalink/invoices/view/{id}` [documented for credit notes, inferred for invoices]. `issued_at` is the finalise date when the status is not draft.
- Reconciliation: not flagged. `GET /v1/voucherlist` carries number, contact, dates, totals and status but not our marker [documented response fields], so matching a night's invoices to orders would need one `GET /v1/invoices/{id}` per voucher at 2 requests per second, including invoices that did not come from the shop.
- `ShadowLookup`: not flagged. Shadow mode compares an item with the system's own record for the same reference; Lexware has no reference field and `findExisting` has no payload to search with (section 6), so the comparison would always say "not found". `lookupCustomer` (contact by email), `lookupProduct` (article number) and `searchProducts` work and serve the mapping screens.

## 12. Options (connection config, `options` block)

| Key | Values | Default |
| --- | --- | --- |
| `invoice_state` | `draft`, `open` | `draft` |
| `refund_state` | `draft`, `open` | follows `invoice_state` |
| `customer_policy` | `match_or_create`, `match_only` | `match_or_create` |
| `duplicate_customer` | `oldest`, `fail` | `oldest` |
| `unknown_sku` | `refuse`, `custom` | `refuse` |
| `total_mismatch` | `fail`, `warn` | `fail` |
| `total_tolerance` | 0 to 50 minor units, or empty | empty: one per document row, at most 10 |
| `shipping_type` | `delivery`, `service`, `none` | `delivery` |
| `unit_name` | text | `Stück` |
| `timezone` | PHP time zone name | `Europe/Berlin` |

## 13. Rate limits and pacing

2 requests per second per client across all endpoints, a token bucket; over the limit the call is **not performed** and answers 429 without `Retry-After` [documented]. The authorization server has separate, undocumented limits and blocks a client for seconds to minutes [documented]. `rateLimitHints()` returns 1.7 requests per second (an interval of about 600 ms, a safety margin under 2) and concurrency 1. `ping()` is one call, `GET /v1/profile` (no request at all when the key is past its end date). 429 maps to SYNC-RATE-001; Lexware sends no `Retry-After`, so the wait is the base default of 30 seconds and the engine's retry delays grow from there. There is no daily cap.

## 14. Error mapping

| Situation | SYNC code | Notes |
| --- | --- | --- |
| Timeout, reset, name not resolved | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | |
| 401 | SYNC-AUTH-001 | key wrong, revoked, or (after `key_expires_on`) SYNC-AUTH-005 |
| 403 | SYNC-AUTH-004 | the key lacks a permission; the message names the area (contacts, invoices, ...) from the path |
| 404 on a resource id | SYNC-VAL-002 | record deleted between two steps (retried once) |
| 406 with an `IssueList` | SYNC-VAL-002 | `i18nKey` and a short sanitised reason; an entry naming `taxRatePercentage` (an unsupported rate): SYNC-MAP-002; draft invoice referenced by a credit note: handled before the call |
| 400, 405, 415 | SYNC-VAL-002 | |
| 402 | SYNC-AUTH-006 | plan does not include API access [inferred; see below] |
| 409 (`version` conflict) | SYNC-NET-002 | retry after a fresh read |
| 429 | SYNC-RATE-001 | no `Retry-After`; the wait is 30 seconds |
| 500, 503, 504 | SYNC-NET-002 | retry |

Only specific vendor codes map to SYNC-AUTH-006: the connector does not guess from the word "plan". 402 is the one status in the documented error list (`npm` client: regular errors include 402) that fits a licence block; whether Lexware really answers it for a non-XL organisation is not documented. Secrets: the key is scrubbed from every message; organisation id, contact names and addresses never appear in errors or logs.

## 15. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | true | Invoices (`/v1/invoices`) |
| `customers` | true | Contacts |
| `refunds` | true | Credit notes |
| `stock_in` | false | No stock in the API |
| `tracking_in` | false | No shipment record |
| `prices_in` | false | Not read |
| `invoice_readback` | true | Number and status |
| `reconciliation` | false | The voucher list has no reference (section 11) |
| `webhooks` | false | Event subscriptions exist [documented] but the plugin polls |
| `shadow_lookup` | false | No reference field; `findExisting` cannot search without the payload (section 11) |

Capability record (connection-methods spec section 4): `order_document` `invoice`, `refund_document` `credit_note`, `stock_read` `none`, `tracking_read` `no`, `customer_lookup_by_email` `yes`, `product_lookup_by_sku` `yes` (article number), `reference_field` `none` (marker in `introduction`), `native_idempotency` `none`, `webhooks` `none`, `rate_limit` 2 per second, `requires` "Lexware Office Version XL".

## 16. Not verified

- The Invoices chapter, Paging of Resources, Error Codes (regular and legacy bodies), the FAQ (valid tax rates, datetime format, text formatting and length limits of `title`, `introduction`, `remark`, `name`) and the Voucherlist, Profile and Payments chapters of https://developers.lexware.io/docs/ could not be read on 2026-11-02 (the page is cut off in the middle of Credit Notes for the fetch tool). Everything about invoices is taken from the Credit Notes chapter (same shape) and from the third-party mirror of the create schema.
- The Voucherlist filter names `voucherType`, `voucherStatus`, `contactId`, `voucherDateFrom`, `voucherDateTo`, `size`, `page`: response fields are documented by the Microsoft connector page, the filters are recalled from the reference [third-party]. If `contactId` is not a filter the search degrades to the day window alone (more `GET /v1/invoices/{id}` calls, still correct).
- The 406 `IssueList` entry shape (`i18nKey`, `source`, `type`) is [third-party] (Chift). That `source` (or the `field` of a `details` entry) carries the path `...taxRatePercentage` for an unsupported rate is [inferred]; if Lexware words it differently the rate problem is reported as SYNC-VAL-002 with the short reason instead of SYNC-MAP-002. The regular error body (`timestamp`, `status`, `error`, `message`, `path`, `details`) is not confirmed; the connector reads whichever of these keys is present.
- Page size: `size` up to 250 [third-party]; the connector asks for 250 and accepts whatever the answer's `size` says.
- Supported `taxRatePercentage` values (an FAQ page). Articles accept 0, 7, 19 since March 2024 [documented]; vouchers historically also 5 and 16. `listTaxCodes` offers 19, 7, 0 and the special types and allows the merchant to type another percentage in the tax mapping.
- Whether `finalize` can be applied to an existing draft (no such endpoint found), whether invoices can be deleted (not found), whether an invoice's `voucherDate` survives finalising.
- Whether Lexware answers a non-XL organisation with 401, 402 or 403 and what the text says.
- Whether address fields sent together with `contactId` override the contact's address.
- Who may create keys (role) and whether a key shows its end date in the list (the connector asks the merchant to type it).
- `shippingConditions` being required on invoices (the third-party schema marks `shippingType` required). If Lexware does not require it, the extra object is harmless.
- The invoice deeplink path `/permalink/invoices/view/{id}`.
- Terms of the API licence (`terms_url`): the page is named on https://www.lexware.de/partner/public-api/ (fetched 2026-11-02, which also says the API causes no extra cost from Version XL), but the licence page itself could not be fetched; its text is unread. Check it for use by a free plugin distributed to many merchants before publishing.

## 17. Manual smoke test (real organisation, no secrets in the repository)

1. In a Lexware Office organisation on Version XL (a trial works) create a key with all permissions; note its end date.
2. Add the connection, paste the key and the date. Expected: Test connection shows the company name, subscription status and tax type from the profile. A wrong key gives SYNC-AUTH-001.
3. Create an article with article number `TEST-1` (19 %). Place a WooCommerce order for `TEST-1` with a German billing address. Expected: one contact, one draft invoice whose introduction starts `Bestellung WC-{id}`, total equal to the shop's. Run the sync again: no second invoice. Edit nothing.
4. Switch `invoice_state` to `open`, repeat with a new order: a numbered open invoice.
5. Refund the order: an unlinked draft credit note for a draft invoice; for an open invoice a linked credit note that is `paidoff` after finalising.
6. Order in another currency: SYNC-VAL-002. Order with 7 % and 19 % lines: both rates on one invoice. Order mixing a 19 % line with a tax-free line: SYNC-VAL-002.
7. Place 10 orders in quick succession and watch for 429 handling (2 requests per second).
