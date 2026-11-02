---
id: hubspot
name: HubSpot
catalog_row: HubSpot
connection_method: key
available: true
verified: false
hosts: [api.hubapi.com]
terms_url: https://legal.hubspot.com/developer-terms
privacy_url: https://legal.hubspot.com/privacy-policy
developer_url: https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/account-service-keys
variants: []
---

# HubSpot connector

Connector id `hubspot`, type CRM, contract version 1. Code: `includes/Connectors/HubSpot/` (shared CRM helpers in `includes/Connectors/CrmCommon/`). It sends WooCommerce customers as contacts, orders as deals with line items, and refunds as a note on the deal. It does not read stock, tracking or invoices, because HubSpot has none of them for a shop.

## 1. Sources

All fetched 2026-11-02 with WebFetch. `[documented]` = official HubSpot page, `[third-party]` = community or integration vendor page, `[inferred]` = reasoned from the above and not stated.

| Page | Used for |
| --- | --- |
| https://developers.hubspot.com/docs/apps/developer-platform/build-apps/authentication/account-service-keys | Service keys: who can create, steps, Bearer header, scopes, 100-key account cap, REST only, no webhooks |
| https://developers.hubspot.com/docs/api-reference/crm/search | `POST /crm/objects/{version}/{object}/search`, filters, operators, limits |
| https://developers.hubspot.com/docs/guides/crm/using-object-apis | Create with `properties` and `associations`, batch endpoints, `idProperty`, status codes, deal required properties |
| https://developers.hubspot.com/docs/api-reference/latest/crm/objects/line-items/guide.md | Line item properties, association to a deal, "duplicate line items are silently merged" |
| https://developers.hubspot.com/docs/api-reference/latest/crm/objects/products/guide | Product properties (`name`, `price`, `hs_sku`) |
| https://developers.hubspot.com/docs/api-reference/2026-03/crm/activities/notes/guide.md | Notes: `hs_timestamp` required, `hs_note_body` up to 65,536 characters, association format |
| https://developers.hubspot.com/docs/api-reference/crm-associations-v4/guide?app=1 | Default association type ids |
| https://developers.hubspot.com/docs/developer-tooling/platform/usage-guidelines.md | Burst and daily limits, 429 body, rate limit headers |
| https://community.hubspot.com/t/best-way-to-handle-409-conflict-in-batch-contact-create/27522 (title only, from search) | 409 on an existing contact `[third-party]` |

## 2. How the merchant connects

1. Sign in to HubSpot as a super admin, or a user with Developer tools access.
2. Open Development, then Keys, then Service keys, and press Create service key.
3. Add the scopes listed on this screen (contacts, deals, line items, notes, products: read and write) and create the key.
4. Press Show on the key, then Copy, and paste it into the Service Key field here.
5. Choose the deal pipeline and stage for new orders, or leave them empty to use the first pipeline.
6. Press Test connection.

Plan gate: none documented for REST access. A service key needs a HubSpot account with the Development area; the account holds at most 100 service keys, apps and MCP connectors combined `[documented]`. Legacy private apps can no longer be created (closed 26 Oct 2026, from `docs/research`), which is why the key method is a service key.

## 3. Authentication

`Authorization: Bearer <service key>` on every call `[documented]`. Stored encrypted as secret `access_token`. The key has no expiry date, but HubSpot recommends rotating every six months and a rotated key keeps working for 7 days when "expire later" is chosen `[documented]`; the plugin cannot know the key's age, so nothing is shown. Credential fields: `access_token` (secret), `api_version` (select `2026-03` default, `2026-09`), `portal_id` (optional Hub ID, used only to build links to records).

Date-based API paths (`/crm/objects/{version}/...`) are used. The default `2026-03` is the version the service key page ties its limits to; `2026-09` is the newest on the search page. Both are options because the older `/crm/v3/` paths are scheduled to go away in 2027 `[documented in docs/research]`.

Options (stored with the connection, keys under `options` or directly in the config): `pipeline`, `deal_stage`, `deal_stage_paid` (ids), `reference_property` (default `dealname`), `product_matching` (`off` default, `on`), `unknown_sku` (`refuse` default, `free_text`), `total_mismatch` (`fail` default, `warn`), `api_version`, `portal_id`.

## 4. Data sent and when (External services disclosure)

Host `api.hubapi.com`, only after the merchant connected a HubSpot service key.

