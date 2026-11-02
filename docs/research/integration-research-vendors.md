# Estasoft Enterprise ERP/CRM Sync Engine: vendor integration research, per-vendor detail

Section 4 of the integration research (ERP and accounting vendors). CRM, Gulf and cloud accounting vendors (sections 5 to 7) are in `estasoft-erp-sync-engine-integration-research-vendors-2.md`. Findings, the summary matrix and the decisions are in `estasoft-erp-sync-engine-integration-research.md`. Tags: (D) read on an official vendor page, (3P) third-party only, (I) inferred or recalled. "Not found" means no source could be read.

## 4. ERP and accounting vendors

### 4.1 Odoo (17, 18, 19, 20; Online, Odoo.sh, on-premise) | FEASIBLE WITH CAVEATS
odoo.com/documentation pages errored in both rounds. Round 2 read the same content from the `odoo/documentation` and `odoo/odoo` repositories on GitHub (branches 17.0 to 20.0; 19.0 head dated 2 Nov 2026), plus odoo.com pricing, plan and acceptable-use pages. "gh" below means an official GitHub source.
- **API**: 19.0 and later: JSON-2, `POST /json/2/<model>/<method>` with a JSON body of `ids`, `context` and named parameters; 200 on success, 4xx or 5xx with a JSON object on error; each call is its own SQL transaction (D, gh). 17.0 and 18.0: XML-RPC `/xmlrpc/2` and JSON-RPC `/jsonrpc` using `execute_kw` (D, gh); a PHP 8 install may lack the XML-RPC extension, so prefer JSON-RPC. Detect the version with `GET /web/version`. Odoo 20.0 exists and its JSON-2 text matches 19.0.
- **RPC removal**: the 19.0 and 20.0 docs say `/xmlrpc`, `/xmlrpc/2` and `/jsonrpc` are scheduled for removal in Odoo 22 (fall 2028) and Online 21.1 (winter 2027) (D, gh); an August 2025 commit had said Odoo 20 (fall 2026). The 20.0 branch says the db service is already removed. Never call the db service.
- **Auth**: JSON-2 `Authorization: bearer <key>`; `X-Odoo-Database` only when one host serves several databases. On 17 and 18 the key replaces the password and the login stays; Odoo Online users also need a local password set (D, gh). Created under Preferences, Account Security, New API Key with a description and a duration; shown once. Docs say at most 3 months; 19.0 source: non-administrators are capped by the group setting `api_key_duration` (90 days); administrators can make persistent keys. Keys can be rotated over RPC with `res.users.apikeys.generate` and `revoke`; by default only Settings administrators may, and `base.enable_programmatic_api_keys=True` extends it to others (limit 10 keys) (D, gh). Odoo recommends a dedicated bot user with minimum rights.
- **Gate**: Odoo Online allows the external API **only on the Custom plan**; the 17.0 to 20.0 docs say it is unavailable on One App Free and Standard (D, gh). The pricing-plan page lists "External API" only under Custom and says Odoo-initiated integrations (payment providers, carriers) are Standard (D). Custom costs USD 46.70 or 58.40 per user per month (two figures, unlabelled) (D). Odoo.sh and on-premise sit under Custom as hosting options. **No way to detect the plan from the API was found**, so the connect screen needs a clear "plan or API disabled" error.
- **Merchant steps**: create a dedicated bot user; make a key with a duration; paste address, database (if several) and key.
- **Objects** (D, gh from the 19.0 source unless noted): `sale.order` with `partner_id`, `order_line` commands and `client_order_ref` (Char, `copy=False`, **not unique**); `action_confirm` is public. `res.partner.email` is a plain Char with no unique constraint (search with `=ilike`, I). `product.product.default_code` is an indexed Char; duplicates only warn. **Stock**: read per warehouse by passing the context key `warehouse_id` or `location` to `qty_available`; write with `stock.quant` create and `inventory_quantity_auto_apply` in inventory mode (needs stock-manager rights, I). **Tracking**: `stock.picking.carrier_tracking_ref` exists only if the `stock_delivery` module is installed. **Invoice**: two routes, the `sale.advance.payment.inv` wizard's `create_invoices`, or `account.move` create then `action_post`; `account.move.name` is the number, with `state` and `payment_state`; an invoice from an order gets `ref = client_order_ref or name`. **Credit note**: the `account.move.reversal` wizard (`refund_moves`) gives `move_type='out_refund'` with `reversed_entry_id`; using it over RPC is inferred.
- **Idempotency**: none. The duplicate check on draft orders is an informational computed field only; posted invoices are unique on name plus journal. `load()` upserts by external ID (`ir.model.data`) but also overwrites existing rows. Search by `client_order_ref` before create. Create, confirm, invoice and post are separate calls, each its own transaction.
- **Webhooks**: no generic outbound webhook. The Studio automated action "Send Webhook Notification" exists and Studio is a Custom-plan feature. Otherwise poll by `write_date`.
- **Limits**: the Acceptable Use Policy says throttled calls are typically acceptable at **about 1 call per second with no parallel calls**; batch APIs are expected for imports; dedicated Odoo.sh hosting can be considered to lift this (D). Payload limits: not found.
- **Sandbox**: Odoo Online "Duplicate" is neutralised by default, at most 5 copies, each expiring after 15 days; Odoo.sh staging is neutralised (crons off, mail caught, payment and shipping in test mode) and auto-deleted after a month; shared demo databases last hours (D, gh). 14-day free trial (3P); whether the API works in a trial: not found.
- **Saudi Arabia (ZATCA phase 2)** (D, gh): `l10n_sa` plus `l10n_sa_edi`. Each Sales journal must be **onboarded by hand with an OTP from the Fatoora portal** (OTP expires after 60 minutes). Modes Sandbox, Simulation, Production; **after the first Production submission you cannot go back**, and Production invoices cannot be reset to draft. A rejected invoice stays Rejected and needs Retry. B2B is cleared, B2C reported. Arabic is mandatory on invoices; company and contact data need district, building number, plot, VAT and an identification scheme; the company currency must be SAR. Credit notes need a ZATCA Reason, and an unrelated credit note needs the original invoice number in Customer Reference. Posting creates an `account.edi.document` in state `to_send`; the cron "EDI: Perform web services operations" ships **inactive** with a one-day interval, so an API-posted invoice may wait for "Process now" (`action_process_edi_web_services` is public). Whether the cron is enabled on install: not verified.
- **UAE** (D, gh): only `l10n_ae`, `l10n_ae_reports` and `l10n_ae_pos`; **no e-invoicing module**; third-party apps exist (3P). Rollout per a May 2026 article (3P): pilot 1 Jul 2026; large businesses (AED 50 million or more) from 1 Jan 2027 with their service provider appointed by 30 Oct 2026; smaller businesses 1 Jul 2027.
- **What the plugin should do**: two transports (JSON-2 and JSON-RPC) chosen by `/web/version`; one call per second, serial; store the key expiry and warn; for Saudi, post, call `action_process_edi_web_services`, then poll the EDI state, treat Rejected as a retry state, validate Arabic and partner fields up front, and never auto-post in Production without a visible warning.
- **Sources**: github.com/odoo/documentation (`external_api.rst`, `external_rpc_api.rst`, `saudi_arabia.rst`, `united_arab_emirates.rst`, `odoo_online.rst`, `odoo_sh` branches) and commit c5c1004c; github.com/odoo/odoo (`sale_order.py`, `account_move.py`, `stock_quant.py`, `res_users.py`, `account_edi`, `l10n_sa_edi`); odoo.com/pricing-plan, /pricing and /acceptable-use; Odoo forum thread on external API limits (3P); banqup.com UAE rollout article (3P).

