# Admin app

The admin screen of ESTASOFT SYNC is a React and TypeScript app built with `@wordpress/scripts`. PHP prints an empty `<div id="estasoft-sync-root">`, boot data in `window.estasoftSync`, and enqueues the script handle `estasoft-sync-admin`. Everything else happens in the browser, over the REST API described in `src/api/types.ts`.

## Build output

| File | Purpose |
| --- | --- |
| `build/index.js` | The app. One bundle, no code splitting. |
| `build/index.css` and `build/index-rtl.css` | Styles. The stylesheet uses logical properties only, so both files are the same size. |
| `build/index.asset.php` | Dependencies and version, read by PHP when it enqueues the script. |

Script dependencies are `wp-a11y`, `wp-api-fetch`, `wp-components`, `wp-element` and `wp-i18n`. WordPress 6.4 is the minimum, so `react/jsx-runtime` (only registered by WordPress from 6.6) is compiled into the bundle from `src/jsx-runtime.ts`, a tiny shim over `createElement` from `@wordpress/element`.

## Commands

All commands run from the repository root.

| Command | What it does |
| --- | --- |
| `npm ci` | Install exactly what `package-lock.json` says. |
| `npm run build` | Production build into `build/`. |
| `npm run start` | Watch build into `build/`. |
| `npm run build:mock` | Build with mock data into `build/mock/` (see below). |
| `npm run start:mock` | Same, rebuilt on every change. |
| `npm run typecheck` | `tsc --noEmit`, strict. |
| `npm run lint:js` / `npm run lint:css` | ESLint (WordPress rules, text domain and translator comment rules) and Stylelint. |
| `npm run test:js` | Jest 30 with Testing Library and jest-axe (17 files, about 260 tests). |
| `npm run check:js` | All of the above, then the production build. |

`wp-scripts test-unit-js` is not used. From `@wordpress/scripts` 37 it runs Vitest, so tests run through `wp-scripts test-unit-jest --config jest.config.js` instead.

## Running without WordPress (mock data)

```
npm run build:mock
python3 -m http.server 8765 --directory build/mock
```

Open `http://localhost:8765/`. The build writes `build/mock/index.html` (from `src/dev/host.html`) next to the bundle. In this build the WordPress packages are bundled, the REST transport is replaced by an in-memory server (`src/api/mock/server.ts`) and the data is the sample store of design board S03: Cedar & Stone, time zone Asia/Dubai, "now" fixed at 2 November 2026 11:40, 333 queue items today.

Options in the address:

| Address | Effect |
| --- | --- |
| `?scenario=normal` | Default. Connected, running, five failed orders. |
| `?scenario=first_run` | Not connected, setup guide not finished. |
| `?scenario=paused` | Syncing paused. |
| `?scenario=shadow` | Shadow mode (test run) in progress. |
| `?scenario=systems` | The normal world plus a signed-out Xero (id 21), a TallyPrime export connection with files (22), Wafeq (23), Business Central (24) and SAP Business One (25), to see every connect, reconnect and export screen without clicking through setup. |
| `?role=manager` | Can view and operate, cannot configure. |
| `?dir=rtl` | Right-to-left layout. |

Magic values for the Odoo connection form: API key `wrong` gives AUTH-001, `noaccess` gives a missing-permission answer, an address containing `down` gives NET-001, database `nodb` gives CFG-003. Every other system (`src/api/mock/connect.ts`) takes the same words in any field, secret or not: `wrong` AUTH-001, `expired` AUTH-005, `noplan` AUTH-006, `noapp` AUTH-007, `badcert` NET-003, `nodb` CFG-003, `noaccess` AUTH-004 (partly approved), and a value containing `down` NET-001. For an app that signs in, a client secret of `cancel` comes back from the sign-in page cancelled and `fail` comes back failed (the mock sign-in page address points back at the mock page itself). Tests use the same server and can inject failures per route (`createMockServer({ failures })`).

The mock is only built when `ESTASOFT_MOCK=1`. `webpack.config.js` replaces `process.env.ESTASOFT_MOCK` at build time and `src/index.tsx` imports `./api/mock` behind it, so the production bundle contains none of it. Check after a production build:

```
grep -c -e ESTASOFT_MOCK -e createMockServer -e "Cedar" -e mock-nonce build/index.js
```

