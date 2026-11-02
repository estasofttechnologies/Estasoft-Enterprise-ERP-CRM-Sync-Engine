# WordPress.org plugin guidelines: audit

Checked against the 18 guidelines at
<https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/> (read on 2 Nov 2026, after the
connector build: 37 connector documents, 35 selectable). Re-read the page on the day of submission; the Plugins team
may change it. The commands named below were run on 2 Nov 2026; [PLUGIN-CHECK-NOTES.md](PLUGIN-CHECK-NOTES.md) holds
their results, and [SMOKE-TEST.md](SMOKE-TEST.md) the install test of the release zip.

How to read the status column:

- **Met**: the repository complies and a command or file shows it.
- **Met, owner confirms**: it complies, but the confirmation is a fact only the owner can state (rights, a link, an account).
- **Owner action**: something only the owner can do or decide ([OWNER-ACTIONS.md](OWNER-ACTIONS.md)).

What changed since the first audit: the plugin now ships 37 connectors in one plugin (35 selectable, 2 listed and not
selectable), so guidelines 2, 6, 8, 16 and 17 were re-checked against that scope; `readme.txt` has an External services
entry for every service, generated from the connector documents and checked in CI.

| # | Guideline | How this plugin complies | Status |
| --- | --- | --- | --- |
| 1 | Plugins must be compatible with the GNU General Public License | The plugin is GPL-2.0-or-later (plugin header, `readme.txt`, `composer.json`, `package.json`, `LICENSE`). It bundles no third-party PHP library: `includes/` is the plugin's own code (919 PHP files) and the main file, `uninstall.php` and `languages/index.php` are the only other PHP. The generated data tables are the plugin's own: `includes/Catalog/catalog.php` and `includes/Connectors/providers.php` are generated from the repository's own documents; `includes/Mapping/Transforms/Data/` (countries, calling codes, subdivisions, Latin folding) is a compilation of public reference codes (ISO 3166, ITU E.164), with no data file, list or code copied from another project (no libphonenumber, no CLDR). `build/index.js` contains the app and no vendor library: the production dependency tree is 120 npm packages (87 MIT, 30 GPL-2.0-or-later, tslib 0BSD, mousetrap Apache-2.0, gradient-parser MIT), all GPL-compatible, and none of the third-party ones is inside the bundle (checked: no `tslib`, `mousetrap` or `gradient-parser` string in `build/index.js`). React and the WordPress packages are WordPress's own. The zip holds no image, font or icon. | Met, owner confirms. The owner confirms that Estasoft holds the rights to the icon and banner in `.wordpress-org/` (they are not in the zip) and that nobody else holds rights to the data in `includes/Mapping/Transforms/Data/` beyond the public codes. |
| 2 | Developers are responsible for the contents and actions of their plugins | Every file is in the repository and reviewed; the release zip is built from it by `bin/build-zip.sh`, and `bin/smoke-install.sh` installs that exact zip. The plugin contacts only a system the merchant connects: the hosts of each service are listed in `readme.txt` (External services) and in [EXTERNAL-SERVICES.md](EXTERNAL-SERVICES.md), generated from the connector documents (`php bin/generate-external-services.php --check` in CI). Vendor facts are in `docs/connectors/` with confidence tags. | Met. **Owner action:** read each vendor's API terms (Pennylane's already stops one connector, see 16) and open every link in [EXTERNAL-SERVICES.md](EXTERNAL-SERVICES.md) that is marked "not confirmed". |
| 3 | A stable version of a plugin must be available from its plugin directory page | Releases are deployed to WordPress.org from the same tag and the same verified zip that GitHub gets (`.github/workflows/release.yml`). The plugin has no update mechanism of its own and downloads nothing. | Met. **Owner action:** approve the deploy job promptly so GitHub never carries a newer version than the directory. |
| 4 | Code must be (mostly) human readable | No obfuscation, no minified PHP. `php bin/red-flags.php` (a token scan, not a text search) finds no `eval`, `base64_decode`, `extract`, `@`, `error_reporting`, `ini_set`, shell call, remote include or stray `exit` in 921 runtime files. The admin app is compiled by `@wordpress/scripts`; the readable TypeScript source and the build steps (`npm ci`, `npm run build`) are in the public repository, which `readme.txt` links under "Third-party libraries and services". | Met. **Owner action:** make the GitHub repository public before submission, or the source link in the readme is dead. If reviewers prefer the source inside the zip, remove `/src` from `.distignore` and add `/package.json`, `/package-lock.json` and `/webpack.config.js`. |
| 5 | Trialware is not permitted | Every feature is free. No licence key, tier, trial, quota, locked screen or sandbox-only code exists. `grep -rniE "licen[cs]e key|premium|trial|upsell|upgrade to|pro version" includes src` has five hits, all text about a vendor's own plans (the Zoho Books call allowance of its "Premium" plan, and the setup hints that a free trial of Wafeq or sevDesk is enough to get an API key); none is a plugin feature. The plugin never checks a licence with a server. | Met. |
| 6 | Software as a Service is permitted | The plugin is an interface to the merchant's own system. It has substantial functionality of its own that runs on the site: queue, worker, mapping engine, preview, shadow mode, logs, reconciliation, export files for DATEV, Sage 50 and TallyPrime, and every admin screen. It does not validate licences and is not a storefront. The services are documented in `readme.txt` under `== External services ==`: for each one what it is used for, the data sent, when, the hosts, and links to its terms and privacy policy (SuiteCRM, which publishes no terms for software that the merchant runs on their own server, says so in words). 32 services are contacted over the network, 3 make a file and contact nothing, 2 cannot be selected. | Met. See `SUBMISSION-NOTES.md` for what a reviewer can see without a real ERP. **Owner action:** confirm the links marked "not confirmed" in [EXTERNAL-SERVICES.md](EXTERNAL-SERVICES.md). |
| 7 | Plugins may not track users without their consent | No telemetry, analytics, ad tracking or update ping. Outbound requests go only through `Core\Support\WpHttpClient`, to a system the merchant connected, after the merchant entered credentials (a deliberately configured service). `php bin/red-flags.php` fails on any other `wp_remote_*`, curl or socket call. `bin/smoke-install.sh` records every outbound request across install, activation, the REST and admin checks and uninstall with a guard that blocks them: the count is zero. | Met. |
| 8 | Plugins may not send executable code via third-party systems | Nothing is downloaded or run from elsewhere. No CDN script, style or font, no iframe, no installer or updater. The compiled app loads from the plugin folder; its dependencies are WordPress script handles, all served by the site (checked in the smoke test). `grep -o 'https\?://[A-Za-z0-9./_-]*' build/index.js` finds a GitHub link and `https://localhost/` (a base for URL parsing) only. All connector traffic is data, over the vendor's API. | Met. |
| 9 | Do nothing illegal, dishonest, or morally offensive | The readme makes no unverified claim, no performance number and no "best". It says in the description that no connector has been tried with a live account yet and tells the merchant to use Shadow mode first. It never claims a store is compliant with a tax authority; invoices are issued by the connected system. No fake reviews, no review pressure. The one connector whose legal position is open (Pennylane) is not offered. | Met. **Owner action:** do not solicit or buy reviews; do not use more than one account. |
| 10 | No external links or credits on the public site without permission | The plugin outputs nothing on the public front end: no `wp_head`, `wp_footer`, shortcode, block, widget or template hook (grep of `includes/`). | Met. |
| 11 | Do not hijack the admin dashboard | Notices are limited to the plugin's own state. Those that show on every admin screen are the welcome notice (dismissible), the missing encryption key and a sign-in that stopped working (both clear themselves when fixed and say how to fix it); failed orders, a backlog and "Shadow mode ends" show only on the plugin's own pages. No advertising, no upgrade prompt, no referral link. The activation redirect to setup happens once, for a single-site interactive activation only. The dashboard box shows queue status and can be hidden in Screen Options. The admin bar item is on by default and has a setting to turn it off. | Met. |
| 12 | Readmes must not spam | Five tags (woocommerce, erp, crm, accounting, sync), no competitor tags, no affiliate links, no keyword stuffing. Vendor names appear to say which systems the plugin works with, in a list and in the External services section, which the guidelines require. `php bin/check-readme.php` checks the tag count, banned wording and referral parameters. | Met. |
| 13 | Plugins must use WordPress' default libraries | No bundled copy of jQuery, PHPMailer, SimplePie or similar. HTTP goes through the WordPress HTTP API; the admin app uses the WordPress React and components (`build/index.asset.php` lists `wp-a11y`, `wp-api-fetch`, `wp-components`, `wp-element`, `wp-i18n`; no `react-dom`, `jQuery` or similar string in `build/index.js`); encryption uses libsodium from PHP. | Met. |
| 14 | Frequent commits to a plugin should be avoided | The SVN repository only receives release-ready code: one deploy per tag by the release workflow. Development happens on GitHub. | Met. |
| 15 | Plugin version numbers must be incremented for each new release | `bin/bump-version.sh` sets the header, constant, Stable tag, `package.json` and changelog together; `bin/build-zip.sh` refuses to build when they disagree or when the tag differs. | Met. |
| 16 | A complete plugin must be available at the time of submission | The plugin is complete as described: the engine, every admin screen and 35 selectable connectors. Two catalog entries are listed and cannot be selected, and say why on the screen: SMACC (publishes no API) and Pennylane (needs a sign-in app for production and its API terms limit use). Their code does nothing until `available` is set. No name is being reserved. | Met. **Owner action:** the connectors have been tested against simulated copies of the vendors' APIs only; run the smoke test of each connector document on a vendor sandbox before or soon after submission and flip `verified` (see OWNER-ACTIONS.md). The readme says plainly that none has been tried live. |
| 17 | Plugins must respect trademarks, copyrights, and project names | The slug and name begin with Estasoft, the owner's own brand, not with a third-party mark. WooCommerce and the vendors are named only in text, to say which systems the plugin works with, with a disclaimer in the readme under "Trademarks" and in `README.md`. No vendor logos ship (the zip holds no image; the catalog has names only). See the trademark notes and the list of 34 below. | Met. **Owner action:** vendor brand terms before any logo is added; confirm the Estasoft name is the owner's to use if the team asks. |
| 18 | We reserve the right to maintain the Plugin Directory | Acknowledged. A security contact exists (`SECURITY.md`); the WordPress.org account email must be monitored. | **Owner action:** keep the account email current, turn on two-factor authentication on the WordPress.org account, and answer review emails. |

