# Mapping engine

How an order or product becomes the payload a connector sends. Code: `includes/Mapping`. WooCommerce side: `includes/Woo`. Namespace root: `Estasoft\SyncEngine`.

The flow for one queue item, in `Mapping\PayloadBuilder` (the container key `payload_builder`):

1. Load the live rule set for the connection and item type.
2. Read the item from WooCommerce into the canonical model (`Mapping\CanonicalSource`, implemented by `Woo\WooOrderReader`). Store fields that rules ask for as `meta.<key>` are requested by name; nothing else is read.
3. Refuse an order with nothing to send: no product lines, or only products marked Exclude from syncing (SYNC-VAL-002 with a plain reason), and a refund that only covers excluded products. For orders, then check the buyer tax number (see Tax). A failure is SYNC-VAL-001 and nothing is sent.
4. Run the rules (`Mapping\RuleEngine`). Failures become a `ConnectorError`; the queue handles them like any other.
5. Pass the payload through the filter `estasoft_sync_payload`.
6. Return `Sync\PayloadResult` with the payload, the mapping version, and the warnings as notes.

The engine is pure PHP. It sends nothing, writes nothing, and does the same thing for the same input. The only lookups that leave the site are the read-only product lookups of `sku_match`, and those happen only in the real build or in a preview where the person chose to look things up.

## Rule model

A rule set is an ordered list of rules, stored as `rules_json` in `estasoft_sync_mappings`.

```
{
  "id": "rule-customer-phone",
  "target": "partner.phone",
  "source": "billing.phone",
  "transforms": [ { "type": "phone_e164", "args": { "default_country": "billing.country" } } ],
  "required": false,
  "error_code": "SYNC-VAL-002",
  "when": { "source": "billing.company", "equals": "Acme" }
}
```

| Key | Meaning |
|---|---|
| `id` | Unique in the set. Appears in error messages (the message names the rule, never the value). |
| `target` | A field path from the connector's `describeFields()`. A path ending in `[]` such as `order_line[].sku` is a list. |
| `source` | A canonical path (`billing.phone`, `lines[].sku`), a list of paths (the transforms receive them as a list, see `join`), or `{ "const": value }` for a fixed value. |
| `transforms` | Steps run in order. Each step is `{ "type": name, "args": {...} }`. |
| `required` | When true, an empty final value stops the item with `error_code`, or SYNC-VAL-002 when no code is given. |
| `when` | Optional condition `{ "source": path, "equals": value }`. When it does not hold the rule is skipped. A yes/no value is written `"true"` or `"false"`, the way the canonical model writes it (the editor and the sanitiser store `true`/`false` as those words, never `1` or an empty string). |

Behaviour that is easy to get wrong:

- **Empty results are left out.** `null`, `''` and an empty list are not written. `0` and `false` are kept.
- **A later rule overwrites an earlier rule** that wrote the same target.
- **Lists.** A source such as `lines[].sku` produces one value per row. A transform that is not list-aware (everything except `join`) runs once per row.
- **Row alignment.** Rules that write the same list target (`order_line[].*`) line up by row number. A list-target rule whose sources are all single values (`order.currency`) is applied to every row that earlier rules already created; if there are none it creates one row. A rule that only holds a constant never makes a row of its own: with no rows made before it, it writes nothing, so an order without lines never gets a phantom `order_line[0]` holding just a fixed value.
- **Required over a list.** A `required` rule whose source is a list fails when the list is empty or missing, with the rule's error code (the preset `line_sku` gives SYNC-MAP-001), and also when any one row is empty.
- **Stops at the first failure.** `RuleEngine::apply()` throws `MappingException` (code plus the sanitised rule id). `applyAll()` collects every error; the previewer uses it.
- **`changed`.** The output records which targets hold a value that differs from the plain source, so the preview can highlight them.
- **Validation.** `RuleEngine::validate()` and `RuleValidator` check ids, targets against the connector's fields, canonical sources (`CanonicalFields`, or `meta.<key>`), transform names and each transform's `check()`. A `meta.<key>` whose key looks like payment, card, password or visitor data is an error on save (`source_blocked`, `when_blocked`); see Store fields below. Inbound sets (stock, tracking) skip the source check because their sources are the connector's fields.

## Transforms

The library is fixed. Rules pick from it; they cannot bring code. `TransformRegistry::standard()` builds it.

