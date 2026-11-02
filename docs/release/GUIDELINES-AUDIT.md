# WordPress.org plugin guidelines: audit

Checked against the 18 guidelines at
<https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/> (read on 2 Nov 2026). Re-read
the page on the day of submission; the Plugins team may change it.

How to read the status column:

- **Met**: the repository already complies and a command or file shows it.
- **Met by design, verify**: the design complies, but the code that proves it is still being written or reviewed;
  the check to run is named.
- **Owner action**: something only the owner can do or decide.

| # | Guideline | How this plugin complies | Status |
| --- | --- | --- | --- |
| 1 | Plugins must be compatible with the GNU General Public License | The plugin is GPL-2.0-or-later (header, `readme.txt`, `composer.json`, `package.json`, `LICENSE`). It bundles no third-party PHP library. The compiled admin app contains only code from npm packages under GPL-compatible licences (MIT and similar); WordPress's own React and component packages are used from WordPress, not bundled. Images are Estasoft's own. No vendor logo is shipped. | Met. **Verify at release:** list the licences of the packages that end up in `build/` (for example `npx license-checker --production --summary`) and confirm that Estasoft holds the rights to the icon and banner artwork. **Owner action** for the rights confirmation. |
| 2 | Developers are responsible for the contents and actions of their plugins | Every file is in the repository and reviewed. The one outside service, Odoo, is documented in the readme with what is sent, when and why, and links to its terms and privacy policy. Vendor facts are kept in `docs/research/` with confidence tags. | Met. **Owner action:** read the current terms of Odoo (and of every vendor whose connector ships later) and confirm they allow API access by a third-party plugin; open one link check of the readme's external links. |
| 3 | A stable version of a plugin must be available from its plugin directory page | Releases are deployed to WordPress.org from the same tag and the same verified zip that GitHub gets (`.github/workflows/release.yml`). The plugin has no update mechanism of its own and downloads nothing. | Met. **Owner action:** approve the deploy job promptly so GitHub never carries a newer version than the directory. |
| 4 | Code must be (mostly) human readable | No obfuscation. `bin/lint-php.sh` fails on `eval`, `base64_decode`, `extract`, `@` suppression and other red flags. The admin app is compiled by `@wordpress/scripts`; the readable TypeScript source and the build steps (`npm ci`, `npm run build`) are in the public repository, which `readme.txt` links under "Third-party libraries and services". | Met by design. **Owner action:** make the GitHub repository public before submission, or the source link in the readme is dead. If reviewers prefer the source inside the zip, remove `/src` from `.distignore` (and add `/package.json`, `/package-lock.json`, `/webpack.config.js`). |
| 5 | Trialware is not permitted | Every feature is free. No licence key, tier, trial, quota, locked screen or sandbox-only API access exists, and none is planned. | Met. **Verify:** `grep -rniE "licen[cs]e key|premium|trial|upsell" includes src` finds no licence, tier or upsell code (review every match). |
| 6 | Software as a Service is permitted | The plugin is an interface to the merchant's own system (Odoo), which the readme documents. It has substantial functionality of its own: queue, worker, mapping engine, preview, logs, reconciliation and all admin screens, all running on the site. It does not validate licences and is not a storefront. | Met. See `SUBMISSION-NOTES.md` for what a reviewer can see without a real Odoo. |
| 7 | Plugins may not track users without their consent | No telemetry, analytics, ad tracking or update ping. The only outbound requests go through `Core\Support\WpHttpClient` to the address the merchant entered, after the merchant entered credentials (a deliberately configured service). `bin/lint-php.sh` fails on any other `wp_remote_*`, curl or socket call. | Met. Run `bin/lint-php.sh`. |
| 8 | Plugins may not send executable code via third-party systems | Nothing is downloaded or run from elsewhere. No CDN script, style or font, no iframe, no installer or updater, no premium-version installer. Connectors ship inside the plugin or as separate plugins on WordPress.org. | Met by design. **Verify** after the admin app lands: `grep -rn "https\?://" build/ src/ includes/` shows documentation links only. |
| 9 | Do nothing illegal, dishonest, or morally offensive | The readme makes no unverified claim, no performance numbers and no "best". It never claims a store is compliant with a tax authority; invoices are issued by the connected system. No fake reviews, no review pressure. | Met. **Owner action:** do not solicit or buy reviews; do not use more than one account. |
| 10 | No external links or credits on the public site without permission | The plugin outputs nothing on the public front end: no "Powered by", no links. | Met by design. **Verify** after the Woo and Admin modules land: no `wp_footer`, `wp_head` or shortcode output. |
| 11 | Do not hijack the admin dashboard | Notices are limited to the plugin's own state (a connection is paused, a key is about to expire, a setup step is open), are dismissible or clear themselves, and say how to fix the problem. No advertising or upgrade prompt. The activation redirect to setup happens once, only for a single-site interactive activation. The WordPress dashboard widget shows queue status and can be hidden in Screen Options. | Met by design, verify. **Verify** with the finished `Admin\Notices`, `Admin\Welcome` and `Admin\DashboardWidget`. |
| 12 | Readmes must not spam | Five tags (erp, crm, woocommerce, odoo, sync), no competitor tags, no affiliate links, no keyword stuffing. | Met. `php bin/check-readme.php` checks the tag count. |
| 13 | Plugins must use WordPress' default libraries | No bundled copy of jQuery, PHPMailer, SimplePie or similar. HTTP goes through the WordPress HTTP API; the admin app uses WordPress's React and components; encryption uses libsodium from PHP. | Met by design. **Verify** after the build: `build/index.asset.php` lists `wp-*` handles for React and the `@wordpress` packages, and `grep -c "react-dom" build/index.js` finds no bundled React. |
| 14 | Frequent commits to a plugin should be avoided | The SVN repository only receives release-ready code: one deploy per tag by the release workflow, with a descriptive tag name. Development happens on GitHub. | Met. |
| 15 | Plugin version numbers must be incremented for each new release | `bin/bump-version.sh` sets the header, constant, Stable tag, `package.json` and changelog together; `bin/build-zip.sh` refuses to build when they disagree or when the tag differs. | Met. |
| 16 | A complete plugin must be available at the time of submission | Phase 1 is the full engine, the Odoo connector and every admin screen. The setup screen lists 34 systems but only Odoo is available, and the catalog marks the other 33 `available => false`. | Met by design. **Owner action:** decide whether unavailable systems are labelled "Coming soon" or hidden (open design decision 3 in `scope-update.md`), and submit only after the plugin has been tried against real Odoo 17, 18 and 19. No name is being reserved. |
| 17 | Plugins must respect trademarks, copyrights, and project names | The slug and name begin with Estasoft, the owner's own brand, not with a third-party mark. WooCommerce, Odoo and the other vendors are named only in text to say what the plugin works with, with a disclaimer in the readme and README. No vendor logos ship in version 0.1.0 (the catalog has names only). See the trademark notes and the list of 34 below. | Met for 0.1.0. **Owner action:** vendor brand terms before any logo is added; confirm the Estasoft name is the owner's to use if the team asks. |
| 18 | We reserve the right to maintain the Plugin Directory | Acknowledged. A security contact exists (`SECURITY.md`); the WordPress.org account email must be monitored. | **Owner action:** keep the account email current, turn on two-factor authentication on the WordPress.org account, and answer review emails. |

