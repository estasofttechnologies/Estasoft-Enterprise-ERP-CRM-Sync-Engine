---
id: sage200
name: Sage 200
catalog_row: Sage 200, Sage 300 and Sage X3
connection_method: own_app
available: true
verified: false
hosts: [id.sage.com, api.columbus.sage.com]
terms_url: https://www.sage.com/en-gb/legal/
privacy_url: https://www.sage.com/en-gb/legal/privacy-and-cookies/
developer_url: https://developer.sage.com/200/guides/authenticating/
variants: [{ id: sage200, label: "Sage 200 (Standard and Professional, UK)" }]
---

# Sage 200 connector

Connector id `sage200`, type ERP, contract version 1. Code: `includes/Connectors/Sage200/` (shared order and money helpers in `includes/Connectors/ErpCommon/`). One of the three products of the catalog row "Sage 200, Sage 300 and Sage X3"; each has its own connector, provider and document (`sage300.md`, `sagex3.md`).

The connector sends WooCommerce orders to Sage 200 as Sales Order Processing orders (`sop_orders` with their lines), refunds as SOP returns (`sop_returns`), and customers. It reads nothing back except the order or return itself (to find it again and check its total); there is no nightly list (section 11). **This is the least verified of the connectors in the build: the official Sage 200 API reference pages could not be read (section 1).** Resource and field names come from the authentication and FAQ guides (documented) and from open-source clients that call the live API (third-party, MIT or Apache licensed). Test connection probes every resource the connector uses, so a wrong name shows when connecting, not on the first order.

Supported: Sage 200 Standard and Sage 200 Professional (online, UK). The API paths use a `uk` segment; whether other countries exist is not stated (`regions` is UK only).

## 1. Sources

Fetched or read on 2026-11-02. `developer.sage.com` reference pages and the Sage legal pages were refused by the fetch tool (not on its allow list); the pages marked `[documented]` were read.

| Source | Used for |
| --- | --- |
| https://developer.sage.com/200/guides/authenticating/ `[documented]` | OAuth 2.0 authorization code via Sage ID; authorize `https://id.sage.com/authorize` with `audience=s200ukipd/sage200`; token `https://id.sage.com/oauth/token`; scope `openid profile email offline_access`; access token 28,800 s (8 h); refresh token up to 90 days (set in the credential request form); renewal body `client_id`, `client_secret`, `redirect_uri`, `refresh_token`, `grant_type=refresh_token`; headers `Authorization: Bearer`, `X-Company`, `X-Site`, `Content-Type: application/json`; base URLs `https://api.columbus.sage.com/uk/sage200/accounts/` (Standard) and `.../sage200extra/accounts/` (Professional/Extra); the full redirect address must be listed in the credential request form |
| https://developer.sage.com/200/guides/api_faq/ `[documented]` | 500 rows by default, `$top` up to 5,000, `$skip` needs `$orderby`; no OData `In`; 401 for an expired token or a missing/invalid `X-Site`/`X-Company`; 403 when the user's role lacks the module; 404 wrong URL, verb or endpoint missing in the version; 524 database not configured; no sandbox, each API configuration belongs to one subscription; the old authentication method ended 19 Feb 2021; Professional on-premise sites may have older versions with missing endpoints |
| https://developer.sage.com/200/guides/ and /200/reference/ `[documented]` | Titles only: SOP (Sales Orders), Sales, Stock, Commercials categories per release; no endpoint pages reachable |
| https://github.com/prismatic-io/components (`components/sage-200`, MIT) `[third-party]` | Header `ocp-apim-subscription-key`; base `.../accounts/v1`; `GET /sites` (`company_id`, `company_name`, `tenant_id`, `site_id`, `site_name`, `site_short_name`); `/customers`, `/customer_contacts` (`default_email`), `/customer_delivery_addresses`, `/products`, `/tax_codes` (`id`, `code`, `name`, `tax_rate`), `/sop_orders` (POST body `customer_id`, `document_date`, `customer_document_no`, `customer_delivery_address_id`, `exchange_rate`, `use_invoice_address`; GET answers `total_net_value`, `total_tax_value`, `total_gross_value`, `currency_id`, `currency{is_base_currency}`, `lines`), `/sop_order_lines` (`line_type` `EnumLineTypeStandard`, `product_id`, `line_quantity`, `selling_unit_price`, `unit_discount_percent`, `tax_code_id`, `line_total_value`, `line_tax_value`), `date_time_updated gt <ISO>` as a filter, bare JSON arrays, `$orderby=id` paging |
| https://github.com/hotgluexyz/target-sage-200 `[third-party]` | Posting `sop_orders` with nested `lines` (each `product_id`, `line_quantity`, `tax_code_id`, optional `selling_unit_price` and `unit_discount_percent`); `/sop_returns` takes the same body; ledger endpoints `/sales_invoices` and `/sales_credit_notes` post financial totals only; customers by `reference`, products by `code`, `$filter=<field> eq '<value with doubled quotes>'`; list answers are a bare array or `{"value": [...]}`; changing `selling_unit_price` needs the Sage permission "Override prices and discounts on order lines"; Standard base `https://api.columbus.sage.com/uk/sage200/accounts/v1`, Professional `https://api.columbus.sage.com/uk/sage200extra/accounts/v1` |
| docs/research/integration-research-vendors.md section 4.11 | Earlier research: ISV account and credential request form (up to 72 hours), Professional needs Azure AD tunnelling set up by the partner |
| https://www.sage.com/en-gb/legal/ | Fetched 2026-11-02: "Sage UK legal information", linking the terms and the privacy and cookies policy. The privacy and cookies page itself (front matter) is listed by web search but the fetch tool failed on it, so it was not read |

