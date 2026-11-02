# Full design review: what was found and changed (2 Nov 2026)

Request: "check all screens, review it if you missed anything, check everything in the features, make it professional and improve it." Four independent reviewers read the 80 boards: setup and reference, daily use and responsive, configuration and tools, and a text audit of coverage, consistency and wording. (The text auditor could not open the spec, so feature IDs were matched by family; the spec section numbers below are mine.) The canvas is now 82 boards. Every board was re-rendered and checked: no overflow, no text under 12 px, no touch control under 44 px at 782 px and below, no text in its own background colour, and no WCAG 2.1 A or AA violation reported by axe-core.

## Added (missing screens and states)

- **E06 Mapping: stock and tracking.** The Mapping tab row had a Stock tab with no board. It is now "Stock and tracking" and shows what comes back from Odoo.
- **H02b Order box, all states:** synced, waiting, retrying, failed, skipped, shadow mode, not sent (placed before go-live), product excluded.
- **Skipped and Restore:** a Skipped filter and a Skipped row in the queue, a Restore button and dialog, bulk Skip with a reason, a "View skipped" link on Needs attention.
- **C04 now also holds** the row menu (three variants), the result messages after an action (all sent, partly failed, skipped, paused), and a restore dialog.
- **A05 dashboard widget in five states** (needs attention, all clear, paused, shadow mode, not connected).
- **Setup wizard:** where to find the Odoo credentials (with a copy-for-your-administrator button), testing, partly connected, refused sign-in, other results table, a blocked-setup variant of the store check, a preview that finds a problem, a cancelled or partly approved sign-in for sign-in systems, a payment-method table for accounting systems, a pipeline choice for HubSpot.
- **Mapping:** version list and Discard draft on every versioned tab, Save and Publish switched off when there is no draft, a connection picker on Mapping, Stock and Reconciliation, Changed and New markers on edited rules, Remove this field, a +/- diff in the preview dialog.
- **Reconciliation:** Ignore dialog with a reason, refund repair dialog.
- **Tools and settings:** alert emails now four (paused, daily failed list, queue backlog, test), test email result, danger buttons for destructive actions, Disconnect and Delete dialogs that say what will not be sent or remembered, "Export settings first", an Access board with two capabilities and a "what each allows" table, a capacity-test verdict.
- **Reference:** Z03 gains radio, field error with icon and border, message variants, segmented filter, selectable card, wizard steps, save bar and pager; Z01 gains 8 contrast rows; Z02 gains right-to-left and logo rules; Z04 gains 5 codes; Z05 gains wizard routes, queue extras and a limits table.
- **W01:** a separate 772 by 250 banner (larger type, not a shrunk copy), icon at 5 sizes, file names, names on WordPress.org.

## Fixed (consistency and quality)

- **One set of sample orders.** The orders list had different customers, amounts and states for #10485, #10479, #10474, #10484 and #10492 than the queue, Needs attention and order detail. Rebuilt from the same data. "Oldest waiting" now points at #10484, the oldest waiting order, not at the newest.
- **Paused scenario** (A04, email) rebuilt as one story: Odoo stops answering at 11:52, 41 orders waiting at 12:05, 318 synced (the hourly bars add up), events match, the email is dated 11:52.
- **Queue counts:** 333 items = 312 synced, 14 waiting, 5 failed, 2 skipped. Reconciliation shows 2 open differences everywhere. Stock shows 3 conflicts on both screens.
- **Admin bar** says "14 waiting, 5 failed" with a red dot; grey when not connected.
- **Buttons and wording:** Retry for failed items, Sync again for items that already synced, Retry this order on the detail page. The system picker buttons have names ("Select Xero"). "Gulf" filter became "In the UAE". The "most used ERP" claim is removed. Jargon replaced (circuit, probe, worker, WP-Cron, utf8mb4, payload).
- **Promises softened:** no more "none are lost", "within seconds", "customers will not notice", "a delay cannot oversell", "removed from every message".
- **Tax invoices:** the plugin never signs or sends them; the Invoices page now says your ERP or provider does, and that rules and dates differ by country (hard-coded legal deadlines removed).
- **Tabs:** every mapping tab says whether it is versioned; Products and Tax say they apply on save and are logged.
- **Switch controls** were 16 px wide; fixed (earlier round).

## Spec changes this implies (to fold into the Phase 1 spec)

1. **Error codes added** (all proposals): SYNC-AUTH-003 sign-in cancelled, SYNC-AUTH-004 permission missing, SYNC-CFG-003 database not found, SYNC-NET-003 certificate not trusted, SYNC-PRV-001 preview failed. SYNC-AUTH-001 now means 401 only; 403 becomes AUTH-004.
2. **A refused sign-in (AUTH-001) pauses the connection** and orders wait as Queued; none are marked Failed. This adds a transition to spec 6.2 (Active to Paused).
3. **REST additions:** POST /queue/{id}/restore, POST /queue/bulk, POST /stock/conflicts/{id}/accept and /hold, POST /connections/{id}/pause and /resume, POST /notifications/test, POST /shadow/start, POST /go-live, GET and PUT /onboarding, GET /orders/sample, GET /connections/{id}/oauth/organisations.
4. **Number ranges** for settings (board Z05): pause after 3 to 20 failures (5), check again 1 to 60 minutes (2), 1 to 50 requests per second (4), 1 to 10 items at once (2), safety buffer 0 to 999 (2), queue warning 10 to 10,000 (200), up to 10 recipients.
5. **Capabilities:** estasoft_sync_manage also allows reading connections and mapping (no secrets), accepting or holding a stock conflict, and repairing or ignoring a reconciliation difference. estasoft_sync_configure includes ending shadow mode and going live.
6. **Tax and SKU matches are live-edit** (logged, no drafts). Orders, customers, refunds and stock-and-tracking mapping are versioned.
7. **Shadow mode changes nothing** in WooCommerce or Odoo, including stock and tracking from Odoo (assumed; the spec was silent). At day 7 the owner is asked; nothing switches by itself.
8. **Delete all data forgets the id map and idempotency keys,** so reconnecting can send orders twice. The dialog says so.

## Not done yet (backlog, ranked)

1. **Orders placed before go-live are never sent** and there is no import of past orders. The Not sent box offers "Send this order" one at a time. A bulk import is a product decision (open question below).
2. A permission-denied screen, a session-expired or REST-error screen, an uninstall screen and multisite are not drawn.
3. Phone and tablet: a sticky action bar for selected rows, and a two-column card layout for 521 to 782 px. Tablet still uses the phone cards.
4. Other systems (D03): a warning that add-ons by other developers are not reviewed by Estasoft and see the credentials you give them; a link to it from Connections.
5. Choose a system: a nothing-selected state with Continue off, a no-results state inside the list.
6. A Choose a code dialog on Tax; Invoices empty state and a "not available for this system" state; Stock sync failed state.
7. Chart descriptions that list the values for screen readers.
8. Z01 does not show the hex values of the text, border and state variables.

## Open questions for the owner

1. Should there be an import of existing orders at go-live, and if so how far back?
2. Is it right that shadow mode also holds back stock and tracking from Odoo?
3. A refused sign-in pausing the whole connection (so no order shows Failed): keep, or mark each order Failed?
4. The number ranges in Z05 are proposals. Confirm or change.
5. Show "Available" for all 34 systems at every release, or hide or label ones whose connector is not shipped yet (scope-update doc)?
