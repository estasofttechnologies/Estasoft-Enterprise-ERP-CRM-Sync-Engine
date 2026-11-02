---
id: sage50-export
name: Sage 50
catalog_row: Sage Business Cloud Accounting and Sage 50
connection_method: export_file
available: true
verified: false
hosts: []
terms_url: ""
privacy_url: ""
developer_url: https://help-sage50.na.sage.com/en-us/2024/Content/Importing_Exporting/Import_Export_Fields/Import_Export_Fields_Sales_Journal.htm
variants: [{ id: sage50-export, label: "Sage 50 desktop program (export file)" }]
---

# Sage 50 export connector

Connector id `sage50-export`, type accounting, contract version 1. Code: `includes/Connectors/Sage50/`. This is the desktop-program half of the catalog row "Sage Business Cloud Accounting and Sage 50" (the cloud half is `sage-accounting`). The connector extends `Export\AbstractExportConnector`: it never calls Sage. It writes a CSV file inside WordPress that the person imports with Sage 50's own import. No host is contacted, no credential is stored.

Two editions are covered, chosen by the setting `edition`:

| `edition` | Sage product | Layout | Sourcing |
| --- | --- | --- | --- |
| `uk` | Sage 50 Accounts, UK and Ireland, version 12 and later (2006 and later) | Audit Trail Transactions, sales invoices `SI`, sales credits `SC`, receipts `SR` | `[third-party]` only |
| `us` | Sage 50, U.S. edition | Sales Journal import | `[documented]` field list, `[inferred]` row semantics |

Not covered: Sage 50 Canada (no import documentation was found for it), Sage 50 Accounts before version 12 (a different column set), the Invoicing and Sales Order modules of Sage 50 UK (the audit-trail import cannot create them `[third-party]`), and Sage 50cloud/Sage Accounting (a separate connector).

Tags: `[documented]` an official Sage page read by fetch, `[third-party]` a partner or community page, `[inferred]` reasoned and not confirmed.

## 1. Sources

All fetched on 2026-11-02.

| Page | URL | What it settled |
| --- | --- | --- |
| Sales Journal Fields (Import/Export), Sage 50 U.S. 2024 help | https://help-sage50.na.sage.com/en-us/2024/Content/Importing_Exporting/Import_Export_Fields/Import_Export_Fields_Sales_Journal.htm | `[documented]` The 74 fields of the Sales Journal with import flag, required flag, type, length and notes; date `##/##/##`; Boolean True/False; default file name `SALES.CSV` |
| Import/Export tips, Sage 50 U.S. 2020 help | https://help-sage50.na.sage.com/en-us/2020/Content/Importing_Exporting/General_Import_Export_Field_Tips.htm | `[documented]` Required fields as for manual entry; a Transaction Number whole and the same on every distribution line of a transaction; up to 160 distributions per transaction (154 for Accounts Receivable journals); avoid double quotes in text; leading zeros matter; blank numbers are zero, blank Booleans false; dates past the end of the second open fiscal year are refused |
| Sales journal import: duplicates (Sage community) | https://communityhub.sage.com/us/sage50_us/f/general-discussion/268009/sales-journal-import---duplicates-on-import/643838 | `[third-party]` A file without Invoice/CM # and Credit Memo columns imported the same records again; with an invoice number Sage refuses a second import of the same number; `Number of Distributions` must equal the number of rows of the invoice |
| Creating the Sage 50 import template (Avontus) | https://docs.avontus.com/creating-the-sage-50-import-template | `[third-party]` Template path File, Select Import/Export, Accounts Receivable, Sales Journal, Import; Fields tab shows chosen fields; Options tab has "First Row Contains Headings"; a working 26-field template |
| Audit Trail Transactions import layout (Making It Happen) | https://import.makingithappen.co.uk/csv_audittrans.htm | `[third-party]` The 13 UK columns, transaction type codes, date formats, the change in column K between version 11 and 12, field mapping from version 2011 (V17) |
| Audit Trail Transactions Grouped (Sage community) | https://communityhub.sage.com/gb/sage-50-accounts/f/general-discussion-uk/195651/sage-50---data-import---audit-transactions-grouped/490122 | `[third-party]` Grouping by transaction type, account reference, date and non-blank reference (and exchange rate in foreign currency) |
| Research note | `docs/research/integration-research-export-formats.md` section 11.2 and 11.3 | Menu path File, Import, "Audit Trail Transactions" for the UK; sample templates in `C:\Program Files (x86)\Sage\Accounts\Import Templates`; one user only while importing; back up first |

