# Odoo connector

Connector id `odoo`, type ERP, contract version 1. Code: `includes/Connectors/Odoo/`. Registration: `includes/Connectors/ConnectorsModule.php`, which adds the connector through the `estasoft_sync_register_connectors` filter (third-party connectors register the same way).

The connector sends WooCommerce orders (as sale orders, optionally with invoice and payment), refunds (as credit notes) and customers to Odoo, and reads stock, delivery tracking and invoice status back. Every network call goes through `Contract\HttpClientInterface`; the connector never calls `wp_remote_*`.

Supported Odoo: 17.0, 18.0, 19.0 and later (Community, Enterprise and Odoo Online). Odoo 16 and older are refused with SYNC-VAL-002 ("Use Odoo 17 or later").

## 1. Transports

| Odoo | Transport class | Wire format |
| --- | --- | --- |
| 17, 18, Online saas~17.x and saas~18.x | `JsonRpcTransport` | `POST {base}/jsonrpc`, service `common` (`authenticate`, `version`) and `object` (`execute_kw`), the API key is sent as the password |
| 19 and later | `Json2Transport` | `POST {base}/json/2/{model}/{method}`, header `Authorization: bearer <key>`, header `X-Odoo-Database: <db>`, named parameters in a JSON object, `ids` and `context` as keys of the body |

Both implement `OdooTransport`. `OdooVersionDetector` chooses by major version: it asks `GET /web/version` first, and when that route does not exist it asks `common.version` over `/jsonrpc`. Neither call needs a sign-in. The database service of Odoo (`db.list` and similar) is never called.

`OdooClient` adds what is the same for both: the company context (`allowed_company_ids`), a cache of `fields_get` answers, version-dependent field names (see section 5) and the one-call-at-a-time pacing.

## 2. Verified facts and sources

