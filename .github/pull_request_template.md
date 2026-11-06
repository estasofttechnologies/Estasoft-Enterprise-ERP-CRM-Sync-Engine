## What this changes

<!-- One or two sentences. Link the issue: "Fixes #123". -->

## Why

<!-- The problem this solves. If it changes behaviour a merchant can see, say how. -->

## How it was tested

<!-- Commands you ran and what you saw. For a screen: what you clicked. -->

## Checklist

- [ ] `bin/check-all.sh` passes, or I list below what could not run and why
- [ ] New or changed behaviour has a test; a bug fix has a test that failed before the fix
- [ ] User-visible strings use `__()` / `_n()` with the text domain `estasoft-enterprise-erpcrm-sync-engine`
- [ ] Output is escaped late, input is sanitised early, queries use `$wpdb->prepare`, state-changing paths check a nonce and a capability
- [ ] No secret, API key or customer data appears in a log, response, exception or test fixture
- [ ] No telemetry, outside call, licence or upsell code, and no code downloaded at run time
- [ ] Docs updated (`docs/`, `readme.txt` Features or FAQ if it changes what the plugin does) and `CHANGELOG.md` has an entry under Unreleased
- [ ] The commit subjects are short and imperative, and carry no co-author or tool attribution lines

## Notes for the reviewer

<!-- Anything that needs a second look: a risky migration, a new REST route, a changed contract. -->
