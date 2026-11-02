---
id: erpnext
name: ERPNext
catalog_row: ERPNext
connection_method: key
available: true
verified: false
hosts: []
terms_url: https://frappe.io/terms
privacy_url: https://docs.frappe.io/legal/policies/privacy-policy
developer_url: https://docs.frappe.io/framework/user/en/api/rest
variants: []
---

# ERPNext connector

Connector id `erpnext`, type ERP, contract version 1. Code: `includes/Connectors/Erpnext/` (shared order and money helpers in `includes/Connectors/ErpCommon/`). The connector sends WooCommerce orders to ERPNext as Sales Orders (optionally with a Sales Invoice and a Payment Entry), refunds as credit notes (a Sales Invoice with Is Return), and customers with their contact and addresses. It reads stock from the Bin doctype, delivery tracking from Delivery Notes, invoice status from the Sales Invoice, and lists orders for the nightly check.

`hosts` is empty on purpose: ERPNext is the merchant's own site (a Frappe Cloud site, a self-hosted server or a hosting partner), so the only host the plugin contacts is the address the merchant types. Private and loopback addresses are refused unless the filter `estasoft_sync_allow_private_hosts` allows them, as for Odoo.

Supported: Frappe v14 (ended 2026-01-31, still works), v15 and v16. The connector uses only the version-neutral REST routes `/api/resource/...` and `/api/method/...` (the `/api/v2` routes are not used).

## 1. Sources

All fetched or read on 2026-11-02. ERPNext and Frappe are open source (MIT for Frappe, GPL-3.0 for ERPNext), so the exact behaviour was read in the source of the `version-15` (Frappe 15.125.0) and `version-16` (Frappe 16.54.0) branches of https://github.com/frappe/frappe and https://github.com/frappe/erpnext. File paths below are paths in those repositories. Source reading is tagged `[documented]` because it is the vendor's own code; the published pages are tagged `[documented page]`.

| Source | Used for |
| --- | --- |
| https://docs.frappe.io/framework/user/en/api/rest `[documented page]` | `/api/resource/:doctype`, `fields`, `filters`, `or_filters`, `order_by`, `limit_start`, `limit_page_length` (default 20), `Authorization: token api_key:api_secret`, create/read/update/delete, `/api/method`, errors carry `exc_type` |
| https://docs.frappe.io/framework/deployment/rate-limiting `[documented page]` | Site rate limit: fixed window, counts processing time, HTTP 429 with `Retry-After`, headers `X-RateLimit-*`, no default stated |
| `frappe/api/v1.py`, `frappe/api/__init__.py` | Routes; list default 20 rows (`limit_page_length`); create returns the document; delete answers 202 `"ok"`; `POST /resource/<doctype>/<name>` with `run_method` calls a whitelisted method |
| `frappe/client.py` (`get_list`) | List parameters, `filters` as `[[doctype?, field, operator, value]]`, `fields` JSON array, default fields `name` |
| `frappe/auth.py` (`validate_auth`, `validate_auth_via_api_keys`) | `token key:secret` and `Basic base64(key:secret)`; a wrong key or secret raises `AuthenticationError` (401); a disabled user is refused |
| `frappe/exceptions.py`, `frappe/app.py` (`handle_exception`), `frappe/utils/response.py` | HTTP status per exception: `AuthenticationError` 401, `PermissionError` 403, `DoesNotExistError` 404, `NameError` and `DuplicateEntryError` 409, `ValidationError` 417, `TooManyRequestsError` 429, others 500; JSON error body has `exc_type`, `exception` (only when the site allows tracebacks) and `_server_messages` (a JSON string holding a list of JSON strings with `message`) |
| `frappe/model/document.py` | `docstatus` 0 draft, 1 submitted, 2 cancelled; `PUT {"docstatus": 1}` on a draft submits it; `check_if_latest` raises `TimestampMismatchError` when the document changed |
| `erpnext/selling/doctype/sales_order/sales_order.json`, `sales_order.py` | `po_no` (Data, allowed on submit), `transaction_date`, mandatory `delivery_date`, `currency`, `selling_price_list`, `ignore_pricing_rule`, `taxes_and_charges`, `items`; the check that refuses a second Sales Order with the same `po_no` and customer unless Selling Settings allows several |
| `erpnext/accounts/doctype/sales_invoice/*`, `erpnext/utilities/transaction_base.py` | `is_return`, `return_against`, `set_posting_time` (without it `posting_date` is overwritten with today), `update_stock`, `sales_order` and `so_detail` on invoice items |
| `erpnext/controllers/accounts_controller.py`, `taxes_and_totals.py`, `sales_and_purchase_return.py` | `set_taxes` copies the template when `taxes` is empty; a given `rate` is kept; return rules |
| `erpnext/accounts/doctype/payment_entry/payment_entry.json`, `.py` | Fields of a Receive entry and its `references` |
| `erpnext/stock/doctype/bin`, `delivery_note` | `actual_qty`, `reserved_qty`, `projected_qty` per item and warehouse; `lr_no`, `transporter_name`, `lr_date`, `po_no` on Delivery Note |
| `erpnext/setup/utils.py` (`get_exchange_rate`) | Whitelisted method; returns 0 when no rate is known |
| Role lists in the DocType JSON files | Sales User (orders, customers), Stock User (items, bins, delivery notes), Accounts User (invoices, payments) |
| https://frappe.io/terms and https://docs.frappe.io/legal/policies/privacy-policy `[documented page]` | Terms and privacy policy of the vendor (they cover Frappe Cloud and enterprise support; there are no separate developer terms for the REST API of a self-hosted site) |