The UK field limits and the tax-code range come from the research note, which gives them as third-party. The shell is blocked by policy from the Sage and Making It Happen hosts; every fact above came through the fetch tool.

## 2. How the merchant connects

1. Choose your edition (UK and Ireland, or US) and enter the settings shown on this connection.
2. In Sage 50 create the accounts, nominal codes and tax codes listed by Download the list (the customer account must exist).
3. Press Create export, then Download. Nothing is sent anywhere.
4. Back up Sage 50 and make sure you are the only user. UK: `File`, `Import`, Audit Trail Transactions (Grouped).
5. US: set up the Sales Journal import template with the fields listed in the import guide, in that order, then import the file.
6. Import each file once and read the error report Sage shows.

No plan or licence gate: any Sage 50 with the import feature. The Exports screen shows the exact field order for the chosen edition.

## 3. Authentication

None. `AuthStyle::ExportFile`. The connection stores settings only.

## 4. Data sent and when (WordPress.org "External services")

No data is sent to Sage or to any other host. The file holds order numbers, dates, customer or account references, descriptions, nominal codes or G/L accounts and amounts. Same storage, download and erase rules as every export file. `hosts` is empty.

## 5. Settings

Common:

| Key | Type, default | Meaning |
| --- | --- | --- |
| `edition` | `uk`, `us` (default `uk`) | Layout |
| `currency` | ISO code, required (GBP, EUR or USD) | Orders in another currency are left out |
| `encoding` | `windows-1252`, `utf-8-bom` (default `windows-1252`) | Sage 50 is an ANSI program `[inferred]`; see section 13 |
| `header_row` | yes/no, default yes | Heading row with the field names |
| `record_payments` | yes/no, default no | UK only: add a receipt line (`SR`) for paid orders |
| `payment_accounts` | table: method id => bank nominal | UK only |
| `fee_tax_code` | text | Tax code for fee lines (fees carry no rate) |
| `untaxed_code` | text, UK default `T0` | Tax code for lines that have no tax and no tax code |
| `adjustment_account` | nominal (UK) or G/L account (US) | Refund amounts that belong to no product |

UK:

| Key | Default | Meaning |
| --- | --- | --- |
| `customer_account` | `WEB` | Account reference of the one customer that all web orders post to; it must exist. `customer.account` in the mapping overrides it per order |
| `sales_nominal` | `4000` | Nominal code for products |
| `shipping_nominal` | empty = sales nominal | Nominal code for shipping |
| `department` | empty | Department code (digits) |
| `user_name` | empty | Column User Name |
| `date_format` | `dd/mm/yyyy` | One of `dd/mm/yyyy`, `dd/mm/yy`, `ddmmyyyy`, `ddmmyy`; must match the computer's regional setting `[third-party]` |

US:

| Key | Default | Meaning |
| --- | --- | --- |
| `customer_id` | `WEB` | Customer ID that all orders use; it must exist. `customer.account` overrides it |
| `ar_account` | required | Accounts Receivable account |
| `sales_account` | required | G/L account for products |
| `freight_account` | empty = sales account | G/L account for shipping |
| `tax_gl_account` | required when there is tax | G/L account that receives tax lines (a tax code `AGENCY:GL` overrides it) |
| `item_tax_type` | `1` | Tax Type written on product lines (0 to 26) |
| `due_days` | `0` | Days from the order date to Date Due |
| `use_items` | no | Write the SKU in Item ID (the item must exist in Sage) |

Tax code format (the `system_code` of the tax mapping): UK `T1` or `T1:4001` (tax code T0 to T99, optional nominal for this rate). US `AGENCY` or `AGENCY:GLACCOUNT` (the sales tax agency ID, optional liability account).

## 6. File layouts

### 6.1 UK and Ireland: Audit Trail Transactions

Columns in order `[third-party]` (V12 and later):

| Col | Name | Written value |
| --- | --- | --- |
| A | Type | `SI` invoice, `SC` credit (refund), `SR` receipt (payment) |
| B | Account Reference | `customer_account` |
| C | Nominal A/C Code | `sales_nominal`, `shipping_nominal`, or the bank nominal for `SR` |
| D | Department Code | `department` or empty |
| E | Date | in `date_format` |
| F | Reference | order number; `{number}-R{refund id}` for a refund |
| G | Details | line description |
| H | Net Amount | decimal point, no thousands separator, two decimals, positive for `SI` and `SC` lines |
| I | Tax Code | `T0` to `T99` |
| J | Tax Amount | always written, also `0.00` |
| K | Exchange Rate | `1` |
| L | Extra Reference | client reference `WC-{id}` |
| M | User Name | `user_name` or empty |