## Trademark and name notes

- **Plugin name and slug.** "Estasoft Enterprise ERP/CRM Sync Engine", slug `estasoft-erp-crm-sync-engine`. "ERP" and
  "CRM" are generic terms. The name does not contain "Woo", "WooCommerce", "Odoo" or any vendor name.
  **Owner action:** the slug is only reserved when the plugin is approved. Open
  <https://wordpress.org/plugins/estasoft-erp-crm-sync-engine/> before submitting; a "not found" page means the
  slug is free.
- **WooCommerce** is a trademark of Automattic Inc. The plugin needs WooCommerce (`Requires Plugins: woocommerce`)
  and says so; the name does not start with or imitate it.
- **Odoo** is a trademark of Odoo S.A. Used in text only ("Odoo connector", the tag `odoo`).
- **Other vendors.** Names appear only as text in the setup screen's list of systems and in documents. The readme
  disclaims affiliation and endorsement.
- **Logos.** The design boards use vendors' own artwork for 23 of the 34 systems (the other 11 use a letter
  tile). **The shipped catalog has only names, so nothing needs clearing for 0.1.0.** Before any logo is added to the
  Choose a system screen, check that vendor's brand or logo terms, keep a record of the permission, and use the
  vendor's current artwork unaltered and no larger than the terms allow. WordPress.org reviewers look closely at
  third-party trademarks; the safe fallback is the system's name in text. This is an **open owner task** for all 34.

### The 34 systems

Trademark owners are given from general knowledge and must be confirmed on each vendor's own page. "Artwork
supplied" means a logo file exists in the design project; it does not mean permission has been checked. The
connector column shows the state of this release.