| Type | Arguments | What it does |
|---|---|---|
| `prefix` | `value` | Adds text in front. Empty stays empty. |
| `suffix` | `value` | Adds text after. Empty stays empty. |
| `trim` | `collapse` | Removes spaces around the text (Unicode-aware); `collapse` squeezes inner runs. Arabic text is not touched. |
| `case` | `mode`: `upper`, `lower`, `title` | Changes case. Scripts without case come back unchanged. |
| `phone_e164` | `default_country` (ISO code or canonical path) | International form. Reads spaces, dashes, brackets, `00`, `+`, a stray `(0)` and Arabic-Indic digits. Unreadable numbers become empty with a warning. |
| `iso_country` | `output`: `alpha2` (default), `alpha3` | Country code or English or Arabic name to ISO 3166-1. Unknown becomes empty with a warning. |
| `iso_state` | `country` (code or path) | WooCommerce state code, ISO code or English or Arabic name to ISO 3166-2. The data covers the countries in `SubdivisionData`, GCC included. |
| `money_minor` | `from`: `major` (default), `minor`; `currency` | Amount to integer minor units with integer arithmetic (3 decimals for KWD, 0 for JPY, 2 otherwise). Text that is not a number becomes empty with a warning. |
| `date_utc` | `format`: `mysql` (default), `iso`, `date`; `timezone` (zone of text without an offset); `output_timezone`: `UTC` (default), `store` (the WordPress time zone) or a zone name | Dates and Unix timestamps to UTC. With `output_timezone` the result is written in that zone, so an order placed at 01:30 in Dubai can give the shop's own date (`format: date`, `output_timezone: store`). An `iso` result outside UTC carries its offset instead of `Z`. Without the argument nothing changes. |
| `value_map` | `map`, `else`, `ignore_case`, `strict` | Table lookup. Unmapped values keep the original unless `else` or `strict` says otherwise. |
| `if_then` | `operator` (`equals`, `not_equals`, `contains`, `empty`, `not_empty`, `gt`, `lt`), `value`, `then`, `else` | Chooses between two values. |
| `default` | `value` or `from` (canonical path) | Fallback for an empty value. |
| `required` | `error_code` | Stops the item when the value is empty. Use it mid-pipeline; the rule flag checks the final value. |
| `join` | `separator`, `skip_empty` | Joins a list of sources into text. The only transform that receives whole lists. |
| `split` | `separator`, `limit`, `index` | Splits text; `index` picks one part (`-1` is the last). |
| `tax_lookup` | `country`, `class` | Tax rate in percent to the connected system's tax code through the tax mapping. Unmapped rate stops the item with SYNC-MAP-002. |
| `sku_match` | `output`: `id` (default), `ref` | SKU to the connected system's product. Unknown SKU stops the item with SYNC-MAP-001. |

## Canonical model notes

- **Excluded products.** Lines of products marked Exclude from syncing are left out of `lines[]` and `refund.lines[]`. The order totals (`order.total`, `subtotal`, `tax_total`, `discount_total`) stay the WooCommerce figures, so a total in the other system is `order.total` minus `order.excluded_total`. Three fields describe what was left out: `order.excluded_count` (lines), `order.excluded_total` (their total with tax, minor units) and `order.excluded_tax` (the tax inside it).
- **Refunds.** `refund.amount` is the whole refund. `refund.lines[]` holds the refunded product lines that are not excluded (`sku`, `quantity`, `amount` before tax, `tax`). What the lines do not explain is split out: `refund.shipping_amount` (shipping and its tax), `refund.excluded_amount` (the part that belongs to excluded products) and `refund.other_amount` (the rest: fees, rounding, a manual amount). A shipping-only refund therefore has no lines and a `shipping_amount`. A refund that only covers excluded products is not queued (and is logged); one that also refunds shipping is.
- **Buyer tax number.** `order.buyer_tax_number` is stored in its plain form (`Support\TaxNumber`): ASCII digits, upper-case letters, no spaces, dashes, dots, slashes or direction marks. The validator checks this same form, so the number that was validated is the number that is sent.
- **Store fields (`meta.<key>`).** Any order meta key can be read as `meta.<key>`, except keys that look like payment tokens, card data, passwords, nonces, signatures, sessions or visitor addresses (`Support\MetaKeyPolicy`: whole words such as token, secret, password, nonce, card, cvv, ip_address, user_agent, api_key, and gateway prefixes such as `_stripe`, `_paypal`, `_ppcp`, `_wcpay`). Such a key is refused when the rule is saved, never requested from the order, and never returned by the reader. A site can change the lists with the filters `estasoft_sync_blocked_meta_words` and `estasoft_sync_blocked_meta_prefixes`.

