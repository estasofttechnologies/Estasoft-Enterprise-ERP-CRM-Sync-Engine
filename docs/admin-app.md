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
| `npm run test:js` | Jest 30 with Testing Library and jest-axe (13 files, 180 tests). |
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
| `?role=manager` | Can view and operate, cannot configure. |
| `?dir=rtl` | Right-to-left layout. |

Magic values for the connection form: API key `wrong` gives AUTH-001, `noaccess` gives a missing-permission answer, an address containing `down` gives NET-001, database `nodb` gives CFG-003. Tests use the same server and can inject failures per route (`createMockServer({ failures })`).

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
                         boot.ts, local-types.ts, mock/ (development only)
src/app/                 App, AppBar, Routes, router (hash), sections, providers:
                         NoticeProvider, StatusProvider (polls /status), NowContext,
                         DisplaySettingsContext, ErrorBoundary, AppContext
src/hooks/               useResource, useAsyncAction, useDebouncedValue, useMediaQuery
src/lib/                 format (money, dates, durations), text (labels, error wording),
                         attention, catalog, rules (transforms), path, download
src/components/          design-system parts: Btn, Card, Callout, Dialog, Fields, StateChip,
                         StatusBadge, ResponsiveTable, Pager, SaveBar, Steps, Kpi, ...
src/screens/<area>/      one folder per screen (dashboard, queue, invoices, connections, setup,
                         mapping, stock, reconciliation, logs, settings, shadow)
src/styles/              SCSS partials: tokens, base, layout, components, screens, responsive
src/dev/host.html        host page for the mock build
tests/js/                one test file per area, plus helpers.tsx and setup.ts
```

## How the pieces work

- **Routing.** Hash routes (`#/queue/91`), parsed in `src/app/router.tsx`. WordPress owns the real URL (`admin.php?page=...`), so nothing else would survive a reload. `Routes.tsx` maps the first segment to a screen. Filters and pages live in the hash query (`#/queue?state=failed&page=2`), so they can be linked.
- **Server calls.** Screens call `api.*` from `endpoints.ts`. `client.ts` adds the nonce, sends an `Idempotency-Key` on every request that is not a GET, reads `X-WP-Total` and `X-WP-TotalPages` for lists, and turns error answers into `ApiError` with the code, the HTTP status, the error class and per-field errors. Wording comes from `ERROR_CATALOG` through `lib/text.ts` (`errorText`, `whatHappened`).
- **Loading data.** `useResource` returns `{ data, loading, error, refreshing, reload }`. It ignores stale answers, polls when asked (`/status` every 30 seconds through `StatusProvider`, and the queue, connection detail, stock and reconciliation screens on their own intervals), doubles the interval after each failure up to 5 minutes, and stops while the tab is hidden. `StatusProvider` also announces changes in the numbers through a polite live region.
- **Actions.** `useAsyncAction` wraps a write: it blocks double clicks, shows the progress label, and on failure keeps the dialog open and shows the error text in place. Confirmations use `ConfirmDialog`; deleting all plugin data asks for the typed word DELETE.
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

- Screens or options with no REST route yet: the Exports screen, connect forms for systems other than Odoo (an explanation is shown), product and customer matching options, and the carriers link table. The Stock and Reconciliation screens have no connection picker.
- Transform argument names in the rule editor (`value`, `mode`, `default_country`, `map`, `equals`, `then`, `else`, `separator`, `index`) are the ones the editor sends; they must match the PHP transform library.
- Vendor logos are not shown (letter tiles instead).
- The order box printed by PHP still has a "Sync again" button; the server only retries failed or retrying items.
- `npm audit` reports advisories in development tooling and in `@wordpress/*` packages that WordPress itself supplies at run time; none are inside `build/index.js`.