## 2. How the merchant connects

1. In ERPNext create a user for the sync with the Sales User, Stock User and Accounts User roles.
2. Open that user, open API Access, press Generate Keys and copy the API Secret now, it is shown only once.
3. Paste your site address (https), the API Key and the API Secret into the fields on this screen.
4. Enter the company if your site has several, then press Test connection.
5. In ERPNext give every item the same item code as the WooCommerce SKU, and make one Sales Taxes and Charges Template per tax rate.

Plan gate: none. Frappe Cloud, self-hosted and hosting-partner sites all expose the REST API. The key belongs to a user, so that user's roles, user permissions and company restrictions apply to every call. A site behind a firewall must allow the web host of the shop.

## 3. Authentication

`Authorization: token <api_key>:<api_secret>` on every request (`ErpnextTokenAuth`, a small `AuthStrategy`; the base `HeaderKeyAuth` takes one secret field and ERPNext needs two). The key is a public identifier; both parts are stored encrypted in the connection secrets and are scrubbed from every message. No session, no cookie, no CSRF token is used. API keys of a user do not expire by themselves.

Credential fields: `address` (https site address, normalised to scheme and host, no path), `api_key`, `api_secret` (secret). Advanced fields (config, with the defaults below): `company`, `documents`, `customer_policy`, `customer_group`, `territory`, `price_list`, `warehouse`, `delivery_days`, `shipping_item`, `fee_item`, `discount_item`, `payment_account`, `mode_of_payment`, `update_stock`, `total_mismatch`, `refund_state`.

## 4. Data sent and when (External services disclosure)

Host: the ERPNext address the merchant typed. Nothing else is contacted.

| Action | Data sent | Data read |
| --- | --- | --- |
| Test connection | Nothing | User, companies, currency, Selling Settings defaults, taxes templates, price lists |
| Order synced | Buyer name, email, phone, company, tax number, billing and shipping address, order number, order date, currency, line names, SKUs, quantities, prices, tax codes, shipping, fees, discount, note | Customer by email, contact, address, items by SKU, price list, exchange rate, Sales Order by order number, totals back |
| Invoice and payment (options) | Order number, date, paid amount, payment reference | Sales Invoice linked to the order, its outstanding amount, the receiving account |
| Refund synced | Refund lines, quantities, amounts, reason, refund number | Original invoice, credit note by refund number |
| Stock read (scheduled) | SKUs | Bin quantities |
| Tracking read (scheduled) | Date window | Delivery Notes with `po_no`, transport receipt number, transporter |

## 5. Objects and field mapping

Canonical field to ERPNext field. Order payload paths are the same as the Odoo connector's (`partner.*`, `delivery.*`, `order_line[].*`, `shipping.*`, `fee[].*`, `discount.*`, `payment.reference`); defaults are in `ErpnextDefaultMappings`.

