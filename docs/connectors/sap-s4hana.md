---
id: sap-s4hana
name: SAP S/4HANA
catalog_row: SAP S/4HANA
connection_method: cloud_app
available: true
verified: false
hosts: ["*.s4hana.cloud.sap"]
terms_url: https://www.sap.com/about/legal/terms-of-use.html
privacy_url: https://www.sap.com/about/legal/privacy.html
developer_url: https://github.com/stud0709/sap-dev-release/wiki/Configuring-Access-to-S4HANA-Cloud-with-Basic-Auth
variants: []
---

# SAP S/4HANA connector

Connector id `sap-s4hana`, type ERP, contract version 1. Code: `includes/Connectors/SapS4Hana/` (shared ERP helpers in `includes/Connectors/EnterpriseCommon/`). It sends WooCommerce orders to SAP S/4HANA (Cloud public or private edition) as sales orders through the OData V2 API `API_SALES_ORDER_SRV`, refunds as credit memo requests through `API_CREDIT_MEMO_REQUEST_SRV`, and reads products and billing documents back. The public cloud API host (`myXXXXXX-api.s4hana.cloud.sap`) and its token service are under `*.s4hana.cloud.sap`, which the front matter `hosts` names. A private edition or on-premise system has an address the merchant types; it is allowed too (the connector accepts any public https address, `merchantHost`), and private network addresses are refused unless the filter `estasoft_sync_allow_private_hosts` allows them. The token URL is typed by the merchant and may point to another host; it is contacted only for the token.

