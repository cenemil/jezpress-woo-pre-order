# CLAUDE.md — jezpress-woo-pre-order

Guidance for Claude Code when working in this plugin. See the parent `plugins/CLAUDE.md` for
repo-wide conventions (updater/license patterns, release process, coding standards).

- **Constant prefix:** `JWPO_`
- **Slug:** `jezpress-woo-pre-order`
- **Text domain:** `jezpress-woo-pre-order`
- **Requires:** WordPress 5.8+, WooCommerce 6.0+, PHP 7.4+
- **License-gated:** yes — everything past `JWPO_Settings` / `JWPO_Admin` only loads when the
  license is valid (see `jezpress-woo-pre-order.php:79`).
- **Git:** this plugin directory is its own repo — `origin` is
  `https://github.com/cenemil/jezpress-woo-pre-order.git`, default branch `master` (not `main`).

## Git — always run git from inside this directory

The parent `wp-content/plugins/` directory is **not** a repository, and neither is anything above it
until `/Users/cenemiljonessumbalan`. A git command run from `plugins/` therefore walks up and
silently attaches to the repo in the **home directory** (`origin` = `cenemil/xerophpapp.git`),
reporting all of `~` as untracked — `git add`/`git commit` from there can commit unrelated personal
files to the wrong remote. `cd` into this plugin directory first, every time.

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
| Cart/checkout line-item badge (plain product/variation) | `render_cart_item_badge()` on `woocommerce_cart_item_name` |
| Cart/checkout line-item badge (Pack Builder pack contents) | `JWPO_Bundle_Bridge::render_cart_item_badge()` on `woocommerce_cart_item_name` |
| Holding new orders in `jwpo-preorder` | `JWPO_Cart::filter_payment_complete_status()` + `maybe_hold_for_preorder()` |
| Pack Builder pending-item notice | `JWPO_Bundle_Bridge` |

### How an order gets *into* `jwpo-preorder`

There are three routes, all converging on the same status, and
`JWPO_Cart::stamp_hold_meta()` (on `woocommerce_order_status_jwpo-preorder`, **priority 5**) is what
writes the release-date meta + order note for all of them. Priority 5 is load-bearing: the
confirmation email runs off the same action at 10 and its template reads that meta.