## Other checks the reviewers make

| Topic | Result |
| --- | --- |
| No direct file writes outside uploads | The only writes are the export files and their folders under `wp-content/uploads/estasoft-sync/` (`ExportStore`, one work file in `LedgerExportConnector`), each with a `phpcs:ignore` that says why; nothing is written to the plugin folder. |
| Capabilities and nonces | Two capabilities, `estasoft_sync_manage` and `estasoft_sync_configure`. Every REST route has a permission callback; only the signed inbound webhook is open to everyone (it checks an HMAC). Both `admin-post` actions and the product box check a nonce and a capability. There is no `admin-ajax` action. See [PLUGIN-CHECK-NOTES.md](PLUGIN-CHECK-NOTES.md). |
| Uninstall | Data is kept on deactivation and on deletion unless "Delete all data when the plugin is deleted" is on; then the nine tables, options, meta, capabilities and the exports folder go. Both paths are in the smoke test. |
| Internationalisation | Every string uses the text domain `estasoft-erp-crm-sync-engine`; no `.pot` file is committed yet (needs WP-CLI: `bin/make-pot.sh`, an owner action). |
| Requirements | `Requires Plugins: woocommerce`; a guard shows a notice instead of a fatal error when PHP, WordPress, sodium or WooCommerce is missing (smoke test). |

