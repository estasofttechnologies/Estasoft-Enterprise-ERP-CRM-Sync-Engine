---
id: pipedrive
name: Pipedrive
catalog_row: Pipedrive
connection_method: key
available: true
verified: false
hosts: ["*.pipedrive.com"]
terms_url: https://www.pipedrive.com/en/terms-of-service
privacy_url: https://www.pipedrive.com/en/privacy
developer_url: https://app.pipedrive.com/settings/api
variants: []
---

# Pipedrive connector

Connector id `pipedrive`, type CRM, contract version 1. Code: `includes/Connectors/Pipedrive/` (shared CRM helpers in `includes/Connectors/CrmCommon/`). It sends customers as persons (and organizations when the buyer gave a company), orders as deals and refunds as a note on the deal. No stock, tracking or invoice read-back.

## 1. Sources

Fetched 2026-11-02. Tags as in `docs/research`.

| Page | Used for |
| --- | --- |
| https://pipedrive.readme.io/docs/core-api-concepts-rate-limiting | Token budget, burst limits per plan and auth type, search limit, headers, Cloudflare 403 |
| https://pipedrive.readme.io/docs/pipedrive-api-v2 | Which resources are v2 (deals, persons, organizations, products, deal products, pipelines, stages, search); notes are not in the list |
| https://pipedrive.readme.io/docs/pipedrive-api-v2-migration-guide | `emails` / `phones` arrays, `custom_fields`, address object, cursor paging, `PATCH`, RFC 3339 times, deal products keys |
| https://pipedrive.readme.io/docs/core-api-concepts-pagination | `cursor`, `limit` (default 100, max 500), `additional_data.next_cursor`; v1 `start` with `more_items_in_collection` |
| https://developers.pipedrive.com/docs/api/v1/ItemSearch | `GET /api/v2/itemSearch`: `term`, `item_types`, `fields`, `exact_match`, limit 100 |
| https://pipedrive.readme.io/docs/marketplace-oauth-authorization | OAuth details (not used here; see "Not built") |
| https://docs.sim.ai/integrations/pipedrive-service-account.md `[third-party]` | Where the personal token is, `x-api-token` header, one token per user per company, no scopes, admin can hide the page |

## 2. How the merchant connects

1. Sign in to Pipedrive as the user the sync should act as; the deals and notes belong to this user.
2. Open Personal preferences, then API, and copy Your personal API token (generate one if the field is empty).
3. If the API page is missing, ask a Pipedrive admin to allow API access for your user.
4. Enter your company address (the part before `.pipedrive.com`) and paste the token here.
5. Optionally enter the pipeline and stage ids for new orders; empty uses your default pipeline and its first stage.
6. Press Test connection.

Plan gate: none documented for the API token. Each user has one token per company; creating a new token invalidates the old one at once `[third-party]`. The token has the full access of its user and no scopes.

## 3. Authentication

Header `x-api-token: <token>` `[third-party]`; the token is never put in the URL (Pipedrive also accepts an `api_token` query parameter, which would leak into logs). Secret `api_token`. Credential fields: `company_domain` (text; `mycompany` or `mycompany.pipedrive.com`, normalised to a host ending `.pipedrive.com`; anything else is refused), `api_token` (secret).

Not built: OAuth. A Pipedrive OAuth app has one callback address `[documented]`, a private app needs approval to be shared, and the catalog row says "key". The token is the supported route.

Options: `pipeline_id`, `stage_id`, `lines` (`note` default, `products`), `unknown_sku` (`refuse` default, `create`), `mark_won` (off), `total_mismatch` (`fail` default, `warn`).

## 4. Data sent and when (External services disclosure)

Host `https://<company>.pipedrive.com` (the address the merchant typed), only after connecting.

| Action | Data sent | Data read |
| --- | --- | --- |
| Test connection | Token | The signed-in user, company, first pipeline page |
| Order synced | Buyer name, email, phone, company name and address, order number, date, currency, total, order lines (when products mode is on: SKU, price, quantity) | Persons and organizations matching the email or company name, the deal by order number, products by SKU |
| Refund synced | Refund number, amount, reason | Notes of the deal |

## 5. Objects and field mapping

Target paths of the mapping (shared by the CRM connectors, `includes/Connectors/CrmCommon/CrmFields.php`): `reference`, `currency`, `date`, `paid_at`, `note`, `total`, `excluded_total`, `prices_include_tax`, `payment.reference`; `contact.email`, `.first_name`, `.last_name`, `.company`, `.phone`, `.street`, `.city`, `.state`, `.postcode`, `.country`; `lines[].sku`, `.name`, `.quantity`, `.unit_price`, `.total`, `.tax`; `shipping.total`; `fees[].name`, `.total`; `discount.total`, `.codes`. Refunds: `order_ref`, `amount`, `currency`, `date`, `reason`. Products: `product.sku`. Money fields are integer minor units.

| Canonical | Pipedrive (API v2 unless noted) |
| --- | --- |
| `order.client_ref` | deal `title` (default) |
| total minus excluded | deal `value` (JSON number built from the exact decimal string) |
| `order.currency` | deal `currency` |
| `order.created_at` | deal `add_time` (RFC 3339 UTC) |
| billing name, email, phone | person `name`, `emails[{value, primary: true, label: work}]`, `phones[...]` |
| billing company, address | organization `name`, `address.value` |
| order lines | one note (`POST /api/v1/notes`, `content` as HTML with `<br>` line breaks, `deal_id`) listing lines, shipping, fees, discount and totals; with option `lines = products` also deal products (lines without a SKU stay in the note only) |

