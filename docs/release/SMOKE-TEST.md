# Smoke test of the release zip

Written by `bin/smoke-install.sh --report`; do not edit by hand. Run it again after any change to the plugin and commit the new file with the release.

| | |
|---|---|
| Date (UTC) | 2026-11-02 20:44 |
| Result | passed: 98 checks, 0 failed |
| Plugin version | 0.1.0 |
| Zip | `estasoft-erp-crm-sync-engine-0.1.0.zip` |
| Files in the zip | 930 |
| Size | 1.93 MiB zipped, 5.90 MiB unpacked |
| SHA-256 | `9efb9823de53b76561022ef9046afd6e249f824036af7fd515a2f2fb49027a2e` |
| WordPress | 6.4.3 (fresh install, WP_DEBUG on, debug.log) |
| PHP | 8.3.6 |
| Database | 10.11.14-MariaDB-0ubuntu0.24.04.1 (fresh database, utf8mb4) |
| WooCommerce | the stand-in `tests/php/stubs/woocommerce` (a copy, version 9.4.0): the real WooCommerce is not available on the build machine |

## What was tested

The plugin under test is the unpacked release zip in `wp-content/plugins/`, never the repository. Each step ran in its own PHP process.


### Install WordPress

- WordPress 6.4.3 installed into a fresh database
- the shop_manager role exists (WooCommerce adds it on a real site)
- the unzipped plugin is in plugins/
- the plugin folder is a real folder, not a symlink to the repository
- the WooCommerce stand-in is in plugins/

### Requirements guard: PHP too old

- the guard functions load from the release zip
- a PHP that is too old gives the PHP message, not a fatal error
- the guard adds one admin notice
- the notice says the plugin is not running and why

### Requirements guard: WordPress and WooCommerce

- the guard functions load from the release zip
- WordPress 6.3 gives the WordPress message
- PHP, WordPress and sodium pass without WooCommerce when WooCommerce is not asked for
- no WooCommerce gives the WooCommerce message
- WooCommerce 8.0 gives the version message

### Plugin active, WooCommerce not

- active plugins set to: estasoft-erp-crm-sync-engine/estasoft-erp-crm-sync-engine.php

### No WooCommerce: notice, no fatal error

- WooCommerce is not loaded in this request
- the plugin file loaded without a fatal error
- an administrator sees "is not running" and "WooCommerce must be installed and active."
- the plugin did not boot: no REST route is registered without WooCommerce
- a visitor without activate_plugins sees nothing

### WooCommerce active

- active plugins set to: woocommerce/woocommerce.php

### Activate the plugin

- WooCommerce (the stand-in) is loaded
- activate_plugin() returns without an error or unexpected output
- the plugin is active
- nine tables were created
- table connections exists
- table queue exists
- table logs exists
- table mappings exists
- table id_map exists
- table state exists
- table idempotency exists
- table recon_runs exists
- table recon_diffs exists
- administrators got estasoft_sync_manage and estasoft_sync_configure
- shop managers got estasoft_sync_manage only
- the subscriber role got neither capability
- the editor role got neither capability
- the author role got neither capability
- the settings option was stored
- the settings option is not autoloaded
- background tasks were scheduled (WP-Cron, because the Action Scheduler of WooCommerce is not in the stand-in)

### REST routes, catalog, connectors, admin page

- the plugin is still active in a new request
- the plugin class autoloads
- the REST namespace estasoft-sync/v1 is registered
- no route is open to everyone except the signed inbound route (1 open: POST /estasoft-sync/v1/inbound/(?P<connection>\d+))
- the namespace is listed by the REST index
- docs/rest-api.md lists its routes
- every route in docs/rest-api.md is registered
- Note: registered but not in docs/rest-api.md: GET /estasoft-sync/v1; POST /estasoft-sync/v1/connections/(?P<connection>\d+)/oauth/organisation; GET /estasoft-sync/v1/oauth/callback; GET /estasoft-sync/v1/exports; POST /estasoft-sync/v1/exports; GET /estasoft-sync/v1/exports/(?P<file>[a-f0-9]{1,32})/download; DELETE /estasoft-sync/v1/exports/(?P<file>[a-f0-9]{1,32})
- an anonymous request to each of 76 routes is refused (401 or 403; a 4xx for the signed inbound route)
- GET /catalog answers 200 for an administrator
- the catalog route lists all 34 systems
- every connector in providers.php appears in the catalog
- GET /status answers 200 for an administrator on a new site
- GET /settings answers 200 for an administrator on a new site
- GET /readiness answers 200 for an administrator on a new site
- GET /onboarding answers 200 for an administrator on a new site
- a shop manager can read /status (200) and is refused on PUT /settings (403)
- the registry knows every provider
- every connector class loads and is built through the registry
- the registry rejected no connector
- every provider class in providers.php exists
- the ESTASOFT SYNC menu page is registered
- the admin page prints its mount point
- the page does not say the app is missing (the zip carries build/)
- the admin app script is enqueued on its page
- the script is build/index.js from the plugin folder
- its dependency list is the one in build/index.asset.php
- its version is the build hash from build/index.asset.php
- every dependency handle exists in WordPress 6.4.3
- the script and its dependencies are all served by this site (no CDN)
- the boot data is handed over as window.estasoftSync
- the boot data holds no encryption key
- the stylesheet is build/index.css

### Seed data for the uninstall tests

- seeded an export file, user meta, a transient and an unrelated option

### Deactivate

- the plugin is deactivated
- deactivation keeps the nine tables
- deactivation keeps the options
- deactivation keeps the capabilities
- deactivation unscheduled the background tasks
- deactivation keeps the exports folder

### Uninstall, Delete all data off

- delete_on_uninstall is off by default
- uninstall with delete_on_uninstall off keeps the nine tables
- it keeps the options
- it keeps the exports folder
- it keeps the user meta
- it keeps the capabilities

### Uninstall, Delete all data on

- uninstall with delete_on_uninstall on drops all nine tables
- it removes every option and transient of the plugin
- it removes the exports folder
- it removes the user meta
- it removes the capabilities from every role
- it leaves an unrelated option alone
- it leaves the users alone

### debug.log and outbound requests

- debug.log works (the canary written at install is in it)
- debug.log has no line that mentions the plugin (no notice, warning or deprecation from its code)
- debug.log has no fatal error
- the plugin made no outbound HTTP request in any phase (nothing is contacted without a connection)


## What this does not prove

- The real WooCommerce (orders, HPOS, block checkout, Action Scheduler) is not installed here. Activation and the admin page are proven against the stand-in; the order hooks need a test on a real shop.
- No connector was run against a vendor: nothing is connected, and the test proves no outbound request is made.
- The admin app ran no JavaScript here: the test proves that the script is enqueued with the right handles on WordPress 6.4.3, not that it renders in a browser.
