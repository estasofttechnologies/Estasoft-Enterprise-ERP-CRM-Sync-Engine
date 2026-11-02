---
id: acumatica
name: Acumatica
catalog_row: Acumatica
connection_method: own_app
available: true
verified: false
hosts: []
terms_url: https://www.acumatica.com/terms-of-use/
privacy_url: https://www.acumatica.com/privacy-policy/
developer_url: https://github.com/Acumatica/Help-and-Training-Examples
variants: []
---

# Acumatica connector

Connector id `acumatica`, type ERP, contract version 1. Code: `includes/Connectors/Acumatica/` (shared order and money helpers in `includes/Connectors/ErpCommon/`). The connector sends WooCommerce orders to Acumatica as Sales Orders, refunds as credit memos (a Sales Invoice of type Credit Memo), and customers with their main contact. It reads stock per warehouse from `StockItem`, shipment tracking numbers from `Shipment` packages, invoice status from `SalesInvoice`, and lists orders for the nightly check.

`hosts` is empty on purpose: every Acumatica customer has their own site (`https://<name>.acumatica.com` or their own server, sometimes with an instance folder in the path). The plugin contacts only that address. Private and loopback addresses are refused unless the filter `estasoft_sync_allow_private_hosts` allows them.

Supported: the contract-based REST API of `Default` endpoint versions 22.200.001 to 25.200.001. The version in the `endpoint_version` setting is used (24.200.001 when it is empty). Test connection reads the site's own list from `GET /entity` and, when the site does not offer that version, says which supported version to enter. **Endpoint 26.200.001 is not supported**: it changes the query syntax (`$expand` and `$select` nest in parentheses, custom fields move into `$select`, drop-down filters use internal ids) `[third-party]`. Test connection says so when the site offers only 26.200.001.

## 1. Sources

Fetched or read on 2026-11-02. Acumatica's own help site (help.acumatica.com) was **not reachable** from this build (the fetch tool refused it), so the REST documentation was read in three places that carry the same text: the official examples repository https://github.com/Acumatica/Help-and-Training-Examples (the Postman collection `IntegrationDevelopment/Help/IntegrationDevelopmentGuide.postman_collection.json` holds the request URLs, bodies and tests of the Integration Development Guide, cloned and read), the MYOB Advanced edition of the same guide (https://help.myob.com.au/advanced/Docs/Published/IntegrationDevelopmentGuide/, MYOB Advanced is Acumatica sold under MYOB's name; examples there use endpoint 25.200.001) and third-party guides. All three are tagged below.

