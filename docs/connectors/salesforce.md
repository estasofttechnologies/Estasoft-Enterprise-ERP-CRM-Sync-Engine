---
id: salesforce
name: Salesforce
catalog_row: Salesforce
connection_method: own_app
available: true
verified: false
hosts: ["*.salesforce.com"]
terms_url: https://www.salesforce.com/company/legal/program-agreement/
privacy_url: https://www.salesforce.com/company/privacy/
developer_url: https://trailhead.salesforce.com/content/learn/projects/build-integrations-with-external-client-apps
variants: []
---

# Salesforce connector

Connector id `salesforce`, type CRM, contract version 1. Code: `includes/Connectors/Salesforce/` (shared CRM helpers in `includes/Connectors/CrmCommon/`). Sends customers as Contacts (and Accounts for companies), orders as Opportunities with the buyer as primary Contact Role, refunds as a completed Task on the Opportunity. No stock, tracking or invoices.

## 1. Sources

Fetched 2026-11-02.

| Page | Used for |
| --- | --- |
| https://developer.salesforce.com/docs/platform/salesforce-app-limits-cheatsheet/guide/salesforce-app-limits-platform-api.html | 24-hour allocations (Developer Edition 15,000; Enterprise 100,000 plus per licence), 25 concurrent long-running requests (5 in Developer/Trial), `REQUEST_LIMIT_EXCEEDED`, `Sforce-Limit-Info`, 16,384-byte URI limit |
| https://developer.salesforce.com/docs/platform/api-rest/guide/errorcodes.html | Status codes (400, 401, 403 `REQUEST_LIMIT_EXCEEDED`, 404, 409, 500, 503), error body: array of `{message, errorCode, fields}` |
| https://trailhead.salesforce.com/content/learn/projects/build-integrations-with-external-client-apps/implement-the-oauth-20-web-server-flow | Web server flow on the My Domain host: `/services/oauth2/authorize`, `/services/oauth2/token`, `grant_type=authorization_code`, response `access_token`, `instance_url`, `id`, `issued_at`, `signature` |
| https://www.apexhours.com/salesforce-oauth-2-0-jwt-bearer-flow/ `[third-party]` | JWT bearer: `iss` consumer key, `sub` user, `aud` login URL, RS256, `grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer`, no refresh token, certificate and pre-authorisation, `invalid_grant` "user hasn't approved this consumer" |
| `docs/research` section 5.2 (earlier fetches of developer.salesforce.com and help.salesforce.com) | Enterprise/Unlimited/Performance/Developer include the API, Professional needs an add-on; "API Enabled" permission; external client apps replace new connected apps since Spring '26; refresh tokens idle 30 days expire from 4 Nov 2026 |
| Opportunity, OpportunityLineItem, PricebookEntry, SOQL and sObject reference pages | The search returned them; the page bodies were navigation only, so object facts below are `[inferred]` from the Salesforce data model |

## 2. How the merchant connects

1. In Salesforce Setup open External Client App Manager and create an external client app with OAuth enabled.
2. Paste the redirect address shown on this screen as the Callback URL and add the scopes api and refresh_token.
3. Copy the Consumer Key and Secret into the fields here, and enter your My Domain address, for example `mycompany.my.salesforce.com`.
4. In Object Manager add the Opportunity text field `Woo_Order_Ref__c` (External ID, Unique) and let your sync user see it.
5. If your org uses one currency, enter it under Advanced; orders in other currencies are then refused.
6. Press Sign in with Salesforce and approve, then press Test connection.

Plan gate: Enterprise, Unlimited, Performance and Developer editions include the API; Professional needs the API add-on `[documented in docs/research]`; the user needs the "API Enabled" permission. A refused API shows as `API_DISABLED_FOR_ORG` or `API_CURRENTLY_DISABLED` and is mapped to SYNC-AUTH-006 `[third-party]`. Sandbox: use the sandbox My Domain address (`mycompany--name.sandbox.my.salesforce.com`); a free Developer Edition org works. Salesforce says consumer credentials must not be packaged in a distributed product `[documented in docs/research]`, which is why every merchant uses their own app. **Step 4** is the recommended reference key (section 6); without it the Opportunity name is the key and Test connection lists `find reference field` as missing. **Step 5**: an org that does not use several currencies stores an Amount with no currency label, so the connection must say which currency that is; without it Test connection fails and every order is refused (section 7).

## 3. Authentication