| Payload path | ERPNext field | Notes |
| --- | --- | --- |
| client reference (from the queue item, never the payload) | Sales Order `po_no` | `WC-{order id}`; max 140 characters (Data field) |
| `currency` | `currency` | Must have an enabled selling Price List in this currency |
| `date_order` | `transaction_date` | Date part, in the shop's time zone (`date_utc` with `output_timezone: store` in the default mapping) |
| (option `delivery_days`) | `delivery_date` | Mandatory in ERPNext; `transaction_date` plus the option (default 0) |
| `note` | A `Comment` document on the Sales Order (`comment_type: Comment`, `reference_doctype`, `reference_name`, `content`); `remarks` on the Sales Invoice | The Sales Order has no plain remarks field in `sales_order.json` (v15, v16); a comment is the version-neutral place for a note. Added once, right after the order is created; a failure to add it is a warning, never an error |
| `partner.email` | Customer `email_id`, Contact `email_ids` | |
| `partner.name`, `partner.company_name` | Customer `customer_name` (company name when present, else the name), `customer_type` (`Company` or `Individual`) | |
| `partner.phone` | Customer `mobile_no`, Address `phone` | |
| `partner.vat` | Customer `tax_id` | |
| `partner.street`, `city`, `state_id`, `zip`, `country_id` | Address `address_line1`, `city`, `state`, `pincode`, `country` | Billing address; `country` is the ERPNext Country name found by its two-letter `code` |
| `delivery.*` | A second Address (type Shipping), `shipping_address_name` | Only when it differs from the billing address |
| `order_line[].product` | item `item_code` | SKU equals Item code; unknown SKU: SYNC-MAP-001 |
| `order_line[].quantity`, `price_unit` | item `qty`, `rate` | `rate` is sent as given and kept by ERPNext; `ignore_pricing_rule` is set so no pricing rule changes it |
| `order_line[].tax` | Sales Taxes and Charges Template name | One template per order (see section 8); the template's rows are copied into `taxes` |
| `shipping.total`, `fee[].total`, `discount.total` | item lines with `shipping_item`, `fee_item`; `discount_amount` with `apply_discount_on: Net Total` | See below |
| `payment.reference` | Payment Entry `reference_no` | Only with the payment option |

Shipping and fees: sent as item lines with the item codes the merchant gives in `shipping_item` and `fee_item`, so the order's tax template applies to them as it does in the shop. With no `shipping_item` and a shipping amount above zero, the order is refused with SYNC-VAL-002 naming the option. A discount is sent as the document discount (`discount_amount`, `apply_discount_on: Net Total`), which ERPNext spreads over the lines before tax. An order with a discount and a template that has "Actual" rows is refused (the total could not be checked).

Customer flow (`customer_policy`: `match_or_create` default, `match_only`, `always_create`): search `Customer` by `email_id` (and `disabled = 0`), then, when none, search `Contact` by its `Contact Email` child row and follow its link to a Customer; create when none and the policy allows it. The Customer insert makes the primary Contact itself (`Customer.create_primary_contact` runs on update when `email_id` or `mobile_no` is set). Addresses are separate `Address` documents linked to the customer through `links`.

Required Customer values: `customer_group` and `territory` come from the options, else from Selling Settings (`Selling Settings` single doctype), else the order is refused with SYNC-VAL-002 naming the missing setting.

## 6. Order flow

`send()` for an order item, in this order. Every pass starts by reading where the order stands and carries on from there.

1. Parse the payload (typed minor units) and check what can be checked without a request: email, name, lines, currency, option values.
2. Find the Sales Order `po_no = WC-{id}` with `docstatus != 2`. If it exists the pass continues from it (steps 8 to 10); nothing is created again.
3. Resolve the company (option, else the only company, else refuse), the price list (option, else the first enabled selling Price List whose currency is the order's), the exchange rate (`erpnext.setup.utils.get_exchange_rate` when the currency is not the company's; 0 refuses the order naming both currencies), the warehouse option.
4. Resolve every SKU with one `Item` list call (`item_code in (...)`, `disabled = 0`). Missing: SYNC-MAP-001 before anything is created.
5. Resolve the tax template (section 8). Read its rows and `included_in_print_rate` flags; convert prices when the shop's prices and the template disagree (section 8).
6. Find or create the customer and its addresses.
7. Insert the Sales Order as a draft with the lines, `taxes` copied from the template, `ignore_pricing_rule: 1`, `po_no`, `transaction_date`, `delivery_date`, `selling_price_list`, `currency`, `conversion_rate`, `company`.
8. Read it back: currency must equal the order's and `grand_total` must equal `total - excluded_total` within one minor unit (`total_mismatch = fail` default stops here with the draft left in ERPNext, the next pass finds it, checks it again and does not create a second one). Then submit it (`PUT {"docstatus": 1}`).
9. With `documents = order_invoice` or `order_invoice_payment`: find a Sales Invoice that has an item with `sales_order = {SO}` and `docstatus != 2`; when none, insert one from the Sales Order's items (`sales_order` and `so_detail` filled so the order shows as billed), `po_no`, `set_posting_time = 1` with the order's date, `due_date`, `update_stock` per option, and submit it. A draft invoice is submitted, never inserted twice.
10. With `order_invoice_payment` and a paid order: find a Payment Entry (Receive) that references the invoice with `docstatus != 2`; when none, insert one for the invoice's outstanding amount to `payment_account` and submit it.

