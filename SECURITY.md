# Security policy

Estasoft Enterprise ERP/CRM Sync Engine handles store orders, customer data and the credentials for the
systems it connects to. Security reports are taken seriously and are welcome.

## Supported versions

Only the latest released version receives security fixes. Update to it before you report a problem, if you can.

| Version | Supported |
| ------- | --------- |
| 0.1.x   | Yes       |

## How to report a vulnerability

**Do not open a public issue, pull request or forum post for a vulnerability.**

Use GitHub private vulnerability reporting:

1. Open <https://github.com/estasofttechnologies/estasoft-enterprise-erp-crm-sync-engine/security/advisories/new>.
2. Describe what you found, which version and configuration it affects, and how to reproduce it. A short proof of
   concept helps. Say what an attacker could do.
3. Do not include real customer data, real API keys or anyone's passwords. Use a test store.

Only the maintainers can see the report. We will discuss a fix and a date for publishing it with you in the
private advisory, and we will credit you when it is published unless you prefer to stay anonymous.

We aim to reply within five working days and to ship a fix for a confirmed high-severity problem as soon as it
has been tested. Please give us a reasonable time to fix a problem before you tell anyone else.

## What is in scope

- The plugin's PHP code, REST API (`estasoft-sync/v1`), admin screens and WP-CLI commands.
- How credentials are stored, encrypted, redacted and kept out of responses, logs and exceptions.
- Missing capability or nonce checks, injection, cross-site scripting, request forgery, path traversal,
  unsafe deserialisation, and server-side request forgery through connector addresses.

## What is out of scope

- Problems in WordPress, WooCommerce, Odoo or any other system the plugin connects to. Report those to their
  own projects.
- Findings that need an attacker who is already a site administrator and only reach what an administrator can
  do anyway.
- Missing security headers or best-practice suggestions with no demonstrated effect.
- Automated scanner output without a working explanation.

## For site owners

- Keep the encryption key (`ESTASOFT_SYNC_KEY` in `wp-config.php`) out of backups that are shared and out of
  version control.
- Give each connection an API key with only the permissions the plugin needs, and replace keys before they
  expire.
- The plugin sends no telemetry and contacts only the system you connect. See the *External services* section of
  `readme.txt`.
