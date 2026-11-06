# Release checklist: from code complete to an approved listing

Steps in order. **Who** says whether tooling does it (and where) or the owner has to. Tick a step only when the
evidence named in it exists. The short list of what only the owner can do is [OWNER-ACTIONS.md](OWNER-ACTIONS.md).

Legend: **Tooling** = a script or CI job in this repository does it. **Owner** = a person with the right account
or authority has to do it. **Done** = already true on the day this file was last checked (6 Nov 2026).

## A. Repository set-up (once)

| # | Step | Who | Status |
| --- | --- | --- | --- |
| A1 | The project documents are in the repository: `docs/spec/phase1-spec.md`, `docs/spec/review-2-changes.md`, `docs/DECISIONS.md`. `php bin/check-docs.php` checks the links and the index. | Owner / lead | **Done** |
| A2 | Make the GitHub repository public (the readme's source-code link, guideline 4, needs it). | Owner | **Done** |
| A3 | Settings > Code security: turn on **private vulnerability reporting** (SECURITY.md and the issue-template links depend on it). | Owner | Open |
| A4 | Settings > Environments: create **`wordpress-org`**, add the owner as **required reviewer** (this is the manual approval), and store **`SVN_USERNAME`** and **`SVN_PASSWORD`** as secrets of that environment. Use the WordPress.org account that is a committer of the plugin. | Owner | Open |
| A5 | Settings > Branches: protect `main`, require the status check **CI passed** (the `ci-ok` job) and a pull request review. | Owner | Open |
| A6 | Run `composer update` once with network access and commit `composer.lock`; commit `package-lock.json` (it exists). CI and Dependabot rely on lock files. | Lead | Open (`composer.lock` is missing) |
| A7 | Turn on two-factor authentication on the WordPress.org account. Check that the `Contributors:` name in `readme.txt` is exactly that account's username. | Owner | Open |

## B. Code complete: automated gate

| # | Step | Who | Status |
| --- | --- | --- | --- |
| B1 | `bin/check-all.sh` is green locally (it lists whatever it had to skip). | Tooling | Runs now; PHPCS and PHPStan need `composer install` |
| B2 | CI is green on `main`: PHP lint 8.1 to 8.4, PHPCS, PHPStan, unit tests 8.1 to 8.4, integration tests on WordPress 6.4 and latest with MySQL 8, admin app checks, generated files, release zip build. Workflow: `.github/workflows/ci.yml` (passes `actionlint`; not runnable locally). | Tooling | CI |
| B3 | **PHPCS clean** with the WordPress standard (`phpcs.xml.dist`). Every `phpcs:ignore` has a reason next to it. | Tooling | Clean locally on 2 Nov ([PLUGIN-CHECK-NOTES.md](PLUGIN-CHECK-NOTES.md)); CI |
| B4 | **Plugin Check clean** on the release zip: `.github/workflows/plugin-check.yml` (official `WordPress/plugin-check-action`, WooCommerce installed on the test site). Fix every error, read every warning in the job summary. [PLUGIN-CHECK-NOTES.md](PLUGIN-CHECK-NOTES.md) says what was approximated here and what only this job can show. **Watch the first run:** the workflow writes `.wp-env.override.json` to add WooCommerce, which cannot be tried outside GitHub. | Tooling, then Owner reads it | CI |
| B5 | The red-flag scan is clean: `php bin/red-flags.php` and `php bin/check-php-version.php` (both run by `bin/lint-php.sh`). | Tooling | **Done** |
| B6 | Version consistency: header, `ESTASOFT_SYNC_VERSION`, readme `Stable tag`, `package.json` all equal (`bin/build-zip.sh --dry-run`). | Tooling | **Done** for 0.1.0 |
| B7 | `.gitattributes` (`git archive`) and `.distignore` (release zip, 10up action) leave the same files in: `bin/check-dist-parity.sh`. Documentation links resolve and every document is indexed: `php bin/check-docs.php`. | Tooling | **Done** |
| B8 | The generated files are up to date: `php bin/generate-connector-index.php --check`, `php bin/generate-catalog-from-docs.php --check`, `php bin/generate-external-services.php --check` (the `generated` job of CI). The last one rewrites the External services section of `readme.txt`, `docs/release/EXTERNAL-SERVICES.md` and the systems table of `README.md` from `docs/connectors/*.md`. | Tooling | **Done** |
| B9 | **Install test of the release zip:** `bash bin/smoke-install.sh --report docs/release/SMOKE-TEST.md` builds the zip, installs it (unzipped, not the repository) into a fresh WordPress 6.4.3 with WP_DEBUG, and checks activation, nine tables, capabilities, every REST route of `docs/rest-api.md` (anonymous requests refused), the catalog, every connector class, the admin page and its script, the requirements guard, deactivation and both uninstall modes. Run it again after any code change and commit the report. The `smoke` job of CI runs it on WordPress 6.4 and latest; add it to the `needs` of `ci-ok` after its first green run. | Tooling | **Done** ([SMOKE-TEST.md](SMOKE-TEST.md)); CI job unproven |

## C. Manual verification before the first submission

| # | Step | Who | Status |
| --- | --- | --- | --- |
| C1 | **Try the connectors on real accounts.** At least Odoo 18 and 19 with the Docker commands in [SUBMISSION-NOTES.md](SUBMISSION-NOTES.md) (section 9, path B): connect, test connection, order created, paid, refunded, stock and tracking read-back, a failure and a retry. Then each other connector with the manual smoke test at the end of its document in `docs/connectors/`; set `verified: true` and run the generators. No connector has been used on a live account yet, and the readme says so. | Owner / QA | Open |
| C2 | Try the built plugin on **WordPress 6.4** (the minimum) and the latest 7.x, PHP 8.1 and 8.4, with the real WooCommerce, HPOS on and off, with the classic and the block checkout. The smoke test proves the PHP side against a WooCommerce stand-in, and that the app's script handles exist on 6.4.3; the admin app runs at run time with the `@wordpress/*` packages WordPress ships, which on 6.4 are older than the ones it was compiled against, so open every admin screen on 6.4. | Owner / QA | Open |
| C3 | Admin screens with a screen reader, at 200% zoom, on a phone and a tablet, in each admin colour scheme (the design status lists these as not checked). | Owner / QA | Open |
| C4 | Re-read `readme.txt` (description, features, FAQ, External services) against the finished build: every sentence must be true. The External services entries are generated, so check the introduction and the links: open each address (the ones marked "not confirmed" at the end of [EXTERNAL-SERVICES.md](EXTERNAL-SERVICES.md) first). | Owner | Open |
| C5 | Confirm **Tested up to** (now 7.1) is the current WordPress release the day you submit, and that the plugin header's `WC tested up to` (now 11.2) is a current WooCommerce version. | Owner | Checked 6 Nov 2026 (WordPress 7.1, WooCommerce 11.2) |
| C6 | `php bin/check-readme.php` passes (checks the rules offline), then paste `readme.txt` into the **official validator**: <https://wordpress.org/plugins/developers/readme-validator/>. | Tooling, then Owner | Offline check **Done**; validator open |
| C7 | **Licences.** The licences of the packages that are in the production dependency tree of `build/` are listed in [GUIDELINES-AUDIT.md](GUIDELINES-AUDIT.md) (guideline 1): all GPL-compatible, and none is inside the bundle. Confirm that Estasoft holds the rights to the icon and banner. | Owner | Licences **Done**; rights open |
| C8 | **Source and bundle.** `build/index.asset.php` lists WordPress script handles for React and the `@wordpress` packages; the bundle contains no copy of React or jQuery and none of the mock layer (CI checks the mock, the smoke test checks the handles). | Tooling | **Done** |
| C9 | **Trademarks.** No vendor logo in `src/`, `assets/` or `build/` (the zip holds no image). Brand terms for each of the 34 systems located before any logo is added (list in [GUIDELINES-AUDIT.md](GUIDELINES-AUDIT.md)). | Owner | Open |
| C10 | **Slug.** WordPress.org assigned `estasoft-enterprise-erpcrm-sync-engine` on upload (6 Nov 2026). The text domain, the folder in the zip and the main file match it; the first zip used a different text domain and Plugin Check on wordpress.org flagged it. | Owner | **Done** |
| C11 | Create the translation template once: `bin/make-pot.sh` writes `languages/estasoft-enterprise-erpcrm-sync-engine.pot` (needs WP-CLI, which is not installed on the build machine, so no `.pot` exists yet). Commit it. | Owner / lead | Open |
| C12 | **Legal.** Decide Pennylane (its connector is not offered until its API terms and production sign-in are cleared), and read the developer terms of the other vendors ([OWNER-ACTIONS.md](OWNER-ACTIONS.md), item 15). | Owner | Open |

## D. Assets

| # | Step | Who | Status |
| --- | --- | --- | --- |
| D1 | Icon 128 and 256 px, banner 772 x 250 and 1544 x 500 px are in `.wordpress-org/`. | Tooling | **Done** |
| D2 | Screenshots 1 to 6 are in `.wordpress-org/` (1280 wide PNG, sample shop, all retaken on 6 Nov 2026 from the final build). | Owner | **Done** ([ASSETS.md](ASSETS.md)) |
| D3 | `php bin/check-readme.php --strict-assets` passes (it fails while any captioned screenshot is missing). | Tooling | **Done** |

## E. Version, tag and zip

| # | Step | Who | Status |
| --- | --- | --- | --- |
| E1 | For a version after 0.1.0: `bin/bump-version.sh <x.y.z>`, then write the real notes in `CHANGELOG.md` and `readme.txt` (changelog and upgrade notice). The placeholder text blocks the build on purpose. | Owner / lead | Per release |
| E2 | For 0.1.0: replace `Unreleased` in the CHANGELOG heading with the release date. | Owner / lead | **Done** (2026-11-06) |
| E3 | `bin/build-zip.sh` passes locally: versions agree, readme valid, only runtime files in the zip, every PHP file guarded, header and text domain correct. Inspect the printed file list and size. | Tooling | **Done**: a full build ran as part of the smoke test ([SMOKE-TEST.md](SMOKE-TEST.md) gives the file count, size and SHA-256) |
| E4 | Merge to `main`, wait for CI, then create the tag **`v<version>`** on that commit. The release workflow refuses a tag that is not on `main` or that differs from the plugin version. | Owner | Open |

## F. Submit and publish

| # | Step | Who | Status |
| --- | --- | --- | --- |
| F1 | The tag build uploads `estasoft-enterprise-erpcrm-sync-engine-<version>.zip` to the GitHub release (the `github-release` job). Download that exact file. | Tooling | On tag |
| F2 | Submit it at <https://wordpress.org/plugins/developers/add/> while signed in to the WordPress.org account `estasofttechnologies`. Paste the text from [SUBMISSION-NOTES.md](SUBMISSION-NOTES.md) where the form or the review email asks for notes. | Owner | Open |
| F3 | Answer the Plugin Review team's emails (they come from the account's address) and fix what they list. Re-submit by uploading a new zip as they instruct; bump the version if they ask. | Owner | After submission |
| F4 | After approval the SVN repository exists. Re-run the release workflow for the approved tag (or tag the next version). When the `deploy-wordpress-org` job waits, review it and **approve**. It puts the unpacked verified zip in `trunk`, copies it to `tags/<version>` and uploads `.wordpress-org/*` to `assets`. | Owner | After approval |
| F5 | Check the plugin page: name, banner at both sizes, icon, screenshots and captions, tabs, changelog, upgrade notice, "Requires Plugins" box. Install it from the directory on a clean site with WooCommerce and run the setup. | Owner | After deploy |
| F6 | Watch the support forum. Fix cycle: change, `bin/check-all.sh`, bump, tag, approve. Avoid frequent commits to SVN (guideline 14): one deploy per release. | Owner | Ongoing |

## What was verified when this file was last checked (6 Nov 2026)

- `php bin/red-flags.php`, `php bin/check-php-version.php`, `php bin/check-readme.php --strict-assets`,
  `php bin/check-docs.php`, `bash bin/check-dist-parity.sh`, the three `--check` generators, and `actionlint` on the
  three workflows, run on the working tree. PHPCS (WordPress standard) and PHPStan (level 6) through
  Composer and in CI; results in [PLUGIN-CHECK-NOTES.md](PLUGIN-CHECK-NOTES.md).
- `bash bin/smoke-install.sh`: the release zip was built with `npm ci` and `npm run build`, unpacked into a fresh
  WordPress 6.4.3 and exercised ([SMOKE-TEST.md](SMOKE-TEST.md)).
- GitHub Actions on `main`: CI, Plugin Check and `woocommerce-smoke.yml` (the real WooCommerce 11.2.2 on WordPress
  7.1.4, posts and HPOS storage).
- **Not verified:** the readme validator on wordpress.org, the deployment, a real checkout in a browser, and any
  vendor account.
