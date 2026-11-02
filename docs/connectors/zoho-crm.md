---
id: zoho-crm
name: Zoho CRM
catalog_row: Zoho CRM
connection_method: own_app
available: true
verified: false
hosts: [accounts.zoho.com, accounts.zoho.eu, accounts.zoho.in, accounts.zoho.com.au, accounts.zoho.jp, accounts.zoho.sa, accounts.zohocloud.ca, accounts.zoho.com.cn, www.zohoapis.com, www.zohoapis.eu, www.zohoapis.in, www.zohoapis.com.au, www.zohoapis.jp, www.zohoapis.sa, www.zohoapis.ca, www.zohoapis.com.cn]
terms_url: https://www.zoho.com/crm/zohocrm-terms.html
privacy_url: https://www.zoho.com/privacy.html
developer_url: https://api-console.zoho.com
variants: []
---

# Zoho CRM connector

Connector id `zoho-crm`, type CRM, contract version 1. Code: `includes/Connectors/ZohoCrm/` with the sign-in and data-centre code shared with Zoho Books in `includes/Connectors/Zoho/` and CRM helpers in `includes/Connectors/CrmCommon/`. Sends customers as Contacts, orders as Deals plus a note, refunds as a note on the Deal. No stock, tracking or invoices.

## 1. Sources

Fetched 2026-11-02. The Zoho CRM developer pages are static HTML and were read; the Zoho Books pages are script-rendered and could not be read (see the Zoho Books document).

| Page | Used for |
| --- | --- |
| https://zoho.com/crm/developer/docs/api/v8/insert-records.html | Insert: `POST {api-domain}/crm/v8/{module}`, body `data`, 100 records max, response `code/details/message/status`, `DUPLICATE_DATA`, `MANDATORY_NOT_FOUND`, `INVALID_DATA`, 207 for partial success, required fields of Deals (`Deal_Name`, `Stage`, `Pipeline`) and Contacts (`Last_Name`) |
| https://zoho.com/crm/developer/docs/api/v8/upsert-records.html | Upsert and `duplicate_check_fields` (not used; see section 6) |
| https://prezohoweb.zoho.com/crm/developer/docs/api/v8/api-limits.html | Credits, daily allowance per edition, concurrency, `TOO_MANY_REQUESTS`, `X-API-CREDITS-REMAINING` |
| https://www.zoho.com/crm/developer/docs/api/v8/access-refresh.html | Token endpoints per data centre, code exchange parameters, `api_domain`, `invalid_client` / `invalid_code` / `invalid_redirect_uri` |
| https://www.zoho.com/crm/developer/docs/api/v8/scopes.html | Scope names `ZohoCRM.modules.deals.CREATE` and so on |
| https://www.zoho.com/deluge/help/crm/search-records-V8.html | Search criteria form `(Field:equals:value)`, operators, up to 10 conditions |
| https://help.zoho.com/portal/en/community/topic/kaizen-30-parameters-in-zoho-crm-apis-part-2-2?page=8 `[third-party, Zoho staff post]` | `criteria`, `email`, `phone`, `word` are exclusive; escape `(` `)` `,` with a backslash then URL-encode |
| https://www.zoho.com/deluge/help/crm/create-record-V8.html | Notes fields `Note_Title`, `Note_Content`, `Parent_Id` lookup form, `$se_module` |
| https://docs.unified.to/skills/zoho-crm-oauth-app/SKILL.md `[third-party]` | `Authorization: Zoho-oauthtoken`, `access_type=offline`, `prompt=consent`, 20 refresh tokens per user per client, comma-separated scopes, secret per data centre |

## 2. How the merchant connects

1. Open the Zoho API Console for your data centre and choose Add Client, then Server-based Applications.
2. Paste the redirect address shown on this screen into Authorized Redirect URIs of the new client.
3. Copy the Client ID and Client Secret into the fields here and choose the data centre your Zoho account uses.
4. Press Sign in with Zoho and approve the access.
5. Enter the deal stage names for paid and unpaid orders if yours differ from the Zoho defaults.
6. Press Test connection.

No plan gate: the API exists in every edition including Free (5,000 credits a day) `[documented in docs/research]`. The signing-in user needs rights to add Contacts, Deals and Notes. A Server-based client is used (not a Self Client) because the plugin's sign-in routes need a redirect address. The Zoho data centre of the account is fixed at sign-up; a wrong choice shows as `invalid_client` or a domain error.

## 3. Authentication

