# jezpress-woo-pre-order — Development Plan

Source spec: `../jezpress-woo-pre-order.md`. This plan follows the same architecture, naming,
and release conventions established by `jezpress-woo-pack-builder` (JWPB), `jezpress-woo-shop-toggle`
(JWST), and `jezpress-woo-stripe-subscriptions-audit` (JWSSA).

## Status (2026-07-23)

Phases 0–6 are implemented and lint-clean; the plugin activates without fatal errors on the live
Local site (verified via WP-CLI). Everything past Phase 0 is license-gated and has **not** been
functionally exercised yet — that requires an active license key. Deviations from the original plan:

- No separate `class-jwpo-ajax.php` — manual "release now" / "mark completed" admin actions use
  WooCommerce's built-in Order Actions dropdown (`woocommerce_order_actions` filter) instead of a
  custom AJAX handler. Simpler, and it's the standard WC extension pattern for this kind of action.
- Reporting tab expands Pack Builder bundle line items into their individual contained pre-order
  products (via `_jwpo_bundle_pending_items` item meta) rather than counting the box SKU — this
  matches the spec's actual demand-planning intent ("print run for the book").
- Bundle bridge only supports **standard** packs (fixed items via `JWPB_DB::get_pack_items()`).
  Custom packs (customer-selectable addons) are explicitly out of scope for this first pass.
- Pre-order meta only ever lives on the parent product post (WC variations have no data-tab save),
  so bundle bridge checks `is_preorder_active()` against the pack row's `product_id`, not
  `variation_id`, even though `variation_id` is used for the display name.

Phase 7 (regression/testing checklist) is intentionally left undone per client instruction.

### Post-1.0 additions

- **1.1.0** — "Pre-order" title badge on single product pages, plus the same badge next to Pack
  Builder custom-pack addon prices.
- **1.2.0** — Release date field reworked in the product editor: rendered manually so the help tip
  sits after the input, `min` of now + 1 day rounded up to the next quarter hour, `step="900"` for
  15 minute intervals, with a legacy-date guard that drops both constraints when an existing saved
  date would fail them (otherwise HTML5 validation blocks the whole product form). Info icon is now
  CSS-drawn rather than a Unicode glyph.

The release mechanism — the distinction between the instant, cron-free product-level revert and the
scheduled order-level `Pre-order → Releasing` transition, and the timezone difference between their
two storage formats — is documented in this plugin's `CLAUDE.md`, with a customer-facing summary in
the `readme.txt` FAQ.

## Naming & Conventions

- Slug: `jezpress-woo-pre-order`
- Class prefix: `JWPO_`, constant prefix: `JWPO_`, option/nonce prefix: `jwpo_`
- Dependencies: WooCommerce (required, hard dependency, checked at `plugins_loaded` priority 20).
  `jezpress-woo-pack-builder` is an **optional soft dependency** for bundle propagation — detect via
  `class_exists('WC_Product_Pack')`, same defensive pattern as `JWPB_Subscription_Bridge`. Plugin
  must load and work fine (single-product pre-order only) if Pack Builder is absent.
- WordPress 5.8+, PHP 7.4+. No build step — pure PHP, no Composer/npm.
- License + updater pattern ported unchanged from `jezpress-woo-stripe-subscriptions-audit`
  (`JWPO_License` singleton, `JWPO_Updater`). License option name: `jzwb_lic_` + first 8 chars of
  `md5('jezpress-woo-pre-order')`.

## Open Decisions (need client sign-off before/while building)

1. **Payment handling** — spec leaves this open. Default assumption for Phase 1: charge in full at
   time of order (standard WooCommerce checkout flow, no deposit/split-payment gateway work). Deposit
   or partial-payment support is out of scope unless the client confirms it's required — flag as a
   possible v2.
2. **Status machine exact labels** — spec names an intermediate "Releasing" status between
   Pre-order and Completed. Confirm with client whether Releasing is a real fulfilment step (staff
   pick/pack window) or just a transient flag before Completed.
3. **Cancellations/refunds mid-preorder** — not covered in spec. Flag as a gap; default to standard
   WooCommerce refund/cancel behaviour with no special pre-order handling in Phase 1.
4. **Release date granularity** — is release date per-product only, or does the client need per-order
   overrides (e.g. staggered release for the same product)? Default: per-product only, matching spec.