Rows: one row for every product line, every shipping line and every fee line (so each row carries its own description, nominal, tax code and tax). All rows of an order share type, account reference, date and Reference, which is what the Grouped import merges into one transaction `[third-party]`. The plain import makes one header per row; both read the file. The Reference is never empty. The receipt row (`SR`, only with `record_payments`): Account Reference as above, nominal of the payment method's bank account, tax code `T9`, net the order total, tax `0.00` `[inferred]`.

Limits `[third-party]`/`[inferred]`: Net Amount up to 8 digits plus 2 decimals (99999999.99); tax code `T0` to `T99`; Account Reference at most 8 characters; Reference at most 30; Details at most 60 (longer text is cut to 57 characters plus `...` and a warning is logged); Extra Reference at most 30. A Reference or Account Reference over its limit leaves the order out (it is the key).

The header row (when `header_row`) uses the names of the table. Version 12 and later only: before version 12 column K is Extra Reference and there is no Exchange Rate `[third-party]`.

### 6.2 US: Sales Journal

The person sets the import template (File, Select Import/Export, Accounts Receivable, Sales Journal, Import) to show these fields in this order and checks "First Row Contains Headings" `[third-party]` for the option; the field names are `[documented]`:

| No | Field | Written value | Required `[documented]` |
| --- | --- | --- | --- |
| 1 | Customer ID | `customer_id` | yes |
| 2 | Invoice/CM # | order number; `{number}-R{refund id}` for a credit memo | no, but written always |
| 3 | Apply to Invoice Number | the original order number on credit memos, empty on invoices | no |
| 4 | Credit Memo | `FALSE`, or `TRUE` for a refund | no |
| 5 | Date | `MM/DD/YYYY` | yes |
| 6 | Date Due | date plus `due_days` | yes |
| 7 | Customer PO | client reference `WC-{id}` (at most 20 characters) | no |
| 8 | Accounts Receivable Account | `ar_account` | yes |
| 9 | Number of Distributions | number of rows of this transaction (1 to 147) | yes |
| 10 | Invoice/CM Distribution | line number 1..n for products, `0` for tax and freight lines | maybe |
| 11 | Quantity | quantity | no |
| 12 | Item ID | SKU when `use_items`, otherwise empty | no |
| 13 | Description | line text; `freight charge` for shipping | no |
| 14 | G/L Account | `sales_account`, `freight_account`, `tax_gl_account` | yes |
| 15 | Unit Price | unit price before discount, without tax, positive | no |
| 16 | Tax Type | `item_tax_type` on products, `0` on tax lines, `27` on freight | yes (0 to 27) |
| 17 | Amount | negative for an invoice line (credit), positive for a credit memo line `[documented]`: "Positive = debit, negative = credit" | yes |
| 18 | Sales Tax Agency | agency ID of the line's tax code | yes |
| 19 | Recur Number | `0` | yes in the field table |
| 20 | Recur Frequency | `0` | yes in the field table |

The header fields (1 to 9) repeat on every row of a transaction `[inferred]`; the Accounts Receivable amount is not written because it is calculated on import `[documented]`. Tax lines carry one row per agency with Tax Type `0`, Invoice/CM Distribution `0`, G/L `tax_gl_account` and Amount the negative tax. Freight lines have Tax Type `27`, Invoice/CM Distribution `0`. Text containing a double quote has it replaced by a single quote (Sage advises against them `[documented]`); fields holding a comma are quoted with doubled quotes (CSV). Dates beyond the end of the second open fiscal year are refused by Sage; the connector cannot know the year, so the guide warns.

Field `Transaction Number` is not written: the field table marks it export-only, although the tips page says transactions made outside Sage need one. Both statements are quoted in Not verified.

Limits: at most 147 distributions per transaction (the Sales Journal field table; the tips page says 154 for Accounts Receivable and 160 in general) so the stricter value is used; Customer ID at most 20 characters, Invoice/CM # 20, Description 160, G/L Account 15, Customer PO 20 `[documented]`.

## 7. Objects and field mapping

