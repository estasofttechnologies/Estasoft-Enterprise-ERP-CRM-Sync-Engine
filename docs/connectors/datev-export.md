---
id: datev-export
name: DATEV
catalog_row: DATEV
connection_method: export_file
available: true
verified: false
hosts: []
terms_url: ""
privacy_url: ""
developer_url: https://www.datev.de/dnlexom/v2/content/documents/1003221/pdf
variants: []
---

# DATEV export connector

Connector id `datev-export`, type accounting, contract version 1. Code: `includes/Connectors/Datev/`. The connector extends `Export\AbstractExportConnector`: it never calls DATEV. It writes a DATEV-Format file (`EXTF`, format category 21 "Buchungsstapel", header version 700, format version 13, 125 columns) that the tax adviser or the merchant imports in DATEV Rechnungswesen. No host is contacted, no credential is stored.

Tags: `[documented]` an official DATEV page read by fetch (section 1), `[third-party]` a vendor or plugin page that states it, `[inferred]` reasoned from the structure of the format or from third-party examples and not confirmed by an official page.

## 1. Sources

All fetched on 2026-11-02.

| Page | URL | What it settled |
| --- | --- | --- |
| DATEV document 1003221, ASCII-Import Buchungsstapel | https://www.datev.de/dnlexom/v2/content/documents/1003221/pdf | General settings (character set "ANSI", variable record, semicolon, no thousands separator, decimal comma, `TTMM` dates, heading row, quotes around text with doubling, no trailing delimiter) and the complete list of 125 fields with type, length and rules |
| DATEV document 9211450, importing a Buchungsstapel | https://www.datev.de/dnlexom/v2/content/documents/9211450/pdf | Import path (Bestand, Importieren, Stapelverarbeitung), "DATEV-Format ändern" for Berater, Mandant, Wirtschaftsjahr, the ASCII fallback with "Import ab Zeile" 2, the warning that saving in Excel breaks the header |
| DATEV Format (cisbox) | https://www.datev.de/content/dam/markenassets/marktplatz/schnittstellen-funktionsumfang/DATEV-Format_cisbox.pdf | Which fields an interface must fill (movement data: Umsatz, Konto, Belegdatum) and which are optional |
| CatCode Accounting Export with DATEV for WooCommerce | https://wordpress.org/plugins/?p=378964 | `[third-party]` EXTF, category 21, format version 13, header version 700, 125 columns, Windows-1252 or UTF-8 with BOM, CR LF, suggested accounts 8400/8300 and 4400/4300, debtor suggestion 10000 |
| Perk DATEV integration | https://support.perk.com/hc/en-us/articles/22004122556572-DATEV-integration-for-expenses | `[third-party]` header table with Flag EXTF, version number 700, category 21, name Buchungsstapel, format version 12 (so version 12 is also in use), SKR03, SKR04, SKR07 settings |
| Pickware DATEV export notes | https://help.pickware.com/en/articles/659945-further-information-on-the-datev-document-export | `[third-party]` Belegfeld 1 = order number, Buchungstext, Festschreibung 0, Beleglink for document images, Auftragsnummer 30 characters |

DATEV's developer portal (developer.datev.de) is a single-page application whose text the fetch tool could not read, and the shell is blocked by policy from datev.de. The header record field list in section 6 therefore rests on third-party examples and the structure of the format `[inferred]`.

Facts:

