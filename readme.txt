== JezPress Woo Pre-Order ==
Contributors: jezpress
Tags: woocommerce, pre-order, backorder, bundle
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
Stable tag: 1.2.0
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
