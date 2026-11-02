---
id: sagex3
name: Sage X3
catalog_row: Sage 200, Sage 300 and Sage X3
connection_method: unknown
available: true
verified: false
hosts: []
terms_url: https://www.sage.com/en-gb/legal/
privacy_url: https://www.sage.com/en-gb/legal/privacy-and-cookies/
developer_url: https://online-help.sagex3.com/erp/12/en-us/Content/V7DEV/integration-guide_ws-create.html
variants: [{ id: sagex3, label: "Sage X3 (web services of your version)" }]
---

# Sage X3 connector

Connector id `sagex3`, type ERP, contract version 1. Code: `includes/Connectors/SageX3/` (shared order and money helpers in `includes/Connectors/ErpCommon/`). One of the three products of the catalog row "Sage 200, Sage 300 and Sage X3".

**The connection method of Sage X3 is to be confirmed.** The catalog shows it as "To be confirmed" (`connection_method: unknown`, docs/spec/connection-methods-spec.md section 2) because it depends on the merchant's version and on what their partner exposes (docs/spec/scope-update.md: "Still To be confirmed: Sage X3 (depends on version)"). This connector implements the one documented path, the Syracuse **REST web services at `/api1/`** with Basic authentication (on-premise installations), and nothing else. Sage X3 Online accepts OAuth 2.0 only; that mode is **not built**, so a merchant on Sage X3 Online cannot use this connector yet. SOAP is not built.

It sends WooCommerce orders to Sage X3 as sales orders through a class and a representation the merchant (or their Sage partner) chooses. Sage ships its representations read-only on all resources; creating and updating needs a class and representation whose facets allow it, so the class names are options and Test connection checks them. The connector sends orders and finds them again; it sends no refunds, reads no stock or tracking, and creates no customers. Every order goes on one customer; the buyer's name, email, address and the order note are not sent.

## 1. Sources

Fetched or read on 2026-11-02. Most of the online help (online-help.sagex3.com) was refused by the fetch tool; one page was read and the rest comes from a third-party reference that cites the Sage pages line by line.

| Source | Used for |
| --- | --- |
| https://online-help.sagex3.com/erp/12/en-us/Content/V7DEV/integration-guide_ws-create.html `[documented]` | `GET .../MYCLASS/$template?representation=MYREPR.$create`; `POST http://myserver:myport/api1/x3/erp/MYENDPOINT/MYCLASS?representation=MYREPR.$create` with a JSON body; success status 201 with a `Location` header; **a 201 only means the class and representation were found and the body accepted**; read the body for a `$diagnoses` list with `$severity` and `$message`; `$isMandatory` facet (the template marks `ORDDAT`); the answer can differ from the body because class and representation business rules run |
| https://github.com/actouf/sage-x3-l4g, `plugins/sage-x3-l4g/references/web-services-rest.md` `[third-party]`, which cites the Sage pages `integration-guide_ws-overview`, `_ws-query`, `_ws-read-details`, `_ws-create`, `_ws-update`, `_ws-delete`, `_ws-basic-authentication`, `_ws-certificates-authentication`, `_ws-oauth2-authentication`, `_ws-sdata-differences`, `api-guide_api-requests` | URL `https://<server>:<port>/api1/x3/erp/<ENDPOINT>/<CLASS>?representation=<REP>.$<facet>`; facets `$query`, `$details`, `$edit`, `$create`; list: `count` (default 20), `where=<predicate>`, `orderBy`; the answer is `{"$itemsPerPage", "$resources", "$links"}` and paging follows `$links.$next.$url`; `GET .../$prototypes('<REP>.$edit')` for property metadata; update `PUT`; Basic and client certificate are on-premise only, OAuth 2 is the only mode of Sage X3 Online; every call carries the header, no session cookie; a 200 on a read does not prove the record exists (`$diagnoses` "721 : Record does not exist"); composite keys joined with `~`; ETag concurrency is not available |
| docs/research/integration-research-vendors.md section 4.12b (official v12 help dated 8 Sep 2026, partner programme) | "Updates are limited to upgraded modules, classic modules use SOAP"; partner certification with 6 free support cases, acceptance discretionary; no public sandbox; KSA and UAE localisation not found |
| https://www.sage.com/en-gb/legal/ | Fetched 2026-11-02: "Sage UK legal information", linking the terms and the privacy and cookies policy; the privacy page itself was not read |

## 2. How the merchant connects

The steps are provisional because the method is to be confirmed.

1. Ask your Sage X3 partner for the https web service address, the folder name and a Syracuse user with Basic login.
2. Ask them for a sales order class and representation that allow creating orders, and note both names.
3. Paste the address, folder, user name, password, both names and the order total property here.
4. Enter the customer code that should receive the web orders, then press Test connection.
5. Sage X3 Online (OAuth 2 only) cannot connect with this connector yet.

Plan gate: Sage X3 web services must be enabled for the folder and the user must be mapped to an X3 user with a profile that may create sales orders. Sage partner certification is expected for production integrations `[research]`. The plugin refuses an `http` address (Basic sign-in) and private or loopback hosts unless the filter allows.

