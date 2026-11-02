---
id: odoo
name: Odoo
catalog_row: Odoo
connection_method: key
available: true
verified: false
hosts: []
terms_url: ""                  # not verified by fetch (odoo.com is not reachable from the build session)
privacy_url: ""                # not verified by fetch (odoo.com is not reachable from the build session)
developer_url: https://www.odoo.com/documentation/19.0/developer/reference/external_api.html
variants: []
---

# Odoo connector

Connector id `odoo`, type ERP, contract version 1. Code: `includes/Connectors/Odoo/`. Registration: `includes/Connectors/ConnectorsModule.php`, which adds the connector through the `estasoft_sync_register_connectors` filter (third-party connectors register the same way).

The connector sends WooCommerce orders (as sale orders, optionally with invoice and payment), refunds (as credit notes) and customers to Odoo, and reads stock, delivery tracking and invoice status back. Every network call goes through `Contract\HttpClientInterface`; the connector never calls `wp_remote_*`.

Supported Odoo: 17.0, 18.0, 19.0 and later (Community, Enterprise and Odoo Online). Odoo 16 and older are refused with SYNC-VAL-002 ("Use Odoo 17 or later").


## How the merchant connects

1. In Odoo, make a user just for syncing, with rights to Sales, Inventory and Invoicing.
2. Sign in as that user and open Preferences, Account Security, then New API Key. Copy the key once.
3. In WordPress open Estasoft Sync, Connections, Add a connection, and choose Odoo.
4. Enter the address you use to sign in to Odoo, the database name, the user's email (Odoo 17 and 18) and the key.
5. Press Test connection. The plugin shows the Odoo version, the company and the warehouses it found.

The plugin contacts only the address you enter. It refuses private network addresses unless the filter `estasoft_sync_allow_private_hosts` allows them.

## 1. Transports

| Odoo | Transport class | Wire format |
| --- | --- | --- |
| 17, 18, Online saas~17.x and saas~18.x | `JsonRpcTransport` | `POST {base}/jsonrpc`, service `common` (`authenticate`, `version`) and `object` (`execute_kw`), the API key is sent as the password |
| 19 and later | `Json2Transport` | `POST {base}/json/2/{model}/{method}`, header `Authorization: bearer <key>`, header `X-Odoo-Database: <db>`, named parameters in a JSON object, `ids` and `context` as keys of the body |

Both implement `OdooTransport`. `OdooVersionDetector` chooses by major version: it asks `GET /web/version` first, and when that route does not exist it asks `common.version` over `/jsonrpc`. Neither call needs a sign-in. The database service of Odoo (`db.list` and similar) is never called.

