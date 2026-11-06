# Vendor logos

The Choose a system screen shows the vendor's own logo next to the name of 23 of the 34 systems. This page says what
ships, where it came from, what has **not** been checked, and how to take the logos out.

## What ships

- 22 files in `assets/logos/`, each a **160 by 160 px PNG** named after the connector id (`xero.png`). They are in the
  release zip (about 200 KB in all).
- The catalog answer (`GET /catalog`) carries a `logo` field for every system: the address of the file, or an empty
  string. The screen draws the logo at 36 px in a card and 44 px in the list. It is decorative (empty `alt`) because
  the name is written next to it.
- A system with no file, a file that cannot be loaded, or the filter `estasoft_sync_show_vendor_logos` returning
  false shows a letter tile instead. Nothing else in the plugin depends on a logo.
- The other screens (connections, dashboard, queue) show names only.

## Where the files came from

The owner supplied the vendors' own artwork as SVG, PNG, JPEG and WebP files of different sizes. Each was cut to its
content, centred on a white square and scaled to fit (full-colour squares such as QuickBooks and Exact fill the
square; a wide logo on a solid colour, Odoo, keeps its colour round the mark). The artwork itself is not redrawn or
recoloured. The 160 px size keeps it sharp at twice the display size.

| Connector id | Shown for | Trademark owner (to confirm) | Permission |
| --- | --- | --- | --- |
| `quickbooks` | QuickBooks Online | Intuit Inc. | Not asked |
| `xero` | Xero | Xero Limited | Not asked |
| `zoho-books` | Zoho Books | Zoho Corporation | Not asked |
| `myob` | MYOB | MYOB Group | Not asked |
| `sage-accounting` | Sage Business Cloud Accounting and Sage 50; Sage 200, Sage 300 and Sage X3 | The Sage Group plc | Not asked |
| `smacc` | SMACC | SMACC | Not asked |
| `datev-export` | DATEV | DATEV eG | Not asked |
| `exact-online` | Exact Online | Exact Group | Not asked |
| `moneybird` | Moneybird | Moneybird B.V. | Not asked |
| `fortnox` | Fortnox | Fortnox AB | Not asked |
| `odoo` | Odoo | Odoo S.A. | Not asked |
| `erpnext` | ERPNext | Frappe Technologies Pvt. Ltd. | Not asked |
| `business-central` | Microsoft Dynamics 365 Business Central | Microsoft Corporation | Not asked |
| `netsuite` | NetSuite | Oracle Corporation | Not asked |
| `sap-b1` | SAP Business One | SAP SE | Not asked |
| `sap-s4hana` | SAP S/4HANA | SAP SE | Not asked |
| `oracle-fusion` | Oracle Fusion Cloud ERP | Oracle Corporation | Not asked |
| `hubspot` | HubSpot | HubSpot, Inc. | Not asked |
| `zoho-crm` | Zoho CRM | Zoho Corporation | Not asked |
| `salesforce` | Salesforce | Salesforce, Inc. | Not asked |
| `pipedrive` | Pipedrive | Pipedrive | Not asked |
| `suitecrm` | SuiteCRM | SalesAgility Ltd. | Not asked |

Trademark owners are written from general knowledge and must be confirmed on each vendor's own page.

**Letter tile (no logo):** Qoyod, Wafeq, Daftra, TallyPrime, Lexware Office, sevDesk, Pennylane, Dynamics 365
Finance and Supply Chain, Dynamics 365 Sales, Acumatica and Holded. Ten more files were supplied whose vendor is not
clear from the artwork; none of them is in the plugin. When the owner names them they can be added the same way.

## What has not been checked

- **No vendor's brand or logo terms have been read, and no vendor has been asked.** Many vendors allow an integration
  to show their logo with a statement that it is theirs; some require a partner agreement, a size limit or their
  current artwork. The files were supplied by the owner and were not compared with the vendors' current brand kits.
- WordPress.org guideline 17 asks plugins to respect trademarks and copyrights. A reviewer may ask for a vendor's
  permission or for the logos to be removed. The readme already says the names and logos belong to their owners and
  that the plugin is independent of them.
- Screenshot 3 on WordPress.org was taken before the logos were added.

## Take the logos out

- **Everywhere:** delete the `assets/logos/` folder (and the line in `CHANGELOG.md`). The screen shows letter tiles
  and no other change is needed. `tests/php/Unit/Catalog/LogosTest.php` only checks the files that are there.
- **One site, without touching the files:** `add_filter( 'estasoft_sync_show_vendor_logos', '__return_false' );`
- **One vendor:** delete its file.

## Add a logo

1. Save the vendor's current artwork as a 160 by 160 px PNG named `<connector id>.png` in `assets/logos/`. A vendor
   with several products shares one file through `SAME_AS` in `includes/Catalog/Logos.php`.
2. Add a row to the table above with the trademark owner and where the permission or the brand terms are recorded.
3. Run `phpunit` (the logo test checks the name and the size) and `npm run build`.
