# Plugin Check notes

The official Plugin Check (the WordPress.org plugin and the GitHub action `WordPress/plugin-check-action`) cannot be
run on the build machine: wordpress.org is blocked. This page records the first real run on GitHub, how each category
was approximated with the tools that exist on the build machine, the result of each on 2 Nov 2026, and what only the
real tool can verify. The real run is the job `plugin-check` in `.github/workflows/plugin-check.yml`; read its summary
before submitting ([OWNER-ACTIONS.md](OWNER-ACTIONS.md), item 3).

Every command is run from the repository root. The release zip (not the working tree) is what the real tool checks;
`bin/build-zip.sh` and `bin/smoke-install.sh` make and install that zip.

## The first real run (3 Nov 2026)

The job `plugin-check` ran on GitHub against the release zip (WordPress latest, Plugin Check 2.1.0). It found what the
approximations on the build machine could not:

| Finding | What was done |
| --- | --- |
| 33 errors, `wp_function_not_compatible_with_requires_wp`: `array_is_list()` is read as a WordPress 6.5 function, and the plugin supports 6.4 (it runs on PHP 8.1, where the function exists natively). | Every call now goes through `Core\Support\Lists::isList()`, which gives the same answer (a unit test compares the two). `bin/red-flags.php` fails on a new call to `array_is_list()`. |
| Warning: `load_plugin_textdomain()` is discouraged for plugins hosted on WordPress.org. | Removed. WordPress.org loads the translations of a hosted plugin by itself. |
| Warning: the readme Description is cut at 2,500 words. WordPress.org shows every section it does not know (External services, Third-party libraries and services) under the Description and cuts the whole; External services alone was 2,576 words, so the last systems would have been hidden from the disclosure. | What is sent and when is the same for every system, so it is said once in the introduction of External services, and each entry keeps only what differs (what it is used for, what it sends, the hosts, the terms and privacy links). The whole is now 1,933 words. `bin/check-readme.php` counts the words the way the parser does and fails above 2,300. |
| 8 warnings, `PluginCheck.Security.DirectDB.UnescapedDBParameter`, in `Core\Repositories\Repository` and `Connectors\Support\OAuth\WpdbRefreshLock`: a statement built by `$wpdb->prepare()` is passed to `$wpdb`. | Marked with `phpcs:ignore` and the reason (the statement is prepared; the table names come from `$wpdb`). |

The first attempt of the job failed before it checked anything: the workflow added Plugin Check and WooCommerce to the
test site by itself while the action installs both, and the two copies stopped with "Cannot redeclare
wp_plugin_check_load()". The workflow now leaves both to the action.

## Results on the build machine