`OdooClient` adds what is the same for both: the company context (`allowed_company_ids`, on every call), a cache of `fields_get` answers, version-dependent field names (see section 5) and links to records. The one-call-at-a-time pacing is done below it by `PacedHttpClient` (section 4).

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
| `account.move` has `edi_state` (`to_send`, `sent`, `to_cancel`, `cancelled`), `edi_blocking_level` (`info`, `warning`, `error`) and `edi_error_message`; `action_process_edi_web_services()` only handles documents in `to_send` or `to_cancel` whose blocking level is not `error`, so calling it again is harmless | `addons/account_edi/models/account_move.py` (17, 18, 19) |
| `sale.order.action_confirm()` writes `date_order = now` (through `_prepare_confirmation_values`), so the date the order was created with is lost on confirmation | `addons/sale/models/sale_order.py` (17, 18, 19) |
| The currency of a sale order is its price list's currency (company currency when there is no price list); the price list is computed from the customer unless it is given; a price list may belong to no company or to the order's company | `addons/sale/models/sale_order.py`: `_compute_currency_id`, `_compute_pricelist_id`, the `pricelist_id` domain (17, 18, 19); `addons/product/models/product_pricelist.py` (`sequence`, `currency_id`, `company_id`, `active`) |
| `account.tax.price_include` ("Included in Price") is a stored boolean in 17. In 18 and 19 it is computed from the tax's own `price_include_override` or, when that is empty, the company's `account_price_include`, and reads like any field. `amount_type` is `percent`, `fixed`, `division` or `group` | `addons/account/models/account_tax.py`, `addons/account/models/company.py` (17, 18, 19) |
| Without `allowed_company_ids` in the context, a call sees the records of every company the user belongs to; with it, only those companies. The company in use (`env.company`) is the first one named | `Environment.companies` in `odoo/api.py` (17, 18) and `odoo/orm/environments.py` (19) |
| `sale.order` can be deleted only in state `draft` or `cancel`, and only by the Sales Administrator group (the Sales "own documents" group can read, write and create but not delete) | `_unlink_except_draft_or_cancel` in `addons/sale/models/sale_order.py`; `addons/sale/security/ir.model.access.csv` (17, 18, 19) |
| `stock.picking.sale_id` is a stored many2one to the sale order (module `sale_stock`), so a search can follow it: `sale_id.client_order_ref` | `addons/sale_stock/models/stock.py` (17, 18, 19) |
| Saudi ZATCA: `l10n_sa_uuid` (module `l10n_sa_edi`), `l10n_sa_qr_code_str` and `l10n_sa_confirmation_datetime` (module `l10n_sa`) exist in 17, 18 and 19 | `addons/l10n_sa_edi/models/account_move.py`, `addons/l10n_sa/models/account_move.py` |
| `l10n_sa_reason` (selection `BR-KSA-17-reason-1` to `-5`; `-4` is "goods or services refund") is a field of `account.move` in 19 only; none of the 17.0 and 18.0 ZATCA modules define it | `addons/l10n_sa/models/account_move.py` (19.0) |
| The company's ZATCA mode is `l10n_sa_api_mode` (`sandbox`, `preprod`, `prod`) in 17, 18 and 19; the boolean `l10n_sa_edi_is_production` exists on `res.company` from 19 | `addons/l10n_sa_edi/models/res_company.py` |

Everything above is in the Community repositories. Odoo Enterprise modules were not in the clone; where the connector relies on behaviour of an Enterprise-only module nothing is claimed here.

How the connector uses them:

- The transport is chosen by major version (19 and later: JSON-2; 17 and 18: JSON-RPC). A version with a string major (Odoo Online `saas~18.x`) is read as that number.
- Every call carries `allowed_company_ids = [the company in use]`, and every lookup of customers, products, taxes and price lists also asks for `company_id in (false, the company in use)`, so records shared by all companies stay visible and another company's records are never picked.
- Installed apps are detected by `fields_get` probes (`sale.order`, `stock.warehouse`, `account.move`, `stock.picking.carrier_tracking_ref`, `account.move.l10n_sa_uuid`), because an ordinary sync user cannot read `ir.module.module`.
- The version-dependent names above are chosen by `OdooClient::version()` or by asking `fields_get` whether the field exists.

### Not verified

