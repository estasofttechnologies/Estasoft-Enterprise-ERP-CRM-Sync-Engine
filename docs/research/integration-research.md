# Estasoft Enterprise ERP/CRM Sync Engine: vendor integration research

Researched 2 Nov 2026. Purpose: replace assumptions with what each vendor's own documentation says about API, authentication, who must register the app, how a merchant connects, plan gates, objects, limits and regions.


**This research is split into four documents** (the files are large): this one holds the findings, the summary matrix and the decisions; `estasoft-erp-sync-engine-integration-research-vendors.md` holds the ERP and accounting vendors (section 4); `estasoft-erp-sync-engine-integration-research-vendors-2.md` holds the CRM, Gulf and cloud accounting vendors (sections 5 to 7); `estasoft-erp-sync-engine-integration-research-export-formats.md` holds the import formats for the export-file systems (section 11). Section numbers are kept across the four.

## How to read this document

Tags on a fact:
- **(D)** stated on an official vendor page that was read.
- **(3P)** stated only by a third party (Apideck, Maesn, Chift, partner blogs). Treat as likely, not certain.
- **(I)** inferred or recalled, not read on any page. Treat as a hypothesis to verify before building.
- **Not found** means no source could be read. It does not mean the thing does not exist.

Verdicts:
- **FEASIBLE**: documented path with no blocker found.
- **FEASIBLE WITH CAVEATS**: works, but a named blocker or cost shapes the design.
- **BLOCKED (direct)**: a WordPress site cannot reach it without an extra component (a local agent, a relay, a partner approval).
- **UNKNOWN**: not researched or too little found. Do not build until checked.

## 1. Coverage: what was and was not verified

Nine research tasks ran in parallel on 2 Nov 2026. The web-search budget for the run was used up part-way, so coverage was uneven; a second round of five tasks covered the gaps (sections 4.1, 4.5, 4.12, 4.13, 6.4 and 11).

| Coverage | Systems |
|---|---|
| Read from official docs | Business Central, Dynamics 365 Finance and Supply Chain (mostly), Sage Accounting, Sage 200, Moneybird, Pennylane, Fortnox, Lexware Office, Wafeq, Qoyod (partly), HubSpot, Salesforce, Pipedrive, SuiteCRM, ERPNext, Xero (pricing, OAuth, limits), Zoho CRM limits, Dataverse limits |
| Mostly from partner or third-party pages | Exact Online, sevDesk, DATEV, SAP Business One, SAP S/4HANA, Oracle Fusion, Daftra, Tally, QuickBooks Online (Intuit's site is a JavaScript shell), Zoho Books |
| Second round (2 Nov 2026) | MYOB, Holded, NetSuite, Acumatica, Dynamics 365 Sales, Sage 300, Sage X3: read from official pages where reachable; Acumatica and Sage 300 mostly from third parties. See sections 4.5, 4.12, 4.13 |
| Second round, from GitHub sources | Odoo: the documentation and source repositories (odoo.com/documentation pages errored); SMACC: no public API exists |

No search results were fabricated to fill a gap. Where a section says "not found", the design must not assume the answer.

## 2. The findings that change the design

### 2.1 Estasoft runs no server, so every merchant brings their own credentials

Almost every OAuth vendor needs a client secret at token exchange (Sage Accounting, Exact, Fortnox, HubSpot, Pipedrive, Moneybird, Pennylane, QuickBooks, Xero standard apps, Zoho, Salesforce unless the app is set secret-less, Oracle, S/4HANA). A secret shipped inside a GPL plugin is public. Several vendors also fix one redirect URI per app (Pipedrive, Exact, Fortnox), which cannot match thousands of merchant domains. Pennylane's OAuth is partner-approved and its terms say the secret must never be shared. So the baseline connection model is **merchant-owned credentials**, in five shapes (four ways to connect with credentials, and an export file for systems with no web connection):

| Shape | What the merchant does | Systems (documented) |
|---|---|---|
| **A. Paste a key or token** | Generate a key inside the vendor app, paste it | Odoo, ERPNext (key and secret), Holded, Qoyod, Wafeq, Daftra, Lexware Office, sevDesk, Moneybird (personal token), Pennylane (company token), Pipedrive (API token), HubSpot (Service Key, beta), SuiteCRM (client credentials) |
| **B. Register your own app, then sign in** | Create an app in the vendor's developer portal, paste Client ID and Secret, then sign in and approve | Sage Accounting, Exact Online, Fortnox, MYOB, Acumatica, Xero, Zoho Books and CRM (Self Client), Salesforce (external client app), QuickBooks Online (plus Intuit's production assessment), Moneybird (optional) |
| **C. Register an app in the cloud directory, no sign-in** | Entra or identity-domain admin creates an app and secret (NetSuite: uploads a certificate instead of a secret), ERP admin authorises it | Business Central, Finance and Supply Chain, Dynamics 365 Sales, NetSuite, Oracle Fusion, S/4HANA communication arrangement |
| **D. Server login with company, user and password** | Give URL, company database, user, password; plugin keeps a session | SAP Business One Service Layer, Sage 300 |
| **E. Export file** | Press Create export, download a file, import it in the desktop program | TallyPrime, DATEV, Sage 50 |