| Source | Used for |
| --- | --- |
| Official examples repository, Postman collection `[documented]` | Request shapes: `PUT Customer`, `GET Customer?$expand=MainContact...`, `PUT SalesOrder?$expand=Details`, `GET SalesOrder?$filter=OrderType eq 'SO' and CustomerOrder eq '...'`, `PUT SalesInvoice` with `Type: Credit Memo`, `POST SalesInvoice/ReleaseSalesInvoice`, `GET StockItem?$expand=WarehouseDetails&$select=InventoryID,WarehouseDetails/WarehouseID,WarehouseDetails/QtyOnHand`, `GET Shipment?$expand=Packages&$select=Packages/TrackingNbr`, `GET SalesOrder?$expand=Details,Shipments`, `POST entity/auth/login` (not used), `GET /entity` (version and endpoint list) |
| https://help.myob.com.au/advanced/Docs/Published/IntegrationDevelopmentGuide/OAuthOIDC_AuthorizationCode_AuthorizationCode.html `[documented, MYOB edition]` | Authorize endpoint `https://<instance>/identity/connect/authorize`; discovery document at `/identity/.well-known/openid-configuration`; `client_id` is `<GUID>@<Tenant>`; `redirect_uri` must equal a Redirect URI registered on Connected Applications (SM303010); scopes `api`, `offline_access`, `api:concurrent_access`; PKCE `S256`; refresh token chain expires 30 days after first authentication |
| .../IntegrationDev_RESTExample_Basic_Create_Record.html `[documented, MYOB edition]` | `PUT` creates or updates; `If-None-Match: *` makes it create-only (412 if it exists); values are `{"value": ...}`; status codes 200, 400, 401, 403, 412, 422 (field errors in `error`), 429, 500 |
| .../IntegrationDev_RESTExample_Parameter_filter.html and `_skip.html` `[documented, MYOB edition]` | `$filter` with `eq`, `gt`, `and`, `or`, `substringof`, `startswith`, `endswith`; date literals `datetimeoffset'...'` (URL-encoded); nested path `MainContact/Email`; filtering on detail records is not supported; `$skip` is applied before `$top`; no total count |
| .../IntegrationDev_RESTExample_Basic_Action_Execution.html `[documented, MYOB edition]` | Actions are `POST <Entity>/<Action>` with `{"entity": {...}, "parameters": {}}`; 202 plus `Location` means still running; poll the Location URL until 204; other requests in that session fail meanwhile; 404 no such action |
| .../IntegrationDev_RESTExample_SalesInvoice_ReleaseCreditMemo.html and `_RetrieveStatusOfOperation.html` `[documented, MYOB edition]` | Release of a credit memo: body `{"entity": {"Type": {"value": "Credit Memo"}, "ReferenceNbr": {"value": "..."}}}`; status becomes Open |
| .../IntegrationDev_RESTExample_SalesInvoice_CreateEUR.html `[documented, MYOB edition]` | `Currency` on a SalesInvoice; the rate comes from Currency Rates (CM301000); the customer needs currency and rate overriding enabled to take another currency |
| .../IntegrationDev_RESTExample_Shipment_ReadTrackingNumber.html, `_WriteTrackingNumber.html` `[documented, MYOB edition]` | `Packages/TrackingNbr` on `Shipment` |
| https://www.apideck.com/blog/how-to-integrate-with-the-acumatica-api `[third-party]` | Base URL `https://<instance>/entity/<endpoint>/<version>/`; token endpoint `/identity/connect/token`, form encoded, secret in the body; access token 3,600 s; refresh token may rotate; `PX-CbApiBranch` header; licence limits (Essentials 50, Select 100, Prime 150, Enterprise 300 requests a minute; 429 only when more than 20 requests are queued or one waited more than 10 minutes); sessions held for 10 minutes; endpoint 26.200.001 syntax change; `LastModified` versus `LastModifiedDateTime`; legacy `/entity/auth/login` to be discontinued |
| docs/research/integration-research-vendors.md section 4.13 | Earlier research, same facts |
| Acumatica terms and privacy pages | **Not fetched** (refused by the tool); the two addresses in the front matter are Acumatica's usual addresses and are listed under "Not verified" |

## 2. How the merchant connects

1. In Acumatica open Connected Applications (SM303010), add an application and choose the Authorization Code flow.
2. Paste the redirect address shown on this screen into the Redirect URIs tab, add a shared secret and copy it at once.
3. Paste your site address, the Client ID (it ends with `@` and your tenant name) and the secret into the fields here.
4. Press Sign in with Acumatica and approve the access as the user who will own the sync.
5. In Acumatica ask an administrator to turn on sliding expiration of refresh tokens, or you must sign in again every 30 days.
6. Enter the branch if you use several, then press Test connection.

Plan gate: Web API use is licensed by the edition (requests a minute and parallel requests, section 12); whether a WooCommerce connection needs an extra Commerce licence is not stated in anything read. The signing-in user needs rights to the Sales Orders (SO301000), Customers (AR303000), Sales Invoices (SO303000) and Stock Items (IN202500) forms.

## 3. Authentication

OAuth 2.0 authorization code with the merchant's own connected application (`own_app`), PKCE `S256`, client secret in the request body, scopes `api offline_access` (not `api:concurrent_access`, so the sync never holds extra sessions). Authorize `https://<instance>/identity/connect/authorize`, token `https://<instance>/identity/connect/token`. Bearer token on every call; refresh and re-sign-in are done by the shared token manager (SYNC-AUTH-002 when the refresh token is refused, which is what happens after 30 days if sliding expiration is off; the connection then waits for the person). `credential_expires_at` is set 30 days after each refresh to warn early.