Sources are the official documentation repository (https://github.com/odoo/documentation, branches 17.0, 18.0, 19.0) and the Odoo source (https://github.com/odoo/odoo, same branches). They were read from a local clone on 2026-11-02; the file paths below are paths in those repositories. Published documentation pages:

- JSON-2 API (19.0): https://www.odoo.com/documentation/19.0/developer/reference/external_api.html (file `content/developer/reference/external_api.rst`)
- External RPC API (deprecated from 19.0): https://www.odoo.com/documentation/19.0/developer/reference/external_rpc_api.html
- The same RPC page for 18.0 and 17.0: https://www.odoo.com/documentation/18.0/developer/reference/external_api.html and https://www.odoo.com/documentation/17.0/developer/reference/external_api.html

| Fact | Where it was checked |
| --- | --- |
| JSON-2 is `POST /json/2/<model>/<method>`; headers `Host`, `Authorization: bearer <key>`, `Content-Type: application/json`; `X-Odoo-Database` is optional (needed when the server hosts several databases); body keys `ids`, `context` and the method's parameters | documentation 19.0 `external_api.rst`; `addons/rpc/controllers/json2.py` (route `/json/2/<__model__>/<__method__>`, `auth='bearer'`) |
| JSON-2 success is HTTP 200 with the JSON-encoded return value; errors are 4xx/5xx with a body `{name, message, arguments, context, debug}` | documentation 19.0; `serialize_exception` and `Json2Dispatcher.handle_error` in `odoo/http.py` (19.0) |
| JSON-2 statuses: `UserError` and `ValidationError` 422, `AccessError` and `AccessDenied` 403, `MissingError` 404, `LockError` 409, unknown model or method 404 (`werkzeug NotFound`), wrong parameters 422, an invalid key 401 (`Invalid apikey`), anything else 500 | `odoo/exceptions.py` (`http_status`), `addons/rpc/controllers/json2.py`, `odoo/addons/base/models/ir_http.py` `_auth_method_bearer` (19.0) |
| JSON-2 needs a key with scope `rpc` or no scope (a "global" key) | `_auth_method_bearer`: `_check_credentials(scope='rpc', key=token)`; documentation 19.0 (programmatic key management) |
| JSON-2 calls to a method that takes `@api.model` must not send `ids` (422) | `json2.py` |
| A request whose database cannot be selected answers HTTP 404 with an HTML page that says "No database is selected ... Alternatively, use the X-Odoo-Database header" | `odoo/http.py` (19.0, the page near line 283; the header is read near line 1827) |
| `GET /web/version` (and `/json/version`) exists from 19.0 and returns `{"version_info": [...], "version": "19.0"}` without a sign-in | `addons/rpc/controllers/__init__.py` (19.0); test `test_misc9_webversion` in `odoo/addons/test_http/tests/test_misc.py`; the route is not present in 17.0 or 18.0 |
| `/jsonrpc` takes `{"jsonrpc":"2.0","method":"call","params":{"service","method","args"}}`; errors come back with HTTP 200 and `error.data.{name,message,arguments,context,debug}`; code 200 "Odoo Server Error", 100 "Odoo Session Expired", 404 for an unknown route | `odoo/addons/base/controllers/rpc.py` (17.0, 18.0), `addons/rpc/controllers/jsonrpc.py` (19.0); `JsonRPCDispatcher.handle_error` in `odoo/http.py` (18.0) |
| `common.version` returns `server_version`, `server_version_info`, `server_serie`, `protocol_version` | `odoo/service/common.py` |
| `common.authenticate` returns the user id, or `False` when the login, key or password is wrong, in 17, 18 and 19 | `exp_authenticate` in `odoo/service/common.py` (catches `AccessDenied`, returns `False`) |
| `/xmlrpc`, `/xmlrpc/2` and `/jsonrpc` are deprecated in 19.0 and scheduled for removal in Odoo 22 (fall 2028) and Online 21.1; they still exist in the 20.0 branch | documentation 19.0 `external_rpc_api.rst`; `addons/rpc/controllers/jsonrpc.py` on 19.0 and 20.0 |
| A missing model in `execute_kw` gives `UserError: Object <model> doesn't exist` | `odoo/service/model.py` (17.0, 18.0, 19.0) |
| External API access exists only on *Custom* pricing plans (not One App Free, not Standard) | documentation 17.0, 18.0, 19.0 |
| API keys replace the password; the login stays; a key is shown once; from 19.0 a key lasts at most three months | documentation 17.0 to 19.0 |
| `sale.advance.payment.inv.advance_payment_method` has the value `delivered` ("Regular invoice") and `create_invoices()` | `addons/sale/wizard/sale_make_invoice_advance.py` (17, 18, 19) |
| `account.payment.register.action_create_payments()` | `addons/account/wizard/account_payment_register.py` (17, 18, 19) |
| The tax field of `sale.order.line` is `tax_id` in 17 and 18 and `tax_ids` in 19 | `addons/sale/models/sale_order_line.py` |
| A storable product is `type = 'product'` in 17; from 18 it is `type = 'consu'` with the boolean `is_storable` | `addons/stock/models/product.py` (17, 18, 19) |
| The context key that limits `qty_available` and `free_qty` of `product.product` to a warehouse is `warehouse` in 17 and `warehouse_id` in 18 and 19 | `addons/stock/models/product.py` (`_get_domain_locations`) |
| `account.move.action_process_edi_web_services`, `edi_state`, `edi_error_message` | `addons/account_edi/models/account_move.py` (17, 18, 19) |
| Saudi ZATCA: `l10n_sa_uuid` (module `l10n_sa_edi`), `l10n_sa_qr_code_str` and `l10n_sa_confirmation_datetime` (module `l10n_sa`) exist in 17, 18 and 19 | `addons/l10n_sa_edi/models/account_move.py`, `addons/l10n_sa/models/account_move.py` |
| `l10n_sa_reason` (selection `BR-KSA-17-reason-1` to `-5`; `-4` is "goods or services refund") is a field of `account.move` in 19 only; none of the 17.0 and 18.0 ZATCA modules define it | `addons/l10n_sa/models/account_move.py` (19.0) |
| The company's ZATCA mode is `l10n_sa_api_mode` (`sandbox`, `preprod`, `prod`) in 17, 18 and 19; the boolean `l10n_sa_edi_is_production` exists on `res.company` from 19 | `addons/l10n_sa_edi/models/res_company.py` |

How the connector uses them:

- The transport is chosen by major version (19 and later: JSON-2; 17 and 18: JSON-RPC). A version with a string major (Odoo Online `saas~18.x`) is read as that number.
- Installed apps are detected by `fields_get` probes (`sale.order`, `stock.warehouse`, `account.move`, `stock.picking.carrier_tracking_ref`, `account.move.l10n_sa_uuid`), because an ordinary sync user cannot read `ir.module.module`.
- The version-dependent names above are chosen by `OdooClient::version()` or by asking `fields_get` whether the field exists.

### Not verified

- Odoo Online: the wording of the refusal on a plan without external API access is not documented. `OdooErrorMapper` reads a refusal as SYNC-AUTH-006 only when the host is an `odoo.com` address and the answer is a 403 without a JSON body, or a fault whose message mentions the plan, subscription, upgrade, XML-RPC, JSON-RPC or external API. Treat the heuristic as unconfirmed until it has met a real Online database on a Standard plan.
- A JSON-RPC call to a database that does not exist is expected to fail with `psycopg2.OperationalError: ... database "x" does not exist`; this comes from reading the code path (`Registry(db)`), not from a live run. The mapper also accepts the JSON-2 "No database is selected" page.
- Odoo's own rate limiting: Odoo does not document HTTP 429 for the external API. The connector honours 429 and `Retry-After` because proxies and hosting platforms in front of Odoo send them.
- No live Odoo server was available. All behaviour above the wire was tested against `FakeOdooServer` (an in-memory Odoo for versions 17, 18, 19 and Online) and against wire fixtures written from the sources listed above. The manual smoke test in section 11 is the way to close this gap.
- The wording and fields of the Saudi flow after `action_process_edi_web_services` (certificate onboarding, chain index, the exact `edi_state` values on every release) follow the source of `account_edi` and `l10n_sa_edi`; they were not run against the ZATCA sandbox.
- The Enterprise source (modules that exist only in Odoo Enterprise) was not available; every source path in section 2 is from the Community repository.
- Odoo 20 was only checked for the presence of `/jsonrpc` and `/json/2`; it is treated like 19 (JSON-2).

## 3. Connection settings

Credential fields (`credentialFields()`):

| Key | Secret | Required | Notes |
| --- | --- | --- | --- |
| `address` | no | yes | Normalised to scheme and host only. https is required. |
| `database` | no | yes | Letters, digits, `_`, `.`, `-`, at most 63 characters, checked before any request. |
| `user` | no | no | The sync user's login (email). Needed with the key on Odoo 17 and 18; Odoo 19 uses the key alone. |
| `api_key` | yes | yes | Write-only. No spaces or control characters. |
| `company` | no | no | Name of the company that gets the documents when the user can see several. |

Address rules (`OdooAddressNormalizer`): only `http` and `https`; no credentials in the address; path, query and fragment are dropped; a trailing slash is removed; private-network hosts are refused (loopback, RFC 1918, link-local, CGNAT, IPv6 private and mapped forms, decimal and hex IPv4 spellings, and the names `localhost`, `*.localhost`, `*.local`, `*.internal`, `*.lan`, `*.localdomain`, `*.home.arpa`, `*.intranet`, `*.corp`) unless the filter `estasoft_sync_allow_private_hosts` returns true. Plain `http` is accepted only for a private host and only when that filter returns true. Refusals are SYNC-VAL-002.

Options (read from the connection's `config.options`, or the same keys directly in the config; `OdooOptions`):

| Key | Values | Default |
| --- | --- | --- |
| `documents` | `order`, `order_invoice`, `order_invoice_payment` | `order` |
| `invoice_state` | `post`, `draft` | `post` (only when invoices are made) |
| `refund_state` | `draft`, `post` | `draft` |
| `customer_policy` | `match_or_create`, `match_only`, `always_create` | `match_or_create` |
| `duplicate_customer` | `oldest`, `fail` | `oldest` |
| `missing_product` | `fail`, `create` | `fail` |
| `create_service_products` | yes or no | yes |
| `call_interval_ms` | 0 to 10000 | 1000 |
| `shipping_product`, `fee_product`, `discount_product`, `refund_product` | internal reference of a service product | built-in defaults, created when missing |

## 4. Pacing, timeouts and errors

- One request at a time, about one per second (`rateLimitHints()` returns 1.0 and concurrency 1). `PacedHttpClient` waits between calls, never more than 5 s per wait; `call_interval_ms = 0` switches it off; `test()` is not paced.
- 30 s timeout on every request.
- Clients are cached for 300 s per connection (version, sign-in result, `fields_get` answers).

| Situation | Code | Class |
| --- | --- | --- |
| Time-out, connection failed, gateway 408/504/524, unreadable (non-JSON) answer | SYNC-NET-001 | retry |
| HTTP 5xx, unknown server fault, database connection failure | SYNC-NET-002 | retry |
| Untrusted or invalid TLS certificate | SYNC-NET-003 | needs a person |
| HTTP 401, `authenticate` returned false, `AccessDenied`, `Unauthorized` | SYNC-AUTH-001 | needs a person |
| Session expired (JSON-RPC code 100) | SYNC-AUTH-002 | needs a person |
| `AccessError` or `Forbidden` (the sync user lacks a right) | SYNC-AUTH-004 | needs a person |
| Odoo Online without external API access (see "Not verified") | SYNC-AUTH-006 | needs a person |
| Database does not exist or is not served here | SYNC-CFG-003 | needs a person |
| HTTP 429 (with `Retry-After` when given) | SYNC-RATE-001 | retry after the wait |
| `LockError`, `ConcurrencyError`, serialisation failure, deadlock | SYNC-NET-002 (status 409) | retry |
| `MissingError` (record deleted between two steps) | SYNC-VAL-002 | retry |
| `UserError`, `ValidationError`, database constraint errors, wrong parameters | SYNC-VAL-002 | needs a person |
| Missing model or method (an app is not installed, or the version lacks it) | SYNC-VAL-002 | needs a person |
| Missing product (SKU not in Odoo, `missing_product = fail`) | SYNC-MAP-001 | needs a person |
| Missing customer email | SYNC-MAP-003 | needs a person |
| Redirect, or an Odoo version older than 17 | SYNC-VAL-002 | needs a person |
| An unexpected PHP error inside the connector (safety net) | SYNC-NET-002 | retry |

`classifyError()` first returns the class the mapper set on the error (for example retry for `MissingError`); then retry when the error detail names a retryable fault or the HTTP status is 409, 423 or 425; otherwise it returns `null` and the engine uses the catalog class of the code.

Secrets: the API key, the login and the database name are scrubbed from every message before it is stored in an error. The contract test `OdooConnectorContractTest` checks that no secret appears in any result or error text.

## 5. Order flow

`send()` for an order item:

1. Parse and check the mapped payload (before any request): customer email and name, product lines, amounts as integer minor units.
2. `findExisting()`/`send()` look for a `sale.order` with `client_order_ref = WC-{order id}` first, so a repeat after a lost answer finds the order instead of creating a second one. A duplicate search that finds several returns the oldest.
3. Resolve products (by internal reference, `default_code`), taxes (a code made of digits is a tax id, any other code is a tax name; sale taxes of the company only) and the service products for shipping, fees and discounts. No customer and no order is created until products and taxes are resolved (service products, and with `missing_product = create` missing products, can be created while resolving).
4. Find or create the customer (`res.partner`) by email, per `customer_policy`; the delivery address becomes a child partner of type `delivery` when it differs.
5. Create the `sale.order` with its lines (the tax field is `tax_id` or `tax_ids` by version, the warehouse is set when the payload names one), then, by `documents` and the payload's `flow` keys:
   - confirm (`action_confirm`);
   - create the invoice through the `sale.advance.payment.inv` wizard (`advance_payment_method = delivered`), post it (`action_post`) unless `invoice_state = draft`;
   - register the payment through the `account.payment.register` wizard (`action_create_payments`) for `order_invoice_payment`;
   - on a Saudi company with the e-invoicing module, call `action_process_edi_web_services`; a failure there does not undo the invoice and is shown through `fetchInvoice()`.
6. The result's external reference is the order's id and name; the record link uses `/odoo/<model>/<id>` from Odoo 18 (the route exists in 18.0 and not in 17.0) and `/web#id=<id>&model=<model>&view_type=form` on 17.

Order payload paths (target paths of the default "orders" mapping, see `OdooDefaultMappings`): `partner.*` (name, email, phone, company_name, street, street2, city, zip, state_id, country_id, vat), `delivery.*`, `order_line[].product` (SKU), `order_line[].product_id`, `order_line[].name`, `order_line[].quantity`, `order_line[].price_unit`, `order_line[].discount`, `order_line[].tax`, `shipping.{name,price,tax}`, `fee[].{name,price,tax}`, `discount.{name,price}`, `currency`, `date_order`, `note`, `payment_term`, `warehouse`, `payment.reference`, `flow.{confirm,invoice,register_payment}`. The client reference is always taken from the queue item (`WC-{id}`), never from the payload.

Money is integer minor units everywhere inside the plugin. `OdooAmounts` converts at the edge only, using the currency's exponent (0, 2 or 3 decimals; KWD, BHD and OMR use 3) and rounds half away from zero; amounts are never compared as floats. Dates are UTC `Y-m-d H:i:s`.

## 6. Refund flow

A refund item creates a credit note (`account.move`, `move_type = out_refund`) directly, with lines taken from the refund payload, linked to the original order through `invoice_origin` (the order's name) and, when the order was invoiced, to its invoice through `reversed_entry_id`. Idempotency key: `ref` is `WC-{refund id}`, followed by a space and the refund reason when there is one; an existing credit note whose `ref` is exactly that or begins with it followed by a space is returned instead of creating another. The order must exist in Odoo (`WC-{order id}`), otherwise the item fails before any other request. `refund_state = post` (or `flow.post`) posts the note. On a Saudi company, Odoo 19 gets `l10n_sa_reason` (the payload's `zatca_reason`, else `BR-KSA-17-reason-4`); Odoo 17 and 18 have no such field on `account.move` and nothing is sent.

## 7. Reading from Odoo

- `fetchStock($skus, $warehouses)`: `product.product` read with the warehouse in the context (`warehouse` in 17, `warehouse_id` from 18), which is how Odoo itself computes the on-hand figure for chosen warehouses; on hand is `qty_available`, held is `qty_available - free_qty`. Products that are not stocked (services, consumables without tracking: `type != 'product'` in 17, `is_storable` false from 18) have no quantity and are left out.
- `fetchTracking($since)`: `stock.picking` records changed since the date (UTC), with carrier and `carrier_tracking_ref` when the Delivery app is installed.
- `fetchInvoice($ref)`: invoice number, state, totals in minor units, payment state; for Saudi companies the e-invoice status (`edi_state`, error text, QR text, issue time).
- `listForReconcile($from)`: orders with `client_order_ref` starting `WC-` since the date, with totals, invoiced amount and refunded amount (credit notes summed by reversed invoice or `invoice_origin`, draft and posted).
- `lookupCustomer`, `lookupProduct`, `searchProducts`, `listTaxes`: read-only helpers for the mapping screens.
- `describeFields($type)` lists Odoo fields for `orders`, `customers`, `products`, `refunds`, and for the inbound types `stock` and `tracking` the Odoo source fields (their targets are WooCommerce paths such as `product.stock_quantity`, `order.tracking.number`). `defaultMapping($type)` returns rules that pass the mapping engine's `RuleValidator`.

## 8. United Arab Emirates and Saudi Arabia

- UAE: the Odoo source for 17.0, 18.0, 19.0 and 20.0 has only the accounting localisation `l10n_ae` (and `l10n_ae_pos`) and no UAE e-invoicing module; the test screen shows a note for a UAE company (`uae_no_einvoicing_module`). The Enterprise source was not available, so a separate Enterprise module is not excluded. VAT is handled through ordinary taxes (`account.tax`), matched by name or code from the mapping; a tax the connector cannot find stops the order with SYNC-MAP-002 so a total is never silently wrong.
- Saudi Arabia: `test()` reports whether `l10n_sa_edi` is installed and whether the company is in production (`l10n_sa_edi_is_production` on 19, `l10n_sa_api_mode == 'prod'` on 17 and 18). The plugin never touches ZATCA itself: it only asks Odoo to process the invoice and reads the result back.
- Arabic text (names, addresses, notes) is sent and read as UTF-8 unchanged, and no language is forced on the call (the context carries only the company).

## 9. Tests

All tests run without WordPress or a network: `phpunit -c phpunit.xml.dist --filter Connectors` (516 tests at the time of writing).

| Path | Purpose |
| --- | --- |
| `tests/php/Support/FakeHttpClient.php` | Scripted `HttpClientInterface`: queued answers, routes, time-outs, certificate errors, 429 with `Retry-After`, 5xx, malformed JSON; records every request |
| `tests/php/Support/FakeOdooServer.php`, `FakeOdooDatabase.php`, `FakeOdooDomain.php`, `FakeOdooFault.php` | A small in-memory Odoo for 17, 18, 19 and Online that answers the calls the connector makes over both transports (domains, `fields_get`, wizards, duplicates, access rights, lost answers, plan block) |
| `tests/php/Contract/ConnectorContractTestCase.php` | Contract rules of the specification, section 10; any connector's test extends it |
| `tests/php/Unit/Connectors/Odoo/OdooConnectorContractTest.php`, `OdooConnectorJson2ContractTest.php` | The contract rules for Odoo 18 (JSON-RPC) and Odoo 19 (JSON-2) |
| `tests/php/Unit/Connectors/Odoo/*Test.php` | Every method and error path by area: address, amounts, version, transports, error mapper, client factory, send order, refund, lookups, inbound reads, connection test, mapping integration, safety |
| `tests/php/Unit/Connectors/Odoo/OdooWireFixturesTest.php` and `tests/php/fixtures/odoo/` | Wire-format bodies (`version/`, `faults/`) written from the Odoo sources in section 2, replayed through the detector and both transports. They are not recordings from a live server and hold no secrets. |

Code style and types: `phpcs` with the WordPress standard (code under `includes/`), `phpstan` at level 8.

## 10. Known gaps

- No live Odoo run yet (see "Not verified").
- Prices are not read back from Odoo (`PricesIn` is false) and Odoo webhooks are not used (`Webhooks` is false); changes in Odoo are found by the scheduled reads in section 7.
- Multi-company works through the `company` setting; a user who cannot see that company gets SYNC-AUTH-004.
- Variants: products are matched on the variant's internal reference; Odoo product templates without a reference on the variant cannot be matched.
- Lots, serial numbers and multi-step deliveries are not modelled; stock is the on-hand and free quantity per warehouse.

## 11. Manual smoke test against a real Odoo

No credentials are stored in the repository. Use a throw-away server and a key you create yourself.

1. Start Odoo and PostgreSQL with the official images (pick one of `odoo:17.0`, `odoo:18.0`, `odoo:19.0`):

   ```
   docker network create odoo-smoke
   docker run -d --name odoo-db --network odoo-smoke -e POSTGRES_USER=odoo -e POSTGRES_PASSWORD=odoo -e POSTGRES_DB=postgres postgres:15
   docker run -d --name odoo-app --network odoo-smoke -p 8069:8069 -e HOST=odoo-db -e USER=odoo -e PASSWORD=odoo odoo:19.0
   ```

2. Open http://localhost:8069, create a database (for example `smoke`, with demo data) and install Sales, Inventory, Invoicing and, for tracking, the Delivery app. For a Saudi test, set the company country to Saudi Arabia and install `l10n_sa_edi`; use the ZATCA sandbox mode only.
3. Create a sync user (not the administrator if you can avoid it) with rights to Sales, Inventory and Invoicing, sign in as that user, and create an API key under Preferences, Account Security, New API Key (a key without a scope; on Odoo 19 it must be a global key). Copy it once.
4. In WordPress, allow the private address with a must-use plugin of your own (never commit it):

   ```
   add_filter( 'estasoft_sync_allow_private_hosts', '__return_true' );
   ```

   If WordPress runs in Docker too, use `http://host.docker.internal:8069` or the container name as the address.
5. Add an Odoo connection with address `http://localhost:8069`, database `smoke`, the user's login (17 and 18; optional on 19) and the key. Press Test connection. Expected: the version (17, 18 or 19), the company, the currency, the warehouses and the list of installed apps; a wrong key gives SYNC-AUTH-001, a wrong database SYNC-CFG-003, a user without Sales rights SYNC-AUTH-004 when an order is sent.
6. Place a WooCommerce order whose product SKU equals an Odoo product's internal reference. Expected: one sale order whose Customer Reference is `WC-{order id}`; run the sync again for the same order and confirm that no second order appears. Repeat with `documents = order_invoice` and `order_invoice_payment` and check the invoice and payment.
7. Refund the order in WooCommerce; expected: one credit note whose reference starts with `WC-{refund id}`.
8. Change the on-hand quantity of a product in Odoo and run the stock read; ship a delivery with a tracking reference and run the tracking read.
9. Run it once per Odoo version (17, 18, 19). To check the transport choice, watch the Odoo log: 17 and 18 show `POST /jsonrpc`, 19 shows `POST /json/2/...` and `GET /web/version`.
10. Remove the containers and the key when done: `docker rm -f odoo-app odoo-db`.

On Odoo Online the address is `https://<name>.odoo.com`, the database is `<name>`, and external API access needs a Custom plan; with another plan the connection test is expected to report SYNC-AUTH-006 (unconfirmed, see "Not verified").