| Action | Data sent | Data read |
| --- | --- | --- |
| Test connection | Nothing but the key | One contact, deal and pipeline row each (to prove the scopes) |
| Order synced | Buyer name, email, phone, company, billing address, order number, order date, currency, totals, order lines (name, SKU, price, quantity), order note | Matching contact by email, existing deal by order number, optional products by SKU |
| Refund synced | Refund number, amount, reason, order number | The deal's note list |
| Mapping preview with lookups | Email or SKU being looked up | Matching contact or product |

Nothing is sent to Estasoft or any other host.

## 5. Objects and field mapping

Target paths of the mapping (shared by the CRM connectors, `includes/Connectors/CrmCommon/CrmFields.php`): `reference`, `currency`, `date`, `paid_at`, `note`, `total`, `excluded_total`, `prices_include_tax`, `payment.reference`; `contact.email`, `.first_name`, `.last_name`, `.company`, `.phone`, `.street`, `.city`, `.state`, `.postcode`, `.country`; `lines[].sku`, `.name`, `.quantity`, `.unit_price`, `.total`, `.tax`; `shipping.total`; `fees[].name`, `.total`; `discount.total`, `.codes`. Refunds: `order_ref`, `amount`, `currency`, `date`, `reason`. Products: `product.sku`. Money fields are integer minor units.

| Canonical | HubSpot |
| --- | --- |
| `order.client_ref` (`WC-123`) | deal `dealname` (default) or the property named by option `reference_property` |
| `order.total` minus excluded | deal `amount` (decimal string in the order currency) |
| `order.currency` | deal `deal_currency_code` |
| `order.created_at` | deal `closedate` (UTC midnight, milliseconds) |
| `order.note` and customer message | deal `description` |
| billing email, names, phone, company, address | contact `email`, `firstname`, `lastname`, `phone`, `company`, `address`, `city`, `state`, `zip`, `country` |
| `lines[]` | `line_items` object: `name`, `quantity`, `price` (unit price, decimal string), `hs_sku`, optional `hs_product_id` |
| shipping, fees, discount | extra line items named "Shipping", the fee name, "Discount" (negative prices are refused by HubSpot `[documented]`, so a discount is carried in `amount` and named in `description`) |
| deal stage and pipeline | options `pipeline` and `deal_stage` (ids), `deal_stage_paid` for paid orders |

A deal is created with associations to the contact (type 3, `HUBSPOT_DEFINED`) and to every line item (type 19) in the same create call `[documented]`, so the deal is never half linked. Line items are created first (one call each; HubSpot merges identical line items in a batch `[documented]`, so batch create is not used).

Option `product_matching` (`off` default, `on`): when on, each line's SKU is looked up in products (`hs_sku` EQ) and `hs_product_id` is set; `unknown_sku` (`refuse` default, `free_text`) decides what an unknown SKU does (SYNC-MAP-001 or a free-text line). Off, every line is free text and carries the SKU in `hs_sku`; this is the default because a shop's HubSpot rarely holds a product catalogue.

## 6. Duplicate protection

`findExisting` searches `POST /crm/objects/{version}/deals/search` with filter `dealname EQ WC-123` (or the custom property), case-insensitive `[documented]`, and reads every page (`paging.next.after`). Several hits: the oldest (lowest id). A deal is a complete record because contact and line items are linked in the create call; the line items are created before the deal, so a crash leaves unlinked line items (harmless clutter) and never a half deal. Contacts: search `email EQ`; on a create answered 409 "Contact already exists. Existing ID: n" `[third-party]` the id in the message is used. Search results can lag a few seconds after a write `[documented]`; retries wait at least one minute.

## 7. Taxes, currency and totals

HubSpot deals carry no tax. A line item's `price` is the unit price without tax when unit price times a whole quantity equals the line total, else the line is sent as quantity 1 with the line total and "x quantity" added to its name; tax appears only in the deal `amount`, which is the order's WooCommerce total (tax and shipping included) minus the excluded total. HubSpot may recalculate `amount` from its line items when the account is set that way; after the create the connector reads the deal back, patches `amount` once if it differs, and compares `amount` and `deal_currency_code` with the order; a deal whose money is still wrong under `total_mismatch = fail` is archived, so it is never reported as found. A currency HubSpot does not accept is SYNC-VAL-002 (the error names the currency). Option `total_mismatch` (`fail` default, `warn`). `listTaxCodes()` returns an empty list (HubSpot has no tax codes for deals); the tax mapping is not used by this connector. All amounts are converted from integer minor units with the currency exponent (KWD 3 decimals) using whole-number arithmetic.