## Tax and buyer tax numbers

`Mapping\TaxMap` stores both kinds of entry in `estasoft_sync_mappings` with type `tax`, as one flat list with no drafts (`MappingRepository::replaceLive`); every change is logged with who made it.

```
{ "kind": "tax", "country": "SA", "tax_class": "", "tax_name": "VAT", "rate_percent": "15", "system_code": "VAT15" }
{ "kind": "buyer_tax", "country": "SA", "digits": 15, "required_for_business": true }
```

- A rate is matched by country and rate (compared as integer basis points, never as a float). An unmapped rate is SYNC-MAP-002 before anything is sent.
- The default buyer rule is Saudi Arabia, 15 digits, required when the buyer is a business (a company name or a tax number is present). The first save stores the default; saving an empty list of buyer rules switches it off.
- The tax number is read from the first order meta key of a built-in list (`_billing_vat_number`, `_billing_tax_number` and similar) that holds a number after cleaning; sites with another field extend the list with `estasoft_sync_buyer_tax_number_keys`. Arabic-Indic digits, spaces, dashes and the like are normalised first (see Canonical model notes).

## SKU matching

`Mapping\SkuMatcher` resolves a SKU in this order:

1. A match a person set by hand for this variation, then this product.
2. A stored match for this variation or product whose SKU is still the SKU on the line.
3. A stored match for the SKU on another WooCommerce id (`IdMapRepository::findBySku`), for a product that was re-created. A hand-made match belongs to the product it was made for: it is accepted here only when that product is the line's product or variation.
4. A read-only lookup through the connector's `lookupProduct()`. A hit is remembered.

No match is SYNC-MAP-001. A lookup that fails for a network or server reason is not a mapping error: `LookupFailedException` carries the connector's own error, so the queue retries. `peek()` and `ReadOnlyProductResolver` answer without writing anything; the previewer uses them.

## Preview

`Mapping\Previewer` shows source, target, changed paths, rule notes, lookups and errors for one real order or product. It sends nothing and writes nothing. Lookups happen only when asked for and are labelled "(read-only lookup)". Errors use the `ApiErrorBody` shape with status 422. When a preview cannot be built at all the code is SYNC-PRV-001.

## Versions

`Mapping\MappingService` is the entry point for the admin screens: `set` (the rule set an editor opens, with its sources and targets), `saveDraft` (sanitises, validates, stores), `validate`, `changes` (rules added, changed and removed since the live version), `publish`, `discardDraft`, `rollback`, `versions`, `version`. Publishing is refused when the rules cannot be checked: if the connection or its connector is missing, or the connector offers no fields for a type that writes to it, `validate` returns a `fields_unavailable` problem ("cannot be checked"), `publish` raises `RuleValidationException` and the draft is kept. Inbound sets (stock, tracking) read the other system's fields and are checked without a field list. History is linear: publishing makes the next version, and a rollback makes a new live version copied from the chosen old one, so nothing is rewritten. Every action is written to the log with the person's display name. `Mapping\Presets` holds the starting rule sets.

## WooCommerce side

`Woo\WooModule` registers the hooks only when WooCommerce is active (`Woo\Compatibility`; HPOS and the block checkout are declared compatible). It uses the CRUD layer only and never reads order tables directly.

| Hook | Used for |
|---|---|
| `woocommerce_checkout_order_processed`, `woocommerce_store_api_checkout_order_processed` | New order from the classic and block checkout. |
| `woocommerce_payment_complete`, `woocommerce_order_status_changed` | Payment and status changes. An order the queue has never seen is queued as new on its first relevant event (payment, or a move to processing, on hold or completed) when it was placed at or after the go-live time. |
| `woocommerce_update_order` (2 arguments) | Edits, coalesced into one queue item at `shutdown`; the order WooCommerce passes is used, not loaded again. |
| `woocommerce_refund_created` | Refunds (partition `order:{parent id}`), unless they only cover excluded products. `woocommerce_order_refunded` is not used so a refund is queued once. |
| `woocommerce_created_customer` | New customers. |
| `estasoft_sync_item_state_changed`, `estasoft_sync_item_synced`, `estasoft_sync_item_failed`, `estasoft_sync_shadow_processed` | Mirror the queue state into order meta (`Woo\StateMirror`). |