## 2. How the merchant connects

1. Ask Sage for API credentials: choose a web application and give the redirect address shown on this screen as its callback.
2. Copy the Client ID and Client Secret Sage sends into the fields here, and choose Standard or Professional.
3. Press Sign in with Sage and approve the access as a Sage 200 user whose role allows Sales Order Processing.
4. Choose the site and company if Sage lists several, then press Test connection.
5. In Sage 200 give the user the permission to override prices on order lines, and make the products and a tax code per rate.

Plan gate: the API is enabled by default on Standard and needs "API access" enabled for the user; on Professional the partner must set up the Native API with Azure AD tunnelling `[documented]`. Sage must accept the application request (up to 72 hours `[research]`), so the plugin cannot ship shared credentials: every merchant (or their Sage partner) requests their own. A refresh token lasts at most 90 days, as set on the form; after that the person signs in again (SYNC-AUTH-002).

## 3. Authentication

OAuth 2.0 authorization code through Sage ID with the merchant's own client (`own_app`). Authorize `https://id.sage.com/authorize?audience=s200ukipd/sage200`, token `https://id.sage.com/oauth/token`, client secret in the request body, scope `openid profile email offline_access`, no PKCE (not mentioned in the guide). Every API call carries `Authorization: Bearer <token>`, `X-Site`, `X-Company` and `Content-Type: application/json`, which are the headers the authentication guide lists. The header `ocp-apim-subscription-key` comes only from third-party clients: it is sent only when the merchant filled in the optional field `subscription_key`, and not at all otherwise. The access token is renewed under a lock by the shared token manager when less than five minutes remain; `credential_expires_at` is set 90 days after each refresh.

Stored: `client_id`, `edition`, `site_id`, `company_id`, `subscription_key` (optional) is a **secret** like the client secret and the tokens. After sign-in `GET /sites` lists the sites and companies the user can reach and the person picks one (`organisations()` and `selectOrganisation()`); the choice stores `site_id` and `company_id`, pinned on every call.

## 4. Data sent and when (External services disclosure)

Hosts: `id.sage.com` (sign-in, renewal) and `api.columbus.sage.com` (data).

| Action | Data sent | Data read |
| --- | --- | --- |
| Sign in / renewal | Client id, client secret, code or refresh token | Access token, refresh token |
| Test connection | Nothing | One record each of tax codes, customers, contacts, products, orders and returns; the sites, to name the company |
| Order synced | Buyer name, email, phone, address, order number, order date, line SKUs, quantities, prices, tax codes, shipping and fee lines, note | Customer by reference or contact email, products by code, tax codes, order by customer document number, totals back |
| Refund synced | Refund lines, quantities, amounts, refund number | Order by customer document number, return by number |

## 5. Objects and field mapping

