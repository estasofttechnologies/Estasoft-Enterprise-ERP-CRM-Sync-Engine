---
id: oracle-fusion
name: Oracle Fusion Cloud ERP
catalog_row: Oracle Fusion Cloud ERP
connection_method: cloud_app
available: true
verified: false
hosts: []
terms_url: https://www.oracle.com/legal/terms/
privacy_url: https://www.oracle.com/legal/privacy/privacy-policy.html
developer_url: https://docs.oracle.com/en/cloud/saas/financials/26a/farfa/Retrieving_Bearer_Token_Using_IDCS.html
variants: []
---

# Oracle Fusion Cloud ERP connector

Connector id `oracle-fusion`, type ERP, contract version 1. Code: `includes/Connectors/OracleFusion/` (shared ERP helpers in `includes/Connectors/EnterpriseCommon/`). It sends WooCommerce orders to Oracle Fusion Cloud Receivables as invoices through the REST API `receivablesInvoices`, refunds as on-account credit memos through `receivablesCreditMemos`, and reads items and the nightly check list back. The pod host and the identity domain host are the merchant's own (`https://<instance>.fa.<datacenter>.oraclecloud.com` and `https://idcs-….identity.oraclecloud.com`), so `hosts` in the front matter is empty and the connector accepts any public https address; private addresses are refused unless the filter `estasoft_sync_allow_private_hosts` allows them.

What it does not do, and why: it does not create customer accounts (REST creates the party only; the account is SOAP, section 7), reads no stock, and no tracking.

## 1. Sources

Fetched with WebFetch on 2026-11-02. `[documented]` is an Oracle documentation page, `[third-party]` a partner page, `[inferred]` reasoned and not stated. Several pages of the Financials REST reference answer with the guide's landing page (the reference is a script application), so some attribute names could not be read.