## Trademark and name notes

- **Plugin name and slug.** "Estasoft Enterprise ERP/CRM Sync Engine", slug `estasoft-erp-crm-sync-engine`. "ERP" and
  "CRM" are generic terms. The name does not contain "Woo", "WooCommerce", "Odoo" or any vendor name.
  **Owner action:** the slug is only reserved when the plugin is approved. Open
  <https://wordpress.org/plugins/estasoft-erp-crm-sync-engine/> before submitting; a "not found" page means the
  slug is free.
- **WooCommerce** is a trademark of Automattic Inc. The plugin needs WooCommerce (`Requires Plugins: woocommerce`)
  and says so; the name does not start with or imitate it.
- **Vendors.** Names appear only as text: in the readme (supported systems, External services), in the setup
  screen's list of systems and in documents. The readme disclaims affiliation and endorsement. The tag `odoo` was
  removed from the readme so that no vendor name is used as a tag.
- **Logos.** The design boards use vendors' own artwork for 23 of the 34 systems (the other 11 use a letter
  tile). **The shipped plugin has no logo of any vendor** (no image in the zip), so nothing needs clearing for 0.1.0.
  Before any logo is added to the Choose a system screen, check that vendor's brand or logo terms, keep a record of
  the permission, and use the vendor's current artwork unaltered and no larger than the terms allow. The safe
  fallback is the system's name in text. This is an **open owner task** for all 34.

### The 34 systems

Trademark owners are given from general knowledge and must be confirmed on each vendor's own page. "Artwork
supplied" means a logo file exists in the design project; it does not mean permission has been checked. The
connector column shows the state of this release (from `includes/Catalog/catalog.php`).

