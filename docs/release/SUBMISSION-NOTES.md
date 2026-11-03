# Submission notes

Text the owner pastes into the WordPress.org submission and into replies to the Plugin Review team. Each block
between the lines is ready to paste. Check every statement against the finished build first (see
[CHECKLIST.md](CHECKLIST.md), step C4), and change this file when the plugin changes. Last checked against the tree
on 2 Nov 2026 (version 0.1.0).

## 1. Summary

---

**Estasoft Enterprise ERP/CRM Sync Engine** keeps WooCommerce in step with an ERP, accounting system or CRM. When an
order, refund or customer event happens, the plugin writes one row to its own queue table in the same database
transaction as the order. A background worker (Action Scheduler, which ships with WooCommerce, with a WP-Cron
fallback) delivers the queue to the connected system, with retries, a circuit breaker and a rate limiter. Stock,
delivery tracking and invoice status can be read back. Everything the merchant sees is a React admin screen under
WooCommerce > ESTASOFT SYNC, using WordPress components and the REST API `estasoft-sync/v1`.

The plugin contains 35 connectors (34 entries on the setup screen, because the Sage entries hold several products):
13 ERP systems, 13 accounting systems, 6 CRM systems, and 3 systems that get an import file instead of a connection
(DATEV, Sage 50, TallyPrime). Two more entries, SMACC and Pennylane, are listed and cannot be selected yet, and
say why. A connector does nothing until a merchant selects it and enters credentials. All features are free. There
is no licence key, tier, trial or upsell code, and no telemetry.

---

## 2. External service disclosure (also in `readme.txt`)

---

The plugin contacts only the system the merchant connects, only after the merchant has entered that system's
credentials, and only through `Core\Support\WpHttpClient` (`wp_remote_request`, 30 second timeout, TLS verification
on, unsafe and private addresses rejected unless a site owner opts in with the filter
`estasoft_sync_allow_private_hosts`). There is no Estasoft server, no analytics, no update check and no relay.

`readme.txt` has an entry under `== External services ==` for every system that is contacted over the network
(32): what it is used for, the data sent, when, the hosts, and links to the vendor's terms and privacy policy. The
same data as a table, with the data and timing taken from each connector's own document, is
`docs/release/EXTERNAL-SERVICES.md`. Both are generated from `docs/connectors/*.md` by
`php bin/generate-external-services.php`, and CI fails when they are out of date. In short, for every system:

- Sent: the credentials (to sign in), and the order, customer and product data the merchant's mapping selects
  (order number, date, status, totals, line items with SKU, quantity, price and tax, shipping, fees, coupons, name,
  email, phone, addresses, company, buyer tax number). Full card numbers are never read.
- Read back: product matches, stock quantities, delivery tracking, invoice status, and data for a nightly comparison.
- When: on Test connection; when an order, refund or customer event is delivered from the queue; on the scheduled
  stock, tracking and reconciliation reads; on Sync again or Retry. In Shadow mode the plugin only reads.
- Why: to create and update the sales orders (or invoices, or deals), customers and credit notes, and to bring stock
  and tracking back.
- The three file-export systems contact no server: the plugin writes a file in a protected folder of the uploads
  directory, the merchant downloads it and imports it by hand.
- A host written `*.example.com` stands for the address of the merchant's own account at that vendor, and for a
  system the merchant runs themselves (Odoo, ERPNext, SuiteCRM, SAP Business One, Sage 300, Sage X3 and others) the
  only host is the address the merchant types.

---

## 3. Why there are 35 connectors in one plugin

---