## 3. Authentication

HTTP Basic on every request (`BasicAuth`): `Authorization: Basic base64(user:password)`; the Syracuse user must use basic authentication and be mapped to an X3 user `[third-party]`. HTTPS is required. No session or cookie is stored. A next-page link in an answer is followed only when it has the same scheme, host and port as the address the merchant typed (a bare path is read as being on it); a link to another server is refused with SYNC-VAL-002 (field `address`) and no request, with its password, is sent there. Stored: `address`, `folder`, `username` (config), `password` (secret).

## 4. Data sent and when (External services disclosure)

Host: the address the merchant typed.

| Action | Data sent | Data read |
| --- | --- | --- |
| Test connection | Nothing | One customer record, the metadata (`$prototypes`) of the order representation, the order template |
| Order synced | Order number, order date, currency, line item codes, quantities, prices, order note | The order by its reference, totals back |
| Nightly check | Nothing | Not provided |

## 5. Objects and field mapping

The names of the sales order class, representation and properties differ between folders and partners. They are options with defaults taken from the standard X3 sales order and are checked against the representation's metadata when connecting. The defaults are **[inferred]** from the standard product (the sources read name only `ORDDAT`, `BPCNAM`, `BPCNUM`, `BPCUSTOMER`, `SORDER`):

| Option | Default | Meaning |
| --- | --- | --- |
| `order_class` | `SORDER` | Class code |
| `order_representation` | `SOH` | Representation with a `$create` facet that allows creating |
| `prop_customer` | `BPCORD` | Customer code property |
| `prop_date` | `ORDDAT` | Order date (documented as mandatory in the template example) |
| `prop_reference` | `CUSORDREF` | Customer order reference, holds `WC-{id}` |
| `prop_currency` | `CUR` | Currency |
| `prop_lines` | `SORDERQ` | Name of the line collection in the body |
| `prop_line_item`, `prop_line_quantity`, `prop_line_price` | `ITMREF`, `QTY`, `GROPRI` | Line properties |
| `prop_number` | `SOHNUM` | Order number property read from the answer (else the number in the `Location` header) |
| `prop_site` | `SALFCY` | Sales site property, used when `site` is set |
| `item_class`, `item_representation`, `prop_item_code` | `ITMMASTER`, `ITMMASTER`, `ITMREF` | Class used only to check that a SKU exists before the order is sent (read-only `$query`); `[inferred]` names. Save `item_class` as `none` to skip the check (an empty setting means the default), in which case an unknown item is reported by X3 itself as a `$diagnoses` error |
| `customer_class`, `customer_representation`, `prop_customer_code` | `BPCUSTOMER`, `BPCUSTOMER`, `BPCNUM` | Used only by Test connection to check that `customer_code` exists; save `customer_class` as `none` to skip; a folder that does not offer the class skips the check |
| `shipping_item`, `fee_item`, `discount_item` | (empty) | Item codes of service items that carry shipping, fees and a discount as lines (quantity 1, negative price for a discount); an order with such an amount is refused naming the setting when it is empty |
| `customer_code` | (required) | The customer that receives the web orders |
| `site` | (optional) | Sales site, sent as `prop_site` (`SALFCY`) when set |
| `total_property` | (required) | Property of the order that holds its total, for the total check. Prices from the folder's price lists can differ from the shop's unnoticed, so Test connection fails without it, and an order is refused before anything is sent, unless `total_mismatch` is set to continue with a warning |