### 4.2 ERPNext (Frappe) | FEASIBLE WITH CAVEATS
- **API**: REST at `/api/resource/<DocType>` and `/api/v2/...` (v2 from Frappe v15) (D). Versions: v14 ended 31 Jan 2026; v15 to end 2027; v16 to end 2029 (D).
- **Auth**: `Authorization: token api_key:api_secret` (D). Runs as the key's owner, so that user's roles apply.
- **Merchant steps**: create an API user with Sales, Stock and Accounts roles; User, Settings, API Access, Generate Keys; give site URL, key and secret.
- **Objects**: Sales Order with customer purchase order number (I); credit note is a Sales Invoice marked as return with "Return Against" (D); stock via the Bin doctype (I). No idempotency key.
- **Limits**: site-wide fixed-window limit set in `site_config.json`, returns 429 (D); default not stated. Lists return 20 rows by default.
- **Webhooks**: the Webhook doctype fires on document events with an HMAC-SHA256 header (D); merchant must configure them.
- **Regions**: Frappe Cloud has UAE, Bahrain, Frankfurt, London, Virginia and Sydney servers (D). Frappe advised moving sites out of the AWS UAE and Bahrain region on 4 Mar 2026 (D). UAE VAT 201 report built in (D); ZATCA phase 2 needs third-party apps (3P).
- **Sources**: docs.frappe.io framework REST, token auth, rate limiting, webhooks pages; frappe.io/support-versions; frappe.io/cloud/servers; docs.frappe.io/erpnext/sales-return.

### 4.3 Dynamics 365 Business Central | FEASIBLE WITH CAVEATS
- **API**: OData v4, API v2.0; v1.0 removed in version 28 (D). SaaS base `https://api.businesscentral.dynamics.com/v2.0/<environment>/api/v2.0/companies(<id>)/...` (D).
- **Auth**: Entra OAuth 2.0 client credentials, scope `https://api.businesscentral.dynamics.com/.default`, app permission `API.ReadWrite.All`; token 1 hour (D). Basic auth access keys unsupported online since Oct 2022 (D).
- **Gate**: any online tenant; no licence for the app account (D). No app review found.
- **Merchant steps**: (1) Entra admin centre: register app, create secret, add BC permission, grant admin consent. (2) In BC: open "Microsoft Entra applications", New, client ID, state Enabled, assign permission sets (not SUPER). (3) Paste tenant ID, client ID, secret, environment name, company.
- **Objects**: `salesOrders` with `externalDocumentNumber` (not unique); `salesCreditMemos` with a `post` action; customers by email; items by number or GTIN. **Gaps**: no per-location stock, no tracking number on shipments (D).
- **Webhooks**: sales orders, invoices, credit memos, customers, items; subscriptions expire after 3 days, validation handshake, deleted on non-retryable errors, ids only (D).
- **Limits**: 600 per minute per environment production, 300 sandbox; 6,000 per 5 minutes per user; 5 concurrent; 429 on excess (D).
- **Regions**: Microsoft localises US, UK, Australia and many EU countries; UAE and Saudi not on the list (D).
- **Sources**: learn.microsoft.com BC automation-apis-using-s2s-authentication, operational-limits-online, dynamics-subscriptions, endpoints-apis-for-dynamics, salesorder, salescreditmemo, item, salesshipment, deprecated-features-w1.

