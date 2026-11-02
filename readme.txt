=== Estasoft Enterprise ERP/CRM Sync Engine ===
Contributors: estasofttechnologies
Tags: erp, crm, woocommerce, odoo, sync
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps WooCommerce in step with your ERP, accounting system or CRM through a queue, so checkout never waits. Orders, customers, stock and tracking.

== Description ==

Estasoft Enterprise ERP/CRM Sync Engine sends WooCommerce orders, customers and refunds to the system your business runs on, and reads stock levels and delivery tracking back. Every order event is saved to a queue in your own database, in the same step that saves the order. A background worker delivers the queue, so a slow or offline ERP never holds up a customer at checkout.

The plugin is free, with every feature included, and it stays free: no paid tier, licence key, trial or usage limit. The screens are in English; the plugin is ready for translation.

= Features =

* **Durable queue.** Failed deliveries retry with growing delays. If the other system keeps failing, the connection pauses and orders wait instead of being lost.
* **No duplicates.** Each order carries a reference (WC-{order number}) that is looked up before anything is created, so a retry does not make a second copy.
* **Preview before sending.** See exactly what your system will receive for a real order before anything is sent.
* **Mapping you can read.** Rules that turn WooCommerce fields into the other system's fields, with tax mapping, SKU matching, versions and rollback. No code to write.
* **Shadow mode.** Run the whole process for a trial period without changing anything in either system, and compare the results.
* **Needs attention.** Failed orders are grouped by cause, with a plain explanation and a fix for each error code.
* **Stock and tracking back** into the store, and a nightly reconciliation that lists differences, where the connected system supports them.
* **Dashboard, logs and readiness check**, a support report that leaves secrets out, and email alerts.
* **Encrypted credentials.** Keys and passwords are stored encrypted. The encryption key lives in wp-config.php.
* **WooCommerce compatible.** High-performance order storage (HPOS) and the block checkout are declared compatible, and WooCommerce data is read through its own CRUD API only.

= Systems =

Version 0.1.0 includes the connector for **Odoo** (versions 17, 18, 19 and later). The setup screen lists 34 accounting, ERP and CRM systems; connectors for the other 33 are planned and are not part of this version. Other developers can add connectors through the documented connector contract.

= Trademarks =

WooCommerce is a trademark of Automattic Inc. Odoo is a trademark of Odoo S.A. All other product names belong to their owners. They are used here only to say which systems the plugin works with. This plugin is independent and is not made, endorsed or sponsored by any of them.

== External services ==

The plugin has no telemetry, no analytics, no update server and no Estasoft server. It contacts only the system you connect, and only after you have entered that system's address and credentials. Nothing is sent to anyone else.

**Odoo** (your own Odoo server, Odoo Online or Odoo.sh), using the address you enter.

* What is sent: your login and API key (to sign in); the order, customer and product data that your mapping selects, such as order number, date, status, totals, line items with SKU, quantity, price and tax, shipping, fees, coupons, name, email, phone, addresses, company and buyer tax number. Full card numbers are never read; the payment method, transaction ID and last four digits are sent only if you map them.
* What is read back: product matches, stock quantities, delivery tracking, invoice status and the data needed for the nightly comparison.
* When: when you press Test connection; when an order, refund or customer event is queued and delivered; on the scheduled stock, tracking and reconciliation checks; and when you press Sync again or Retry.
* Why: to create and keep up to date the sales orders, customers and credit notes in Odoo, and to bring stock and tracking back.
* Terms and privacy: for Odoo Online and Odoo.sh, the [Odoo legal documents](https://www.odoo.com/documentation/19.0/legal.html) and [Odoo privacy policy](https://www.odoo.com/privacy). If you run Odoo yourself, the data goes only to your own server.

Each connector added later will be listed here with the same details before it is released.

== Third-party libraries and services ==

* The plugin contains no third-party PHP library. Encryption uses libsodium, which is part of PHP.
* The admin screen is built with React and WordPress components that WordPress itself provides. They are not bundled.
* Background tasks use Action Scheduler as shipped with WooCommerce, and fall back to WP-Cron.
* The plugin loads no font, script, style or image from another site.
* Source code: the admin screen is written in TypeScript and compiled with @wordpress/scripts. The readable source and the build steps (`npm ci`, then `npm run build`) are in the public repository: https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine

== Installation ==

1. Install and activate WooCommerce 8.2 or later.
2. Install this plugin from the Plugins screen (or upload the zip) and activate it.
3. Add an encryption key to wp-config.php, above the line "That's all, stop editing!". The plugin shows a ready-made line to copy: `define( 'ESTASOFT_SYNC_KEY', 'a long random value' );`. Keep a copy of the key. Without it the saved credentials cannot be read and must be entered again.
4. Open WooCommerce > ESTASOFT SYNC and follow the setup: check your store, choose a system, connect, choose what to sync, preview an order, go live.
5. For Odoo, create an API key in Odoo under your user's Account Security. Odoo Online needs the Custom plan to allow API access. Odoo 19 keys can have an end date of at most three months, so renew them before they run out.

== Frequently Asked Questions ==

= Does it work with HPOS and the block checkout? =
Yes. Both are declared compatible, and orders are read through WooCommerce's CRUD functions, never with SQL on order tables.

= Which Odoo versions are supported? =
Odoo 17 and 18 through the JSON-RPC API, and Odoo 19 and later through the JSON-2 API. The plugin detects the version when you press Test connection.

= What is encrypted? =
API keys, passwords and client secrets are encrypted with libsodium before they are saved. The key is a constant in wp-config.php, not in the database. Order and customer data in the queue and log are not encrypted; the log redacts emails, phone numbers and tokens by default.

= Does the plugin send data to Estasoft? =
No. See External services.

= Does it sign or issue tax invoices? =
No. It sends orders to your system, and the certified invoicing module of that system issues the invoice. The plugin never claims that a store is compliant with any tax authority's rules.

= Does the plugin need WooCommerce? =
Yes. WordPress 6.5 and later enforce this through the Requires Plugins header. On WordPress 6.4 the plugin checks for WooCommerce itself and shows a notice.

= How do I remove everything? =
Deactivate and delete the plugin. Your queue, mappings and settings are kept by default; to remove them on deletion, turn on "Delete all data when the plugin is deleted" in the plugin's settings first.

= Where do I get help? =
In the support forum for this plugin on WordPress.org. Bug reports and connector requests are welcome on GitHub.

== Screenshots ==

1. The dashboard: what synced, what is waiting, what needs a person.
2. Needs attention: failed orders grouped by cause, fixed in one step.
3. Choose your system from 34, in one list.
4. Preview exactly what your system will receive before anything is sent.
5. Mapping with versions, preview and rollback.
6. The order screen box and the Sync column.

== Changelog ==

= 0.1.0 =
* First release: the queue and worker, the Odoo connector, mapping with preview and rollback, shadow mode, stock and tracking read-back, reconciliation, logs, and the admin screens.

== Upgrade Notice ==

= 0.1.0 =
First release.