What it does not do, and why: it does not create customers (section 7), has no nightly check list (the net amount of S/4HANA cannot be compared with the shop's totals), reads no stock (its fields were not read), and no delivery tracking.

## 1. Sources

Fetched with WebFetch on 2026-11-02. `[documented]` is an SAP page, `[third-party]` a partner or community page, `[inferred]` reasoned and not stated. SAP's help portal answers `robots_disallowed` and the SAP Business Accelerator Hub (`api.sap.com`) is a script application, so the official API definitions could not be read. Entity and property names not on a page below are [inferred] from the SAP API names given in the task and the third-party pages.

| Page | Used for |
| --- | --- |
| https://developers.sap.com/tutorials/spa-process-action-create.html | [documented] base `https://my-api.s4hana.cloud.sap/sap/opu/odata/sap/API_SALES_ORDER_SRV`, `POST /A_SalesOrder`, communication scenario `SAP_COM_0109`, header fields `SalesOrderType`, `SalesOrganization`, `DistributionChannel`, `OrganizationDivision`, `SoldToParty`, `PurchaseOrderByCustomer`; answer 201 with `SalesOrder`, `SalesOrderDate`, `TotalNetAmount`, `RequestedDeliveryDate`; CSRF token fetch at the service root `/` |
| https://github.com/stud0709/sap-dev-release/wiki/Configuring-Access-to-S4HANA-Cloud-with-Basic-Auth | [third-party] communication user (app F2268), communication system (F1762), communication arrangement (F1763); scenarios `SAP_COM_0109` sales orders (`API_SALES_ORDER_SRV`), `SAP_COM_0008` business partner and customer (`API_BUSINESS_PARTNER`); basic authentication needs a technical communication user, a dialog user is redirected to a SAML page; the host contains `-api` (`myXXXXXX-api.s4hana.cloud.sap`), port 443 |
| https://blogs.sap.com/2019/11/08/s-4hana-cloud-x-csrf-token-and-e-tag-validation/ | [third-party] fetch the CSRF token with a GET carrying `x-csrf-token: fetch`; the response carries the token and the `etag`; update, patch and delete send `x-csrf-token` and `If-Match`; the ETag changes after every update so a new GET is needed; a request without a valid token gets 403 |
| https://www.apideck.com/blog/guide-to-sap-4-hana-rest-and-soap-api.md | [third-party] OAuth 2.0 client credentials with a scope such as `API_SALES_ORDER_SRV_0001`; basic authentication for development; sales order body (`SalesOrderType`, `SalesOrganization`, `SoldToParty`, `to_Item[].Material`); a claim of 100 requests per second per API and 429 with `Retry-After` (not confirmed by any SAP page; the research file reports 503 instead) |
| https://docs.sim.ai/integrations/sap_s4hana | [third-party] OData V2 shapes: `d.results`, `__count`, `__next`, single entity under `d`; `$filter`, `$top`, `$skip`, `$select`, `$expand`; `A_BusinessPartner`, `A_Customer`, `A_SalesOrder`, `A_Product`, `A_BillingDocument`; sales order items by deep insert `to_Item`; update with `MERGE`, `If-Match` defaults to `*`; communication user or OAuth client credentials with a token URL |
| https://raw.githubusercontent.com/api-evangelist/sap-sales-and-distribution-sd/refs/heads/main/openapi/sap-sales-and-distribution-sd-billing-document-api-openapi.yml | [third-party] `A_BillingDocument` is read-only apart from `CancelBillingDocument` and `GetPDF`; fields `BillingDocument`, `BillingDocumentDate`, `SoldToParty`, `TotalNetAmount`, `TotalTaxAmount`, `TransactionCurrency`, `BillingDocumentIsCancelled`, `AccountingPostingStatus`; `$top`, `$skip`, `$filter`, `$select`, `$expand`, `$inlinecount` |
| https://api.sap.com/odata/1.0/catalog.svc/Files('946248ecc8b149fb8f30fe0426aeaf01')/$value | [documented] SAP guide names the service `API_CREDIT_MEMO_REQUEST_SRV` (communication scenario `SAP_COM_0158`), base path `/sap/opu/odata/sap/`, request type `CR`; no entity or property names |
| https://community.sap.com/t5/technology-q-a/odata-deep-insert-to-customer-not-working/qaq-p/12739337 | [third-party] a business partner created through `API_BUSINESS_PARTNER` with role `FLCU01` does not return the customer number; SAP Note 3567506 says the customer is synchronised after the response and a later GET must read it |
| `docs/research/integration-research-vendors.md` 4.7 | basic or OAuth with a communication user; tenant-specific cloud hosts; events need SAP Advanced Event Mesh; throttling reportedly 503; no idempotency key |

## 2. How the merchant connects

The Connect screen shows these steps (V0 reads the list into the catalog field `steps`). No redirect address is needed: there is no sign-in page, the communication user or OAuth client signs in for itself.

1. In SAP open Communication Users and create a technical user for the shop; copy its password.
2. Create a communication system (host of this website) and add the user for inbound communication.
3. Create a communication arrangement for `SAP_COM_0109` (sales orders), and for `SAP_COM_0009`, `SAP_COM_0158` and billing if you use them.
4. Copy the API address (the host with `-api`) from the arrangement; for OAuth also the client ID, secret, token URL and scope.
5. Enter the address, the sales organization, distribution channel, division and the customer number of the sold-to party here.
6. Press Test connection.

Plan gates: the tenant administrator creates the communication objects; Fiori apps are named in the third-party page above. On the public edition a business user (not a technical one) is redirected to a login page [third-party], which is why a communication user is required.

## 3. Authentication

Credential fields:

| Key | Where | Secret | Notes |
| --- | --- | --- | --- |
| `address` | config | no | `https://host` of the API host (`myXXXXXX-api.s4hana.cloud.sap` or a private-edition address). Path, query and credentials are cut off; plain http and private hosts are refused. |
| `auth_method` | config | no | `oauth` (client credentials, default) or `basic` (communication user). |
| `client_id`, `client_secret` | secrets | yes | OAuth. |
| `token_url` | config | no | The "Token Service URL" shown on the communication arrangement. https only. |
| `scope` | config | no | Space-separated scopes from the arrangement (for example `API_SALES_ORDER_SRV_0001`). Empty sends none. |
| `user` | config | no | Basic: the communication user. |
| `password` | secrets | yes | Basic. |
| `sales_organization`, `distribution_channel`, `division` | config | no | Required to send orders. |
| `sold_to_party` | config | no | Customer number all web orders are sold to (section 7). |

OAuth: `POST {token_url}` with `grant_type=client_credentials`, the client id and secret in a Basic header, and `scope`; the base keeps the token until five minutes before it ends and asks for a new one (no refresh token). A refused token request is SYNC-AUTH-001. No S/4HANA answer that names an expired secret was found, so SYNC-AUTH-005 is not produced.

CSRF and ETag (writes only): before a POST, PATCH or DELETE the connector sends `GET {service root}/` with `X-CSRF-Token: Fetch`, reads the response header `X-CSRF-Token` and the session cookies the answer sets, and sends the token and the same cookies on the write (the token belongs to the session). A 403 with `X-CSRF-Token: Required` fetches a new token once and replays the write; a 403 without it is an authorisation error. For DELETE the connector first reads the entity, takes its `ETag` header and sends it as `If-Match` (`*` when the answer has none). The only write that needs an ETag is the removal of a sales order that failed the check (section 7). A 412 or 428 is returned as SYNC-VAL-002 ("the record changed"); the delete is not repeated. The token and cookies are kept for the length of one request and fetched again on the next, because the connector object is rebuilt per call.

## 4. Data sent and when (for the WordPress.org "External services" disclosure)

Only the merchant's tenant host (and the token URL the merchant typed, normally the same tenant) is contacted. Nothing goes to Estasoft.

| Action | Request | Data |
| --- | --- | --- |
| Test connection | token request; `GET` with `$top=1` on `A_SalesOrder`, `A_Product`, `A_BillingDocument`, `A_CreditMemoRequest` | Client id and secret (to the token URL) |
| Send an order | `GET A_Product`, `GET A_SalesOrder` (by reference), CSRF fetch, `POST A_SalesOrder` with items | Order number and date, sold-to party, SKUs, quantities, prices, currency, optional note |
| Send a refund | `GET A_SalesOrder`, `GET A_CreditMemoRequest`, CSRF fetch, `POST A_CreditMemoRequest` | Refunded lines and amounts, reason |
| Read back | `GET A_BillingDocumentItem`, `A_BillingDocument`, `A_Product` | Sales order numbers, billing document numbers and dates, item numbers |

## 5. Objects and field mapping

| Canonical (source) | Payload path | S/4HANA field (`A_SalesOrder`) |
| --- | --- | --- |
| `order.client_ref` | `client_ref` | `PurchaseOrderByCustomer` (35 characters) |
| `order.created_at` | `order_date` | `SalesOrderDate`, `CustomerPurchaseOrderDate` |
| `order.currency` | `currency` | `TransactionCurrency` |
| a custom field | `sold_to_party` | `SoldToParty` (wins over the connection setting) |
| `order.total` | `total` | not sent; S/4HANA's amount is net of tax and the shop's gross, so only the lines are compared (section 7) |
| `lines[].sku` | `order_line[].product` | `to_Item[].Material` |
| `lines[].quantity` | `order_line[].quantity` | `to_Item[].RequestedQuantity` (a decimal string; the unit is the product's base unit) |
| `lines[].unit_price` | `order_line[].price_unit` | `to_Item[].to_PricingElement[]` with `ConditionType` = `price_condition` (default `PR00`), `ConditionRateValue`, `ConditionCurrency` (when `price_source` = `shop`) |

The order note, the payment reference and the buyer's details are not sent: the sales order header has no free note field that every tenant has, and no customer is created. They stay in WooCommerce.

Refund payload: `order_ref`, `date`, `reason`, `amount`, `refund_line[].{product, quantity, amount}`. The paths are the ones the shared ERP parser reads (`includes/Connectors/EnterpriseCommon/OrderParser.php`, `RefundParser.php`).

Dates are sent as OData V2 `/Date(milliseconds)/` of midnight UTC; the day itself is the order date in the store's time zone (the WordPress setting), so a Gulf shop at UTC+4 does not get the previous day between midnight and 4 a.m. Money is an integer in minor units inside the plugin and a decimal string with the currency's exponent on the wire. Arabic and other scripts are sent as UTF-8. The sales order type is the option `sales_order_type` (default `OR`).

Shipping, fees and discounts: S/4HANA prices them with condition types of the merchant's pricing procedure that this connector cannot know. An order with shipping, fees or a discount is sent with its goods lines only, the option `extra_charges` (`fail` default) refuses such an order with SYNC-VAL-002 naming the pricing procedure, and `ignore` lets it go on (the total check then decides).

Options (connection settings, key `options`): `total_field` (name of a free text header property, see section 6), `price_source` (`shop` default, or `system` to let S/4HANA price from its own master data), `price_condition` (`PR00`), `sales_order_type` (`OR`), `credit_memo_type` (`CR`), `total_mismatch` (`fail` default, `warn`), `extra_charges` (`fail`, `ignore`). An unknown SKU always stops the order with SYNC-MAP-001.

## 6. Duplicate protection

`PurchaseOrderByCustomer = WC-{order id}` is the client reference. Before every create: `GET A_SalesOrder?$filter=PurchaseOrderByCustomer eq 'WC-123'&$select=SalesOrder,...` (the value is quoted by doubling `'`). If more than one row is found the oldest by `SalesOrder` is used. Credit memo requests use `WC-{refund id}` in the same way. No idempotency key exists. A create whose answer was lost is found by this search on the next pass. A reference longer than 35 characters is refused (SYNC-VAL-002); it is never cut.

The engine links whatever `findExisting()` returns without calling `send()`, and `findExisting()` has no payload. If the option `total_field` names a free text property of the sales order header that the tenant has (for example a custom field `YY1_ShopNet_SDH`), the connector writes the shop's net amount there ("Shop net 63.00 AED", `price_source = shop` only) and `findExisting()` compares it with `TotalNetAmount` and `TransactionCurrency`: a difference is returned as SYNC-VAL-002 instead of a reference. The option is empty by default because a property the tenant does not have makes S/4HANA refuse the whole order (400); there is no property that every tenant has [not verified]. Without it nothing is compared by `findExisting()`.

## 7. Customers, taxes, currency and the total check

- Customers: not synced. Creating a customer through `API_BUSINESS_PARTNER` needs roles, a sales area and company data that depend on the tenant, and the customer number is not returned by the create (SAP Note 3567506, [third-party]). All orders are therefore sold to the customer in `sold_to_party` (or to the one a mapping supplies in `sold_to_party`). The buyer's name, email and addresses stay in WooCommerce. `lookupCustomer()` always answers "none" (after a sign-in check).
- Products: matched by SKU as the `Product` of `API_PRODUCT_SRV` (`A_Product`). An unknown SKU is SYNC-MAP-001 before anything is sent.
- Tax: S/4HANA determines tax from the tenant's tax determination; the connector sends none. The tax mapping screen lists nothing (`listTaxCodes` is empty: tax codes are not exposed by the four APIs).
- Prices: with `price_source = shop` each item carries a price condition so SAP uses the shop's price. Whether that condition type accepts a manual value depends on the pricing procedure [not verified]. With `system` S/4HANA prices the order; the total check shows the difference.
- Currency: `TransactionCurrency` is the order's; after creation it is read back, a different one fails the order.
- Total check: the answer shown in the SAP tutorial has `TotalNetAmount` (net of tax) and no gross figure, and tax is S/4HANA's own, so the connector checks the net amount. With `price_source = shop` it compares `TotalNetAmount` with the sum of quantity times unit price of the lines it sent, in minor units; one minor unit is tolerated. With `price_source = system` S/4HANA prices the order, nothing is comparable, and the result carries a warning (`extra.warnings`). Tax can therefore not be checked against the WooCommerce total; that is a stated limit of this connector.
- With `total_mismatch = fail` a new order that fails the check (currency, or net amount with `price_source = shop`) is removed (`DELETE A_SalesOrder('id')` with the ETag) and the item fails with SYNC-VAL-002, so the next pass starts clean. If the DELETE is refused the order stays and the message says so. Known limit: without `total_field` (section 6), an order that stays after a refused DELETE is found by its reference on the next pass and linked by `findExisting()` without the check. The person who reads the first error must delete or correct it in S/4HANA. A pass never deletes an order it only found: if `send()` finds an order that fails the check, the item fails with SYNC-VAL-002 ("not made by this pass") and the order stays for a person.

## 8. Flow

1. Parse and check the payload (lines, amounts, currency, reference).
2. `GET A_SalesOrder` by reference. If found, the check of step 5 is made on it and it is returned as existing.
3. Require `sold_to_party`, `sales_organization`, `distribution_channel` and `division` (SYNC-VAL-002 naming the setting), the reference length (35), and `extra_charges`. Resolve every product through `A_Product` (one request per 20 SKUs); unknown SKU is SYNC-MAP-001, before anything is sent.
4. Fetch CSRF, then `POST A_SalesOrder` with `to_Item`. 201 returns the new `SalesOrder`; a lost answer is covered by step 2 on the next pass.
5. Check currency and `TotalNetAmount` (section 7); remove the order if the check fails and `total_mismatch = fail`.

Invoicing: S/4HANA creates billing documents through its own billing steps (the API `API_BILLING_DOCUMENT_SRV` is read-only apart from cancel); the connector never creates or cancels one. `findExisting()` returns an order as found once the sales order exists (nothing else is done by this connector; see the limit in section 7).

Refund: the order must exist (else SYNC-VAL-002 on `order_ref`, before any create) and every refund line must name a product (an amount-only refund is SYNC-VAL-002: credit it in S/4HANA). After the create the request's `TotalNetAmount` and `TransactionCurrency` are compared with the sum of the refund lines (one minor unit tolerated; `TotalNetAmount` on the request is [inferred]). With `total_mismatch = fail` a request this pass made that differs is deleted (`DELETE A_CreditMemoRequest('id')` with the ETag) and the item fails with SYNC-VAL-002; if the delete is refused the request stays and the message says so. A request that was only found is checked but never deleted. Tax is not part of the comparison. `GET A_CreditMemoRequest` by `WC-{refund id}`; if none, `POST A_CreditMemoRequest` with type `CR` (option `credit_memo_type`), the connector's sales organization, distribution channel and division, the order's sold-to party and currency, `ReferenceSDDocument` = the sales order [inferred], `PurchaseOrderByCustomer` = `WC-{refund id}`, and one item per refund line (`Material`, `RequestedQuantity`, and a price condition `price_condition` whose rate is the line amount divided by the quantity). A credit memo request is a request document: S/4HANA turns it into a credit memo in its billing run.

Payments: not built.

## 9. Reading from S/4HANA

- `lookupProduct`, `searchProducts`: `A_Product` (`Product` equals, the upper-case form tried second; `substringof` for the search, at most 25).
- `fetchInvoice($ref)`: `A_BillingDocumentItem?$filter=ReferenceSDDocument eq '{SalesOrder}'` (newest billing document first) then `A_BillingDocument`: number, date, `BillingDocumentIsCancelled` (status `cancelled` or `issued`); `authority_status` is empty; null when there is no billing document yet.
- `listForReconcile`: not built (the Reconciliation flag is off): S/4HANA's `TotalNetAmount` is net of tax and cannot be compared with the shop's totals.
- Stock, tracking: not built.

## 10. Rate limits and pacing

SAP publishes no per-API limit on a page read. The connector runs one request at a time, about two a second (`rateLimitHints()` 2.0 and 1), reads lists with `$top=100` and `$skip` for up to 50 pages. A 503 is SYNC-RATE-001 (`Retry-After`, else 10 seconds); a 429 is SYNC-RATE-001 by the base (`Retry-After`, else 30 seconds). 30 second timeout.

## 11. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| orders | true | `API_SALES_ORDER_SRV` |
| customers | false | Customer creation is asynchronous and tenant specific (section 7) |
| refunds | true | `API_CREDIT_MEMO_REQUEST_SRV` (field names [inferred]) |
| stock_in, tracking_in, prices_in | false | Not built |
| invoice_readback | true | `API_BILLING_DOCUMENT_SRV` read |
| reconciliation | false | Not built: the net amount of S/4HANA cannot be compared with the shop's gross totals |
| webhooks | false | Events need SAP Advanced Event Mesh |
| shadow_lookup | true | `lookupProduct` and `searchProducts` read `A_Product`; `lookupCustomer` answers none |

Entities: order, refund.

## 12. Error mapping

| S/4HANA | SYNC code |
| --- | --- |
| Token request refused (`invalid_client`, `unauthorized_client`), HTTP 401 (after one new token), basic user refused | SYNC-AUTH-001 |
| HTTP 403 (not a CSRF failure), OData error text naming authorisation | SYNC-AUTH-004 |
| HTTP 403 `X-CSRF-Token: Required` | token fetched again once; a second one is SYNC-AUTH-004 |
| HTTP 412, 428 | SYNC-VAL-002 "the record changed" |
| HTTP 503 | SYNC-RATE-001 (wait from `Retry-After`, else 10 s) |
| HTTP 429 | SYNC-RATE-001 (the base: `Retry-After`, else 30 s) |
| HTTP 400 and other 4xx with `{"error":{"code","message":{"value"}}}` | SYNC-VAL-002 (reason: the first `innererror.errordetails[]` message with severity `error`, else `message.value`; redacted by the base) |
| HTTP 404 on a read of one record | none found; a permission probe treats 403 and 404 as "not readable" |
| HTTP 5xx | SYNC-NET-002 |
| Time-out, reset, name not resolved | SYNC-NET-001 |
| Certificate failure | SYNC-NET-003 |
| Unknown SKU | SYNC-MAP-001 |
| Reference too long, extra charges, total or currency differs | SYNC-VAL-002 |
| Unexpected PHP error inside the connector | SYNC-VAL-002 "The connector stopped unexpectedly" (the base bridge) |

SYNC-AUTH-006 and SYNC-AUTH-007 are not produced: no S/4HANA error code for a missing scenario or arrangement was found. The client secret, token, password, tenant host and communication user are removed from every message.

## 13. Not verified

- No live S/4HANA tenant was available. Behaviour above the wire is tested against `FakeSapS4Server` and wire fixtures built from the pages in section 1.
- The official API definitions (Business Accelerator Hub, help portal) could not be read. Property names not on a page above are [inferred]: `CustomerPurchaseOrderDate`, `to_PricingElement` and its fields, `RequestedQuantity` on items, `A_CreditMemoRequest`, its type field and items, `ReferenceSDDocument` on `A_BillingDocumentItem` and on a credit memo request, `A_ProductDescription`.
- The length of `PurchaseOrderByCustomer` (35); the length of `Product` (40) is not checked by the connector.
- Whether DELETE of a sales order is allowed through the API and when.
- The rate limit and the status code of a throttled call (429 or 503).
- The token URL form, scopes and the communication scenario of billing documents and products (`SAP_COM_0009`/`SAP_COM_0158` come from the task text and the SAP guide; the billing document scenario is unknown).
- The gross total of a created order: the answer fields read do not include one; the total check covers the net amount only (section 7).
- `terms_url` and `privacy_url` could not be fetched with the available tool (hosts not permitted).
- Private edition (RISE) and on-premise addresses behave like the public cloud for these APIs, which is stated by the third-party integration page only.
- A sales order created with a price condition may need the pricing procedure to allow manual prices.

## 14. Manual smoke test against a real tenant

No credentials are stored in the repository. Use a test tenant or a SAP S/4HANA Cloud trial.

1. Create the communication user, system and arrangements of section 2; note the API host, and for OAuth the token URL, client id, secret and scope.
2. Add an SAP S/4HANA connection, choose `basic` or `oauth`, enter the sales organization, distribution channel, division and a test customer as the sold-to party. Press Test connection. Expected: a list of readable services; one without an arrangement shows as missing.
3. Create a product in S/4HANA whose `Product` equals a WooCommerce SKU. Place an order. Expected: one sales order whose Customer Reference is `WC-{id}`; run the sync again and confirm that no second order appears.
4. Compare prices and the total with WooCommerce (try `price_source` both ways).
5. Refund the order and check the credit memo request.
6. Remove the communication arrangement and user when done.