Stored: `client_id` (config, includes the tenant), `client_secret` (secret), tokens (secrets, reserved keys). The tenant is the text after `@` in the client id and is also the single organisation offered after sign-in. The branch is the config value `branch` sent as `PX-CbApiBranch` `[third-party]`.

Credential fields: `address`, `client_id`, `client_secret`, `branch` (optional), `endpoint_version` (optional, auto-detected), plus options (section 5).

## 4. Data sent and when (External services disclosure)

Host: the Acumatica address the merchant typed (`/identity/...` for sign-in, `/entity/...` for data). Nothing else is contacted.

| Action | Data sent | Data read |
| --- | --- | --- |
| Sign in / refresh | Client id, client secret, code or refresh token | Access token, refresh token |
| Test connection | Nothing | Endpoint list, branches, warehouses, tax categories |
| Order synced | Buyer name, email, phone, address, order number, order date, currency, line SKUs, quantities, prices, tax categories, shipping, fees, discount, note | Customer by email, stock items by SKU, Sales Order by order number, totals back |
| Refund synced | Refund lines, quantities, amounts, reason, refund number | Original order, credit memo by refund number |
| Stock read (scheduled) | SKUs | Quantity on hand per warehouse |
| Tracking read (scheduled) | Date window | Orders with `WC-` references, their shipments and package tracking numbers |

## 5. Objects and field mapping

Order payload paths are the shared ones of the ERP connectors; the default rules are `ErpCommon\DefaultRules` (the same list the ERPNext connector uses).

| Payload path | Acumatica field | Notes |
| --- | --- | --- |
| client reference (queue item) | SalesOrder `CustomerOrder` | `WC-{order id}`; the field holds 40 characters `[inferred]`; longer is refused |
| (option `order_type`) | `OrderType` | Default `SO` |
| `date_order` | `Date` | Date part in the shop's time zone |
| `currency` | `CurrencyID` | Must be accepted by the customer (currency override on the customer) |
| `note` | `Description` | |
| `partner.email`, `name`, `company_name`, `phone`, address | Customer `MainContact/Email`, `CustomerName`, `MainContact/Phone1`, `MainContact/Address/{AddressLine1, City, State, PostalCode, Country}` | `CustomerName` is the company name when present |
| `partner.vat` | not sent | Tax registration lives in other entities; see "Not verified" |
| `delivery.*` | SalesOrder `ShipToAddressOverride` and `ShipToAddress` | Only when it differs from the billing address `[inferred]` |
| `order_line[].product` | detail `InventoryID` | SKU equals Inventory ID; unknown SKU: SYNC-MAP-001 |
| `order_line[].quantity`, `price_unit` | detail `OrderQty`, `UnitPrice` | Needs "price override" rights of the user |
| `order_line[].tax` | detail `TaxCategory` | Acumatica computes the tax from the customer's tax zone and the category |
| `shipping.total`, `fee[].total` | detail lines with the inventory items `shipping_item`, `fee_item` | Refused when the option is empty and the amount is above zero |
| `discount.total` | detail line with `discount_item` and a negative `UnitPrice` | Refused when the option is empty |
| `warehouse` / option `warehouse` | detail `WarehouseID` | |

Options (config): `order_type` (`SO`), `customer_policy` (`match_or_create` default, `match_only`, `always_create`), `customer_class`, `warehouse`, `shipping_item`, `fee_item`, `discount_item`, `refund_item`, `refund_state` (`hold` default, `release`), `total_mismatch` (`fail` default, `warn`), `branch`, `endpoint_version`.

