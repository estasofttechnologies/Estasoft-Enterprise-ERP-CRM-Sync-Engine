---
id: netsuite
name: NetSuite
catalog_row: NetSuite
connection_method: cloud_app
available: true
verified: false
hosts: ["*.suitetalk.api.netsuite.com"]
terms_url: https://www.oracle.com/legal/terms/
privacy_url: https://www.oracle.com/legal/privacy/privacy-policy.html
developer_url: https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_162730264820.html
variants: []
---

# NetSuite connector

Connector id `netsuite`, type ERP, contract version 1. Code: `includes/Connectors/NetSuite/` (shared ERP helpers in `includes/Connectors/EnterpriseCommon/`, used by NetSuite, SAP Business One, SAP S/4HANA and Oracle Fusion). It sends WooCommerce orders to NetSuite as sales orders (optionally billed to an invoice), refunds as credit memos made from the invoice, and customers as customer records, and reads invoice status and the nightly check list back. It does not read stock or tracking (section 11 says why).

The connector talks to SuiteTalk REST Web Services only. SOAP and token-based authentication are not used: [documented] Oracle stops accepting new SOAP and TBA integrations from 2027.1 (see Sources).

## 1. Sources

Fetched with WebFetch on 2026-11-02. `[documented]` is an official Oracle NetSuite help page, `[third-party]` is a partner or community page, `[inferred]` is reasoned from the others and not stated anywhere.

| Page | Used for |
| --- | --- |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_162790605110.html | JWT header (`typ`, `alg`, `kid`) and claims (`iss`, `scope`, `aud`, `exp`, `iat`) of the M2M request token; `alg` is one of PS256/384/512 or ES256/384/512; `exp` less than 60 minutes after `iat` |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_162755359851.html | Token endpoint `POST https://<accountID>.suitetalk.api.netsuite.com/services/rest/auth/oauth2/v1/token`, form fields `grant_type=client_credentials`, `client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer`, `client_assertion`; answer `access_token`, `expires_in` 3600, `token_type`; `invalid_grant` when the token expired |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_162730264820.html | M2M flow overview, who may set it up (administrator or the permission "OAuth 2.0 Authorized Applications Management") |
| https://docs.alumio.com/connectors/netsuite/oauth2-setup.md | [third-party] integration record with only "Client Credentials (Machine to Machine) Grant" ticked, key generation with `openssl`, setup page Setup > Integration > Manage Authentication > OAuth 2.0 Client Credentials (M2M) Setup, certificate id |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_156570709583.html | Error body (`status`, `o:errorDetails[].detail`, `o:errorCode`, `o:errorPath`), codes `INVALID_LOGIN` 401, `INVALID_CONTENT` 400, `NONEXISTENT_ID` 404, `INVALID_REQUEST` 400, `CONCURRENCY_LIMIT_EXCEEDED` 429, `UNEXPECTED_ERROR` 500; headers `X-NetSuite-PropertyNameValidation` and `X-NetSuite-PropertyValueValidation` |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N259503.html | Integration concurrency limit: Standard 5 (15 with one SuiteCloud Plus licence), Premium 15 (max 45), Enterprise 20 (max 80), Ultimate 20 (max 140); each licence adds 10; shared by SOAP, REST and RESTlet |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_1504769959.html | Concurrency governed per account; a client over the limit gets an error and must retry; sandbox limit equals production, developer accounts have 5 |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/subsect_1559222360.html | A REST request that runs longer than 15 minutes times out |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_157909186990.html | SuiteQL: `POST /services/rest/query/v1/suiteql`, header `Prefer: transient` (required), body `{"q": "...", "params": [...]}`, query parameters `limit` and `offset`, answer `links`, `count`, `offset`, `totalResults`, `items`, at most 100,000 rows per query |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_1545141395.html | Create: `POST /services/rest/record/v1/customer`, answer 204 with a `Location` header whose last segment is the internal id |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/article_0731124139.html | Update: `PATCH`, 204 with `Location` |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_156335203191.html | Upsert: `PUT /services/rest/record/v1/{type}/eid:{externalId}` creates or updates |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/article_0203101639.html | Batch upsert (not used), `X-NetSuite-idempotency-key` only for asynchronous batch requests (`Prefer: respond-async`) |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N3432681.html | External ids are unique within a record type and some record groups, case insensitive, not shown on the record by default |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_159793606645.html | Sales order body: `entity.id`, `tranDate`, `otherrefnum`, `memo`, `customForm`, `item.items[].item.id`, `quantity` |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_157901123882.html | `POST /record/v1/{source}/{id}/!transform/{target}`; salesOrder to invoice and invoice to creditMemo; optional body with header fields and `item.items`; answer 204 |
| https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/article_0814121722.html | "REST web services do not support legacy tax features. To work with taxation through REST web services, you must have the SuiteTax feature enabled." Record `salesTaxItem` (`name`, `description`, `taxType`, `isInactive`) |

