<?php
/**
 * JWPO_Bundle_Bridge — optional Pack Builder integration.
 *
 * Pack Builder (jezpress-woo-pack-builder) is a soft/optional dependency:
 * this class only wires its hooks when WC_Product_Pack exists, and the rest
 * of the plugin works fine without it (single-product pre-order only). No
 * changes are needed to Pack Builder itself — all the coupling lives here.
 *
 * Order-holding only supports "standard" packs (fixed items,
 * JWPB_DB::get_pack_items()). Custom packs (customer-selectable addons) are
 * out of scope there — a customer picks addons at cart time, so there's no
 * fixed pre-order state to hold the whole bundle to. The cart/checkout badge
 * (render_cart_item_badge()) is display-only and has no such constraint, so
 * it covers both standard and custom packs.
 *
 * At checkout, when a standard pack's contents include a pre-order product,
 * the pack line item itself is stamped as pre-order (same item meta keys
 * JWPO_Cart uses for a plain product), so JWPO_Cart::maybe_hold_for_preorder()
 * holds the whole order without needing to know anything about packs. Per
 * spec, the whole bundle waits for the *last* contained item to release.
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
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'render_cart_item_badge' ), 10, 3 );
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

		$result = self::collect_pending_release( $pack_items );

		if ( null === $result ) {
			return;
		}

		$item->add_meta_data( JWPO_Cart::ITEM_META_IS_PREORDER, 'yes' );

		// Whole bundle waits for the LATEST release date among its pending
		// items — if any pending item is open-ended, the bundle is too.
		$item->add_meta_data(
			JWPO_Cart::ITEM_META_RELEASE_DATE,
			$result['open_ended'] ? '' : wp_date( 'Y-m-d H:i:s', $result['max_ts'] )
		);

		$item->add_meta_data( self::ITEM_META_BUNDLE_CONTENTS, wp_json_encode( $result['pending'] ) );
	}

	/**
	 * Adds a "Pre-order" badge next to a Pack Builder cart/checkout line item
	 * when any of its contents — a standard pack's fixed items, or a custom
	 * pack's customer-selected addons — is currently pre-order active.
	 *
	 * Restricted to the cart and checkout pages themselves: this filter also
	 * fires in the mini-cart widget, which can render on any page and isn't
	 * part of this indicator's scope.
	 *
	 * Reads the same cart item data JWPB_Cart itself reads for its own
	 * contents summary (`_jwpb_snapshot` / `_jwpb_addon_selections`), rather
	 * than re-deriving contents from JWPB_DB, so this stays in sync with
	 * whatever the customer actually has in their cart (seasonal snapshot,
	 * or their specific addon picks) instead of the pack's current live
	 * configuration.
	 *
	 * @param string $name
	 * @param array  $cart_item
	 * @param string $cart_item_key
	 * @return string
	 */
	public static function render_cart_item_badge( $name, $cart_item, $cart_item_key ) {
		if ( ! is_cart() && ! is_checkout() ) {
			return $name;
		}

		$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

		if ( ! $product instanceof WC_Product_Pack ) {
			return $name;
		}

		if ( $product->is_custom() ) {
			$items = isset( $cart_item['_jwpb_addon_selections'] ) ? $cart_item['_jwpb_addon_selections'] : array();
		} else {
			$items = isset( $cart_item['_jwpb_snapshot'] ) ? $cart_item['_jwpb_snapshot'] : JWPB_DB::get_pack_items( $product->get_id() );
		}

		if ( empty( $items ) ) {
			return $name;
		}

		$result = self::collect_pending_release( $items );

		if ( null === $result ) {
			return $name;
		}

		$tooltip = self::build_release_tooltip( $result['open_ended'], $result['max_ts'] );

		return $name . ' ' . JWPO_Product::build_badge_html( $tooltip );
	}

	/**
	 * Inspects a list of pack rows (shape: product_id, variation_id,
	 * quantity — shared by JWPB_DB::get_pack_items() and JWPB_Cart's addon
	 * selections) for pending pre-order items. Shared by order-time stamping
	 * and the cart/checkout badge, which both need the same "does this pack
	 * currently contain any not-yet-released pre-order item" answer.
	 *
	 * @param array $items
	 * @return array{pending: array, open_ended: bool, max_ts: int}|null Null if nothing is pending.
	 */
	private static function collect_pending_release( $items ) {
		$pending    = array(); // display_product_id => array( 'name' => ..., 'qty_per_unit' => ... )
		$open_ended = false;
		$max_ts     = 0;

		foreach ( $items as $row ) {
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
			return null;
		}

		return array(
			'pending'    => $pending,
			'open_ended' => $open_ended,
			'max_ts'     => $max_ts,
		);
	}

	/**
	 * @param bool $open_ended
	 * @param int  $max_ts
	 * @return string
	 */
	private static function build_release_tooltip( $open_ended, $max_ts ) {
		if ( $open_ended || ! $max_ts ) {
			return '';
		}

		$template = JWPO_Settings::get( 'default_availability_text', __( 'Available on {date}', 'jezpress-woo-pre-order' ) );

		return str_replace( '{date}', date_i18n( get_option( 'date_format' ), $max_ts ), $template );
	}
}