## Architecture

### File Layout (mirrors JWPB/JWST/JWSSA)

```
jezpress-woo-pre-order/
  jezpress-woo-pre-order.php     Entry: JWPO_VERSION/DIR/URL constants, loads updater+license
                                  before plugins_loaded, WC check at plugins_loaded priority 20
  uninstall.php
  readme.txt
  .gitignore
  CLAUDE.md                      Written once architecture stabilises (post Phase 1)
  includes/
    class-jwpo-product.php       Static. Per-product pre-order meta, product data tab,
                                  add-to-cart button text / price / availability filters
    class-jwpo-order-status.php  Static. Registers custom order statuses (Pre-order, Releasing)
                                  via wc_order_statuses + register_post_status; HPOS-safe
    class-jwpo-cart.php          Static. Detects pre-order items at add-to-cart/checkout,
                                  stamps order + order-item meta
    class-jwpo-bundle-bridge.php Static. Optional JWPB integration — inspects pack contents for
                                  pre-order items, propagates hold status to the whole bundle
    class-jwpo-scheduler.php     Static. WP-Cron date-based status transition + manual
                                  "Release Now" admin action
    class-jwpo-emails.php        Registers 2 WC_Email subclasses via woocommerce_email_classes
    class-jwpo-admin.php         Singleton. WooCommerce → Pre-Orders menu; tabs Settings | Orders |
                                  Reporting | License; order list column/filter; per-product report
    class-jwpo-ajax.php          Static. Manual release trigger, admin list actions
    class-jwpo-license.php       Singleton (ported pattern, unchanged)
    class-jwpo-updater.php       (ported pattern, unchanged)
```

### Data Model

**Product postmeta** (set on the WC product edit screen, new "Pre-order" tab):

| Key | Type | Notes |
|---|---|---|
| `_jwpo_preorder_enabled` | `yes`/`no` | |
| `_jwpo_release_date` | MySQL datetime | Scheduled publish/release date |
| `_jwpo_button_text` | string | Optional override, default "Pre-order Now" |
| `_jwpo_availability_text` | string | Optional override template, default "Available on {date}" |

**Order-level:**

- Custom order status `wc-jwpo-preorder` ("Pre-order") — registered via `register_post_status` +
  `wc_order_statuses` filter. Use WC's order-status API (not direct `post_status` queries) so this
  stays compatible with HPOS (`WC_Order::set_status()` / `wc_get_orders(['status' => ...])`).
- Custom order status `wc-jwpo-releasing` ("Releasing").
- Order meta `_jwpo_release_date` — earliest pending release date among the order's pre-order line
  items (for a bundle: the max release date across pre-order items inside it, since the whole bundle
  must wait for the *last* item to release).
- Order item meta `_jwpo_is_preorder` (`yes`/`no`) and `_jwpo_item_release_date` per line item.

### Bundle / Pack Builder Integration

- Soft dependency only — `JWPO_Bundle_Bridge` checks `class_exists('WC_Product_Pack')` before doing
  anything, same philosophy as `JWPB_Subscription_Bridge`. Pack Builder itself needs **no changes** —
  all coupling lives on the JWPO side, keeping JWPB decoupled and reusable elsewhere.
- On checkout, when a line item's product is a `WC_Product_Pack`, read its contents (order item meta
  `_jwpb_contents`, written by `JWPB_Order`) and check each contained `product_id`/`variation_id`
  against `_jwpo_preorder_enabled`.
- If any contained item is pre-order, the whole pack line — and the whole order, per spec — is held
  at "Pre-order" status until **all** contained pre-order items have reached their release date
  (i.e. until `now >= MAX(release_date)` across the pre-order items in the bundle).
- Verify against the real-world case named in the spec: Sugar Baby Life Box (built with
  `jezpress-woo-pack-builder`).

### Order Workflow

1. Checkout completes → normal WooCommerce flow reaches Processing (or Pending payment, gateway
   dependent).
2. If the order contains ≥1 pre-order line item (single product or pre-order-containing bundle),
   `JWPO_Cart`/order hooks transition Processing → **Pre-order** (`wc-jwpo-preorder`).
