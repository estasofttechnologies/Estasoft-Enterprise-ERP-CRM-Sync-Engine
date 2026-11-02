# Scope update: all 34 systems are official connectors (2 Nov 2026), and what the vendor research changed

**Decision (owner, 2 Nov 2026):** every one of the 34 systems on the Choose a system screen is made available, and Estasoft intends to integrate with all of them. The earlier statuses Coming next, Planned and Community are retired.

**Updates, later on 2 Nov 2026:** the owner answered the three open questions (export files; relay left to me; second research round, now done).

**Earlier update:** the owner asked whether each vendor's requirements and API had actually been read. They had not. Nine research tasks then read what could be reached; the results are in `estasoft-erp-sync-engine-integration-research.md`. The section "What the research changes" below says what that does to this scope.

## What changed on the canvas (first part)

- Choose a system (B02, R05): all 34 rows show a Select button; the Status column and status badges are gone, because every row would say the same thing. The notice reads that all 34 systems are official connectors and every feature is free.
- D03 is **Other systems**: ask for a system that is not among the 34 (opens a request on GitHub), how to build a connector, and installed add-ons from other developers. The word **Community** only labels add-ons built by other developers.
- B03b, B04b and B04c lose their "Phase 2" label. Z02 and Z03 use the new wording.
- Z07 is "System logos and build order": all 34, their logo status, and an internal **build wave** for each.

## Build waves (internal, not shown to merchants)

| Wave | Systems | Includes |
| --- | --- | --- |
| 1 | 1 | Odoo, with the engine. The first release. |
| 2 | 7 | Dynamics 365 Business Central, Zoho Books, ERPNext, QuickBooks Online, Xero, HubSpot, Zoho CRM |
| 3 | 6 | NetSuite, SAP Business One, Salesforce, MYOB, Sage Accounting and Sage 50, Dynamics 365 Sales |
| 4 | 20 | Qoyod, Wafeq, Daftra, SMACC, TallyPrime, DATEV, Lexware Office, sevDesk, Exact Online, Moneybird, Pennylane, Fortnox, SAP S/4HANA, Oracle Fusion Cloud ERP, Dynamics 365 Finance and Supply Chain, Sage 200/300/X3, Acumatica, Holded, Pipedrive, SuiteCRM |

The waves are the order they were first proposed, before the research. **They are now out of date as an effort ranking** (see below) and have no dates.

## Rule that follows

A release should list a system as Available only if that release contains its connector. Showing a system that does not work yet would mislead merchants and may be rejected by WordPress.org. Options: hide systems whose connector is not in the release, or show them as "Coming soon" until they ship. The canvas shows the end state with all 34 Available.

## What the research changes

Full detail, sources and confidence tags are in the research document. The points that change the scope:

