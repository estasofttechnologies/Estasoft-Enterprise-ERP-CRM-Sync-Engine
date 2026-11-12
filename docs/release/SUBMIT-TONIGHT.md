# Submit tonight

The short version of [OWNER-ACTIONS.md](OWNER-ACTIONS.md) for the evening of submission. Written 6 Nov 2026 for
version 0.1.0. Everything that a script or a GitHub workflow can check has been checked; these are the steps that
need the owner's accounts.

## State of the build (6 Nov 2026)

- `main` on GitHub is the source of the zip. CI (lint, PHPCS, PHPStan, unit and integration tests, admin app
  checks, generated files, zip build), Plugin Check and the real-WooCommerce smoke test run on every push.
- The plugin ships no logos: no vendor logo and no Estasoft logo image inside the zip. The WordPress.org icon and
  banner are in `.wordpress-org/` and are not part of the zip.
- Not tried: any connector against a live vendor account (the readme and the setup screen say so), and a real
  checkout in a browser.

## Before you upload (10 minutes)

1. **The slug.** WordPress.org assigned `estasoft-enterprise-erpcrm-sync-engine` when you uploaded the first zip. Plugin Check on
   wordpress.org found one problem only: the text domain was `estasoft-erp-crm-sync-engine`. This zip uses the
   assigned slug everywhere (text domain, folder in the zip, main file), so that problem is gone. The slug cannot be
   changed after approval; if you would rather have a shorter one, ask the review team before they approve.
2. **Check the account.** Sign in at <https://wordpress.org/> with the account that will own the plugin. Turn on
   two-factor authentication (Profile > Account & Security). The account is `estasoft`, and
   `Contributors: estasoft` in `readme.txt` is exactly that username.
3. **Read the zip's readme once in the validator.** Paste `readme.txt` into
   <https://wordpress.org/plugins/developers/readme-validator/> and read the result. The offline check
   (`php bin/check-readme.php --strict-assets`) already passes, so anything new the validator reports is worth a look.

## Upload (after the first review, 12 Nov 2026)

4. The Plugin Review team pended the first submission with a list of issues (all fixed in this zip). Upload the new
   `estasoft-enterprise-erpcrm-sync-engine-0.1.0.zip` at <https://wordpress.org/plugins/developers/add/> while signed in
   as `estasoft`, then **reply to the review email thread** (not a new email). Keep the reply short; the team reviews
   the whole plugin again and does not want a list of changes. Suggested reply:

   > Hello, I uploaded a corrected version. Besides the listed issues, I changed Pennylane to a placeholder that makes
   > no request (its API terms are not cleared yet), and limited the plugin's admin notices to the Dashboard, the
   > Plugins list and WooCommerce screens. Thank you.

5. Wait for the next reply (a few days to a few weeks; do not ask for status before a month has passed).
6. Do **not** push the tag `v0.1.0` yet. The release workflow publishes to WordPress.org subversion and needs the
   `wordpress-org` environment (required reviewer, secrets `SVN_USERNAME` and `SVN_PASSWORD`), which can only exist
   after approval. Create it then ([CHECKLIST.md](CHECKLIST.md), A4 and F4).

## Do these next, any day this week

- Settings > Code security: turn on **private vulnerability reporting** (SECURITY.md and the issue templates link to
  it) and protect `main` with the `CI passed` check.
- Try the plugin on a real shop: a real checkout (classic and block), every admin screen on WordPress 6.4, a screen
  reader and 200% zoom.
- Live-test the connectors your first customers use (Odoo first, see SUBMISSION-NOTES section 9, path B), then set
  `verified: true` for each in `docs/connectors/<id>.md`.

## If the review team asks for changes

Fix on a branch, run `bash bin/check-all.sh`, merge to `main`, rebuild with `bash bin/build-zip.sh` and upload the
new zip as they instruct. Keep the version at 0.1.0 unless they ask for a bump; if you bump, use
`bin/bump-version.sh` so the header, constant, readme and package agree.