Every count must be 0. `build/mock/` is in `.distignore`, so it never ships.

To prove that `build/` is not stale after a source change, rebuild and look for text that only the current source has, and for text that was removed on purpose:

```
npm run build
for t in "/queue/bulk" "/mappings/" "/versions/" "Continue anyway" "Restore selected"; do printf '%s: ' "$t"; grep -c -F -- "$t" build/index.js; done
for t in "Sync again" "Completed when shipped" "nothing is lost"; do printf '%s: ' "$t"; grep -c -F -- "$t" build/index.js; done
```

The first loop must print 1 for every text, the second 0.

## Boot data

`window.estasoftSync` matches `BootData` in `src/api/types.ts`: `restUrl`, `nonce`, `adminUrl`, `pluginUrl`, `version`, `capabilities` (`manage`, `configure`), `storeTimezone`, `currency`, `currencyDecimals`, `locale`, `wpAdminColorScheme`, `routes`, `notices`, `encryptionKeyLine`. `src/api/boot.ts` reads it once; `getBoot()` and `setBoot()` live in `src/api/client.ts`. Without a `manage` capability the app shows a single "no access" message. Without `configure` (the manager role) the configuration screens show what is set but the controls that change it are disabled or left out, with a note saying who can change it.

## Folder map

```
src/index.tsx            entry, mounts #estasoft-sync-root
src/jsx-runtime.ts       createElement shim (WordPress 6.4)
src/globals.d.ts         *.scss and *.css module types
src/api/                 types.ts and errors.ts (contract, do not edit here),
                         client.ts (request, pagination, ApiError), endpoints.ts (typed calls),
                         boot.ts, local-types.ts, mock/ (development only: server.ts, data.ts,
                         catalog.ts, connectors.ts for the 34 rows, connect.ts for the connect
                         and export routes)
src/app/                 App, AppBar, Routes, router (hash), sections, providers:
                         NoticeProvider, StatusProvider (polls /status), NowContext,
                         DisplaySettingsContext, ErrorBoundary, AppContext
src/hooks/               useResource, useAsyncAction, useKeepFocus, useDebouncedValue, useMediaQuery
src/lib/                 format (money, dates, durations), text (labels, error wording),
                         attention, catalog, rules (transforms), path, download
src/components/          design-system parts: Btn, Card, Callout, Dialog, Fields, StateChip,
                         StatusBadge, ResponsiveTable, Pager, SaveBar, Steps, Kpi, ...
src/screens/<area>/      one folder per screen (dashboard, queue, invoices, connections, connect,
                         exports, setup, mapping, stock, reconciliation, logs, settings, shadow)
src/styles/              SCSS partials: tokens, base, layout, components, screens, responsive
src/dev/host.html        host page for the mock build
tests/js/                one test file per area, plus helpers.tsx and setup.ts
```

## How the pieces work

