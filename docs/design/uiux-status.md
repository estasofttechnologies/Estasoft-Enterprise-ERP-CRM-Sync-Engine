# Estasoft Enterprise ERP/CRM Sync Engine UI/UX: design status (2 Nov 2026, after the vendor research)

Canvas (Design type, light only): https://claude.ai/artifact/3vLXy2LTAteRKE3ZsHLKxh (private until shared from the page's Share menu). **88 boards on 7 pages**. Decisions, identifiers, the palette and the brand mark are in `estasoft-erp-sync-engine-decisions.md`. Behaviour (features, data, rules, REST routes, error catalog, acceptance scenarios, build order) is in `estasoft-erp-sync-engine-phase1-spec.md`. **Four later documents change those two:** `estasoft-erp-sync-engine-scope-update.md` (all 34 systems are official connectors, and what the research changed), `estasoft-erp-sync-engine-review-2-changes.md` (what the full review found, what was added and fixed, spec changes it implies, backlog), `estasoft-erp-sync-engine-integration-research.md` (what each vendor's documentation says about API, sign-in, plan gates, objects, limits and import formats) and `estasoft-erp-sync-engine-connection-methods-spec.md` (connection methods, export mode, the relay decision, the WordPress.org checklist).

Requests (2 Nov 2026): start the UI/UX design; the Estasoft E icon, colours and the name ESTASOFT SYNC; all 34 systems; round 2 (fix bugs, build missed screens); a sand and navy WordPress.org banner with the official name; system logos from files the owner supplied; all systems available; a full review of every screen and feature; then "have you read each vendor's requirements and API?", which led to the research and to the connect screens below; the owner then chose export files for the desktop systems, left the relay decision to me, and asked for a second research round, which is done.

## Direction

