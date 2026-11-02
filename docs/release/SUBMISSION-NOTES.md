# Submission notes

Text the owner pastes into the WordPress.org submission and into replies to the Plugin Review team. Each block
between the lines is ready to paste. Check every statement against the finished build first (see
[CHECKLIST.md](CHECKLIST.md), step C4), and change this file when the plugin changes.

## 1. Summary

---

**Estasoft Enterprise ERP/CRM Sync Engine** keeps WooCommerce in step with an ERP, accounting system or CRM. When an
order, refund or customer event happens, the plugin writes one row to its own queue table in the same database
transaction as the order. A background worker (Action Scheduler, which ships with WooCommerce, with a WP-Cron
fallback) delivers the queue to the connected system, with retries, a circuit breaker and a rate limiter. Stock and
delivery tracking can be read back. Everything the merchant sees is a React admin screen under WooCommerce >
ESTASOFT SYNC, using WordPress components and the REST API `estasoft-sync/v1`.

Version 0.1.0 includes the connector for Odoo (17, 18, 19 and later). The setup screen lists 34 systems; the other
33 are marked not available in this version. All features are free. There is no licence key, tier, trial or upsell
code, and no telemetry.

---

## 2. External service disclosure (also in `readme.txt`)

---

The plugin contacts only the system the merchant connects, only after the merchant has entered that system's
address and credentials, and only through `Core\Support\WpHttpClient` (`wp_remote_request`, 30 second timeout, TLS
verification on, unsafe and private addresses rejected unless a site owner opts in with the filter
`estasoft_sync_allow_private_hosts`). There is no Estasoft server, no analytics, no update check and no relay.