1. **No shared sign-in for everyone.** Estasoft runs no server, and most OAuth vendors need a client secret at token exchange (a secret in a GPL plugin is public) and a fixed redirect address (cannot match every merchant's domain). So each merchant connects with their own credentials, in one of five ways: paste a key; create their own app and sign in; a cloud app made by the company's administrators; a server address, user and password; or an export file. The canvas now shows one connect screen per shape (B03, B03b to B03f) and a "how you connect" line for every system on Choose a system.
2. **Three systems cannot be reached from a hosted plugin on its own:** Sage 50 (desktop program, no web API), TallyPrime (local server on the merchant's PC, no authentication) and the DATEV online link (production access gated per integrator by DATEV). **Owner decision: they use export files, written inside WordPress, with no helper program** (B03f, D06; spec addendum section 8). SMACC publishes no API at all, so it is listed but cannot be selected until SMACC opens one.
3. **A second research round** (2 Nov 2026) covered MYOB, Holded, NetSuite, Acumatica, Dynamics 365 Sales, Sage 300, Sage X3, SMACC, Odoo's official sources and the import formats of the export systems. All are now FEASIBLE WITH CAVEATS or better except SMACC (no public API). Still "To be confirmed": Sage X3 (depends on version) and the Sage 300 partner question. Many details remain third-party or inferred; see the research document's tags.
4. **Capabilities differ per system, not per type.** Stock read-back and tracking read-back exist for few systems; Business Central has no per-location stock and no tracking field; Xero, QuickBooks Online, Moneybird, Pennylane, Wafeq and Lexware Office have no stock or tracking to read. S05 lists what each documents. The earlier per-type table (S04) is a default only.
5. **Credentials expire.** Odoo 19 keys last at most 3 months; Pennylane tokens are set to 1, 6 or 12 months; Microsoft client secrets end on a date the merchant picks; several refresh tokens lapse after 31 to 100 days idle. D01 now shows a key-expiry warning; the spec needs an expiry field and a replace-key flow.
6. **Plan gates exist.** Odoo Online needs the Custom plan; Qoyod needs Pro; Wafeq needs Starter; Lexware Office needs Version XL; sevDesk needs Buchhaltung Pro; Pennylane needs Essential. The connect screens say so before asking for a key.
7. **QuickBooks Online does not work in the Gulf** (no tax-authority integration found) and needs Intuit's per-app production assessment even for a private app. Xero's free developer plan allows 1,000 calls a day per organisation.
8. **No vendor offers a general idempotency key** (Sage Accounting 7 days, Wafeq 1 hour, Xero optional header). Duplicate protection lives in the plugin: a local ledger plus search-before-create on each system's reference field.
9. **Wave order is out of date.** Cheapest to build now look like systems that connect with a key and have clear documentation (Wafeq, Lexware Office, Moneybird, Pennylane, Odoo, ERPNext, Pipedrive, HubSpot). Heaviest: SAP, Oracle, Sage 200, Salesforce, SuiteCRM.
10. **Error codes: 25 to 30.** Added SYNC-AUTH-005 (key or secret ran out), SYNC-AUTH-006 (plan does not include outside access), SYNC-AUTH-007 (the ERP does not know this app yet), SYNC-EXP-001 (export file could not be written) and SYNC-EXP-002 (orders left out of an export).
11. **Sign-in relay: decided, no relay in the first release** (my decision at the owner's request to decide; the owner can override). Reasons: each vendor would make Estasoft its counterparty (Intuit assessment, Exact review, Fortnox revenue share, Pipedrive approval, Pennylane discretion, Salesforce forbids packaged credentials); Xero's cost grows with merchants; secrets and liability; WordPress.org allows services but adds disclosure and consent duties. A broker interface is built so a relay can be added later without touching any connector (spec addendum section 7).
12. **Odoo specifics.** Two transports (JSON-RPC for 17 and 18, JSON-2 for 19 and later), about one call a second, Custom plan on Odoo Online, manual irreversible ZATCA onboarding in Saudi Arabia, no UAE e-invoicing module.

## Files

- `estasoft-erp-sync-engine-integration-research.md` (what each vendor documents).
- `estasoft-erp-sync-engine-connection-methods-spec.md` (connection methods, export mode, relay decision, WordPress.org checklist).

## What this supersedes

- `estasoft-erp-sync-engine-decisions.md`, "Connector catalog": the status words and counts (Included 1, Coming next 7, Planned 6, Community 20) are replaced by the waves above, and the waves by the research. The official connector cap of about 8 in year one is dropped, which raises the support load of a free plugin: 34 connectors to build, test and keep working.
- `estasoft-erp-sync-engine-phase1-spec.md`: section 3 (Phases and connector scope), ONB-5 (status words), canvas note 7 (Phase 2 labels), the connector contract (needs capability flags, transport adapters per version, per-connection throttling, token refresh under a lock, polling cursors) and the error catalog (30 codes). CMP-4 becomes the Other systems page.
- `estasoft-erp-sync-engine-uiux-status.md` is updated.

## Open

1. Confirm no relay in the first release.
2. Re-order the waves by effort, or keep them; give dates.
3. "Coming soon" label for unreleased systems, or hide them until they ship.
4. Prove each export format by importing a generated test file into the vendor's own sample company (the DATEV column count and the Sage 50 layouts are the weakest).
5. Ask Sage whether a free third-party plugin may use the Sage 300 Web API.
6. Whether to tell merchants about SMACC's own WooCommerce connection.
7. Whether to keep the contract and test suite public for add-ons from other developers.

## Update, 3 Nov 2026: what the connector build found

- **Every system now has a connector** (34 catalog rows, 36 connector ids because three rows hold several products), built from each vendor's public documentation and tested only against in-repository fakes of the vendors' wire behaviour. None has been run against a live account; the Choose a system screen says so ("Not yet tested with a live account") until a connector is verified with a vendor sandbox.
- **Item 4 above is corrected.** Xero, QuickBooks Online, MYOB, Exact Online and Fortnox do document quantity on hand for tracked items, so those connectors flag stock read-back; Moneybird, Pennylane, Wafeq and Lexware Office still have no stock or tracking to read. Each connector document lists its flags with the reason.
- **Pennylane is listed but cannot be selected** until its production terms are cleared: its own guide says company tokens are for sandbox use and OAuth is needed for production, and its API terms restrict open-source use. **SMACC** stays unavailable (no public API).
- **Sage 200, Sage 300 and Sage X3** are three connectors behind one catalog row (the person picks the product); **Sage Business Cloud Accounting and Sage 50** are one row with two connectors (API and file).
- **Export-file systems** (TallyPrime, DATEV, Sage 50) write their files inside WordPress, protected from direct web access, and nothing is sent to the vendor.
- Sign-in systems use the merchant's own app and a stable callback address (`estasoft-sync/v1/oauth/callback`); there is no relay.