The result's reference is the Sales Order name, its link `{address}/app/sales-order/{name}`, and `extra` has the total in minor units, currency, status and the invoice and payment names when they exist. `findExisting()` answers with a reference only when every step the options ask for is finished (a draft order, an unsubmitted invoice or a missing payment makes it return nothing and `send()` finishes the job), the rule of the Odoo connector.

Replay safety: insert and submit are separate calls. After a time-out the next pass reads the Sales Order, the invoice and the payment first. A submit that did reach ERPNext is seen as `docstatus = 1`. Nothing is created twice because every create is preceded by a search of its own reference (`po_no` on the order, the item link on the invoice, the reference rows on the payment).

## 7. Duplicate protection

`po_no` ("Customer's Purchase Order") on the Sales Order holds `WC-{order id}` and is searched before every create. ERPNext itself refuses a second Sales Order with the same `po_no` and customer unless Selling Settings "Allow Multiple Sales Orders Against a Customer's Purchase Order" is on (`sales_order.py`), a second line of defence. Invoices are found through the order link on their items, payments through their reference rows, credit notes through `po_no = WC-{refund id}` and `is_return = 1`. `po_no` is allowed to change after submit (`allow_on_submit`). `findExisting()` skips cancelled documents (`docstatus = 2`) and reports unfinished ones as not found (see section 6).

## 8. Taxes

The tax code of a line is the **name of a Sales Taxes and Charges Template** (for example `UAE VAT 5% - ACME`), chosen through the plugin's tax mapping. `listTaxCodes()` lists the enabled templates of the company (`disabled = 0`, `company`), with the rate shown as the sum of the rows whose `charge_type` is `On Net Total`.

- One order has one template. All lines of an order (and its shipping and fee lines) must name the same code, or none of them a code. Two different codes, or some lines with a code and some without, are refused with SYNC-VAL-002 ("one tax template per order"): ERPNext's document level taxes cannot say which line is taxed by which rate. Orders that mix rates need Item Tax Templates in ERPNext, which this connector does not use (section 15).
- The template's rows are read (`GET Sales Taxes and Charges Template/{name}`) and copied into the document's `taxes` (`charge_type`, `account_head`, `description`, `rate`, `included_in_print_rate`, `cost_center`, `row_id`), so the result does not depend on how ERPNext fills `taxes` on its own.
- Tax-included prices: if the template's rows have `included_in_print_rate = 1`, ERPNext reads `rate` as tax-inclusive. The connector converts the payload's prices (tax-exclusive unless the mapping sets `prices_include_tax`) to the template's reading with whole-number arithmetic when the template has one `On Net Total` row; with several rows, or `Actual` or `On Previous Row` charge types, it refuses naming the template.
- No code on any line: `taxes` and `taxes_and_charges` are sent empty. ERPNext fills `taxes` from a template only in `set_taxes()`, which the REST insert does not call (`accounts_controller.py`), so nothing is added; map tax-free lines to a zero-rated template if the company needs the tax line on the document.
- After the insert the connector compares `grand_total` with the shop total (section 6, step 8).

## 9. Payments