Order payload paths are the shared ones of the ERP connectors; the default rules are `ErpCommon\DefaultRules` (the same list the ERPNext connector uses).

| Payload path | Sage 200 field | Notes |
| --- | --- | --- |
| client reference (queue item) | `sop_orders.customer_document_no` | `WC-{order id}` |
| `date_order` | `document_date` | ISO `YYYY-MM-DDT00:00:00Z`, the shop's date |
| `currency` | not sent | Sage 200 orders use the customer's currency; see below |
| `note` | not sent | No documented plain note field on the order (memos are a separate resource) |
| `partner.*` | Customer `reference`, `name`, `short_name`, and contact `default_email`, `default_telephone` | Customers are found by contact email and created with the options below |
| `delivery.*` | `customer_delivery_addresses` and `customer_delivery_address_id` | Created only when it differs from the invoice address |
| `order_line[].product` | line `product_id` (resolved from the product `code`) | SKU equals product code; unknown SKU: SYNC-MAP-001 |
| `order_line[].quantity`, `price_unit` | `line_quantity`, `selling_unit_price` | Needs the Sage permission to override prices |
| `order_line[].tax` | `tax_code_id` (resolved from the tax code number) | One tax code per line |
| `shipping.total`, `fee[].total` | lines with `product_id` of the service products `shipping_product`, `fee_product` | Refused when the option is empty and the amount is above zero |
| `discount.total` | a line with `discount_product` and a negative price | Refused when the option is empty |

Options (config): `edition` (`standard`, `professional`), `base_currency` (ISO code, default `GBP`), `customer_policy` (`match_or_create` default, `match_only`), `customer_reference_prefix` (up to three letters, default `WEB`), `default_tax_code` (number, for new customers), `country_code_id`, `shipping_product`, `fee_product`, `discount_product`, `refund_product`, `total_mismatch` (`fail`, `warn`). Ids are sent as whole numbers (Sage 200 ids are integers `[inferred]`).

Currency: a Sage 200 order takes the currency of its customer. The connector sends orders only in the company's base currency (`base_currency`, default GBP): an order in another currency is refused with SYNC-VAL-002 naming both currencies, before anything is created. After the create the answer's `currency.is_base_currency` must be true and `total_gross_value` must match the shop total, otherwise the order is left and reported (section 6).

Customer flow: contact search `GET /customer_contacts?$filter=default_email eq '<email>'&$orderby=id` (paged), then `GET /customers/{customer_id}`; the first customer whose `account_status_type` is active. A match in lower case is accepted too. When none and the policy allows it, `POST /customers` with `reference` (at most eight characters, the length of a Sage 200 account reference `[inferred]`: the prefix, three letters at most, and the order number in base 36 padded to fill eight, for example `WEB0002S`; the same order always gives the same reference), `name`, `short_name` (15 characters), the invoice address fields `address_1`, `city`, `county`, `postcode`, `currency_id` of the base currency (read once from `GET /currencies`, the one with `is_base_currency`), `country_code_id` and `default_tax_code_id` (options), then `POST /customer_contacts` with `customer_id`, `name`, `default_email`, `default_telephone`. Before creating, the customer is looked up by that reference, so a pass that stopped after the customer was made (before its contact) finds it again instead of making another. Both are `[third-party]` shapes; required fields vary by company setup and a refusal is reported as SYNC-VAL-002 with Sage's message.

## 6. Order flow

1. Parse the payload; refuse a foreign currency; check email, name, lines, options.
2. Find the order: `GET /sop_orders?$filter=customer_document_no eq 'WC-{id}'&$orderby=id`. An order found is never created again; the pass continues with the check in step 6.
3. Resolve each SKU: `GET /products?$filter=code eq 'SKU'` (chunks of one: the `In` filter is not supported). Missing: SYNC-MAP-001 before a customer or order is made. Service lines resolve their products the same way.
4. Resolve tax codes: `GET /tax_codes` once (code number to id and rate). Unknown: SYNC-MAP-002.
5. Find or create the customer (and the delivery address if it differs: `GET /customer_delivery_addresses?$filter=customer_id eq {id}`, reused when the first line and post code match, else `POST /customer_delivery_addresses`).
6. `POST /sop_orders` with the header and the nested `lines`. Read the answer (or `GET /sop_orders/{id}` when the answer lacks totals): `currency.is_base_currency` and `total_gross_value` against `total - excluded_total` within one minor unit. A difference leaves the order in Sage for the person and stops the item (`total_mismatch = fail`, default) or goes on with a warning (`warn`). The next pass finds the order and checks it again.