Customer flow: `GET Customer?$filter=MainContact/Email eq '<email>'&$expand=MainContact`, paged; the returned email is compared in lower case too (Acumatica's `eq` follows the database collation); first active match (`Status` not `Inactive`). When none and the policy allows it: `PUT Customer` with `CustomerName`, `CustomerClass` (option, omitted when empty), `MainContact` (email, phone, address) and no `CustomerID`, which lets Acumatica number it (`[inferred]`, see "Not verified"). The customer is found again by email on every later order.

## 6. Order flow

1. Parse the payload and check what needs no request: email, name, lines, currency, options.
2. Find the Sales Order: `GET SalesOrder?$filter=OrderType eq '<type>' and CustomerOrder eq 'WC-{id}'&$expand=Details&$select=...` (the documented pattern). A found order that is not Cancelled is never created again. A found order on hold (`Status` = Hold, which happens when a previous pass stopped at the total check) is checked again (step 6).
3. Resolve every SKU: `GET StockItem?$filter=InventoryID eq 'A' or InventoryID eq 'B'...` in chunks of 10; the item must be `ItemStatus` Active. Missing: SYNC-MAP-001 before a customer or order is created. Non-stock items and service items are also found through `StockItem`, `NonStockItem`: the connector asks `StockItem` first, then `NonStockItem` for the SKUs still missing.
4. Resolve the tax categories used (each must exist and be active: `GET TaxCategory`, matched without regard to case; unknown is SYNC-MAP-002), and the shipping, fee and discount items (settings, matched like SKUs).
5. Find or create the customer.
6. `PUT SalesOrder?$expand=Details` with `Hold: true` (so a wrong total never becomes a live order), `CustomerOrder`, `OrderType`, `CustomerID`, `Date`, `CurrencyID`, `Description`, the lines. Read the answer: `CurrencyID` must equal the order's currency, `OrderTotal` must equal `total - excluded_total` within one minor unit (`total_mismatch = fail` stops here and leaves the held order for the person; the next pass finds it, reads the total again and does not create a second order).
7. `PUT SalesOrder` with `Hold: false` for the order found by key (`OrderType`, `OrderNbr`). The order is now open.

The reference returned is `{OrderType}/{OrderNbr}`, the link is `{address}/Main?ScreenId=SO301000&OrderType=SO&OrderNbr=...`, and `extra` has the total in minor units, the currency and the status. `findExisting()` returns a reference only for an order that is not on hold and not cancelled; an order on hold makes `send()` finish it.

Dates: Acumatica keeps the order `Date` as sent; releasing or shipping does not change it `[inferred]`.

## 7. Duplicate protection

`CustomerOrder` carries `WC-{id}` and is searched with `OrderType` before every create. Acumatica does not offer an idempotency key. Credit memos start their `Description` with `WC-{refund id} refund of WC-{order id}` (then `: reason`; `Description` on a SalesInvoice is `[inferred]`) and are searched by `Type eq 'Credit Memo'` and `startswith(Description, 'WC-{refund id} refund of ')` before every create, so refund 50 is never mistaken for refund 501. A credit memo that is Voided or Rejected is not a match.

## 8. Taxes

The tax code of a line is the **Tax Category** ID in Acumatica (`TaxCategory` of the detail line). `listTaxCodes()` reads `TaxCategory` (`TaxCategoryID`, `Description`, `Active`); the rate is unknown to the connector (Acumatica takes it from the tax zone), so `rate_percent` is `0` and the name says "rate set by the tax zone". The mapping rows pick a category per country and rate. Acumatica adds the tax itself from the customer's tax zone (`Customer.TaxZone`, documented as assignable); the connector does not send tax amounts (overriding with `TaxDetails` is documented but not used). Prices are sent without tax; a mapping with `prices_include_tax` is refused for Acumatica (the tax calculation mode of the tax zone is not read), naming the setting. After the create the total is compared with the shop's (section 6).

## 9. Payments

Not implemented. Acumatica's payment documents need a payment method and cash account setup and, for card payments, a processing centre; the documented examples are for card payments imported or authorised in Acumatica. The connector does not pretend to record a payment. Option `documents` does not exist for Acumatica.

## 10. Refunds

A refund is a `SalesInvoice` with `Type` "Credit Memo", `CustomerID` of the order's customer, `Hold: true` (the documented create keeps `Hold` false; the connector holds it so a person can review), `Currency` of the order, `Description` as in section 7, and a detail line per refunded product (`InventoryID`, `Qty` positive, `UnitPrice` the line amount divided by the quantity, `TaxCategory` copied from the line of the same item on the original order), a shipping refund as a line with the shipping item (its tax category copied the same way) and an amount without lines as one line with the `refund_item`. Shipping and amount-only amounts are sent exactly as the shop gives them (with tax); Acumatica adds its own tax on top when the category applies, and the total check below then stops the credit memo on hold instead of posting a wrong amount. When the order has an invoice that the connector can read (through `SalesOrder` `Shipments/InvoiceNbr`), each line also carries `OrigInvNbr` and `OrigInvType` (documented on the Credit Memo example). `refund_state = release` removes the hold and runs `ReleaseSalesInvoice`, then polls the `Location` up to three times; if it still answers 202 the item is complete with a note and the next pass reads the status (`Open` means released). The refund total is compared with the credit memo's `Amount` (one minor unit; `total_mismatch = warn` only warns). A credit memo that was made and then found wrong stays on hold for the person to delete.

The original order must exist (`CustomerOrder = WC-{order id}`), otherwise SYNC-VAL-002 before any other request.

## 11. Reading from Acumatica

- Stock: `GET StockItem?$filter=InventoryID eq ... or ...&$expand=WarehouseDetails&$select=InventoryID,WarehouseDetails/WarehouseID,WarehouseDetails/QtyOnHand` (documented), chunks of 10 SKUs, quantity is the sum of `QtyOnHand` over the chosen warehouses (all when none chosen), held is 0 (the available quantity field is not documented).
- Tracking: Sales Orders with `startswith(CustomerOrder,'WC-')` modified since the date (`LastModified`, or `LastModifiedDateTime` on endpoint 26 which is not supported) with `Shipments/ShipmentNbr`, then `Shipment?$filter=ShipmentNbr eq ... &$expand=Packages` for `Packages/TrackingNbr`, `Status`, `ShipmentDate`. A shipment counts when its status is Confirmed or Completed. The carrier is not read (the shipping method field was not verified), so `carrier` is empty.
- Invoice: the last `Shipments/InvoiceNbr` of the order, then `SalesInvoice/Invoice/{nbr}?$select=ReferenceNbr,Type,Status`. `authority_status` is empty.
- Reconcile: Sales Orders with `startswith(CustomerOrder,'WC-')` and `Date` from the window (one day earlier than asked, because dates carry no zone), with `OrderTotal`, currency and status; a Cancelled order is listed with `state = cancelled`. `refunded_minor` is the sum of `Amount` of the credit memos of the window whose description names the order and that are not on hold, voided or rejected. Paged with `$top=100` and `$skip`. Acumatica gives no buyer email on the order, so `email` is empty.
- `lookupCustomer`, `lookupProduct`, `searchProducts`, `listTaxCodes`: read-only helpers.

## 12. Rate limits and pacing

Per the licence edition: 50, 100, 150 or 300 requests a minute and 1, 2, 3 or 8 parallel requests; requests are slowed after half the budget and answered with 429 only when more than 20 are queued or one waited more than 10 minutes `[third-party]`. Other connected apps share the budget. The connector sends one request at a time, at most 50 a minute (the Essentials edition), and obeys `Retry-After` when sent. Timeout 30 s. A request that waits longer than 30 s is a time-out (SYNC-NET-001), retried later.

## 13. Error mapping

| Acumatica answer | SYNC code | Class |
| --- | --- | --- |
| Time-out, reset, DNS | SYNC-NET-001 | retry |
| Certificate failure | SYNC-NET-003 | needs a person |
| 5xx, `PXTimeoutException`, "deadlock" | SYNC-NET-002 | retry |
| 401 (after one refresh) | SYNC-AUTH-001, or SYNC-AUTH-002 when the refresh token is refused | needs a person |
| 403 | SYNC-AUTH-004 naming the form | needs a person |
| 429 | SYNC-RATE-001 with the wait | retry |
| 500 with "API Login Limit" or "maximum number of API users" | SYNC-RATE-001 (60 s) | retry |
| 412 (`If-None-Match`) | normal "exists" | |
| 422 with field errors | SYNC-VAL-002 naming the first field and its message | needs a person |
| 500 with `exceptionMessage` about a missing record or value (`cannot be found`, `cannot be empty`) | SYNC-VAL-002 with the cleaned message | needs a person |
| Missing stock item | SYNC-MAP-001 | needs a person |
| Missing tax category | SYNC-MAP-002 | needs a person |
| Missing email | SYNC-MAP-003 | needs a person |
| Currency, total, version 26, tax mode, missing option | SYNC-VAL-002 | needs a person |

Messages keep only a short cleaned reason (160 characters); the instance address, tenant, client id and secret are scrubbed.

## 14. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| orders | yes | Sales Order |
| customers | yes | Customer with MainContact |
| refunds | yes | SalesInvoice of type Credit Memo (documented create and release) |
| stock_in | yes | `StockItem` `WarehouseDetails/QtyOnHand` (documented request) |
| tracking_in | yes | `Shipment` `Packages/TrackingNbr` (documented) |
| prices_in | no | Not read |
| invoice_readback | yes | `SalesInvoice` status |
| reconciliation | yes | Sales Orders by `CustomerOrder` |
| webhooks | no | Push notifications (SM302000) need administrator setup; polling is used |
| shadow_lookup | yes | `findExisting` is read-only |

## 15. Not verified

No Acumatica site was available; behaviour was written from the documentation and tested against `FakeAcumaticaServer`.

- The field names beyond the documented requests. Documented in the examples collection: SalesOrder `CustomerID`, `Date`, `Description`, `Hold`, `OrderType`, `CurrencyID`, `OrderTotal`, `CustomerOrder`, `Shipments/InvoiceNbr`, `Shipments/ShipmentNbr`, detail `InventoryID`, `OrderQty`, `UnitPrice`, `WarehouseID`; SalesInvoice `Type`, `CustomerID`, `Currency`, `Hold`, `ReferenceNbr`, `Status`, detail `InventoryID`, `Qty`, `UnitPrice`, `OrigInvNbr`, `OrigInvType`. `[inferred]`: SalesInvoice `Amount` and `Description` and `Date`, the `TaxCategory` field of a detail line, `ShipToAddressOverride`, `ShipToAddress`, `LastModified` on SalesOrder, `Status` values (Hold, Open, Cancelled, Balanced, Voided), `CustomerName`, `CustomerClass`, `MainContact` and its `Address` fields, `ItemStatus`, the `NonStockItem`, `TaxCategory` and `Warehouse` entities and their fields, Shipment `Status` and `ShipmentDate`. Test connection probes `GET <Entity>?$top=1&$select=<fields>` for each field the connector uses, so a wrong name is reported when connecting, not on the first order.
- Creating a Customer without `CustomerID` (auto numbering) and the `Description` of a SalesInvoice for the refund reference.
- Whether `LastModified` is the right modified field on endpoints 22 to 25 for `Shipment` (the guide shows it for `StockItem`).
- The wording of 422 and 500 bodies beyond `error` per field; the connector reads `message`, `exceptionMessage` and every `error` string.
- The "API Login Limit" message `[third-party]`.
- Whether a Commerce connector licence is needed for a WooCommerce connection.
- Endpoint 26.200.001 is refused, not supported.
- Whether the lowest edition limit of 50 requests a minute applies to the merchant's licence (other editions allow more; the connector does not go faster).
- The terms and privacy addresses in the front matter were not fetched; `developer_url` is the official examples repository, because the page where the connected application is registered (SM303010 help) was not reachable.
- Tax registration numbers (`partner.vat`) are not sent.
- Payments are not implemented (section 9).

## 16. Manual smoke test against a real Acumatica

1. Use an Acumatica trial or sandbox (the trial allows two API sessions) with the U100 or your own data. Turn on Inventory and Order Management.
2. In Connected Applications (SM303010) add an application with the Authorization Code flow, the redirect address from the plugin, a shared secret, and enable sliding expiration.
3. Create a user with rights to Sales Orders, Customers, Sales Invoices and Stock Items, an inventory item whose ID is a WooCommerce SKU, a Tax Category and shipping, fee and discount service items.
4. Add the connection (`allow_private_hosts` filter for a local server), sign in, press Test connection: expect the site version, the endpoint chosen, the tenant, warehouses and tax categories, and the field probes passing.
5. Place an order: expect one Sales Order open with Customer Order `WC-{id}` and the shop total. Run it again: no second order.
6. Refund it: expect a credit memo with the description `WC-{refund id}`, on hold unless `refund_state = release`.
7. Change the quantity on hand and confirm a shipment with a package tracking number; run the stock and tracking reads.