1. **`woocommerce_payment_complete_order_status`** — the preferred route. Rewrites the status the
   gateway is about to set, so the order never passes through Processing/Completed and the customer
   doesn't get those statuses' emails on the way past. Two non-obvious constraints:
   - **Priority 999, not 10.** Gateways hook this same filter and some do it unconditionally —
     `WC_Gateway_COD::change_payment_complete_order_status()` forces `completed` for every COD
     order. Gateways register later than this plugin, so at equal priority they run after us and
     win; the order then went `pending → completed` (firing the customer's "order complete" email)
     before the `completed` fallback bounced it back into Pre-order. Verified on the test site.
   - **Must stay side-effect free and deterministic.** `WC_Order` re-applies it within the same
     request (`maybe_set_date_paid()`, `get_date_paid()`), so an "already held" short-circuit in
     here breaks `date_paid`. That's why the `ORDER_META_HELD` check lives in the *action* handlers,
     not the filter.
2. **`woocommerce_order_status_processing` / `_completed`** — fallbacks for paths
   `payment_complete()` never runs on: COD, BACS/cheque once the admin marks the order paid,
   gateways calling `update_status()` directly, manual admin changes. `completed` matters because
   fully virtual/downloadable orders skip Processing entirely.
3. **Manual** — an admin picking Pre-order in the status dropdown. Route 1 and 2 don't fire, but
   `stamp_hold_meta()` still does.

`JWPO_Cart::ORDER_META_HELD` (`_jwpo_held`) is set once, on the first hold, and both the fallback
actions and `stamp_hold_meta()` bail when it's `yes`. Without it, an admin deliberately pushing a
held order to Processing ahead of its release date would see it bounce straight back.

### Emails — the lazy-mailer trap

`JWPO_Emails::register_email_actions()` adds both status actions to `woocommerce_email_actions`.
**This is not optional plumbing** — before 1.5.0 it was missing and *neither email ever sent*.

A `WC_Email` subclass registers its trigger in its constructor, and constructors only run when
`WC()->mailer()` is instantiated. WooCommerce instantiates it lazily:
`WC_Emails::init_transactional_emails()` registers `send_transactional_email` against a hardcoded
action list at `init`, and *that* is what loads the mailer and then fires `<action>_notification`.
Custom statuses aren't in that list, so nothing loaded the mailer for them.

Two consequences that must be preserved:

- The email subclasses hook **`woocommerce_order_status_jwpo-preorder_notification`** (and
  `..._jwpo-releasing_notification`), as core WC emails do — never the raw status action. Hooking
  the raw action cannot work: the `add_action()` happens while that same action is already
  mid-dispatch, so PHP's `foreach` over a copy of the callback array never sees it.
- Don't hook both the raw action *and* the `_notification` variant "to be safe" — on requests where
  the mailer is already loaded that sends the email twice.

Worst-hit case before the fix was the release notice: a WP-cron request touches nothing that loads
the mailer, so `JWPO_Scheduler` transitions fired into a void.

`JWPO_Emails::trigger_admin_new_order()` (priority 20 on the same `_notification` action) exists
because core only sends the admin "New order" email on the pending → processing/completed/on-hold
transitions. Now that a pre-order skips all three, the shop would otherwise never be told a
pre-order came in. It's safe to call unconditionally — `WC_Email_New_Order::trigger()` no-ops once
`_new_order_email_sent` is set.

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

This applies outside packs too, and is easy to get wrong: `JWPO_Cart::stamp_line_item()` must use
`$item->get_product_id()` (the parent), **not** `$item->get_product()`, which returns the variation
for a variable product and therefore reads empty pre-order meta. That mismatch was live until 1.5.0
— `JWPO_Product::render_cart_item_badge()` resolves the parent, so a pre-ordered variation showed a
cart badge while the order was never held and no confirmation email was sent.

`JWPO_Bundle_Bridge::collect_pending_release()` centralises this "does this pack currently contain
a not-yet-released pre-order item" check — both `stamp_pack_line_item()` (order-time meta) and
`render_cart_item_badge()` (cart/checkout badge) call it, over the same row shape
(`product_id`/`variation_id`/`quantity`) used by both `JWPB_DB::get_pack_items()` and JWPB's own
`_jwpb_addon_selections` cart item data.

`collect_pending_release()`'s row source is centralised in `resolve_pack_rows()`, used by **both**
the cart badge and order-time stamping (since 1.5.0 — before that, `stamp_pack_line_item()` bailed
on custom packs and re-derived standard ones from `JWPB_DB`, so a pre-ordered addon showed a badge
but never held the order or sent the confirmation email). It reads whatever JWPB already stamped
onto the cart item — `_jwpb_addon_selections` for a custom pack, `_jwpb_snapshot` for a
standard/seasonal one, falling back to `JWPB_DB::get_pack_items()` — matching
`JWPB_Order::copy_pack_meta_to_order_item()`'s own precedence, so the badge, the order line item and
JWPB's contents summary all agree on what's in the box rather than on the pack's current live
configuration.

`apply_preorder_item_meta()` uses `update_meta_data()`, not `add_meta_data()`, and keeps the **later**
of the pack's own date and its contents' date. A pack product can itself be flagged pre-order, in
which case `JWPO_Cart::stamp_line_item()` has already stamped the same two meta keys at priority 10;
duplicate keys would leave `get_pending_release_timestamp()` reading whichever value happened to be
stored first.

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

## Order-output labelling — snapshot, never a live check

Two places append a plain `(Pre-order)` suffix (`JWPO_Cart::preorder_label()`, built from
`JWPO_Product::get_badge_text()` so it follows the `jwpo_preorder_badge_text` filter):

| What | Where |
|---|---|
| The line item name | `JWPO_Cart::append_order_item_label()` on `woocommerce_order_item_name` |
| One line of a pack's contents list | `JWPO_Bundle_Bridge::append_content_line_label()` on JWPB's `jwpb_order_item_content_line` |

Both read the **order-time snapshot** in item meta — `ITEM_META_IS_PREORDER` and
`ITEM_META_BUNDLE_CONTENTS` — never `JWPO_Product::is_preorder_active()`. That's deliberate and the
whole point: `is_preorder_active()` answers against the product's *current* release date, so once
the date passed the label would silently vanish from an order the customer already placed, and the
release notice would describe a different order than the confirmation did.

The one live-check fallback is for orders predating `ITEM_META_BUNDLE_CONTENTS`, and it's reached
only after confirming the item carries `ITEM_META_IS_PREORDER`. Without that guard it would start
labelling the contents of long-settled orders the moment someone flagged one of their products
pre-order.

Note the badge and the label are separate mechanisms: the cart/checkout **badge** is HTML
(`build_badge_html()`, with the release-date tooltip); order output uses this **plain-text suffix**,
because it has to survive the plain-text email as well as HTML.

`woocommerce_order_item_name` also fires in `WC_Structured_Data`, so the suffix reaches an order's
schema.org payload. Harmless, but it's why the suffix is kept plain and short.

## Known rough edges

- **Release *time* is never displayed.** `get_availability_text()` formats with
  `get_option('date_format')` only. A 14:30 release shows just "Available on 2 August 2026" and the
  button flips mid-afternoon with no explanation. Appending `get_option('time_format')` when the
  time isn't midnight would fix it.
- **Released products look active in the editor** (see above). Compounded by the legacy guard:
  those are exactly the products that lose the `min`/`step` constraints.

## Assets

`assets/css/frontend.css` is enqueued on single product pages, the cart page, and the checkout page
(`is_product() || is_cart() || is_checkout()` check in `JWPO_Product::enqueue_frontend_assets()`) —
the same `.jwpo-preorder-badge` markup is reused for the cart/checkout line-item badge, so it needs
to be enqueued wherever that markup can appear. The availability tooltip (release-date text) lives
on a `title` attribute on the badge element itself — `JWPO_Product::build_badge_html()` /
`get_badge_html()` render the whole `<span class="...badge" title="...">` (class name is a
parameter, so Pack Builder's `.jwpb-addon-preorder-badge` reuses the same builder) — there is no
separate info-icon element anymore.

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

Those three commands run from the parent `plugins/` directory — that's fine for `zip`/`jezpress`,
but do **not** run git there (see the Git section above). Commit, tag and push from inside this
directory:

```bash
git push origin master
```

Notify the team via the Jezweb dev Google Chat space.