### 4.4 Dynamics 365 Finance and Supply Chain | FEASIBLE WITH CAVEATS
- **API**: OData v4 at `<environment URL>/data/<Entity>`; `?cross-company=true` for cross-company; page size 10,000 (D).
- **Auth**: Entra OAuth, register the app, add its client ID under System administration, Setup, Microsoft Entra applications with a service user (D). Needs an Entra admin and an F&O system administrator.
- **Objects**: `SalesOrderHeadersV2` and `SalesOrderLinesV2` as separate calls (not one transaction); Microsoft advises single-threading them (community). `CustomersV3` keyed by account, no email field documented. Per-warehouse stock is not confirmed on public OData; the documented route is the Inventory Visibility add-in (D). Tracking, invoices and credit notes: not found.
- **Limits**: resource-based throttling, 429 with `Retry-After`, priority throttling per client ID (D).
- **Regions**: Saudi phase 2 e-invoicing through the Electronic invoicing service (D). UAE: not found.
- **Sources**: learn.microsoft.com fin-ops-core data-entities pages (services-home-page, odata, service-protection-api-limits, throttling-faq, entity-sales-order-headers-v2-salesorderheaderv2), supply-chain inventory-visibility-api, business-events home page.

### 4.5 Dynamics 365 Sales (Dataverse) | FEASIBLE
Round 1 covered limits; round 2 read the authentication and object pages.
- **API**: Dataverse Web API v9.2 at `{org URL}/api/data/v9.2/` (D). Store the full organisation URL; regional hosts differ: crm (North America), crm3 (Canada), crm4 (Europe), crm8 (India), crm9 (GCC), crm11 (UK), crm12 (France), crm14 (South Africa), crm15 (UAE), crm16 (Germany) (D). No Saudi row was found.
- **Auth**: Entra app registration with a client secret or certificate; token scope `{environment URL}/.default`; token lasts 60 minutes by default (D). The app needs a **Dataverse application user** (Power Platform admin center, Environments, Settings, Users + permissions, Application users, New app user) with a custom security role (D). The application user is unlicensed and does not use a paid licence (D). One application user per app per environment (D). Multitenant apps are allowed (D), but a shared app would need a shipped secret, so each merchant registers their own.
- **Merchant steps**: an Entra administrator registers the app and creates a secret; an environment administrator creates a custom security role and the application user; the merchant pastes tenant ID, client ID, secret and organisation URL. The pages do not name the administrator roles; expect two people (I). Suggested role privileges: create, read, write, append and append-to on order, order product, invoice, account and contact; read on product and price list (I).
- **Tables**: `salesorder`, `salesorderdetail`, `invoice`, `invoicedetail`, `quote`, `quotedetail` (D). An invoice is an order that you bill (D). Required fields were not on the page; `pricelevelid` and `transactioncurrencyid` may be required (I, test in a trial). Account and contact (email on `emailaddress1`) and product and price list are standard (I).
- **Duplicates**: `PATCH entityset(altkey=value)` is an upsert; `If-Match: *` makes it update-only and `If-None-Match: *` create-only (D). A key on `salesorder` needs a custom column for the WooCommerce order number, delivered as a solution the merchant imports (I).
- **Webhooks**: registered with the Plug-in Registration tool, ports 80 and 443 only, JSON `RemoteExecutionContext`, 60 second timeout, one retry on 502, 503, 504; payloads over 256 KB lose the input parameters (D). Keep polling as the fallback. `$batch` takes up to 1,000 requests; change sets are atomic and need CRLF line endings (D).
- **Limits**: 6,000 requests, 20 minutes of execution and 52 concurrent per user per 5 minutes; 429 with `Retry-After` (D).
- **Sandbox**: the free Developer Plan includes Dataverse but **cannot install Dynamics 365 apps** (D). The Sales trial lasts 30 days, extends once for 30, and cannot be reset (D). From 15 Nov 2026 sign-up no longer creates a developer environment; unmanaged developer environments are suspended from 15 Dec 2026 and deleted from 15 Jan 2027 (D). A personal email cannot open the admin center to add users and roles (D), which probably blocks creating the application user (I). Whether `salesorder` exists without the Sales app: unknown; test in a trial.
- **Sources**: learn.microsoft.com power-apps developer data-platform (authenticate-oauth, use-upsert-insert-update-record, use-webhooks, register-web-hook, execute-batch-operations-using-web-api); power-platform admin (manage-application-users, new-datacenter-regions, trial-environments, developer/plan); dynamics365/sales developer quote-order-invoice-entities.

### 4.6 SAP Business One | FEASIBLE WITH CAVEATS
- **API**: Service Layer, `/b1s/v1` (OData v3, deprecated since FP 2405, 3P) and `/b1s/v2` (OData v4) (D). URL is customer-specific, port from the SLD (D).
- **Auth**: `POST /Login` with `CompanyDB`, `UserName`, `Password` returns `B1SESSION` and `ROUTEID` cookies, sample timeout 30 minutes, 401 code 301 on expiry (D). OIDC supported through an identity provider (D).
- **Gate**: Indirect Access instance licence plus a dedicated Indirect Access user (3P, 2018 source, may be outdated). TLS 1.2 or 1.3; default certificate is self-signed (D).
- **Merchant steps (I)**: make Service Layer reachable (reverse proxy, VPN or allowlist), create a dedicated licensed user, create user-defined fields for the Woo order ID, give URL, company database, user and password, plus a CA certificate if self-signed.
- **Objects**: Orders, Invoices, DeliveryNotes, CreditNotes; `NumAtCard`; `TrackingNumber` on delivery notes (3P); stock in `ItemWarehouseInfoCollection` (I). Duplicate card code returns error -10 (D).
- **Webhooks**: only from FP 2602 (3P). Large documents (500+ lines) can return 502 unless the proxy timeout is raised (D).
- **Notes**: SAP's April 2026 API Policy limits integrations to published APIs and restricts bulk extraction (D).
- **Sources**: SAP Learning Service Layer courses; the Service Layer PDF mirror; sap-b1-blog.com glossary and FP 2602 articles; Sana Commerce knowledge base.

