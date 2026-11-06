# Estasoft Enterprise ERP/CRM Sync Engine: decisions and ideas

Last updated 2026-11-02 (round 2). WordPress/WooCommerce plugin that connects a WooCommerce store to ERP and CRM systems (NetSuite, Salesforce, Odoo, SAP Business One, Dynamics 365, Sage and others) through an asynchronous, fault-tolerant queue, so checkout never waits on an external API.

Related docs: `docs/design/uiux-status.md` (the 79-board canvas) and `docs/spec/phase1-spec.md` (features, data model, state machine, REST routes, hooks, connector contract, error catalog, acceptance scenarios, build order).

## Decided (owner, 2 Nov 2026)

- **Name:** Estasoft Enterprise ERP/CRM Sync Engine.
- **Name shown inside the plugin screens:** ESTASOFT SYNC (app bar, WooCommerce menu item, admin bar, widget, order screen box). The full name above stays the plugin's official name.
- **Brand mark:** the Estasoft E from the owner's PDF (`estasoft_1.pdf`), recoloured to the plugin's navy and white. Details below.
- **All features are free.** No licenses, license keys, tiers, trials or paid version. LicenseDesk is not used for this plugin.
- **Language:** English only. Strings stay translatable so volunteers can add languages later.
- **Markets:** Gulf and Middle East, plus USA, EU, UK and Australia.
- **Colors:** Sand and navy (picked 2 Nov 2026 from six options). Values and rules below.
- **Systems to show:** all 34 candidates from the 2 Nov 2026 list are on the Choose a system screen, with the statuses in the catalog below. The statuses are my proposal and still need the owner's confirmation.
- **Starting point:** the owner's concept brief: non-blocking queue (Action Scheduler or Redis), visual schema mapping and transformation, bi-directional order, customer, stock and tracking sync, dead-letter queue with exponential backoff and a manual retry screen, dedicated custom tables, connectors for NetSuite, Salesforce, Odoo and SAP Business One.
- **Dropped because of the free decision:** price bands, plan tiers, Freemius or merchant-of-record, the payment-from-Iraq question, a hosted paid relay.

## Round 2 additions (2 Nov 2026, proposals from the spec; owner to confirm)

- **Phase 1 scope:** the engine, the connector contract, every admin screen, and one official connector: Odoo. Sign-in, accounting and CRM connectors (the 7 Coming next) are Phase 2; boards B03b, B04b and B04c are labelled Phase 2.
- **No telemetry code in Phase 1.** The usage-data toggle was removed from Settings. The plugin talks only to the connected system, after the person sets it up. (Replaces open item 7.)
- **Two capabilities, not one:** `estasoft_sync_manage` (administrator and shop manager: view, retry, skip, pause, resume, sync now, run reconciliation) and `estasoft_sync_configure` (administrator: connections, mapping, tax, stock rules, settings, tools). The saved API key is never returned to anyone.
- **Nine tables, not seven:** the earlier seven plus `estasoft_sync_recon_runs` and `estasoft_sync_recon_diffs`, so nightly check results persist.
- **Twenty error codes** (SYNC-AUTH, NET, RATE, MAP, VAL, DUP, STATE, CFG, PAUSE, RECON, COMP, plus SYNC-LOCK-001, SYNC-STK-001 and SYNC-INB-001 added in round 2). Full text on board Z04 and in spec section 11.
- **Skipped** is a terminal queue state shown as a neutral tag, not a seventh sync-state chip.
- **Notifications** by `wp_mail` only: connection paused (once per incident), daily failed-orders list, queue backlog, sign-in expired. No outside email service.
- **Queue state machine, retry schedule (1 min, 5 min, 1 h, 24 h plus up to 20 s jitter), circuit breaker (5 failures, probe every 2 min), rate limit (4 per second, 2 at once)** are written in spec section 6. They are defaults, not measured.

## Brand mark (2 Nov 2026)

- Source: the owner's PDF, a square with the letter E and a curved middle arm. Original colours: yellow background #FED431 and charcoal E #1C1C1C (read from the file; this is also the exact Estasoft yellow, which the earlier palette option had only as a placeholder).
- In the plugin: the same E shape, the yellow replaced by the plugin navy #12355B and the charcoal by white #FFFFFF, the colours of the previous icon. Drawn as a rounded square (corner radius about 22% of the side), shown at 24 px in the app bar next to the words ESTASOFT SYNC.
- The shape was taken from the PDF's vector path, not redrawn by hand. The original is a plain square; the rounded corners were added to match the previous icon and are easy to remove.
- The previous icon (two arrows and a copper dot) is retired.
- WordPress.org icon (256 and 128 px) and banner (1544 by 500 and 772 by 250 px) were exported as PNG in round 2 from board W01.

