# Connection methods, export mode and the relay decision (spec addendum)

Written 2 Nov 2026 from the vendor research (`estasoft-erp-sync-engine-integration-research.md`) and the owner's answers the same day. It extends `estasoft-erp-sync-engine-phase1-spec.md`. Where it disagrees with that spec, this document wins. Facts about vendors are tagged as in the research document: (D) read on an official page, (3P) third-party only, (I) inferred.

## 1. Owner decisions

| # | Question | Owner's answer | Result |
|---|---|---|---|
| 1 | Sage 50, TallyPrime and DATEV have no web connection. Export file, helper program, or not possible? | Make it, but nothing outside WordPress requirements. | **Export file only.** The plugin writes the file inside WordPress. No helper program, no outside server. |
| 2 | Should Estasoft run an optional sign-in relay? | Not sure; make it ready from my side. | **Decision (mine, owner can override): the first release has no relay.** The connection layer is built so a relay can be added later without touching any connector. Section 7 gives the reasons and the design. |
| 3 | A second research round? | Yes. | Run on 2 Nov 2026 (MYOB, Holded, NetSuite, Acumatica, Dynamics 365 Sales, Sage 300, Sage X3, SMACC, Odoo's official sources, and the import formats for the export systems). Results are in the research document. SMACC has no public API. |

## 2. The ways a system connects

Every connector declares exactly one `connection_method`. The Setup and Connections screens are drawn per method (boards B03, B03b to B03f, D06, S05).

| Method | Code | What the merchant does | Stored | Example systems |
|---|---|---|---|---|
| Paste a key | `key` | Makes a key inside the system, pastes it, presses Test | the key (encrypted), the address, extra IDs | Odoo, ERPNext, Wafeq, Qoyod, Daftra, Lexware Office, sevDesk, Moneybird, Pennylane, Pipedrive, HubSpot (Service Key), SuiteCRM |
| Own app, then sign in | `own_app` | Creates an app in the vendor's developer area, pastes Client ID and secret, then signs in once | Client ID and secret (encrypted), access and refresh tokens (encrypted), expiry dates | Xero, QuickBooks Online, Zoho Books and CRM (Self Client code), Salesforce, Sage Accounting, Exact Online, Fortnox |
| Cloud app | `cloud_app` | An administrator registers an app in the vendor's cloud directory; another administrator enables it inside the ERP; merchant pastes IDs and secret | IDs, secret (encrypted), secret end date | Business Central, Finance and Supply Chain, SAP S/4HANA, Oracle Fusion |
| Server sign-in | `server_login` | Gives a server address, company database, user and password; optionally uploads a certificate authority file | address, database, user, password (encrypted), CA file | SAP Business One |
| Export file | `export_file` | Presses Create export, downloads a file, imports it in the desktop program | settings and ledger names only; no credentials | TallyPrime, DATEV, Sage 50 |

Two further values exist. `none` is for a system whose vendor publishes no way to connect (SMACC): it is listed, cannot be selected, and the screen says why. `unknown` is for a system whose way to connect depends on the version or was not fully documented (Sage X3), shown as "To be confirmed". Server sign-in now also covers Sage 300 (Basic sign-in on the merchant's own web server, https only). NetSuite and Dynamics 365 Sales use `cloud_app`; NetSuite's variant has **no secret**: the plugin makes a key pair on the WordPress host, keeps the private key encrypted, and shows the public certificate for the NetSuite administrator to upload. MYOB, Acumatica, Xero and the others in the own-app row use Client ID and secret.

### 2.1 Test connection (all methods except `export_file`)

`Test connection` must run these checks in order and report each one separately, stopping at the first failure. A passing test never saves anything the merchant did not enter; a failing test saves nothing.

1. **Reach** the address (30 second limit). Failure: `SYNC-NET-001`.
2. **Certificate** is trusted, using the site's certificate list plus the uploaded authority file if any. Failure: `SYNC-NET-003`. The check is never turned off.
3. **Sign in** with the credentials. Refused: `SYNC-AUTH-001`. Key or secret past its end date: `SYNC-AUTH-005`.
4. **Plan or licence** lets the API in. Failure: `SYNC-AUTH-006`.
5. **App known** to the ERP (cloud apps). Failure: `SYNC-AUTH-007`.
6. **Permissions**: can read items and customers, can create the order document, and can create a credit note. Missing: `SYNC-AUTH-004`, naming the missing permission.
7. **Detect version and shape**: Odoo with `GET /web/version`: 17 or 18 use JSON-RPC (prefer it over XML-RPC, which a PHP 8 host may lack), 19 and later use JSON-2; never call Odoo's database service. SAP Business One v1 versus v2; ERPNext v14, v15 or v16; Acumatica endpoint version. Store it on the connection.

### 2.2 Who must be present

Each connect screen says who is needed (a Microsoft 365 administrator and a Business Central administrator; an IT team for SAP; the tax adviser for DATEV). Every connect screen has a "Copy these steps for my administrator" button. It copies plain text, nothing is sent.

## 3. Credentials

1. **Encryption.** Secrets are encrypted with libsodium (`sodium_crypto_secretbox`, available in PHP 8.1 and later) using a key defined in `wp-config.php` (the readiness check already requires it). A missing key blocks saving (`SYNC-CFG-002`).
2. **Storage.** In `estasoft_sync_connections`, never in `wp_options`, never autoloaded, never in the log, never in the support report. Screens show only the last four characters.
3. **Never returned.** The REST API and screens never return a secret. Replacing one is a write-only field.
4. **Expiry.** Each connection stores `credential_expires_at` where the vendor tells us (Odoo 19 key, 3 months at most; Pennylane token; Microsoft client secret; refresh token windows) and `credential_warn_days = 14`. A warning appears on Connections and the Dashboard, and goes in the alert email. **Replace the key** keeps the connection ID, mappings, queue and history.
5. **Refresh tokens that rotate** (Exact, Fortnox, QuickBooks, Xero, Sage Accounting, Pennylane OAuth). Refresh under a lock so two background tasks never refresh at once; write the new refresh token and the new expiry in one transaction before using the new access token; if the write fails, treat the connection as needing sign-in again (`SYNC-AUTH-002`) rather than risk a lost token.
6. **Idle sites.** Where a refresh token lapses after idle days (Sage Accounting 31, Pipedrive 60, Xero 60, Salesforce 30 from 4 Nov 2026), the plugin makes a harmless call each week while the connection is on, so it never lapses by itself.

## 4. Connector contract additions

Each connector exposes a capability record. Screens and the queue read it; they never assume a feature.

| Capability | Values |
|---|---|
| `connection_method` | `key`, `own_app`, `cloud_app`, `server_login`, `export_file`, `unknown` |
| `order_document` | `sales_order`, `invoice`, `sales_order_and_invoice`, `deal`, `voucher_file` |
| `refund_document` | `credit_note`, `stage_and_note`, `none` |
| `stock_read` | `per_location`, `total`, `none`, `unknown` |
| `tracking_read` | `yes`, `no`, `unknown` |
| `customer_lookup_by_email` | `yes`, `no` (Wafeq, Lexware cannot search by email in a documented way) |
| `product_lookup_by_sku` | `yes`, `no`, `by_external_id_only` |
| `reference_field` | the field that carries the WooCommerce order number, or `none` |
| `native_idempotency` | `none`, or a description (Sage Accounting 7 days, Wafeq 1 hour, Xero header, QuickBooks `requestid`) |
| `webhooks` | `none`, `optional`, `needed` (none is the default; polling is always available) |
| `rate_limit` | a token bucket: requests, window, daily budget, concurrency, `retry_after` supported, quiet windows (Exact 04:00 to 04:30) |
| `requires` | the plan or licence line shown before connecting |
| `transports` | per detected version (Odoo `rpc` and `json2`) |

Rule: **a setting is shown only when the capability exists**. Choose what to sync hides stock, tracking and prices for a system that has none and says why (already drawn for accounting and CRM). A capability marked `unknown` shows nothing on a merchant screen until a connector build confirms it.

## 5. Duplicates and ambiguous outcomes

No researched vendor offers a general idempotency key, so the plugin owns this.

1. A local ledger row per (connection, WooCommerce object, document type) holding the remote ID and a hash of what was sent.
2. The WooCommerce order number goes into the system's `reference_field`. Before creating, the connector searches that field.
3. Where `native_idempotency` exists, the plugin also sends it.
4. A timeout or a 5xx after a create is an **unknown outcome**. The item goes to Retrying; on retry the connector first checks whether the document exists, and only then creates.
5. Where there is no reference field (Lexware Office, Qoyod, Daftra, Pennylane), the plugin writes the order number into a free text field (title, remark, note) and searches by it; the connector states this limit in its capability record.
6. SAP Business One: a user-defined field on sales orders is created by the merchant (shown on the connect screen).

## 6. Throttling and polling

1. **One token bucket per connection**, sized from the capability record. Honour `Retry-After`; where the vendor sends none (Lexware Office, Zoho Books) back off on 429 with a growing wait.
2. **Daily budgets** are tracked per connection: Xero Starter 1,000 calls a day per organisation, Zoho Books 1,000 to 10,000 by plan, Exact 5,000. At 80 percent the plugin slows non-urgent work (stock, reconciliation) and keeps orders first.
3. **Polling is the default** for stock, tracking and document status. Each connection keeps a cursor (modified-since). Webhooks, where they exist and the merchant turns them on, only trigger an earlier poll.
4. **Business Central**: at most 5 requests at once, 600 a minute per environment (D).
5. **Odoo**: about one call a second and none in parallel (Odoo's Acceptable Use Policy), so the queue runs serially and batches where it can.
6. **NetSuite**: integration concurrency is 5 on a Standard account, so run serially there.
7. **MYOB**: 8 requests a second and 1,000,000 a day per key; an over-limit answer is **403 RateLimitError, not 429**, so the throttle must recognise it.
8. **Acumatica**: 50 to 300 requests a minute by edition, with parallelism 1 to 8; **Holded**: 100 a minute.

## 7. The sign-in relay

### 7.1 What it is
An optional service run by Estasoft that holds one registered OAuth app per vendor and does the sign-in step for merchants, so Connect is one click. It would change the `own_app` systems only.

### 7.2 Decision for the first release: no relay
Reasons, from the research:

1. **Estasoft would become the vendor's counterparty for every merchant.** Intuit needs an app assessment (D). Exact needs a review and an app per country (3P). Fortnox needs review and an App Partner agreement with a 75 percent revenue share (D). Pipedrive public apps need approval (up to 21 business days) (D). HubSpot listing needs 3 installs and certification needs 60 (D). Pennylane OAuth is granted at its discretion and the secret must not be shared (D). Salesforce says consumer credentials must not be packaged (D).
2. **Cost grows with merchants.** Xero Starter allows 5 connections; Core is AUD 35 a month for 50, and Plus is AUD 245 a month for 1,000 and needs App Certification (D). A free plugin would put Estasoft's bill on every install.
3. **Secrets and liability.** A relay holds client secrets and sees tokens in transit. A breach affects every merchant's accounting system.
4. **WordPress.org.** Allowed in principle: a plugin that is an interface to an external service is permitted, and the service must be documented in the readme with terms of use and privacy policy; the plugin may not contact outside servers without the user's consent (guidelines 6 and 7, review checklist). So a relay is not forbidden, but it adds review risk and a permanent disclosure and support duty. The own-app route needs none of it, because the merchant is configuring their own service.
5. **The own-app route already works for every `own_app` system**, at the price of a few minutes of setup (drawn in B03b with step by step help).

### 7.3 What is ready now
1. The connection layer has a `ConnectionBroker` interface with two methods, `begin(connection)` and `complete(connection, response)`, returning tokens. The default implementation `OwnAppBroker` uses the merchant's Client ID, secret and redirect address.
2. A connector never calls a vendor's token endpoint itself; it asks the broker. A relay is therefore a second broker, added later, and no connector changes.
3. The connect screens already separate "create your own app" (step 1) from "sign in" (step 2). A relay replaces step 1 with one button.
4. The settings schema reserves `broker = own_app | estasoft_relay` (only `own_app` accepted in the first release).

### 7.4 If it is built later (design)
1. **Stateless.** The service exchanges the authorisation code and returns the tokens to the merchant's own site once, over HTTPS, to a REST address on that site, verified by a signed `state` and a PKCE challenge created by the site. It stores no tokens and no merchant data. After that, the site refreshes tokens itself (the service holds a secret, so refresh also passes through it for vendors that need the secret on refresh; the service never keeps the result).
2. **No logging** of codes, tokens or secrets. Request logs carry only a time, the vendor and a result.
3. **Open source** server code, so merchants and reviewers can read it, with a published privacy statement and terms.
4. **Consent.** Off by default. Turning it on shows what leaves the site and why, with links, and needs an explicit tick. Own-app stays available, so nothing is locked behind the service.
5. **No code delivery.** The service returns tokens only, never code or updates (guideline 8).
6. **Readme**: an "External services" section naming the service, what is sent (the sign-in response), when, and links to its terms and privacy policy.
7. **Per-vendor feasibility** before any build: QuickBooks, Xero, Zoho, Sage Accounting and Salesforce are the ones a relay would help most. Pennylane, Exact and Fortnox would need agreements first.

### 7.5 When to revisit
After the first release, if support requests show that creating an own app is where merchants give up (count how many reach "Sign in" versus how many open step 1), and the vendor agreements and monthly costs above are acceptable.

## 8. Export files (Sage 50, TallyPrime, DATEV)

### 8.1 Rule
Everything happens inside WordPress. The plugin writes a file, the merchant downloads it and imports it in the desktop program. No helper program, no outside server, no upload to Estasoft.

### 8.2 Behaviour
1. A connection with `connection_method = export_file` has an **Exports** screen (board D06) instead of Test connection.
2. **Create export** takes every queued item of the connection (or a date range, or one order) and writes one file in the system's format. The items become **Exported**. This reuses the Synced state with the word Exported, because the plugin never learns whether the desktop program accepted the file.
3. Orders that cannot be written (a payment method with no ledger, a code with no mapping) are left out and stay Queued, with `SYNC-EXP-002` naming the cause.
4. An order already in an earlier file triggers a warning before it goes into a second one. Importing the same file twice can create vouchers twice; the plugin says so on the screen.
5. A second "masters list" file names the ledgers, tax codes and stock items the file uses, so the merchant can check that they exist in the desktop program before importing.
6. Refunds become credit notes in the same file where the format supports them.
7. Files are made on request. An optional daily schedule makes a file in the background; it never sends it anywhere.

### 8.3 Where files live (WordPress rules)
1. In a subfolder of the uploads folder (`wp_upload_dir()`), never in the plugin folder, because WordPress deletes the plugin folder on update.
2. Random 32 character names; a closed `index.php`; an `.htaccess` and `web.config` that deny direct access.
3. Nginx and some hosts ignore those rules, so the plugin never links to the file. Downloads go through an admin action that checks the **Manage sync** capability and a nonce, streams the file, and writes an audit entry.
4. A self-test on first use tries to fetch a test file by its web address and warns if it is publicly readable.
5. Written with WordPress's filesystem functions; failure to write is `SYNC-EXP-001`.
6. Retention default 30 days (a setting), removed by a daily Action Scheduler task. A **Delete all export files now** button exists.
7. **Personal data.** A file holds names, addresses and phone numbers. The plugin registers with WordPress's personal data export and erase tools: on an erase request it lists the export files that hold that customer and deletes them (the whole file, since the file is a snapshot).
8. Uninstall removes the folder and its files.

### 8.4 Formats (from the research document, section 11)
| System | File | Key facts |
|---|---|---|
| TallyPrime | **XML** (works in every release; JSON needs 7.0 and its voucher payload was not readable) | Voucher `VCHTYPE="Sales" ACTION="Create"`, invoice view, party ledger, one inventory entry per stock item, an explicit **round-off line** because debits and credits must match. Ledgers and stock items must already exist ("DESC not found"). Duplicates: import each file once; Tally's setting on the same ID decides overwrite or duplicate; the plugin writes a stable per-order ID and keeps an export log. Import: Alt+O, Transactions. |
| DATEV | DATEV-format CSV, Buchungsstapel (`EXTF`, format 700) | Semicolons, decimal comma, **Windows-1252**, Belegdatum `TTMM`, Belegfeld 1 (order number) always filled, one posting per tax rate, S for a sale and H for a refund, one fiscal year per file, no foreign-currency orders. **Column count unresolved** (125 or 121 in current versions; 116 in older ones), so confirm against a real import. The merchant must never open or save the file in Excel. The adviser imports through Bestand, Importieren, Stapelverarbeitung. |
| Sage 50 UK | CSV in the Audit Trail Transactions layout (grouped import) | Types SI and SC; columns Type, Account Reference, Nominal A/C, Department, Date, Reference, Details, Net, Tax Code, Tax, Exchange Rate (1), Ex.Ref, User Name (version 12 and later). Posts to the audit trail only, not to the Invoicing module. Account references, nominal codes and tax codes must exist. Third-party sourcing; confirm against a real import. |
| Sage 50 US and Canada | CSV for the Sales Journal | Transaction Number shared by all lines of an order; at most 160 distributions; customers and accounts must exist first; a duplicate invoice number for the same customer is refused (so use the order number). Field list is third-party and low confidence. |

Layouts on the canvas are examples. **Each format must be proved by importing a generated test file into the vendor's own sample company before release.**

## 9. WordPress.org checklist for this addendum

| Rule | What the plugin does |
|---|---|
| Comply with the terms of each third-party service and API (guideline 1) | Each connector records the vendor's terms it depends on; SAP's April 2026 API policy (published APIs only, bulk limits), Intuit's per-app assessment, and Xero's developer terms are noted in the research document |
| Document third-party services in the readme with terms and privacy links (review checklist) | An "External services" readme section lists every vendor API the plugin calls, when and why (only when the merchant has configured that connection), with links |
| No contact with outside servers without consent (guideline 7) | The plugin contacts a vendor only after the merchant enters credentials for it. No telemetry. No relay in the first release |
| No executable code from third parties (guideline 8) | No code or updates are downloaded; connectors ship inside the plugin or as separate WordPress.org plugins |
| Interfaces to external services are allowed (guideline 6) | The connectors are interfaces to the merchant's own services; nothing is locked behind a payment |
| No saving content in the plugin folder | Export files go to the uploads folder; logs and settings go to the database |
| External requirements documented in the readme and the Settings page | Plan and licence requirements are listed per system in the readme and on each connect screen |
| Security | Capability and nonce on every action, encrypted secrets, escaped output, prepared queries, no secret in any response |

(I) The guideline numbers and wording are from a search of the published guidelines and the plugin review checklist, read in the search results, not from the full pages. Re-read the current guidelines before submission.

## 10. New and changed error codes

| Code | Class | Message |
|---|---|---|
| SYNC-AUTH-005 | Needs a person | The key or secret for {system} ran out on {date}. |
| SYNC-AUTH-006 | Needs a person | Your {system} plan does not include outside access. |
| SYNC-AUTH-007 | Needs a person | {system} does not know this app yet. |
| SYNC-EXP-001 | Needs a person | The export file could not be written to the uploads folder. |
| SYNC-EXP-002 | Needs a person | {n} orders were left out of the export: {reason}. |

The catalog is now **30 codes**. Phase 1 spec section on errors and the REST route list must be updated to match.

## 11. System-specific rules found by the second round

1. **Odoo, Saudi Arabia.** The sales journal must be onboarded with ZATCA by hand inside Odoo (OTP from the Fatoora portal, expires in 60 minutes); after the first Production submission there is no way back. The plugin must show a warning before it posts in Production, validate Arabic and partner fields up front, require a ZATCA Reason on credit notes, call `action_process_edi_web_services` after posting (the EDI cron ships inactive), poll the EDI state, and treat Rejected as a retry state.
2. **Odoo, plan.** Odoo Online needs the Custom plan and the API cannot reveal the plan, so a refused call shows `SYNC-AUTH-006` with the plan explanation.
3. **MYOB.** The consent covers one ledger and needs a company-file administrator. The `CompanyFile` scope was removed on 1 Sep 2026; use the granular `sme-*` scopes.
4. **NetSuite.** Tax through REST needs SuiteTax. SOAP and token-based authentication stop accepting new integrations from 2027.1, so only REST with OAuth 2.0 is built. Tracking write through REST is unconfirmed.
5. **Acumatica.** The merchant's administrator must turn on sliding refresh-token expiry with an infinite absolute lifetime, or background sync stops after 30 days. A credit memo entity was not found.
6. **Dynamics 365 Sales.** A free Developer Plan cannot install Dynamics 365 apps, and new developer environments stop from 15 Nov 2026, so the connector is tested on a 30-day trial. A custom column and alternate key for the WooCommerce order number must be installed as a solution.
7. **Sage 300.** The plugin refuses an address that is not https, because Basic sign-in travels with it.
8. **Gulf e-invoicing.** Saudi phase 2 waves continue (wave 25: integrate by 1 Feb 2027); UAE mandatory from 1 Jan 2027 for businesses above AED 50 million. The plugin posts orders and lets the ERP's certified module issue the invoice. It never claims compliance.

## 12. Open

1. Confirm no relay in the first release (section 7).
2. Confirm that Exported, drawn as the Synced icon with the word Exported, is the right wording on the queue and dashboard for file connections.
3. Each export format must be proved by importing a generated file into the vendor's own sample company; the DATEV column count and the Sage 50 layouts are the weakest.
4. Wave order (the research changes the effort ranking).
5. Whether the plugin should tell merchants about SMACC's own WooCommerce connection, or only say that SMACC publishes no API.
6. Does Sage allow a free third-party plugin to use the Sage 300 Web API? Ask Sage before launch.