Off by default. `documents = order_invoice_payment` records a Payment Entry (type Receive, party Customer) for a paid order. It needs the option `payment_account` (the bank or cash Account that receives the money; `mode_of_payment` is optional). The account's currency must equal the order's currency, otherwise the order is refused with SYNC-VAL-002 (no exchange differences are booked). The entry references the invoice with `allocated_amount` equal to its outstanding amount; `reference_no` is the payment's transaction id or `WC-{id}`; `reference_date` is the order date. ERPNext fills the receivable account, the account currencies and the rates when it validates the entry (`PaymentEntry.validate`).

## 10. Refunds

A refund is a Sales Invoice with `is_return = 1` (a credit note). Lines have **negative** quantities and positive rates, the same tax template as the order's invoice, `po_no = WC-{refund id}`, `posting_date` with `set_posting_time = 1` from the refund date, `update_stock = 0`. When the original order has a Sales Invoice (created by this connector or by ERPNext staff) it is the `return_against`; ERPNext then checks that the returned quantities do not exceed the invoiced ones (`validate_returned_items`). When there is no invoice the credit note is made without `return_against`. `refund_state = draft` (default) leaves it as a draft, `post` submits it. Refund shipping and fees are added as lines with the shipping and fee items. A refund amount that the lines do not explain is added as one line with the `fee_item`, named "Refund adjustment"; without a `fee_item` it is refused.

The original order must exist in ERPNext (`po_no = WC-{order id}`), otherwise the item fails before any other request with SYNC-VAL-002. The total of the credit note is compared with the refund amount (one minor unit).

## 11. Reading from ERPNext

- Stock (`fetchStock`): `Bin` rows filtered by `item_code in (SKUs)` and, when warehouses are chosen, `warehouse in (...)`, fields `item_code`, `warehouse`, `actual_qty`, `reserved_qty`, paged. Quantity is the sum of `actual_qty`, held is the sum of `reserved_qty`.
- Tracking (`fetchTracking`): submitted Delivery Notes (`docstatus = 1`, `is_return = 0`) with `modified >= since` and `po_no like %WC-%`, fields `name`, `po_no`, `lr_no`, `transporter_name`, `lr_date`, `posting_date`, `modified`, paged. `po_no` can hold several order numbers separated by commas; each exact `WC-{digits}` token becomes one row. The tracking number is `lr_no`; the carrier is `transporter_name`.
- Invoice (`fetchInvoice`): the Sales Invoice linked to the order: name, status, `grand_total`, `outstanding_amount`. `authority_status` is empty (ERPNext core has no tax-authority submission in this API).
- Reconcile (`listForReconcile`): Sales Orders with `po_no like WC-%` and `transaction_date >= from`, `docstatus = 1`, fields name, `po_no`, `grand_total`, `currency`, `contact_email`; refunded amount summed from submitted returns with `po_no` in the order's invoices (read per page).
- `lookupCustomer`, `lookupProduct`, `searchProducts` (`item_code like %q% or item_name like %q%`), `listTaxCodes`: read-only helpers for the mapping screens.

## 12. Rate limits and pacing

Frappe has no per-key request quota. A site may set `rate_limit` in `site_config.json`: a fixed window that counts the **time spent processing** requests, not their number; over the limit the site answers 429 with `Retry-After` (seconds until the window resets) `[documented page]`. The default is not stated. The connector sends one request at a time, about two per second (`rateLimitHints()`: 2.0 and concurrency 1), obeys `Retry-After` and returns SYNC-RATE-001 with the wait; list calls ask for 100 rows a page. Timeout 30 s.

## 13. Error mapping

| ERPNext answer | SYNC code | Class |
| --- | --- | --- |
| Transport time-out, reset, DNS | SYNC-NET-001 | retry |
| Certificate that fails verification | SYNC-NET-003 | needs a person |
| 5xx, 508 deadlock, `QueryDeadlockError`, `QueryTimeoutError` | SYNC-NET-002 | retry |
| 401 (`AuthenticationError`, `InvalidAuthorizationToken`) | SYNC-AUTH-001 | needs a person |
| 403 (`PermissionError`) | SYNC-AUTH-004, naming the doctype in the message | needs a person |
| 429 with `Retry-After` | SYNC-RATE-001 | retry after the wait |
| 409 `TimestampMismatchError`, `DocumentLockedError`, `QueryDeadlockError` | SYNC-NET-002 | retry |
| 409 `DuplicateEntryError` / `NameError` | SYNC-DUP-001 when the existing record can be read, else SYNC-VAL-002 | handled |
| 404 `DoesNotExistError` on an optional lookup | normal "not found" | |
| 404 on the site root (`/api/method/ping`) or an HTML body | SYNC-VAL-002 "this address is not an ERPNext site" | needs a person |
| 417 `ValidationError`, `LinkValidationError`, `MandatoryError`, `InvalidDates`, `UpdateAfterSubmitError`, `DocstatusTransitionError` | SYNC-VAL-002 with the cleaned first server message | needs a person |
| Missing Item | SYNC-MAP-001 | needs a person |
| Template not found | SYNC-MAP-002 | needs a person |
| Missing customer email | SYNC-MAP-003 | needs a person |
| Price list, exchange rate, account currency, total, tax conversion, company, delivery date, missing option | SYNC-VAL-002 naming the field | needs a person |

