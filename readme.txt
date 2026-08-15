== JezPress Woo Pre-Order ==
Contributors: jezpress
Tags: woocommerce, pre-order, backorder, bundle
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
Stable tag: 1.6.2
License: Proprietary

Pre-order support for WooCommerce — hold orders until a product's release date, with bundle-aware status for Pack Builder boxes.

== Description ==

JezPress Woo Pre-Order lets a store take orders for products ahead of their release date.

- Mark any product as a pre-order with a release date; the Add to Cart button and price
  messaging update automatically.
- Orders containing a pre-order item are held in a "Pre-order" status until the release date,
  then move to "Releasing" automatically (or via manual admin action) ahead of a manual "Completed".
- Customers get a distinct pre-order confirmation email and a release/dispatch notification email.
- Bundles/boxes built with JezPress Woo Pack Builder stay in pre-order status as a whole until
  every pre-order item inside them has been released.
- Admin order list shows pre-order status; a reporting tab lists pending pre-order counts per
  product for print-run/demand planning.

Requires WooCommerce to be active. Optional integration with JezPress Woo Pack Builder for
bundle-aware pre-order status.

== Frequently Asked Questions ==

= What happens when a product's release date passes? =

The product reverts to normal WooCommerce behaviour on its own — there is no cron job or admin
action involved. A product counts as "pre-order active" only while the Enable pre-order checkbox
is ticked AND the release date is still in the future, and that comparison is made live on every
page request. The moment the release date passes, all pre-order presentation stops:

* The Add to Cart button reverts to the WooCommerce default text.
* The "Available on {date}" availability notice is no longer shown.
* The "Pre-order" badge next to the product title disappears.
* Pack Builder packs stop counting the item as pending; the pack's pre-order notice disappears
  entirely if that was the only pre-order item inside it.
* New orders are no longer held — they follow the normal WooCommerce status flow and do not
  receive the pre-order confirmation email.

= Does the Enable pre-order checkbox turn itself off after release? =

No. The checkbox stays ticked and the release date stays in the field. Only the front-end
behaviour changes. This is intentional so the release date remains on record, but it does mean a
released product still looks like an active pre-order in the product editor — check the date, not
the checkbox.

= What happens to orders that were already placed before the release date? =

They are handled separately from the product. Each pre-order order stores its own copy of the
release date, and a scheduled release check moves it from Pre-order to Releasing once that date
passes. The check frequency is configurable on the settings screen (daily by default), so an order
can remain in Pre-order for up to one check interval after its release date — the product
front-end flips over immediately, but orders follow the schedule. Releasing to Completed is always
a manual admin action.

= Can a release date be set in the past? =

Not from the product editor. The release date field enforces a minimum of one day ahead of the
current site time and restricts the time to 15 minute intervals. Products saved with an earlier or
off-interval date before this rule existed keep their stored value and are exempt from the
constraint, so their other product settings can still be saved.

== Changelog ==

= 1.6.2 =
* Pre-order confirmation email: removed the closing line above the order summary, which repeated the "we will email you again as soon as your order is ready to ship" sentence already in the release paragraph
* Release notice email: the closing line no longer repeats "your order is now being prepared for dispatch" from the paragraph above it, and now reads only "You'll receive a separate shipping notification once it's on its way"
* Both lines are the emails' default additional content, so a site that had saved its own wording on the WooCommerce → Settings → Emails screen keeps it — only a stored copy of the exact old default is cleared
* Fixed apostrophes and quotes in that closing line printing as raw HTML entities in the plain-text version of both emails ("You&#8217;ll receive..." instead of "You'll receive...")

= 1.6.1 =
* Order line items for pre-ordered products now read "Product name (Pre-order)" everywhere an order is rendered — the pre-order confirmation and release notice emails, the thank-you page, My Account, and the admin order screen
* Inside a Pack Builder pack's contents list, the specific items still awaiting release are marked "(Pre-order)" individually, so a customer can tell which part of the box is holding the order up rather than only seeing the pack labelled as a whole (requires Pack Builder 1.3.3+)
* Both labels read the pre-order state stamped on the order at checkout rather than the product's current release date, so an order's emails stay consistent with each other once the release date passes

