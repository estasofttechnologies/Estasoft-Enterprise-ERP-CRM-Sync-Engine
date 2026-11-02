# Estasoft Enterprise ERP/CRM Sync Engine: vendor integration research, CRM, Gulf and cloud accounting vendors

Sections 5 to 7 of the integration research (CRM vendors; Gulf and regional accounting vendors; QuickBooks Online, Xero, Zoho Books and Zoho CRM). ERP and accounting vendors are in `estasoft-erp-sync-engine-integration-research-vendors.md` (sections 4); findings and the matrix are in `estasoft-erp-sync-engine-integration-research.md`. Tags: (D), (3P), (I) as in the main document.

## 5. CRM vendors

### 5.1 HubSpot | FEASIBLE WITH CAVEATS
- **API**: REST `https://api.hubapi.com`, date-based versions; 2026-09 is current, 2026-03 supported; v4 unsupported March 2027, v1 to v3 and legacy apps September 2027 (D).
- **Auth**: OAuth needs `client_secret`; access token 30 minutes (D). Marketplace listing requires OAuth as the sole method and at least 3 installs; private distribution limited to 10 accounts (D). Legacy private-app creation closed 26 Oct 2026; **Service Keys (beta)** replace them and do not support webhooks (D).
- **Merchant steps**: admin with Developer Tools access, Settings, Integrations, Service Keys, tick scopes, copy key.
- **Objects**: Deals with line items; Orders object; contact email as unique identifier with `batch/upsert`; unique-value properties as `idProperty` (up to 10 per object); no refund object found.
- **Limits**: Free/Starter 100 per 10 seconds and 250k per day; Professional 190 and 625k (D). Developer test accounts free, up to 10, expire after 90 days idle.
- **Sources**: developers.hubspot.com changelogs (legacy-private-app-creation-sunset, service-keys, fall-2026-spotlight) and docs (usage-guidelines, versioning, orders guide).

