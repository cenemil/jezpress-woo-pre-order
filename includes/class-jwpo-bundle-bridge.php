<?php
/**
 * JWPO_Bundle_Bridge — optional Pack Builder integration.
 *
 * Pack Builder (jezpress-woo-pack-builder) is a soft/optional dependency:
 * this class only wires its hooks when WC_Product_Pack exists, and the rest
 * of the plugin works fine without it (single-product pre-order only). No
 * changes are needed to Pack Builder itself — all the coupling lives here.
 *
 * At checkout, when a pack's contents include a pre-order product, the pack
 * line item itself is stamped as pre-order (same item meta keys JWPO_Cart uses
 * for a plain product), so the order-holding path holds the whole order without
 * needing to know anything about packs — and the pre-order confirmation email
 * follows from that hold. Per spec, the whole bundle waits for the *last*
 * contained item to release.
 *
 * Since 1.5.0 this covers **custom** packs (customer-selected addons) as well
 * as standard ones, reading the contents from the cart item exactly as
 * render_cart_item_badge() does — see resolve_pack_rows(). Before that, custom
 * packs were stamped-out entirely, so a pre-ordered addon showed a cart badge
 * but never held the order and never sent the confirmation email.
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

		// Priority 20 — after JWPO_Cart::stamp_line_item() (10), whose meta this
		// merges with when the pack product is itself flagged pre-order. No
		// ordering dependency on JWPB_Order's own hook, since pack contents are
		// read from the cart item / JWPB_DB rather than from order item meta
		// JWPB_Order may or may not have written yet.
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'stamp_pack_line_item' ), 20, 4 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'render_cart_item_badge' ), 10, 3 );

		// Label the individual pending items inside a pack's contents list.
		// No-ops harmlessly on Pack Builder < 1.3.3, which has no such filter.
		add_filter( 'jwpb_order_item_content_line', array( __CLASS__, 'append_content_line_label' ), 10, 4 );
	}

	/**
	 * Append "(Pre-order)" to a single pack contents line when that item was
	 * awaiting release at the time the order was placed.
	 *
	 * The pack line item as a whole already gets the suffix from
	 * JWPO_Cart::append_order_item_label(), but a pack usually mixes released
	 * and pending items, so the customer still can't tell *which* part of the
	 * box is holding the order up. This labels the specific items.
	 *
	 * Uses ITEM_META_BUNDLE_CONTENTS — the order-time snapshot of exactly which
	 * contents were pending, written by stamp_pack_line_item(). Falls back to a
	 * live is_preorder_active() check only for orders placed before that meta
	 * existed; that fallback answers against the product's *current* release
	 * date, so it stops labelling once the date passes.
	 *
	 * Pack Builder's contents entries key the display product as
	 * variation_id ?: product_id, matching how collect_pending_release() keys
	 * the pending map.
	 *
	 * @param string                $line Formatted contents line.
	 * @param array                 $c    Contents entry (product_id, variation_id, ...).
	 * @param WC_Order_Item_Product $item Order item.
	 * @param bool                  $html Whether $line is HTML or plain text.
	 * @return string
	 */
	public static function append_content_line_label( $line, $c, $item, $html = true ) {
		if ( ! $item instanceof WC_Order_Item_Product || ! is_array( $c ) ) {
			return $line;
		}

		$product_id   = isset( $c['product_id'] ) ? (int) $c['product_id'] : 0;
		$variation_id = isset( $c['variation_id'] ) ? (int) $c['variation_id'] : 0;

		if ( ! $product_id ) {
			return $line;
		}

		// Nothing in this pack was pending release when the order was placed, so
		// no line can be labelled. Checked before the live-product fallback
		// below, which would otherwise start labelling the contents of a settled
		// old order the moment someone flagged one of its products pre-order.
		if ( 'yes' !== $item->get_meta( JWPO_Cart::ITEM_META_IS_PREORDER ) ) {
			return $line;
		}

		$raw     = $item->get_meta( self::ITEM_META_BUNDLE_CONTENTS );
		$pending = $raw ? json_decode( $raw, true ) : null;

		if ( is_array( $pending ) ) {
			$display_id = $variation_id ? $variation_id : $product_id;

			if ( ! isset( $pending[ $display_id ] ) && ! isset( $pending[ (string) $display_id ] ) ) {
				return $line;
			}
		} elseif ( ! JWPO_Product::is_preorder_active( $product_id ) ) {
			// Pre-1.6.1 order with no pending snapshot to consult.
			return $line;
		}

		return $line . ' ' . JWPO_Cart::preorder_label( $html );
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

		if ( ! $product instanceof WC_Product_Pack ) {
			return;
		}

		$pack_items = self::resolve_pack_rows( $values, $product );

		if ( empty( $pack_items ) ) {
			return;
		}

		$result = self::collect_pending_release( $pack_items );

		if ( null === $result ) {
			return;
		}

		// Whole bundle waits for the LATEST release date among its pending
		// items — if any pending item is open-ended, the bundle is too.
		self::apply_preorder_item_meta( $item, $result['open_ended'], $result['max_ts'] );

		$item->update_meta_data( self::ITEM_META_BUNDLE_CONTENTS, wp_json_encode( $result['pending'] ) );
	}

	/**
	 * The pack's contents as the customer actually has them in their cart:
	 * their addon picks for a custom pack, the snapshot taken at add-to-cart
	 * time for a standard/seasonal one. Same source and precedence as
	 * JWPB_Order::copy_pack_meta_to_order_item() and render_cart_item_badge(),
	 * so all three agree on what's in the box.
	 *
	 * @param array           $values  Cart item data.
	 * @param WC_Product_Pack $product
	 * @return array Rows of product_id / variation_id / quantity.
	 */
	private static function resolve_pack_rows( $values, $product ) {
		if ( $product->is_custom() ) {
			return isset( $values['_jwpb_addon_selections'] ) ? $values['_jwpb_addon_selections'] : array();
		}

		if ( isset( $values['_jwpb_snapshot'] ) ) {
			return $values['_jwpb_snapshot'];
		}

		return JWPB_DB::get_pack_items( $product->get_id() );
	}

	/**
	 * Writes the pre-order item meta onto the pack line item, merging with
	 * anything JWPO_Cart::stamp_line_item() already stamped at priority 10 —
	 * which happens when the pack product *itself* is also flagged pre-order.
	 * Uses update_meta_data() and keeps the later of the two dates, because
	 * duplicate meta keys would leave JWPO_Cart::get_pending_release_timestamp()
	 * reading only whichever value happened to be stored first.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param bool                  $open_ended
	 * @param int                   $max_ts
	 * @return void
	 */
	private static function apply_preorder_item_meta( $item, $open_ended, $max_ts ) {
		if ( 'yes' === $item->get_meta( JWPO_Cart::ITEM_META_IS_PREORDER ) ) {
			$existing = $item->get_meta( JWPO_Cart::ITEM_META_RELEASE_DATE );

			if ( '' === $existing ) {
				$open_ended = true;
			} else {
				try {
					// Item meta is a site-timezone wall-clock value, matching
					// what JWPO_Product::get_release_date_raw() returns.
					$max_ts = max( $max_ts, ( new DateTime( $existing, wp_timezone() ) )->getTimestamp() );
				} catch ( Exception $e ) {
					$open_ended = true;
				}
			}
		}

		$item->update_meta_data( JWPO_Cart::ITEM_META_IS_PREORDER, 'yes' );
		$item->update_meta_data(
			JWPO_Cart::ITEM_META_RELEASE_DATE,
			$open_ended ? '' : wp_date( 'Y-m-d H:i:s', $max_ts )
		);
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

		$items = self::resolve_pack_rows( $cart_item, $product );

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
	 * Whether a pack currently contains at least one pending (not-yet-released)
	 * pre-order item — shared by the body-class hooks in JWPO_Product so a pack
	 * whose own product isn't flagged pre-order still trips "product-pre-order"
	 * / "cart-product-pre-order" when one of its contained addons is.
	 *
	 * $values is the cart item data when called for a cart row, or an empty
	 * array on the single product page — resolve_pack_rows() falls back to the
	 * pack's default JWPB_DB rows for a standard/seasonal pack in that case,
	 * but returns nothing for a custom pack, since no addon *selections* exist
	 * yet outside the cart. In that case we fall back to the product's own
	 * get_addon_items() — the pool of selectable addons (postmeta-backed, per
	 * WC_Product_Pack::get_addon_items()/get_addon_fields(); JWPB_DB's own
	 * addon table is not the read path Pack Builder itself uses) — so a
	 * pre-order addon flags the pack before a customer has picked anything.
	 *
	 * @param WC_Product_Pack $product
	 * @param array           $values Cart item data, or empty outside the cart.
	 * @return bool
	 */
	public static function has_pending_preorder_contents( $product, $values = array() ) {
		if ( ! $product instanceof WC_Product_Pack ) {
			return false;
		}

		$items = self::resolve_pack_rows( $values, $product );

		if ( empty( $items ) && empty( $values ) && $product->is_custom() && method_exists( $product, 'get_addon_items' ) ) {
			$items = array_map(
				function ( $row ) {
					$row['quantity'] = 1;
					return $row;
				},
				$product->get_addon_items()
			);
		}

		if ( empty( $items ) ) {
			return false;
		}

		return null !== self::collect_pending_release( $items );
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