## Colors: Sand and navy (picked 2 Nov 2026)

Warm neutrals with a deep navy primary and one copper accent. Light only (see Open).

| Role | Hex | Use |
| --- | --- | --- |
| Primary | #12355B | The one filled button per view, links, active tab, brand mark |
| Primary hover | #0B2540 | Hover and pressed |
| Selected tint | #E7EEF6 | Selected row, Syncing chip background |
| Page canvas | #F5F0E6 | Behind cards |
| Card | #FFFDF8 | Cards, tables, dialogs |
| Control border | #8A8070 | Inputs, outline buttons |
| Divider | #E6DCC8 | Hairlines, table rows |
| Text | #1F2430 | Body text |
| Secondary text | #5A5346 | Labels, helper text |
| Accent (copper) | #A24A20 | Decoration only, never status. No longer used in the brand mark. |

Sync states are the same in every palette and always show an icon and a word, never color alone:

| State | Icon | Text | Background |
| --- | --- | --- | --- |
| Queued | clock | #4B5563 | #EEF0F3 |
| Syncing | refresh | #12355B | #E7EEF6 |
| Synced | check | #1F6B38 | #E6F4EA |
| Retrying | alert-triangle | #8A5200 | #FDF1DC |
| Failed | x | #A32424 | #FBE9E9 |
| Paused (circuit open) | player-pause | #374151 | #E5E7EB |

Contrast, computed 2 Nov 2026 (text needs 4.5:1, control borders 3:1):

| Pair | Ratio |
| --- | --- |
| White on primary | 12.5:1 |
| Primary on card | 12.3:1 |
| Primary on selected tint (Syncing chip) | 10.7:1 |
| Text on card | 15.3:1 |
| Secondary text on card | 7.5:1 |
| Secondary text on canvas | 6.7:1 |
| Control border on card | 3.8:1 |
| Copper #A24A20 on canvas | 5.2:1 |
| Status chips (Synced, Retrying, Failed, Queued, Paused) | 5.7:1 or better |

Notes:
- The first copper tried, #B4572B, was only 4.3:1 on the canvas, so it was darkened to #A24A20.
- Round 2 rendered every board in Chromium and ran axe-core (WCAG 2.1 A and AA): no violations reported. Not yet checked: the palette on a real WordPress admin screen, in each admin color scheme, with a screen reader, or in high-contrast mode.
- Suggested CSS custom properties: `--estasoft-sync-primary`, `-primary-hover`, `-tint`, `-canvas`, `-card`, `-border`, `-divider`, `-text`, `-text-muted`, `-accent`, plus `-state-{queued,syncing,synced,retrying,failed,paused}-{fg,bg}`.

## Name and identifiers (proposed; slug not yet checked or reserved on WordPress.org)

| Use | Value |
| --- | --- |
| Full name | Estasoft Enterprise ERP/CRM Sync Engine |
| Admin menu label | ESTASOFT SYNC (under WooCommerce) |
| Slug, folder, text domain | estasoft-erp-crm-sync-engine |
| Main file | estasoft-erp-crm-sync-engine.php |
| PHP namespace | Estasoft\SyncEngine |
| Prefix | estasoft_sync_ ; constants ESTASOFT_SYNC_ |
| Database tables (9) | {prefix}estasoft_sync_queue (also the outbox; dead-letter is a status), _logs, _mappings (versioned), _id_map, _state, _idempotency, _connections, _recon_runs, _recon_diffs |
| Action Scheduler group | estasoft-sync |
| Hooks | estasoft_sync_* |
| REST namespace | estasoft-sync/v1 |
| WP-CLI | wp estasoft-sync |
| CSS prefix | estasoft-sync- |
| Capabilities | estasoft_sync_manage (administrator and shop manager), estasoft_sync_configure (administrator) |
| Community connector add-ons | estasoft-sync-connector-{system} |
| License | GPLv2 or later |
| Requirements (proposed, confirm) | WordPress 6.4+, WooCommerce 8.2+ (HPOS first), PHP 8.1+; declare HPOS compatibility |

The brief used generic table names (wp_erp_sync_queue and so on). They are renamed with the estasoft_ prefix so they cannot collide with another plugin.

## Distribution (proposed)

WordPress.org plus a public GitHub repository. WordPress.org requires GPL-compatible code, forbids features locked behind payment, trials or quotas, serves updates only from WordPress.org, and forbids contacting external servers without explicit consent. An all-free plugin fits. No telemetry in Phase 1.

The slug is chosen at submission. As far as is known it cannot be changed after approval; confirm with the plugins team before submitting.

