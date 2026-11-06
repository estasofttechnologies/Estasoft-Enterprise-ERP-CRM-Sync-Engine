# WordPress.org assets

Everything in `.wordpress-org/` is uploaded to the plugin's `assets` folder in the WordPress.org SVN repository
by the release workflow (the 10up deploy action reads `.wordpress-org/` by default). Assets are never part of the
plugin zip: `.distignore` keeps the folder out of it.

## Specifications

| File | Size | Format | Used for |
| --- | --- | --- | --- |
| `icon-128x128.png` | 128 x 128 px | PNG (also JPG or GIF) | Plugin directory search results |
| `icon-256x256.png` | 256 x 256 px | PNG | High-density screens; the plugin page |
| `banner-772x250.png` | 772 x 250 px | PNG or JPG | Plugin page header, standard screens |
| `banner-1544x500.png` | 1544 x 500 px | PNG or JPG | Plugin page header, high-density screens |
| `screenshot-1.png` to `screenshot-6.png` | up to 1600 px wide | PNG or JPG | Screenshots tab; captions come from `readme.txt` |

Rules that WordPress.org enforces or reviewers check:

- File names are lower case and exact. The number in `screenshot-N` must match the numbered caption in the
  `== Screenshots ==` section of `readme.txt` (`php bin/check-readme.php --strict-assets` checks this).
- No price, upgrade, licence or "pro" wording in any image. The plugin has no paid tier.
- The official name, **Estasoft Enterprise ERP/CRM Sync Engine**, is written in full on the banner. The short
  label ESTASOFT SYNC is used only inside the plugin's own screens.
- No image made for WordPress.org (icon, banners) carries a third-party logo or trademark (see
  [GUIDELINES-AUDIT.md](GUIDELINES-AUDIT.md), guideline 17). A screenshot of the plugin's own Choose a system screen
  shows the vendor logos the plugin itself shows ([LOGOS.md](LOGOS.md)); a vendor's name may appear in a screenshot
  as the name of the connected system on a screen.
- Screenshots show the finished plugin with sample data only: no real customer names, emails, addresses, API keys
  or order numbers from a live shop.
- Optional and not used: `icon.svg`, right-to-left banners (`banner-772x250-rtl.png`, `banner-1544x500-rtl.png`),
  `screenshot-N.gif`. The plugin is English only for now.

## What is there (all done)

| File | Description |
| --- | --- |
| `icon-128x128.png`, `icon-256x256.png` | A white "E" with a notch on a navy rounded square (the Estasoft mark). PNG with transparent corners. |
| `banner-1544x500.png`, `banner-772x250.png` | Sand background on the left with the full official name in navy, the line "Keep WooCommerce in step with your ERP, accounting system or CRM. Checkout never waits.", and "Orders, customers, stock, tracking"; a navy panel on the right with an illustrative dashboard card (312 synced today, 14 waiting, 5 need a person, and a short list of orders with their states). The orders are invented (#10494 and so on): it is artwork, not a claim about real results. No vendor logo, no price. |
| `screenshot-1.png` to `screenshot-6.png` | 1280 wide PNG (screenshot 3 is 1280 x 1160, 225 KB; the others 1280 x 960, 98 to 131 KB). Taken in a browser from the plugin inside WordPress 6.4.3 with the sample shop "Cedar & Stone Supply" (design board S03), default admin colours, the admin menu visible. The Connection card on screenshot 1 shows a sample address. |

Rights to the artwork: the icon is the Estasoft E from the owner's own brand file; the owner confirms that Estasoft
holds the rights to the icon and banner before submission ([OWNER-ACTIONS.md](OWNER-ACTIONS.md), item 7).

## Screenshots and their captions

The captions in `readme.txt` match these files (looked at on 2 Nov 2026).

| No. | Caption in `readme.txt` | What the image shows |
| --- | --- | --- |
| 1 | The dashboard: what synced, what is waiting, what needs a person. | Dashboard with the four numbers (312 synced today, 14 waiting, 5 need attention, oldest waiting 46 minutes), the Needs attention list with five entries, the Connection card (Odoo) and the queue bars. |
| 2 | Needs attention: failed orders grouped by cause, fixed in one step. | Queue, Needs attention: "Product not found in Odoo" with three orders and the buttons Map, Retry 3 orders and Skip 3 orders; the next group begins below. |
| 3 | Choose from 34 systems: how you connect, what you need first. | Choose a system with the filter "In the UAE" (All 34, ERP 11, Accounting 17, CRM 6, In the UAE 11), the search field, the explanation, and the five accounting systems of the UAE: each row says how you connect (your own app then sign in, paste a key, export file) and what you need first, carries the tag "Not yet tested with a live account", and Xero is selected. |
| 4 | Preview exactly what your system will receive before anything is sent. | Mapping with the "Preview with a real order" dialog open: "What Odoo will receive. Preview only. Nothing is sent." and the JSON of the order. |
| 5 | Reconciliation: a nightly check lists every order that differs between WooCommerce and your system, ready to repair. | Reconciliation: last check, compared, match and differences, and three differences with Repair and Ignore buttons. |
| 6 | Settings: email notifications, performance, access and a readiness check, with no usage tracking. | Settings, Notifications tab, with the line "This plugin has no usage tracking and talks only to the system you connect, and only after you set it up." |

Screenshot 3 was retaken on 3 Nov 2026 from the build with the 34-system Choose a system screen (the method line, the
"Not yet tested" tag and the reason on a system that cannot be selected). It was taken before the vendor logos were
added (6 Nov 2026) and shows letter tiles; retake it if the logos stay in the release. The others still match the screens; look at
them once more against the final build.

If a final screen differs from its caption, change the caption in `readme.txt` (keep the numbering) rather than the
other way round, then run `php bin/check-readme.php --strict-assets`.

## Before submission

1. Look at screenshots 1, 2, 4, 5 and 6 once more against the final build; compress any new file (for example `oxipng -o4`), keeping each under about 1 MB.
2. Run `php bin/check-readme.php --strict-assets`. It fails while any captioned screenshot is missing.
3. Open the plugin page preview after the first deploy and check the banner crops cleanly at both sizes.