- **Routing.** Hash routes (`#/queue/91`), parsed in `src/app/router.tsx`. WordPress owns the real URL (`admin.php?page=...`), so nothing else would survive a reload. `Routes.tsx` maps the first segment to a screen. Filters and pages live in the hash query (`#/queue?state=failed&page=2`), so they can be linked.
- **Server calls.** Screens call `api.*` from `endpoints.ts`. `client.ts` adds the nonce, sends an `Idempotency-Key` on every request that is not a GET, reads `X-WP-Total` and `X-WP-TotalPages` for lists, and turns error answers into `ApiError` with the code, the HTTP status, the error class and per-field errors. Wording comes from `ERROR_CATALOG` through `lib/text.ts` (`errorText`, `whatHappened`).
- **Loading data.** `useResource` returns `{ data, loading, error, refreshing, reload }`. It ignores stale answers, polls when asked (`/status` every 30 seconds through `StatusProvider`, and the queue, connection detail, stock and reconciliation screens on their own intervals), doubles the interval after each failure up to 5 minutes, and stops while the tab is hidden. `StatusProvider` also announces changes in the numbers through a polite live region.
- **Actions.** `useAsyncAction` wraps a write: it blocks double clicks, shows the progress label, and on failure keeps the dialog open and shows the error text in place. A form that is switched off while its request runs uses `useKeepFocus`, which puts the keyboard back on the control that had it (or on the result) when the request is over. Confirmations use `ConfirmDialog`; deleting all plugin data asks for the typed word DELETE.
- **Notices.** `NoticeProvider` renders WordPress `Notice` components inside two persistent live regions (`role="status"` and `role="alert"`), so screen readers hear them.
- **Dates, numbers, money.** Always through `lib/format.ts` and the `DateTime` and `Money` components, which use the store time zone, the site locale and the currency decimals from the boot data (never `toLocaleString` directly). The number of decimals of an amount comes from `currencyDecimals` of the boot data for the store currency, and from the table in `lib/format.ts` for other currencies. That table is a copy of `EXPONENTS` in `includes/Core/Support/Money.php` (IQD has 3 places, JPY 0); Intl is not asked, because it disagrees with the server for IQD. Keep the two in step.
- **Time.** `StatusProvider` keeps a shared clock (`NowContext`) that starts from the server time of the last `/status` answer and moves on a minute at a time, so deadlines such as the time left to report an invoice change colour while the page stays open, without a request. Settings are loaded for everyone who can open the app (managers read them, administrators change them): the "Show times in" choice and the log retention come from there.
- **What the server accepts.** Queue actions are only offered for the states the server accepts (Retry: failed, retrying; Skip: queued, retrying, failed; Restore: skipped), and the messages say the number the server reports (`done`), not the number sent. The tracking status is `none` or `completed`; another saved status is kept and shown by its slug. The preview and publish of a mapping use the saved draft on the server, so edits on screen are saved first.
- **Error details.** `ApiError.details` holds `errors` (every rule problem of a refused publish), `test` (the failed connection test) and `key_line` (the wp-config line for a missing key), read from `data.details` and checked for shape. `ErrorDetails` shows them in dialogs and callouts.
- **Links.** Addresses that come from a connected system go through `safeHttpUrl` (`lib/url.ts`); only `http:` and `https:` become links.
- **Connection picker.** On the Mapping screen the chosen connection is in the address (`?connection=13`); `client.ts` adds it as `connection_id` to the mapping, tax and product requests. Other screens send none and the server uses the first connection that is not disconnected.
- **Theme.** Product colours are CSS variables named `--estasoft-sync-*`, defined in `src/styles/_tokens.scss` on `.estasoft-sync`, `.estasoft-sync-overlay` (dialogs) and `.estasoft-sync-popover` (menus), because those two render in portals. The rest of the look comes from WordPress components and the admin colour scheme variables.
- **Responsive.** Up to 782 px tables become cards (`ResponsiveTable` keeps real table roles and labels every cell), the tab bar becomes a sections menu, touch targets are at least 44 px, and dialogs below 520 px become bottom sheets.
- **Accessibility.** Status is always an icon plus a word. Focus ring is 2 px navy. Animations stop under `prefers-reduced-motion`. Every screen has a jest-axe test, and the mock build was also checked with axe in Chrome (colour contrast included) on every route at 1280 and 390 px wide.
- **Text.** Every string goes through `__`, `_n` or `sprintf` with the literal text domain `estasoft-erp-crm-sync-engine` and a translator comment where a placeholder is used. ESLint enforces it.

## The connect experience

One component set draws the connect screen for all 34 systems from the catalog (`GET /catalog`) and the connector's own form fields. `src/screens/connections/ConnectSystem.tsx` picks the shape from `connection_method`; the parts are in `src/screens/connect/`.

| Shape (`connection_method`) | Screen | What happens |
| --- | --- | --- |
| `key` | `DirectConnect` | Numbered steps, plan note, form, **Test connection** (`POST /connections/test`), and a passing test saves (`POST /connections`). |
| `cloud_app` | `DirectConnect` | The same, with the two-administrator note and "Copy the steps for my administrators". After saving, the company is chosen (`GET /connections/{id}/oauth/organisations`, `POST /connections/{id}/oauth/organisation`). |
| `server_login` | `DirectConnect` | The same, with the warning that the server must be reachable from this website. |
| `own_app` | `OwnAppConnect` | Step 1: steps, the redirect address to copy (`<restUrl>oauth/callback`, or the `display` field the connector sends), Client ID and secret. Step 2: **Save and sign in at {vendor}** saves the connection without calling the vendor (`POST /connections`, status `needs_reconnect`), then `POST /connections/{id}/oauth/start` and the browser goes to the vendor's page. |
| `export_file` | `ExportConnect` | No credentials: the connector's non-secret options, "how it works", and a link to the Exports screen. Nothing is sent anywhere. |
| `none`, `unknown`, or no form | `MethodExplainer` | Says why it cannot be connected. Nothing pretends to work. |

