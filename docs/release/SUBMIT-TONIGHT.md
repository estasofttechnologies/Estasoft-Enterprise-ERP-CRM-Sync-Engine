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

1. **Check the plugin name and slug.** WordPress.org builds the permalink from the plugin name, so it may offer
   `estasoft-enterprise-erp-crm-sync-engine` rather than the text domain and folder name
   `estasoft-erp-crm-sync-engine`. If the review email proposes the longer one, reply that you want
   `estasoft-erp-crm-sync-engine` so the slug matches the text domain; if they insist on the other one, the text
   domain and folder name must be changed to match before approval.
2. **Check the account.** Sign in at <https://wordpress.org/> with the account that will own the plugin. Turn on
   two-factor authentication (Profile > Account & Security). Check that `Contributors: estasofttechnologies` in
   `readme.txt` is exactly that account's username; change `readme.txt` and rebuild the zip if it is not.
3. **Read the zip's readme once in the validator.** Paste `readme.txt` into
   <https://wordpress.org/plugins/developers/readme-validator/> and read the result. The offline check
   (`php bin/check-readme.php --strict-assets`) already passes, so anything new the validator reports is worth a look.

## Upload

4. Open <https://wordpress.org/plugins/developers/add/>, upload the file
   `estasoft-erp-crm-sync-engine-0.1.0.zip`, tick the confirmations and submit.
5. An automatic check runs at once and a person from the Plugin Review team writes to the account's email address
   (not at once). Reply in the same thread. The text to paste is in [SUBMISSION-NOTES.md](SUBMISSION-NOTES.md),
   sections 1 to 8 for the description and section 9 for how a reviewer can test it without a paid ERP.
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