**Go-live.** The go-live time is `answers.went_live_at` of the onboarding state, written when setup is finished (`Jobs\Shadow\GoLiveService`), read through the port `Woo\Ports\GoLiveInfo`. There is no rolling window: an order placed at or after that time is a real order whatever its age when the first event arrives; an order placed before it stays "Placed before go-live" and the order box offers Send this order. While setup is unfinished no order counts as placed after go-live.

**Checks every way of queuing shares** (`Woo\OrderEligibility`): drafts (`auto-draft`, `checkout-draft`, `trash`, or an order without an id) and orders of nothing but excluded products are never queued, by a hook or by the Send this order and Sync again buttons (the box and the bulk actions answer "unsaved" or "excluded"). The box shows no button on the Add order screen.

**Finding an order's queue item.** `CoreQueueGateway` asks Core for the newest item of one object by type and id (`QueueRepository::latestForEntity`, and `forEntity` for the connections that hold the order). Until those exist it searches the queue and keeps only an exact match on type and id. Sync again forgets the last-synced hash on every such connection before queuing the update. The orders list column reads only the order meta and asks the queue nothing; its view counts are cached for five minutes (a button or bulk action clears them), and "Not synced yet" is every order minus the orders that have a state.

Order meta keys are exactly `Core\MetaKeys::order()`, written with `update_meta_data` and `save_meta_data` (the order itself is never re-saved, so no loop). Admin output is rendered on the server, escaped, then passed through `wp_kses`. The order box buttons are nonce-protected links to `admin-post.php` (action `estasoft_sync_order_action`) and need `Capabilities::MANAGE`.

## Filters and actions

| Name | Where | Purpose |
|---|---|---|
| `estasoft_sync_payload` (filter) `$payload, $item, $connection` | `PayloadBuilder` | Last chance to change the payload. |
| `estasoft_sync_buyer_tax_number_keys` (filter) | `WooOrderReader` | Extra order meta keys that hold the buyer's tax number. |
| `estasoft_sync_blocked_meta_words`, `estasoft_sync_blocked_meta_prefixes` (filters) | `MetaKeyPolicy` | Words and key prefixes that make a `meta.<key>` source refused. |
| `estasoft_sync_card_last4_keys` (filter) | `WooOrderReader` | Meta keys that hold the last four card digits. Card data is never read beyond that. |
| `estasoft_sync_stock_buffer` (filter) | `WooModule` | Units to hold back from the stock sent out (default 0). |
| `estasoft_sync_meta_keys` (filter, from Core) | `WooModule` hooks it | Adds `_estasoft_sync_match` to the registered meta keys for products. |
| `estasoft_sync_queue_display` (filter, from Core) | `Woo\QueueDisplay` hooks it | Fills the label and detail shown for a queued order, refund or customer. |
| `estasoft_sync_product_match` (action) `$connectionId, $productId, $sku, $found` | `MappingModule` | Fired after an automatic product match. |

## Add a transform

1. Create `includes/Mapping/Transforms/MyThing.php`: `final class MyThing extends BaseTransform` with `name()` (lower snake case, the `type` in rules), `apply( $value, array $args, TransformContext $context )` and, if it has required arguments, `check( array $args ): array` returning plain-language problems. Return `null` or `''` for "nothing to send". Override `handlesLists()` only if you need the whole list. Throw `TransformArgumentException` for an unusable argument and `MappingException` only for a rule that must stop the item. Never call the network from a transform; go through `TransformContext` lookups.
2. Add `new MyThing()` to `TransformRegistry::standard()`.
3. Add a unit test in `tests/php/Unit/Mapping/Transforms/` (extend `TransformTestCase`): normal input, empty input, a list, bad arguments, and Arabic text when the transform touches text.
4. Add a row to the Transforms table above.

Keep arguments to plain text, numbers, booleans and small tables. The sanitiser (`RuleSanitizer`) keeps arguments exactly as typed, so a separator of a single space survives.
