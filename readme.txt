== JezPress Woo Pre-Order ==
Contributors: jezpress
Tags: woocommerce, pre-order, backorder, bundle
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 6.0
Stable tag: 1.1.0
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

== Changelog ==

= 1.1.0 =
* Added a "Pre-order" badge next to the product title on the single product page, shown only when that specific product has pre-order enabled and active.
* Pack Builder custom-pack addon options now show the same badge next to their price when that addon's product is in pre-order.
* Badge includes an info icon with a hover tooltip showing the release-date availability text.

= 1.0.0 =
* Initial scaffolding — license/updater, admin shell, settings.