- **Form from data.** `CredentialForm` draws `CredentialFieldDef` by `type` (`text`, `password`, `select`, `textarea`, `url`, `number`, `display`) and sorts by `group` (`connection`, `app`, `advanced`; advanced sits behind "More settings"). Secrets go to `secrets`, everything else to `config`; an empty secret is never sent. Odoo keeps its own text (`connect/copy.ts`) when the catalog gives no steps or fields.
- **Choose a system.** Every row shows how you connect, the plan note (`needs`), and for `verified: false` the tag "Not yet tested with a live account" with a help button (the connector was built from the vendor's documentation and tested with recorded examples; Shadow mode checks results before anything is sent). `available: false` and `none` rows cannot be selected, and say why in the row (`unavailable_reason`: "Cannot be selected yet: ..."). A row with `variants` asks "Which product do you use?" and goes on with the product's connector id (`resolveSystem` in `lib/connect.ts`, which takes the product's own way to connect, steps, hosts, links and form from the catalog's `variants[]`, never the first product's); the wizard stores it as `answers.variant`. A product whose way to connect is `unknown` (Sage X3) but whose connector sends a form is drawn from its sign-in style (`shapeOfEntry`) with a note that the way to connect is still to be confirmed.
- **Test result.** `testChecks` (`lib/connect.ts`) shows the checks of connection-methods spec 2.1 in order (reach, certificate, sign in, plan, app for a cloud app, permissions), stopping at the first failure, from the error code or `missing_permissions`.
- **What is sent, and where.** Every connect screen lists the `hosts` and links `terms_url` and `privacy_url` (WordPress.org guideline 6).
- **The way back from a sign-in page.** The server redirects to `#/connections/<id>?signin=ok|cancelled|failed&connection=<id>`. `ConnectionDetail` reads it (`readSignIn`) and shows `SignInReturn`: `cancelled` says nothing was connected (SYNC-AUTH-003) and offers Try again; `failed` says so and points to the log; `ok` goes on to the organisation question, then runs the test, and a partly approved sign-in lists what is missing (SYNC-AUTH-004) with "Sign in again". When setup is not finished it offers "Continue setup". A reconnect of a sign-in that had an organisation is already active when the page loads, so the question is not asked again and the test runs at once. A sign-in that ends on the Connections list (`#/connections?signin=failed`, an unknown or used link) is explained there. A connector that needs no sign-in page (it answers `status: active` to `POST /connections`) goes on without one. The wizard keeps Continue off until the connection is not `needs_reconnect`. The browser leaves the page through `lib/external.ts` (`leaveTo`, which tests replace with `setLeaver`).
- **Reconnect and expiry.** The Connections list, the dashboard card and the detail page show **Reconnect** for `needs_reconnect` (a dialog, then the sign-in again for an own app; the replace dialog for the other shapes). The expiry warning names what runs out (key, secret, password or sign-in) and offers **Replace the key/secret/password** for every shape; a key or password is tested before it is saved, and the secret of an own app is saved and the sign-in starts again. `#/connections/problems` is the reference of problems AUTH-001 to AUTH-007 (board D04).
- **Exports** (`src/screens/exports`, route `#/connections/<id>/exports`, board D06). The open batch, **Create export file now** (`POST /exports`), the files with size, orders, date and expiry, Download (`GET /exports/{id}/download`, read as a Blob so the bytes are unchanged, then saved; a file's attachments, such as the masters list, have their own button) and Delete (with a dialog), the orders left out and why (SYNC-EXP-002), the SYNC-EXP-001 error with Create again, the import guide of the format (`guides.ts`, by connector id: Tally, DATEV, Sage 50, or a generic one), and "Delete all export files now" (one `DELETE` per file). The dashboard shows a card with the waiting orders when a connection uses files.
- **What to sync.** `WhatStep` picks the questions by the connection type: ERP (orders as sale orders and invoices), accounting (what an order becomes, a payment table of typed accounts, customers, refunds, invoice link; board B04b) and CRM (contacts, pipeline, deal per order, consent, stage per order status; board B04c). Stock, tracking and prices appear only for a flagged capability, and "Not offered" cards say why not. The answers are stored in `onboarding.answers.what`.

## Adding a screen

1. Add the response types to `src/api/types.ts` (contract owner) or `src/api/local-types.ts` (screen-only).
2. Add typed calls to `src/api/endpoints.ts`, and the same routes to `src/api/mock/server.ts` with sample data in `mock/data.ts`.
3. Create `src/screens/<area>/<Screen>.tsx`. Start from `Invoices.tsx`: `PageHeader`, `useResource`, then `LoadingState`, `ErrorState`, `EmptyState` and the content, each state handled.
4. Register the route in `src/app/Routes.tsx`. If it is a new top-level section, add it to `src/app/sections.ts` (and `SectionId`).
5. Add styles to `src/styles/_screens.scss` (or a new partial that `index.scss` loads) using only `--estasoft-sync-*` variables and logical properties (`margin-inline-start`, `inset-block-start`), and the 782 px rules to `_responsive.scss`.
6. Add `tests/js/<area>.test.tsx`: render with `renderApp({ route: '/your-route' })` from `helpers.tsx` (options also take `scenario`, `boot`, `failures` and `wrap`), assert the heading with `expectHeading`, the states and one key flow, then call `expectNoA11yViolations()`. To reach a state the sample world does not have, put a wrapper around the mock transport: `patchJson` changes a JSON answer, `answer` gives a fixed status and body (an error with `data.details`), `failRoute` makes a request fail like a lost connection.
7. Run `npm run check:js`.

## Versions this was built and tested with

`@wordpress/scripts` 37.0.0, `@wordpress/components` 43.0.0, `@wordpress/element` 10.0.0, `@wordpress/i18n` 7.0.0, `@wordpress/api-fetch` 8.0.0, `@wordpress/a11y` 5.0.0, React 19, TypeScript 6.0.3, Jest 30.5.2, jest-axe 11.0.0, Testing Library React 16.3.3, Node 22.

## Known gaps

- **The connect form is not in the catalog answer yet.** The screens read `credential_fields` on each catalog row (and on a variant). `GET /catalog` does not send it today, so until it does a pasted key and an app that signs in get the standard fields (`api_key`; `client_id` and `client_secret`) and every other system is explained instead of guessed at. Odoo keeps its own form.
- A variant (Sage) carries no `connection_method` in the catalog answer: `lib/connect.ts` knows the two the connection-methods spec names (Sage 50 is a file, Sage 300 a server sign-in) plus Sage Accounting and Sage 200 as own apps.
- The queue and the dashboard still say Synced, not Exported, for an order that is in an export file. The REST answer does not say which items belong to a file connection.
- The masters list of an export ("Download the list", board D06) is shown when the file carries `attachments` (`ExportFileInfo.attachments`, downloaded with `?part=`, from 1). `types.ts` does not list `attachments` yet; `local-types.ts` adds it.
- The payment accounts (accounting) and the deal stage for each order status (CRM) are typed, because no route lists the accounts or the stages of a system. They are saved with the setup answers; nothing on the server reads them yet, like the other answers of step 3. A CRM pipeline is saved through the connector's own option when it has one called `pipeline`.
- Screens or options with no REST route yet: product and customer matching options and the carriers link table. The Stock and Reconciliation screens have no connection picker.
- Transform argument names in the rule editor (`value`, `mode`, `default_country`, `map`, `equals`, `then`, `else`, `separator`, `index`) are the ones the editor sends; they must match the PHP transform library.
- Vendor logos are shown on the Choose a system screen from `assets/logos/<connector id>.png` (the `logo` field of the catalog answer, always sent, empty when there is no file). A system with no file, a file that cannot load, or the filter `estasoft_sync_show_vendor_logos` returning false shows a letter tile. See docs/release/LOGOS.md. The other screens still show names only.
- The order box printed by PHP still has a "Sync again" button; the server only retries failed or retrying items.
- `npm audit` reports advisories in development tooling and in `@wordpress/*` packages that WordPress itself supplies at run time; none are inside `build/index.js`.
