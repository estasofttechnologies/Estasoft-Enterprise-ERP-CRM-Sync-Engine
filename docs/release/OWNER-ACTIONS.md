# What only the owner can do

Everything else is in the repository and checked by a script. The full ordered list is
[CHECKLIST.md](CHECKLIST.md); this page is the short version. Updated 6 Nov 2026. For the evening of submission use [SUBMIT-TONIGHT.md](SUBMIT-TONIGHT.md).

## Before the first submission

1. **GitHub.** The repository is public (done), so the readme's source-code link works. Still to do: turn on private
   vulnerability reporting (Settings > Code security) and protect `main` with the `CI passed` check.
2. **The slug.** WordPress.org assigned `estasoft-enterprise-erpcrm-sync-engine` when the zip was uploaded; the plugin's text
   domain, folder and main file use it. Plugin Check on the uploaded zip found nothing else.
3. **Run the gate once on GitHub and read it.** Push, wait for CI, then read the job summaries of **Plugin Check**
   (the real one runs only there; see [PLUGIN-CHECK-NOTES.md](PLUGIN-CHECK-NOTES.md) for what it can still find) and
   of the **smoke** job. Commit `composer.lock` and `package-lock.json` first. When the smoke job has passed once,
   add it to the `needs` of the `ci-ok` job.
4. **Confirm the dates and versions on the day:** `Tested up to` in `readme.txt` is the current WordPress release,
   `WC tested up to` in the plugin header (now 11.2) is a current WooCommerce. The `CHANGELOG.md` heading already
   carries the date 2026-11-06; change it if you submit on another day.
5. **Paste `readme.txt` into the official validator**
   (<https://wordpress.org/plugins/developers/readme-validator/>) and read the External services section as a
   reviewer would.
6. **Confirm the links marked "not confirmed"** at the end of [EXTERNAL-SERVICES.md](EXTERNAL-SERVICES.md) (the
   terms or privacy page of a vendor that could not be read when the connector was written, for example the Sage
   pages, which refuse automated reads). Fix the front matter in `docs/connectors/<id>.md`, then run
   `php bin/generate-external-services.php`. Systems with no terms page at all (SuiteCRM) are said so in words.
7. **Confirm the rights to the artwork:** Estasoft holds the rights to the icon and banner in `.wordpress-org/`
   (they are not in the zip). Real vendor logos are used **only with each vendor's written permission**; the plugin
   ships none.
8. **Look at the screenshots again** against the final screens (all six were retaken on 6 Nov 2026 from the final
   build, without vendor logos; [ASSETS.md](ASSETS.md)).
9. **Test on a real shop.** Done in CI: the real WooCommerce 11.2.2 on WordPress 7.1.4 with programmatic orders and
   refunds, posts storage and HPOS (`woocommerce-smoke.yml`). Not done: a real checkout in the browser (classic and
   block), every admin screen on WordPress 6.4 (older `@wordpress/*` packages), PHP 8.1 and 8.4 on a real shop,
   a screen reader, 200% zoom and a phone.
10. **Create the translation template** once: `bin/make-pot.sh` needs WP-CLI; commit
    `languages/estasoft-enterprise-erpcrm-sync-engine.pot`.

## Submit and publish

11. **Set up the deployment:** create the GitHub environment `wordpress-org` with yourself as required reviewer and
    the secrets `SVN_USERNAME` and `SVN_PASSWORD`; turn on two-factor authentication on the WordPress.org account
    `estasofttechnologies`, and check that `Contributors: estasofttechnologies` in `readme.txt` is exactly that
    account's username.
12. **Submit the zip** from the GitHub release (tag `v0.1.0` on `main`) at
    <https://wordpress.org/plugins/developers/add/>, using the text in [SUBMISSION-NOTES.md](SUBMISSION-NOTES.md).
    Answer the review emails, which go to the account's address, and approve the deploy job after approval.
13. **Set up support:** watch the WordPress.org support forum and the GitHub issues, and decide who answers a
    security report sent to the address in `SECURITY.md`.

## Before any connector is called verified, and legal questions

14. **Live-test every connector with a vendor sandbox or a free account.** Follow the manual smoke test at the end
    of each document in `docs/connectors/`, then set `verified: true` in its front matter and run
    `php bin/generate-catalog-from-docs.php` and `php bin/generate-external-services.php`. Until then the readme and
    the setup screen say that no connector has been tried live. Start with Odoo, QuickBooks Online, Xero, HubSpot
    and the ones your first customers use.
15. **Legal review of vendor terms.** Pennylane's API contract (internal business purposes only, open-source
    clause, rights over integrations) is why that connector is not offered; decide with Pennylane or a lawyer
    whether a free open-source plugin may use it, and if a sign-in (OAuth) version is needed. Read the developer
    terms and any app-review or partner rule of every other vendor the same way (each connector document lists what
    it found under "Not verified"); the plugin is free and each merchant uses their own account, which is the case
    to put to the vendor when a term is unclear.
16. **Trademarks.** Confirm the owner of each of the 34 names in [GUIDELINES-AUDIT.md](GUIDELINES-AUDIT.md) and find
    each vendor's brand terms before any logo is considered.
17. **Translations and right-to-left.** The screens are English only. Translations come from translate.wordpress.org
    after approval; a right-to-left admin layout is not built (the stylesheet uses logical properties, so it
    should not break).