Default: OAuth 2.0 web server flow with PKCE, on the merchant's My Domain host: authorize `https://<domain>/services/oauth2/authorize`, token `https://<domain>/services/oauth2/token`, scopes `api refresh_token`, client id and secret in the body. Tokens are stored encrypted. The API host is the My Domain address itself (`https://<domain>/services/data/<version>/`), not the `instance_url` of the answer. Credential fields: `domain` (My Domain host, normalised; must end in `.salesforce.com`), `client_id`, `client_secret`, `api_version` (default `v66.0`, checked against `GET /services/data/`), `auth_method` (`web_server` default, `jwt_bearer`), and for JWT `jwt_username` and `private_key` (secret). JWT bearer grant needs the app's certificate and a pre-authorised user; it gets no refresh token and asks for a new access token when needed, so it also works without anyone signing in (e.g. if idle refresh tokens expire).

Options: `stage_paid` (default `Closed Won`), `stage_unpaid` (default `Prospecting`), `org_currency` (required when the org does not use several currencies), `reference_field` (API name of an Opportunity field the merchant made; `Name` to use the name on purpose; empty means `Woo_Order_Ref__c` when the user can see it), `lines` (`description` default, `products`), `pricebook`, `unknown_sku` (`refuse` default, `skip`), `shipping_product`, `fee_product`, `discount_product`, `total_mismatch` (`fail` default, `warn`), `api_version`, `auth_method`.

## 4. Data sent and when (External services disclosure)

Host `<My Domain>.salesforce.com` (the address the merchant entered), only after the merchant connected.

| Action | Data sent | Data read |
| --- | --- | --- |
| Sign in / refresh | Client id, secret, code or refresh token, PKCE verifier | Access token |
| Test connection | Nothing | Version list, organisation name, the definition of Opportunity, Contact, Task and Contact Role (to learn what the user may create, whether the org has several currencies, and whether the reference field is visible) |
| Order synced | Buyer name, email, phone, address, company, order number, date, currency, total, order lines as text (or as products, option) | Contact by email, Account by name, Opportunity by order number |
| Refund synced | Refund number, amount, reason | The Opportunity's Tasks |
| Health probe (circuit breaker) | Nothing | One Organization row (one query) |

## 5. Objects and field mapping

Target paths of the mapping (shared by the CRM connectors, `includes/Connectors/CrmCommon/CrmFields.php`): `reference`, `currency`, `date`, `paid_at`, `note`, `total`, `excluded_total`, `prices_include_tax`, `payment.reference`; `contact.email`, `.first_name`, `.last_name`, `.company`, `.phone`, `.street`, `.city`, `.state`, `.postcode`, `.country`; `lines[].sku`, `.name`, `.quantity`, `.unit_price`, `.total`, `.tax`; `shipping.total`; `fees[].name`, `.total`; `discount.total`, `.codes`. Refunds: `order_ref`, `amount`, `currency`, `date`, `reason`. Products: `product.sku`. Money fields are integer minor units. The connector turns them into Salesforce fields: `LastName`, `FirstName`, `Email`, `Phone`, `Mailing*`, `Account.Name`, `Opportunity.Name`, `Amount`, `CurrencyIsoCode`, `CloseDate`, `Description`, `StageName`, and for products mode `OpportunityLineItem.TotalPrice`.

| Canonical | Salesforce |
| --- | --- |
| `WC-123` | `Opportunity.Woo_Order_Ref__c` (a text External ID, Unique, when the user can see it, or the field named by option `reference_field`) **and** `Opportunity.Name` = `WC-123` (the name is the fallback key; a rep may rename it) |
| total minus excluded | `Amount` |
| currency | `CurrencyIsoCode` when the Opportunity has that field (multi-currency); otherwise option `org_currency` must be set and the order currency must equal it |
| order date | `CloseDate` (`yyyy-MM-dd`) |
| paid or not | `StageName` = option `stage_paid` (default `Closed Won`) / `stage_unpaid` (default `Prospecting`) |
| buyer | `Contact` (`LastName` required) and `OpportunityContactRole {ContactId, OpportunityId, IsPrimary: true}` |
| company | `Account.Name`; `Contact.AccountId`, `Opportunity.AccountId` |
| lines, shipping, fees, discount | `Opportunity.Description` (32,000 character limit, longer text is cut with an ellipsis and a warning), or with option `lines = products` as `OpportunityLineItem` |