A store that outgrows its accounting system, or has an ERP and a CRM, needs one queue, one mapping engine, one set
of screens and one place to see what failed; separate plugins would each need their own copy of all that. The
connectors are small classes on one shared base (`includes/Connectors/Support/`) and one versioned connector
contract (`includes/Contract/`); a connector is built only when its system is used (`ConnectorsModule` registers
each as a lazy closure under its id), so a site that uses Odoo builds Odoo and nothing else. A connector that has
no connection makes no request, loads no extra code and adds no hook. Each connector has a document in
`docs/connectors/` (sources with confidence tags, field mapping, error mapping, what is not verified, and a manual
smoke test). The readme says that no connector has been tried with a live vendor account yet and tells the merchant
to use Shadow mode first.

---

## 4. Design: queue, outbox, idempotency

---

- **Outbox.** The queue table is also the outbox. An order event is written in the same database transaction that
  saves the order, so an order is never saved without its event, and checkout never calls the other system.
- **Worker.** Action Scheduler (or WP-Cron) claims items with a lock and a lease, delivers them one at a time per
  connection, honours the vendor's rate limits (shared across workers), retries with growing delays, and pauses the
  connection (a circuit breaker) when the other system keeps failing, so orders wait instead of being lost or
  retried in a storm. A failure that retrying cannot fix goes to Needs attention with a code (`SYNC-...`) and a
  plain remedy.
- **Idempotency.** Each order carries the reference `WC-{order id}`. Before creating anything, the connector looks
  for that reference in the other system (find before create), and the plugin keeps an id map and the hash of the
  last data sent, so a retry or a replay does not make a second copy. REST requests that change state accept an
  `Idempotency-Key` header.
- **Shadow mode.** The same process runs without changing anything in either system, so a merchant can compare the
  result before going live.
- **No tax signing.** The plugin never signs, issues or certifies a tax invoice. The connected system does that.

---

## 5. Why the plugin adds capabilities

---

The plugin adds two capabilities, `estasoft_sync_manage` and `estasoft_sync_configure`, so a store can let staff
operate the queue without letting them change connections or credentials.

- `estasoft_sync_manage`: see the dashboard, queue and logs; retry, skip and restore items. Granted to
  administrators and to the `shop_manager` role.
- `estasoft_sync_configure`: change connections, credentials, mapping and settings, and delete plugin data.
  Granted to administrators only.

Every REST route and every state-changing action checks one of them and a nonce (REST requests carry the
`X-WP-Nonce` header). The capabilities are added on activation and on upgrade (`Core\Capabilities::grant()`) and
removed from every role with the rest of the plugin's data when the merchant chooses to delete it
(`Core\Capabilities::revoke()`).

---

## 6. Why the plugin has REST routes

---

The admin screens are one React app. It talks to the site only through the REST API of the plugin, namespace
`estasoft-sync/v1` (76 method-and-path pairs, listed in `docs/rest-api.md` and, for the export and sign-in routes, in `docs/connectors/BUILDING.md`), using `@wordpress/api-fetch`, which sends the
`wp_rest` nonce. There is no `admin-ajax` action and no public page. Every route registers through one method
(`BaseController::add()`) that sets a `permission_callback` for `estasoft_sync_manage` or
`estasoft_sync_configure`; WordPress itself refuses a cookie-authenticated request without a valid nonce. Arguments
are declared with types, ranges and sanitise callbacks. Two routes differ, and are the only ones a visitor can
reach: the sign-in callback of a vendor's OAuth page (it accepts only a one-time, single-use `state` that the plugin
made for a signed-in administrator and answers with a redirect), and the signed inbound webhook (off until a
configurer turns it on for a connection; it checks an HMAC of the body and a time window and is rate limited, and
what it receives is only a hint, because the plugin reads the data again from the system). `bin/smoke-install.sh`
requests every route as a visitor and expects 401 or 403 (a 4xx for the webhook).

---

## 7. Where secrets and data are stored

---

- Credentials (API keys, passwords, client secrets, tokens, private keys) are encrypted with libsodium `secretbox`
  before they are saved. The key is the constant `ESTASOFT_SYNC_KEY` in `wp-config.php` when the merchant has added one
  (the plugin shows a ready-made line when no key is available), otherwise it is derived from the WordPress security
  keys and salts of the same file. The key is never stored in the database. Without any key nothing is saved.
