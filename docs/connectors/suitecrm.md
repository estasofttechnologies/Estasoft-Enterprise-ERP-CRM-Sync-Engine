---
id: suitecrm
name: SuiteCRM
catalog_row: SuiteCRM
connection_method: key
available: true
verified: false
hosts: []
terms_url: https://github.com/salesagility/SuiteCRM-Core/blob/hotfix/LICENSE.txt
privacy_url: https://github.com/salesagility/SuiteCRM-Core/blob/hotfix/LICENSE.txt
developer_url: https://docs.suitecrm.com/developer/integration-api/v8/authentication/
variants: []
---

# SuiteCRM connector

Connector id `suitecrm`, type CRM, contract version 1. Code: `includes/Connectors/SuiteCrm/`. Registration: `SuiteCrmProvider`.

The connector sends WooCommerce orders to SuiteCRM as an opportunity (the deal) linked to a contact and, when the buyer gave a company, an account; sends refunds as a note on the opportunity; finds contacts and products; and reads opportunities back for the nightly check. SuiteCRM is open source and runs on the merchant's own server, so the only host the plugin contacts is the address the merchant types. That address must be https and public; a private address is refused unless the site owner allows it with the filter `estasoft_sync_allow_private_hosts` (the rule the Odoo connector follows). There is no vendor-side processing: the licence file is given as both terms and privacy link because SuiteCRM publishes neither a developer agreement nor a privacy notice for a self-hosted installation.

## 1. Sources

Most facts below were read from the source of SuiteCRM 8 itself (repository `salesagility/SuiteCRM-Core`, branch `hotfix`, files under `public/legacy/` and `public/.htaccess`, read from raw.githubusercontent.com on 2 Nov 2026), which is the strongest source there is for a self-hosted product. They are tagged [source]. Documentation pages are tagged [documented].

