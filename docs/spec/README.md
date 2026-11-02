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
(status words) and the connector contract and error catalog sections of the Phase 1 spec (30 codes now).

## Documents

| Document | What it is |
| --- | --- |
| [phase1-spec.md](phase1-spec.md) | Phase 1 specification. **Not yet in the repository**, see the note below. |
| [review-2-changes.md](review-2-changes.md) | Findings of the full design review, what was added and fixed, the spec changes it implies, and the backlog. **Not yet in the repository.** |
| [scope-update.md](scope-update.md) | All 34 systems are official connectors; what the vendor research changed. |
| [connection-methods-spec.md](connection-methods-spec.md) | Connection methods, credentials, export mode, relay decision, WordPress.org checklist. |
| [../DECISIONS.md](../DECISIONS.md) | Design decisions, identifiers, palette and brand mark. **Not yet in the repository.** |
| [../ARCHITECTURE.md](../ARCHITECTURE.md) | Code layout, seams, core services, mapping rule model, queue rules, conventions. |
| [../error-catalog.md](../error-catalog.md) | The thirty `SYNC-...` error codes: class, message, what the person does, retries. |
| [../design/uiux-status.md](../design/uiux-status.md) | State of the UI/UX design: direction, boards, what was checked and what was not, open decisions. |
| [../research/integration-research.md](../research/integration-research.md) | What each vendor documents: API, sign-in, plan gates, objects, limits. Facts are tagged documented, third-party or inferred. |
| [../research/integration-research-vendors.md](../research/integration-research-vendors.md) | Per-vendor research notes, first part. |
| [../research/integration-research-vendors-2.md](../research/integration-research-vendors-2.md) | Per-vendor research notes, second round. |
| [../research/integration-research-export-formats.md](../research/integration-research-export-formats.md) | Import formats of the systems that connect through export files. |
| [../release/CHECKLIST.md](../release/CHECKLIST.md) | Ordered steps from "code complete" to an approved WordPress.org listing, and who does each. |
| [../release/SUBMISSION-NOTES.md](../release/SUBMISSION-NOTES.md) | Text to paste into the WordPress.org submission and reviewer replies, and reviewer test instructions. |
| [../release/GUIDELINES-AUDIT.md](../release/GUIDELINES-AUDIT.md) | The 18 plugin guidelines, how the plugin meets each, and the vendor trademark list. |
| [../release/ASSETS.md](../release/ASSETS.md) | Icon, banner and screenshot specifications and their current status. |

Documents written by the build agents live next to the code they describe and are added to this table when they
land: `docs/core-services.md`, `docs/mapping-engine.md`, `docs/admin-app.md` and `docs/connectors/odoo.md`.

## Note for maintainers: three documents still to copy

`phase1-spec.md`, `review-2-changes.md` and `../DECISIONS.md` are maintained in the owner's design project (the
project documents `estasoft-erp-sync-engine-phase1-spec`, `estasoft-erp-sync-engine-review-2-changes` and
`estasoft-erp-sync-engine-decisions`). They have to be copied into this repository unchanged, apart from a short
front note, before the first release. `bin/check-docs.php` already expects these files; until they exist the
links above are reported as broken.

## Keeping this folder honest

- Add a new document to the table above. `php bin/check-docs.php` fails when a file under `docs/` is not listed or
  a relative link is broken (`bin/check-all.sh` runs it).
- When a decision overrides an older document, say so at the top of the newer one and in the "Which document wins"
  list. Do not edit history; add a dated update.