Shape B and C need two people in many companies (a developer-portal user plus an ERP admin). The connect screens must say who needs to be present.

### 2.2 Some systems cannot be reached from a hosted WordPress site

- **QuickBooks Online in the Gulf**: not supported (no UAE or Saudi tax-authority integration found). Works for US, UK, Canada, Australia.
- **Sage 50 (UK and US)**: no web API. UK has no supported API; US has a Windows-only SDK that must run on the same machine. **BLOCKED (direct).**
- **TallyPrime**: local HTTP server on the merchant's PC (port 9000), no authentication, never to be exposed to the internet. **BLOCKED (direct).** Decided: an export file.
- **DATEV**: the Online API is gated per integrator (production release meetings, partner status). Not self-service. **BLOCKED (direct)** for the API. The DATEV-format CSV export is public and needs no registration; that is what the plugin writes.
- **SAP Business One and on-premise SAP/ERP generally**: Service Layer usually sits inside the merchant's network with a self-signed certificate. Reachability, allowlisting and custom CA support are per-merchant problems.

The earlier decision "all 34 systems are Available" is therefore only true if "available" includes export and local-agent modes for these. The owner decided on export files (section 8).

### 2.3 Credentials expire, so the plugin needs expiry handling everywhere

| System | Expiry fact |
|---|---|
| Odoo 17 to 20 | Docs: key duration at most 3 months. 19.0 source: non-administrator users are capped at 90 days by a group setting; administrators can make persistent keys or choose a date. 17.0 keys probably do not expire (I) |
| Pennylane | Company token expires in 1, 6 or 12 months or never, chosen by the merchant (D) |
| Sage Accounting | Access token 5 minutes, refresh token 31 days (D); idle sites need re-authorising |
| Exact Online | Access token 10 minutes, single-use rotating refresh token, 30 days (3P) |
| Fortnox | Access token 1 hour, rotating refresh token 45 days (D) |
| Pipedrive | Access token 60 minutes, refresh token expires after 60 days unused (D) |
| Microsoft apps | Client secrets expire; merchant chooses the date (D) |
| Salesforce | Refresh tokens unused for 30 days expire from 4 Nov 2026 in production (secondary source) |
| QuickBooks Online | Access 60 minutes; refresh rolling 100 days with a 5-year hard cap; refresh value rotates about daily (D, 3P) |
| Xero | Access 30 minutes; refresh 60 days unused, rotating (D) |
| Zoho | Access 1 hour; refresh does not expire but the 21st token silently revokes the oldest (3P) |
| Business Central / Oracle / Dataverse | Access token about 1 hour, no refresh token for client credentials (D) |

Design consequence: store expiry date where known, warn 14 days before, and give a one-click "replace key" flow that does not lose mappings.

### 2.4 Nobody offers a general idempotency key

- Sage Accounting: `idempotency_id` in the body, 32 characters, 7 days (D).
- Wafeq: `X-Wafeq-Idempotency-Key`, honoured for 1 hour only (D).
- Moneybird: an idempotency header exists on **incoming webhooks** only (D).
- Xero: optional `Idempotency-Key` header on POST, PUT, PATCH (3P). QuickBooks: a `requestid` replay parameter (3P).
- Everyone else: none found.

So the plugin must keep its own ledger (WooCommerce ID to remote ID), write the Woo order number into the vendor's reference field, and search before creating. A timeout is an unknown outcome and must be reconciled before any retry. The reference field differs per system:

| System | Field to carry the order number |
|---|---|
| Odoo | `client_order_ref` on `sale.order` (I) |
| ERPNext | "Customer's Purchase Order" (`po_no`, I) or a custom field |
| Business Central | `externalDocumentNumber` (D, not unique) |
| SAP Business One | `NumAtCard` or a user-defined field (I) |
| Wafeq | `external_id` on invoices, credit notes, contacts, items (D) |
| Moneybird / Sage Accounting | `reference` (D) |
| Exact | `YourRef` (I) |
| Lexware Office | none found; only title, introduction, remark (D) |
| Qoyod, Daftra, Pennylane | not found |
| HubSpot | unique-value properties as `idProperty` (D) |
| Salesforce | external ID fields and upsert (D) |

### 2.5 Stock and tracking read-back are the exception, not the rule

Documented stock read: Fortnox (needs the stock licence), Exact (StockPositions, 3P), Daftra (`stock_balance`), Odoo (`stock.quant`, I), SAP Business One (`ItemWarehouseInfoCollection`, I). Not available in the standard API: Business Central (no per-location stock, no tracking field), Moneybird, Pennylane, Lexware Office, Wafeq (no stock quantity), Sage Accounting (stock movements only on create and by movement). Tracking number read: SAP Business One (`TrackingNumber` on delivery notes, 3P) and Odoo (`carrier_tracking_ref`, I). So **stock sync and tracking sync must be per-system capability flags**, and the setup screens must show what each system can do.

### 2.6 Webhooks are mostly unusable, so polling is the default

Business Central webhooks expire after 3 days and need a validation handshake. SAP Business One webhooks need release FP 2602. S/4HANA and Oracle need an event broker. HubSpot Service Keys do not support webhooks. Salesforce, SuiteCRM, Odoo, Sage Accounting, sevDesk, Qoyod: none found. Poll with a modified-since cursor through Action Scheduler and treat webhooks as an optional accelerator.

### 2.7 Rate limits differ by orders of magnitude, so throttle per connection

| System | Limit |
|---|---|
| Odoo | About 1 call per second and none in parallel (Acceptable Use Policy); batch where possible (D) |
| Lexware Office | 2 requests per second, no `Retry-After` (D) |
| Exact Online | 60 per minute and 5,000 per day per app per company, sequential, daily outage 04:00 to 04:30 (3P) |
| Fortnox | 25 per 5 seconds per tenant (D) |
| Pennylane | 25 per 5 seconds per token (D) |
| Wafeq | 60 per minute per organisation, per record type (D) |
| Moneybird | 150 per 5 minutes per IP (D) |
| Business Central | 600 per minute per environment (production), 5 concurrent (D) |
| Sage Accounting | 150 concurrent per app; 1,296,000 per day (D) |
| HubSpot | 100 to 190 per 10 seconds depending on tier (D) |
| Pipedrive | 20 to 480 per 2 seconds by plan and auth type; daily token budget (D) |
| Dataverse | 6,000 requests per 5 minutes per user (D) |

### 2.8 Moving targets to design around

- **Odoo**: JSON-2 (`/json/2/...`, bearer key) exists from 19.0. The old `/jsonrpc` and XML-RPC are deprecated; the 19.0 and 20.0 docs say removal in Odoo 22 (fall 2028) and Online 21.1 (winter 2027), while an earlier commit said Odoo 20 (D, gh; the branches disagree). Support both: JSON-RPC for 17 and 18, JSON-2 for 19 and later, detected with `/web/version`.
- **Odoo Online**: the external API works only on the **Custom** plan. One App Free and Standard have no API (D).
- **HubSpot**: legacy private-app creation closed on 26 Oct 2026; Service Keys (beta) replace them; v1 to v4 APIs go away in 2027 (D).
- **Salesforce**: new connected apps are restricted; use external client apps. Username-password flow retires Feb 2027 (secondary).
- **Sage Accounting**: v3 scheduled for retirement 31 Jul 2026; target v3.1 (D).
- **Pennylane**: v1 deprecated; target Company API v2 (D). Webhooks are beta.
- **Business Central**: API v2.0; v1.0 removed in version 28 (D).
- **ERPNext**: v14 support ended Jan 2026; v15 and v16 both live (D).

## 3. Summary matrix