Mapping types `orders` and `refunds` with the same target paths as `tallyprime-export` (see section 6 there): `document.*`, `customer.*`, `line[].*`, `shipping[].*`, `fee[].*`, `payment.*`; refunds add `refund_line[]`, `refund_shipping`, `refund_other`, `original_line[]`, `original_shipping[]`. Sage-specific meaning:

| Target | UK column | US field |
| --- | --- | --- |
| `document.number` | Reference | Invoice/CM # |
| `document.reference` | Extra Reference | Customer PO |
| `document.date` | Date | Date, Date Due |
| `customer.account` | Account Reference | Customer ID |
| `line[].name` | Details | Description |
| `line[].sku` | not written | Item ID (`use_items`) |
| `line[].quantity`, `line[].unit_price` | not written (the UK layout has no quantity) | Quantity, Unit Price |
| `line[].net`, `.tax`, `.tax_code` | Net Amount, Tax Amount, Tax Code (nominal part of the code) | Amount, tax line, Sales Tax Agency |

## 8. Duplicate protection

- UK: Sage 50 UK has no duplicate check `[third-party]`. An order waits in the open batch as one row; the engine's base `send()` and `findExisting()` find it with one file check (`ExportStore::hasItem()`), so a retry never adds a queue item twice, and the engine marks the item Exported with the file name (the warning before an order enters a second file belongs to that engine). The client reference is in Extra Reference.
- US: Sage refuses an invoice number already used for the same customer ID `[third-party]`; the order number is the invoice number, so a second import of the same file fails line by line rather than duplicating. Importing without the Invoice/CM # column duplicates, which is why the column is always written.
- `findExisting()` answers for orders waiting in the open batch.

## 9. Taxes

Tax is always written explicitly: a tax code and a tax amount per row (UK), or tax distribution lines per agency (US). Line totals are tax-exclusive. The tax code comes from the plugin's tax mapping (country plus rate gives `T1` and so on). Sage's behaviour when the Tax Amount is blank (calculated or zero) is not settled, which is why the amount is never blank. Prices are not re-computed: the mapped net and the WooCommerce tax are written as they are.

## 10. Payments

UK only, off by default (`SR` rows, section 6.1). US: not supported; receipts belong to the Cash Receipts journal, which this connector does not write.

## 11. Refunds

UK: `SC` rows with positive amounts, Reference `{number}-R{refund id}`, the original order number in Details. US: a credit memo transaction (`Credit Memo` TRUE, amounts positive, `Apply to Invoice Number` the original). Refund shipping gross is split with the original shipping tax rate; amounts tied to no product go to `adjustment_account` (tax code `untaxed_code`), and without it the refund is left out and named.

## 12. Stock, tracking and invoice read-back

None. Sage 50 is a desktop program that does not answer, so the plugin cannot read anything back: no stock, no tracking, no invoice status, no check of what was imported. The flags for stock, tracking, invoice read-back and reconciliation are false.

## 13. Encoding

`windows-1252` writes the ANSI code page: German, Nordic, French and Spanish letters stay exact; characters it cannot hold (Arabic and others) become `?` and the Exports preview counts them. `utf-8-bom` writes UTF-8 with a byte order mark so Arabic stays intact; Sage 50's reading of it is not verified. Line ends are CR LF. The format says `rawBytes`: the bytes are already in the chosen character set and the engine adds nothing. The encoding and the heading-row setting are read from the connection when the file is made (`$batch->connection()`), so a change made after an order was queued applies to the file; the edition, accounts, codes and dates stay as the order saw them, because its rows were written with them. CSV quoting follows RFC 4180: a field with a comma, a quote or a line break is quoted and inner quotes doubled; line breaks inside names are replaced by a space.

## 14. Rate limits and pacing

Not applicable: no request is made. Orders go in one row at a time. When the file is made the batch is read from disk one row at a time (`ExportBatch::iterate()`) and written to a work file in the plugin's protected exports folder, then handed over with `ExportFile::fromPath()`; neither the batch nor the file is in memory, and `stream()` yields the same pieces for a caller that writes them itself. The test sends 10,000 orders, checks that the pieces and the finished file each add less than 1 MB of memory, and reads the file back from disk row by row. One third-party page mentions a limit of about 0.5 MB for the UK import; the connector does not split files for it (Not verified).

## 15. Left-out reasons (SYNC-EXP-002) and error mapping