- Every plugin screen sits inside the WordPress admin, under WooCommerce > ESTASOFT SYNC, in WordPress's default admin colours around the plugin's own Sand and navy screens (primary #12355B, canvas #F5F0E6, card #FFFDF8; copper #A24A20 decoration only).
- **Official name:** Estasoft Enterprise ERP/CRM Sync Engine, always in full on WordPress.org, in the plugin header and readme, on the Plugins screen, in the banner and in release notes. **ESTASOFT SYNC** is the short label inside the plugin's own screens, the WooCommerce menu and the admin bar.
- Brand mark: the Estasoft E from the owner's PDF, recoloured navy and white.
- **34 systems, all Available**, by type (Accounting 17, ERP 11, CRM 6), with filters, search, a country suggestion row and a Select button on each. **Each row now says how you connect and what you need first.** Build order is kept as internal waves on board Z07 (out of date as an effort ranking). **Other systems** (D03): ask for a system that is not listed, or build a connector.
- **Ways a system connects** (from the research): paste a key; your own app, then sign in; a cloud app from your administrators; a server sign-in; **an export file** for TallyPrime, DATEV and Sage 50 (decided: written inside WordPress, no helper program); no public API yet (SMACC, listed but cannot be selected); and to be confirmed (Sage X3). No sign-in relay in the first release (decided).
- Six sync states everywhere, each an icon and a word. Skipped is a neutral grey tag, not a seventh state. Paused belongs to a connection, not to an item.
- One sample world (Cedar & Stone Supply, an invented Dubai shop, Odoo, Mon 2 Nov 2026 11:40): 333 queue items today = 312 synced, 14 waiting (9 queued, 2 syncing, 3 retrying), 5 failed, 2 skipped. The paused scenario (A04, the first email) is a later moment, 12:05, with Odoo silent since 11:48. All boards use the same orders, names and amounts.
- Static boards, English only, light only.

## What the connect screens show

- **B03 Odoo (paste a key):** now warns that Odoo Online needs the Custom plan, that the key has an end date of at most 3 months, and that the user field is only needed for Odoo 17 and 18.
- **B03b Xero (own app, then sign in):** create an app in your own Xero account, paste its Client ID and secret, then sign in; includes the free-plan limit (5 organisations, 1,000 calls a day) and a table of the ways to connect.
- **B03c Wafeq (paste a key):** the plan check, the key, test results, plan-refused and key-refused; what Wafeq cannot do (no stock, no tracking, no customer search by email).
- **B03d Business Central (cloud app):** two administrators, three parts, secret end-date warning, app-not-enabled error.
- **B03e SAP Business One (server sign-in):** reachability, certificate upload, a test that shows each step, and what happens when the server cannot be reached.
- **B03f Export file (TallyPrime, DATEV, Sage 50):** settings, ledger names, how each system's file is imported, and what each file needs from you; includes the warning never to save a DATEV file in Excel.
- **D06 Exports:** create an export, history, how to import, how orders appear in the queue (Queued, then Exported, never Synced), failures, empty state, and where files are kept (uploads folder, random names, capability and nonce checked, 30 day retention, personal data tools).
- **B03 Odoo** now also says what to expect: about one call a second, the Delivery module for tracking, ZATCA onboarding by hand in Saudi Arabia, and two connection methods by version.
- **D01** shows an expiring-key warning with Replace the key.
- **S05** lists, for every system, how it connects, what an order becomes, and whether stock, tracking and refunds are documented. "Not confirmed" means the documentation could not be read.

## System logos

- From files the owner supplied (vendors' own artwork), made into square 160 px PNG tiles at 24, 28, 36 or 40 px. Systems without a logo show a letter tile.
- **23 of 34 have a logo.** **11 still show a letter tile:** Qoyod, Wafeq, Daftra, TallyPrime, Lexware Office, sevDesk, Pennylane, Dynamics 365 Finance and Supply Chain, Dynamics 365 Sales, Acumatica, Holded. Ten more files were supplied but their vendors were not clear from the artwork, so none is used yet (owner to name them).
- Before release, check each vendor's brand terms. WordPress.org reviewers look closely at third-party trademarks; the safe fallback is the system's name in text.

## Boards (88)

- **Overview (13):** Main, S01 screen map, S02 sync states, S03 sample data, S04 connector capabilities by type, **S05 capabilities by system**, Z01 colours and accessibility, Z02 handoff, Z03 components, Z04 error catalog (**30 codes**), Z05 data and API map with limits, Z06 queue state transitions, Z07 system logos and build order.
- **Setup (13):** B01 to B06 with B03b, **B03c, B03d, B03e, B03f** (export file), B04b, B04c. B01 has a blocked variant; B03 has testing, partial, refused and other results; B05 has a problem variant.
- **Daily use (21):** A01 to A09 (A05 is five widget states, A06 Screen Options, A06b Help tab, A07 notices, A08 loading, A09 shadow mode), C01 to C05 and C07 (C04 is the row menu, result messages and dialogs), H01 invoices, H02 order screen, H02b order box in eight states, H03 orders list, H04 product screen (three states).
- **Configure (19):** D01 to D06 (D03 is Other systems, **D06 is Exports**), E01 to E05, E06 mapping: stock and tracking, E07, E08, F01 to F03, G01, G02.
- **Tools and settings (11):** I01 to I05, I06 access, I06b tools, I07 log entry, I08 notifications, I09 alert emails (four), I10 tools dialogs.
- **Responsive (10):** R01 to R09 and R08b.
- **WordPress.org (1):** W01 icon at five sizes, both banners, names, files, screenshots. PNG exports: icon 256 and 128 px, banner 1544 by 500 and 772 by 250 px.

Board ID C06 is not used. I06 is Access only and I06b is Tools; D05 holds the connection dialogs.

## Checked

- Markup on every board. Every board rendered in Chromium at its declared width: no sideways overflow outside designed table scrolling, no text under 12 px, no touch control under 44 px at 782 px and below, no text in its own background colour, and no WCAG 2.1 A or AA violation from axe-core. Heights match content. The new connect boards, Choose a system and S05 were also looked at as images.
- Vendor facts: fourteen research tasks in two rounds read official documentation where reachable. Each fact in the research document is tagged documented, third-party or inferred.

## Not checked

- Not run in a real WordPress admin, in each admin colour scheme, on a real phone or tablet, with a screen reader, in high-contrast mode, or at 200 percent zoom. Boards are static.
- **Vendor research is broad but not final:** every system has now been researched, but many object-level details are third-party or inferred (Acumatica, Sage 300, DATEV columns, Sage 50 layouts, Exact, sevDesk). SMACC has no public API. Do not build a connector from the canvas alone; use the research document and confirm against the vendor's current documentation.
- **Export formats are not proved:** each must be tested by importing a generated file into the vendor's own sample company.
- Connect screens other than Odoo, Xero, Wafeq, Business Central, SAP Business One and the export file (TallyPrime example) are not drawn; each way of connecting is shown once with one example.
- The new error codes AUTH-005 to AUTH-007 and EXP-001 and EXP-002 are proposals. The wording Exported for file connections is an open design question.
- Tax rules and dates are examples; verify before text like it ships. Logo ownership was judged from the artwork. Arabic strings have not been reviewed by a native reader.

## Backlog from the review

See `estasoft-erp-sync-engine-review-2-changes.md`: import of past orders at go-live, permission-denied and error screens, a sticky action bar and a tablet card layout, a trust warning on Other systems, a few empty and failed states, chart descriptions. Added by the research: connect screens for the remaining systems, an expiry state in the connection detail (D02), a "plan does not include API" state on every key connection, and a capability-driven What to sync for systems without stock or tracking.

## Open (owner to decide)

1. Confirm no relay in the first release (my decision; the owner can override).
2. Prove each export format with a real import; ask Sage about Sage 300 Web API use by a free plugin.
3. Name the 10 unplaced logo files.
4. Wave order for the 34 connectors (the research changes the effort ranking), and "Coming soon" versus hiding systems not yet built.
5. Whether to mention SMACC's own WooCommerce connection to merchants.
6. Import of existing orders at go-live; shadow mode also holding back stock and tracking; a refused sign-in pausing the connection (review doc).
7. Primary buttons: fixed navy or following each user's WordPress admin colour scheme.
8. Is the Invoices tab part of the first release?
9. Names: Shadow mode, Needs attention.
10. The menu item reads ESTASOFT SYNC in capitals; keep, or "Estasoft Sync" in the WordPress menu only.
11. Brand mark: rounded corners or the square exactly as in the PDF.
12. Admin built with React or PHP list tables.
13. Two-capability split, nine-table data model and the number ranges are proposals.
14. Alert email subjects say ESTASOFT SYNC; use the full official name there?
