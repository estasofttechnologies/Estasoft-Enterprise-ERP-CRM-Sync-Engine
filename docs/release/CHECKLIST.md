# Release checklist: from code complete to an approved listing

Steps in order. **Who** says whether tooling does it (and where) or the owner has to. Tick a step only when the
evidence named in it exists.

Legend: **Tooling** = a script or CI job in this repository does it. **Owner** = a person with the right account
or authority has to do it. **Done** = already true on the day this file was written (2 Nov 2026).

## A. Repository set-up (once)

| # | Step | Who | Status |
| --- | --- | --- | --- |
| A1 | Copy the three project documents into the repository, unchanged apart from a front note: `docs/spec/phase1-spec.md`, `docs/spec/review-2-changes.md`, `docs/DECISIONS.md`. `bin/check-docs.php` reports them missing until then. | Owner / lead | **Open** |
| A2 | Make the GitHub repository public (the readme's source-code link, guideline 4, needs it). | Owner | Open |
| A3 | Settings > Code security: turn on **private vulnerability reporting** (SECURITY.md and the issue-template links depend on it). | Owner | Open |
| A4 | Settings > Environments: create **`wordpress-org`**, add the owner as **required reviewer** (this is the manual approval), and store **`SVN_USERNAME`** and **`SVN_PASSWORD`** as secrets of that environment. Use the WordPress.org account that is a committer of the plugin. | Owner | Open |
| A5 | Settings > Branches: protect `main`, require the status check **CI passed** (the `ci-ok` job) and a pull request review. | Owner | Open |
| A6 | Run `composer update` once with network access and commit `composer.lock`; commit `package-lock.json`. CI and Dependabot rely on lock files. | Lead | Open |
| A7 | Turn on two-factor authentication on the WordPress.org account. | Owner | Open |

## B. Code complete: automated gate

| # | Step | Who | Status |
| --- | --- | --- | --- |
| B1 | `bin/check-all.sh` is green locally (it lists whatever it had to skip). | Tooling | Runs now; PHPCS and PHPStan need `composer install` |
| B2 | CI is green on `main`: PHP lint 8.1 to 8.4, PHPCS, PHPStan, unit tests 8.1 to 8.4, integration tests on WordPress 6.4 and latest with MySQL 8, admin app checks, release zip build. Workflow: `.github/workflows/ci.yml`. | Tooling | Not runnable locally; workflows pass `actionlint` and YAML checks |
| B3 | **PHPCS clean** with the WordPress standard (`phpcs.xml.dist`). Every `phpcs:ignore` has a reason next to it. | Tooling | CI |
| B4 | **Plugin Check clean** on the release zip: `.github/workflows/plugin-check.yml` (official `WordPress/plugin-check-action`, WooCommerce installed on the test site). Fix every error, read every warning in the job summary. **Watch the first run:** the workflow writes `.wp-env.override.json` to add WooCommerce, which cannot be tried outside GitHub. | Tooling, then Owner reads it | CI |
| B5 | The red-flag scan is clean: `bin/lint-php.sh` (no `eval`, `base64_decode`, `extract`, `@`, stray `exit`, direct network calls outside `WpHttpClient`). | Tooling | Runs now |
| B6 | Version consistency: header, `ESTASOFT_SYNC_VERSION`, readme `Stable tag`, `package.json` all equal (`bin/build-zip.sh --dry-run`). | Tooling | Done for 0.1.0 |
| B7 | `.gitattributes` (`git archive`) and `.distignore` (release zip, 10up action) leave the same files in: `bin/check-dist-parity.sh`. Documentation links resolve and every document is indexed: `php bin/check-docs.php`. | Tooling | Parity passes; docs check fails until A1 is done |

## C. Manual verification before the first submission

| # | Step | Who | Status |
| --- | --- | --- | --- |
| C1 | **Smoke test against real Odoo 17, 18 and 19** using the Docker commands in `SUBMISSION-NOTES.md`: connect, test connection, order created, paid, refunded, stock and tracking read-back, a failure and a retry. The unit and integration tests use a fake Odoo; no real server has been used yet. | Owner / QA | Open |
| C2 | Try the built plugin on **WordPress 6.4** (the minimum) and the latest 7.x, PHP 8.1 and 8.4, with HPOS on and off, with the classic and the block checkout. CI proves the PHP side only; the admin app is compiled against recent `@wordpress/*` packages but at run time uses the ones WordPress ships, which on 6.4 are older, so open every admin screen on 6.4. | Owner / QA | Open |
| C3 | Admin screens with a screen reader, at 200% zoom, on a phone and a tablet, in each admin colour scheme (the design status lists these as not checked). | Owner / QA | Open |
| C4 | Re-read `readme.txt` Features, FAQ and External services against the finished build: every sentence must be true. Check that all external links open. | Owner | Open |
| C5 | Confirm **Tested up to** (now 7.1) is the current WordPress release the day you submit, and that the plugin header's `WC tested up to` is a current WooCommerce version. | Owner | Open |
| C6 | `php bin/check-readme.php` passes (checks the rules offline), then paste `readme.txt` into the **official validator**: <https://wordpress.org/plugins/developers/readme-validator/>. | Tooling, then Owner | Offline check done; validator open |
| C7 | **Licences.** List the licences of the npm packages that are inside `build/` (for example `npx license-checker --production --summary`); all must be GPL-compatible. Confirm Estasoft holds the rights to the icon and banner. | Owner | Open |
| C8 | **Source and bundle.** `build/index.asset.php` lists WordPress script handles for React and the `@wordpress` packages; the bundle contains no copy of React or jQuery and none of the mock layer (CI checks the mock). | Tooling, then Owner | Open |
| C9 | **Trademarks.** No vendor logo in `src/`, `assets/` or `build/` (`find src assets -iname "*.png" -o -iname "*.svg"`). Brand terms for each of the 34 systems located before any logo is added (list in `GUIDELINES-AUDIT.md`). | Owner | Open |
| C10 | **Slug check.** Open <https://wordpress.org/plugins/estasoft-erp-crm-sync-engine/>; "not found" means the slug is free. Search the directory for plugins with similar names. | Owner | Open |
| C11 | Create the translation template once the admin app is built: `bin/make-pot.sh` writes `languages/estasoft-erp-crm-sync-engine.pot` (needs WP-CLI, which is not installed on the build machine, so no `.pot` exists yet). Commit it. | Owner / lead | Open |

## D. Assets

| # | Step | Who | Status |
| --- | --- | --- | --- |
| D1 | Icon 128 and 256 px, banner 772 x 250 and 1544 x 500 px are in `.wordpress-org/`. | Tooling | **Done** |
| D2 | **Screenshots 1 to 6 are pending.** Take them from the finished plugin as described in `ASSETS.md` and add them as `.wordpress-org/screenshot-1.png` to `screenshot-6.png`. | Owner | **Open** |
| D3 | `php bin/check-readme.php --strict-assets` passes (it fails while any captioned screenshot is missing). | Tooling | Fails until D2 is done |

## E. Version, tag and zip

| # | Step | Who | Status |
| --- | --- | --- | --- |
| E1 | For a version after 0.1.0: `bin/bump-version.sh <x.y.z>`, then write the real notes in `CHANGELOG.md` and `readme.txt` (changelog and upgrade notice). The placeholder text blocks the build on purpose. | Owner / lead | Per release |
| E2 | For 0.1.0: replace `Unreleased` in the CHANGELOG heading with the release date. | Owner / lead | Open |
| E3 | `bin/build-zip.sh` passes locally: versions agree, readme valid, only runtime files in the zip, every PHP file guarded, header and text domain correct. Inspect the printed file list and size. | Tooling | Logic proven on the current tree (`--dry-run`); a full build needs the admin app |
| E4 | Merge to `main`, wait for CI, then create the tag **`v<version>`** on that commit. The release workflow refuses a tag that is not on `main` or that differs from the plugin version. | Owner | Open |

## F. Submit and publish

| # | Step | Who | Status |
| --- | --- | --- | --- |
| F1 | The tag build uploads `estasoft-erp-crm-sync-engine-<version>.zip` to the GitHub release (the `github-release` job). Download that exact file. | Tooling | On tag |
| F2 | Submit it at <https://wordpress.org/plugins/developers/add/> while signed in to the WordPress.org account `estasofttechnologies`. Paste the text from `SUBMISSION-NOTES.md` where the form or the review email asks for notes. | Owner | Open |
| F3 | Answer the Plugin Review team's emails (they come from the account's address) and fix what they list. Re-submit by uploading a new zip as they instruct; bump the version if they ask. | Owner | After submission |
| F4 | After approval the SVN repository exists. Re-run the release workflow for the approved tag (or tag the next version). When the `deploy-wordpress-org` job waits, review it and **approve**. It puts the unpacked verified zip in `trunk`, copies it to `tags/<version>` and uploads `.wordpress-org/*` to `assets`. | Owner | After approval |
| F5 | Check the plugin page: name, banner at both sizes, icon, screenshots and captions, tabs, changelog, upgrade notice, "Requires Plugins" box. Install it from the directory on a clean site with WooCommerce and run the setup. | Owner | After deploy |
| F6 | Watch the support forum. Fix cycle: change, `bin/check-all.sh`, bump, tag, approve. Avoid frequent commits to SVN (guideline 14): one deploy per release. | Owner | Ongoing |

## What was verified when this file was written

- `bin/lint-php.sh`, `bin/check-readme.php`, `bin/build-zip.sh --dry-run`, `bin/bump-version.sh` and
  `bin/release-notes.sh` were run against the repository (the last two on a temporary copy).
- `bin/build-zip.sh` was run for real on a temporary copy with a stand-in `build/` folder: it built a zip with one
  `estasoft-erp-crm-sync-engine/` folder and a checksum, and the zip passed its own verification.
- The three workflows and the Dependabot file pass `actionlint` and `yamllint`.
- **Not verified here (no network and no GitHub):** any GitHub Actions run, Plugin Check, the readme validator, the
  deployment, `composer install`, `npm ci`, or a full build of the admin app by these scripts.