### 5.2 Salesforce | FEASIBLE WITH CAVEATS
- **API**: REST `https://{MyDomain}.my.salesforce.com/services/data/v68.0/` (D).
- **Auth**: external client apps (new connected apps are restricted since Spring '26). Web server flow with PKCE; secret optional only if the app does not require it; JWT bearer needs a certificate; client credentials uses a Run As user (D).
- **Gate**: Enterprise, Unlimited, Performance and Developer include the API; Professional needs an add-on (D). User needs "API Enabled".
- **Merchant steps**: in Setup create an external client app with the client credentials flow and API scope, set a Run As integration user, copy key and secret, paste My Domain URL, key and secret.
- **Objects**: Order needs Account, EffectiveDate, Price Book and Status Draft; upsert by external ID (up to 200 per call); no refund object found; webhooks none found.
- **Limits**: Developer Edition 15,000 calls per day; Enterprise 100,000 plus 1,000 per licence; up to 25 concurrent long-running requests (D).
- **Sources**: developer.salesforce.com REST guide (editions, composite upsert), help.salesforce.com (connected app restriction, web server flow, JWT flow, client credentials), Trailhead ECA packaging.

### 5.3 Pipedrive | FEASIBLE WITH CAVEATS
- **API**: REST v2 for deals, persons, organisations, products, search; deprecated v1 endpoints out of support 1 Aug 2026 (D). Base host is the company domain from `api_domain`.
- **Auth**: personal API token (one per user per company) or OAuth (Basic auth with secret; code 5 minutes, access 60 minutes, refresh 60 days unused); **one callback URL per app**, so a shared app needs a relay (D).
- **Gate**: admin must tick "use API" in the permission set. Public apps need approval (up to 21 business days); a private app set to Live can be shared by install link (D).
- **Objects**: `POST /api/v2/deals`, deal products; no upsert; webhooks v2 with basic auth only, no signing, 40 per user, ban after 10 failures.
- **Limits**: daily budget 30,000 times plan multiplier times seats; burst per 2 seconds by plan and auth type (D).
- **Sources**: pipedrive.readme.io (rate limiting, marketplace OAuth, private app, API v2, webhooks v2); developers.pipedrive.com/changelog.

### 5.4 SuiteCRM | FEASIBLE WITH CAVEATS
- **API**: v8 JSON:API style at `/Api/V8/module...`, token at `/Api/access_token`; documented only in the SuiteCRM 7 guide. v4.1 is legacy; SOAP Portal removed in 7.15.2 and 8.10.2 (D).
- **Auth**: OAuth2 client credentials, password, refresh, authorisation code (D). Scopes not implemented.
- **Gate**: none (open source). The merchant's server needs OpenSSL keys in `Api/V8/OAuth2/`, an encryption key, `mod_rewrite` and Authorization header pass-through (D). Hosts without shell access may block key generation (I).
- **Objects**: `POST /Api/V8/module`; filters with `filter[field][eq]`; no upsert, no webhooks documented; AOS quote and invoice module names unverified.
- **Sources**: docs.suitecrm.com developer integration-api v8 pages; supported-versions; release notes 8.10 and 7.15.x.

### 5.5 Zoho CRM
See section 7.

## 6. Gulf and regional accounting vendors

### 6.1 Wafeq (UAE and Saudi Arabia) | FEASIBLE
- **API**: v1 `https://api.wafeq.com/v1`; UAE e-invoicing (ASP) API `https://api.wafeq.com/ae/v1`; separate ZATCA phase 2 API (D).
- **Auth**: `Authorization: Api-Key <key>` from app.wafeq.com/c/api-keys, then "Enable API". Starter plan or higher; works during trial (D). OAuth2 only for public apps in the developer portal. No approval step.
- **Objects**: `POST /invoices/` requires contact, currency, invoice date, due date, invoice number and line items; accepts `external_id`, `reference`, `status` (default DRAFT). `POST /credit-notes/` (linked to invoices through Credit Note Applications). Items carry `sku` and `external_id`; list only filters by `external_id`. **No email filter on contacts, no SKU lookup, no stock field.** Idempotency header (UUID v4) honoured 1 hour. Webhooks cover ZATCA events only; poll with `modified_ts_after`.
- **Limits**: 60 per minute per organisation per record type; 429 with `Retry-After` (D). Demo organisation and free UAE e-invoicing sandbox.
- **E-invoicing**: Saudi phase 2 auto-reporting needs Plus or Premium; through the Public API reporting is a separate call `POST /invoices/{id}/tax-authority/report/`. Wafeq is a registered UAE ASP; mandate dates per Wafeq pages only (D).
- **Sources**: developer.wafeq.com reference pages (invoices_create, items_create, credit_notes_create, rate-limits, idempotency, get-your-api-key); zatca.wafeq.com docs; wafeq.com pricing and FTA e-invoicing pages.

### 6.2 Qoyod (Saudi Arabia) | FEASIBLE WITH CAVEATS
- **API**: REST v2.0 `https://api.qoyod.com/2.0/` with 19 resources including invoices, credit notes, customers, products, inventories (D). Field-level docs could not be read.
- **Auth**: `API-KEY` header from Settings, General Settings (D). **Pro or Advanced plan only; Basic has no API.** Pro SAR 2,070/year, Advanced SAR 3,795/year including VAT (D). Fair use, no numeric limit.
- **E-invoicing**: ZATCA phase 2 certified; phase 2 only on Pro and Advanced (D).
- **Not verified**: line fields, tax handling, reference field, duplicate control, webhooks. Zapier listing shows find customer, find product by ID or SKU and a stock change trigger (3P).
- **Sources**: apidoc.qoyod.com; qoyod.com knowledge-base articles on plans and features; qoyod.com/en/api-integrations; Zapier Qoyod listing.

### 6.3 Daftra | FEASIBLE WITH CAVEATS
- **API**: legacy `https://{subdomain}.daftara.com/api2/<resource>.json` and v2 `/v2/api/...` (D). The docs mix `daftara.com` and `daftra.com`; verify the real host.
- **Auth**: `apikey` header from Settings, API, API Key; or OAuth2 password grant returning a long-lived Bearer token (D). Plan gate not found.
- **Objects**: `POST /api2/invoices` requires only `client_id`; returns 202 with an id; no external-ID field. Products list filters by `product_code`, `barcode`, `keywords` and returns `stock_balance` (the only documented stock read in the Gulf group). Credit notes link through `subscription_id`. Webhooks configured in the App Hub; payload, signing and retries not documented. Rate limit numbers not found.
- **Sources**: docs.daftara.dev (auth, invoice, credit note, products, clients, webhooks, OAuth pages).

### 6.4 SMACC | BLOCKED (no public API)
- docs.smacc.com has ten sections and none covers an API; smacc.com/ww/en/docs is a "Coming Soon" page that lists a REST API reference, authentication and rate limits as in development (D). A competitor (Wafeq) says SMACC offers an API feature but advises using internal tools (3P); Apideck lists SMACC as "On our radar" (3P). A German product called smacc.io has API docs but is a different company.
- SMACC markets a "One-click setup" for Salla, WooCommerce and Shopify: the merchant links the accounts and picks the GL account and warehouse, with "no coding or API work" (D). It names no credentials. Each Salla order creates an invoice and journal entries; new customers become receivable accounts; a sale reduces stock; price changes flow from SMACC to Salla; a signed e-invoice is reported for each order (D).
- ZATCA phase 2 compliant (UBL 2.1; standard invoices cleared in real time, simplified invoices reported within 24 hours); UAE FTA and Egypt ETA guides "in development" (D). The partner programme is for resellers, distributors and accounting firms; no technology-partner track (D). 14-day free trial (D). No CSV import found.
- **Decision for the plugin**: SMACC is listed but cannot be selected until SMACC publishes its API. Merchants can ask SMACC for access or use SMACC's own WooCommerce connection. Revisit when the reference is published.
- **Sources**: docs.smacc.com; smacc.com docs, Salla integration, e-invoicing and partner pages; apideck.com/accounting-software/smacc; wafeq.com SMACC comparison; help.salla.sa SMACC article (title only).

### 6.5 TallyPrime | BLOCKED (direct)
No REST API. TallyPrime runs an HTTP server on the merchant's PC (default port 9000) that accepts XML, and JSON from TallyPrime 7.0 (D). No authentication on the gateway. Tally describes a local Tally Connector agent that opens an outbound WebSocket to a vendor-run server, and a Tally-initiated pull using TDL (D). A hosted WordPress site cannot reach the PC; exposing the port to the internet is advised against (3P). Voucher import supports create and credit-note vouchers; duplicate handling is not documented. UAE e-invoicing runs through Tally's own ASP flow and needs TSS and a Tally.NET ID (D).

## 7. QuickBooks Online, Xero, Zoho Books, Zoho CRM

Intuit's developer site only returns a JavaScript shell, so most QuickBooks facts come from Intuit blogs and third-party docs. Zoho object-level pages were partly unreachable.

### 7.1 QuickBooks Online | FEASIBLE WITH CAVEATS (US, UK, CA, AU); BLOCKED for the Gulf
- **API**: REST/JSON; minor versions below 75 were removed on 1 Aug 2025 and requests below 75 are treated as 75 (D). Host `quickbooks.api.intuit.com` with a per-company `realmId`; sandbox host `sandbox-quickbooks.api.intuit.com` (3P).
- **Auth**: OAuth 2.0 authorization code; token calls use Basic auth with the client secret (D); PKCE not mentioned. Access token 60 minutes; refresh token rolling 100 days plus a 5-year hard cap (D); the refresh value rotates about daily, so store the newest (3P). A Reconnect URL is mandatory (D). Scope `com.intuit.quickbooks.accounting`.
- **Gate**: development keys work with the sandbox only (3P). **Production keys need a per-app assessment questionnaire (legal, technical, security), "even if it is a private app for your own use"** (D). It needs an EULA, privacy policy, hosting details. Production redirect URIs must be exact https, at most 25 per app (3P), so one shared app cannot cover merchant domains without a relay. Builder tier is free up to 500,000 metered "CorePlus" calls per month (reads, queries, batches; writes not metered) (D); Silver is USD 300/month and listed apps must subscribe (D).
- **Merchant steps**: with a shared app, click Connect, sign in, pick the company. With a per-merchant app (impractical for non-technical owners), create an Intuit developer app, pass the assessment, add the redirect URI, paste Client ID and secret.
- **Objects**: **no SalesOrder entity** (3P); use Invoice, SalesReceipt, Estimate, CreditMemo, RefundReceipt. `reference` options: DocNumber or PrivateNote (I). Customer by `PrimaryEmailAddr` and Item by Sku (I). `QtyOnHand` on inventory items; no native multi-warehouse. Tracking number is writable on Invoice and SalesReceipt only (I). A `requestid` replay parameter exists (3P). Webhooks must be CloudEvents format (deadline 15 May 2026 passed) (D).
- **Limits**: 500 per minute and 10 per second per company; batch endpoint 120 per minute (3P).
- **Sandbox**: free; up to 10 companies for 2 years; region-specific (3P).
- **Regions**: metering covers US, Canada (excluding Quebec), UK, Australia (D). UAE: no integration with the UAE tax authority (3P). Saudi: Intuit said in 2021 the e-invoice format was not available (3P); no ZATCA support found.
- **Sources**: developers.intuit.com OAuth 2.0 page; blogs.intuit.com posts p=35349, 33143, 34635, 35371; Intuit partner FAQ; docs.unified.to QuickBooks OAuth skill; hotglue CorePlus article (3P).

### 7.2 Xero | FEASIBLE WITH CAVEATS
- **API**: Accounting API 2.0 at `https://api.xero.com/api.xro/2.0/`; global host; organisation chosen with `GET /connections` and `Xero-tenant-id` (3P).
- **Auth**: standard authorization code with secret; PKCE (no secret) is for native apps only, single-page apps unsupported (D). Custom Connection uses client credentials for one organisation (D). Access token 30 minutes; refresh token 60 days unused, rotating, 30-minute retry grace (D). Redirect URIs absolute, no wildcards, at most 50 per app (D). Apps created on or after 2 Mar 2026 must use granular scopes (D).
- **Gate**: Starter tier free: 5 connections and 1,000 calls per day per organisation (D). Core AUD 35/month for 50 connections; Plus AUD 245/month for 1,000 connections and needs App Certification (D). Custom Connections work only for AU, NZ, UK and US organisations and the organisation pays about USD 5 to 10 per month per connection (D). A shared Estasoft app is not viable (50 redirect URI cap, no wildcards).
- **Merchant steps**: create an app at developer.xero.com with the plugin's redirect URL, paste Client ID and secret, click Connect, choose the organisation; merchant needs tracked-inventory items with inventory and COGS accounts (D).
- **Objects**: **no SalesOrder endpoint** (I); Invoices (ACCREC) with `Reference`; contacts by email (I); items by `Code` (max 30 characters); `QuantityOnHand` with `QuantityAvailable` and `QuantityOnBackorder` from 10 Aug 2026, single value, no warehouses (D); CreditNotes (allocations must be against the same contact from 8 Oct 2026); no delivery or tracking. Optional `Idempotency-Key` header, 128 characters (3P). Webhooks for invoices, contacts, credit notes (D).
- **Limits**: per organisation 5 concurrent, 60 per minute, 5,000 per day (1,000 on Starter); app-wide 10,000 per minute; 3.5 MB requests (D).
- **Regions**: UAE VAT tool live; e-invoicing via Peppol "Invici" still pre-live on 25 Sep 2026 (3P); UAE mandate: large businesses 1 Jan 2027, others 1 Jul 2027 (D). Saudi: Xero said Dec 2024 it cannot offer e-invoicing there (3P).
- **Sources**: developer.xero.com (pricing, faq/oauth2, faq/limits, faq/granular-scopes, faq/custom-integration, changelog); xero.com/ae e-invoicing page.

### 7.3 Zoho Books | FEASIBLE WITH CAVEATS
- **API**: REST v3 `https://www.zohoapis.{tld}/books/v3/`, every call needs `organization_id` (D). Data-centre domains: .com, .eu, .in, .com.au, .jp (D) plus .ca, .sa, .com.cn, .uk (3P).
- **Auth**: OAuth 2.0 authorization code, server client or **Self Client** (3P). `Authorization: Zoho-oauthtoken`; access token 1 hour; refresh tokens do not expire but are capped at 20 per user per client, oldest silently revoked (3P). Client secret is per data centre (3P).
- **Gate**: API open on every plan including Free (D). Registering a client in the API Console is free and self-serve (I). A shared Estasoft client is not viable without a relay.
- **Merchant steps (Self Client)**: create a Self Client in the Zoho API Console, generate a short-lived grant code with scopes, paste client ID, secret, grant code, data centre and organisation; the plugin exchanges the code for a refresh token. The merchant must know their data centre.
- **Objects**: `/invoices`, `/salesorders`, `POST /invoices/fromsalesorder` (3P); `reference_number` as reference (I). Item SKU, `stock_on_hand`, per-warehouse stock and shipment tracking: **not found**. Credit notes scope exists (3P). No idempotency key found. Webhooks only through manually configured workflow rules (3P); poll with `last_modified_time`.
- **Limits**: daily Free 1,000, Standard 2,000, Professional 5,000, Premium/Elite/Ultimate 10,000, shared by all API consumers of the organisation (D). Per minute: official page says 30, two third-party guides say 100; design for 30. Concurrency 5 on Free, 10 on paid; 429 without `Retry-After` (3P).
- **Sandbox**: none; use a test organisation or trial.
- **Regions**: Saudi Zoho Books is ZATCA phase 2 approved from the Standard plan (D); whether API-created invoices are pushed to ZATCA is not stated. UAE edition and UAE e-invoicing: not found.
- **Sources**: zoho.com/books/api/v3; Apideck and Truto Zoho Books guides (3P); zoho.com/sa/books/e-invoicing.

### 7.4 Zoho CRM | FEASIBLE WITH CAVEATS
- **API**: v8 current; v2 to v8 still served (3P). Base `{api_domain}/crm/v8/`, where `api_domain` comes from the token response; data centres US, EU, IN, AU, JP, CA, SA, CN (3P).
- **Auth**: OAuth 2.0 only, no API keys; server client or Self Client; access token 1 hour; refresh tokens until revoked, 20 per user per client; secret per data centre (3P).
- **Gate**: API in all editions including Free (3 users, 5,000 credits per day) (D). Credits over a rolling 24 hours: 50,000 base plus per-user credits (250 Standard, 500 Professional, 1,000 Enterprise, 2,000 Ultimate); extra credits pay-as-you-go from USD 0.14 per 1,000 per day, bought by a super admin on a card (D).
- **Merchant steps**: same Self Client flow as Zoho Books.
- **Objects**: contacts, accounts, deals, custom modules (3P); `Sales_Orders` and `Products` standard (I); upsert on a unique field (3P); custom fields via API at 10 credits each (D). No warehouses, tracking or credit notes found; use custom fields. No idempotency key. Webhooks per workflow.
- **Limits**: no per-minute limit; 1 credit per standard call, 1 credit per 10 records for insert/update/upsert, COQL 1 to 3, bulk-write initialise 500 (D). Concurrency numbers conflict between two Zoho pages (5 to 25 versus 5 to 300); design low.
- **Sandbox**: Enterprise and above (3P); free Developer Edition (3P).
- **Sources**: zoho.com/crm/help/api/api-limits.html; zoho.com crm developer docs (api-limits, purchase-from-dashboard); docs.unified.to Zoho CRM skill; supergood.ai report card (3P).

### 7.5 Cross-vendor notes from this group
- A shared app with no relay does not work for any of the three: Intuit (25 exact https redirect URIs), Xero (50, no wildcards), Zoho (registered redirects, secret per data centre). Zoho's Self Client and Xero's Custom Connection need no redirect.
- Only Zoho has a sales-order API. QuickBooks and Xero need Invoice or SalesReceipt.
- Onboarding effort, easiest to hardest: Zoho (free, Self Client), Xero (free Starter app but 1,000 calls per day), QuickBooks (assessment for every production app).
- Webhooks: Xero best, QuickBooks CloudEvents only, Zoho none by API.