- `[documented]` Settings of the file: character set ANSI, fields separated by semicolons, text in double quotes with embedded quotes doubled, no thousands separator, comma before decimals, `Belegdatum` as `TTMM`, other dates `TTMMJJJJ`, a heading row, no delimiter at the end of the record.
- `[documented]` 125 record fields, numbered 1 to 125 (fields 118 to 125 are the newest, introduced after program version 12.2): Umsatz (ohne Soll/Haben-Kz) 10,2 always positive; Soll/Haben-Kennzeichen `S` or `H`; WKZ Umsatz (ISO code, empty means the base currency); Konto up to 9 digits; Gegenkonto (ohne BU-Schlüssel); BU-Schlüssel 4 characters; Belegdatum `TTMM`; Belegfeld 1 up to 36 (digits, letters and `$ & % * + - /`); Belegfeld 2 up to 12; Buchungstext up to 60; Festschreibung (1 locks the batch after import, 0 does not).
- `[documented]` Belegfeld 1 is the open-item key (OP-Schlüssel) and a must field.
- `[documented]` Fields 20 (Beleglink) and 21 to 36 (Beleginfo, eight Art/Inhalt pairs) and 48 to 87 (Zusatzinformation, twenty pairs): Art up to 20 characters, Inhalt up to 210, always filled in pairs.
- `[documented]` Import path and warning: see the table above. "Keine Daten vorhanden" can mean the Berater, Mandant or Wirtschaftsjahr in the header is not accepted for the client.
- `[third-party]` Header: `"EXTF";700;21;"Buchungsstapel";13;` then the creation time as `YYYYMMDDHHMMSSFFF`. Files are written in Windows-1252 or UTF-8 with a byte order mark, lines end in CR LF.
- `[third-party]` Automatic revenue accounts (SKR03 8400 for 19 percent and 8300 for 7 percent, SKR04 4400 and 4300) calculate the tax themselves, so the BU-Schlüssel stays empty; other revenue accounts need the key the tax adviser names. A CatCode page suggests exactly these pairs but does not say which pair belongs to which chart; the pairing is `[inferred]` from the standard charts.
- `[inferred]` Personal accounts have one more digit than the Sachkontenlänge. With four digits, debtors are 10000 to 69999 and creditors 70000 to 99999. Merchants can change ranges in DATEV, so the connector checks only length and digits and reports a range problem as a note, not as a refusal.
- `[inferred]` BU-Schlüssel 2 is 7 percent output tax, 3 is 19 percent output tax, 8 and 9 are the matching input tax keys. DATEV document 0907054 holds the list; it could not be read, so the connector contains no BU key default. The key comes from the tax mapping.

## 2. How the merchant connects

1. Ask your tax adviser for the consultant number and the client number of your company in DATEV.
2. Enter both numbers, the first day of your financial year, the account length and the chart (SKR03 or SKR04).
3. Enter the debtor account and, in the tax mapping, one revenue account for each tax rate, for example `8400`.
4. Press Create export, then Download. Never open or save the file in Excel: it breaks the first line.
5. Give the file to your tax adviser, or import it in DATEV: `Bestand`, `Importieren`, `Stapelverarbeitung`.
6. If DATEV shows no data, check the consultant and client numbers in `DATEV-Format ändern`.

Who is needed: a tax adviser or DATEV user with access to the client. The DATEV online interface (Rechnungsdatenservice) is not used (section 15).

## 3. Authentication

None. The connection stores no credential. The consultant and client numbers are not secrets. `AuthStyle::ExportFile`.

## 4. Data sent and when (WordPress.org "External services")

