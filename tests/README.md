# Tests

| Folder | What it holds | Needs |
| --- | --- | --- |
| `php/Unit/<Area>/` | Unit tests. Plain PHPUnit, no WordPress, no database. WordPress functions the code calls come from `php/Support/wp-stubs.php`. | PHP 8.1+ |
| `php/Integration/<Area>/` | Tests that run inside a real WordPress against a real database. Each test runs in a transaction that is rolled back (DDL commits implicitly, so schema tests clean up after themselves). | MySQL 8 or MariaDB, a WordPress copy |
| `php/Support/` | Shared helpers: the integration base class, WordPress stubs, fakes (fake HTTP client, fake clock, and so on). | |
| `php/stubs/woocommerce/` | A minimal stand-in for WooCommerce. WooCommerce itself is not installed in the tests; code that needs `WC_Order` and friends is written against thin adapters and tested with fakes. | |
| `js/` | Jest tests for the admin app (`@testing-library/react`, `jest-axe`). | Node 22 |
| `phpstan-bootstrap.php` | Constants and stubs PHPStan needs. | |

## Run them

```bash
composer test                                   # PHP unit tests: vendor/bin/phpunit
npm run test:js                                 # Jest

# Integration tests. This DROPS AND RECREATES the database named by WP_DB_NAME.
bin/install-wp-core.sh latest /tmp/wordpress    # or: 6.4
export WP_CORE_DIR=/tmp/wordpress WP_TEST_CONTENT=/tmp/wp-content
export WP_DB_NAME=estasoft_test WP_DB_USER=root WP_DB_PASSWORD=secret WP_DB_HOST=127.0.0.1
php bin/install-test-wp.php
vendor/bin/phpunit -c phpunit-integration.xml.dist
```

`bin/check-all.sh` runs all of these when it can and tells you what it skipped.

## How CI runs them

`.github/workflows/ci.yml` uses this repository's own harness, not the official WordPress test suite: it starts a
MySQL 8 service, downloads WordPress with `bin/install-wp-core.sh` (the newest 6.4.x for the minimum supported
version, and the current stable release), then runs `php bin/install-test-wp.php` and
`vendor/bin/phpunit -c phpunit-integration.xml.dist`. The harness installs a real WordPress, activates the
WooCommerce stub and this plugin, and needs no WordPress test-suite checkout. Unit tests run on PHP 8.1 to 8.4.

## Conventions

- File name = class name + `Test.php`; the folder mirrors the folder in `includes/`.
- Assert behaviour, not implementation. No sleeping: inject a `Clock`.
- Give your own integration runs their own database and content folder so parallel runs do not collide.
- A bug fix comes with a test that fails without it.