For Odoo (the merchant's own server, Odoo Online or Odoo.sh):

- Sent: the login and API key (to sign in); the order, customer and product data the merchant's mapping selects
  (order number, date, status, totals, line items with SKU, quantity, price and tax, shipping, fees, coupons, name,
  email, phone, addresses, company, buyer tax number). Full card numbers are never read.
- Read back: product matches, stock quantities, delivery tracking, invoice status, and data for a nightly comparison.
- When: on Test connection; when an order, refund or customer event is delivered from the queue; on the scheduled
  stock, tracking and reconciliation checks; on Sync again or Retry.
- Why: to create and update the sales orders, customers and credit notes, and to bring stock and tracking back.
- Terms and privacy for Odoo Online and Odoo.sh: https://www.odoo.com/documentation/19.0/legal.html and
  https://www.odoo.com/privacy. A self-hosted Odoo is the merchant's own server.

---

## 3. Why the plugin adds capabilities

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

## 4. Where secrets and data are stored

---

- Credentials (API keys, passwords, client secrets) are encrypted with libsodium `secretbox` before they are
  saved. The key comes from the constant `ESTASOFT_SYNC_KEY` in `wp-config.php`, which the merchant adds; the
  plugin shows a ready-made line. The key is not stored in the database. Without it nothing is saved.
- They are saved in the plugin's own table (`{$wpdb->prefix}estasoft_sync_connections`), never in `wp_options`,
  never autoloaded, and are never returned by the REST API, written to a log, put in a support report or included
  in an exception message. The screens show only the last four characters.
- The plugin creates its own tables, all prefixed `{$wpdb->prefix}estasoft_sync_` (queue, connections, mappings,
  logs, id map, state, idempotency and reconciliation data), and a few options. WooCommerce data is read and
  written only through WooCommerce's CRUD functions (`wc_get_order`, `$order->update_meta_data()`); there is no SQL
  on posts, postmeta or HPOS tables.
- Data is kept on deactivation. On deletion it is removed only if the merchant turned on "Delete all data when the
  plugin is deleted" (`uninstall.php`; multisite is handled by looping the sites).
- Logs redact emails, phone numbers, tax numbers and tokens by default, and are pruned by age (7, 14 or 30 days).

---

## 5. Compiled JavaScript and source

---

`build/` holds the compiled admin app. The readable TypeScript and React source is in `src/` of the public
repository https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine and the readme links to
it. Build: `npm ci`, then `npm run build` (`@wordpress/scripts`). React and the WordPress component packages are
provided by WordPress and are not bundled. No script, style or font is loaded from another site. The zip is built
by `bin/build-zip.sh` from the same repository.

---

## 6. Reviewer test instructions: without a real ERP, and with a local Odoo

---

**There is no demo mode and no sample connector.** Without a connected system a reviewer can still activate the
plugin, open WooCommerce > ESTASOFT SYNC, see the first-run dashboard, run the readiness check, walk the setup
wizard up to the connection step, and open the Settings, Mapping and Logs screens. Nothing is sent anywhere. To see
a full sync, run Odoo locally (about ten minutes):

1. **Start Odoo with Docker**, using the official image and the commands from its Docker Hub page. The image tags
   `17.0`, `18.0` and `19.0` exist; use `19.0` (JSON-2 API) and, to see the other transport, `18.0`:

   ```bash
   docker run -d -e POSTGRES_USER=odoo -e POSTGRES_PASSWORD=odoo -e POSTGRES_DB=postgres --name db postgres:15
   docker run -d -p 127.0.0.1:8069:8069 --name odoo --link db:db odoo:19.0
   ```

   Odoo is then at http://localhost:8069. Create a database (choose demo data to get sample products), and install
   the apps **Sales**, **Inventory** and **Invoicing**. (The container alias of the database must be `db`.)
2. **Create an API key in Odoo:** user menu > My Profile (Preferences) > Account Security > New API Key. Copy it.
3. **Allow a local address in WordPress.** The plugin refuses private and local addresses by default. For this test
   add a must-use plugin, `wp-content/mu-plugins/estasoft-allow-local.php`:

   ```php
   <?php
   add_filter( 'estasoft_sync_allow_private_hosts', '__return_true' );
   ```
4. **Prepare WordPress:** WordPress 6.4 or later, PHP 8.1 or later, WooCommerce active. Add to `wp-config.php`:
   `define( 'ESTASOFT_SYNC_KEY', 'any long random string of 32 or more characters' );`
5. **Connect:** WooCommerce > ESTASOFT SYNC > setup. Address `http://localhost:8069`, the database name, the login
   (the Odoo user's email), and the API key. Press Test connection: it reports the version and the permissions.
6. **Create a WooCommerce product** whose SKU equals an Odoo product's Internal Reference, then place an order for
   it. In the plugin, the order appears in Queue and becomes Synced; in Odoo it appears under Sales > Orders with
   the customer reference `WC-<order id>`. Place a refund to see a credit note. Use **Preview** in setup to see
   the exact data before it is sent, and shadow mode to run without changing anything in Odoo.
7. **Failures:** use an unknown SKU to see Needs attention with the code `SYNC-MAP-001`; stop the Odoo container to
   see retries and the connection pause.

Clean up: `docker rm -f odoo db`.

---

## 7. Other points reviewers ask about

---

- **Nothing runs at file load** except the `ABSPATH` guard and declarations. The main file checks the PHP version
  first, before any class is loaded, and checks WooCommerce on `plugins_loaded`.
- **No `eval`, `base64_decode` of code, `extract`, `@` suppression, `error_reporting`, `ini_set`, shell calls or
  remote includes** in the runtime code. `bin/lint-php.sh` enforces this in CI.
- **Requires Plugins: woocommerce.** On WordPress 6.4 (before the header is enforced) the plugin shows a notice
  and does not start when WooCommerce is missing.
- **HPOS and the block checkout** are declared compatible with `FeaturesUtil::declare_compatibility`.
- **Admin notices** concern only the plugin's own state, are dismissible or clear themselves, and say how to fix
  the problem. There is no advertising and no upgrade prompt.
- **Nothing is output on the public site.**
- **Trademarks:** WooCommerce and Odoo are named only in text, with a disclaimer; no vendor logos ship in 0.1.0.
- **The plugin is English only.** Strings use the text domain `estasoft-erp-crm-sync-engine`; a `.pot` file is
  generated with `bin/make-pot.sh`.

---