## Connector catalog (34 systems, proposed statuses, 2 Nov 2026)

Status words on the Choose a system screen: **Included** works in the first release. **Coming next** and **Planned** are official connectors we build and maintain (next, then later). **Community** means nobody has built it and any developer can, through the connector contract.

Systems are grouped by type, because the type decides what can sync: ERP (orders, customers, refunds, stock, tracking), accounting (orders as invoices, customers, refunds as credit notes, stock only if the system tracks it), CRM (customers, orders as deals). The Choose what to sync screen shows only what the type supports and says why the rest is hidden.

| Status | Count | Systems |
| --- | --- | --- |
| Included | 1 | Odoo |
| Coming next | 7 | Microsoft Dynamics 365 Business Central, Zoho Books, ERPNext, QuickBooks Online, Xero, HubSpot, Zoho CRM |
| Planned | 6 | NetSuite, SAP Business One, Salesforce, MYOB, Sage Business Cloud Accounting and Sage 50, Dynamics 365 Sales |
| Community | 20 | Accounting: Qoyod, Wafeq, Daftra, SMACC, TallyPrime, DATEV, Lexware Office, sevDesk, Exact Online, Moneybird, Pennylane, Fortnox. ERP: SAP S/4HANA, Oracle Fusion Cloud ERP, Dynamics 365 Finance and Supply Chain, Sage 200, 300 and X3, Acumatica, Holded. CRM: Pipedrive, SuiteCRM |

By type: 11 ERP and business suites, 17 accounting, 6 CRM. 17 of the 34 are known in the Gulf (UAE or Saudi Arabia).

Why these, from the 2 Nov 2026 research (rough signals from vendor, blog and website-detection pages):
- QuickBooks Online and Xero: free developer sandboxes (an Intuit sandbox company with Australia and UK regions; a Xero demo company in the developer portal). QuickBooks is dominant in the US; Xero is claimed above 60% of Australian small businesses; both are popular with UAE businesses.
- HubSpot: the usual default CRM for small and mid-size shops, more popular in the US, UK and Germany. Zoho CRM is more popular in India, the UAE and Israel and pairs with Zoho Books.
- ERPNext: open source, popular with cost-conscious UAE businesses, free to test.
- Germany is accountant-driven (DATEV, Lexware Office, sevDesk), so those are left to the community. Gulf-local systems (Qoyod, Wafeq, Daftra, SMACC, TallyPrime) are community until their APIs are checked.
- A cap of about 8 official connectors in year one (the first 8 above) keeps support work manageable, because the plugin is free.

Connection style, as intended: Odoo and ERPNext use an address plus an API key or user. QuickBooks Online, Xero, Zoho Books, Zoho CRM, HubSpot, Salesforce and Dynamics 365 use a sign-in page. Each connector confirms this when built.

A public connector contract (adapter interface plus contract test suite) lets others build and maintain Community connectors, named `estasoft-sync-connector-{system}`.

## Ideas

**Reliability is the product**
- Transactional outbox: write the sync event in the same database transaction as the order.
- Idempotency keys so a retry never creates a duplicate ERP order.
- Nightly reconciliation: compare WooCommerce with the ERP for a window, flag missing or different records, offer one-click repair.
- Ordering per order: created, paid and refunded for one order process in sequence (partition by order ID).
- Error classes: transient (timeouts, 429, 5xx), permanent (unmapped SKU, validation), needs a human. Permanent errors do not burn retries. Bulk replay after a fix.
- Circuit breaker per connector, rate limiting, respect Retry-After, jitter on the 1 min / 5 min / 1 h / 24 h schedule.
- Published load-test harness (for example k6) with server spec and method. Do not claim "under 500 ms checkout" or "thousands per minute" without it.

**Architecture**
- Canonical order model, then one small adapter per system.
- Runtime profiles as settings, not tiers: Action Scheduler by default; WP-CLI worker plus optional Redis for high volume.
- Read and write orders through the WooCommerce CRUD layer, never raw SQL on HPOS tables.
- Credentials encrypted with libsodium; the key comes from the WordPress security keys or from a constant in wp-config.php, never from the database.
- Log table: truncate payloads, redact personal data, prune on a schedule.
- Never forward raw payment tokens to an ERP: send transaction ID and last four digits to stay out of PCI scope.

**Mapping**
- Connector presets, a small expression language for transforms, versioned mappings with rollback.
- Preview with a real order before enabling; dry-run and shadow mode that compares output with what the ERP recorded.
- Visual drag-and-drop mapper later.