Auth shape: A paste key, B own OAuth app, C cloud-directory app, D server login. "Gate" is what the merchant must already have.

| System | Shape | Gate on merchant | Order reference | Stock read | Tracking | Webhooks | Verdict |
|---|---|---|---|---|---|---|---|
| Odoo | A | Online: Custom plan; self-host: none | `client_order_ref` (D, not unique) | yes, per warehouse (D) | yes, needs Delivery module (D) | none generic (D) | FEASIBLE WITH CAVEATS |
| ERPNext | A | none | `po_no` (I) | via Bin (I) | not confirmed | merchant-configured | FEASIBLE WITH CAVEATS |
| Business Central | C | Entra admin and BC admin | `externalDocumentNumber` | no per-location | no | yes, 3-day expiry | FEASIBLE WITH CAVEATS |
| D365 Finance and SCM | C | Entra admin and F&O sysadmin | not found | needs Inventory Visibility add-in | not found | broker only | FEASIBLE WITH CAVEATS |
| D365 Sales (Dataverse) | C | Two administrators: Entra and Power Platform; a Sales licence | alternate key upsert (D) | not used | not used | yes, 1 retry (D) | FEASIBLE |
| SAP Business One | D | Indirect Access licence, reachable Service Layer | `NumAtCard` / UDF | yes | yes (3P) | FP 2602 only | FEASIBLE WITH CAVEATS |
| SAP S/4HANA | C | tenant admin | not found | not found | not found | broker only | FEASIBLE WITH CAVEATS |
| Oracle Fusion | C | identity-domain admin and Fusion admin | source transaction number (I) | not found | not found | needs OIC | FEASIBLE WITH CAVEATS |
| Sage Accounting | B | none | `reference`, `idempotency_id` | movements only | no | none | FEASIBLE WITH CAVEATS |
| Sage 50 UK/US | E (export file) | desktop only | invoice number as reference | n/a | n/a | n/a | BLOCKED (direct); export file FEASIBLE with caveats |
| Sage 200 | B plus subscription key | ISV account and credential forms | not found | not found | not found | not found | FEASIBLE WITH CAVEATS |
| Sage 300 | D | Your own web server (IIS) reachable over https; partner status unanswered | not found; look up first | not found | not found | none | FEASIBLE WITH CAVEATS |
| Sage X3 | B (Online) or D (on-premise) | Partner certification expected; sales write path depends on version | not found; look up first | not found | not found | none found | FEASIBLE WITH CAVEATS |
| MYOB | B (key by form request) | Developer key request to MYOB; company-file administrator | `CustomerPurchaseOrderNumber`, order `Number` (D) | yes, per location (D) | no field (D) | none found | FEASIBLE WITH CAVEATS |
| Holded | A | Paid plan; owner, admin or developer role | none; `find-by-number` | yes (D) | yes (D) | yes, signed (D) | FEASIBLE WITH CAVEATS |
| Acumatica | B | Administrator registers a connected application; API licence | none; look up `CustomerOrder` | yes (3P) | yes (3P) | optional push (3P) | FEASIBLE WITH CAVEATS |
| NetSuite | C (certificate, no secret) | NetSuite administrator; SuiteTax for tax | `externalId` upsert (D) plus idempotency header (D) | unconfirmed | unconfirmed | none | FEASIBLE WITH CAVEATS |
| Exact Online | B | merchant registers app; module-dependent | `YourRef` (I) | yes (3P) | partial (3P) | thin payloads | FEASIBLE WITH CAVEATS |
| Moneybird | A (or B) | paid plan | `reference` | no | no | yes | FEASIBLE WITH CAVEATS |
| Pennylane | A | Essential plan or higher, admin role | not found | no | no | beta | FEASIBLE WITH CAVEATS |
| Fortnox | B | per-scope licences and integration add-on | not confirmed | yes with stock licence | not found | websocket only | FEASIBLE WITH CAVEATS |
| Lexware Office | A | Version XL | none | no | no | event subscriptions | FEASIBLE WITH CAVEATS |
| sevDesk | A | Buchhaltung Pro (EUR 34.90/month) | custom field (I) | unconfirmed | no | none | FEASIBLE WITH CAVEATS |
| DATEV | E (export file) | tax adviser imports; online link gated | Belegfeld 1 | no | no | none | BLOCKED (direct); export file FEASIBLE |
| HubSpot | A (Service Key) | admin with developer access | unique property | n/a | n/a | not on Service Keys | FEASIBLE WITH CAVEATS |
| Salesforce | B (external client app) | Enterprise or higher, or API add-on | external ID | n/a | n/a | none | FEASIBLE WITH CAVEATS |
| Pipedrive | A | "use API" enabled by admin | none | n/a | n/a | yes | FEASIBLE WITH CAVEATS |
| SuiteCRM | A (server keys) | server keys and rewrite config | none | n/a | n/a | none | FEASIBLE WITH CAVEATS |
| Qoyod | A | Pro plan or higher | not found | not found | not found | none | FEASIBLE WITH CAVEATS |
| Wafeq | A | Starter plan or higher, enable API | `external_id` + idempotency key | no | no | ZATCA events only | FEASIBLE |
| Daftra | A | plan gate not found | none | yes | no | yes (details missing) | FEASIBLE WITH CAVEATS |
| SMACC | none | no public API; SMACC's own connectors only | not found | not found | not found | not found | BLOCKED (no public API) |
| TallyPrime | E (export file) | desktop PC; ledgers and stock items must exist | voucher number; REMOTEID | n/a | n/a | none | BLOCKED (direct); export file FEASIBLE |
| QuickBooks Online | B (per-app production assessment) | Intuit assessment for every production app; Gulf not supported | DocNumber / PrivateNote (I) | `QtyOnHand`, no warehouses | tracking field on invoice only (I) | CloudEvents only | FEASIBLE WITH CAVEATS (US/UK/CA/AU); BLOCKED for Gulf |
| Xero | B (or paid Custom Connection) | Starter: 5 connections, 1,000 calls/day | `Reference`; `Idempotency-Key` (3P) | `QuantityOnHand`, no warehouses | no | yes | FEASIBLE WITH CAVEATS |
| Zoho Books | B (Self Client) | none; know your data centre | `reference_number` (I) | not found | not found | workflow rules only | FEASIBLE WITH CAVEATS |
| Zoho CRM | B (Self Client) | none; credits per edition | unique-field upsert (3P) | n/a | n/a | per workflow | FEASIBLE WITH CAVEATS |