| Page | Used for |
| --- | --- |
| https://docs.oracle.com/en/cloud/saas/financials/25d/farfa/op-receivablesinvoices-post.html | [documented] `POST /fscmRestApi/resources/11.13.18.05/receivablesInvoices`, header `REST-Framework-Version`, `Upsert-Mode`; body attributes with lengths: `BusinessUnit` (240), `TransactionSource` (50), `TransactionType` (20), `TransactionNumber` (20), `TransactionDate`, `BillToCustomerNumber` (30), `BillToCustomerName` (360), `InvoiceCurrencyCode` (15), `ConversionRate`, `ConversionRateType`, `ConversionDate`, `PaymentTerms` (15), `PurchaseOrder` (50), `CrossReference` (30), `Comments` (1760), `ShippingReference` (50), `FreightAmount`, `InvoiceStatus` (default `Complete`), `AllowCompletion`, `CustomerTransactionId`, `DocumentNumber`, `DefaultTaxationCountry`; child arrays `receivablesInvoiceLines`, `receivablesInvoiceDistributions`, `receivablesInvoiceInstallments`, `notes`; the example uses `-H "REST-Framework-Version: 2"` |
| https://download.oracle.com/ocomdocs/global/saas_docs/fa/19A/fin/ar_invoice_usage_notes.htm | [documented] create sample with `BillToCustomerNumber`, `BusinessUnit`, `TransactionSource`, `TransactionType`, `InvoiceCurrencyCode`, `PaymentTerms`, `TransactionDate`; lines `receivablesInvoiceLines` with `Description`, `LineNumber`, `Quantity`, `UnitSellingPrice`; privilege `AR_CREATE_RECEIVABLES_INVOICE_PRIV`; `InvoiceStatus` Complete, Incomplete, Frozen; setups needed (Receivables System Options, Payment Terms, Transaction Type, Transaction Source, Auto-Accounting, Remit-to Address, Customer); the answer returns `CustomerTransactionId`; manual tax lines through `receivablesInvoiceLineTaxLines` (`TaxRateCode`, `TaxAmount`, `TaxableAmount`) |
| https://docs.oracle.com/en/cloud/saas/financials/25d/farfa/Create_Receivables_Credit_Memos.html | [documented] the Receivables Credit Memo REST API creates on-account credit memos; examples with lines, foreign currency and manual tax lines exist |
| https://docs.oracle.com/en/cloud/saas/financials/26a/farfa/Retrieving_Bearer_Token_Using_IDCS.html | [documented] `POST https://<IDCS_HOST>/oauth2/v1/token`, `Content-Type: application/x-www-form-urlencoded;charset=UTF-8`, client id and secret as Basic, body `grant_type=client_credentials&scope=urn:opc:resource:fusion:<POD>:erp/`; answer `access_token`, `token_type`, `expires_in` (typically 3600); the client id must also exist as a Fusion user (Security Console), followed by the scheduled process "Import User and Role Application Security Data"; the confidential application allows "Client credentials and JWT assertion" |
| https://docs.oracle.com/en/cloud/saas/applications-common/25a/farca/Quick_Start.html | [documented] authentication over SSL: basic, SAML 2.0 bearer, JWT, OAuth 2.0 (for v1 resources); `Content-Type: application/vnd.oracle.adf.resourceitem+json` on bodies; URL `https://<server>/fscmRestApi/resources/11.13.18.05/<resource>`; `describe` shows `rangeSize` 25 |
| https://docs.oracle.com/en/cloud/saas/supply-chain-and-manufacturing/26b/fasrp/op-itemsv2-get.html | [documented] `GET /fscmRestApi/resources/11.13.18.05/itemsV2` with `q`, `limit`, `offset`, `fields`, `orderBy`, `onlyData`, `finder` (`ItemNumberAltKey;ItemNumber=...`); attributes `ItemId`, `ItemNumber`, `ItemDescription`, `OrganizationId`, `OrganizationCode`, `PrimaryUOMValue` |
| https://www.apideck.com/blog/oracle-fusion-cloud-api-integration | [third-party] response `items`, `count`, `hasMore`, `limit`, `offset`; `q` syntax of framework version 1 (`A='x';B=y`) and 2+ (`A = 'x' and B = 'y'`); 429 when over the limit, per user; missing job roles give 403; wrong content type gives cryptic errors; OAuth through OCI IAM identity domains, token endpoint `https://{identity-domain-url}/oauth2/v1/token` |
| https://www.cleverence.com/articles/oracle-documentation/rest-api-for-oracle-fusion-cloud-financials-6371/ | [third-party] `q`, `fields`, `expand`, `limit`, `offset`; responses carry an `ETag` and updates send `If-Match`; honour 429 `Retry-After` |
| https://www.ateam-oracle.com/a-simple-guide-to-erp-cloud-customer-creation-through-integration | [third-party] REST creates party and address (`/crmRestApi/resources/11.13.18.05/hubOrganizations`, `hubPersons`); the customer account, site and profile are SOAP services (`CustomerAccountService`, `ReceivablesCustomerProfileService`) |
| `docs/research/integration-research-vendors.md` 4.8 | GET returns at most 499 rows; throttling returns 429; limits unpublished |

## 2. How the merchant connects

The Connect screen shows these steps (V0 reads the list into the catalog field `steps`). No redirect address is needed: client credentials have no sign-in page. An identity domain administrator and a Fusion administrator are both needed.

1. In the identity domain make a confidential app with client credentials; copy its Client ID, Secret and the `urn:opc:resource:fusion:` scope.
2. In Fusion, Security Console, add a user whose user name is the Client ID and give it the Receivables roles.
3. Run the scheduled process Import User and Role Application Security Data once so the user and its roles are known.
4. Enter the Fusion address, the identity domain address, the Client ID, the Secret and the scope here.
5. Enter the business unit, transaction source, transaction type and the customer account number web orders are billed to.
6. Press Test connection.

Plan gates: the roles and privileges (for example `AR_CREATE_RECEIVABLES_INVOICE_PRIV` [documented]) belong to the Fusion user. A user without them gets 403 [third-party]; no Oracle error code for a missing licence was found, so SYNC-AUTH-006 is not used. Basic authentication is also accepted by Fusion over SSL [documented]; it is offered as `auth_method = basic` but Oracle advises against it for production and it fails when multi-factor authentication is on [third-party].

## 3. Authentication

Credential fields:

| Key | Where | Secret | Notes |
| --- | --- | --- | --- |
| `address` | config | no | `https://<instance>.fa.<datacenter>.oraclecloud.com` (path cut off; https only; public hosts). |
| `auth_method` | config | no | `oauth` (default) or `basic`. |
| `identity_url` | config | no | `https://idcs-….identity.oraclecloud.com` or the identity domain URL; the token URL is this plus `/oauth2/v1/token`. |
| `scope` | config | no | `urn:opc:resource:fusion:<POD>:erp/` exactly as the application shows it. |
| `client_id`, `client_secret` | secrets | yes | OAuth. |
| `user` | config | no | Basic. |
| `password` | secrets | yes | Basic. |
| `business_unit`, `transaction_source`, `transaction_type`, `payment_terms`, `bill_to_account` | config | no | Required to send an invoice (section 5). |