Option `lines = products` (default `description`): `OpportunityLineItem {OpportunityId, PricebookEntryId, Quantity, TotalPrice}` (TotalPrice rather than UnitPrice, so the line total is exact; tax is added to the line, because Salesforce sets `Amount` to the sum of the lines). The price book is option `pricebook` (name) or the standard one; the entry is found by `SELECT Id, Product2.ProductCode FROM PricebookEntry WHERE Pricebook2Id = ... AND IsActive = true AND Product2.ProductCode IN (...)` (and the currency when multi-currency). `unknown_sku`: `refuse` (default, SYNC-MAP-001 before anything is created) or `skip` (the line stays in the description only; because `Amount` is then the sum of the lines that exist, the total check normally refuses such an order, which is the point: money is never silently wrong). Shipping, fees and discount become lines only if options `shipping_product`, `fee_product`, `discount_product` name a product code; otherwise the total check reports the difference.

## 6. Duplicate protection and resuming

**Which field is the key.** An Opportunity's name can be edited by anyone, and a renamed Opportunity would make a refund fail and a replayed order create a second one. So the reference lives in a field of its own, which the merchant adds once: an Opportunity text field `Woo_Order_Ref__c` (length 40), marked External ID and Unique, visible to the sync user (field-level security). The connector does not create it: creating a field through the Tooling API needs "Customize Application" and leaves field-level security to be granted `[inferred]`, the page for the Tooling API object could not be read, and a field the sync user cannot see would only look created. Instead it looks for it among the fields of the Opportunity describe answer, which lists exactly the fields the user can see, on every pass and at no extra cost (the describe is read anyway for the currency). Test connection reports the result: found, or `find reference field` listed as missing with the Opportunity name as the only key. A field made later is used from the next pass. Every Opportunity is made with the reference in the field **and** in `Name`. A search tries the field first and then `Name`, so an Opportunity made before the field existed is still found. Option `reference_field` names a field of the merchant's own (or `Name`, on purpose, which ignores `Woo_Order_Ref__c` and silences the warning). A Unique field also makes Salesforce refuse a second Opportunity with the same reference (`DUPLICATE_VALUE`, SYNC-VAL-002; the next pass finds the first).