3. `JWPO_Scheduler` cron (daily check is likely sufficient; confirm granularity with client) scans
   orders in Pre-order status whose release date has passed and transitions them to **Releasing**
   (`wc-jwpo-releasing`). A manual "Release Now" action in the admin order list/detail bypasses the
   date check for early release.
4. Releasing → **Completed** is a manual admin action only, per spec ("manual trigger by admin"). No
   auto-complete — this is the point where staff confirm dispatch.

### Customer-Facing

- My Account → Orders: custom status label + colour via `wc_order_statuses` / order status CSS
  class. Order details page adds an "Expected release: {date}" notice while status is Pre-order or
  Releasing.
- Emails: two new `WC_Email` subclasses registered through `woocommerce_email_classes` so they show
  up in WooCommerce → Settings → Emails like any core email (subject/heading/toggle editable by
  admin, no hardcoded copy):
  - `JWPO_Email_Preorder_Confirmation` — fires on Processing → Pre-order transition.
  - `JWPO_Email_Release_Notice` — fires on → Releasing transition.

### Admin-Side

- Order list: extra column showing pre-order badge + release date. WooCommerce surfaces custom
  statuses in its status filter dropdown automatically once registered correctly — no custom filter
  UI needed.
- Reporting: WooCommerce → Pre-Orders → Reporting tab — a simple table of pre-order-enabled products
  with pending pre-order quantities (order items joined against `_jwpo_is_preorder` meta), per the
  spec's "print run / demand planning" use case.

### License Gate

Follow the JWPB pattern exactly: always-loaded regardless of license — `JWPO_License`,
`JWPO_Updater`, `JWPO_Admin` (shell only). License-gated (only when `is_valid()`) — `JWPO_Product`,
`JWPO_Order_Status`, `JWPO_Cart`, `JWPO_Bundle_Bridge`, `JWPO_Scheduler`, `JWPO_Emails`, `JWPO_Ajax`.
Unlicensed installs see the License tab only, same as every sibling plugin.

## Phased Delivery

- **Phase 0 — Scaffolding**: entry file, constants, license/updater port, `uninstall.php`,
  `readme.txt`, `.gitignore`, admin shell with tab nav + license gate (copy JWST shell, rename).
- **Phase 1 — Single-product pre-order**: product meta + tab, add-to-cart button/price/availability
  overrides, Pre-order status registration, Processing → Pre-order transition on checkout.
- **Phase 2 — Scheduler**: cron date-based Pre-order → Releasing transition + manual admin release
  action; Releasing status + manual → Completed action.
- **Phase 3 — Emails**: pre-order confirmation + release notice `WC_Email` classes.
- **Phase 4 — Customer account UI polish**: status badges, expected-date notices.
- **Phase 5 — Admin order list column/filter + reporting page**.
- **Phase 6 — Pack Builder bridge**: bundle-aware pre-order propagation, whole-bundle hold logic;
  validate against the Sugar Baby Life Box use case.
- **Phase 7 — Regression + testing checklist** (do not execute yet, per client instruction — leave as
  a checklist matching the spec's Testing section):
  - Single-product pre-order — full order-to-release cycle.
  - Bundle with one pre-order item — whole-box hold behaviour, correct emails, correct status
    transitions.
  - Regression on existing order automations/plugins to confirm the new statuses don't break them.

## Nonces & Capabilities (planned)

| Action | Nonce action | Cap |
|---|---|---|
| Save pre-order product meta | WC metabox system | `manage_woocommerce` |
| Manual release trigger | `jwpo_release_{order_id}` | `manage_woocommerce` |
| Manual complete trigger | `jwpo_complete_{order_id}` | `manage_woocommerce` |
| Save settings | `jwpo_save_settings` | `manage_woocommerce` |
| License activate/deactivate | `jezweb_license_activate` / `jezweb_license_deactivate` | `manage_options` |

## Release Process (once built)

Same as siblings: bump `JWPO_VERSION` + `Version:` header, update `Stable tag:` + changelog in
`readme.txt`, zip excluding `.git*`, `CLAUDE.md`, `PLAN.md`, then:

```bash
jezpress plugins preflight jezpress-woo-pre-order ./jezpress-woo-pre-order.zip
jezpress plugins upload jezpress-woo-pre-order ./jezpress-woo-pre-order.zip
```

Notify the team via the Jezweb dev Google Chat space per parent `plugins/CLAUDE.md` webhook format.