## 8. Decisions this research put in front of the owner, and the answers (2 Nov 2026)

1. **"Available" for every system.** Sage 50, TallyPrime and the DATEV online link cannot be reached by a hosted plugin alone. **Decided: export files, written inside WordPress, no helper program, nothing outside WordPress rules** (section 11; spec addendum section 8). SMACC has no public API, so it is listed but cannot be selected yet.
2. **Sign-in relay.** The owner was unsure and asked for it to be made ready. **Decided (by me, owner can override): the first release has no relay**; the connection layer has a broker interface so a relay can be added later (spec addendum section 7).
3. **Second research round** for the unresearched systems: done (sections 4.1, 4.5, 4.12, 4.13, 6.4).
4. **Plan requirements shown before connecting.** Done on the connect screens: Odoo Online Custom plan, Qoyod Pro, Wafeq Starter, Lexware XL, sevDesk Buchhaltung Pro, Pennylane Essential, Holded paid plan, Fortnox licences.
5. **Wave order.** Still open. The research changes the effort ranking. Cheapest to build: systems that connect with a key and have clear documentation (Wafeq, Lexware Office, Moneybird, Pennylane, Holded, Odoo, ERPNext, Pipedrive, HubSpot). Heaviest: SAP, Oracle, NetSuite, Sage 200, Salesforce, SuiteCRM, Acumatica.

## 9. Design and spec changes implied

- One **connect screen per auth shape** (A paste key, B own-app steps then sign-in, C cloud-directory app steps, D server login with reachability test), each listing who must be present.
- A **capability table per system** (stock read, tracking write, credit notes, webhooks, reference field) driving which settings are shown.
- A **credential expiry** state in the connection card with a 14-day warning and a replace-key flow.
- A **plan requirement** line on every system card and connect screen.
- A **reachability test** and **custom CA certificate** field for server-login systems.
- Connector contract in the spec gains: capability flags, transport adapters per version (Odoo RPC and JSON-2), token refresh under lock with atomic persist, per-connection token bucket, polling cursors.
- Error codes to add: key expired, plan lacks API, server unreachable, certificate not trusted, app not authorised in the ERP, rate limited with retry window.

