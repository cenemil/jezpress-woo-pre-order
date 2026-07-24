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

	public static function init() {
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'stamp_line_item' ), 10, 4 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_hold_for_preorder' ) );
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
		$product = $item->get_product();

		if ( ! $product || ! JWPO_Product::is_preorder_active( $product ) ) {
			return;
		}

		$item->add_meta_data( self::ITEM_META_IS_PREORDER, 'yes' );
		$item->add_meta_data( self::ITEM_META_RELEASE_DATE, JWPO_Product::get_release_date_raw( $product ) );
	}

	/**
	 * Fires when WooCommerce transitions an order to Processing. If the
	 * order contains pre-order items that haven't released yet, redirect it
	 * into the Pre-order status instead.
	 *
	 * @param int $order_id
	 * @return void
	 */
	public static function maybe_hold_for_preorder( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$release_timestamp = self::get_pending_release_timestamp( $order );

		if ( null === $release_timestamp ) {
			return;
		}

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
			$order->delete_meta_data( self::ORDER_META_RELEASE_DATE );
			$note = __( 'Order held — awaiting release of pre-order item(s). No release date set; release manually when ready.', 'jezpress-woo-pre-order' );
		}

		$order->add_order_note( $note );
		$order->update_status( 'jwpo-preorder' );
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