- Odoo Online: the wording of the refusal on a plan without external API access is not documented. `OdooErrorMapper` reads a refusal as SYNC-AUTH-006 only on an `odoo.com` address, and only for a 403 answer without a JSON body, or for an access refusal (`AccessDenied`, `AccessError`, `Forbidden`, or a fault without a Python name) whose message names the external API, XML-RPC, JSON-RPC, JSON-2, API access or outside access, or names a plan or subscription together with the API. A `UserError`, `ValidationError` or any other business error that happens to say "subscription" or "plan" is never read this way. Treat the heuristic as unconfirmed until it has met a real Online database on a Standard plan.
- A JSON-RPC call to a database that does not exist is expected to fail with `psycopg2.OperationalError: ... database "x" does not exist`; this comes from reading the code path (`Registry(db)`), not from a live run. The mapper also accepts the JSON-2 "No database is selected" page.
- Odoo's own rate limiting: Odoo does not document HTTP 429 for the external API. The connector honours 429 and `Retry-After` because proxies and hosting platforms in front of Odoo send them.
- No live Odoo server was available. All behaviour above the wire was tested against `FakeOdooServer` (an in-memory Odoo for versions 17, 18, 19 and Online) and against wire fixtures written from the sources listed above. The manual smoke test in section 11 is the way to close this gap.
- The wording and fields of the Saudi flow after `action_process_edi_web_services` (certificate onboarding, chain index, the exact `edi_state` values on every release) follow the source of `account_edi` and `l10n_sa_edi`; they were not run against the ZATCA sandbox.
- Rounding of taxes that are included in the price: the connector converts a price with whole-number arithmetic per unit, while Odoo rounds per line (or globally, by the company's rounding setting). A line with a large quantity or an order of many lines can differ from the shop by more than the one minor unit that the total check accepts; the check then reports it instead of letting it pass. This was tested against the fake only.
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
| `company` | no | no | Name (or id) of the company that gets the documents when the user can see several. Empty: the sync user's own default company. A name Odoo does not know, or the user cannot see, is SYNC-VAL-002. |

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
| `pricelist` | name or id of the price list to use for every order; it must be in the order's currency | empty: the first active price list in the order's currency, by sequence |
| `create_pricelist` | yes or no: make a price list ("WooCommerce XXX", for the company in use) when Odoo has none in the order's currency | no |
| `total_mismatch` | `fail`: a total that differs by more than one minor unit stops the order before it is confirmed; `warn`: the order goes on and the result carries a warning | `fail` |
| `shipping_product`, `fee_product`, `discount_product`, `refund_product` | internal reference of a service product | built-in defaults, created when missing |

## 4. Pacing, timeouts and errors

- One request at a time, about one per second (`rateLimitHints()` returns 1.0 and concurrency 1). `PacedHttpClient` waits between calls, never more than 5 s per wait; `call_interval_ms = 0` switches it off; `test()` is not paced.
- 30 s timeout on every request.
- Clients are cached for 300 s per connection (version, sign-in result, `fields_get` answers).

| Situation | Code | Class |
| --- | --- | --- |
| Time-out, reset or refused connection (including "SSL connection timeout" and "SSL_connect: Connection reset"), name not resolved, gateway 408/504/524, unreadable (non-JSON) answer | SYNC-NET-001 | retry |
| HTTP 5xx, unknown server fault, database connection failure (only the kind of error is kept, never its text) | SYNC-NET-002 | retry |
| A certificate that fails verification (self-signed, unknown issuer, expired, wrong host name) | SYNC-NET-003 | needs a person |
| HTTP 401, `authenticate` returned false, `AccessDenied`, `Unauthorized` | SYNC-AUTH-001 | needs a person |
| Session expired (JSON-RPC code 100) | SYNC-AUTH-002 | needs a person |
| `AccessError` or `Forbidden` (the sync user lacks a right) | SYNC-AUTH-004 | needs a person |
| Odoo Online without external API access (narrow patterns, see "Not verified") | SYNC-AUTH-006 | needs a person |
| Database does not exist or is not served here | SYNC-CFG-003 | needs a person |
| HTTP 429 (with `Retry-After` when given) | SYNC-RATE-001 | retry after the wait |
| `LockError`, `ConcurrencyError`, serialisation failure, deadlock | SYNC-NET-002 (status 409) | retry |
| `MissingError` (record deleted between two steps) | SYNC-VAL-002 | retry |
| `UserError`, `ValidationError`, database constraint errors, wrong parameters | SYNC-VAL-002 | needs a person |
| Missing model or method (an app is not installed, or the version lacks it) | SYNC-VAL-002 | needs a person |
| Missing product (SKU not in Odoo, `missing_product = fail`) | SYNC-MAP-001 | needs a person |
| Tax code not found in Odoo | SYNC-MAP-002 | needs a person |
| No price list in the order's currency; the order came back in another currency; a tax that does not treat prices as the shop does; a total that differs (`total_mismatch = fail`); a named company that does not exist | SYNC-VAL-002 | needs a person |
| Missing customer email | SYNC-MAP-003 | needs a person |
| Redirect, or an Odoo version older than 17 | SYNC-VAL-002 | needs a person |
| An unexpected PHP error inside the connector (safety net) | SYNC-NET-002 | retry |

`classifyError()` first returns the class the mapper set on the error (for example retry for `MissingError`); then retry when the error detail names a retryable fault or the HTTP status is 409, 423 or 425; otherwise it returns `null` and the engine uses the catalog class of the code.

Secrets: the API key and the login are scrubbed from every message before it is stored in an error. The database name is taken out of Odoo's own texts where it stands in quotes or after `dbname=` (PostgreSQL writes `database "name" does not exist`); the SYNC-CFG-003 message still names the database, because the person typed it and needs to see which one is wrong. Words that are not quoted are left alone, so a database called `sale` does not damage messages. Database connection errors keep only their kind (for example `database error OperationalError`), because their text can hold the PostgreSQL host, port or user. The contract test `OdooConnectorContractTest` checks that no secret appears in any result or error text.

## 5. Order flow

`send()` for an order item:

1. Parse and check the mapped payload before any request: customer email and name, product lines, amounts as integer minor units.
2. Look for a `sale.order` with `client_order_ref = WC-{order id}` (and the company in use). If there is one it is never created again: the pass carries on from where the order stands (see "Every pass starts from a read" below). A search that finds several returns the oldest.
3. Currency and price list. Odoo takes the currency of an order from its price list and the price list from the customer, so a customer whose price list is in another currency would get an order in that currency whatever the shop charged. The order is therefore always pinned to a price list: the payload's currency (the company currency when the payload has none) decides, the option `pricelist` can name the list, and shared lists (no company) count as well as the company's own. With no active price list in that currency the order is refused with SYNC-VAL-002 naming the currency, before any customer or order is created, unless `create_pricelist` is on, which makes one. After the order is created its currency is read back; if it is not the payload's, the quotation is removed and nothing is confirmed.
4. Resolve products (by internal reference, `default_code`; the company in use), taxes (a code made of digits is a tax id, any other code is a tax name; sale taxes of the company only) and the service products for shipping, fees and discounts. Each tax's name, rate, type and "Included in Price" flag are read, and every price is made to agree with the tax (see "Prices and taxes"). No customer and no order is created until products, taxes and prices are settled (service products, and with `missing_product = create` missing products, can be created while resolving).
5. Find or create the customer (`res.partner`) by email, per `customer_policy`; the delivery address becomes a child partner of type `delivery` when it differs.
6. Create the `sale.order` with its lines (the tax field is `tax_id` or `tax_ids` by version) and the price list, company, date, warehouse and payment term the payload names.
7. Check the total (see "The total check"). On a quotation that fails, the connector tries to remove it again; only a Sales Administrator can delete, so with an ordinary sync user the quotation usually stays in Odoo as a draft and the error says so. The next pass finds that draft, checks it again and does not make a second one. Correcting a tax setting does not recalculate a quotation that already exists, so the person deletes the draft in Odoo and the next pass makes a new one.
8. Go through the steps the options and the payload's `flow` keys ask for:
   - confirm (`action_confirm`), then write the shop's order date back: Odoo sets the date of an order to the moment of confirmation, and the shop's date is the one that belongs on the order and the one reconcile windows use;
   - create the invoice through the `sale.advance.payment.inv` wizard (`advance_payment_method = delivered`), post it (`action_post`) unless `invoice_state = draft`;
   - on a Saudi company with the e-invoicing module, ask Odoo to send each posted invoice that is waiting (`edi_state = to_send`) to the tax authority service (`action_process_edi_web_services`);
   - register the payment through the `account.payment.register` wizard (`action_create_payments`) for `order_invoice_payment`.
9. The result's external reference is the order's id and name; the record link uses `/odoo/<model>/<id>` from Odoo 18 (the route exists in 18.0 and not in 17.0) and `/web#id=<id>&model=<model>&view_type=form` on 17. Its `extra` has the total in minor units, the currency, the state and, when a difference was accepted (`total_mismatch = warn`), a `warnings` list.

### Every pass starts from a read

A pass never assumes what the previous one did. It reads the order, its invoices and their tax-service state first, and does only what is missing.

- Steps that are safe to run twice (confirm, post, write the date, ask the tax service) are tried once more at once after a failure that may pass by itself, except after a 429 or any error that comes with a wait time: that is returned at once and the queue waits as told.
- Steps that make something new (the invoice, the payment) are run once. If one fails in a way that may have reached Odoo (a time-out, a lost answer), the connector reads the invoice or payment state again: if it is there the pass goes on from it; if not, the failure is returned and the next pass starts from a fresh read. Nothing is replayed blindly, so a lost answer cannot create a second invoice or payment.
- The engine links whatever `findExisting()` returns and does not call `send()` for it. So `findExisting()` reports an order as found only when its steps are done, according to the connection's options: a quotation that is not confirmed, an invoice not made or not posted, a posted invoice still waiting for the tax service (and not blocked by an error), or an unpaid invoice when payment is wanted makes it return nothing. `send()` then finds the order, finishes the steps and says it already existed. The same rule applies to credit notes. A document that Odoo's tax service blocked with an error that needs a person does not keep the item open. Shadow checks see an unfinished record as not found.

### Prices and taxes

The prices in the payload are without tax by default, because the WooCommerce reader supplies line subtotals, which WooCommerce stores without tax; a mapping that sends gross prices must set `prices_include_tax`. Odoo, for its part, treats a tax either as added to the price or as already inside it ("Included in Price", a field of the tax; from Odoo 18 it follows the tax's own setting or the company's default). When the two sides disagree for a line, the price is converted with whole-number arithmetic (minor units, the rate in ten-thousandths of a percent, rounding half away from zero) if the line has one plain percentage tax; if the line has several taxes, or a fixed, division or group tax, it is refused with SYNC-VAL-002 naming the tax. A tax with a rate of 0 needs no conversion. Lines whose tax is not named in the mapping get the product's default taxes in Odoo; the connector does not read those, and the total check is what catches a difference there. Shipping, fee and discount lines follow the same rules.

### The total check

The mapping supplies `total` (the order total with tax, from `order.total`) and `excluded_total` (the part that belongs to products left out of the sync). The total Odoo works out for the quotation is compared with `total - excluded_total` in minor units. A difference of one minor unit is rounding. More than that stops the order before it is confirmed (`total_mismatch = fail`, the default), or lets it go on with a warning in the result (`warn`). Without a `total` in the payload nothing is compared. For orders with a coupon, give the Discount service product the same default sales tax as the goods, or map `discount.tax`; otherwise Odoo does not tax the discount and the totals differ.

Order payload paths (target paths of the default "orders" mapping, see `OdooDefaultMappings`): `client_order_ref`, `currency`, `date_order`, `note`, `payment_term`, `warehouse`, `total`, `excluded_total`, `prices_include_tax`, `partner.*` (name, email, phone, company_name, street, street2, city, zip, state_id, country_id, vat), `delivery.*`, `order_line[].{product, product_id, name, quantity, price_unit, discount, tax}`, `shipping.{name, total, tax}`, `fee[].{name, total, tax}`, `discount.{name, total}` (and `discount.tax` when a mapping supplies it), `payment.reference`, `flow.{confirm, invoice, register_payment}`. The client reference is always taken from the queue item (`WC-{id}`), never from the payload.

Money is integer minor units everywhere inside the plugin. `OdooAmounts` converts at the edge only, using the currency's exponent (0, 2 or 3 decimals; KWD, BHD and OMR use 3) and rounds half away from zero; amounts are never compared as floats. Dates are UTC `Y-m-d H:i:s`.

## 6. Refund flow

A refund item creates a credit note (`account.move`, `move_type = out_refund`) directly, with lines taken from the refund payload, linked to the original order through `invoice_origin` (the order's name) and, when the order was invoiced, to its invoice through `reversed_entry_id`. The amounts follow the same price and tax rules as order lines (`prices_include_tax`, conversion for a single percentage tax, refusal otherwise). The total of a credit note is not compared with the refund amount (see "Known gaps").

Idempotency key: `ref` is `WC-{refund id}`, followed by a space and the refund reason when there is one; an existing credit note whose `ref` is exactly that or begins with it followed by a space is returned instead of creating another. The order must exist in Odoo (`WC-{order id}`), otherwise the item fails before any other request.

`refund_state = post` (or `flow.post`) posts the note and, on a Saudi company, sends it to the tax authority service. The same steps are done on every pass for a credit note that already exists: a draft that should be posted is posted, and a posted note waiting for the tax service is sent (unless an error blocks it). On a Saudi company, Odoo 19 gets `l10n_sa_reason` (the payload's `zatca_reason`, else `BR-KSA-17-reason-4`); Odoo 17 and 18 have no such field on `account.move` and nothing is sent.

## 7. Reading from Odoo

- `fetchStock($skus, $warehouses)`: `product.product` read with the warehouse in the context (`warehouse` in 17, `warehouse_id` from 18), which is how Odoo itself computes the on-hand figure for chosen warehouses; on hand is `qty_available`, held is `qty_available - free_qty`. Products that are not stocked (services, consumables without tracking: `type != 'product'` in 17, `is_storable` false from 18) have no quantity and are left out.
- `fetchTracking($since)`: finished outgoing deliveries (`stock.picking`, state `done`, `date_done` from the date on, UTC) of sale orders whose client reference starts with `WC-`, with carrier and `carrier_tracking_ref` when the Delivery app is installed. Where deliveries carry their sale order (`sale_id`, module `sale_stock`) the search itself asks for `sale_id.client_order_ref =like 'WC-%'`, so deliveries of orders made in Odoo cannot fill the page of 500. Where they do not, pages are read until one comes back short, 500 matching rows are found, or 20 pages have been read.
- `fetchInvoice($ref)`: the latest customer invoice of the order: number, state, totals in minor units, payment state; for Saudi companies the plugin's `authority_status` (`waiting`, `reported`, `rejected`) next to Odoo's own `edi_state` and `edi_blocking_level`, the error text, the QR text and the issue time. `rejected` means Odoo blocked the document with an error that needs a person; a document that is posted and waiting without such an error is `waiting`.
- `listForReconcile($from)`: orders of the company in use with `client_order_ref` starting `WC-` and an order date (`date_order`) from the date on, with totals, invoiced amount and refunded amount (credit notes summed by reversed invoice or `invoice_origin`, draft and posted). The window uses the order date, which is why the shop's date is written back after confirmation.
- `lookupCustomer`, `lookupProduct`, `searchProducts`, `listTaxes`: read-only helpers for the mapping screens.
- `describeFields($type)` lists Odoo fields for `orders`, `customers`, `products`, `refunds`, and for the inbound types `stock` and `tracking` the Odoo source fields (their targets are WooCommerce paths such as `product.stock_quantity`, `order.tracking.number`). `defaultMapping($type)` returns rules that pass the mapping engine's `RuleValidator`.

## 8. United Arab Emirates and Saudi Arabia

- UAE: the Odoo source for 17.0, 18.0, 19.0 and 20.0 has only the accounting localisation `l10n_ae` (and `l10n_ae_pos`) and no UAE e-invoicing module; the test screen shows a note for a UAE company (`uae_no_einvoicing_module`). The Enterprise source was not available, so a separate Enterprise module is not excluded. VAT is handled through ordinary taxes (`account.tax`), matched from the mapping by id (a code made of digits) or by name; a tax the connector cannot find stops the order with SYNC-MAP-002, and a tax that treats prices differently from the shop is converted or refused, so a total is never silently wrong.
- Saudi Arabia: `test()` reports whether `l10n_sa_edi` is installed and whether the company is in production (`l10n_sa_edi_is_production` on 19, `l10n_sa_api_mode == 'prod'` on 17 and 18). The plugin never touches ZATCA itself: on every pass it asks Odoo to process a posted invoice or credit note that is waiting (`edi_state = to_send`, not blocked by an error), and reads the result back. The EDI cron ships switched off, so without this a document whose first attempt failed would wait for ever.
- Arabic text (names, addresses, notes) is sent and read as UTF-8 unchanged, and no language is forced on the call (the context carries only the company in use).

## 9. Tests

All tests run without WordPress or a network: `phpunit -c phpunit.xml.dist --filter Connectors` (591 tests at the time of writing).

| Path | Purpose |
| --- | --- |
| `tests/php/Support/FakeHttpClient.php` | Scripted `HttpClientInterface`: queued answers, routes, time-outs, certificate errors, 429 with `Retry-After`, 5xx, malformed JSON; records every request |
| `tests/php/Support/FakeOdooServer.php`, `FakeOdooDatabase.php`, `FakeOdooDomain.php`, `FakeOdooFault.php` | A small in-memory Odoo for 17, 18, 19 and Online that answers the calls the connector makes over both transports (domains, `fields_get`, wizards, duplicates, access rights, lost answers, plan block). It models on purpose the behaviours that once hid bugs: an order takes its currency from its price list (the customer's when none is given), taxes can be included in the price, confirming an order sets its date to now, a posted document waits in `edi_state = to_send` until the e-invoicing action runs and an error blocks it, a user of two companies sees both unless the context names one, and a delivery's sale order can be searched through `sale_id.client_order_ref`. It does not guard against everything Odoo guards (for example a second `create_invoices` is not refused), so a test that must prove "not repeated" counts the calls. |
| `tests/php/Contract/ConnectorContractTestCase.php` | Contract rules of the specification, section 10; any connector's test extends it |
| `tests/php/Unit/Connectors/Odoo/OdooConnectorContractTest.php`, `OdooConnectorJson2ContractTest.php` | The contract rules for Odoo 18 (JSON-RPC) and Odoo 19 (JSON-2) |
| `tests/php/Unit/Connectors/Odoo/*Test.php` | Every method and error path by area: address, amounts, version, transports, error mapper, client factory, send order, refund, lookups, inbound reads, connection test, mapping integration, safety |
| `OdooConnectorOrderChecksTest.php` | Price list and currency pinning, price-included taxes, the total check, the order date after confirmation, and two companies with the same names |
| `OdooConnectorStepsTest.php` | Which steps are repeated and which never are, the Saudi tax service step on later passes, credit notes that already exist, 429 handling |
| `tests/php/Unit/Connectors/Odoo/OdooWireFixturesTest.php` and `tests/php/fixtures/odoo/` | Wire-format bodies (`version/`, `faults/`) written from the Odoo sources in section 2, replayed through the detector and both transports. They are not recordings from a live server and hold no secrets. |

Code style and types: `phpcs` with the WordPress standard (code under `includes/`), `phpstan` at level 8.

## 10. Known gaps

- No live Odoo run yet (see "Not verified").
- Prices are not read back from Odoo (`PricesIn` is false) and Odoo webhooks are not used (`Webhooks` is false); changes in Odoo are found by the scheduled reads in section 7.
- Several companies: the connector works in one company (the `company` setting, else the sync user's default company) and uses its records and the records shared by all companies. Making orders in more than one company from one connection is not supported; add one connection per company.
- Prices of lines whose tax is not named in the mapping are sent as they are; the product's default taxes in Odoo apply and are not read, so a difference shows up only in the total check.
- The total of a credit note is not compared with the refund amount; its lines follow the refund payload, and the refund's shipping, fees and rounding that are not in lines are not added.
- The sync user needs the Sales Administrator group to remove a rejected quotation; with an ordinary Sales user it stays as a draft and has to be deleted by a person.
- The warning of `total_mismatch = warn` is carried in the result (`extra["warnings"]`); the engine does not show it yet.
- `findExisting()` reports an unfinished order or credit note as not found (see section 5), so a shadow check shows it as "would be sent".
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
   Also check: the order date in Odoo is the WooCommerce date, not the time of confirmation; for a customer whose price list is in another currency the order is still in the shop's currency; with a sales tax set to "Included in Price" in Odoo the order total still equals the WooCommerce total; with a deliberately wrong tax rate the order is refused with a message naming both totals (`total_mismatch`, default `fail`).
7. Refund the order in WooCommerce; expected: one credit note whose reference starts with `WC-{refund id}`.
8. Change the on-hand quantity of a product in Odoo and run the stock read; ship a delivery with a tracking reference and run the tracking read.
9. Run it once per Odoo version (17, 18, 19). To check the transport choice, watch the Odoo log: 17 and 18 show `POST /jsonrpc`, 19 shows `POST /json/2/...` and `GET /web/version`.
10. Remove the containers and the key when done: `docker rm -f odoo-app odoo-db`.

On Odoo Online the address is `https://<name>.odoo.com`, the database is `<name>`, and external API access needs a Custom plan; with another plan the connection test is expected to report SYNC-AUTH-006 (unconfirmed, see "Not verified").