## 8. Payments

Not applicable: a HubSpot deal records no payment. The deal stage for a paid order is option `deal_stage_paid`.

## 9. Refunds

A note (`POST /crm/objects/{version}/notes`) with `hs_timestamp` and `hs_note_body` (first line `WC-45`, then "Refund of order WC-123: 12.00 EUR" and the reason), associated to the deal (type 214) `[documented]`. Idempotent: before creating, the deal's notes are searched with the filter `associations.deal EQ <deal id>` and the first line of each body is compared with `WC-45`. The deal amount is not changed.

## 10. Stock, tracking and invoices

Not read. HubSpot has no inventory, shipment or invoice record that a shop could use; the research found no refund object either. No flag is set for them.

## 11. Rate limits and pacing

Private distribution (service keys) burst: 100 requests per 10 seconds on Free and Starter, 190 on Professional and Enterprise; daily 250,000 / 625,000 / 1,000,000 shared by the account `[documented]`. Search is limited to 5 requests per second per account `[documented]`. The hints are 4 requests per second and 1 at a time. A 429 carries a JSON body with `errorType: RATE_LIMIT` and `policyName` `DAILY` or a burst name; HubSpot documents no `Retry-After` `[documented]`, so the connector waits 10 seconds for a burst and 3600 seconds for `DAILY`. `X-HubSpot-RateLimit-Remaining` and `-Daily-Remaining` are read; when the daily figure is below 50 the connector stops with SYNC-RATE-001.

## 12. Error mapping

| HubSpot answer | SYNC code |
| --- | --- |
| 401 | SYNC-AUTH-001 |
| 403 (missing scope or permission; `MISSING_SCOPES` category) | SYNC-AUTH-004, naming the object |
| 429 `RATE_LIMIT` | SYNC-RATE-001 |
| 400 `VALIDATION_ERROR` / `INVALID_OPTION` / property errors | SYNC-VAL-002 with the property name |
| 409 on contact create | handled: existing contact used |
| 423 Locked | SYNC-NET-002 (retry, "wait at least 2 seconds" `[documented]`) |
| 5xx, timeouts | SYNC-NET-002 / SYNC-NET-001 |
| Unknown SKU with `product_matching` on and `unknown_sku = refuse` | SYNC-MAP-001 |
| Missing email | SYNC-MAP-003 |
| Totals differ and `total_mismatch = fail` | SYNC-VAL-002 |

SYNC-AUTH-005 and -006 are never produced: a service key has no documented end date and REST access has no documented plan gate.

## 13. Capabilities

`orders` (deals), `customers` (contacts), `refunds` (note), `reconciliation` (all deals created since a date, read in creation order across pages and restarted from the last creation time when a query reaches HubSpot's 10,000-result cap; only those whose name is exactly `WC-<number>` are kept, with amount; refunds are not summed), `shadow_lookup`. Not flagged: `stock_in`, `tracking_in`, `prices_in`, `invoice_readback` (no such records), `webhooks` (service keys do not support them `[documented]`).

## 14. Not verified

- Service key availability on every HubSpot plan and in EU-hosted accounts (the EU data centre may use another API host; only `api.hubapi.com` is documented here).
- That a service key can call the `2026-03` and `2026-09` paths of every object used (the pages show them for apps; the key page ties the limits to the same versions).
- The pipelines endpoint (`GET /crm/pipelines/{version}/deals`) and the scope it needs; Test connection treats a refusal as "pipelines not readable" and still passes if deals work.
- That the search filter `associations.deal` is accepted for notes (third-party knowledge, not on the pages fetched). If HubSpot refuses it the refund fails with SYNC-VAL-002 naming the property and no note is made twice.
- Deal amount recalculation from line items in the merchant's account.
- Exact wording of HubSpot's refusal for a currency that is not enabled.
- No live account was available; everything above the wire was tested against `FakeHubSpot`.

## 15. Manual smoke test

1. Create a free HubSpot developer test account (up to 10, expire after 90 days idle `[documented in docs/research]`) and a service key with the scopes listed on the Connect screen.
2. Add the connection, press Test connection; expect the account name, scopes OK for contacts, deals, line items and notes.
3. Place an order with two lines; expect one contact, one deal named `WC-<id>` with two line items and the order total as amount. Retry the order: no second deal.
4. Refund it: one note on the deal.
5. Change the deal's amount in HubSpot, run Reconciliation: one difference.