| Fact | Tag | Source |
| --- | --- | --- |
| SuiteCRM 8 serves the API v8 from `public/legacy/Api/`, so the paths are `/legacy/Api/access_token` and `/legacy/Api/V8/module/...`; the documentation pages of the v8 API write the same paths as `/Api/V8/...` (SuiteCRM 7 layout) | [source] `public/legacy/Api/index.php`, `public/legacy/Api/V8/Config/routes.php`; [documented] https://docs.suitecrm.com/developer/integration-api/v8/endpoints/ | see left |
| Routes: `POST /access_token`; under `/V8`: `GET /module/{module}`, `GET /module/{module}/{id}`, `POST /module`, `PATCH /module`, `DELETE /module/{module}/{id}`, `GET\|POST\|DELETE /module/{module}/{id}/relationships/{linkFieldName}[/{relatedId}]`, `GET /current-user`, `GET /meta/modules`, `GET /meta/fields/{module}`, `GET /meta/swagger.json`; every `/V8` route sits behind the OAuth2 resource-server middleware | [source] `routes.php`; endpoints also [documented] | |
| Grants enabled: client credentials (1 hour), password (1 hour, with refresh token), refresh token (refresh token life 1 month), authorization code (code 10 minutes); the access token lives `PT1H`; a client record allows exactly one grant type (`allowed_grant_type`) plus `refresh_token`; the client secret is compared as a SHA-256 hash | [source] `Api/V8/Config/services/middlewares.php`, `Api/V8/OAuth2/Repository/ClientRepository.php` | |
| Token request fields: `grant_type`, `client_id`, `client_secret`, plus `username` and `password` for the password grant; answer has `token_type`, `expires_in`, `access_token` (and `refresh_token` for the password grant) | [documented] https://docs.suitecrm.com/developer/integration-api/v8/authentication/ | |
| Clients are made in Admin, OAuth2 Clients and Tokens | [documented] same page | |
| The RSA keys are `Api/V8/OAuth2/private.key` and `public.key` (`openssl genrsa -out private.key 2048`, `openssl rsa -in private.key -pubout -out public.key`, owned by the web server user, mode 600); the config value `oauth2_encryption_key` is needed and, when empty, a built-in default is used and a warning is logged | [documented] https://docs.suitecrm.com/developer/integration-api/v8/installation/ ; [source] `ApiConfig::OAUTH2_PRIVATE_KEY`, `middlewares.php` | |
| Requirements: server must support OAuth2 encryption and URL rewriting; Apache needs `mod_rewrite` and the rules in the root `.htaccess` (which also passes the `Authorization` header to PHP); if a valid token fails, check that rule and that nothing upstream strips the header | [documented] installation page; [source] `public/.htaccess` (`RewriteCond %{HTTP:Authorization} .+`, `RewriteRule ^ - [E=HTTP_AUTHORIZATION:%0]`, and the fall-through to `index.php` for any path that is not a file) | |
| List parameters: `fields[Module]=a,b` (an unknown field is an error), `page[number]` and `page[size]` (both must be greater than 0; without `page[size]` the API returns every record), `sort=field` or `sort=-field`, `filter[field][operator]=value` with operators `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `like` (SQL LIKE, `%` wildcard) and `filter[operator]=and\|or`; a filtered field must exist in the module's definition; deleted records are excluded unless asked for | [source] `Param/Options/Filter.php`, `Page.php`, `Fields.php`, `Sort.php`, `JsonApi/Repository/Filter.php`, `Service/ModuleService.php`; [documented] endpoints page | |
| A filter on `email1` is turned into a join on the email address tables, so `filter[email1][eq]=a@b.c` finds a contact or account by any of its addresses | [source] `ModuleService::getRecords` | |
| Create: `POST /V8/module` with `{"data":{"type":"<ModuleName>","attributes":{…}}}`; the answer is `{"data":{"type":"<ObjectName>","id":…,"attributes":{…},"relationships":{…}}}` (the type in answers is the object name, for example `Opportunity`); a bean is saved with its normal rules and ACL (`ACLAccess('save')`) | [source] `ModuleService::createRecord`, `getDataResponse`; request shape also [documented] | |
| Link a record: `POST /V8/module/{Module}/{id}/relationships/{linkFieldName}` with `{"data":{"type":"<RelatedModule>","id":"<id>"}}` | [documented] endpoints page; [source] `RelationshipService::createRelationshipByLink` | |
| Errors: `{"errors":{"status":<int>,"title":"…","detail":"…"}}` | [source] `JsonApi/Response/ErrorResponse.php` | |
| Opportunity fields: `name` (required, 50 characters), `amount` (currency, required), `currency_id`, `date_closed` (required), `sales_stage` (required), `probability` (0 to 100), `lead_source`, `next_step` (100), `description`, link fields `contacts`, `accounts`, `notes`; stock stages Prospecting, Qualification, Needs Analysis, Value Proposition, Id. Decision Makers, Perception Analysis, Proposal/Price Quote, Negotiation/Review, Closed Won, Closed Lost with default probabilities 10, 20, 25, 30, 40, 50, 65, 80, 100, 0 | [source] `modules/Opportunities/vardefs.php`, `include/language/en_us.lang.php` | |
| Contact fields (person template): `first_name`, `last_name` (required, 100), `email1`, `phone_work`, `phone_mobile`, `primary_address_street` (150), `_city` (100), `_state` (100), `_postalcode` (20), `_country`; account fields (company template): `name` (required, 150), `email1`, `phone_office`, `billing_address_street` (150), `_city`, `_state`, `_postalcode` (20), `_country`, `website`; note: `name` (255), `description`, `parent_type`, `parent_id`, `contact_id`; product (module `AOS_Products`): `name`, `part_number` (25), `price`, `currency_id`, `type`; currency: `iso4217` (3), `conversion_rate`, `status` | [source] `include/SugarObjects/templates/person|company|basic/vardefs.php`, `modules/AOS_Products/vardefs.php`, `modules/Currencies/vardefs.php` | |
| Not used: the GraphQL API of SuiteCRM 8 (`/api/graphql`), which was not read | – | – |

## 2. How the merchant connects

1. On your SuiteCRM server check that URL rewriting works and that `Api/V8/OAuth2/private.key` and `public.key` exist.
2. In SuiteCRM open Admin, OAuth2 Clients and Tokens, and create a Client Credentials client (or a Password client for a dedicated sync user).
3. Copy the client ID and secret; the sync user needs rights to Contacts, Accounts, Opportunities and Notes (and Products if you match SKUs).
4. Here enter the address of your SuiteCRM (for example `https://crm.example.com`), the version, the grant, the client ID and the secret.
5. For the password grant also enter the sync user's name and password.
6. Press Test connection. Expected: the sync user, and a tick for each module.