Payload to X3: `order_line[].product` (SKU = item code, `ITMREF`), `quantity`, `price_unit`; `date_order` (`YYYY-MM-DD`); `currency` (ISO code; must be the customer's currency or the order is refused); `note` is not sent. Shipping, fee and discount amounts: sent as lines with the service items of the settings above; refused (SYNC-VAL-002 naming the setting) when the setting is empty, because the standard way to carry them (additional charge lines or price lists) differs per folder and nothing read documents it. This keeps totals honest.

Customers: not created (`customers` is not flagged); `partner.*` is not sent. All orders go on `customer_code`.

## 6. Order flow

1. Parse the payload (the buyer is not needed); check the customer code, currency, lines and the item settings of shipping, fee and discount.
2. Find the order: `GET /api1/x3/erp/{folder}/{class}?representation={rep}.$query&where={prop_reference} eq 'WC-{id}'&count=20` and follow `$links.$next` to the end. A row in `$resources` means it exists; the pass stops there. A `$diagnoses` error in the answer is a failure, not "not found".
3. Resolve each SKU, and each service item, with one `GET .../ITMMASTER?representation=ITMMASTER.$query&where=ITMREF eq 'SKU'` per code (the `where` grammar is documented with `eq` and quoted strings; an `or` chain is not documented). A missing item is SYNC-MAP-001.
4. `POST ...?representation={rep}.$create` with the JSON body. Check the answer: any `$diagnoses` entry with `$severity` `error` is a failure (SYNC-VAL-002 with the first message), whatever the HTTP status is.
5. Currency check: when the answer names a currency that is not the order's, the order stays in X3 and the item stops (SYNC-VAL-002 naming both; X3 takes the currency of the customer). Total check: the answer's `total_property` value against `total - excluded_total` within one minor unit (`fail` or `warn` by `total_mismatch`). With `total_mismatch = fail` (the default) the check cannot be skipped: no `total_property` means nothing is sent (SYNC-VAL-002, field `total_property`), and an answer without the value means the item stops with the order left in X3 (checked again on every pass); only `warn` turns either into a warning. An existing order is read back and checked again on every pass, so a wrong total is reported until the person deletes the order in X3.

The reference returned is the order number read from the answer (`prop_number`) or the `Location` header; `extra` has the currency (the order's) and `total_minor` (always, unless `warn` was chosen and the answer had no total). `findExisting()` returns it for an existing order (no later steps).

## 7. Duplicate protection

The customer order reference property holds `WC-{id}` and is searched before every create. Sage X3 has no idempotency key; a local ledger plus lookup is the only protection (docs/research §4.12b). The class may already refuse a duplicate; its message is passed on.

## 8. Taxes

Tax is determined by X3 from the customer, the item and the folder's rules. The connector sends no tax codes and `listTaxCodes()` returns none. Prices are sent without tax; `prices_include_tax` is refused. A wrong tax setup shows in the total check when `total_property` is set.

## 9. Payments, refunds, reading back

Not implemented, not flagged: no payment, credit note, stock, tracking or invoice path was read in the Sage pages that could be fetched (the research found `SORDER` only in a forum title and no stock, invoice or item class confirmed). The only flag is `orders`. The nightly check is not provided. `lookupProduct` and `searchProducts` (by exact item code) work through the item class but the `shadow_lookup` flag is not set, because customers cannot be looked up by email (the property is not documented).

## 10. Rate limits and pacing

None stated (docs/research: "no rate limits found"). Page size is 20 by default and the server may cap it (`$itemsPerPage` is read). The connector sends one request at a time, one a second, with a 30 s timeout. 429 and Retry-After are obeyed (SYNC-RATE-001).

## 11. Error mapping

| Sage X3 answer | SYNC code | Class |
| --- | --- | --- |
| Time-out, reset, DNS | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| 5xx | SYNC-NET-002 | retry |
| 401 | SYNC-AUTH-001 | needs a person |
| 403 | SYNC-AUTH-004 (the X3 profile may not create sales orders) | needs a person |
| 404 on a class or representation | SYNC-VAL-002 "the folder does not expose {class} with {representation}" | needs a person |
| 4xx/5xx with a body | SYNC-VAL-002 with the cleaned message | needs a person |
| 200/201 with `$diagnoses` of severity error | SYNC-VAL-002 with the first `$message` | needs a person |
| HTML login page, redirect | SYNC-AUTH-001 ("Basic authentication is not enabled for this user or folder") | needs a person |
| Missing item | SYNC-MAP-001 | needs a person |
| Currency, option, shipping/fee/discount without their item settings | SYNC-VAL-002 | needs a person |

## 12. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| orders | yes | `$create` on the configured representation (documented path) |
| customers | no | Not created |
| refunds | no | No credit note path read |
| stock_in | no | Not confirmed |
| tracking_in | no | Not confirmed |
| prices_in | no | |
| invoice_readback | no | |
| reconciliation | no | No list path built |
| webhooks | no | |
| shadow_lookup | no | Customers cannot be looked up by email; `lookupProduct` by item code exists but is not enough for the flag |

## 13. Not verified

- **The connection method** (to be confirmed per version): on-premise Basic is built; Online OAuth 2 and the connected application are not; client credentials on-premise was reported as a Sage bug `[research]`.
- **Whether a standard class and representation of a sales order allow `$create` on the merchant's version**; Sage's own text says updates are limited to upgraded modules. Nothing here proves `SORDER` and `SOH` exist and are writable.
- All property names and default class and representation codes in section 5.
- The shape of `$prototypes('REP.$edit')` (read as `$properties` with collections under `$item.$properties`; a folder that does not answer it is accepted without the property check), and `SOHNUM` as the order number property.
- Paging: the `$links.$next.$url` form was followed as documented; the fake used for tests numbers its pages with `startIndex`.
- The `where` grammar beyond `eq` and quoted strings; paging by `$links.$next`.
- The form of `$diagnoses` in successful answers and the `Location` format.
- Partner certification and whether a free plugin may use the web services.
- The terms address (the Sage legal index) was read on 2026-11-02; the privacy and cookies page is listed by web search but the fetch tool failed on it.

## 14. Manual smoke test against a real Sage X3

1. Use a Sage X3 v12 folder (SEED demo or a partner sandbox) with web services enabled and a Syracuse user with Basic authentication.
2. Ask the partner for the sales order class and a writable representation; check that `GET .../SORDER/$template?representation=SOH.$create` answers with a template.
3. Add the connection with the address, folder, user, password, class names and a customer code. Press Test connection: expect the prototype properties found and the template answered, or a message naming the missing class, representation or property.
4. Place an order: expect one sales order with the customer order reference `WC-{id}`. Run it again: no second order. Check a failing body: the sync must show the `$diagnoses` message.
