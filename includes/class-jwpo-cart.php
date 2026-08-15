<?php
/**
 * JWPO_Cart — stamps pre-order line items at checkout and holds the order
 * in the "Pre-order" status until every pre-order item has released.
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Cart {

	const ITEM_META_IS_PREORDER   = '_jwpo_is_preorder';
	const ITEM_META_RELEASE_DATE  = '_jwpo_item_release_date';
	const ORDER_META_RELEASE_DATE = '_jwpo_release_date';

	/** Set once, when an order is first held. Stops the order ever being re-held. */
	const ORDER_META_HELD = '_jwpo_held';

	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'stamp_line_item' ), 10, 4 );

		// Preferred route — intercept the status the gateway is *about* to set
		// on payment, so the order lands in Pre-order directly instead of
		// passing through Processing/Completed first (which would fire those
		// statuses' own customer emails on the way past).
		//
		// Priority 999 deliberately: gateways override this same filter, and
		// some do it unconditionally — WC_Gateway_COD::change_payment_complete_order_status()
		// forces `completed` for every COD order. Gateways register later than
		// this plugin does, so at an equal priority they run *after* us and win,
		// which sent the customer a "completed" email before the order bounced
		// back into Pre-order via the fallback below.
		add_filter( 'woocommerce_payment_complete_order_status', array( __CLASS__, 'filter_payment_complete_status' ), 999, 3 );

		// Fallbacks for the paths payment_complete() never runs on: COD,
		// BACS/cheque once the admin marks the order paid, gateways that call
		// update_status() directly, and manual admin status changes. Completed
		// matters because virtual/downloadable orders skip Processing entirely.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_hold_for_preorder' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_hold_for_preorder' ) );

		// Stamp the hold meta + note whenever an order lands in Pre-order by
		// *any* route, including an admin setting the status by hand. Priority
		// 5 so the release date exists before the confirmation email (which
		// runs off the same action at priority 10) renders it.
		add_action( 'woocommerce_order_status_jwpo-preorder', array( __CLASS__, 'stamp_hold_meta' ), 5, 2 );

		// "(Pre-order)" after the line item name wherever an order is rendered:
		// both emails, the thank-you page, My Account, and the admin order screen.
		add_filter( 'woocommerce_order_item_name', array( __CLASS__, 'append_order_item_label' ), 10, 2 );
	}

	/**
	 * The "(Pre-order)" suffix used on order line items and, via
	 * JWPO_Bundle_Bridge, on individual Pack Builder pack contents.
	 *
	 * @param bool $html Whether the return value lands in HTML output.
	 * @return string
	 */
	public static function preorder_label( $html = true ) {
		$text = sprintf( '(%s)', JWPO_Product::get_badge_text() );

		return $html ? esc_html( $text ) : $text;
	}

	/**
	 * Append "(Pre-order)" to a pre-order line item's name in order output.
	 *
	 * Reads the item meta stamped at checkout rather than asking
	 * JWPO_Product::is_preorder_active() — that's a live check against the
	 * product's current release date, so once the date passed it would answer
	 * "no" and the label would vanish from the order the customer already
	 * placed. The stamped meta is the order-time snapshot, so the pre-order
	 * confirmation and the release notice describe the same order.
	 *
	 * Covers plain products, variations and Pack Builder packs alike: a pack
	 * carries this meta whenever any of its contents was pending release, from
	 * JWPO_Bundle_Bridge::apply_preorder_item_meta().
	 *
	 * @param string        $name
	 * @param WC_Order_Item $item
	 * @return string
	 */
	public static function append_order_item_label( $name, $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return $name;
		}

		if ( 'yes' !== $item->get_meta( self::ITEM_META_IS_PREORDER ) ) {
			return $name;
		}

		return $name . ' ' . self::preorder_label();
	}

	/**
	 * Copy the product's current pre-order state onto the order line item at
	 * the moment the order is created, so later product edits don't change
	 * the terms of an order already placed.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string                $cart_item_key
	 * @param array                 $values
	 * @param WC_Order              $order
	 * @return void
	 */
	public static function stamp_line_item( $item, $cart_item_key, $values, $order ) {
		// Deliberately get_product_id() — the *parent* — not get_product(),
		// which returns the variation for a variable product. Pre-order meta
		// only ever lives on the parent product post, so reading it off a
		// variation always comes back empty: that's why a pre-ordered variation
		// used to show the cart badge (which resolves the parent) yet never
		// held the order or sent the confirmation email.
		$product_id = $item->get_product_id();

		if ( ! $product_id || ! JWPO_Product::is_preorder_active( $product_id ) ) {
			return;
		}

		$item->add_meta_data( self::ITEM_META_IS_PREORDER, 'yes' );
		$item->add_meta_data( self::ITEM_META_RELEASE_DATE, JWPO_Product::get_release_date_raw( $product_id ) );
	}

	/**
	 * Redirects the post-payment status to Pre-order when the order still has
	 * pre-order items pending release.
	 *
	 * Deliberately kept free of side effects and of any "already held" check:
	 * WC_Order re-applies this filter within the same request (notably from
	 * maybe_set_date_paid()) and expects a consistent answer. The meta and
	 * order note are written by stamp_hold_meta() off the resulting transition.
	 *
	 * @param string        $status
	 * @param int           $order_id
	 * @param WC_Order|null $order
	 * @return string
	 */
	public static function filter_payment_complete_status( $status, $order_id, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order ) {
			return $status;
		}

		return null === self::get_pending_release_timestamp( $order ) ? $status : 'jwpo-preorder';
	}

	/**
	 * Fires when WooCommerce transitions an order to Processing or Completed.
	 * If the order contains pre-order items that haven't released yet,
	 * redirect it into the Pre-order status instead.
	 *
	 * Only ever holds an order once (ORDER_META_HELD) — otherwise an admin who
	 * deliberately pushes a held order to Processing/Completed ahead of its
	 * release date would see it bounce straight back to Pre-order.
	 *
	 * @param int $order_id
	 * @return void
	 */
	public static function maybe_hold_for_preorder( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || 'yes' === $order->get_meta( self::ORDER_META_HELD ) ) {
			return;
		}

		if ( null === self::get_pending_release_timestamp( $order ) ) {
			return;
		}

		$order->update_status( 'jwpo-preorder' );
	}

	/**
	 * Records the expected release date and the "held" order note once an
	 * order has landed in Pre-order, whichever route put it there.
	 *
	 * @param int           $order_id
	 * @param WC_Order|null $order
	 * @return void
	 */
	public static function stamp_hold_meta( $order_id, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order || 'yes' === $order->get_meta( self::ORDER_META_HELD ) ) {
			return;
		}

		$release_timestamp = self::get_pending_release_timestamp( $order );

		if ( $release_timestamp > 0 ) {
			// Order-level release timestamp is stored in UTC (unlike the
			// product-level field, which is a site-timezone wall-clock value
			// edited directly in the admin) since this meta is internal only.
			$order->update_meta_data( self::ORDER_META_RELEASE_DATE, gmdate( 'Y-m-d H:i:s', $release_timestamp ) );
			$note = sprintf(
				/* translators: %s: expected release date */
				__( 'Order held — awaiting release of pre-order item(s). Expected: %s', 'jezpress-woo-pre-order' ),
				date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $release_timestamp )
			);
		} else {
			// Covers both an open-ended pre-order item and an order an admin
			// moved into Pre-order by hand with nothing pending on it.
			$order->delete_meta_data( self::ORDER_META_RELEASE_DATE );
			$note = __( 'Order held — awaiting release of pre-order item(s). No release date set; release manually when ready.', 'jezpress-woo-pre-order' );
		}

		$order->update_meta_data( self::ORDER_META_HELD, 'yes' );
		$order->add_order_note( $note );

		// Safe inside a status transition: WC_Order::status_transition() clears
		// its pending transition before dispatching, so this save() can't
		// re-enter the same hook.
		$order->save();
	}

	/**
	 * Inspects an order's line items for pending pre-order holds.
	 *
	 * @param WC_Order $order
	 * @return int|null Null if nothing is pending (order can proceed normally).
	 *                  0 if pending with no known release date (open-ended).
	 *                  Otherwise the max release Unix timestamp across all
	 *                  still-pending pre-order items.
	 */
	public static function get_pending_release_timestamp( $order ) {
		$has_pending = false;
		$open_ended  = false;
		$max_ts      = 0;

		foreach ( $order->get_items() as $item ) {
			if ( 'yes' !== $item->get_meta( self::ITEM_META_IS_PREORDER ) ) {
				continue;
			}

			$raw = $item->get_meta( self::ITEM_META_RELEASE_DATE );

			if ( '' === $raw ) {
				$has_pending = true;
				$open_ended  = true;
				continue;
			}

			try {
				$timestamp = ( new DateTime( $raw, wp_timezone() ) )->getTimestamp();
			} catch ( Exception $e ) {
				$timestamp = 0;
			}

			if ( $timestamp > time() ) {
				$has_pending = true;
				$max_ts      = max( $max_ts, $timestamp );
			}
		}

		if ( ! $has_pending ) {
			return null;
		}

		return $open_ended ? 0 : $max_ts;
	}
}