OAuth: client credentials with the secret in a Basic header and `scope`; no refresh token. The token is valid about 3600 seconds [documented: "typically 3600 unless changed when the app was created"]; the base asks for a new one five minutes before the end. An invalid client or secret is SYNC-AUTH-001. No Oracle answer that names an expired secret was found, so SYNC-AUTH-005 is not produced.

## 4. Data sent and when (for the WordPress.org "External services" disclosure)

Only the Fusion address and the identity domain address the merchant typed are contacted. Nothing goes to Estasoft.

| Action | Request | Data |
| --- | --- | --- |
| Token | `POST {identity_url}/oauth2/v1/token` | Client id and secret (Basic header), scope |
| Test connection | `GET receivablesInvoices?limit=1`, `GET itemsV2?limit=1`, `GET receivablesCreditMemos?limit=1` | Nothing about customers |
| Send an order | `GET itemsV2` (SKU), `GET receivablesInvoices?q=...` (reference), `POST receivablesInvoices` with lines | Order number and date, bill-to account number, SKU, description, quantities, prices, currency, note |
| Send a refund | `GET receivablesCreditMemos?q=...`, `POST receivablesCreditMemos` | Refunded lines and amounts, reason |
| Read back | `GET receivablesInvoices`, `receivablesCreditMemos` | References and totals |

## 5. Objects and field mapping

Payload paths are the ones the shared ERP parser reads (`includes/Connectors/EnterpriseCommon/OrderParser.php` and `RefundParser.php`); the default rules (version 1) fill them from the WooCommerce order.