Notes for the readme: SuiteCRM 8 serves the API under `/legacy/Api` (choose version 8; version 7 uses `/Api`); a server on a plain `http` address or on a private network is refused unless the site owner allows it; shared hosting that cannot generate the RSA keys (no `openssl` or no write rights to `Api/V8/OAuth2/`) cannot use the API at all.

## 3. Authentication

The shared `OAuthTokenManager` with the client credentials or password grant against `{address}{prefix}/access_token` (client id and secret in the body, no scopes: SuiteCRM does not implement them). The access token lives one hour and is renewed under the shared lock; with the password grant the grant is run again (the refresh token is not kept). A 401 makes one fresh token request, then SYNC-AUTH-002. A 404 page from the token path (rewriting off) is reported as SYNC-VAL-002 about the address, not as a refused key.

| What | Where |
| --- | --- |
| Address, version (`v8` or `v7`), grant (`client_credentials` or `password`), client ID | connection config |
| Client secret, sync user password | encrypted secrets |
| Sync user name | config |
| Options | config `options` |

The API prefix is `/legacy/Api` for version 8 and `/Api` for version 7. [The v7 prefix is the one the documentation pages write; it was not tested.]

## 4. Data sent and when (External services disclosure)

Host: only the SuiteCRM address the merchant entered.

| Action | Data | When |
| --- | --- | --- |
| Token | Client ID, client secret (and sync user, password for that grant) | Connect and about every hour of work |
| Test connection | Reads the sync user, module list and field list of five modules | On request |
| Send order | Buyer name, email, phone, billing address, company; WooCommerce order number, date, status, total, currency and the product lines (as text in the description) | When an order is queued |
| Send refund | Refund number, reason, amount, refunded lines as a note | When a refund is queued |
| Lookups, reconcile | Email addresses, SKUs, order references | On request and on the nightly check |

## 5. Options

| Key | Values | Default |
| --- | --- | --- |
| `base_currency` | ISO code of the currency SuiteCRM uses as its default; an order in that currency sends `currency_id = -99` | empty |
| `stage_new` | sales stage of an unpaid order | `Prospecting` |
| `stage_paid` | sales stage of a paid order | `Closed Won` |
| `lead_source` | value for the contact and the opportunity | `Web Site` |
| `create_accounts` | `yes`, `no`: make or find an account when the buyer gave a company | `yes` |
| `match_products` | `yes`, `no`: every SKU must exist in `AOS_Products.part_number` | `no` |
| `unknown_sku` | `fail` (SYNC-MAP-001), `free_text` – only with `match_products = yes` | `fail` |
| `customer_policy` | `match_or_create`, `match_only` | `match_or_create` |
| `reference_field` | name of a custom text field of Opportunities that also holds `WC-{id}` | empty |
| `total_mismatch` | `fail`, `warn` | `fail` |

## 6. Objects and field mapping

The mapping screens use the paths every CRM connector shares (the targets of the version 1 rules); the connector reads the mapped payload and writes these SuiteCRM fields.