| # | System | Type | Trademark owner (to confirm) | Artwork supplied | Connector in 0.1.0 | Brand terms |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | QuickBooks Online | Accounting | Intuit Inc. | Yes | Yes | Owner to find and record |
| 2 | Xero | Accounting | Xero Limited | Yes | Yes | Owner to find and record |
| 3 | Zoho Books | Accounting | Zoho Corporation | Yes | Yes | Owner to find and record |
| 4 | MYOB | Accounting | MYOB Group | Yes | Yes | Owner to find and record |
| 5 | Sage Business Cloud Accounting and Sage 50 | Accounting | The Sage Group plc | Yes | Yes (Sage Business Cloud Accounting online; Sage 50 as a file) | Owner to find and record |
| 6 | Qoyod | Accounting | Qoyod | No (letter tile) | Yes | Owner to find and record |
| 7 | Wafeq | Accounting | Wafeq | No (letter tile) | Yes | Owner to find and record |
| 8 | Daftra | Accounting | Daftra | No (letter tile) | Yes | Owner to find and record |
| 9 | SMACC | Accounting | SMACC | Yes | **No, listed but not selectable** | Owner to find and record |
| 10 | TallyPrime | Accounting | Tally Solutions Pvt. Ltd. | No (letter tile) | Yes (a file you import; nothing is sent) | Owner to find and record |
| 11 | DATEV | Accounting | DATEV eG | Yes | Yes (a file you import; nothing is sent) | Owner to find and record |
| 12 | Lexware Office | Accounting | Haufe-Lexware GmbH and Co. KG | No (letter tile) | Yes | Owner to find and record |
| 13 | sevDesk | Accounting | sevDesk GmbH | No (letter tile) | Yes | Owner to find and record |
| 14 | Exact Online | Accounting | Exact Group | Yes | Yes | Owner to find and record |
| 15 | Moneybird | Accounting | Moneybird B.V. | Yes | Yes | Owner to find and record |
| 16 | Pennylane | Accounting | Pennylane | No (letter tile) | **No, listed but not selectable** | Owner to find and record |
| 17 | Fortnox | Accounting | Fortnox AB | Yes | Yes | Owner to find and record |
| 18 | Odoo | ERP | Odoo S.A. | Yes | Yes | Owner to find and record |
| 19 | ERPNext | ERP | Frappe Technologies Pvt. Ltd. | Yes | Yes | Owner to find and record |
| 20 | Microsoft Dynamics 365 Business Central | ERP | Microsoft Corporation | Yes | Yes | Owner to find and record |
| 21 | NetSuite | ERP | Oracle Corporation | Yes | Yes | Owner to find and record |
| 22 | SAP Business One | ERP | SAP SE | Yes | Yes | Owner to find and record |
| 23 | SAP S/4HANA | ERP | SAP SE | Yes | Yes | Owner to find and record |
| 24 | Oracle Fusion Cloud ERP | ERP | Oracle Corporation | Yes | Yes | Owner to find and record |
| 25 | Dynamics 365 Finance and Supply Chain | ERP | Microsoft Corporation | No (letter tile) | Yes | Owner to find and record |
| 26 | Sage 200, Sage 300 and Sage X3 | ERP | The Sage Group plc | Yes | Yes (three connectors) | Owner to find and record |
| 27 | Acumatica | ERP | Acumatica, Inc. | No (letter tile) | Yes | Owner to find and record |
| 28 | Holded | ERP | Holded | No (letter tile) | Yes | Owner to find and record |
| 29 | HubSpot | CRM | HubSpot, Inc. | Yes | Yes | Owner to find and record |
| 30 | Zoho CRM | CRM | Zoho Corporation | Yes | Yes | Owner to find and record |
| 31 | Salesforce | CRM | Salesforce, Inc. | Yes | Yes | Owner to find and record |
| 32 | Dynamics 365 Sales | CRM | Microsoft Corporation | No (letter tile) | Yes | Owner to find and record |
| 33 | Pipedrive | CRM | Pipedrive | Yes | Yes | Owner to find and record |
| 34 | SuiteCRM | CRM | SalesAgility Ltd. | Yes | Yes | Owner to find and record |

Counts: 17 accounting, 11 ERP, 6 CRM; 23 with supplied artwork, 11 without (from
[../design/uiux-status.md](../design/uiux-status.md)); 32 of the 34 entries have a selectable connector (37
connector documents in all, because the Sage entries hold five products). The names, types and flags are the ones in
`includes/Catalog/catalog.php`.

## Reviewer-facing risks to be ready for

- **"The plugin has 37 connectors in one plugin."** Answer: the plugin is one engine with a connector contract, and
  a connector is built only when its system is used (lazy registration in `ConnectorsModule`); the others cost nothing
  and contact nothing. One zip keeps one queue, one mapping engine and one set of screens for a store that may change
  system. The text is in `SUBMISSION-NOTES.md`.
- **"The plugin lists systems it cannot connect to."** Answer: two entries are shown as not available with the
  reason, so a merchant is not told to wait for a connector that cannot work.
- **"Needs an external service to do anything."** Answer: so does any ERP connector; it is guideline 6 and is
  documented in the readme; reviewer test instructions without a paid ERP (Odoo Community in Docker) are in
  `SUBMISSION-NOTES.md`.
- **"Why custom capabilities?"** Answer in `SUBMISSION-NOTES.md`.
- **"Compiled JavaScript."** Answer: source in the public repository, build steps in the readme.
- **"No connector has been tested live."** Answer: true, and the readme and the setup screen say so; Shadow mode
  (changes nothing in either system) is the first step of the setup.
