# Specification and document index

This folder holds the product specification and the documents that amend it. Several documents were written one
after another as the design and the vendor research progressed, so they overlap and sometimes disagree. This page
says which one wins and lists every document in `docs/`.

## Which document wins

When two documents disagree, the one higher in this list is right:

1. **[review-2-changes.md](review-2-changes.md)**, the "Spec changes this implies" list: extra error codes, extra
   REST routes, a refused sign-in pauses the connection, shadow mode changes nothing in either system, number
   ranges, capabilities.
2. **[scope-update.md](scope-update.md)**: all 34 systems are official connectors, and what the vendor research
   changed (connection methods, waves, error codes, no sign-in relay in the first release).
3. **[connection-methods-spec.md](connection-methods-spec.md)**: the five ways a system connects, the order of
   checks for Test connection, credentials, export files for desktop systems, the relay decision, the
   WordPress.org checklist.
4. **[phase1-spec.md](phase1-spec.md)**: the original Phase 1 specification (features, data, rules, REST routes,
   acceptance scenarios, build order). Still the base; the three documents above amend it.

[../ARCHITECTURE.md](../ARCHITECTURE.md) is the contract for the code (layout, seams, core service names, the
mapping rule model, the canonical order model, queue rules). It follows the order above where they touch.
`src/api/types.ts` is the contract for the REST API between the PHP controllers and the React app.

What the later documents replaced, so nobody builds from the old text: the status words Coming next, Planned and
Community and the "about 8 official connectors" cap (decisions), section 3 (phases and connector scope), ONB-5
(status words) and the connector contract and error catalog sections of the Phase 1 spec (33 codes now).

## Documents

| Document | What it is |
| --- | --- |
| [phase1-spec.md](phase1-spec.md) | Phase 1 specification (copy of the project document). |
| [review-2-changes.md](review-2-changes.md) | Findings of the full design review, what was added and fixed, the spec changes it implies, and the backlog. **|
| [scope-update.md](scope-update.md) | All 34 systems are official connectors; what the vendor research changed. |
| [connection-methods-spec.md](connection-methods-spec.md) | Connection methods, credentials, export mode, relay decision, WordPress.org checklist. |
| [../DECISIONS.md](../DECISIONS.md) | Design decisions, identifiers, palette and brand mark. |
| [../ARCHITECTURE.md](../ARCHITECTURE.md) | Code layout, seams, core services, mapping rule model, queue rules, conventions. |
| [../core-services.md](../core-services.md) | Container keys, repositories, queue services, settings, logging, crypto, lifecycle and hooks of the engine core. |
| [../mapping-engine.md](../mapping-engine.md) | The rule model, the fixed transform library, tax and SKU matching, previews and versions. |
| [../jobs.md](../jobs.md) | Background jobs: stock, tracking, invoices, reconciliation, shadow mode, notifications, readiness, support report, settings export and import. |
| [../rest-api.md](../rest-api.md) | Every REST route: capability, parameters, response, error codes. |
| [../cli.md](../cli.md) | The `wp estasoft-sync` commands. |
| [../admin-app.md](../admin-app.md) | The React admin app: structure, mock development build, how to add a screen. |
| [../connectors/README.md](../connectors/README.md) | Index of the connector documents (generated from their front matter by `bin/generate-catalog-from-docs.php`). |
| [../connectors/BUILDING.md](../connectors/BUILDING.md) | How to build a connector on the shared base: provider, meta, API client, OAuth, exports, tests, the document, the catalog. |
| [../connectors/odoo.md](../connectors/odoo.md) | The Odoo connector: verified API facts with sources, transports, field mapping, smoke test. |
| [../error-catalog.md](../error-catalog.md) | The thirty-three `SYNC-...` error codes: class, message, what the person does, retries. |
| [../design/uiux-status.md](../design/uiux-status.md) | State of the UI/UX design: direction, boards, what was checked and what was not, open decisions. |
| [../research/integration-research.md](../research/integration-research.md) | What each vendor documents: API, sign-in, plan gates, objects, limits. Facts are tagged documented, third-party or inferred. |
| [../research/integration-research-vendors.md](../research/integration-research-vendors.md) | Per-vendor research notes, first part. |
| [../research/integration-research-vendors-2.md](../research/integration-research-vendors-2.md) | Per-vendor research notes, second round. |
| [../research/integration-research-export-formats.md](../research/integration-research-export-formats.md) | Import formats of the systems that connect through export files. |
| [../release/CHECKLIST.md](../release/CHECKLIST.md) | Ordered steps from "code complete" to an approved WordPress.org listing, and who does each. |
| [../release/SUBMISSION-NOTES.md](../release/SUBMISSION-NOTES.md) | Text to paste into the WordPress.org submission and reviewer replies, and reviewer test instructions. |
| [../release/GUIDELINES-AUDIT.md](../release/GUIDELINES-AUDIT.md) | The 18 plugin guidelines, how the plugin meets each, and the vendor trademark list. |
| [../release/ASSETS.md](../release/ASSETS.md) | Icon, banner and screenshot specifications and their current status. |
| [../release/LOGOS.md](../release/LOGOS.md) | The vendor logos the plugin shows, where they come from, what has not been checked, and how to remove them. |
| [../release/EXTERNAL-SERVICES.md](../release/EXTERNAL-SERVICES.md) | Every service the plugin can contact: hosts, data sent, when, terms and privacy links (generated from the connector documents). |
| [../release/PLUGIN-CHECK-NOTES.md](../release/PLUGIN-CHECK-NOTES.md) | What the Plugin Check categories were approximated with, the result of each, and what only the real Plugin Check can verify. |
| [../release/SMOKE-TEST.md](../release/SMOKE-TEST.md) | Result of installing the release zip into a fresh WordPress and exercising it (written by `bin/smoke-install.sh`). |
| [../release/OWNER-ACTIONS.md](../release/OWNER-ACTIONS.md) | The short list of what only the owner can do before and after submission. |

## Keeping this folder honest

- Add a new document to the table above. `php bin/check-docs.php` fails when a file under `docs/` is not listed or
  a relative link is broken (`bin/check-all.sh` runs it).
- When a decision overrides an older document, say so at the top of the newer one and in the "Which document wins"
  list. Do not edit history; add a dated update.