Facts the pages above do not settle are listed in section 13.

## 2. How the merchant connects

The Connect screen shows these steps (V0 reads this list into the catalog field `steps`). No redirect address is needed: this system has no sign-in page. A NetSuite administrator does all of it.

1. In NetSuite turn on the features REST Web Services and OAuth 2.0 under Setup, Company, Enable Features, SuiteCloud.
2. Make a role with REST Web Services, Log in using OAuth 2.0 Access Tokens and sales, invoice, customer and item access; give it to a user.
3. Create an integration record with only Client Credentials (Machine to Machine) and REST Web Services ticked, and copy the Client ID.
4. Make a key pair with the `openssl` command shown here, upload the public file under M2M Setup and copy the Certificate ID.
5. Paste the Account ID, Client ID, Certificate ID and the private key here.
6. Press Test connection.

Plan gates: none found. Oracle states no fee and no approval for REST web services [documented: absence only; the SuiteCloud Developer Network page was not re-read]. Tax needs the SuiteTax feature (section 7). A sandbox works the same way with an account id such as `1234567-sb1`; integration records and M2M mappings are not copied to a sandbox and are cleared when a sandbox is refreshed [documented in the research file, `docs/research/integration-research-vendors.md` 4.13].

## 3. Authentication

Credential fields (`credentialFields()`, stored by the base):

| Key | Where | Secret | Notes |
| --- | --- | --- | --- |
| `account_id` | config | no | Letters, digits, `_` and `-`, 3 to 40 characters, checked before any request. The host is `<account_id lower-cased, "_" replaced by "-">.suitetalk.api.netsuite.com` [third-party]. |
| `client_id` | secrets | yes | Consumer key of the integration record, sent as `iss`. |
| `certificate_id` | config | no | Sent as `kid`. |
| `private_key` | secrets | yes | PEM text, write-only (textarea). Never logged. |
| `algorithm` | config | no | `ES256` (default, key made with `openssl ecparam`) or `PS256`. RS256 is not offered: the NetSuite page lists only PS and ES algorithms. |
| `subsidiary` | config | no | Internal id of the subsidiary new customers belong to; needed on a OneWorld account, empty on others. |

Token: every token is a new signed assertion, `scope` = `rest_webservices`, `aud` = the token URL, `exp` = `iat` + 50 minutes. The answer holds no refresh token (client credentials); the base asks for a new one when fewer than five minutes remain. A token request refused with `invalid_client`, `invalid_request`, `invalid_grant`, `unauthorized_client`, `invalid_scope` or `unsupported_grant_type` is SYNC-AUTH-001 (check Client ID, Certificate ID, key, role). The signature algorithm and the `client_assertion` form are the base's (`OAuthConfig( jwtAlgorithm, jwtMode: 'client_assertion' )`); NetSuite itself was not available to confirm that it accepts the result.

Certificates expire, and the connector cannot read the certificate's end date from NetSuite (the plugin holds only the private key). The merchant sees SYNC-AUTH-002 on the first refused assertion after the certificate ends, and replaces the certificate and key. [inferred]