### 4.7 SAP S/4HANA | FEASIBLE WITH CAVEATS
- **API**: Sales Order A2X OData V2 `API_SALES_ORDER_SRV` (3P); OData V4 sales order API from 2402 (title only). Cloud hosts are tenant-specific (3P).
- **Auth**: basic with a communication user, or OAuth 2.0 authorization code; the client ID appears on the communication arrangement and the secret is the communication user's password (3P).
- **Merchant steps**: tenant admin creates communication user, system and arrangements (`SAP_COM_0109` sales orders, `SAP_COM_0008` business partners, `SAP_COM_0009` products) (3P).
- **Not found / inferred**: customers, products, stock, deliveries, billing endpoints (I); idempotency (none found); webhooks need SAP Advanced Event Mesh (D). Throttling reportedly returns 503 not 429 (3P).
- **Sources**: help.sap.com API Policy PDF; Prismatic and StackOne connector guides (3P).

### 4.8 Oracle Fusion Cloud ERP and SCM | FEASIBLE WITH CAVEATS
- **API**: REST at `https://{instance}.fa.{datacenter}.oraclecloud.com/fscmRestApi/resources/{version}/{resource}` (3P).
- **Auth**: OAuth 2.0 client credentials via the identity domain: scope `urn:opc:resource:fusion:<POD>:erp/`, token 3,600 seconds, no refresh token (D). The client ID must also exist as a Fusion user, followed by the "Import User and Role Application Security Data" job (D).
- **Merchant steps**: partner-level work: create a confidential app, create the matching Fusion user, assign roles, run the import job, provide host, client ID, secret and pod scope.
- **Limits**: GET returns at most 499 rows; rate limits unpublished, throttling returns 429 (3P). Objects (`receivablesInvoices`, `receivablesCreditMemos`, `salesOrdersForOrderHub`, `itemsV2`) are inferred.
- **Sources**: docs.oracle.com Retrieving_Bearer_Token_Using_IDCS (25D and 26A); apideck Oracle Fusion guide (3P).

### 4.9 Sage Accounting | FEASIBLE WITH CAVEATS
- **API**: REST v3.1, base `https://api.accounting.sage.com/v3.1` (3P); v3 scheduled for retirement 31 Jul 2026 (D). Business chosen with `X-Business` header.
- **Auth**: OAuth 2.0 authorization code. Authorise at `https://www.sageone.com/oauth2/auth/central`, token at `https://oauth.accounting.sage.com/token`. Access token 300 seconds, refresh token 31 days, code single-use for 60 seconds. PKCE is supported but `client_secret` is **still required** (D).
- **Gate**: self-service developer account; no approval; API is free (D).
- **Merchant steps**: create a Sage developer account, register an app with an exact callback URL, paste Client ID and Secret, sign in, choose country and business; prepare income ledger account and tax rates.
- **Objects**: `POST /sales_invoices` (needs contact, ledger account, tax rate per line; decimals as strings). `idempotency_id` supported. **No sales-order endpoint, no delivery or tracking object, no webhooks** found. Stock changes via `stock_movements` (community). Credit note POST unconfirmed.
- **Limits**: 1,296,000 per day and 150 concurrent per app; 429 on excess (D). Page size 200.
- **Regions**: UK, IE, CA, ES, FR (D). No Australia, UAE or Saudi found.
- **Sources**: developer.sage.com/accounting guides (authentication, concepts overview, idempotency, invoicing, quick-start, announcements).

### 4.10 Sage 50 | BLOCKED (direct)
UK: Sage staff state there is no supported API; the only route is the paid Sage Data Objects SDK (community). US: Sage 50 .NET API, Windows only, 32-bit .NET 4.8, runs on the same machine as the desktop app, needs an application ID from Sage (D). Sage-hosted Sage 50 cannot host third-party apps (community). A WordPress site cannot reach it without a local Windows agent.
**Sources**: developer-community.sage.com/topic/495; Sage 50 .NET API Quick Start Guide PDF.

### 4.11 Sage 200 | FEASIBLE WITH CAVEATS
- **API**: REST/OData; base `https://api.columbus.sage.com/uk/sage200/accounts/` (Standard) or `.../sage200extra/accounts/` (Professional); headers `X-Site` and `X-Company` (D). UK only.
- **Auth**: OAuth 2.0 authorization code through Sage ID; access token 8 hours, refresh up to 90 days (D). Also needs a developer-portal subscription key (`ocp-apim-subscription-key`).
- **Gate**: Sage ISV developer account form, credential request form (up to 72 hours). Professional needs the partner to set up Azure AD tunnelling (D). A free plugin cannot ship the secret and subscription key.
- **Objects**: `POST /sop_orders` with lines (community). Credit notes, tracking, external reference, idempotency, webhooks: not found. Defaults 500 rows, 5,000 with `$top`.
- **Sources**: developer.sage.com/200 guides (authenticating, api_faq, api-setup-responsibilities, standard-setup-api).