Messages: the first message of `_server_messages` is used after removing HTML tags and the site address, key and secret. Only a short sanitised reason is kept (160 characters).

## 14. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| orders | yes | Sales Order, optional invoice and payment |
| customers | yes | Customer, Contact, Address |
| refunds | yes | Sales Invoice with Is Return |
| stock_in | yes | Bin `actual_qty` per warehouse |
| tracking_in | yes | Delivery Note `lr_no` and `transporter_name` |
| prices_in | no | Item prices are not read |
| invoice_readback | yes | Sales Invoice status and amounts |
| reconciliation | yes | Sales Orders with `po_no` |
| webhooks | no | The Webhook doctype exists but needs merchant setup; polling is used |
| shadow_lookup | yes | `findExisting` is read-only |

## 15. Not verified

No ERPNext site was available; everything was read from the documentation pages and the source of v15 and v16, and tested against `FakeErpnextServer`.

- Wording of `_server_messages` for every validation. The connector keeps only the first message; its text is not interpreted except for the patterns listed in `ErpnextErrorMapper`.
- The order note is stored as a `Comment` on the Sales Order because `sales_order.json` has no remarks field; the Comment doctype's permissions for a Sales User were not read.
- Whether `Customer.email_id` is set for customers created without a primary contact (hence the Contact fallback), and whether `=` on `email_id` is case-insensitive on PostgreSQL sites (the connector compares the returned address in lower case as well).
- The Sales Invoice number series with `ACC-SINV-RET-.YYYY.-` for returns: ERPNext chooses it; the connector does not set `naming_series`.
- Item level tax templates (`item_tax_template`) are not used. Sites that rely on them for tax rates must use one document template.
- Stock items need a warehouse on the order line: the connector sets `set_warehouse` from the option; without it ERPNext uses the Item Default and answers a validation error if there is none.
- `get_exchange_rate` may call an exchange rate service configured in the site (Currency Exchange Settings); this is ERPNext's behaviour, not the plugin's.
- Frappe Cloud rate limits are not documented beyond the generic page.
- Rounded totals: the check uses `grand_total`; sites with "Rounded Total" enabled show `rounded_total` to staff, which may differ by less than the rounding unit.
- Behaviour of `limit_page_length=0` is not used; paging always asks for 100.

## 16. Manual smoke test against a real ERPNext

No credentials are stored in the repository.

1. Start a throw-away ERPNext (Frappe Docker `frappe_docker` pwd.yml, or a Frappe Cloud trial site). Complete the setup wizard with a company, a currency and a fiscal year.
2. Create a Price List for the shop currency, an Item with a code equal to a WooCommerce SKU, a Sales Taxes and Charges Template with one `On Net Total` row, and an Account for payments if you test payments.
3. Create a user with Sales User, Stock User and Accounts User roles, open API Access and Generate Keys.
4. In WordPress allow the private address with a must-use plugin (`add_filter( 'estasoft_sync_allow_private_hosts', '__return_true' );`) and add an ERPNext connection with the address, key and secret. Press Test connection: expect the user, company, currency, price lists, templates and the roles found.
5. Place a WooCommerce order; expect one submitted Sales Order with Customer's Purchase Order `WC-{id}` and the same total. Run the sync again: no second order.
6. Repeat with `documents = order_invoice` and `order_invoice_payment`.
7. Refund the order: expect one return invoice with `po_no = WC-{refund id}`.
8. Change the stock of an item with a Stock Entry and run the stock read; submit a Delivery Note against the order with a transport receipt number and run the tracking read.