| Category of Plugin Check | Approximated with | Result |
| --- | --- | --- |
| Coding standards, security (escaping, sanitising, nonces, SQL, i18n, naming, PHP compatibility) | `vendor/bin/phpcs` (the full `WordPress` ruleset of `phpcs.xml.dist`, with `testVersion 8.1-`, on `includes/`, the main file and `uninstall.php`) | **Clean**: 921 files, no error and no warning. Every `phpcs:ignore` in the runtime code names its reason (file downloads, the plugin's own export files, `DROP TABLE` of its own tables). |
| Static analysis | `vendor/bin/phpstan analyse` (level 6, with the WordPress and WooCommerce stubs; `tests/phpstan-stubs.php` declares the few WP-CLI and Action Scheduler calls the plugin makes) | **Clean**: no error. PHPStan 1.12 and `szepeviktor/phpstan-wordpress` 1.3, as CI installs them. |
| Code that WordPress.org rejects (`eval`, `base64_decode`, `extract`, `@`, `error_reporting`, `ini_set`, shell calls, remote includes, stray `exit`, direct HTTP) | `php bin/red-flags.php` | **Passes**: 921 runtime files, none found; the only network code is `Core\Support\WpHttpClient`. |
| PHP version compatibility | `php bin/check-php-version.php` | **Passes**: 921 runtime files, no syntax, function or class from PHP 8.2 to 8.4. |
| Readme (`readme_*` checks of the real tool) | `php bin/check-readme.php` | **Passes**, 0 warnings: header fields, 5 tags, short description 146 characters, sections, changelog and upgrade notice for the stable tag, screenshot captions against `.wordpress-org/screenshot-1..6.png`, an External services entry (data sent, when, hosts, terms, privacy) for each of the 32 systems that are contacted, every system named in the description, banned wording, referral parameters. Not the official parser: paste the readme into the validator too. |
| Plugin header, version, text domain | `bin/build-zip.sh` (steps 1, 2 and 5e, run on the staged files and again inside the zip) | **Passes**: name "Estasoft Enterprise ERP/CRM Sync Engine", GPL-2.0-or-later, `Requires at least 6.4`, `Requires PHP 8.1`, `Requires Plugins woocommerce`, text domain `estasoft-enterprise-erpcrm-sync-engine` equal to the slug, `Domain Path /languages`, version `0.1.0` in the header, the constant, the readme `Stable tag` and `package.json`. No `Update URI`. |
| Text domain and prefixes | `grep` of `includes/` | Every translation call uses the one text domain (the WordPress i18n sniff of PHPCS is restricted to it and finds no other; 3,700 uses of the literal). Global functions are all `estasoft_sync_*` (`includes/requirements.php`), constants `ESTASOFT_SYNC_*`, everything else is in the namespace `Estasoft\SyncEngine`; options, transients, cron hooks and meta keys start with `estasoft_sync_` (or `_estasoft_sync_`); the REST namespace is `estasoft-sync/v1`; no global variable remains after the main file runs. |
| Direct access guards | `grep` of `includes/`, main file, `uninstall.php`; `bin/build-zip.sh` step 5d | **Every PHP file is guarded**: 919 files in `includes/` have `defined( 'ABSPATH' ) \|\| exit;`, the main file has it, `uninstall.php` checks `WP_UNINSTALL_PLUGIN`, `languages/index.php` is an empty silence file, `build/index.asset.php` holds only a returned array (its tokens are checked). |
| No direct file writes outside uploads | `grep -rnE "file_put_contents\|fwrite\|fopen\|mkdir\|rename\|unlink\|rmdir\|touch\|chmod"` | Writes are in `Connectors/Support/Export/ExportStore.php`, one work file in `Connectors/ExportShared/LedgerExportConnector.php` (in the exports folder) and the download stream in `Rest/Controllers/ExportsController.php`; all under `wp-content/uploads/estasoft-sync/exports/`, with `index.php`, `.htaccess` and `web.config` deny files. Nothing is written to the plugin folder or the web root. The smoke test checks that uninstall removes the folder. |
| Nonce and capability on every state-changing path | `grep` of `register_rest_route`, `admin_post_`, `wp_ajax_`, `$_POST`, `$_GET`, `$_REQUEST`; the REST audit in `bin/smoke-install.php` | **REST:** the only call of `register_rest_route` is in `Rest\BaseController::add()`, which sets a `permission_callback` (`estasoft_sync_manage` or `estasoft_sync_configure`); WordPress core drops a cookie user without a valid `X-WP-Nonce`. The smoke test lists every registered route: none is open to everyone except the signed inbound webhook (HMAC and time window), every other route answers an anonymous request 401 or 403, and a shop manager gets 403 on a configure route. **`admin-post` actions** (2): `estasoft_sync_dismiss_notice` (`check_admin_referer`, capability checked before it saves) and `estasoft_sync_order_action` (`check_admin_referer`, `current_user_can`). **Product box save:** capability, then `wp_verify_nonce`. **`admin-ajax`:** none. Reads of `$_GET`, `$_POST` and `$_SERVER` (13 places) are sanitised and either read-only or behind a nonce. |
| Escaping of output | `grep` of every `echo`, `print`, `printf`, `vprintf`, `print_r` in `includes/` | 38 output statements; each is a constant string or is escaped with `esc_html`, `esc_attr`, `esc_url`, `wp_kses` (with a fixed allowlist) or `selected()`, except two file downloads (`echo` of a CSV text and of file bytes, with `Content-Disposition: attachment` and `nosniff`), each with a `phpcs:ignore` that says why. The inline script is built with `wp_json_encode` and `JSON_HEX_TAG`, `JSON_HEX_AMP`, `JSON_HEX_APOS`, `JSON_HEX_QUOT`. No output at all on the public site (no `wp_head`, `wp_footer`, shortcode, block or widget hook). |
| Enqueued resources | `grep` of `wp_enqueue_*`, `wp_register_*` | Two stylesheets and one script of the plugin and two small stylesheets for the order and product boxes, all with a version, all from the plugin folder; no CDN; the app script is registered in the footer with the dependency list of `build/index.asset.php`; none is loaded on the public site. The smoke test checks that every dependency handle exists in WordPress 6.4.3 and that no script source is on another host. |
| Size of files | `find includes -name '*.php' -size`; the zip | Largest PHP file 54 KB (`includes/Catalog/catalog.php`, generated data); `build/index.js` 427 KiB (the app, one file, loaded only on the plugin's own admin page); the zip is about 2 MiB for 930 files. |
| Files that must not be in the zip | `bin/build-zip.sh` (steps 5a to 5c and 7) and the check at the top of `bin/smoke-install.sh` | **Passes**: top-level entries are only the main file, `uninstall.php`, `readme.txt`, `LICENSE`, `includes/`, `build/`, `assets/`, `languages/`; no `.git`, `node_modules`, `vendor`, tests, docs, `src`, scripts, source maps, logs, archives, hidden files, Markdown or other notes. (The first run of this check found a notes file in `includes/Connectors/Support/`; `.distignore` and `.gitattributes` now leave every Markdown file under `includes/` out.) |
| Activation, runtime and uninstall on a real WordPress | `bash bin/smoke-install.sh` ([SMOKE-TEST.md](SMOKE-TEST.md)) | **Passes**: fresh WordPress 6.4.3, WP_DEBUG and a debug.log, nothing from the plugin in the log, zero outbound requests. |
| Bundled libraries (guideline 13) and licences | `grep -c` for `react-dom`, `jQuery`, `tslib` and similar in `build/index.js`; a walk of the production dependency tree in `node_modules` | No bundled copy; 120 packages in the production tree, all MIT, GPL-2.0-or-later, 0BSD or Apache-2.0 ([GUIDELINES-AUDIT.md](GUIDELINES-AUDIT.md), guideline 1). |

## Findings of this pass

- **Development notes shipped in the zip.** Fixed in `.distignore` and `.gitattributes` (see above). The notes are
  working files of the connector build; move them out of `includes/` (or delete them) before the release.
- **`InboundController::receive()` and an empty body.** `$request->get_body()` is `null` for a request that is
  dispatched inside PHP without a body (`rest_do_request()`), and the handler's `string $rawBody` then raises a
  `TypeError`, answered as 500 `SYNC-REST-001`. A real HTTP request always has a string body (empty when there is
  none), so a visitor gets a 4xx; the smoke test sets an empty body as the server does. A one-word fix:
  `(string) $request->get_body()` in `includes/Rest/Controllers/InboundController.php` (not changed here, because the
  file belongs to the REST module).
- **`docs/rest-api.md` lists 70 of the 76 method-and-path pairs.** Missing: `GET` and `POST /exports`,
  `GET /exports/{file}/download`, `DELETE /exports/{file}`, `POST /connections/{id}/oauth/organisation` and
  `GET /oauth/callback` (they are described in `docs/connectors/BUILDING.md`). The smoke test prints them as a
  note, not a failure.

## What only the real Plugin Check can verify

1. **Its own PHPCS ruleset** (`PluginCheck.*` sniffs on top of the WordPress ones), for example heredoc use (none in
   `includes/`), discouraged functions, `register_setting` without a sanitise callback (none), images and offloaded
   resources. The WordPress sniffs ran clean here.
2. **The official readme parser** and the rules it applies to the header, the tags, the screenshots and the changelog;
   also whether the Contributors name `estasofttechnologies` is a real WordPress.org user, whether `Tested up to` is
   the current WordPress and whether the plugin name or slug is flagged as a trademark.
3. **Runtime checks on a real site** with WooCommerce active: the front end must load no script or style of the
   plugin and no external resource, activation must produce no output, and the admin screen must not trip the
   performance checks. The smoke test proves these only against the WooCommerce stand-in.
4. **The `localhost` check.** The word `localhost` appears in `Connectors/Support/HostPolicy.php` and
   `Connectors/Odoo/OdooAddressNormalizer.php` where the code *refuses* local addresses, and `build/index.js`
   contains `https://localhost/` as the base for parsing a relative address. If the tool flags these, they are
   false positives: no request is ever made to a local address unless a site owner allows it with a filter.
5. **The file-type, hidden-file and compressed-file checks** on the real zip from GitHub (the same zip the smoke test
   unpacks, but built on a different machine).
6. **The checks added to the tool after this date.** Run it again on the day of submission.
7. **Anything that needs the real WooCommerce:** orders, HPOS, the block checkout and the Action Scheduler.