Every search is SOQL with a quoted literal (backslash and single quote escaped): `SELECT Id, Woo_Order_Ref__c, Name, Amount, CreatedDate FROM Opportunity WHERE Woo_Order_Ref__c = 'WC-123' ORDER BY CreatedDate ASC`, then the same on `Name` (`nextRecordsUrl` followed until `done`). The oldest hit wins. An Opportunity is finished when its Contact Role exists, its lines exist (products mode: count equals the payload's matched lines) and its Description holds the summary marker line `WC-123`; the Contact Role is created last, so `findExisting` reports an Opportunity only when `OpportunityContactRole` exists for it (`SELECT Id FROM OpportunityContactRole WHERE OpportunityId = ...`). `send()` reads what exists and creates what is missing. Contacts: `SELECT Id, AccountId FROM Contact WHERE Email = 'x' ORDER BY CreatedDate ASC` (text comparison in SOQL is case-insensitive `[inferred]`). Contact and Account inserts send `Sforce-Duplicate-Rule-Header: allowSave=true` so the merchant's own duplicate rules cannot fail the order after the connector already searched; a `DUPLICATES_DETECTED` answer is otherwise SYNC-VAL-002.

## 7. Taxes, currency, totals

No tax codes (`listTaxCodes()` empty; tax mapping unused). `Amount` is the order total with tax and shipping minus the excluded total (description mode). After the create the Opportunity is read back (`Amount`, `CurrencyIsoCode`): a different amount or currency is SYNC-VAL-002 under `total_mismatch = fail` (default; `warn`). In products mode `Amount` is Salesforce's sum of the lines. Multi-currency is detected from `GET /sobjects/Opportunity/describe` (field `CurrencyIsoCode`). Without it the org stores an Amount with no currency label, which is right only when the order is in the org's own currency, so: **`org_currency` must be set** (Test connection fails with SYNC-VAL-002 naming `org_currency` when it is not), an order in any other currency is refused before anything is created, an order with no `org_currency` is refused too, and reconciliation returns the same error instead of guessing a currency (it used to guess USD). The read-back after the create compares the amount; the currency cannot be read back in a single-currency org, which is why the check is made before the create.

## 8. Payments

None.

## 9. Refunds

A `Task` on the Opportunity: `Subject` = `Refund WC-45`, `WhatId`, `Status` = `Completed`, `ActivityDate` = refund date, `Priority` = `Normal`, `Description` = "WC-45", then "Refund of order WC-123: 12.00 EUR" and the reason. Idempotent by `SELECT Id FROM Task WHERE WhatId = '...' AND Subject = 'Refund WC-45'`. The Opportunity amount is unchanged.

## 10. Stock, tracking, invoices

Not read.

## 11. Rate limits and pacing

Allocation per 24 hours (Developer 15,000; Enterprise 100,000 plus licences); `Sforce-Limit-Info: api-usage=used/max` `[third-party format]` is read and the connector stops with SYNC-RATE-001 (3600 s) when fewer than 100 calls remain. 403 `REQUEST_LIMIT_EXCEEDED` is SYNC-RATE-001 (3600 s). Hints: 4 requests per second, 2 at a time (the long-running-request limit of 25 is never approached by short calls).

## 12. Error mapping

| Salesforce answer | SYNC code |
| --- | --- |
| 401 `INVALID_SESSION_ID` (after one refresh) | SYNC-AUTH-002 |
| token call `invalid_grant`, `invalid_client` | handled by the token manager |
| 403 `API_DISABLED_FOR_ORG`, `API_CURRENTLY_DISABLED` | SYNC-AUTH-006 |
| 403 `INSUFFICIENT_ACCESS*`, `NO_ACCESS`, `INVALID_FIELD_FOR_INSERT_UPDATE` on access | SYNC-AUTH-004 |
| 403 `REQUEST_LIMIT_EXCEEDED` | SYNC-RATE-001 |
| 400 `REQUIRED_FIELD_MISSING`, `INVALID_FIELD`, `INVALID_OR_NULL_FOR_RESTRICTED_PICKLIST`, `FIELD_CUSTOM_VALIDATION_EXCEPTION`, `STRING_TOO_LONG`, `MALFORMED_QUERY`, `DUPLICATES_DETECTED` | SYNC-VAL-002 with the first field |
| `UNABLE_TO_LOCK_ROW`, 503 | SYNC-NET-002 (retry) |
| 404 `NOT_FOUND` on an object or field | SYNC-VAL-002 |
| 5xx, timeouts | SYNC-NET-002 / SYNC-NET-001 |
| unknown SKU (`lines = products`) | SYNC-MAP-001; missing email SYNC-MAP-003 |

## 13. Capabilities

`orders` (Opportunities), `customers` (Contacts), `refunds` (Task), `reconciliation` (`SELECT Id, Woo_Order_Ref__c, Name, Amount, CreatedDate[, CurrencyIsoCode] FROM Opportunity WHERE (Woo_Order_Ref__c LIKE 'WC-%' OR Name LIKE 'WC-%') AND CreatedDate >= <UTC datetime>`, paged by `nextRecordsUrl`; the field is left out when the name is the only key), `shadow_lookup`. Not flagged: `stock_in`, `tracking_in`, `prices_in`, `invoice_readback`, `webhooks`.

## 14. Not verified

- The object reference pages (field lists, which fields are required or createable): taken from the Salesforce data model, not from fetched text.
- Whether PKCE is accepted by an external client app configured with a secret, and which of the secret/PKCE options the merchant's app needs.
- `API_DISABLED_FOR_ORG` as the exact refusal for an edition without the API, and the `Sforce-Limit-Info` format.
- The default `v66.0` exists in every org (Test connection checks `GET /services/data/`).
- JWT bearer: the base's token manager runs it, but the Connect screen offers sign-in for this connector; a pure JWT connection has not been exercised.
- **Reference field.** That the describe answer lists custom fields only when the user can see them (field-level security) and that `Woo_Order_Ref__c` appears in it by that name; that a Unique External ID text field refuses a duplicate with `DUPLICATE_VALUE` (the code is `[inferred]`). Creating the field by the Tooling API was not built (see section 6), so the "create it in Test connection" route is not offered for Salesforce: the instruction is a connect step and a missing item in the test result.
- **Single-currency orgs.** That an Opportunity of an org without multi-currency has no `CurrencyIsoCode` and stores no currency; `Organization.DefaultCurrencyIsoCode` is not used, so `org_currency` is a setting the merchant must give.
- Task, ContentNote and Note availability: `Task` is used for refunds because it is a standard activity.
- No live org: tested against `FakeSalesforce`.

## 15. Manual smoke test

1. Create a free Developer Edition org; make an external client app with the redirect address from the screen and scopes `api`, `refresh_token`.
2. Add the connection with the org's My Domain address (and the org currency if the org has one currency); sign in; Test connection lists the API version and the Opportunity fields, and `find reference field` is missing until you add `Woo_Order_Ref__c`.
3. Place an order: one Contact, one Account (if a company), one Opportunity named `WC-<id>` (with `Woo_Order_Ref__c` the same) and a primary Contact Role. Retry: nothing new. Rename the Opportunity and retry: still nothing new. In a one-currency org, place an order in another currency: it is refused.
4. Set `lines = products`, create a product with code equal to a SKU and a price book entry; the order has a line item.
5. Refund: a completed Task `Refund WC-<refund id>` on the Opportunity.