| Canonical (source) | Payload path | Fusion attribute (`receivablesInvoices`) |
| --- | --- | --- |
| `order.client_ref` | `client_ref` (the queue item supplies `WC-{id}`) | `PurchaseOrder` (50), or `CrossReference` (30) by option `reference_field` |
| `order.created_at` | `order_date` | `TransactionDate` (the date part) |
| `order.currency` | `currency` | `InvoiceCurrencyCode` |
| `order.note`, payment reference | `note`, `payment.reference` | `Comments` (1760): note, then the payment reference, then the last line `Shop total 59.97 AED` (section 6); the note is cut with an ellipsis and a warning to make room for that line |
| a custom field | `bill_to_customer_number` | `BillToCustomerNumber` (wins over the option `bill_to_account`) |
| `order.shipping_total` | `shipping.total` | `FreightAmount` |
| `lines[].sku`, `lines[].name` | `order_line[].product`, `order_line[].name` | `receivablesInvoiceLines[].Description` = `[SKU] name` (240, cut with an ellipsis and a warning) |
| `lines[].quantity` | `order_line[].quantity` | `receivablesInvoiceLines[].Quantity` (JSON number) |
| `lines[].unit_price` | `order_line[].price_unit` | `receivablesInvoiceLines[].UnitSellingPrice` (JSON number, exact for the currency's decimals) |
| none by default | `order_line[].tax`, `shipping.tax` | `receivablesInvoiceLines[].TaxClassificationCode` [inferred], sent only when a custom mapping rule fills the path |
| `fees[]`, `order.discount_total` | `fee[]`, `discount.total` | extra lines (a discount line has a negative `UnitSellingPrice`) |
| `order.total`, `order.excluded_total` | `total` | not sent as an attribute; compared with the invoice total and written as the last line of `Comments` |

Constants from the connection: `BusinessUnit`, `TransactionSource`, `TransactionType`, `PaymentTerms` (only when set). `InvoiceStatus` is not sent: the documented default is `Complete`. Refund payload: `order_ref`, `date`, `reason`, `amount`, `refund_line[].{product, quantity, amount}`.

Lines are descriptive: the SKU is put in the description. The option `memo_line` (name of a Receivables memo line, attribute `MemoLine` [inferred]) is sent on every line when set. Item numbers are checked through `itemsV2` when `unknown_sku = fail` (default); `free_text` skips the check, and `lookupProduct` then accepts every SKU as it is, so the SKU match of the default rules works for a merchant who cannot read items through REST.

The default rules offer no buyer fields and no tax code field, because every order is billed to one account and Fusion works tax out; the mapping screen shows one extra target, `bill_to_customer_number`.

Options (connection settings, key `options`): `unknown_sku` (`fail`, `free_text`), `total_mismatch` (`fail` default, `warn`), `reference_field` (`purchase_order` default, `cross_reference`), `memo_line`, `credit_memo_type` (default `Credit Memo`), `rest_framework_version` (2 to 9, default 2). `payment_terms` is a connection setting.

## 6. Duplicate protection

The client reference `WC-{order id}` is written to `PurchaseOrder` (or `CrossReference`). Before every create the connector searches: `GET receivablesInvoices?q=PurchaseOrder = 'WC-123' and BusinessUnit = '...'&orderBy=CustomerTransactionId:asc&limit=2&onlyData=true` with `REST-Framework-Version: 2` (the `q` syntax of version 2; a single quote inside a value is doubled [inferred]). The oldest match is used. Credit memos use `WC-{refund id}` the same way. The business unit is part of the filter, so an invoice in another unit is not taken for this one. Oracle documents `Upsert-Mode` only for resources keyed by their own identifiers; it is not used. A create whose answer was lost is found by this search on the next pass. A reference longer than the field is refused (SYNC-VAL-002), never cut.

The engine links whatever `findExisting()` returns and does not call `send()` for it, and `findExisting()` has no payload. So that a wrong invoice is not linked silently, the shop's total is the last line of `Comments` ("Shop total 59.97 AED") and `findExisting()` compares it with the invoice's `TotalAmount`: a difference (with `total_mismatch = fail`) is returned as SYNC-VAL-002 instead of a reference. An invoice whose `InvoiceStatus` is `Incomplete` is returned as SYNC-VAL-002 too, asking a person to complete or delete it in Fusion. A person who edits `Comments` by hand removes the marker and so switches the comparison off for that invoice.

## 7. Customers, taxes, currency and the total check

- Customers: not synced. The REST API creates the party and its address (`hubOrganizations`, `hubPersons`) but the customer account, site and profile are SOAP services [third-party], and an invoice needs an existing account number. All orders are billed to `bill_to_account` (or to the number a mapping supplies in `bill_to_customer_number`). Buyer details stay in WooCommerce and the order number is on the invoice. `lookupCustomer()` always answers "none" (after checking the sign-in).
- Tax: Fusion works tax out from its tax rules during completion. The connector sends `TaxClassificationCode` only when a custom mapping rule fills `order_line[].tax`. The tax mapping screen lists nothing (`listTaxCodes` is empty: no tax code list API was read).
- Prices: Fusion lines take a unit selling price; tax-inclusive prices are not expressible, so a payload with `prices_include_tax` is refused with SYNC-VAL-002 before any request.
- Currency: `InvoiceCurrencyCode` is the order's. The invoice is read back and a different currency fails the order. If the order currency differs from the ledger currency Fusion needs a rate: `ConversionRateType` is left to Fusion's default and a missing rate makes Fusion refuse the invoice; its words reach the person as SYNC-VAL-002.
- Total check: the attribute that holds the invoice total is not on the pages read; the connector reads `TotalAmount` [inferred] and, for an invoice created a moment ago, `InvoiceBalanceAmount` [inferred] (equal to the total until something is paid or credited). If the answer to the create has neither, the invoice is read again once. The figure is compared with the shop total (`total` less excluded products) in minor units, one unit tolerated. When Fusion shows no total the check is skipped and the result carries a warning (`extra.warnings`), and the nightly check list returns SYNC-VAL-002 saying so instead of reporting every order missing. A difference with `total_mismatch = fail` leaves the invoice in Fusion (a posted invoice cannot be deleted by this connector), the item fails with SYNC-VAL-002 naming both totals, and `findExisting()` keeps returning that error (section 6) so that no second invoice is made and the wrong one is not linked; a person fixes it in Fusion. With `warn` the invoice is linked and the difference is a warning.
- Dates: `TransactionDate` is the order date (UTC date). `AccountingDate` is left to Fusion; completion may set it to the open period, which can differ from the order date, so the nightly check uses `TransactionDate`.

## 8. Flow

1. Parse and check the payload (reference, lines, amounts, currency); refuse `prices_include_tax`; require `business_unit`, `transaction_source`, `transaction_type` and the bill-to account (SYNC-VAL-002 naming the setting).
2. `GET receivablesInvoices` by reference and business unit. If found: an `Incomplete` invoice is SYNC-VAL-002, otherwise the invoice is compared with the payload (step 6) and returned as existing.
3. Check the reference length, then resolve every SKU through `itemsV2` (`q=ItemNumber = '...'`, one request per distinct SKU, the upper-case form tried second) unless `unknown_sku = free_text`. Unknown SKU is SYNC-MAP-001, before anything is created.
4. `POST receivablesInvoices` with `Content-Type: application/vnd.oracle.adf.resourceitem+json`, the header fields and `receivablesInvoiceLines`. Fusion answers with the new invoice (`CustomerTransactionId`, `TransactionNumber`). A lost answer is covered by step 2 on the next pass.
5. If the answer shows no total, read the invoice again (`GET receivablesInvoices/{CustomerTransactionId}`).
6. Check status, currency and total (section 7).

The result's id is `CustomerTransactionId`, its reference `TransactionNumber`, and `extra.total_minor` the total when Fusion shows one.

Refund: `order_ref` must name an invoice in Fusion (SYNC-VAL-002 before any create otherwise). `GET receivablesCreditMemos` by `WC-{refund id}`; if none, `POST receivablesCreditMemos` with the business unit, source, bill-to account and currency taken from the invoice, `TransactionType` = `credit_memo_type`, the reference, `TransactionDate` = the refund date, `Comments` = "Refund {WC-refund} of order {WC-order}: reason", and `receivablesCreditMemoLines` with one line per refund line (`[SKU] Refund`, quantity, unit price = amount divided by quantity) or, for a refund with no lines, one line "Refund of order {WC-order}" for the amount. It is an on-account credit memo [documented]: it is not applied to the invoice by this connector, so a Receivables clerk applies it. Attribute names of credit memos and their lines are [inferred] from the invoice resource, and so is the sign convention (positive quantities and prices are sent). Payments (receipts) are not built.

## 9. Reading from Fusion

- `lookupProduct`: `itemsV2` by `ItemNumber` (`q`, exact, then the upper-case form); with `free_text` the SKU itself, without a request. `searchProducts`: `q=ItemNumber like '%TEXT%' or ItemDescription like '%text%'` (the number part upper-cased), at most 25. Pages are read with `limit=100` and `offset` until `hasMore` is false.
- `fetchInvoice($ref)`: the invoice by `CustomerTransactionId`: `number` = `TransactionNumber`, `status` = `issued` (`draft` when `InvoiceStatus` is Incomplete), `issued_at` = `TransactionDate`; `authority_status` is empty. Null when the invoice does not exist.
- `listForReconcile($from)`: invoices whose reference starts `WC-`, in the business unit, with `TransactionDate >= from` (`q` with `like`), keyed by reference with `total_minor` (`TotalAmount`), `refunded_minor` and an empty `email`. `refunded_minor` is the sum of the absolute `TotalAmount` of the credit memos with the same filter whose `Comments` say "of order WC-…". If an invoice or a credit memo shows no total, the call returns SYNC-VAL-002 instead of a list.
- Stock, tracking: not built.

## 10. Rate limits and pacing

Limits are unpublished; a limited call answers 429 [third-party] and limits apply per user. The connector makes one request at a time, about two a second (`rateLimitHints()` 2.0 and 1), pages with `limit=100` (the research file reports at most 499 rows per GET) and gives a 429 SYNC-RATE-001 with `Retry-After`, else 10 seconds. 30 second timeout.

## 11. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| orders | true | `receivablesInvoices` |
| customers | false | Account creation is SOAP only (section 7) |
| refunds | true | `receivablesCreditMemos` (on-account); attribute names inferred, section 13 |
| stock_in, tracking_in, prices_in | false | Not built |
| invoice_readback | true | `receivablesInvoices` |
| reconciliation | true | `q` filter on the reference; needs a pod that shows invoice totals |
| webhooks | false | Needs Oracle Integration Cloud |
| shadow_lookup | true | `lookupProduct` and `searchProducts` read items; `lookupCustomer` answers none |

Entities: order, refund.

## 12. Error mapping

| Fusion | SYNC code |
| --- | --- |
| Token endpoint `invalid_client`, `unauthorized_client`, HTTP 401 (after one new token), basic user refused | SYNC-AUTH-001 |
| HTTP 403 (missing job role or privilege) | SYNC-AUTH-004 |
| HTTP 429 | SYNC-RATE-001 (`Retry-After`, else 10 seconds) |
| Other HTTP 4xx except 401, 403, 404 with a text (`o:errorDetails[].detail`, else `detail`, else `title`) | SYNC-VAL-002 (field from `o:errorPath`, reason redacted, tags removed, cut at 200 characters) |
| HTTP 404 on a read of one record | none found; on a write the base mapping |
| HTTP 5xx | SYNC-NET-002 |
| Time-out, reset, name not resolved | SYNC-NET-001 |
| Certificate failure | SYNC-NET-003 |
| Unknown SKU | SYNC-MAP-001 |
| Reference too long, `prices_include_tax`, a setting missing, an Incomplete invoice, total or currency differs | SYNC-VAL-002 |
| Unexpected PHP error inside the connector | SYNC-VAL-002 "The connector stopped unexpectedly" (the base bridge) |

SYNC-AUTH-005, SYNC-AUTH-006 and SYNC-AUTH-007 are not produced: no Fusion answer for an expired secret, a missing licence or an unknown application was found. The client secret, password, token, pod host and identity domain host are removed from every message. The business unit is not a secret and is not removed, because the person needs to see it in a message such as "business unit X is not valid".

## 13. Not verified

- No live Fusion pod was available. Behaviour above the wire is tested against `FakeOracleFusionServer` (tests/php/Support), which encodes the assumptions below; it proves the connector's logic, not Oracle's behaviour.
- Several REST reference pages answered with the landing page, and the credit memo example pages could not be fetched (not on the tool's allowlist), so these are [inferred]: attribute names of `receivablesInvoiceLines` beyond `Description`, `LineNumber`, `Quantity`, `UnitSellingPrice` (including `TaxClassificationCode`, `MemoLine`), the resource attributes and the line array name `receivablesCreditMemoLines` of `receivablesCreditMemos` (they are constants in `OracleFusionRefunds`), the sign convention of credit memo lines, the attribute that holds the invoice total (`TotalAmount`, `InvoiceBalanceAmount`) and whether the answer to the create already shows it, whether `PurchaseOrder`, `CrossReference` and `TransactionDate` can be used in `q`, `FreightAmount` as a header attribute when tax and freight accounting are set up, the exact error body (`o:errorDetails` is assumed from the Oracle REST framework, not read), and the maximum lengths of `Comments` (1760 is on the page) and of a line `Description` (240 is assumed).
- The `q` quoting of framework version 2 (single quotes assumed, a quote inside doubled; one source shows double quotes), whether `like` is case sensitive, the `orderBy=Attribute:asc` form, and `onlyData=true`.
- The status text of an unfinished invoice (`Incomplete` is on the usage notes page; the comparison is case-insensitive).
- Whether `TransactionSource` accepts `Manual` through the API (the Oracle example uses it) or needs an imported source, and whether `TransactionNumber` must be supplied for a source without automatic numbering (the connector never sends it).
- Whether creation with the default `InvoiceStatus` fails when required setup is missing, and what error text.
- The maximum `limit` of a GET (499 is third-party) and the throttling thresholds.
- The pod host forms other than `*.oraclecloud.com`, government and vanity domains, and the identity domain URL for OCI IAM (`https://{identity-domain-url}/oauth2/v1/token`, third-party).
- JWT assertion instead of client secret: the application allows it but the page does not give the grant; not built.
- `terms_url` and `privacy_url` could not be fetched with the available tool (hosts not permitted).
- E-invoicing for Saudi Arabia and the UAE: not covered; the connector never claims compliance.

## 14. Manual smoke test against a real pod

No credentials are stored in the repository. Use a test pod.

1. Do the steps of section 2. Create a Receivables customer account and note its account number, the business unit, the transaction source and type, and the payment terms.
2. Add an Oracle Fusion connection with the addresses, the credentials and those values. Press Test connection. Expected: the invoice and item reads succeed; a user without roles gives SYNC-AUTH-004.
3. Place a WooCommerce order. Expected: one invoice whose PO number is `WC-{id}`; run the sync again and confirm that no second invoice appears.
4. Compare the total with WooCommerce; try a foreign currency and an unknown SKU.
5. Refund the order and check the on-account credit memo (the attribute names of credit memos are the least certain part).
6. Run the nightly check and confirm that the invoice and the credit memo are listed with their totals.
7. Remove the application, the Fusion user and the secret when done.