OAuth 2.0 authorization code. Authorize `https://accounts.zoho.{tld}/oauth/v2/auth`, token `https://accounts.zoho.{tld}/oauth/v2/token` `[documented: token endpoints for US, AU, EU, IN, CN, JP, SA, CA; authorize path `[third-party]`]`; Canada's accounts host is `accounts.zohocloud.ca`, not derived from the API host. Parameters `access_type=offline`, `prompt=consent` `[third-party]`, client id and secret in the body, no PKCE, scopes comma-separated `[third-party]`. Scopes: `ZohoCRM.modules.deals.CREATE,READ,UPDATE,DELETE` (delete only removes a deal whose money came out wrong), `ZohoCRM.modules.contacts.CREATE,READ`, `ZohoCRM.modules.accounts.CREATE,READ`, `ZohoCRM.modules.notes.CREATE,READ`, `ZohoCRM.modules.products.READ` (product lookups for the SKU mapping screen), `ZohoCRM.org.READ`, `ZohoCRM.coql.READ`. Header `Authorization: Zoho-oauthtoken <token>`. Access token one hour; refresh token does not expire but only 20 exist per user per client (the 21st silently revokes the oldest `[third-party]`), so the plugin keeps the one it has and never signs in again by itself. Tokens are stored encrypted by the shared token manager. Credential fields: `data_centre` (select), `client_id`, `client_secret`.

The API host is derived from the chosen data centre (`https://www.zohoapis.{tld}`); the `api_domain` in the token answer is the same value and is not trusted for a host.

Options: `stage_paid` (default `Closed Won`), `stage_unpaid` (default `Qualification`), `pipeline`, `reference_field` (default `Deal_Name`), `total_mismatch` (`fail` default, `warn`); `org_currency` and `org_id` are stored by the sign-in step.

## 4. Data sent and when (External services disclosure)

Hosts `accounts.zoho.<dc>` (sign-in, token refresh) and `www.zohoapis.<dc>` (data) of the data centre the merchant chose, only after the merchant signed in.

| Action | Data sent | Data read |
| --- | --- | --- |
| Sign in / refresh | Client id, client secret, code or refresh token | Access token |
| Test connection | Nothing | Organisation name and currency |
| Order synced | Buyer name, email, phone, company, billing address, order number, date, currency, total, the order lines as note text | Contact by email, Deal by order number, Account by company name, the Deal's notes |
| Refund synced | Refund number, amount, reason | The Deal's notes |

## 5. Objects and field mapping

Target paths of the mapping (shared by the CRM connectors, `includes/Connectors/CrmCommon/CrmFields.php`): `reference`, `currency`, `date`, `paid_at`, `note`, `total`, `excluded_total`, `prices_include_tax`, `payment.reference`; `contact.email`, `.first_name`, `.last_name`, `.company`, `.phone`, `.street`, `.city`, `.state`, `.postcode`, `.country`; `lines[].sku`, `.name`, `.quantity`, `.unit_price`, `.total`, `.tax`; `shipping.total`; `fees[].name`, `.total`; `discount.total`, `.codes`. Refunds: `order_ref`, `amount`, `currency`, `date`, `reason`. Products: `product.sku`. Money fields are integer minor units. The connector turns them into Zoho fields: `Last_Name`, `First_Name`, `Email`, `Phone`, `Mailing_*`, `Account_Name`, `Deal_Name`, `Amount`, `Currency`, `Closing_Date`, `Description`, `Note_Title`, `Note_Content`.