- They are saved in the plugin's own table (`{$wpdb->prefix}estasoft_sync_connections`), never in `wp_options`,
  never autoloaded, and are never returned by the REST API, written to a log, put in a support report or included
  in an exception message. The screens show only the last four characters.
- The plugin creates its own tables, all prefixed `{$wpdb->prefix}estasoft_sync_` (queue, connections, mappings,
  logs, id map, state, idempotency and reconciliation data), and a few options. WooCommerce data is read and
  written only through WooCommerce's CRUD functions (`wc_get_order`, `$order->update_meta_data()`); there is no SQL
  on posts, postmeta or HPOS tables.
- Export files are written only under `wp-content/uploads/estasoft-sync/exports/`, in folders that deny web access,
  and are deleted after 30 days and with the plugin's data.
- Data is kept on deactivation. On deletion it is removed only if the merchant turned on "Delete all data when the
  plugin is deleted" (`uninstall.php`; multisite is handled by looping the sites).
- Logs redact emails, phone numbers, tax numbers and tokens by default, and are pruned by age (7, 14 or 30 days).

---

## 8. Compiled JavaScript and source

---

`build/` holds the compiled admin app. The readable TypeScript and React source is in `src/` of the public
repository https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine and the readme links to
it. Build: `npm ci`, then `npm run build` (`@wordpress/scripts`). React and the WordPress component packages are
provided by WordPress and are not bundled (`build/index.asset.php` lists the handles). No script, style or font is
loaded from another site. The zip is built by `bin/build-zip.sh` from the same repository.

---

## 9. Reviewer test instructions

There is no demo mode and no sample connector. A reviewer needs no paid ERP. Pick the path that suits.

**Path A: nothing installed except WordPress and WooCommerce (a few minutes).**

---

1. Use WordPress 6.4 or later, PHP 8.1 or later (sodium is part of PHP), and WooCommerce active. Nothing has to be added
   to `wp-config.php`: the WordPress security keys encrypt the credentials. To try the dedicated key, add
   `define( 'ESTASOFT_SYNC_KEY', 'any long random string of 32 or more characters' );`
2. Activate the plugin. Open WooCommerce > ESTASOFT SYNC: the first-run dashboard, the readiness check
   (Settings > Readiness check), the Choose a system screen (34 entries; two say they cannot be selected yet) and
   the Settings, Mapping and Logs screens. Nothing is sent anywhere, and the plugin makes no request until a
   connection is saved.
3. **A full sync with no outside system, using a file export.** Choose **Sage 50**, **DATEV** or **TallyPrime** on
   Choose a system and fill in the fields it asks for (no key and no sign-in are needed). Place a WooCommerce
   order. The order appears in Queue as waiting for export. Open Exports and press **Create export file now**, then
   download the file. Use Preview to see exactly what a system would receive, and Shadow mode to run the process
   without changing anything. Use an unknown SKU or a missing field to see Needs attention with a code.

---

**Path B: a real ERP, with Odoo Community in Docker (about ten minutes).**

---

1. **Start Odoo** with the official image. Docker Hub lists the tags `20.0` (also `latest`), `19.0` and `18.0`
   (checked 2 Nov 2026). The connector supports Odoo 17, 18, 19 and later, so use `19.0` (the JSON-2 API) or `18.0`
   (JSON-RPC), not `latest`:

   ```bash
   docker run -d -e POSTGRES_USER=odoo -e POSTGRES_PASSWORD=odoo -e POSTGRES_DB=postgres --name db postgres:15
   docker run -d -p 127.0.0.1:8069:8069 --name odoo --link db:db odoo:19.0
   ```

   Odoo is then at http://localhost:8069. Create a database (choose demo data to get sample products), and install
   the apps **Sales**, **Inventory** and **Invoicing**. (The container alias of the database must be `db`.)
