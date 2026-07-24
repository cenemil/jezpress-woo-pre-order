<?php
/**
 * JWPO_Bundle_Bridge — optional Pack Builder integration.
 *
 * Pack Builder (jezpress-woo-pack-builder) is a soft/optional dependency:
 * this class only wires its hooks when WC_Product_Pack exists, and the rest
 * of the plugin works fine without it (single-product pre-order only). No
 * changes are needed to Pack Builder itself — all the coupling lives here.
 *
 * Only "standard" packs (fixed items, JWPB_DB::get_pack_items()) are
 * supported. Custom packs (customer-selectable addons) are out of scope for
 * this first pass — a customer picks addons at cart time, so there's no
 * fixed pre-order state to hold the whole bundle to.
 *
 * At checkout, when a pack's contents include a pre-order product, the pack
 * line item itself is stamped as pre-order (same item meta keys JWPO_Cart
 * uses for a plain product), so JWPO_Cart::maybe_hold_for_preorder() holds
 * the whole order without needing to know anything about packs. Per spec,
 * the whole bundle waits for the *last* contained item to release.
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Bundle_Bridge {

	/** Order item meta: JSON map of contained product_id => {name, qty_per_unit}, for reporting. */
	const ITEM_META_BUNDLE_CONTENTS = '_jwpo_bundle_pending_items';

	public static function init() {
		if ( ! class_exists( 'WC_Product_Pack' ) || ! class_exists( 'JWPB_DB' ) ) {
			return;
		}

		// Priority 20 — no ordering dependency on JWPB_Order's own hook,
		// since pack contents are read from JWPB_DB directly rather than
		// from order item meta JWPB_Order may or may not have written yet.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'stamp_pack_line_item' ), 20, 4 );
	}

	/**
	 * @param WC_Order_Item_Product $item
	 * @param string                $cart_item_key
	 * @param array                 $values
	 * @param WC_Order              $order
	 * @return void
	 */
	public static function stamp_pack_line_item( $item, $cart_item_key, $values, $order ) {
		$product = $item->get_product();

		if ( ! $product instanceof WC_Product_Pack || $product->is_custom() ) {
			return;
		}

		$pack_items = JWPB_DB::get_pack_items( $product->get_id() );

		if ( empty( $pack_items ) ) {
			return;
		}

		$pending    = array(); // display_product_id => array( 'name' => ..., 'qty_per_unit' => ... )
		$open_ended = false;
		$max_ts     = 0;

		foreach ( $pack_items as $row ) {
			// Pre-order meta only ever lives on the parent product post — WC
			// variations don't get their own product-data-tab save, so the
			// parent product_id (not variation_id) is what JWPO_Product reads.
			$parent_id = $row['product_id'];

			if ( ! JWPO_Product::is_preorder_active( $parent_id ) ) {
				continue;
			}

			$display_id      = $row['variation_id'] ? $row['variation_id'] : $parent_id;
			$display_product = wc_get_product( $display_id );

			$pending[ $display_id ] = array(
				'name'         => $display_product ? $display_product->get_name() : ( '#' . $display_id ),
				'qty_per_unit' => max( 1, (int) $row['quantity'] ),
			);

			$release_ts = JWPO_Product::get_release_timestamp( $parent_id );

			if ( ! $release_ts ) {
				$open_ended = true;
			} else {
				$max_ts = max( $max_ts, $release_ts );
			}
		}

		if ( empty( $pending ) ) {
			return;
		}

		$item->add_meta_data( JWPO_Cart::ITEM_META_IS_PREORDER, 'yes' );

		// Whole bundle waits for the LATEST release date among its pending
		// items — if any pending item is open-ended, the bundle is too.
		$item->add_meta_data(
			JWPO_Cart::ITEM_META_RELEASE_DATE,
			$open_ended ? '' : wp_date( 'Y-m-d H:i:s', $max_ts )
		);

		$item->add_meta_data( self::ITEM_META_BUNDLE_CONTENTS, wp_json_encode( $pending ) );
	}
}