### 4.12 Sage 300 | FEASIBLE WITH CAVEATS
Sage 300 CRE and Sage 300 People are separate products with their own APIs; this is Sage 300 ERP. Mostly third-party sources; the official reference ships inside the install, not online (3P).
- **API**: REST Web API, installed with the Web Screens option on IIS, Swagger at `/sage300webapi`; URL pattern `https://{host}/Sage300WebApi/v1.0/-/{COMPANY}/{MODULE}/{Entity}`, for example `AR/ARCustomers('1200')`; `POST /OE/OEOrders` reported (3P). Latest release 2027 (3P). developer.sage.com/300 lists only the Web SDK and Product SDK (D).
- **Auth**: HTTP Basic with a Sage 300 user whose security group has WEB-API access (3P). No OAuth or API key found.
- **Exposure**: the merchant publishes their own IIS. Sage R&D advises exposing only ports 80 and 443 with an https binding and Require SSL, or using a VPN (3P). The plugin must refuse non-https addresses.
- **Objects**: confirmed `OE/OEOrders`, `AR/ARCustomers`, `AP/APVendors` (3P); invoices, credit notes, items and stock by location not found. Query options `$filter`, `$orderby` (indexed fields only), `$top`, `$skip` (3P). No upsert found: look up before posting.
- **Partner programme** (D, Aug 2023 PDF): any developer may apply; acceptance is at Sage's discretion; the Product SDK is for registered partners; you may not imply Sage endorses you. Whether use of the Web API needs partner status, and whether a free or open-source plugin is allowed, is **not addressed: ask Sage before launch**.
- **Sandbox**: none found; sample companies SAMLTD and SAMINC (3P). Middle East resellers: not found.

### 4.12b Sage X3 | FEASIBLE WITH CAVEATS
- **API** (D, v12 help, dated 8 Sep 2026): RESTful Web API based on SData 2.0 at `/api1/`; SOAP also exists. Reads work across modules; **updates are limited to upgraded modules**, classic modules use SOAP. Query `/api1/x3/erp/{ENDPOINT}/{CLASS}?representation={REPR}.$query`; create is `POST` with `.$create`; a 201 response does not mean success, check `$diagnoses` (D). Stateless; each call carries an authorization header.
- **Auth** (D): Basic and certificate authentication are on-premise only; **OAuth 2 is the only method in Sage X3 Online**. GraphQL (2021 R2 and later, `/xtrem/api`, POST only) uses a connected application created under Administration, Settings, Authentication, Connected applications, giving a client ID and secret and a JWT bearer token with a dedicated allowed user (D). Client credentials on-premise was reported as an acknowledged Sage bug (3P).
- **Objects**: `BPCUSTOMER` documented; `SORDER` appears in a forum title (3P); stock, invoice and item classes not confirmed. `x3MasterData` GraphQL package has Customer, Product, ProductSite nodes; sales order, invoice and stock nodes not found (D).
- **Limits**: page size 20 by default; no rate limits found (D).
- **Duplicates**: not covered; use a local ledger plus lookup (I).
- **Partner programme** (D): applicants complete required certifications, with 6 free support cases; acceptance is discretionary.
- **Sandbox and regions**: no public sandbox; an Africa and Middle East forum exists (3P); KSA and UAE localisation not found.

### 4.13 MYOB, Holded, NetSuite, Acumatica | FEASIBLE WITH CAVEATS (each)

#### MYOB (Australia and New Zealand)
- **API**: use the **MYOB Business API** (formerly AccountRight), header `x-myobapi-version: v2`, base `https://api.myob.com/accountright/`; covers AccountRight, Essentials and MYOB Business (D, pages dated 2022 and 2023). The `CompanyFile` scope was removed on 1 Sep 2026 and new consents using it now fail (3P). No NZ-specific difference found.
- **Auth** (D): OAuth 2.0 authorization code; authorize `secure.myob.com/oauth2/account/authorize`, token `secure.myob.com/oauth2/v1/authorize`; 13 `sme-*` scopes (for example `sme-sales`, `sme-inventory`, `sme-contacts-customer`, `sme-company-file`); sample access token `expires_in` 1200; refresh returns a new refresh token; client secret required; PKCE not mentioned; the redirect URI is fixed per API key.
- **Gate**: submit the API-access form; MYOB creates a my.MYOB login and a sandbox invite; then Register App in the Developer tab (D). No review described. Free developer licence reported (3P). Essentials files have API access "but not all features" (D).
- **Merchant steps**: request access and accept the sandbox invite; register an app with the plugin's callback; paste key and secret; Connect; sign in at secure.myob.com as a company-file administrator and consent to one ledger (D, 3P for the administrator requirement).
- **Objects**: `POST {cf_uri}/Sale/Invoice/Item` and `/Sale/Order/Item` with lines (type, description, ShipQuantity, UnitPrice, Item, Account, TaxCode); order `Number` (13 characters) can be set (D). Customers in `Contact/Customer` (email filter inferred). Items: `Inventory/Item.Number` (30 characters) with `QuantityOnHand` and per-location `LocationDetails` (D). **No tracking field** (closest: ShippingMethod 20, Comment 2,000). Credit note creation not shown; `Sale/CreditRefund` records a cash refund (D). Reference candidates: `CustomerPurchaseOrderNumber` (255) and order `Number`. No idempotency key, no webhooks found.
- **Limits**: 8 requests per second and 1,000,000 per day per key (3P, MYOB moderator); over the limit returns **403 `RateLimitError`, not 429** (D). Pages 400 by default, 1,000 at most (D).
- **Sandbox**: a sandbox company file arrives with the invite (D).
- **Sources**: developer.myob.com (getting-started, authentication, granular_data_scopes, scopes, error-messages, invoice_item, order_item, customer, inventory item, credit_refund); MYOB community forum threads on rate limits and API access (3P).