## 4. Data sent and when (for the WordPress.org "External services" disclosure)

Only the merchant's own NetSuite host `https://<account>.suitetalk.api.netsuite.com` is contacted. Nothing goes to Estasoft.

| Action | Request | Data |
| --- | --- | --- |
| Test connection, every token refresh | `POST /services/rest/auth/oauth2/v1/token` | The signed assertion (client id, certificate id, no secret leaves the site except the signature) |
| Test connection | `GET /services/rest/record/v1/salesOrder?limit=1`, `customer`, `invoice`, `salesTaxItem`; SuiteQL on `item` and `currency` | Nothing about customers |
| Send an order | `GET .../salesOrder/eid:WC-{id}`, SuiteQL on `customer`, `item`, `currency`; `POST` or `PUT .../customer/eid:...`; `PUT .../salesOrder/eid:WC-{id}`; `GET .../salesOrder/{id}?fields=...`; optional `POST .../salesOrder/{id}/!transform/invoice` | Buyer name, email, phone, billing and delivery address, tax number, order lines, prices, order number, order date, note |
| Send a refund | SuiteQL on `transaction`; `POST .../invoice/{id}/!transform/creditMemo` | Refunded lines and amounts, reason |
| Read back | SuiteQL on `transaction` | Order references and totals |

## 5. Objects and field mapping

Canonical field to NetSuite field (target paths are what the mapping screen shows; the connector turns them into the REST body). The paths are the ones the shared ERP parser reads (`includes/Connectors/EnterpriseCommon/OrderParser.php`, `RefundParser.php`).