| Situation | Reason text |
| --- | --- |
| Order currency differs from `currency` | "its currency (EUR) is not the currency of the books (GBP)" |
| Tax code is not valid (UK: not `T0` to `T99`; US: more than 20 characters or a bad G/L part) | `tax code "VAT" is not a Sage tax code (T0 to T99)`, `tax code "..." is not a sales tax agency ID, optionally with a G/L account` |
| Line, shipping or fee has tax and no tax code | "line 2 has tax but no tax code" |
| Net or tax over the Sage field limit (UK) | "an amount is larger than Sage 50 can import (8 digits before the decimals)" |
| Reference or account reference over its limit | "the order number is longer than 30 characters" (UK), "the order number is longer than 20 characters" (US), `account reference "X" is not 1 to 8 letters or digits` |
| US: more than 147 distributions | "the order has 151 lines, more than the 147 Sage 50 takes in one invoice" |
| US: a tax line has no G/L account | "sales tax agency STATE has no G/L account for its tax" |
| Required setting empty (UK: currency, account reference, sales nominal; US: currency, `ar_account`, `sales_account`, customer ID) | the export stops with the sentence (SYNC-VAL-002); Test connection shows it too |
| Totals differ by more than one minor unit | "the lines add up to 105.00 but the order total is 110.00" |
| Paid order, `record_payments` on, no bank nominal for the method (UK) | "payment method Tabby has no bank nominal" |
| Fee with tax and no `fee_tax_code` | "fee has tax but no tax code" |
| Uploads folder not writable | SYNC-EXP-001 |

A difference of one minor unit is added to the largest row (UK) or the largest tax line, else the largest line (US), so the transaction matches the order total.

## 16. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | true | `SI` rows and Sales Journal transactions |
| `refunds` | true | `SC` rows and credit memos |
| `customers` | false | no customer master is written; accounts must exist |
| all others | false | Sage 50 does not answer |

Catalog capabilities: `order_document = voucher_file`, `refund_document = credit_note`, `reference_field = Extra Reference (UK), Customer PO (US)`, `native_idempotency = none (US refuses a repeated invoice number)`, `requires = Sage 50 desktop with the import feature`.

## 17. Masters list and preview

`mastersList( $batch )` returns a plain text list of the account references (UK) or customer IDs (US), nominal codes or G/L accounts, tax codes or sales tax agencies, and item IDs the waiting orders use, each once and sorted, so the person checks they exist before the import. `previewText( $batch )` returns what the file is, the number of rows, how many characters Windows-1252 cannot hold, the heading row and the first rows; for the US edition it prints the field order for the import template. It writes nothing. The masters list is also the second file of every export: `finish()` returns it as an attachment named "Masters list" (`masters.txt`), which the Exports screen downloads with `GET /exports/{id}/download?part=1`. Both read the batch one row at a time.

## 18. Not verified

- Every UK fact is third-party. No official Sage 50 UK import layout page was found; no real import was run. Column count of the audit-trail layout, tax code range, field lengths, the behaviour of a blank Tax Amount, the heading row being skipped by the import, and the 0.5 MB limit are unconfirmed.
- US row semantics: that the header fields repeat on every distribution row, that the Accounts Receivable debit is implicit, that tax lines are accepted as distributions with Tax Type 0, the sign of amounts, `MM/DD/YYYY` (the official table shows `##/##/##`), and whether `Recur Number`, `Recur Frequency` and `Voided by Transaction` are really required (the field table says yes, a community answer says remove them).
- US Transaction Number: the field table says export-only, the tips page says outside transactions need one. If an import asks for it, the Invoice/CM # groups the rows instead.
- Sage 50 Canada and Sage 50 Accounts before version 12 are not covered.
- UTF-8 with a byte order mark in either edition.
- Ireland: the same layout is assumed for Sage 50 Accounts Ireland; no Ireland-specific page was read.

## 19. Manual smoke test

UK (Sage 50 Accounts with a sample company):
1. Create account reference `WEB`, the sales nominal and tax codes `T0` and `T1`.
2. Make one order with two tax rates and shipping, one partial refund, press Create export.
3. In Sage choose `File`, `Import`, Audit Trail Transactions Grouped; map the columns to the table in 6.1 if asked.
4. Check the audit trail: one `SI` per order with two tax lines, and the `SC`.
5. Import the file a second time and note whether duplicates appear.

US (Sage 50 U.S. sample company):
1. Create customer `WEB`, the accounts and the sales tax agency.
2. Create the Sales Journal import template with the fields of 6.2 in order, heading row checked.
3. Import, then open the Sales Journal: check lines, tax and totals against the order.
4. Import again and confirm Sage refuses the repeated invoice numbers.