Reference returned: the order id, with the `document_no` as its number. `extra` has `total_minor`, `currency`, `status`. `findExisting()` returns a reference for any order found by its Customer Document No (it has no payload to compare with); `send()` reads an existing order back and checks it again with the payload, so a wrong total keeps being reported on every pass until the person deletes the order in Sage 200 (there is no hold state to put it in). No later steps exist (no invoice, no payment), so an existing order is finished. The answer of a `POST` may hold only the id and number; the connector then reads `GET /sop_orders/{id}` for the totals.

Tax-included prices: payload prices are tax-exclusive by default. With `prices_include_tax` each price is converted with whole-number arithmetic using the line's tax rate from `/tax_codes`. Lines without a tax code are sent as they are.

Dates: `document_date` is the date sent; Sage 200 keeps it `[inferred]`.

## 7. Duplicate protection

`customer_document_no` holds `WC-{id}` and is searched before every create. Sage 200 offers no idempotency key and no documented unique constraint on this field. A refund's SOP return holds `WC-{refund id}` in the same field and is searched in `/sop_returns`.

## 8. Taxes

The tax code of a line is the **tax code number** (`code` in `/tax_codes`; for example 1 for standard rate). `listTaxCodes()` returns `code`, `name` and `tax_rate` as a percentage (the documented example shows `tax_rate: 20`). Sage works out tax per line from the tax code; the connector reads it back through `total_tax_value`/`total_gross_value`.

## 9. Payments

Not implemented. The documented surface for receipts in the portion read is a ledger posting without a link to a sales order. The connector does not record payments and does not flag that.

## 10. Refunds

A refund is a `POST /sop_returns` with the same body as an order: `customer_id` of the order's customer, `customer_document_no = WC-{refund id}`, `document_date`, and one line per refunded product (positive `line_quantity`, `selling_unit_price` the line amount divided by the quantity, `tax_code_id` copied from the line of the same product on the original order, read with `GET /sop_orders/{id}`). A refund of shipping is a line with the `shipping_product`, its amount (the shop gives it with tax) taken out of tax with the rate of the tax code of the original shipping line; a refund without lines is one line with the `refund_product`, sent as given. After the create, the return's `total_gross_value` is compared with the refund amount (one minor unit); a difference is reported on this and every later pass (`total_mismatch = warn` only warns) until the person deletes the return. A SOP return does not credit the customer until a person processes it in Sage 200 `[third-party]`, which is the safe default. The posting of a ledger credit note (`/sales_credit_notes`) is not built. The original order must exist (`customer_document_no = WC-{order id}`), otherwise SYNC-VAL-002 before any other request. Duplicate search: `/sop_returns?$filter=customer_document_no eq 'WC-{refund id}'`.

## 11. Reading from Sage 200

- **No nightly list.** `listForReconcile` is not provided and the `reconciliation` flag is off: a SOP return carries only `WC-{refund id}`, so the connector cannot tell which order it refunds, and a list with `refunded_minor` always 0 would raise a false `refund_missing` every night for every refunded order.
- `lookupCustomer`, `lookupProduct`, `searchProducts` (`name` or `code` filter), `listTaxCodes`: read-only helpers.
- Stock, tracking and invoice read-back are **not** provided. The product payload in the third-party example carries `free_stock_quantity`, but the official page that would document it could not be read, so `stock_in` is not flagged.

## 12. Rate limits and pacing

No limit is stated in the guides read (the FAQ says nothing about rate limits). The connector sends one request at a time, two a second at most, pages of 500 rows, 30 s timeout, and obeys `Retry-After` on 429 (SYNC-RATE-001). `rateLimitHints()` returns 2.0 and concurrency 1.

## 13. Error mapping