= 1.6.0 =
* Maintenance release — no functional changes. Internal developer documentation only: recorded the plugin's Git layout (each plugin is its own repository) so routine git commands can't be run from the wrong directory.

= 1.5.0 =
* Fixed: the Pre-order Confirmation and Pre-order Release Notice emails never actually sent. Both are now registered with WooCommerce as email-bearing actions, so WooCommerce loads its mailer for the Pre-order and Releasing transitions the same way it does for core statuses. This also fixes the release notice being skipped entirely when the transition came from the scheduled release check (WP-cron).
* Orders now land in Pre-order directly on payment instead of passing through Processing or Completed first, so customers no longer receive the standard "Processing" or "Order complete" email immediately before the pre-order confirmation. This holds even for gateways that force a status of their own — Cash on Delivery, for one, marks every order Completed on payment.
* The shop admin's standard WooCommerce "New order" email is now sent for pre-orders too. WooCommerce only sends it on the transitions into Processing, Completed and On hold, which a pre-order no longer passes through.
* Fixed: orders that skip Processing — fully virtual or downloadable orders, which WooCommerce completes straight away — were never held as pre-orders and so never triggered the confirmation email. They are now.
* Fixed: a pre-ordered variable product bought as a variation was never held as a pre-order and never triggered the confirmation email — it showed the cart badge but the order went through as normal. Variations now resolve to their parent product, where the pre-order settings live.
* Custom Pack Builder packs (customer-selected addons) now hold the order and send the pre-order confirmation when a selected addon is pre-order active. Previously only standard packs did this, so a pre-ordered addon showed a cart badge but the order was never held.
* A pack line item whose own base product is also marked pre-order now stores a single release date — the later of the pack's own date and its contents' — instead of two conflicting values.
* An order is only ever held once, so an admin who deliberately moves a held order to Processing or Completed before its release date no longer sees it bounce back to Pre-order.
* Setting an order to Pre-order by hand in the admin now records the expected release date and order note, and sends the confirmation email, just like the automatic path.

= 1.4.0 =
* Removed the separate info icon next to the "Pre-order" badge — the release-date availability tooltip is now a `title` attribute on the badge itself.
* Badge now shows a small triangular arrow pointing back at the title/name it's attached to, on the product title, cart/checkout line items, and Pack Builder addon options alike.

= 1.3.0 =
* Added a "Pre-order" badge next to cart and checkout line items when that product (or a selected variation) is currently pre-order active.
* Pack Builder packs show the same badge when any of their contents — a standard pack's fixed items, or a custom pack's customer-selected addons — is pre-order active, independent of whether the pack's own base product is marked pre-order.
* frontend.css is now also enqueued on the cart and checkout pages (previously single product pages only) so the badge and info icon render correctly there.

= 1.2.0 =
* Release date field: help tip now sits after the input, and the field floats in line with the other pre-order fields.
* Release date field: enforces a minimum of one day ahead of the current site time, and restricts the time to 15 minute intervals. Products with an existing earlier or off-interval date are exempt so their other settings can still be saved.
* Pre-order info icon is now a styled circular badge rendered from CSS instead of a Unicode glyph, for consistent appearance across themes and platforms.
* Documented the automatic release mechanism — how products revert after their release date, and how that differs from the scheduled order transition (see FAQ).

= 1.1.0 =
* Added a "Pre-order" badge next to the product title on the single product page, shown only when that specific product has pre-order enabled and active.
* Pack Builder custom-pack addon options now show the same badge next to their price when that addon's product is in pre-order.
* Badge includes an info icon with a hover tooltip showing the release-date availability text.

= 1.0.0 =
* Initial scaffolding — license/updater, admin shell, settings.