| Canonical (source) | Payload path | NetSuite field |
| --- | --- | --- |
| `order.client_ref` (queue item, `WC-{id}`) | `client_ref` | `externalId` and `otherRefNum` |
| `order.created_at` | `order_date` | `tranDate` (date part, in the store's time zone) |
| `order.currency` | `currency` | `currency.id` (found by `currency.symbol`) |
| `order.note`, payment reference | `note`, `payment.reference` | `memo`: note, then "Payment reference: ...", cut with an ellipsis and a warning to 950 characters, then the last line `Shop total 59.97 AED` (section 6) |
| `order.total`, `order.excluded_total` | `total` | Not sent as a field; written as the last line of `memo` and compared with the order total |
| buyer: name, email, phone, company, VAT number, address | `partner.name`, `partner.email`, `partner.phone`, `partner.company_name`, `partner.vat`, `partner.street`, `partner.street2`, `partner.city`, `partner.state`, `partner.zip`, `partner.country` | customer `firstName`/`lastName` (a person) or `companyName`, `email`, `phone`, `vatRegNumber`, `subsidiary.id`, `currency.id`, `externalId` |
| `billing.*`, `shipping.*` | `partner.*`, `delivery.*` | `billingAddress` and `shippingAddress` (addressee, addr1, addr2, city, state, zip, country, phone) when `send_addresses` is on |
| `lines[].sku` | `order_line[].product` | `item.items[].item.id` (found by `item.itemid`, or the field named by `sku_field`) |
| `lines[].quantity` | `order_line[].quantity` | `item.items[].quantity` |
| `lines[].unit_price` | `order_line[].price_unit` | `item.items[].rate` (price before tax) |
| `lines[].name` | `order_line[].name` | `item.items[].description` |
| `order.shipping_total` | `shipping.total` | `shippingCost`, or a line with `shipping_item` when that option is set |
| `fees[]` | `fee[].name`, `fee[].total` | lines with `fee_item` |
| `order.discount_total` | `discount.total` | a line with `discount_item` and a negative `rate` |

Refund payload: `order_ref` (`WC-{order id}`), `date`, `reason`, `amount`, `refund_line[].{product, quantity, amount}`.
Customer payload: the `partner.*` paths above.

Options (connection settings, key `options`): `documents` (`order`, `order_invoice`), `customer_policy` (`match_or_create`, `match_only`), `total_mismatch` (`fail`, `warn`), `sku_field` (`itemid` default), `send_addresses` (off by default: whether NetSuite takes address subrecords in a REST create is not verified, section 13), `shipping_item`, `fee_item`, `discount_item`, `refund_item` (item ids), `subsidiary`.

Required in the default mapping: client reference, customer email, customer name, product and quantity of every line. Money is an integer in minor units inside the plugin; the connector writes decimals with the currency's exponent (KWD, BHD, OMR three decimals, JPY none) from exact decimal strings.

Record names and field names above come from the pages in section 1 where they appear there (`entity`, `item.items`, `tranDate`, `otherrefnum`, `memo`, `externalId` in the upsert URL, `salesTaxItem`, `taxType`). The rest (`shippingCost`, `billingAddress`, `shippingAddress`, `vatRegNumber`, `currency.symbol`, `item.itemid` and the `transaction` columns used in SuiteQL) are [third-party] or [inferred] from SuiteScript and SuiteAnalytics names and are listed in section 13.

## 6. Duplicate protection

The client reference `WC-{order id}` is the `externalId` of the sales order and also its `otherRefNum`. NetSuite keeps external ids unique within the record type [documented].

- `findExisting()`: `GET /record/v1/salesOrder/eid:WC-{id}`. A 404 (`NONEXISTENT_ID`) means none. A found order is returned as existing only when its steps are done (section 8, "Every pass starts from a read").
- Creation uses the documented upsert `PUT /record/v1/salesOrder/eid:WC-{id}`, so a replay of a lost first request cannot make a second order: NetSuite finds the same external id. The upsert is only attempted after the GET answered 404.
- Customers: SuiteQL by lower-cased email first; a new customer is created with `PUT /record/v1/customer/eid:WC-C-{first 20 hex of sha1 of the lower-cased email}`, so a retry finds the same customer.
- Credit memos: the transform makes the record, then a `PATCH` sets `externalId` and `otherRefNum` to `WC-{refund id}`. The connector searches by that external id or by the memo `[WC-{refund id}]` (SuiteQL on `transaction`, type `CustCred`) before it transforms, so a PATCH that was lost is still found.
- `X-NetSuite-idempotency-key` exists only for asynchronous batch requests [documented]; it is not used.

Unfinished and wrong records: `findExisting()` returns a reference only when `send()` would have nothing left to do for the order (order exists; with `documents = order_invoice` also the invoice exists). The engine links whatever `findExisting()` returns without calling `send()`, and `findExisting()` has no payload, so the shop's total is written as the last line of the order's `memo` ("Shop total 59.97 AED"). `findExisting()` compares it with the order's `total` and currency and returns SYNC-VAL-002 instead of a reference when they differ and `total_mismatch = fail`; editing the memo by hand switches that comparison off for that order. The wrong order stays in NetSuite for a person; the connector never deletes an order. It deletes only a credit memo that this pass made and that failed its check (section 8).

## 7. Taxes, currency and prices

- Tax: REST web services support only SuiteTax [documented]. With SuiteTax NetSuite works out tax from nexus and item and tax rules, so the connector sends no tax code on lines. The tax mapping screen lists nothing (`listTaxCodes` is empty); Test connection reports whether `salesTaxItem` can be read (`suitetax`, informational). A line's tax is whatever NetSuite computes.
- Total check: after the order exists, `GET /record/v1/salesOrder/{id}?fields=id,tranId,total,currency,tranDate,memo,externalId` is compared with `order.total - order.excluded_total` in minor units; a difference of one minor unit is rounding. More: the default option `total_mismatch = fail` stops the order, so a legacy-tax account or a wrong nexus never syncs wrong money (and `findExisting()` keeps reporting it, section 6). `warn` lets it go on with a warning in the result.
- Prices: NetSuite item lines take a price before tax. A payload with `prices_include_tax` set is refused with SYNC-VAL-002 naming the reason; the WooCommerce reader supplies prices before tax by default.
- Currency: the order is created in the order's currency (`currency.id` found by ISO code through SuiteQL on `currency`). After creation the currency is read back and a different one fails the order. A customer that does not hold the currency makes NetSuite refuse the order; the message is SYNC-VAL-002 with NetSuite's text. The exchange rate is NetSuite's own.
- Date: `tranDate` is the order date in the store's time zone (the WordPress setting): a Gulf shop at UTC+4 would otherwise get the previous day between midnight and 4 a.m., which can be the wrong tax period at month end. The invoice made by the transform gets the same date in the transform body. [inferred: the transform body accepts `tranDate`.]

## 8. Order, invoice and refund flow

`documents` option: `order` (default) or `order_invoice`.

Every pass starts from a read:

1. Parse and check the mapped payload before any request (email, name, lines, amounts, currency); `prices_include_tax` is refused here.
2. `GET salesOrder/eid:WC-{id}`. If it exists, jump to step 7.
3. Resolve the currency, the customer (by email, per `customer_policy`; created with the upsert above) and every product. Unknown SKU: SYNC-MAP-001 before the order is created; there is no option to skip a line (NetSuite needs items). A fee or discount with no `fee_item` or `discount_item` is SYNC-VAL-002 naming the option.
4. `PUT salesOrder/eid:WC-{id}` with `entity`, `tranDate`, `currency`, `otherRefNum`, `memo`, `item.items[]`, addresses, `shippingCost`.
5. Read the id from the `Location` header (204), else `GET salesOrder/eid:WC-{id}`.
6. Total and currency check (section 7).
7. With `order_invoice`: SuiteQL for an invoice whose `createdfrom` is the order; if none, `POST salesOrder/{id}/!transform/invoice` with `tranDate`; the invoice is read back afterwards (SuiteQL) and its total checked as well. The transform is run once; if it fails in a way that may have reached NetSuite (time-out), the next pass reads the invoice first, so it is never repeated blindly.
8. The result's reference is the sales order id and `tranId`; the link is `https://<account>.app.netsuite.com/app/accounting/transactions/salesord.nl?id={id}` [inferred].

A sales order that needs approval before it can be billed makes the transform fail; the message names it (SYNC-VAL-002) and the next pass continues from the invoice step once a person approves the order.

Refund: the order must exist in NetSuite and needs an invoice (`documents = order_invoice`, or one made in NetSuite). Without an order the item fails with SYNC-VAL-002 on `order_ref`; without an invoice with SYNC-VAL-002 "NetSuite has no invoice for this order, so there is nothing to credit". The credit memo is made with `POST invoice/{id}/!transform/creditMemo` with the refund's lines in `item.items` (`item.id`, `quantity`, `amount`), `memo` `[WC-{refund id}] reason` and `tranDate`; a `PATCH` then sets `externalId` and `otherRefNum` to `WC-{refund id}`. A refund with no lines is one line for the amount with the item in the option `refund_item`; without that option it fails with SYNC-VAL-002. The credit memo is found again by external id or memo before a retry. Credit memos are not applied to the invoice automatically.

Read-back: the transform page does not say whether the lines in the body replace the lines copied from the invoice or are added to them, so the credit memo is read back (`foreigntotal`, `currency`) and compared with the refund amount (one minor unit tolerated). A difference with `total_mismatch = fail` removes the memo again (`DELETE /record/v1/creditMemo/{id}` [inferred]) and fails the item with SYNC-VAL-002 naming both amounts; if the delete is refused the memo stays and the message says so. A memo that was only found (made by an earlier pass) is checked but never deleted. The refund total is also the last line of the memo ("Shop total 19.99 AED"), so `findExisting()` returns the error instead of linking a wrong memo. With `warn` the memo is kept and the difference is a warning.

Payments: not built. NetSuite can record customer payments, but the REST fields for a payment against an invoice and the deposit account mapping are not documented on the pages read.

## 9. Reading from NetSuite

- `lookupCustomer`: SuiteQL, case-insensitive email, inactive customers left out.
- `lookupProduct`, `searchProducts`: SuiteQL on `item` by `itemid` (or `sku_field`) and display name; paged.
- `listTaxCodes` / `listTaxes`: none (empty list), see section 7.
- `fetchInvoice($ref)`: the latest invoice created from the sales order: number, status (`A` open, `B` paid in full), totals, issue date. No tax-authority status exists in this API call; `authority_status` is empty.
- `listForReconcile($from)`: sales orders with `externalid` starting `WC-` and a transaction date from the day before `from` on (the dates are the shop's own, not UTC), with totals and the sum of credit memos with `otherrefnum` equal to the order reference; paged through `offset` until `hasMore` is false.
- Webhooks: none (NetSuite has none natively).

## 10. Rate limits and pacing

No requests-per-minute figure is documented. The documented limit is concurrency per account (Standard 5, shared with every other integration of the account); an over-limit request fails with `CONCURRENCY_LIMIT_EXCEEDED` [documented]. The connector runs one request at a time, about two a second (`rateLimitHints()` returns 2.0 and 1) and gives the code `CONCURRENCY_LIMIT_EXCEEDED` SYNC-RATE-001 with the `Retry-After` value or 10 seconds; a bare 429 is mapped by the base (`Retry-After`, else 30 seconds). 30 second timeout on every request.

## 11. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| orders | true | Sales order and optional invoice, documented |
| customers | true | Customer upsert and SuiteQL lookup, documented |
| refunds | true | Credit memo from invoice transform, documented (needs an invoice) |
| stock_in | false | Stock per location through REST is not documented on any page read |
| tracking_in | false | A tracking number write or read through REST is unconfirmed |
| prices_in | false | Not built |
| invoice_readback | true | SuiteQL on invoices |
| reconciliation | true | SuiteQL on sales orders and credit memos |
| webhooks | false | None |
| shadow_lookup | true | `lookupCustomer`, `lookupProduct` and `searchProducts` are reads (SuiteQL) |

Entities: order, refund, customer.

## 12. Error mapping

| NetSuite | SYNC code |
| --- | --- |
| Token endpoint `invalid_client`, `invalid_request`, `invalid_grant`, `unauthorized_client`, `invalid_scope`, `unsupported_grant_type`; HTTP 401 `INVALID_LOGIN` (after one new token) | SYNC-AUTH-001 (the base gives AUTH-002 for a sign-in that expired) |
| HTTP 403 or `o:errorCode` `INSUFFICIENT_PERMISSION` | SYNC-AUTH-004 |
| HTTP 429, `CONCURRENCY_LIMIT_EXCEEDED` | SYNC-RATE-001 (wait from `Retry-After`, else 10 s) |
| HTTP 400, 409 or 422 with an `o:errorDetails[].detail` (`INVALID_CONTENT`, `INVALID_REQUEST`, ...) | SYNC-VAL-002 (field from `o:errorPath`, reason from `detail`, redacted by the base) |
| HTTP 404 `NONEXISTENT_ID` on a lookup | not an error (none found); on a transform source it is SYNC-VAL-002 |
| HTTP 5xx `UNEXPECTED_ERROR` | SYNC-NET-002 (the Error ID is kept in the log detail only) |
| Time-out, reset, name not resolved | SYNC-NET-001 |
| Certificate failure | SYNC-NET-003 |
| Unknown SKU | SYNC-MAP-001 |
| Missing email | SYNC-MAP-003 |
| Total or currency differs, `prices_include_tax`, no invoice to credit, fee or discount item not set | SYNC-VAL-002 |
| Unexpected PHP error inside the connector | SYNC-VAL-002 "The connector stopped unexpectedly" (the base bridge) |

Plan or licence gate SYNC-AUTH-006 and SYNC-AUTH-007 are never produced: no NetSuite error code for a missing REST feature or an unmapped integration was found, so nothing is guessed. Account identifiers, the host, the client id, the certificate id and the key are removed from every message.

## 13. Not verified

- No live NetSuite account was available. All behaviour above the wire is tested against `FakeNetSuiteServer` and wire fixtures written from the pages in section 1.
- `terms_url` and `privacy_url` could not be fetched with the available tool (hosts not permitted); they are the standard Oracle pages and need a check by a person.
- The REST API browser pages are scripts and could not be read: names of fields not shown in the pages of section 1 (`shippingCost`, `billingAddress`, `shippingAddress`, `vatRegNumber`, line `description`, `currency` on customers, the exact casing of `otherRefNum`) are [inferred] from SuiteScript record names.
- Whether `GET /record/v1/{type}/eid:{id}` is accepted for reading (only the upsert `PUT` form is on a page read) and whether `PUT .../eid:` answers 204 or 201 with a `Location` header.
- SuiteQL column names on `transaction` (`type`, `externalid`, `otherrefnum`, `createdfrom`, `trandate`, `foreigntotal`, `status`), `item` (`itemid`, `displayname`, `isinactive`, `upccode`), `customer` (`email`, `isinactive`) and `currency` (`symbol`) are [third-party].
- That the transform body accepts `tranDate`, `externalId` and `item.items` overrides for salesOrder to invoice and invoice to creditMemo. The page shows `memo`, `enddate`, `billdate` and `item.items` for the first and `apply` for the second. Whether `item.items` replaces or adds to the copied lines is not stated; the connector therefore checks the total of every credit memo it makes (section 8).
- That `DELETE /record/v1/creditMemo/{id}` is allowed for a credit memo made this way (used only to take back a memo that failed the check).
- Whether creating addresses as part of a sales order is accepted (REST does not support updating subrecords).
- That the signed assertion is accepted by NetSuite: the ES256 and PS256 signatures are produced by the base (`JwtAssertion`) and verified there against local keys, not against NetSuite.
- The maximum page size of SuiteQL and the `hasMore` field (the page read does not show them; the connector pages with `limit=200` until a page comes back short or `hasMore` is false).
- Certificate validity limits, the permission list of the role (the page names only "REST Web Services" and "Log in using OAuth 2.0 Access Tokens"; record permissions are the connector's reading), and whether SuiteQL needs a further permission.
- A payload size limit and any per-minute rate: not found.
- The Saudi Arabia (ZATCA) flow of the Electronic Invoicing SuiteApp and UAE e-invoicing: not covered; the connector never claims compliance. A Saudi merchant issues the invoice inside NetSuite.

## 14. Manual smoke test against a real account

No credentials are stored in the repository. Use a sandbox account.

1. Do the steps of section 2 in a sandbox. Generate the key with `openssl ecparam -name prime256v1 -genkey -noout -out private.pem` and `openssl req -new -x509 -key private.pem -out public.pem -days 365` and upload `public.pem`.
2. Add a NetSuite connection with the sandbox account id (for example `1234567-sb1`), client id, certificate id, `ES256` and the private key. Press Test connection. Expected: the account, the permissions found (sales orders, customers, invoices, items, SuiteTax) and a list of currencies. A wrong certificate id gives SYNC-AUTH-001; a role without REST Web Services gives SYNC-AUTH-004 on the first read.
3. Place a WooCommerce order whose SKU equals an item name in NetSuite. Expected: one sales order with External ID `WC-{id}` and PO number `WC-{id}`; run the sync again and confirm that no second order appears.
4. Set `documents = order_invoice`, approve the sales order, place another order and check the invoice. Check that totals equal the WooCommerce totals, including tax, and that the order date is the WooCommerce date.
5. Refund the order in WooCommerce; expected: one credit memo with External ID `WC-{refund id}`.
6. Try an order in a currency the customer does not hold and check the message.
7. Remove the integration record and certificate when done.