| Payload path | SuiteCRM field |
| --- | --- |
| `contact.first_name`, `contact.last_name` | Contact `first_name`, `last_name` (a single word goes to `last_name`, which SuiteCRM requires) |
| `contact.email` | Contact `email1` (the search key) |
| `contact.phone` | Contact `phone_work` |
| `contact.street`, `city`, `state`, `postcode`, `country` | Contact `primary_address_street`, `_city`, `_state`, `_postalcode`, `_country` |
| `contact.company` | Account `name` (found by exact name in any letter case, else created with the buyer's address in `billing_address_*`, `phone_office` and `email1`, and linked to the opportunity); no company or `create_accounts = no`: no account |
| `reference` | the start of the Opportunity `name`: `WC-{id} ` + buyer name, cut to 50 characters with the reference kept whole |
| `total` | Opportunity `amount` (decimal text from minor units by the exponent of the currency) |
| `currency` | Opportunity `currency_id` (looked up by `iso4217`, or `-99` for the `base_currency` option) |
| `date` | Opportunity `date_closed` |
| `paid_at` | chooses the `sales_stage` (`stage_paid` when set, else `stage_new`) and its stock `probability` |
| `lines[].sku`, `name`, `quantity`, `unit_price`, `total`, `tax` | Opportunity `description`, one readable line each, below the reference |
| `shipping.total`, `fees[]`, `discount.total`, `discount.codes`, `note` | Opportunity `description` as text |
| `order_ref`, `amount`, `currency`, `date`, `reason` (refunds) | Note `name` (`Refund WC-{refund id} <amount> <currency>`), `description`, `parent_type = Opportunities`, `parent_id` of the order's opportunity |

SuiteCRM has no tax objects here: the amount is the order total including tax (less the products left out), and the tax shows as text in the description. Money is converted from minor units with the currency exponent at the edge only; the answer's `amount` is read back and compared in minor units. Arabic and other scripts are sent as UTF-8 unchanged. `name` is limited to 50 characters: the reference `WC-{id}` is never cut, the rest is cut with an ellipsis and a warning in the result.

## 7. Duplicate protection

The opportunity name begins with `WC-{order id}` followed by a space (or is exactly that). Before every create: `GET /V8/module/Opportunities?filter[operator]=or&filter[name][eq]=WC-123&filter[name][like]=WC-123 %&sort=date_entered&page[size]=50&page[number]=n`, every page; the oldest hit wins. With `reference_field` set, that custom field is searched too. A refund note's name begins with `Refund WC-{refund id}` and is searched the same way.

`findExisting()` returns an order only when it is complete: the opportunity exists and is linked to its contact. Its reference carries `total_minor` and `currency` read from the record. Otherwise it returns nothing and `send()` finishes the links; nothing is created twice. Contacts: `filter[email1][eq]=<email>` with `sort=date_entered`, every page; the oldest wins. Accounts: exact `name`.

## 8. Taxes

Not applicable: SuiteCRM's opportunity has one amount. `listTaxCodes()` returns an empty list and the Tax mapping screen is not used.

## 9. Payments

Not applicable. The paid flag of the order only chooses the sales stage.

## 10. Refunds

A refund creates a note on the opportunity of the original order (found by `WC-{order id}`; none: the item fails before anything is created, SYNC-VAL-002): `name = Refund WC-{refund id} <amount> <currency>`, `description` = reason and refunded lines, `parent_type = Opportunities`, `parent_id`. The amount of the opportunity is not changed. Duplicate protection: the note name (section 7).

## 11. Reads

- `lookupCustomer( $email )`: the contact by `email1`.
- `lookupProduct( $sku )`, `searchProducts( $query )`: `AOS_Products` by `part_number` (exact) and by `name` or `part_number` with `like`; with the module missing the answer is an empty list.
- `listForReconcile( $from )`: opportunities with `name like 'WC-%'` created from the date (`filter[date_entered][gte]`), every page, keyed by `WC-{id}`, `total_minor` from `amount`; `refunded_minor` is the sum of the refund notes (`name like 'Refund WC-%'`, parent an opportunity) read from the same date, each refund counted once; a note whose name does not read `Refund WC-{n} <amount> <currency>` is left out.
- Stock, tracking, invoices: not offered; SuiteCRM's core modules hold none of these for a shop.

## 12. Rate limits and pacing

SuiteCRM documents no rate limit. The connector paces itself: `RateLimitHints( requestsPerSecond: 2.0, concurrency: 1 )`, because a self-hosted server may be small. 429 and `Retry-After` from a proxy in front of the server are honoured.

## 13. Error mapping

| Answer | SYNC code | Class |
| --- | --- | --- |
| Token endpoint 401 (`invalid_client`), 400 `invalid_grant` for a wrong user or password | SYNC-AUTH-001 | needs a person |
| 401 on the API after one new token | SYNC-AUTH-002 | needs a person |
| 403 or `errors.title` "Forbidden"/"Access denied" (ACL) | SYNC-AUTH-004 naming the module | needs a person |
| 404 on `/legacy/Api/...` with an HTML body (rewriting off), a redirect, or HTML instead of JSON | SYNC-VAL-002 field `address`: "URL rewriting looks off" | needs a person |
| Token endpoint 500 or a log line about the private key | SYNC-NET-002 | retry |
| 400 or 422 `errors.detail` about a field ("Filter field … is not found", "fields … not found") | SYNC-VAL-002 with the text | needs a person |
| 5xx, 408, 504, timeout, reset | SYNC-NET-002 / SYNC-NET-001 | retry |
| 429 | SYNC-RATE-001 | retry after the wait |
| Certificate failure | SYNC-NET-003 | needs a person |
| Unknown SKU with `match_products` | SYNC-MAP-001 | needs a person |
| Missing buyer email | SYNC-MAP-003 | needs a person |
| Currency not defined in SuiteCRM, amount read back differs | SYNC-VAL-002 | needs a person |

SYNC-AUTH-006 (plan gate) is not used: SuiteCRM has no plans.

## 14. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| Orders | true | opportunity |
| Customers | true | contact and account |
| Refunds | true | note on the opportunity |
| StockIn | false | no stock in the core modules |
| TrackingIn | false | no shipments |
| PricesIn | false | not built |
| InvoiceReadback | false | the core modules have no invoices (AOS invoices were not verified) |
| Reconciliation | true | opportunities by name and date |
| Webhooks | false | none documented |
| ShadowLookup | true | `findExisting()` is read-only |

## 15. Not verified

- No live SuiteCRM was available. Everything is built from the source files named in section 1 and tested against `FakeSuiteCrmServer`, an in-memory imitation of the v8 module API (filters, paging, links, errors) written from that source.
- The exact JSON of success answers (201 versus 200 on create, the shape of `meta` and `links` for paging; paging is driven by the page size and a short last page, not by `meta`).
- Whether `email1` is returned among the attributes of a contact without a `fields[...]` parameter (the filter itself is server side and trusted).
- Whether a custom dropdown set by the administrator changes the allowed `sales_stage` and `lead_source` values; the values are options for that reason. Whether the ACL of the sync user allows `POST` of notes and relationships.
- Whether `-99` is the default currency id on every installation and whether `GET /V8/module/Currencies` is allowed for the sync user.
- The v7 prefix `/Api` and the whole connector against SuiteCRM 7.
- Whether the legacy v8 API is left in place by future SuiteCRM 8 releases (it is under `public/legacy/`).
- The GraphQL API of SuiteCRM 8 was not read and is not used.
- The ACL meaning of the client's assigned user for the client credentials grant.

## 16. Manual smoke test

Use a throw-away SuiteCRM 8 (the official Docker image or a demo VPS).

1. Check `https://<host>/legacy/Api/V8/meta/swagger.json` answers 401 (not a 404 page): rewriting works.
2. Admin, OAuth2 Clients and Tokens: make a Client Credentials client; copy the ID and secret.
3. If the site is not public, allow the private address with a must-use plugin of your own: `add_filter( 'estasoft_sync_allow_private_hosts', '__return_true' );` (never commit it).
4. Add a SuiteCRM connection with the address, version SuiteCRM 8, grant client credentials, the ID and the secret. Press Test connection. Expected: the sync user's name and a tick for Accounts, Contacts, Opportunities, Notes.
5. Place an order with a company name. Expected: one contact (email as the order), one account, one opportunity named `WC-{id} …` with the order total, linked to both. Run the sync again: no second opportunity.
6. Refund the order: expected one note `Refund WC-{refund id} …` on the opportunity.
7. Wrong secret: SYNC-AUTH-001. Rewriting off: SYNC-VAL-002 about the address.