No data is sent to DATEV or to any other host. The file holds order numbers, dates, customer names (in the Buchungstext, unless switched off), account numbers and amounts. Same storage, download and erase rules as every export file (V0's export engine). `hosts` is empty.

## 5. Settings

| Key | Type, default | Meaning |
| --- | --- | --- |
| `consultant_number` | integer 1001 to 9999999, required | Header field Berater |
| `client_number` | integer 1 to 99999, required | Header field Mandant |
| `fiscal_year_start` | `MM-DD`, default `01-01` | Month and day the financial year starts. The year follows from the order dates |
| `account_length` | integer 4 to 8, default 4 | Sachkontenlänge. Personal accounts have this plus one |
| `chart` | `skr03`, `skr04` (default `skr03`) | Header field SKR (`03` or `04`) and the account suggestions |
| `currency` | ISO code, default `EUR` | Orders in another currency are left out (no foreign-currency postings) |
| `debtor_mode` | `collective`, `customer_id` (default `collective`) | One collective debtor, or an own account per registered customer |
| `debtor_account` | digits, default `10000` | Collective debtor account; with `customer_id` the base of the range (account = base + WooCommerce customer id; guests use `collective_account`) |
| `collective_account` | digits, default = `debtor_account` | Debtor used for guest orders in `customer_id` mode |
| `shipping_account` | digits, empty = revenue account of the shipping tax code | Revenue account for shipping |
| `encoding` | `windows-1252`, `utf-8-bom` (default `windows-1252`) | See section 13 |
| `booking_text` | `number_name`, `number` (default `number_name`) | Buchungstext with or without the customer name |
| `origin` | 2 letters, default `RE` | Header field Herkunft |
| `exported_by` | text up to 25, default `Estasoft Sync` | Header field Exportiert von |
| `dictation_code` | up to 2 letters, empty | Header field Diktatkürzel |
| `lock_postings` | yes/no, default no | Festschreibung 1. Off: the adviser locks the batch |
| `record_payments` | yes/no, default no | Add a payment posting for paid orders (needs `payment_accounts`) |
| `payment_accounts` | table: payment method id => account | Bank or clearing account per method |
| `adjustment_account` | digits | Revenue account for refund amounts that belong to no product |
| `untaxed_code` | `account` or `account:BU key` | Account for lines that have no tax and no tax code |
| `fee_tax_code` | text | Tax code for fee lines (`account` or `account:BU key`); fees carry no rate |

Tax code format (the `system_code` column of the tax mapping): `account` or `account:BU key`, for example `8400` (automatic account, no key) or `8200:3`. The account must be digits of the Sachkontenlänge; the key is 1 to 4 digits.

## 6. File layout

Row 1, the header record, 31 fields separated by semicolons `[inferred]` for the names after field 17:

| No | Name | Written value |
| --- | --- | --- |
| 1 | Kennzeichen | `"EXTF"` |
| 2 | Versionsnummer | `700` |
| 3 | Datenkategorie | `21` |
| 4 | Formatname | `"Buchungsstapel"` |
| 5 | Formatversion | `13` |
| 6 | Erzeugt am | `YYYYMMDDHHMMSSFFF` (UTC clock, milliseconds `000`) |
| 7 | Importiert | empty |
| 8 | Herkunft | `"RE"` (setting `origin`) |
| 9 | Exportiert von | `"Estasoft Sync"` |
| 10 | Importiert von | empty |
| 11 | Berater | `consultant_number` |
| 12 | Mandant | `client_number` |
| 13 | WJ-Beginn | `YYYYMMDD` of the financial year of the file |
| 14 | Sachkontenlänge | `account_length` |
| 15 | Datum von | `YYYYMMDD`, first Belegdatum of the file |
| 16 | Datum bis | `YYYYMMDD`, last Belegdatum of the file |
| 17 | Bezeichnung | `"WooCommerce"` plus the month, at most 30 characters |
| 18 | Diktatkürzel | `dictation_code` or empty |
| 19 | Buchungstyp | `1` |
| 20 | Rechnungslegungszweck | `0` |
| 21 | Festschreibung | `0` or `1` |
| 22 | WKZ | `"EUR"` |
| 23 | reserviert | empty |
| 24 | Derivatskennzeichen | empty |
| 25, 26 | reserviert | empty |
| 27 | SKR | `"03"` or `"04"` |
| 28 | Branchen-Lösungs-ID | empty |
| 29, 30 | reserviert | empty |
| 31 | Anwendungsinformation | `"Estasoft Sync"` |

Row 2 holds the 125 column names as DATEV's published example files spell them `[inferred]` (DATEV imports by position; the names of the fields we fill are the ones of document 1003221 and a test compares them). Rows 3 and later hold one posting each, 125 fields, text in quotes, numbers, accounts and dates bare, empty fields empty, CR LF line ends, no delimiter after the last field. The positions, names and kinds are in `DatevColumns` in the code.

Fields the connector fills:

| Field | Name | Value |
| --- | --- | --- |
| 1 | Umsatz (ohne Soll/Haben-Kz) | gross amount of the posting, positive, decimal comma, no thousands separator, two decimals (exponent of the currency) |
| 2 | Soll/Haben-Kennzeichen | `S` for a sale, `H` for a refund; reversed for a negative amount |
| 7 | Konto | debtor account |
| 8 | Gegenkonto (ohne BU-Schlüssel) | revenue account |
| 9 | BU-Schlüssel | key from the tax code, empty for an automatic account |
| 10 | Belegdatum | `TTMM` |
| 11 | Belegfeld 1 | order number reduced to digits, letters and `$ & % * + - /`; `{number}-R{refund id}` for a refund. Over 36 characters: the order is left out |
| 14 | Buchungstext | "WC {number} {name}" cut to 60 characters with `...` |
| 48, 49 | Zusatzinformation Art 1, Inhalt 1 | `"WooCommerce"`, client reference `WC-{id}` |
| 114 | Festschreibung | `0` or `1` |

The heading names and kinds of all 125 columns are fixed in the code; a test asserts the count (125), the names of the fields above against the official table, and that every other field of a posting is empty.

All other fields are empty.

Postings of an order: lines are grouped by (revenue account, BU key); one posting per group with the gross amount of its lines (net plus tax); shipping and fees are grouped with the same rule. This is the split of tax per rate: DATEV calculates the tax from the gross amount on automatic accounts, or from the BU key on other accounts. A payment posting (when `record_payments`) is `Konto` bank account, `Gegenkonto` debtor, `S`, the order total, the same Belegfeld 1.

A difference of up to two minor units between the sum of the postings and the order total is added to the largest posting, so the debtor receives exactly the order total; larger differences leave the order out.

## 7. Objects and field mapping

Mapping types `orders` and `refunds` (same target paths as the other ledger-based exports: `document.*`, `customer.*`, `line[].*`, `shipping[].*`, `fee[].*`, `payment.*`; refunds add `refund_line[]`, `refund_shipping`, `refund_other`, `original_line[]`, `original_shipping[]`). The mapping is described once in `docs/connectors/tallyprime-export.md` section 6; differences for DATEV:

| Target | Written to |
| --- | --- |
| `document.number` | Belegfeld 1, Buchungstext |
| `document.reference` | Zusatzinformation Inhalt 1 |
| `document.date` | Belegdatum (`TTMM`) and the header dates |
| `document.total`, `document.excluded_total` | expected gross for the debtor |
| `customer.id` | debtor account in `customer_id` mode |
| `customer.name` | Buchungstext |
| `line[].tax_code`, `shipping[].tax_code` | revenue account and BU key |
| `customer.account` | not used (the debtor comes from the settings) |

`line[].sku`, `.name`, `.quantity` and `.unit_price` are read but not written: a DATEV booking carries no products.

## 8. Duplicate protection

DATEV has no duplicate rule `[documented]` (the adviser may append to a batch or make a separate one). An order waits in the open batch as one row; the engine's base `send()` and `findExisting()` find it with one file check (`ExportStore::hasItem()`), so a retry never adds a queue item twice. After the file is made the engine marks the item Exported with the file name (the warning before an order enters a second file belongs to that engine). The client reference `WC-{id}` is written to Zusatzinformation, so an adviser can search for it. Belegfeld 1 repeats for a payment posting of the same order on purpose (it clears the open item).

## 9. Taxes

Tax per line is expressed by the revenue account and optional BU key of the line's tax code. Rates are not written. WooCommerce line totals are tax-exclusive; the posting amount is net plus tax (gross) because DATEV's automatic accounts split it. An account that is not automatic needs the key of the tax adviser; the connector does not guess keys. Reverse charge, intra-EU and third-country sales need keys, accounts and EU fields (40, 41, 123, 124) that the connector does not fill; these orders have to carry a tax code whose account is a tax-free revenue account, and the adviser reviews them.

## 10. Payments

Off by default. With `record_payments`, paid orders add a posting from the payment method's account to the debtor. A paid order whose method has no account is left out with the method named.

## 11. Refunds

A refund repeats the postings of the refund amounts with `H` (and the same accounts), dated on the refund, Belegfeld 1 `{number}-R{refund id}`. A refunded line takes the revenue account of the same SKU on the original order (or the one account every original line shares); refund shipping gross takes the shipping tax code of the original order (and `shipping_account` when set). Refund amounts that belong to no product go to `adjustment_account`; without it the refund is left out and named.

## 12. Stock, tracking and invoice read-back

None. DATEV does not answer, so the plugin cannot read anything back: no stock, no tracking, no invoice status, no check of what the adviser imported. The flags for stock, tracking, invoice read-back and reconciliation are false.

## 13. Encoding

`windows-1252` (default): the official document says "ANSI". Characters that Windows-1252 cannot hold (Arabic, Polish ł, and so on) become `?`; the preview counts them. German umlauts, ß, Nordic æ ø å and euro sign are written exactly. `utf-8-bom`: UTF-8 with a byte order mark; Arabic and every script stay intact. `[third-party]` says current DATEV releases read UTF-8 with BOM; this is not verified. Line ends are CR LF. The format says `rawBytes`: the bytes are already in the chosen character set and the engine adds nothing. The consultant number, client number, origin, exported-by name, dictation code and the encoding are read from the connection when the file is made (`$batch->connection()`), so a change made after an order was queued applies to the header of the file; the account length, the accounts, the chart and the currency stay as the order saw them, because its records were written with them.

Spreadsheet formulas: free text (the Buchungstext, the Zusatzinformation and the text fields of the header) is written through the engine's `CsvWriter` in neutralising mode `space`. A text that starts with `=`, `+`, `-` or `@`, a tab or a carriage return, and is not a plain number, gets one space in front, so Excel shows it as text and does not run it (a customer name such as `=HYPERLINK(...)`). The space counts toward the field length: the text is shortened after it is added, and a longer text ends with three dots inside the limit. Amounts, dates, accounts, BU keys and the other number columns are written as they are. Belegfeld 1 keeps the characters DATEV allows and cannot start with a plus sign or a hyphen (those are removed, because a sign in front means nothing in a document number); the Buchungstext always starts with `WC `. The booking text and the header name cannot hold a line break (it becomes a space), and a NUL byte is dropped. `[inferred]` DATEV reads a leading space inside quotes as padding; this is not verified (see section 19).

Text fields: quotes doubled, semicolons and line breaks inside names are replaced by a space (a name cannot break the record).

## 14. Rate limits and pacing

Not applicable: no request is made. Each order's records are the order's row. When the file is made the batch is read from disk one row at a time (`ExportBatch::iterate()`) and written to a work file in the plugin's protected exports folder, then handed over with `ExportFile::fromPath()`; neither the batch nor the file is in memory. The header needs the financial year and the date range, so a first pass over the rows leaves out the orders of another financial year and finds the first and last booking date; the header is written; a second pass writes the records. The test sends 10,000 orders, checks that the pieces and the finished file each add less than 1 MB of memory, and reads every posting back from disk line by line.

## 15. Not implemented: online variants

The DATEV Rechnungsdatenservice (invoice data to Unternehmen online) and DATEV Belegbilder (document images with Beleglink) are not built. The Rechnungsdatenservice needs partner onboarding and access rights granted per integrator, and its XML schema was not found `[documented]` in the research (`docs/research/integration-research-export-formats.md`, 11.4). Belegbilder need the PDF of each invoice, which the plugin does not produce; the Beleglink field (20) stays empty.

## 16. Left-out reasons (SYNC-EXP-002) and error mapping

| Situation | Reason text |
| --- | --- |
| Consultant number, client number, financial year start, currency or debtor account missing or invalid | the export stops with the sentence (SYNC-VAL-002); Test connection shows it too |
| Order currency differs from `currency` | "its currency (CHF) is not the currency of the books (EUR)" |
| Belegdatum in another financial year than the file (the first order decides) | "dated 2027-01-03, a different financial year from this file (which starts 2026-01-01); export it again after this file" (left out when the file is made, so the person presses Retry for it after this file) |
| Belegfeld 1 longer than 36 characters after cleaning, or nothing usable left | "the order number is longer than 36 characters", "the order number has no characters DATEV accepts in Belegfeld 1" |
| Tax code is not `account` or `account:key` with an account of at most the account length | `tax code "VAT19" is not an account number` |
| Line or shipping has tax and no tax code | "line 2 has tax but no tax code", "shipping has tax but no tax code" |
| No tax and no tax code, and no `untaxed_code` | "line 1 has no tax code and no account for untaxed sales is set" |
| `customer_id` mode and the customer number leaves the range 10000 to 69999 (for four digits) | "customer 60000 does not fit the debtor range" |
| Collective debtor account does not have one digit more than the account length | "debtor account 1000 is not a number with 5 digits" |
| Postings differ from the order total by more than two minor units | "the postings add up to 105.00 but the order total is 110.00" (up to two units are added to the largest posting) |
| Paid order, `record_payments` on, no account for the method; or paid in another financial year | "payment method Tabby has no account", "it was paid in another financial year than the order" |
| Refund line with tax whose code cannot be told from the original order | "refund line 1 has tax but its tax code cannot be told from the original order" |
| Refund amount that belongs to no product and no `adjustment_account` | "refund amount 5.00 has no adjustment account" |
| Uploads folder not writable | SYNC-EXP-001 |

## 17. Capabilities

| Flag | Value | Reason |
| --- | --- | --- |
| `orders` | true | sales postings |
| `refunds` | true | `H` postings |
| `customers` | false | DATEV master data (Debitoren stammdaten) is not written |
| every other flag | false | DATEV does not answer |

Catalog capabilities: `order_document = voucher_file`, `refund_document = credit_note`, `reference_field = Zusatzinformation and Belegfeld 1`, `native_idempotency = none`, `requires = DATEV Rechnungswesen, Buchungsstapel import`.

## 18. Masters list and preview

`mastersList( $batch )` returns a plain text list of the debtor accounts, revenue accounts, BU keys and bank accounts the waiting orders use, each once and sorted, so the adviser can check them against the client's chart of accounts. `previewText( $batch )` returns what the file is (orders, postings, character set), the consultant and client numbers, the first day of the financial year, how many characters Windows-1252 cannot hold, and the first records. The masters list is also the second file of every export: `finish()` returns it as an attachment named "Masters list" (`masters.txt`), which the Exports screen downloads with `GET /exports/{id}/download?part=1`. `previewText()` writes nothing; both read the batch one row at a time and count only the orders of the file's financial year. `stream()` yields the same pieces as the file for a caller that writes them itself.

## 19. Not verified

- That DATEV Rechnungswesen trims the one space that the connector puts in front of a free text that starts like a formula (`=`, `+`, `-`, `@`, tab, carriage return). If it does not, the text shows with a space in front; the alternative is to remove the characters, which loses the text.
- The header record names after field 17 and their values (Rechnungslegungszweck 0, SKR in field 27, empty reserved fields) are `[inferred]` from third-party files. A header with a wrong count of fields is the likeliest cause of "file not recognised".
- Format version 13 against the DATEV release in use: version 12 files are accepted by current releases per third-party pages; whether a version 13 file with 125 columns is read by older releases is not known.
- UTF-8 with a byte order mark.
- Whether DATEV rejects a non-DATEV `Herkunft`.
- BU keys and the accounts suggested for SKR03 and SKR04.
- Whether a posting with `S` on the debtor and a negative amount flipped to `H` is handled the same as a credit posting.
- A limit on the number of postings in one batch. Intacct documents 99,999 for its own export; for DATEV Rechnungswesen none was found, and the connector does not split a file.
- A real import: none was done. The DATEV column count rests on the official document 1003221.

## 20. Manual smoke test (DATEV Rechnungswesen or a test client)

1. Ask the adviser for a test client with the same account length and chart.
2. Enter the numbers, make two orders of different rates, one with a refund, press Create export.
3. Import the file with `Bestand`, `Importieren`, `Stapelverarbeitung`; if DATEV shows no data, correct the numbers in `DATEV-Format ändern`.
4. Check the postings: debtor, revenue account, tax amount, Belegfeld 1.
5. Try the ASCII import with "Import ab Zeile" set to 2.
6. Repeat with `utf-8-bom` and an Arabic customer name.