#### Holded (Spain and Europe)
- **API**: two surfaces: the new v2 at `https://api.holded.com/api/v2/...` (366 endpoints, OpenAPI at `api.holded.com/openapi/es.json`, not fetched) and a legacy v1 using a `key` header; no v1 sunset date found (D).
- **Auth**: Bearer token with per-module read, write or full access, shown once; no OAuth found; owners and admins can use v1; roles with the Developers option cover v2 only (D). Token lifetime not found.
- **Gate**: **paid plan** (the API is not available on the Free plan); self-service; 14-day trial; each plan has a monthly call limit (D).
- **Merchant steps** (D): an owner, admin or Developers-role user opens Settings, Developers, Credentials, adds an API token, chooses modules and access, copies the token and pastes it. For webhooks: Settings, Webhooks, New webhook with the plugin's public https URL, pick events, copy the `whsec_` secret.
- **Objects**: `POST /api/v2/invoices`; sales orders with approve, partial shipment and **tracking update**; contacts; stock update, availability, in-transit and **warehouse stock levels**; `POST /api/v2/credit-notes` with approve (D). Email lookup, SKU lookup and an external reference field not confirmed; `find-by-number` can serve duplicate checks (I). Draft versus approved is a separate state; approving may trigger fiscal numbering (I).
- **Webhooks** (D): invoice, credit note, sales order, waybill, contact, product, `stock.update`; HMAC-SHA256 in `x-holded-webhook-signature`; 5 retries; the payload is a summary so the plugin fetches the record; `x-holded-webhook-id` for de-duplication.
- **Limits**: 100 requests per minute per key then 429 (D, v1 or v2 unclear); three sliding windows (numbers not read).
- **Regional**: VeriFactu ready; Facturae 3.2.x and FACe (D).
- **Sources**: help.holded.com articles 6896051 and 15704965; holded.com developers and API reference pages; developers.holded.com. Fetch the OpenAPI spec before building.

#### NetSuite
- **API** (D): SuiteTalk REST v1 `https://<accountID>.suitetalk.api.netsuite.com/services/rest/record/v1/<type>`. **SOAP end-of-life**: from 2026.1 new integrations should use REST with OAuth 2.0; from 2027.1 no new SOAP or token-based-authentication integrations; 2028.2 all SOAP endpoints disabled (two Oracle pages disagree slightly on 2027.1).
- **Auth** (D): OAuth 2.0 authorization code (redirect URI pre-registered, https; PKCE S256 optional for confidential clients, required for public clients and from 2027.1 for all new integrations; confidential clients use Basic client credentials; access token 3,600 s; refresh token 7 days confidential, 48 hours default for public, one-time use) **or client credentials (M2M)**: no client secret; the plugin signs a JWT (PS256/384/512 or ES256/384/512, exp under 60 minutes) and the merchant uploads **only the public certificate** (at most 5 active certificates per integration, entity and role). Mappings are not copied to a sandbox and are cleared on sandbox refresh.
- **Gate**: the merchant administrator enables OAuth 2.0 and REST Web Services (Setup, Company, Enable Features, SuiteCloud), creates an integration role and user, and an integration record (client ID and secret are shown once). Role permissions "REST Web Services" and "Log in using OAuth 2.0 Access Tokens". Tax through REST needs **SuiteTax**. No API fee and no vendor approval found. SuiteCloud Developer Network (USD 3,000 or 10,000 a year) gives dev accounts and is not stated as required for customer integrations.
- **Merchant steps (recommended M2M, my design choice)**: administrator enables features; creates role and user; creates the integration record (M2M and REST Web Services ticked); the plugin generates a key pair **on the WordPress host** and shows the public certificate; the administrator uploads it at Manage Authentication, OAuth 2.0 Client Credentials (M2M) Setup and gets a certificate ID; the merchant pastes Account ID, Client ID and Certificate ID. Only a NetSuite administrator is involved.
- **Objects** (D): `POST record/v1/salesOrder` with lines in `item.items[]`; invoice via `salesOrder/{id}/!transform/invoice`; fulfilment via `!transform/itemFulfillment` (without Advanced Shipping this also creates an invoice); credit memo via `invoice/{id}/!transform/creditMemo`. Customer upsert `PUT customer/eid:{extId}`. Customer-by-email, SKU, stock per location and read-back via SuiteQL are inferred (I). **Tracking number write through REST is unconfirmed** (3P: `packageTrackingNumber` on the package sublist).
- **Duplicates**: `externalId` with `PUT .../eid:` upsert (D); `X-NetSuite-idempotency-key` with `Prefer: respond-async`, a duplicate returns IDEMPOTENCY_ERROR pointing to the original job, retention not stated (D); batch upsert up to 100 records, async (D).
- **Webhooks**: none native; push needs SuiteScript deployed in the merchant's account. Poll.
- **Limits** (D): integration concurrency base to maximum: Standard 5 to 15, Premium 15 to 45, Enterprise 20 to 80, Ultimate 20 to 140; each SuiteCloud Plus licence adds 10, shared by SOAP, REST and RESTlets; over the limit fails. Rate per minute and payload limits not found. **The queue must run serially on Standard.**
- **Regional**: Saudi e-invoicing through the Electronic Invoicing SuiteApp (free for one country; needs CSR, CSID, compliance check and ZATCA production token) (D). UAE and EU not found.
- **Sources**: docs.oracle.com NetSuite online help sections on OAuth 2.0, M2M, REST record API, SOAP deprecation FAQ, concurrency; netsuite.com SDN page; blogs.oracle.com real-time synchronisation post.