2. **Create an API key in Odoo:** user menu > My Profile (Preferences) > Account Security > New API Key. Copy it.
3. **Allow a local address in WordPress.** The plugin refuses private and local addresses (and plain http) by
   default. For this test add a must-use plugin, `wp-content/mu-plugins/estasoft-allow-local.php`:

   ```php
   <?php
   add_filter( 'estasoft_sync_allow_private_hosts', '__return_true' );
   ```
4. **Connect:** WooCommerce > ESTASOFT SYNC > setup. Address `http://localhost:8069`, the database name, the login
   (the Odoo user's email), and the API key. Press Test connection: it reports the version and the permissions.
5. **Create a WooCommerce product** whose SKU equals an Odoo product's Internal Reference, then place an order for
   it. In the plugin, the order appears in Queue and becomes Synced; in Odoo it appears under Sales > Orders with
   the customer reference `WC-<order id>`. Place a refund to see a credit note. Use **Preview** in setup to see
   the exact data before it is sent, and Shadow mode to run without changing anything in Odoo.
6. **Failures:** use an unknown SKU to see Needs attention with the code `SYNC-MAP-001`; stop the Odoo container to
   see retries and the connection pause.

Clean up: `docker rm -f odoo db`.

---

**What to expect from the other connectors.** They connect to a vendor's cloud, which needs a vendor account (most
have a free trial or sandbox, named in the setup hint of each). Each document in `docs/connectors/` has the manual
smoke test for its system. The plugin's own tests run each connector against a fake of the vendor's API written from its documentation.

## 10. Other points reviewers ask about

---

- **Nothing runs at file load** except the `ABSPATH` guard and declarations. The main file checks the PHP version
  first, before any class is loaded, and checks WooCommerce on `plugins_loaded`; without it the plugin shows a
  notice and does not start (tested in `bin/smoke-install.sh`).
- **No `eval`, `base64_decode` of code, `extract`, `@` suppression, `error_reporting`, `ini_set`, shell calls or
  remote includes** in the runtime code. `php bin/red-flags.php` enforces this in CI by reading PHP tokens.
- **Requires Plugins: woocommerce.** On WordPress 6.4 (before the header is enforced) the plugin shows a notice
  and does not start when WooCommerce is missing.
- **HPOS and the block checkout** are declared compatible with `FeaturesUtil::declare_compatibility`.
- **Admin notices** concern only the plugin's own state, are dismissible or clear themselves, and say how to fix
  the problem. There is no advertising and no upgrade prompt.
- **Nothing is output on the public site.**
- **Trademarks:** vendor names appear only in text, with a disclaimer; no vendor logos ship.
- **The plugin is English only.** Strings use the text domain `estasoft-erp-crm-sync-engine`; a `.pot` file is
  generated with `bin/make-pot.sh`.
- **Local checks.** The release zip is installed into a fresh WordPress with `bin/smoke-install.sh`
  ([SMOKE-TEST.md](SMOKE-TEST.md)). Plugin Check itself ran only on GitHub; what was approximated is in
  [PLUGIN-CHECK-NOTES.md](PLUGIN-CHECK-NOTES.md).

---

## 11. Short replies to common review points

- **"The plugin needs an external service."** See section 2: the plugin has substantial functionality of its own
  (queue, worker, mapping, preview, shadow mode, logs, reconciliation, export files), the services are the merchant's
  own systems, they are documented with data, timing and links, and Path A and B above test it without a paid ERP.
- **"Why so many connectors?"** Section 3.
- **"Calls an external server."** Only after a merchant saves a connection, only to the hosts listed for that system,
  and never to Estasoft. The smoke test records zero outbound requests on install, activation and every screen.
- **"Compiled code."** Section 8.
- **"Vendor names."** Used only to say which systems the plugin works with, with the disclaimer in the readme; no
  logos; the plugin name and slug begin with Estasoft.