| Canonical | Zoho CRM |
| --- | --- |
| client reference | `Deal_Name` = `WC-123` (default), or the custom single-line field named by option `reference_field` (then `Deal_Name` is "Order 123") |
| total minus excluded | `Amount` (number from the exact decimal) |
| currency | `Currency` (sent only when it differs from the organisation's currency, which needs multi-currency) |
| order date | `Closing_Date` (`yyyy-MM-dd`, UTC date) |
| paid or not | `Stage` = option `stage_paid` (default `Closed Won`) or `stage_unpaid` (default `Qualification`); `Pipeline` = option `pipeline` when pipelines are on |
| buyer | Contact `Last_Name`, `First_Name`, `Email`, `Phone`, `Mailing_*`; `Contact_Name` lookup `{id}` on the Deal |
| buyer company | Account `Account_Name`; `Account_Name` lookup `{id}` on the Deal |
| lines, shipping, fees, discount, totals | One Note on the Deal: `Note_Title` = `WC-123`, `Note_Content` = order summary (limit 32,000 characters; longer text is cut with an ellipsis and a warning) |

Deals have no line items in Zoho CRM `[inferred]`, so lines live in the note. Sales orders were not used: the contract asks CRM connectors for deals.

## 6. Duplicate protection

Upsert with `duplicate_check_fields` is not used: it needs a unique field the merchant would have to create. `findExisting` runs `GET /crm/v8/Deals/search?criteria=(Deal_Name:equals:WC-123)` (the criteria is escaped and encoded as documented) and pages while `info.more_records` is true; a `204` or empty answer means none. Several hits: the oldest `Created_Time`. A Deal is finished only when its note `WC-123` exists (`GET /crm/v8/Deals/{id}/Notes?fields=Note_Title`, paged); the note is written last, so a crash between Deal and note leaves a Deal that `findExisting` does not report; `send()` then reads the Deal, finds the contact link and the missing note and completes it. Contacts: `GET /crm/v8/Contacts/search?email=...` (searches every email field), compared case-insensitively; the oldest wins. A `DUPLICATE_DATA` answer on contact insert carries the existing id, which is used.

## 7. Taxes, currency, totals

Zoho CRM deals carry no tax; the tax mapping is not used and `listTaxCodes()` is empty. `Amount` is the WooCommerce total with tax and shipping, minus the excluded total. The create answer holds only the id, so the Deal is read back (`GET /crm/v8/Deals/{id}?fields=Amount,Currency,Stage`) and `Amount` is compared with the order (one minor unit tolerance); a different currency or amount is SYNC-VAL-002 under `total_mismatch = fail` (default; `warn` keeps going); the deal with wrong money is deleted so it is never reported as found. A currency the organisation has not enabled is rejected by Zoho as `INVALID_DATA` and shown as SYNC-VAL-002 naming the field.

## 8. Payments

None; a paid order picks the paid stage.

## 9. Refunds

A Note on the Deal: title `Refund WC-45`, content "Refund of order WC-123: 12.00 EUR. Reason: ...". Idempotent: the Deal's notes are listed first and the title compared. The Deal amount is unchanged.

## 10. Stock, tracking, invoices

Not read; Zoho CRM has none the shop could use (research found no warehouses, tracking or credit notes). No flag.

## 11. Rate limits and pacing

Credits, not requests: 1 per call, insert 1 per 10 records; daily allowance 5,000 (Free) up to 50,000 plus 250 to 2,000 per user on paid editions, rolling 24 hours; concurrency per organisation 5 (Free) to 300 `[documented, from August 2026]`. The hints are 2 requests per second and 1 at a time. From 50 percent use Zoho sends `X-API-CREDITS-REMAINING`; the connector stops with SYNC-RATE-001 (wait 3600 s) when it reads fewer than 50. `TOO_MANY_REQUESTS` (HTTP status not stated on the page; 429 is expected) is SYNC-RATE-001, wait 60 s (no `Retry-After` documented).

## 12. Error mapping

| Zoho answer | SYNC code |
| --- | --- |
| 401 `INVALID_TOKEN` / `AUTHENTICATION_FAILURE` after one refresh | SYNC-AUTH-002 |
| 401 `OAUTH_SCOPE_MISMATCH` | SYNC-AUTH-004 (names the module) |
| 403 `NO_PERMISSION` | SYNC-AUTH-004 |
| `invalid_client` / `invalid_code` / `invalid_grant` on the token call | handled by the token manager (SYNC-AUTH-001 / -002) |
| `TOO_MANY_REQUESTS`, 429 | SYNC-RATE-001 |
| record `INVALID_DATA`, `MANDATORY_NOT_FOUND`, `INVALID_MODULE`, `INVALID_QUERY`, `MULTIPLE_OR_MULTI_ERRORS` | SYNC-VAL-002 with the field from `details` |
| record `DUPLICATE_DATA` on Contacts | handled (existing id used); on Deals SYNC-VAL-002 |
| 5xx, timeouts | SYNC-NET-002 / SYNC-NET-001 |
| missing email | SYNC-MAP-003 |

Zoho CRM has no plan gate on the API (SYNC-AUTH-006 not produced) and no end date on credentials (SYNC-AUTH-005 not produced).

## 13. Capabilities

`orders`, `customers`, `refunds`, `reconciliation` (COQL `select id, Deal_Name, Amount, Created_Time from Deals where (Deal_Name like 'WC-%' and Created_Time >= ...) order by Created_Time asc limit 200 offset n`, paged while `more_records`), `shadow_lookup` (contacts by email, products by `Product_Code` or word). Not flagged: `stock_in`, `tracking_in`, `prices_in`, `invoice_readback`, `webhooks` (workflow rules only, no API subscription).

## 14. Not verified

- The authorize path and scope separator (read from third-party pages; the official auth-request page was not reachable).
- `GET /Deals/search` and `/Contacts/search` response details (204 when empty, `info.more_records`, maximum `per_page` 200, and the cap of 2,000 records per search): the official search page was not fetched; the Deluge page and a Zoho staff post were.
- That `Parent_Id` plus `$se_module` is accepted together on v8 Notes; both are sent.
- COQL `like` and `OFFSET` limits.
- `Pipeline` required only when pipelines are enabled: the connector sends it when option `pipeline` is set and leaves it out otherwise.
- Whether `Currency` exists only in multi-currency organisations (assumed).
- Concurrency numbers conflict between Zoho pages; the connector stays serial.
- The Zoho UK data centre is left out because the official token list does not name it.
- No live account: tested against `FakeZohoCrm`.

## 15. Manual smoke test

1. Create a Zoho developer account (free CRM) and a Server-based client with the redirect address from the screen.
2. Add the connection, sign in, Test connection: organisation name and currency appear.
3. Place an order: one Contact, one Account (when a company is given), one Deal named `WC-<id>` with the amount, one Note `WC-<id>`. Retry the order: nothing new.
4. Refund it: a note `Refund WC-<refund id>` on the Deal.
5. Open API Dashboard in Zoho and confirm the credits used are small.