#### Acumatica
Acumatica's own help sites could not be fetched; most facts come from third parties and Acumatica's licensing PDFs.
- **API**: contract-based REST at `https://<instance>/[vdir/]entity/Default/<version>/<Entity>`; every merchant has their own instance; default endpoint versions 20.200.001 to 26.200.001 (26.200.001 changes the query syntax) (3P). Legacy cookie sign-in `/entity/auth/login` will be discontinued (3P).
- **Auth** (3P): authorization code, implicit, resource-owner password and hybrid; **no client-credentials flow found**; PKCE S256 supported; redirect URI must match the one registered; client ID `GUID@Tenant`; shared secret shown once, may carry an expiry; access token 3,600 s; refresh tokens rotate and last 30 days by default, with sliding expiration available since 2023 R2.
- **Gate**: an administrator registers a **connected application** on SM303010 (flow Authorization Code, redirect URI, shared secret). Web API connections are licensed separately from named users (D); whether a WooCommerce connection needs a Commerce licence is not found.
- **Merchant steps**: register the connected application with the plugin's callback; add a shared secret; enable sliding expiration with an infinite absolute lifetime so background sync survives; paste instance URL, tenant, branch, endpoint version, client ID and secret; Connect; an Acumatica user consents with scope `api offline_access` (3P).
- **Objects** (3P unless noted): `PUT SalesOrder` with `OrderType`, customer and `Details[]`; customer filter by `MainContact/Email` (I); stock per warehouse via `ItemWarehouse` or a Generic Inquiry over OData (D blog); **tracking via `PUT Shipment` with `Packages[{TrackingNbr}]`** and read back with `$expand=Packages`; `If-None-Match: *` makes a PUT create-only. **Credit memo or return entity not found.** No idempotency header: look up on `CustomerOrder` first.
- **Webhooks**: push notifications (SM302000) configured by the merchant administrator; poll on `LastModifiedDateTime` by default.
- **Limits** (D, April 2025 guide): requests per minute by edition: Essentials 50, Select 100, Prime 150, Enterprise 300; parallelism 1, 2, 3, 8; over 20 queued requests or a wait over 10 minutes returns 429; unclosed sessions hold a slot for 10 minutes (3P).
- **Sandbox**: purchasable; a free 90-day sandbox before a major upgrade (D); trials cap API sessions at 2 (3P).
- **Sources**: acumatica.com licensing guides (2021 R1, April 2025) and web-services blog; Apideck, CData, Celigo, Nango and MYOB-hosted Acumatica integration guide pages (3P).

#### Gulf e-invoicing (from the Sage and Dynamics round)
- **Saudi Arabia** (D, ZATCA, 24 Jul 2026): phase 2 runs in waves; wave 25 covers taxpayers with VAT-subject revenue above SAR 187,500 in any year from 2022 to 2025, who must integrate by 1 Feb 2027; later waves get at least six months' notice.
- **UAE** (D, Ministry of Finance): mandatory from 1 Jan 2027 for businesses with revenue above AED 50 million; the deadline to appoint an accredited service provider moved to 30 Oct 2026 (passed); 32 providers approved. Other business sizes: not read.
- **Implication (I)**: in both countries push sales orders and let the ERP's certified e-invoicing module issue the invoice. No page read says whether connector-created invoices count as legal e-invoices. The plugin must not claim compliance.

### 4.14 Exact Online | FEASIBLE WITH CAVEATS
Most facts from Maesn, Apideck, Hookdeck, CData and Exact's blog (3P); the official REST reference could not be read.
- **API**: REST/OData JSON at `/api/v1/{division}/...`; base domain per country (`start.exactonline.nl`, `.be`, `.de`, `.es`, `.co.uk`, `.com`); 60 rows per page; down daily 04:00 to 04:30.
- **Auth**: OAuth 2.0 authorization code; access token 600 seconds; refresh only after 570 seconds; single-use rotating refresh token valid 30 days; redirect URI must be exact HTTPS; secret required; PKCE not found.
- **Gate**: developer subscription EUR 15/month with test administrations (D, exact.com). A production app needs Exact review (about 5 working days) and each country needs its own app; a customer can register their own app and hand over the key and secret (D, exact.com).
- **Merchant steps**: Partners, App Store, Manage my apps, register an app with the plugin's callback URL, copy Client ID, secret and webhook secret, sign in, choose the division.
- **Objects**: SalesOrders, SalesInvoices (I); `StockPositions` and `ItemWarehouses` for stock; `GoodsDeliveries` webhook topic; credit notes not found. Missing module returns 403.
- **Limits**: 60 per minute and 5,000 per day per app per company; sequential; 10 errors per hour then 1-hour block (3P).
- **Sources**: exact.com/app-store/developer; maesn.com blog on Exact; hookdeck Exact webhooks guide; cdata StockPositions reference.

