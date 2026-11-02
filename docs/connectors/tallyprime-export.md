---
id: tallyprime-export
name: TallyPrime
catalog_row: TallyPrime
connection_method: export_file
available: true
verified: false
hosts: []
terms_url: https://tallysolutions.com/terms-of-use/
privacy_url: https://tallysolutions.com/privacy-policy/
developer_url: https://help.tallysolutions.com/?p=149474
variants: []
---

# TallyPrime export connector

Connector id `tallyprime-export`, type accounting, contract version 1. Code: `includes/Connectors/Tally/`. The connector extends `Export\AbstractExportConnector`: it never calls TallyPrime. It writes one XML file inside WordPress that the person imports in TallyPrime (Alt+O, Transactions). No host is contacted, no credential is stored, nothing leaves the site.

Format summary: TallyPrime XML import, sales vouchers (invoice view) with a party ledger, tax ledgers, shipping and fee ledgers, one inventory entry per product with an accounting allocation, an explicit round-off line, credit-note vouchers for refunds, optional receipt vouchers for paid orders. Dates `YYYYMMDD`. Encoding UTF-16 (little endian, with BOM) or UTF-8, chosen in the settings.

Tags used below: `[documented]` an official Tally page read with a fetch (URL and date in section 1), `[third-party]` a vendor-neutral or community page, `[inferred]` reasoned from a documented sample or from the structure of Tally's own export and not confirmed anywhere.

## 1. Sources

All fetched on 2026-11-02 unless marked.

| Page | URL | What it settled |
| --- | --- | --- |
| Understanding Integration: Transactions | https://help.tallysolutions.com/?p=149474 | Envelope, voucher structure, invoice mode sample, mandatory tags, response tags, prerequisites, "DESC not found", "Voucher totals do not match" |
| Scenario 2 (VB, receipts) | https://help.tallysolutions.com/scenario-2/ and https://help.tallysolutions.com/?p=177881 | Receipt voucher sample (two `ALLLEDGERENTRIES.LIST` blocks), `LASTVCHID`, `LINEERROR` |
| XML integration | https://help.tallysolutions.com/xml-integration/ | `TALLYREQUEST`, `SVCURRENTCOMPANY` inside `STATICVARIABLES`, `TALLYMESSAGE` placement |
| Integration with TallyPrime and prerequisites | https://help.tallysolutions.com/?p=149532 and https://help.tallysolutions.com/pre-requisites-for-integrations/ | Content types and encodings of the HTTP interface: UTF-8 (default), UTF-16 for special characters such as currency symbols, ASCII |
| Import data from XML | https://help.tallysolutions.com/how-to-import-data-from-xml-into-tallyprime-tallyhelp/ | Alt+O, Masters or Transactions, file path, backup question, XML in all releases, Excel from 4.0, JSON from 7.0 |
| Import vouchers (FAQ) | https://help.tallysolutions.com/?p=145423 and https://help.tallysolutions.com/docs/faqs/faq/faq-import-and-export.htm | "Overwrite voucher when a voucher with same GUID exists", duplicates, `REMOTEID` and GUID, F11 features, `Tally.imp`, import masters first |
| Privacy policy | https://tallysolutions.com/privacy-policy/ | Front matter `privacy_url` |
| Terms of Use | https://tallysolutions.com/terms-of-use/ | Front matter `terms_url`. The page itself is not on the fetch allow-list; the address is the "Terms of Use" link in the footer of the fetched pages https://tallysolutions.com/contact-us/ and https://tallysolutions.com/privacy-policy/ |

Facts, one per line:

- `[documented]` Request envelope: `ENVELOPE > HEADER (VERSION 1, TALLYREQUEST Import, TYPE Data, ID Vouchers) > BODY > DESC + DATA > TALLYMESSAGE > VOUCHER`.
- `[documented]` Invoice mode tags: `DATE`, `VOUCHERTYPENAME`, `VOUCHERNUMBER`, `PERSISTEDVIEW "Invoice Voucher View"`, `ISINVOICE Yes`, `OBJVIEW`. Party entry: `LEDGERNAME`, `ISDEEMEDPOSITIVE Yes`, `ISPARTYLEDGER Yes`, `ISLASTDEEMEDPOSITIVE Yes`, negative `AMOUNT`, a nested `BILLALLOCATIONS.LIST` (`NAME`, `BILLTYPE "New Ref"`, `AMOUNT`). Other ledgers: `ISDEEMEDPOSITIVE No`, positive `AMOUNT`. Tax ledger: `BASICRATEOFINVOICETAX.LIST TYPE="Number"` holding the rate, an empty `ROUNDTYPE`. Inventory: `STOCKITEMNAME`, `ISDEEMEDPOSITIVE No`, `RATE` as `15000.00/nos`, `AMOUNT`, `ACTUALQTY` and `BILLEDQTY` as `1 nos`, `BATCHALLOCATIONS.LIST` (`GODOWNNAME`, `BATCHNAME`, `DESTINATIONGODOWNNAME`, `AMOUNT`, quantities), `ACCOUNTINGALLOCATIONS.LIST` (sales `LEDGERNAME`, `ISDEEMEDPOSITIVE No`, `AMOUNT`).
- `[documented]` "All dates must follow the YYYYMMDD format."
- `[documented]` Prerequisites: required masters (ledgers, stock items, units) exist; non-base currencies exist; total debits equal total credits; dates `YYYYMMDD`.
- `[documented]` Errors: "DESC not found" (a referenced master is missing) and "Voucher totals do not match! Dr: ... Cr: ... Diff: ..." (the sample differs by 1.00). Statistics go to `Tally.imp` in the TallyPrime folder.
- `[documented]` The voucher can be created as a "voucher" (accounting view) or as an invoice; "Accounting Invoice" (an invoice without inventory) is described in the page's Note 2.
- `[documented]` Receipt sample: `VOUCHER VCHTYPE="Receipt" ACTION="Create"`, party `ALLLEDGERENTRIES.LIST` with a positive amount, bank or cash `ALLLEDGERENTRIES.LIST` with `ISDEEMEDPOSITIVE Yes` and a negated amount. The invoice sample calls the same list `LEDGERENTRIES.LIST`; the tag table says it is the same block.
- `[documented]` Import steps: Alt+O, Transactions, file path, answer Yes to the backup question, Enter. Masters first when the transactions depend on them. Resolve missing masters through the Exceptions report.
- `[documented]` Duplicates: with "Overwrite voucher when a voucher with same GUID exists" set to No, importing a voucher into the same company again creates a duplicate; with Yes the existing voucher is overwritten. Third-party XML must give each voucher a unique ID; the FAQ names the voucher ID `REMOTEID`. The FAQ also says to import such a file only once.
- `[documented]` Company features (F11) of the file's source and the importing company must match. The page does not say how a file from a third party is checked.
- `[documented]` The HTTP interface names UTF-8 (default) and UTF-16 (for special characters such as currency symbols) as content types. The pages say nothing about the encoding of a file chosen under Alt+O.
- `[third-party]` Tally's own XML exports are UTF-16 with a byte order mark. The default of this connector follows that (see section 12).
- `[inferred]` A credit note is the sales voucher with the signs reversed and `VCHTYPE="Credit Note"`. No official credit-note sample was found.
- `[inferred]` `REMOTEID` is an attribute of the `VOUCHER` element (as in Tally's own exports) and `GUID` a child element. The FAQ names both but shows no syntax.

## 2. How the merchant connects

1. Open the Exports screen of this connection and type the company name exactly as TallyPrime shows it.
2. Choose how customers appear: one shared ledger or one ledger per customer.
3. Create the ledgers named on the settings screen in TallyPrime. Press Download the list on Exports to see them all.
4. Press Create export, then Download. The file stays on your website; nothing is sent.
5. In TallyPrime press `Alt+O`, choose Transactions, type the file path, answer Yes to the backup question.
6. Import each file once. Read the Exceptions report if Tally lists errors.

Who is needed: the person who keeps the books in TallyPrime. No plan or licence gate: the file route works in every TallyPrime release. A TallyPrime with Auto-backup on asks the backup question on every import.

## 3. Authentication

None. The connection stores no credential. `credentialFields()` returns the settings of section 5 (all non-secret, group `connection` and `advanced`); nothing is encrypted because nothing is secret. `AuthStyle::ExportFile`.

## 4. Data sent and when (WordPress.org "External services")

No data is sent to Tally or to any other host. The file holds order numbers, dates, customer names, ledger names, product SKUs or names, quantities and amounts. It is written when a person presses Create export (or when the optional daily task runs), kept in the protected exports folder of the uploads directory (30 days by default), downloaded only by a signed-in person with the Manage sync capability, and listed in the WordPress personal-data export and erase tools by V0's export engine. `hosts` in the front matter is empty.

## 5. Settings

| Key | Type, default | Meaning |
| --- | --- | --- |
| `company` | text, required | Company name written to `SVCURRENTCOMPANY` |
| `currency` | ISO code, required | Currency of the books. Orders in another currency are left out |
| `encoding` | `utf-16`, `utf-8` (default `utf-16`) | See section 12 |
| `customer_ledger_mode` | `shared`, `per_customer` (default `shared`) | One ledger for all web customers, or the customer's own ledger |
| `shared_customer_ledger` | text, default `WooCommerce customers` | Party ledger in `shared` mode |
| `sales_ledger` | text, default `Sales` | Sales ledger of the accounting allocation |
| `shipping_ledger` | text, default `Shipping charged` | Shipping income ledger |
| `fee_ledger` | text, empty = sales ledger | Fees |
| `discount_ledger` | text, empty = no discount line | When set, product lines are written at the price before discount and the discount is posted here |
| `roundoff_ledger` | text, default `Round Off` | Receives a difference of up to `roundoff_max_minor` between the WooCommerce total and the sum of the lines |
| `roundoff_max_minor` | integer 0 to 100, default 5 | Larger differences leave the order out |
| `adjustment_ledger` | text | Refund amounts that belong to no product (fees, manual amounts) |
| `tax_mode` | `ledger`, `none` (default `ledger`) | `none` writes gross prices on the sales ledger and no tax ledger |
| `use_inventory` | yes/no, default yes | No: lines are written as accounting allocations only ("Accounting Invoice") |
| `stock_item_source` | `sku`, `name` (default `sku`) | Which text is the stock item name |
| `missing_sku` | `refuse`, `ledger_only` (default `refuse`) | A line without the stock item text |
| `unit` | text, default `nos` | Unit written after quantities |
| `godown`, `batch` | text, defaults `Main Location`, `Primary Batch` | Documented defaults when godowns and batches are off in Tally |
| `voucher_type_sales`, `voucher_type_credit_note`, `voucher_type_receipt` | text, defaults `Sales`, `Credit Note`, `Receipt` | Voucher type names |
| `voucher_prefix` | text, default empty | Added before the order number |
| `stable_id` | yes/no, default yes | Write `REMOTEID` and `GUID` derived from the client reference |
| `record_payments` | yes/no, default no | Add a receipt voucher for paid orders (needs `payment_ledgers`) |
| `payment_ledgers` | table: payment method id => ledger | Bank or gateway ledger per method |
| `fee_tax_code` | text | Tax ledger used for fee lines, which carry no rate in the canonical model |

The tax ledger names are not settings: they are the codes of the plugin's tax mapping (country plus rate gives the ledger name, for example `Output VAT 5%`).

## 6. Objects and field mapping

Mapping types: `orders` and `refunds` (there is no `customers`, `products`, `stock` or `tracking` mapping: the file writes names, it does not create masters).

Target field catalog, orders:

| Target path | Type | Required | Written to |
| --- | --- | --- | --- |
| `document.number` | string | yes | `VOUCHERNUMBER` (after `voucher_prefix`) and the bill `NAME` |
| `document.reference` | string | yes | `NARRATION` ("Order WC-123") and the stable ID |
| `document.date` | date | yes | `DATE` as `YYYYMMDD` |
| `document.currency` | currency | no | checked against `currency` |
| `document.total` | money | no | expected gross; the party amount; difference goes to the round-off ledger |
| `document.excluded_total` | money | no | subtracted from `document.total` |
| `document.note` | text | no | added to `NARRATION` |
| `customer.name` | string | yes | `LEDGERNAME` of the party (per-customer mode) |
| `customer.account` | string | no | overrides the party ledger name |
| `customer.id` | integer | no | not written by this format (DATEV uses it for the debtor account) |
| `line[].sku`, `line[].name` | sku, string | no | `STOCKITEMNAME` (source chosen by `stock_item_source`) |
| `line[].quantity` | number | yes | `ACTUALQTY`, `BILLEDQTY` |
| `line[].unit_price` | money | no | before-discount price (used with `discount_ledger`) |
| `line[].net` | money | yes | `AMOUNT`, `RATE` (= amount / quantity) |
| `line[].tax`, `line[].tax_rate`, `line[].tax_code` | money, number, tax_code | tax_code yes | tax ledger entry (name = code, `BASICRATEOFINVOICETAX` = rate) |
| `shipping[].name`, `.net`, `.tax`, `.tax_code`, `.tax_rate` | | | shipping ledger entry and its tax |
| `fee[].name`, `.net`, `.tax` | | | fee ledger entry; tax ledger from `fee_tax_code` |
| `payment.method`, `payment.title`, `payment.paid_at`, `payment.reference` | | | receipt voucher (when `record_payments`) |

Refund catalog: `document.number` (original order number), `document.reference`, `document.refund_id`, `document.date`, `document.note`, `document.total`, `document.excluded_total`, the customer fields, `refund_line[].sku|quantity|net|tax`, `refund_shipping.gross`, `refund_other.gross`, and from the original order `original_line[].sku|tax_code|tax_rate`, `original_shipping[].tax_code|tax_rate`.

Default mapping (version 1) takes `order.number`, `order.client_ref`, `order.created_at` (as a shop-time date), `order.total`, the billing name, `lines[].*`, `shipping_lines[].*`, `fees[].*`, `order.payment.*`. Tax codes come from the `tax_lookup` transform (country plus rate gives the ledger name).

The voucher (one per order):

```
VOUCHER REMOTEID=".." VCHTYPE="Sales" ACTION="Create" OBJVIEW="Invoice Voucher View"
  DATE  VOUCHERTYPENAME  VOUCHERNUMBER  PARTYLEDGERNAME  PERSISTEDVIEW  ISINVOICE  NARRATION  (GUID)
  LEDGERENTRIES.LIST  party      ISDEEMEDPOSITIVE Yes, ISPARTYLEDGER Yes, AMOUNT -gross, BILLALLOCATIONS.LIST
  LEDGERENTRIES.LIST  discount   (when discount_ledger is set)       ISDEEMEDPOSITIVE Yes, AMOUNT -discount
  LEDGERENTRIES.LIST  shipping   ISDEEMEDPOSITIVE No, AMOUNT net
  LEDGERENTRIES.LIST  fee        ISDEEMEDPOSITIVE No, AMOUNT net
  LEDGERENTRIES.LIST  tax        one per tax code, with BASICRATEOFINVOICETAX.LIST
  LEDGERENTRIES.LIST  round-off  only when the difference is not zero (sign follows the difference)
  ALLINVENTORYENTRIES.LIST  one per product line, with BATCHALLOCATIONS.LIST and ACCOUNTINGALLOCATIONS.LIST
```

Amounts are decimal strings with the currency's exponent (two digits for AED and SAR, three for KWD). The sum of all `AMOUNT` values of a voucher is exactly zero: the party amount is the order total and the other entries plus the round-off entry add up to it, in whole minor units. The unit tests parse the files with libxml and add the amounts up for sales, credit notes, receipts, discounts, fees and three-decimal currencies.

Credit note: same layout with `VCHTYPE="Credit Note"`, every sign reversed (party `ISDEEMEDPOSITIVE No` with a positive amount, income ledgers and stock items `Yes` with negative amounts), voucher number `{order number}-R{refund id}`, bill `NAME` the same, `BILLTYPE "New Ref"`. Refund shipping gross is split into net and tax with the rate of the original shipping tax code using integer basis-point arithmetic; refund amounts tied to no product (`refund_other.gross`) go to `adjustment_ledger` at no tax, or the refund is left out when no ledger is set.

Receipt (only with `record_payments` and an order with `payment.paid_at`): `VCHTYPE="Receipt"`, party `ALLLEDGERENTRIES.LIST` credit (positive amount), the payment ledger `ISDEEMEDPOSITIVE Yes` (negative amount), bill allocation `BILLTYPE "Agst Ref"` against the invoice bill name `[inferred]`.

## 7. Duplicate protection

- The client reference `WC-{order id}` is written to `NARRATION` and, with `stable_id`, into a `REMOTEID` and `GUID` derived from the connection id and the client reference (a fixed UUID-shaped hash). The same order always gets the same ID. If the person sets "Overwrite voucher when a voucher with same GUID exists" to Yes, a second import replaces the voucher instead of duplicating it `[documented]` for the setting, `[inferred]` for the syntax of the ID.
- An order waits in the open batch as one row file named after its queue item. The engine's base `send()` and `findExisting()` look for that file with one file check (`ExportStore::hasItem()`, whatever the size of the batch), so a retry never adds the same queue item twice (`SendResult::existing`). When the file is made, the engine marks the queue item Exported with the file name as its external reference; the warning before an order enters a second file belongs to that engine (V0), not to this connector.
- Voucher numbers are the order numbers; a duplicate voucher number is not refused by Tally `[documented]` (the page only advises unique numbers).
- Importing the same file twice can create vouchers twice. The Exports screen says so.

## 8. Taxes

Tax is written as ledger entries, one per tax code (the code is the ledger name from the tax mapping), with the rate in `BASICRATEOFINVOICETAX.LIST` `[documented]`. Product prices are the WooCommerce line totals before tax, so the model is tax-exclusive; the party amount is gross. The connector refuses nothing about "prices entered with tax": WooCommerce stores line totals without tax in both modes. `tax_mode = none` writes the gross amount on the sales ledger and no tax entry (the setup screen calls it "No tax in the file").

UAE and Saudi VAT: no UAE or Saudi voucher tag was found `[documented]: not found`. The file carries VAT only as ledger entries; Tally's own VAT features read the ledger's tax settings in Tally. The connector never claims tax-authority compliance (spec section 11, item 8).

GST India: not covered; the GST voucher tags (place of supply, GSTIN, tax details) are not written.

`listTaxCodes( $connection )` returns the tax ledger names the person has already mapped (empty before any mapping).

## 9. Payments

Off by default. With `record_payments` a paid order adds a receipt voucher whose bank or gateway ledger comes from `payment_ledgers[method id]`. A paid order whose method has no ledger is left out of the file with SYNC-EXP-002 naming the method (the Exports screen shows "Map Tabby"). An order that is not paid gets no receipt.

## 10. Refunds

Credit Note vouchers in the same file (see section 6). A refund whose original order is not in any file still exports (Tally does not link it); the person is warned in the file's masters list notes. Partial refunds work line by line; the refund total is checked against `document.total` minus `document.excluded_total`.

## 11. Stock, tracking and invoice read-back

None. TallyPrime does not answer, so the plugin cannot read anything back. The capability flags for stock, tracking, invoice read-back and reconciliation are false.

## 12. Encoding

`encoding = utf-16` writes UTF-16 little endian with a byte order mark and the declaration `<?xml version="1.0" encoding="UTF-16"?>`; `utf-8` writes UTF-8 with the declaration `encoding="UTF-8"` and no byte order mark. The company name and the encoding are read from the connection when the file is made (`$batch->connection()`), so a change made after an order was queued applies to the file; everything else (ledgers, units, tax rates) stays as the order saw it, because its voucher was written with it. The default follows Tally's own exports `[third-party]` and keeps Arabic ledger names intact. Characters that XML 1.0 forbids (control characters other than tab, line feed and carriage return) are removed from names; text is escaped with `&amp; &lt; &gt; &quot; &apos;`. The file is XML, which is not opened as a spreadsheet, so a text that starts with `=`, `+`, `-` or `@` is written as it is and the test reads it back unchanged with libxml; the masters list (plain text) puts every name on a line that starts with two spaces. Ledger and stock item names are trimmed and their inner whitespace collapsed (Tally trims them `[inferred]`).

## 13. Rate limits and pacing

Not applicable: no request is made. `rateLimitHints()` returns 1 request a second and concurrency 1, which only paces the queue. Orders go in one row at a time (the XML of one voucher is the row, a few kilobytes). When the file is made the batch is read from disk one row at a time (`ExportBatch::iterate()`), each voucher is encoded and written to a work file in the plugin's protected exports folder, and the finished file is handed over with `ExportFile::fromPath()`; neither the batch nor the file is ever in memory. The format says `rawBytes` because the bytes are already UTF-16 or UTF-8 with their own byte order mark. `stream()` yields the same pieces (the header, one piece for each voucher, the footer) for a caller that writes them itself. The test sends 10,000 orders, checks that the pieces and the finished file each add less than 1 MB of memory, and reads the file back from disk with a streaming parser.

## 14. Left-out reasons (SYNC-EXP-002) and error mapping

The connector decides when the order is added to the open batch (`send()`); a refusal puts a marker row with the reason into the batch, and the engine's Create export lists the order with SYNC-EXP-002 and its reason and marks the queue item as needing a person. The sentence is the user-facing text. Settings that are missing stop the whole export instead (SYNC-VAL-002 with the sentence), because every order would fail the same way.

| Situation | Reason text | Code |
| --- | --- | --- |
| Order currency differs from `currency` | "its currency (SAR) is not the currency of the books (AED)" | SYNC-EXP-002 |
| Company name or currency missing | the export stops; Test connection and the first send name the setting | SYNC-VAL-002 |
| Product line has no SKU or name for `stock_item_source` and `missing_sku = refuse` | "line 'Poster' has no SKU" | SYNC-EXP-002 |
| Line has tax and no tax code | "line 2 has tax but no tax code" (an unmapped rate stops the order earlier with SYNC-MAP-002 from `tax_lookup`) | SYNC-EXP-002 |
| Fee line has tax and `fee_tax_code` is empty | `fee "Gift wrap" has tax but no tax ledger` | SYNC-EXP-002 |
| Totals differ by more than `roundoff_max_minor` | "the lines add up to 105.00 but the order total is 110.00" | SYNC-EXP-002 |
| Paid order, `record_payments` on, `payment_ledgers` has no entry | "payment method Tabby has no ledger" | SYNC-EXP-002 |
| Refund amount that belongs to no product, no ledger set | "refund amount 5.00 has no adjustment ledger" | SYNC-EXP-002 |
| Customer name empty after cleaning | "the customer name is empty" | SYNC-EXP-002 |
| Total and lines both zero | "the total is zero, so there is nothing to post" | SYNC-EXP-002 |
| Order number or date missing | "the order number is missing", "the order date is missing" | SYNC-EXP-002 |
| Uploads folder not writable | SYNC-EXP-001 (from the export engine) | SYNC-EXP-001 |

## 15. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | true | sales vouchers |
| `refunds` | true | credit note vouchers |
| `customers` | false | no master data is written; ledgers must exist |
| `stock_in`, `tracking_in`, `prices_in`, `invoice_readback`, `reconciliation`, `webhooks`, `shadow_lookup` | false | Tally does not answer; no web interface |

Catalog capabilities: `order_document = voucher_file`, `refund_document = credit_note`, `reference_field = NARRATION and REMOTEID`, `native_idempotency = none` (an overwrite setting in Tally), `requires = TallyPrime, any release (XML)`.

## 16. Masters list and preview

`mastersList( $batch )` returns a plain text list (UTF-8): ledgers (party, sales, shipping, fee, discount, round-off, adjustment, tax, payment), stock items, units, godown and batch names, and the voucher types used, each name once and sorted. It lists only the names the waiting orders use, so the person can check they exist before the import, and it carries no amounts. The same text is the second file of every export: `finish()` returns it as an attachment named "Masters list" (`masters.txt`), which the Exports screen downloads with `GET /exports/{id}/download?part=1`. `previewText( $batch )` returns the first lines of the file (what it is, the company, the character set and the first voucher) and writes nothing. Both read the batch one row at a time. `filenamePattern()` names the pattern the engine uses for the download (`{connection}-{date}-{random}.xml`).

## 17. Not verified

- The encoding Tally expects in a file chosen under Alt+O. The HTTP interface names UTF-8 and UTF-16; the file path is not described. No real import was run.
- `REMOTEID` as an attribute and `GUID` as a child element, and whether Tally accepts a UUID-shaped value generated by a third party.
- The credit-note layout (sign reversal) and the receipt `Agst Ref` bill allocation against a bill that may not exist in Tally.
- Whether Tally recalculates `RATE x quantity` or trusts `AMOUNT` when they differ by a rounding unit (the connector writes `RATE = AMOUNT / quantity` with up to four decimals).
- Whether a voucher number that the voucher type numbers automatically is kept or replaced on import.
- Whether `SVCURRENTCOMPANY` in `DESC` is honoured for an Alt+O import (documented only for the HTTP interface).
- Foreign-currency vouchers, GST India tags, UAE and Saudi VAT tags, and stock items with batches or serial numbers.
- Release behaviour: the file was designed from the documented XML, which "works in all releases"; JSON (7.0 and later) is not written.

## 18. Manual smoke test (a TallyPrime with a sample company)

1. In a test company create the ledgers and two stock items named by the masters list; set the F11 features like the live company.
2. In WordPress add a connection for TallyPrime, enter the company name, publish the default mapping, map one tax rate to a ledger name.
3. Place a WooCommerce order and a partial refund, then press Create export.
4. In TallyPrime press `Alt+O`, Transactions, pick the file, answer Yes to the backup question.
5. Open the Day Book: the sales voucher has the party debit, one inventory line per product, the tax ledger and the same total as the order; the credit note mirrors it.
6. Import the file again: note whether a second voucher appears (the overwrite setting decides) and write the result in the release notes.
7. Repeat with a customer name in Arabic and the other encoding to see which one TallyPrime reads.