**Compliance-aware, not a compliance engine**
- The ERP or an accredited provider signs and clears invoices. The plugin does not.
- The plugin sends the fields invoices need (buyer VAT or TRN, tax category, exemption reason) and writes back invoice number, QR payload, clearance status and PDF link to the order.
- Clearance failures appear as failed syncs. For Saudi B2C, show a countdown to the 24-hour reporting limit.
- Keep order-to-ERP latency low so the ERP has most of the window.

**Data correctness across regions**
- utf8mb4 end to end for Arabic names and addresses; optional transliteration fallback where an ERP needs Latin names. Arabic invoices come from the ERP.
- Money as integer minor units with a per-currency exponent (KWD, BHD, OMR have three decimals); rounding tolerance in reconciliation.
- Tax mapping per country: US state sales tax, EU and UK VAT, Australian GST, Gulf VAT (Saudi Arabia 15%, UAE 5%).
- Per-country required-field defaults (many Gulf addresses lack postcodes or states); E.164 phone numbers.
- Store UTC. The Gulf has no daylight saving; the US, EU and Australia do.
- Cash on delivery preset: post the ERP payment on delivery, not at checkout. Believed common in the region; confirm with merchants.

**Keep support load low (no revenue to pay for it)**
- "Copy support report" button with redaction.
- Built-in Readiness Check: duplicate or missing SKUs, HPOS compatibility, Action Scheduler backlog, WP-Cron versus real cron, country and state codes, currency decimals.
- Public docs, issue templates, automated tests, capacity guide.
- If income is wanted later without licenses: paid implementation and custom-connector work through Estasoft, or sponsorship.

## Evidence and what is not verified

- **Free plugins on WordPress.org:** no plugin with this exact name was found. The closest is "Connect WooCommerce Shop to ERP/CRM, Verifactu and EU/VAT Compliance" (10 ratings). In one keyword list for "erp", the largest sync plugin was Holded with about 3K installs, JTL-Connector about 1K, most others 100 or fewer. CommerceBird (Zoho, Exact Online) has 256 ratings. The space looks thin, but these are search snippets, not a full survey of the directory.
- **ERP share:** Saudi Arabia, Dynamics 365 62.4% and Odoo 30.6%, from website detection that covers about 2.2% of Saudi sites. A rough signal only. Interview 5 to 10 merchants and implementation partners before committing.
- **CRM and accounting share:** from vendor and blog pages (Salesforce 20.7% of the CRM market per IDC as quoted by a blog; Xero over 60% of Australian small businesses per a blog). Rough signals only.
- **ZATCA Phase 2:** Wave 24 covers VAT-registered businesses above SAR 375,000 by 30 June 2026; B2C simplified invoices are reported within 24 hours. From a tax-vendor summary. Verify on zatca.gov.sa before putting it in the docs.
- **UAE e-invoicing:** mandatory for businesses with revenue of AED 50m or more from 1 January 2027 and for others from 1 July 2027; B2B and B2G only for now; Peppol PINT AE. From tax-vendor summaries. Verify with the Federal Tax Authority and Ministry of Finance.
- Not checked: whether each system's API supports what the capability table promises; API access and sandbox cost for Dynamics 365 Business Central, Zoho Books, Zoho CRM, ERPNext, HubSpot, NetSuite, Salesforce and SAP Business One; the APIs of the Gulf-local systems; WooCommerce trademark rules for the plugin name; whether the slug is free on WordPress.org; the WooCommerce, WordPress and Odoo function and hook names in the spec.

## Open (owner to decide)

1. Confirm the slug, and check the name against WordPress.org and trademarks before submission.
2. Confirm the status of each of the 34 systems, especially the 7 marked Coming next, which would be the official set built after Odoo.
3. Public GitHub repository, and its name.
4. Minimum versions (WordPress, WooCommerce, PHP).
5. Support channel: WordPress.org forum, GitHub issues, or both.
6. Test-environment access for each official connector.
7. Telemetry: none in Phase 1 (proposed in the spec). Confirm, and decide later whether an opt-in is ever wanted.
8. Colors: light only is assumed (no dark mode planned). Confirm, and decide whether primary buttons stay fixed navy or may follow each user's WordPress admin color scheme (fixed navy is the default).
9. The WooCommerce menu item reads ESTASOFT SYNC in capitals, as written. WordPress menus are usually mixed case, so decide whether to keep the capitals or use "Estasoft Sync" in the menu only.
10. Brand mark: keep the rounded corners added to the PDF's square, or use the square exactly as supplied.
11. The two-capability model and the nine-table data model (spec sections 5 and 7.1) are proposals; confirm.
12. Admin built with React for Queue, Logs and Mapping, or PHP throughout; and who owns the OAuth application for Phase 2 sign-in connectors (spec open questions 1 and 2).
