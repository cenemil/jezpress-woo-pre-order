# CLAUDE.md — jezpress-woo-pre-order

Guidance for Claude Code when working in this plugin. See the parent `plugins/CLAUDE.md` for
repo-wide conventions (updater/license patterns, release process, coding standards).

- **Constant prefix:** `JWPO_`
- **Slug:** `jezpress-woo-pre-order`
- **Text domain:** `jezpress-woo-pre-order`
- **Requires:** WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+
- **License-gated:** yes — everything past `JWPO_Settings` / `JWPO_Admin` only loads when the
  license is valid (see `jezpress-woo-pre-order.php:79`).

## The release mechanism (read this before touching release logic)

There are **two independent release paths** that people routinely conflate. They use different
storage, different timezones, and different triggers.

### 1. Product-level — instant, no cron

`JWPO_Product::is_preorder_active()` (`includes/class-jwpo-product.php`) is the single gate for all
front-end pre-order presentation:

```php
enabled === 'yes' && ( no release date || release_timestamp > time() )
```

This is evaluated **live on every request**. There is no cron and no stored "released" state, so a
product reverts to normal WooCommerce behaviour at the exact second its release date passes.

`_jwpo_preorder_enabled` is **never cleared** by the plugin. A released product still shows a
ticked checkbox and a past date in the editor — the date is the source of truth, not the checkbox.

Everything that gates on `is_preorder_active()`, all of which stops at release:

| Behaviour | Hook / method |
|---|---|
| Add to Cart button text override | `add_to_cart_text()` on `woocommerce_product_add_to_cart_text` (+ `_single_`) |
| "Available on {date}" notice | `render_availability_notice()` on `woocommerce_single_product_summary` (prio 11) |
| "Pre-order" title badge | `maybe_append_preorder_badge_to_title()` on `the_title` |
| Holding new orders in `jwpo-preorder` | `JWPO_Cart::maybe_hold_for_preorder()` |
| Pack Builder pending-item notice | `JWPO_Bundle_Bridge` |

### 2. Order-level — scheduled, lags behind

Orders placed during the pre-order window carry their **own copy** of the release date in order
meta. `JWPO_Scheduler::run_release_check()` moves them `jwpo-preorder → jwpo-releasing` once it
passes. `jwpo-releasing → completed` is **manual only, by design** — never add automatic
completion.

Consequences to keep in mind:

- Frequency is a setting (`release_check_frequency`, default `daily`), so an order can sit in
  Pre-order for up to one interval past its release date while the product front-end has already
  flipped. This divergence is expected, not a bug.
- It is WP-cron, so it only fires on traffic. Quiet sites lag further.

### Timezone trap

The two paths store the date differently. Do not copy one convention into the other:

| Storage | Format | Timezone |
|---|---|---|
| Product meta `_jwpo_release_date` | `Y-m-d H:i:s` | Site-local wall clock — resolved via `new DateTime( $raw, wp_timezone() )` |
| Order meta (`JWPO_Cart::ORDER_META_RELEASE_DATE`) | `Y-m-d H:i:s` | **UTC** — resolved via `new DateTimeZone( 'UTC' )` |

### Pack Builder note

Pre-order meta only ever lives on the **parent product post**. WooCommerce variations don't get
their own product-data-tab save, so `JWPO_Bundle_Bridge` reads `$row['product_id']`, never
`variation_id` — `variation_id` is used only for the display name.

## Release date field constraints (product editor)

The field is rendered **manually** rather than via `woocommerce_wp_text_input()`, because that
helper always emits the help tip between the label and the input, which crowds the wide
`datetime-local` control. Manual markup puts the tip after the input.

Constraints applied:

- `min` = now + 1 day, **rounded up to the next quarter hour**. The rounding is load-bearing:
  browsers measure `step` intervals *from* `min`, so an unrounded min like `14:07` would make
  `:07/:22/:37/:52` the valid slots.
- `step="900"` — 15 minute intervals.

**Legacy guard:** if a product's already-saved date is in the past *or* off a 15-minute boundary,
both `min` and `step` are omitted for that product. Without this, HTML5 validation refuses to
submit the whole product form, blocking the admin from saving *any* unrelated change until they
touch the date. Do not remove this guard.

**Both constraints are client-side only.** `save_meta()` accepts whatever is posted, so imports,
the REST API, and WP-CLI can still store past or off-interval dates.

## Known rough edges

- **Release *time* is never displayed.** `get_availability_text()` formats with
  `get_option('date_format')` only. A 14:30 release shows just "Available on 2 August 2026" and the
  button flips mid-afternoon with no explanation. Appending `get_option('time_format')` when the
  time isn't midnight would fix it.
- **Released products look active in the editor** (see above). Compounded by the legacy guard:
  those are exactly the products that lose the `min`/`step` constraints.

## Assets

`assets/css/frontend.css` is enqueued **only on single product pages** (`is_product()` check in
`JWPO_Product::enqueue_frontend_assets()`). The badge and the circular info icon are drawn entirely
in CSS — `.jwpo-preorder-info` renders a plain `i` character inside an 18×18 rounded grey chip, so
don't reintroduce a Unicode glyph or dashicon.

`assets/css/admin.css` is enqueued **only on the plugin's own settings page**, not the product edit
screen — any product-editor styling has to be inline or a new enqueue.

## Release process

Per parent `plugins/CLAUDE.md`: bump `JWPO_VERSION` + `Version:` header, update `Stable tag:` +
changelog in `readme.txt`, then:

```bash
zip -r jezpress-woo-pre-order.zip jezpress-woo-pre-order -x "*.git*" -x "*CLAUDE.md" -x "*PLAN.md"
jezpress plugins preflight jezpress-woo-pre-order ./jezpress-woo-pre-order.zip
jezpress plugins upload jezpress-woo-pre-order ./jezpress-woo-pre-order.zip
```

Notify the team via the Jezweb dev Google Chat space.