| # | System | Type | Trademark owner (to confirm) | Artwork supplied | Connector in 0.1.0 | Brand terms |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | QuickBooks Online | Accounting | Intuit Inc. | Yes | No | Owner to find and record |
| 2 | Xero | Accounting | Xero Limited | Yes | No | Owner to find and record |
| 3 | Zoho Books | Accounting | Zoho Corporation | Yes | No | Owner to find and record |
| 4 | MYOB | Accounting | MYOB Group | Yes | No | Owner to find and record |
| 5 | Sage Business Cloud Accounting and Sage 50 | Accounting | The Sage Group plc | Yes | No | Owner to find and record |
| 6 | Qoyod | Accounting | Qoyod | No (letter tile) | No | Owner to find and record |
| 7 | Wafeq | Accounting | Wafeq | No (letter tile) | No | Owner to find and record |
| 8 | Daftra | Accounting | Daftra | No (letter tile) | No | Owner to find and record |
| 9 | SMACC | Accounting | SMACC | Yes | No | Owner to find and record |
| 10 | TallyPrime | Accounting | Tally Solutions Pvt. Ltd. | No (letter tile) | No | Owner to find and record |
| 11 | DATEV | Accounting | DATEV eG | Yes | No | Owner to find and record |
| 12 | Lexware Office | Accounting | Haufe-Lexware GmbH and Co. KG | No (letter tile) | No | Owner to find and record |
| 13 | sevDesk | Accounting | sevDesk GmbH | No (letter tile) | No | Owner to find and record |
| 14 | Exact Online | Accounting | Exact Group | Yes | No | Owner to find and record |
| 15 | Moneybird | Accounting | Moneybird B.V. | Yes | No | Owner to find and record |
| 16 | Pennylane | Accounting | Pennylane | No (letter tile) | No | Owner to find and record |
| 17 | Fortnox | Accounting | Fortnox AB | Yes | No | Owner to find and record |
| 18 | Odoo | ERP | Odoo S.A. | Yes | **Yes** | Owner to find and record |
| 19 | ERPNext | ERP | Frappe Technologies Pvt. Ltd. | Yes | No | Owner to find and record |
| 20 | Microsoft Dynamics 365 Business Central | ERP | Microsoft Corporation | Yes | No | Owner to find and record |
| 21 | NetSuite | ERP | Oracle Corporation | Yes | No | Owner to find and record |
| 22 | SAP Business One | ERP | SAP SE | Yes | No | Owner to find and record |
| 23 | SAP S/4HANA | ERP | SAP SE | Yes | No | Owner to find and record |
| 24 | Oracle Fusion Cloud ERP | ERP | Oracle Corporation | Yes | No | Owner to find and record |
| 25 | Dynamics 365 Finance and Supply Chain | ERP | Microsoft Corporation | No (letter tile) | No | Owner to find and record |
| 26 | Sage 200, Sage 300 and Sage X3 | ERP | The Sage Group plc | Yes | No | Owner to find and record |
| 27 | Acumatica | ERP | Acumatica, Inc. | No (letter tile) | No | Owner to find and record |
| 28 | Holded | ERP | Holded | No (letter tile) | No | Owner to find and record |
| 29 | HubSpot | CRM | HubSpot, Inc. | Yes | No | Owner to find and record |
| 30 | Zoho CRM | CRM | Zoho Corporation | Yes | No | Owner to find and record |
| 31 | Salesforce | CRM | Salesforce, Inc. | Yes | No | Owner to find and record |
| 32 | Dynamics 365 Sales | CRM | Microsoft Corporation | No (letter tile) | No | Owner to find and record |
| 33 | Pipedrive | CRM | Pipedrive | Yes | No | Owner to find and record |
| 34 | SuiteCRM | CRM | SalesAgility Ltd. | Yes | No | Owner to find and record |

Counts: 17 accounting, 11 ERP, 6 CRM; 23 with supplied artwork, 11 without (from
[../design/uiux-status.md](../design/uiux-status.md)); 1 connector in this release. The names, types and the
connector flag are the ones in `includes/Catalog/catalog.php`.

## Reviewer-facing risks to be ready for

- **"The plugin lists systems it cannot connect to."** Answer: the list is the product roadmap shown honestly; 33 are
  marked not available. Decide the label (guideline 16 row).
- **"Needs an external service to do anything."** Answer: so does any ERP connector; it is guideline 6 and is
  documented in the readme; reviewer test instructions are in `SUBMISSION-NOTES.md`.
- **"Why custom capabilities?"** Answer in `SUBMISSION-NOTES.md`.
- **"Compiled JavaScript."** Answer: source in the public repository, build steps in the readme.