### 4.15 Moneybird | FEASIBLE WITH CAVEATS
- **API**: REST v2 `https://moneybird.com/api/v2/{administration_id}/{resource}.json` (D).
- **Auth**: personal API token (Bearer, checkbox scopes, full company access) or OAuth 2.0 authorization code with secret (D). Tokens do not expire today but the docs say to design for expiry. Scopes were swapped on contacts and ledger accounts on 18 and 23 Jun 2026; re-verify.
- **Merchant steps**: create a personal token with `sales_invoices` and `settings` (plus `documents` or `estimates`), paste it, pick the administration.
- **Objects**: `POST sales_invoices.json` (draft until sent); contact by customer ID; products by identifier; credit invoice via `duplicate_creditinvoice` (3P). **No stock fields, no delivery or tracking.** Tax rate and ledger IDs must be mapped per administration.
- **Webhooks**: yes, signed, with an idempotency key header, 10 retries (D).
- **Limits**: 150 per 5 minutes per IP (D). Free plan ended 1 Jul 2026 (3P). Free sandbox with watermarked invoices (D).
- **Sources**: developer.moneybird.com (authentication, introduction, creating-sales-invoices, products, webhooks, changelog).

### 4.16 Pennylane | FEASIBLE WITH CAVEATS
- **API**: Company API v2 `https://app.pennylane.com/api/external/v2/` (D); v1 deprecated.
- **Auth**: company token (read-only or read/write; expiry 1, 6, 12 months or unlimited; shown once) generated at Settings, Connectivity, Developers (D). OAuth is partner-gated: contact Partnerships, Pennylane "may or not grant" a Client ID, and the secret must never be shared (D).
- **Gate**: Essential plan or higher; admin role (D).
- **Objects**: `POST /customer_invoices` (create the customer first; decimals as strings); no idempotency; delivery notes and stock not available; credit-note creation, SKU lookup and e-invoicing behaviour not found.
- **Limits**: 25 requests per 5 seconds per token (D). Webhooks in beta.
- **Regional**: French e-invoicing reform dates recalled from memory only; verify.
- **Sources**: pennylane.readme.io (getting-started, generating-my-api-token, oauth-20-walkthrough, rate-limiting-1, 2026-api-changes-guide, v2-scopes, webhooks).

### 4.17 Fortnox | FEASIBLE WITH CAVEATS
- **API**: REST v3 `https://api.fortnox.se/3/` (D).
- **Auth**: OAuth 2.0 authorization code; code 10 minutes; access 1 hour; refresh 45 days and rotating; secret required via Basic auth; PKCE optional per app; a service-account flow exists to avoid refresh tokens. Static tokens retired 30 Apr 2025 (D).
- **Gate**: each scope needs a licence (`order` needs Order; `warehouse` needs Lager). Integration add-on about SEK 189/month (3P). Publishing needs review and an App Partner agreement (75% revenue share, D). Hidden integrations can be activated via Client ID.
- **Merchant steps**: create an integration in the Developer Portal, paste Client ID and secret, sign in and approve scopes.
- **Objects**: orders, invoices (customer must exist first); stock balance endpoint; "Credit an invoice"; no idempotency header; websocket events (D).
- **Limits**: 300 per minute per client and tenant, enforced as 25 per 5 seconds (D). Up to 30 free test companies (D).
- **Sources**: fortnox.se/developer pages (authorization, scopes, rate limits, websockets, developer-portal, FAQ); apps.fortnox.se/apidocs.

### 4.18 Lexware Office | FEASIBLE WITH CAVEATS
- **API**: REST v1 `https://api.lexware.io/v1`; gateway moved 26 May 2025, old host kept until Dec 2025 (D).
- **Auth**: Bearer API key from `app.lexware.de/addons/public-api`, per organisation, does not expire (3P). No secret. A separate Partner API exists.
- **Gate**: Lexware Office Version XL at no extra cost (D; verify on pricing). Trial and demo account available. No sandbox.
- **Objects**: `POST /invoices` (draft unless `?finalize=true`); contacts by email (min 3 characters); articles by article number; `POST /credit-notes`; no stock; no external-reference field; no idempotency; event subscriptions exist.
- **Limits**: 2 requests per second, 429 without `Retry-After`; page size 250 (D, 3P).
- **Regional**: Germany only; electronic invoice profile read-only.
- **Sources**: developers.lexware.io/docs; lexware.de/funktionen/public-api.

### 4.19 sevDesk | FEASIBLE WITH CAVEATS
Mostly from third parties (3P). REST v1 `https://my.sevdesk.de/api/v1`, per-resource versions via `X-Version`. Static 32-hex API token per user, no prefix, never expires. Needs **Buchhaltung Pro** (EUR 34.90/month monthly term). No sandbox; 14-day trial. Invoices are saved as drafts (`saveInvoice`); `enshrine` (finalise) is irreversible (GoBD). Custom fields on invoices and customers can hold the Woo order ID. No webhooks. Stock read unconfirmed. Rate limits not found. Sources: sevdesk PHP SDK on GitHub, Apideck, Chift docs.

### 4.20 DATEV | BLOCKED (direct); file export FEASIBLE
OAuth 2.0 with PKCE and a client secret; access token 15 minutes, refresh 11 hours single-use (3P). **Production access is not self-service**: sandbox, then a release meeting, then a production release against DATEV test systems; at least two meetings (3P). No sales-order concept: invoices go as XML (Rechnungsdatenservice) or CSV bookings. The tax adviser orders the data service and sets rights. The DATEV-format (EXTF) CSV is public and needs no registration (3P). Sources: datev.de partner pages (D); Maesn DATEV guides (3P).

