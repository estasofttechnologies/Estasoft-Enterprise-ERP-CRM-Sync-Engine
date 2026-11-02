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
  `== Screenshots ==` section of `readme.txt`.
- No price, upgrade, licence or "pro" wording in any image. The plugin has no paid tier.
- The official name, **Estasoft Enterprise ERP/CRM Sync Engine**, is written in full on the banner. The short
  label ESTASOFT SYNC is used only inside the plugin's own screens.
- Third-party logos and trademarks do not appear (see [GUIDELINES-AUDIT.md](GUIDELINES-AUDIT.md), guideline 17).
- Screenshots show the finished plugin with sample data only: no real customer names, emails, addresses, API keys
  or order numbers from a live shop.
- Optional and not used: `icon.svg`, right-to-left banners (`banner-772x250-rtl.png`, `banner-1544x500-rtl.png`),
  `screenshot-N.gif`. The plugin is English only for now.

## Current status

| File | Status |
| --- | --- |
| `icon-128x128.png` | Present, 128 x 128 PNG. Estasoft E in navy and white. |
| `icon-256x256.png` | Present, 256 x 256 PNG. |
| `banner-772x250.png` | Present, 772 x 250 PNG. Sand and navy; full official name; the line "Checkout never waits." |
| `banner-1544x500.png` | Present, 1544 x 500 PNG. |
| `screenshot-1.png` to `screenshot-6.png` | **Pending.** They have to be taken from the finished plugin, not from the static design boards. `readme.txt` already carries the captions, so `php bin/check-readme.php` prints one warning per missing image. |

The banner shows an illustrative dashboard (invented orders such as #10494, a made-up shop). It is artwork, not a
claim about real results. Replace it if a screenshot of the real dashboard is preferred.

Dimensions were checked with `file .wordpress-org/*.png`. Rights to the artwork: the icon is the Estasoft E from
the owner's own brand file; the owner confirms that Estasoft holds the rights before submission.

## Screenshots to take

Take them on a clean test site with sample data, in English, at about 1440 px browser width, in the default
WordPress admin colour scheme, with the admin menu visible. Crop to the content, keep each file under about
1 MB (for example `oxipng -o4` on PNG), and name the files exactly as below.

| No. | Caption in `readme.txt` | Screen to capture (design board) |
| --- | --- | --- |
| 1 | The dashboard: what synced, what is waiting, what needs a person | Dashboard, normal state (A02) |
| 2 | Needs attention: failed orders grouped by cause, fixed in one step | Queue, Needs attention (C02) |
| 3 | Choose your system from 34, in one list | Setup step 2, Choose a system (B02) |
| 4 | Preview exactly what your system will receive before anything is sent | Setup step 5, Preview (B05) |
| 5 | Mapping with versions, preview and rollback | Mapping (E01) |
| 6 | The order screen box and the Sync column | Order screen box (H02) and orders list column (H03), composed into one image |

If the final screens differ from the captions, change the captions in `readme.txt` (and keep the numbering) rather
than the other way round.

## Before submission

1. Add `screenshot-1.png` to `screenshot-6.png` to `.wordpress-org/`.
2. Run `php bin/check-readme.php --strict-assets`. It fails while any captioned screenshot is missing.
3. Open the plugin page preview after the first deploy and check the banner crops cleanly at both sizes.