The reference is always the deal `title`. `POST /api/v2/deals` carries `title`, `person_id`, `org_id`, `value`, `currency`, `add_time`, `status: open`, and `pipeline_id` / `stage_id` only when options give them. The summary note is created last and is the completion marker (section 6). Option `mark_won` (default off): before the note, `PATCH /api/v2/deals/{id}` with `status: won` when the order is paid; the note stays the last step.

Option `lines = products` (default `note`): each line becomes a deal product `POST /api/v2/deals/{id}/products` with `product_id`, `item_price`, `quantity`, `comments`; products are found by `GET /api/v2/itemSearch` (`item_types=product`, `fields=code`, `exact_match=true`). `unknown_sku`: `refuse` (default, SYNC-MAP-001 before anything is sent) or `create` (a product with `name` and `code` is created).

## 6. Duplicate protection

`findExisting`: `GET /api/v2/itemSearch?term=WC-123&item_types=deal&fields=title&exact_match=true` `[documented]` (exact, not case-sensitive), cursor paged. The oldest hit is used. A deal counts as finished only when its summary note exists (`GET /api/v1/notes?deal_id=...`, paged with `start`, the first line of the content is `WC-123`); `mark_won` happens before the note, so a note proves it too. `send()` reads the same things first and continues from the missing step. Persons: itemSearch `item_types=person&fields=email&exact_match=true`; the oldest wins. Pipedrive's search index may trail a write by a short time `[inferred]`; the queue retries after at least a minute, so a lost answer is looked up after the index has caught up. This is the one place where a duplicate deal is possible; see "Not verified".

## 7. Taxes, currency, totals

No tax codes (`listTaxCodes()` is empty and the tax mapping is not used). Deal value is the order total including tax and shipping, minus the excluded total. In products mode the deal value is set explicitly and read back after the products are added (Pipedrive can derive the deal value from its products `[inferred]`); a difference beyond one minor unit is repaired once by `PATCH value`, then checked; `total_mismatch` (`fail` default / `warn`). A currency Pipedrive refuses is SYNC-VAL-002 naming the currency. Money moves from integer minor units to decimals at the edge by whole-number arithmetic.

## 8. Payments

None. A paid order may mark the deal won (`mark_won`).

## 9. Refunds

A note on the deal: content "Refund WC-45 of order WC-123: 12.00 EUR. Reason: ...". Idempotent by listing the deal's notes first and looking for `WC-45` as the first line of the content. The deal value is not changed.

## 10. Stock, tracking, invoices

Not read. Pipedrive products carry no stock the shop could use, and there are no shipments or invoices in the plan-independent API.

## 11. Rate limits and pacing

Daily token budget: 30,000 times the plan multiplier (Lite 1, Growth 2, Premium 5, Ultimate 7) times seats; costs: single get 2, list 20, update 10, search 40 (v2 endpoints cost less); resets at midnight server time `[documented]`. Burst per 2 seconds per token: API token Lite 20, Growth 40, Premium 100, Ultimate 120; search 10 on every plan `[documented]`. The hints are 3 requests per second and 1 at a time, which stays under the search limit. Headers `x-ratelimit-remaining` and `x-ratelimit-reset` are read. A 429 has no documented `Retry-After`: the connector waits 5 seconds when `x-ratelimit-remaining` is 0 (burst) and 900 seconds otherwise (daily budget). A 403 whose body is an HTML page is Cloudflare blocking an `api_token` that kept sending after 429s `[documented]`; it is mapped to SYNC-RATE-001 with a 900 second wait, never to a permissions error.

## 12. Error mapping

| Pipedrive answer | SYNC code |
| --- | --- |
| 401 (`unauthorized access`) | SYNC-AUTH-001 |
| 403 JSON | SYNC-AUTH-004 |
| 403 HTML (Cloudflare) | SYNC-RATE-001 |
| 429 | SYNC-RATE-001 |
| 400 / 422 with `success: false` and `error` / `error_info` | SYNC-VAL-002 (short sanitised reason) |
| 404 on a deal that was just found | SYNC-VAL-002 (retried) |
| 5xx, timeouts | SYNC-NET-002 / SYNC-NET-001 |
| unknown SKU, `lines = products`, `refuse` | SYNC-MAP-001 |
| missing email | SYNC-MAP-003 |

SYNC-AUTH-005 and -006 are not produced (the token has no documented end date; a company without API access shows as 401/403, which reads as AUTH-001/004).

## 13. Capabilities

`orders` (deals), `customers` (persons), `refunds` (note), `reconciliation` (deals updated since a date whose title starts with `WC-`: `GET /api/v2/deals?updated_since=...` with cursor paging, `value`, `currency`), `shadow_lookup`. Not flagged: `stock_in`, `tracking_in`, `prices_in`, `invoice_readback`, `webhooks` (v2 webhooks have no signature `[documented in docs/research]`, so none are used).

## 14. Not verified

- `x-api-token` header (third-party), the `GET /api/v1/users/me` answer shape and the exact error body fields.
- The `itemSearch` v2 result shape: the connector accepts both `items[].item.id` and `items[].id`.
- `updated_since` on `GET /api/v2/deals` (not named on the pages fetched) and `add_time` on deal create.
- Whether notes remain v1-only; the connector uses `/api/v1/notes` because notes are not in the v2 list.
- Search index delay (see section 6).
- Whether a deal value is recalculated when products are added.
- No live account: tested against `FakePipedrive`.

## 15. Manual smoke test

1. Start a Pipedrive trial, copy the personal API token (Personal preferences, API).
2. Add the connection with your company name; Test connection shows the user and company.
3. Place an order; expect a person, a deal titled `WC-<id>` with the order total and a note listing the lines. Retry: no second deal.
4. Set `lines = products` and create a product with a code equal to a SKU; the order gets a deal product.
5. Refund: a refund note appears on the deal.