| Sage answer | SYNC code | Class |
| --- | --- | --- |
| Time-out, reset, DNS | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| 5xx, 524 (database not configured) | SYNC-NET-002 | retry |
| 401 after one refresh | SYNC-AUTH-002 (the base mapping for a sign-in), SYNC-AUTH-001 when the token endpoint refuses the client | needs a person |
| 401 whose text names `X-Site` or `X-Company` | SYNC-VAL-002 naming `site_id` (sign in again and choose the site and company) | needs a person |
| 401/403 with a body that names the subscription key (`Access denied due to missing subscription key`, `invalid subscription key`) | SYNC-AUTH-007 (Sage does not know this app/subscription) | needs a person |
| 403 | SYNC-AUTH-004, naming the module | needs a person |
| 404 on a resource the connector needs | SYNC-VAL-002 "this Sage 200 version does not offer {resource}" (Test connection lists every resource that answered so) | needs a person |
| 429 | SYNC-RATE-001 | retry |
| 400/422 with a validation body | SYNC-VAL-002 with Sage's cleaned message; the "Unit Selling Price" permission text names the permission | needs a person |
| Missing product | SYNC-MAP-001 | needs a person |
| Missing tax code | SYNC-MAP-002 | needs a person |
| Missing email | SYNC-MAP-003 | needs a person |
| Currency, total, missing option | SYNC-VAL-002 | needs a person |

## 14. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| orders | yes | `sop_orders` with lines |
| customers | yes | `customers`, `customer_contacts`, `customer_delivery_addresses` |
| refunds | yes | `sop_returns` |
| stock_in | no | Not documented in pages that could be read |
| tracking_in | no | Not found in any source |
| prices_in | no | |
| invoice_readback | no | Posted invoices do not appear as an entity `[third-party]` |
| reconciliation | no | A SOP return carries only the refund reference and cannot be tied to its order, so the nightly check would report `refund_missing` for every refunded order; the flag is off rather than wrong |
| webhooks | no | Not found |
| shadow_lookup | yes | Read-only |

## 15. Not verified

- Every resource and field name in section 5 other than those of the authentication guide: they are `[third-party]` (Prismatic, hotglue) or `[inferred]`. The `$filter` forms (`eq`, `ge`, `gt` with an unquoted ISO date) are as the open-source clients use them; the guide lists "supported filters" on a page that could not be read.
- Whether `customer_document_no` accepts 20+ characters and is filterable; the length limit of the field.
- The `GET /currencies` resource and its `is_base_currency` field; the creation body of customers and contacts (required fields differ per company).
- Behaviour when a Sage period is closed, when the customer is on hold (`override_on_hold`, `is_editing`, `suppress_warnings` exist on the order body) or over their credit limit.
- Rate limits and the 90-day refresh behaviour in practice.
- The body of a refused client secret at `id.sage.com` (the connector expects the standard `invalid_client`; Sage ID is Auth0-based and may answer `access_denied` with `Unauthorized`, which the shared token code reads as a sign-in that was not completed, SYNC-AUTH-003, instead of SYNC-AUTH-001).
- That Sage 200 ids are integers in request bodies, the 8-character account reference, the fields of `customer_contacts`, `customer_delivery_addresses` and the invoice address on `customers`, and the shape of the answer of a `POST`.
- That a 401 for a wrong `X-Site` or `X-Company` names the header (the connector reads such a text as a wrong site; any other 401 is read as an expired sign-in).
- Whether Professional on-premise sites older than 2018 expose `sop_returns`.
- The privacy and cookies page was not read (the legal index, the terms address, was).
- A free third-party plugin using the API is covered by Sage's ISV terms, which could not be read; the credential request form is aimed at developers and customers (see research).

## 16. Manual smoke test against a real Sage 200

1. Use a Sage 200 Standard trial or a demo site with API access enabled for a test user, and a Sage developer subscription.
2. Request credentials on the Sage form with the plugin's redirect address, note the Client ID and secret (and the subscription key, if Sage sends one).
3. In Sage 200 create a product whose code equals a WooCommerce SKU, a tax code, and give the user the permission to override prices on order lines.
4. Add the connection, sign in, pick the site and company, press Test connection: expect the sites, company, tax codes and every resource probe passing.
5. Place an order in the base currency: expect one SOP order with Customer Document No `WC-{id}` and the shop total. Run it again: no second order.
6. Refund it: expect a SOP return with `WC-{refund id}`.
