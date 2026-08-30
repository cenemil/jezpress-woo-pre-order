<?php
/**
 * JWPO_Product — per-product pre-order meta, product data tab, and
 * frontend button/availability text overrides.
 *
 * A product is "pre-order active" when _jwpo_preorder_enabled is 'yes' AND
 * either no release date is set, or the release date is still in the future.
 * Once the release date passes, the product reverts to normal Add to Cart
 * behaviour automatically — no admin action needed to "turn it off".
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Product {

	const META_ENABLED           = '_jwpo_preorder_enabled';
	const META_RELEASE_DATE      = '_jwpo_release_date';
	const META_BUTTON_TEXT       = '_jwpo_button_text';
	const META_AVAILABILITY_TEXT = '_jwpo_availability_text';

	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_data_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_meta' ) );

		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'add_to_cart_text' ), 10, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'add_to_cart_text' ), 10, 2 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_availability_notice' ), 11 );
		add_filter( 'the_title', array( __CLASS__, 'maybe_append_preorder_badge_to_title' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'render_cart_item_badge' ), 10, 3 );
		add_filter( 'woocommerce_product_get_image', array( __CLASS__, 'add_preorder_badge_to_loop_image' ), 10, 2 );
		add_filter( 'woocommerce_single_product_image_thumbnail_html', array( __CLASS__, 'add_preorder_badge_to_gallery_image' ), 10, 2 );
		add_filter( 'render_block_woocommerce/product-image', array( __CLASS__, 'add_preorder_badge_to_product_image_block' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
		add_shortcode( 'jwpo_preorder_badge', array( __CLASS__, 'render_preorder_badge_shortcode' ) );
	}

	/**
	 * @return void
	 */
	public static function enqueue_frontend_assets() {
		$post = is_singular() ? get_post() : null;
		$has_badge_shortcode = $post && has_shortcode( $post->post_content, 'jwpo_preorder_badge' );

		if ( ! is_woocommerce() && ! is_cart() && ! is_checkout() && ! $has_badge_shortcode ) {
			return;
		}

		wp_enqueue_style( 'jwpo-frontend', JWPO_URL . 'assets/css/frontend.css', array(), JWPO_VERSION );
	}

	/**
	 * Short inline badge text used next to a product name/price when that
	 * specific product is currently in pre-order mode.
	 *
	 * @return string
	 */
	public static function get_badge_text() {
		return apply_filters( 'jwpo_preorder_badge_text', __( 'Pre-order', 'jezpress-woo-pre-order' ) );
	}

	/**
	 * Build the "Pre-order" badge markup for a product — carries a title
	 * attribute with the release-date availability text (e.g. "Available on
	 * 25 December 2026") shown on hover, only included when there's an
	 * actual date/text to show.
	 *
	 * @param WC_Product|int $product
	 * @param string         $class CSS class for the badge element.
	 * @param string         $style Optional inline `style` attribute value.
	 * @return string
	 */
	public static function get_badge_html( $product, $class = 'jwpo-preorder-badge', $style = '' ) {
		$product = self::resolve_product( $product );

		if ( ! $product ) {
			return '';
		}

		return self::build_badge_html( self::get_availability_text( $product ), $class, $style );
	}

	/**
	 * Builds the "Pre-order" badge markup from an already-resolved tooltip
	 * string, for callers (e.g. JWPO_Bundle_Bridge) that need a badge whose
	 * tooltip isn't tied to a single product's own availability text.
	 *
	 * @param string $tooltip
	 * @param string $class CSS class for the badge element.
	 * @param string $style Optional inline `style` attribute value.
	 * @return string
	 */
	public static function build_badge_html( $tooltip, $class = 'jwpo-preorder-badge', $style = '' ) {
		$title = '' !== $tooltip ? ' title="' . esc_attr( $tooltip ) . '"' : '';
		$style = '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '';

		return '<span class="' . esc_attr( $class ) . '"' . $title . $style . '>' . esc_html( self::get_badge_text() ) . '</span>';
	}

	/**
	 * Appends the pre-order badge right after the product title on a single
	 * product page — only when that specific product (plain product or a
	 * Pack Builder pack's own base product) has pre-order enabled and active.
	 *
	 * Scoped tightly to the single product's own main-loop title so it never
	 * touches admin lists, menus, widgets, or other posts/products rendered
	 * on the same page (e.g. related products).
	 *
	 * @param string $title
	 * @param int    $post_id
	 * @return string
	 */
	public static function maybe_append_preorder_badge_to_title( $title, $post_id ) {
		if ( is_admin() || ! is_singular( 'product' ) || ! in_the_loop() || ! is_main_query() ) {
			return $title;
		}

		if ( (int) $post_id !== get_the_ID() ) {
			return $title;
		}

		if ( ! self::is_preorder_active( $post_id ) ) {
			return $title;
		}

		return $title . ' ' . self::get_badge_html( $post_id );
	}

	/**
	 * [jwpo_preorder_badge] — standalone badge for dropping into arbitrary
	 * post/page content, independent of the image/title/cart hooks above.
	 * Renders nothing when the product isn't currently pre-order active
	 * (see is_preorder_active()), so pages can leave the shortcode in place
	 * across a product's release date without an admin removing it by hand.
	 *
	 * `id` defaults to the product of the current single product page (via
	 * the global $product) when omitted, so `[jwpo_preorder_badge]` alone
	 * works when embedded in a single product template/description; on a
	 * plain post or page an explicit `id="123"` is required.
	 *
	 * Defaults to the `.jwpo-preorder-image-badge` corner-tag look rather
	 * than the `.jwpo-preorder-badge` tooltip pill, per the visual style
	 * requested for this shortcode — but that class is `position: absolute`
	 * in frontend.css, styled to sit inside the `.jwpo-preorder-image`
	 * wrapper added around a product thumbnail. Dropped standalone it would
	 * position against whatever ancestor happens to be `position`ed, so the
	 * default style pins it back to normal inline flow; pass `style=""` to
	 * get the raw absolute-positioned tag for use inside your own wrapper.
	 *
	 * @param array $atts
	 * @return string
	 */
	public static function render_preorder_badge_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'    => 0,
				'class' => 'jwpo-preorder-image-badge',
				'style' => 'position:static;display:inline-block;',
			),
			$atts,
			'jwpo_preorder_badge'
		);

		$product_id = (int) $atts['id'];

		if ( ! $product_id ) {
			global $product;
			$product_id = $product instanceof WC_Product ? $product->get_id() : 0;
		}

		if ( ! $product_id || ! self::is_preorder_active( $product_id ) ) {
			return '';
		}

		return self::get_badge_html( $product_id, sanitize_html_class( $atts['class'] ), $atts['style'] );
	}

	// -------------------------------------------------------------------------
	// Meta readers
	// -------------------------------------------------------------------------

	/**
	 * Whether the product currently has an admin-enabled pre-order flag,
	 * regardless of release date.
	 *
	 * @param WC_Product|int $product
	 * @return bool
	 */
	public static function is_enabled( $product ) {
		$product = self::resolve_product( $product );
		return $product && 'yes' === $product->get_meta( self::META_ENABLED );
	}

	/**
	 * Whether the product should currently behave as a pre-order — enabled
	 * AND (no release date set OR release date still in the future).
	 *
	 * @param WC_Product|int $product
	 * @return bool
	 */
	public static function is_preorder_active( $product ) {
		$product = self::resolve_product( $product );

		if ( ! $product || ! self::is_enabled( $product ) ) {
			return false;
		}

		$release_timestamp = self::get_release_timestamp( $product );

		if ( ! $release_timestamp ) {
			return true;
		}

		return $release_timestamp > time();
	}

	/**
	 * @param WC_Product|int $product
	 * @return string Raw MySQL datetime string, or ''.
	 */
	public static function get_release_date_raw( $product ) {
		$product = self::resolve_product( $product );
		return $product ? (string) $product->get_meta( self::META_RELEASE_DATE ) : '';
	}

	/**
	 * Release date is stored as a "Y-m-d H:i:s" wall-clock string in the
	 * site's configured timezone (see save_meta()). This resolves it to a
	 * true Unix timestamp for comparison against time().
	 *
	 * @param WC_Product|int $product
	 * @return int Unix timestamp, or 0 if not set/invalid.
	 */
	public static function get_release_timestamp( $product ) {
		$raw = self::get_release_date_raw( $product );

		if ( '' === $raw ) {
			return 0;
		}

		try {
			$datetime = new DateTime( $raw, wp_timezone() );
		} catch ( Exception $e ) {
			return 0;
		}

		return $datetime->getTimestamp();
	}

	/**
	 * @param WC_Product|int $product
	 * @return string
	 */
	public static function get_button_text( $product ) {
		$product = self::resolve_product( $product );
		$override = $product ? (string) $product->get_meta( self::META_BUTTON_TEXT ) : '';

		if ( '' !== $override ) {
			return $override;
		}

		return JWPO_Settings::get( 'default_button_text', __( 'Pre-order Now', 'jezpress-woo-pre-order' ) );
	}

	/**
	 * @param WC_Product|int $product
	 * @return string Rendered availability text with {date} replaced.
	 */
	public static function get_availability_text( $product ) {
		$product  = self::resolve_product( $product );
		$override = $product ? (string) $product->get_meta( self::META_AVAILABILITY_TEXT ) : '';

		$template = '' !== $override
			? $override
			: JWPO_Settings::get( 'default_availability_text', __( 'Available on {date}', 'jezpress-woo-pre-order' ) );

		$timestamp = self::get_release_timestamp( $product );

		if ( ! $timestamp ) {
			return '';
		}

		$formatted = date_i18n( get_option( 'date_format' ), $timestamp );

		return str_replace( '{date}', $formatted, $template );
	}

	/**
	 * @param WC_Product|int $product
	 * @return WC_Product|null
	 */
	private static function resolve_product( $product ) {
		if ( $product instanceof WC_Product ) {
			return $product;
		}

		if ( is_numeric( $product ) ) {
			$loaded = wc_get_product( $product );
			return $loaded ? $loaded : null;
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Product editor — data tab
	// -------------------------------------------------------------------------

	/**
	 * @param array $tabs
	 * @return array
	 */
	public static function add_data_tab( $tabs ) {
		$tabs['jwpo_preorder'] = array(
			'label'    => __( 'Pre-order', 'jezpress-woo-pre-order' ),
			'target'   => 'jwpo_preorder_data',
			'class'    => array(),
			'priority' => 21,
		);

		return $tabs;
	}

	/**
	 * @return void
	 */
	public static function render_data_panel() {
		global $post;

		$product = wc_get_product( $post->ID );
		?>
		<div id="jwpo_preorder_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox( array(
					'id'          => '_jwpo_preorder_enabled',
					'label'       => __( 'Enable pre-order', 'jezpress-woo-pre-order' ),
					'description' => __( 'Show a pre-order button and availability message instead of the normal Add to Cart button.', 'jezpress-woo-pre-order' ),
					'value'       => $product ? $product->get_meta( self::META_ENABLED ) : 'no',
				) );

				// Rendered manually rather than via woocommerce_wp_text_input() so the
				// help tip sits after the input — WooCommerce places it between the
				// label and the field, which crowds the wide datetime-local control.
				$release_date_value = $product ? self::to_datetime_local( $product->get_meta( self::META_RELEASE_DATE ) ) : '';

				// Earliest selectable release date: 1 day ahead of "now" in site time,
				// rounded up to the next quarter hour so it lines up with the 15 minute
				// step below (the browser measures steps from the min value).
				$release_date_min_ts = (int) ceil( ( time() + DAY_IN_SECONDS ) / ( 15 * MINUTE_IN_SECONDS ) ) * ( 15 * MINUTE_IN_SECONDS );
				$release_date_min    = wp_date( 'Y-m-d\TH:i', $release_date_min_ts );

				// Both constraints are dropped when an already-saved date would fail them,
				// otherwise HTML5 validation blocks saving unrelated product changes.
				$release_date_is_legacy = '' !== $release_date_value &&
					( $release_date_value < $release_date_min || 0 !== ( (int) substr( $release_date_value, 14, 2 ) % 15 ) );
				?>
				<p class="form-field _jwpo_release_date_field">
					<label for="_jwpo_release_date"><?php esc_html_e( 'Release date', 'jezpress-woo-pre-order' ); ?></label>
					<input
						type="datetime-local"
						class="short"
						style="float: left;"
						name="_jwpo_release_date"
						id="_jwpo_release_date"
						value="<?php echo esc_attr( $release_date_value ); ?>"
						<?php if ( ! $release_date_is_legacy ) : ?>
							min="<?php echo esc_attr( $release_date_min ); ?>"
							step="900"
						<?php endif; ?>
					/>
					<?php echo wc_help_tip( __( 'When this passes, the product automatically reverts to normal Add to Cart behaviour. Leave blank for an open-ended pre-order.', 'jezpress-woo-pre-order' ) ); ?>
				</p>
				<?php

				woocommerce_wp_text_input( array(
					'id'          => '_jwpo_button_text',
					'label'       => __( 'Button text override', 'jezpress-woo-pre-order' ),
					'description' => __( 'Leave blank to use the default set on the Pre-Orders settings screen.', 'jezpress-woo-pre-order' ),
					'desc_tip'    => true,
					'value'       => $product ? $product->get_meta( self::META_BUTTON_TEXT ) : '',
				) );

				woocommerce_wp_text_input( array(
					'id'          => '_jwpo_availability_text',
					'label'       => __( 'Availability text override', 'jezpress-woo-pre-order' ),
					'description' => __( 'Use {date} as a placeholder. Leave blank to use the default.', 'jezpress-woo-pre-order' ),
					'desc_tip'    => true,
					'value'       => $product ? $product->get_meta( self::META_AVAILABILITY_TEXT ) : '',
				) );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Convert a stored "Y-m-d H:i:s" wall-clock string to the value expected
	 * by an <input type="datetime-local"> field (Y-m-d\TH:i). No timezone
	 * conversion needed — both are the same site-local wall-clock value.
	 *
	 * @param string $stored_datetime
	 * @return string
	 */
	private static function to_datetime_local( $stored_datetime ) {
		if ( '' === $stored_datetime ) {
			return '';
		}

		return str_replace( ' ', 'T', substr( $stored_datetime, 0, 16 ) );
	}

	/**
	 * @param int $post_id
	 * @return void
	 */
	public static function save_meta( $post_id ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) ||
			 ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		$enabled = isset( $_POST['_jwpo_preorder_enabled'] ) ? 'yes' : 'no';
		$product->update_meta_data( self::META_ENABLED, $enabled );

		// The datetime-local input submits "Y-m-d\TH:i" in the browser's local
		// clock — we treat that wall-clock value as the site's timezone (see
		// get_release_timestamp()) and store it as "Y-m-d H:i:s", unconverted.
		$release_date_input = isset( $_POST['_jwpo_release_date'] ) ? sanitize_text_field( wp_unslash( $_POST['_jwpo_release_date'] ) ) : '';
		$release_date_mysql = '';

		if ( '' !== $release_date_input ) {
			try {
				$datetime = new DateTime( $release_date_input, wp_timezone() );
				$release_date_mysql = $datetime->format( 'Y-m-d H:i:s' );
			} catch ( Exception $e ) {
				$release_date_mysql = '';
			}
		}
		$product->update_meta_data( self::META_RELEASE_DATE, $release_date_mysql );

		$button_text = isset( $_POST['_jwpo_button_text'] ) ? sanitize_text_field( wp_unslash( $_POST['_jwpo_button_text'] ) ) : '';
		$product->update_meta_data( self::META_BUTTON_TEXT, $button_text );

		$availability_text = isset( $_POST['_jwpo_availability_text'] ) ? sanitize_text_field( wp_unslash( $_POST['_jwpo_availability_text'] ) ) : '';
		$product->update_meta_data( self::META_AVAILABILITY_TEXT, $availability_text );

		$product->save();
	}

	// -------------------------------------------------------------------------
	// Frontend
	// -------------------------------------------------------------------------

	/**
	 * @param string     $text
	 * @param WC_Product $product
	 * @return string
	 */
	public static function add_to_cart_text( $text, $product ) {
		if ( self::is_preorder_active( $product ) ) {
			return self::get_button_text( $product );
		}

		return $text;
	}

	/**
	 * @return void
	 */
	public static function render_availability_notice() {
		global $product;

		if ( ! $product instanceof WC_Product || ! self::is_preorder_active( $product ) ) {
			return;
		}

		$text = self::get_availability_text( $product );

		if ( '' === $text ) {
			return;
		}

		echo '<p class="jwpo-availability-notice">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Adds a "Pre-order" badge next to a cart/checkout line item's name when
	 * that item's own product — or, for a variation, its parent post, since
	 * pre-order meta only ever lives there — is currently pre-order active.
	 *
	 * Restricted to the cart and checkout pages themselves: this filter also
	 * fires in the mini-cart widget, which can render on any page and isn't
	 * part of this indicator's scope.
	 *
	 * A Pack Builder pack's own product can independently carry pre-order
	 * meta (see maybe_append_preorder_badge_to_title()); pre-order state
	 * coming from a pack's *contents* is handled separately by
	 * JWPO_Bundle_Bridge, so both badges can appear on the same line if both
	 * apply.
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

		if ( ! $product instanceof WC_Product ) {
			return $name;
		}

		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		if ( ! self::is_preorder_active( $parent_id ) ) {
			return $name;
		}

		return $name . ' ' . self::get_badge_html( $parent_id );
	}

	/**
	 * Wraps a product's thumbnail image — shop loop, category/tag archives,
	 * related/upsell/cross-sell grids, "recently viewed" widgets — with a
	 * positioning class and an image-corner badge when that product (or, for
	 * a variation, its parent, since pre-order meta only ever lives there) is
	 * currently pre-order active.
	 *
	 * Hooked on `woocommerce_product_get_image`, which WC_Product::get_image()
	 * applies wherever `$product->get_image()` is called — including cart/mini-
	 * cart thumbnails, which already carry the name-based badge from
	 * render_cart_item_badge(). Scoped to is_woocommerce() (shop, product
	 * taxonomy archives, single product) so it doesn't add an unstyled badge
	 * to cart/checkout thumbnails or to widgets rendered on unrelated pages —
	 * frontend.css is only enqueued in that same set of contexts.
	 *
	 * @param string     $image
	 * @param WC_Product $product
	 * @return string
	 */
	public static function add_preorder_badge_to_loop_image( $image, $product ) {
		if ( ! is_woocommerce() || ! $product instanceof WC_Product ) {
			return $image;
		}

		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		if ( ! self::is_preorder_active( $parent_id ) ) {
			return $image;
		}

		// The wp-admin products list (edit.php?post_type=product) reuses the
		// global $wp_query for its own listing query, which spuriously makes
		// is_woocommerce() report true there too (WP_Query's is_post_type_archive
		// flag is set from the query vars alone, admin or not) — so this badge
		// renders on that screen's thumbnail column as well. frontend.css never
		// loads there (wp_enqueue_scripts doesn't fire in wp-admin), so the
		// absolute positioning that stacks the badge on the front end doesn't
		// apply; force it onto its own line with an inline style instead of
		// leaving it to sit beside the thumbnail unstyled.
		$style = is_admin() ? 'display:block;' : '';

		return '<span class="jwpo-preorder-image jwpo-preorder-image--loop">' . $image
			. self::get_badge_html( $parent_id, 'jwpo-preorder-image-badge', $style ) . '</span>';
	}

	/**
	 * Same treatment as add_preorder_badge_to_loop_image() for the single
	 * product page's main gallery image, which core renders outside
	 * get_image() (see templates/single-product/product-image.php) via its
	 * own `woocommerce_single_product_image_thumbnail_html` filter, so it
	 * needs a separate hook.
	 *
	 * The class is added onto WC's own `.woocommerce-product-gallery__image`
	 * div in place, rather than adding a new wrapping element, because
	 * single-product.js selects that div as a *direct* child of
	 * `.woocommerce-product-gallery__wrapper` for the zoom/lightbox — an
	 * extra wrapper would break that. If a future WC version changes this
	 * markup and the string replace no longer matches, this silently no-ops
	 * rather than emitting broken HTML.
	 *
	 * @param string $html
	 * @param int    $attachment_id
	 * @return string
	 */
	public static function add_preorder_badge_to_gallery_image( $html, $attachment_id ) {
		global $product;

		if ( ! $product instanceof WC_Product || ! self::is_preorder_active( $product ) ) {
			return $html;
		}

		$html = preg_replace(
			'/class="woocommerce-product-gallery__image/',
			'class="jwpo-preorder-image woocommerce-product-gallery__image',
			$html,
			1
		);

		return str_replace( '</div>', self::get_badge_html( $product, 'jwpo-preorder-image-badge' ) . '</div>', $html );
	}

	/**
	 * Badge for the Woo Blocks "Product Image" block (`woocommerce/product-image`)
	 * — what a block-theme shop/archive/Product Collection actually renders,
	 * as opposed to the classic `loop/thumbnail.php` template.
	 *
	 * `ProductImage::render_image()` only calls `$product->get_image()` (and
	 * so only fires `woocommerce_product_get_image`, handled above) when the
	 * product has a real featured image; for a product with none it returns
	 * `wc_placeholder_img()` directly, bypassing that filter entirely. So
	 * without this separate hook, a placeholder-thumbnail product never gets
	 * a badge here even though the same product gets one in the classic loop.
	 *
	 * The block's own wrapper div already carries
	 * `.wc-block-components-product-image` (`position: relative` in WC's
	 * block CSS), so the badge just needs appending before that div's closing
	 * tag — no extra positioning wrapper needed the way the classic loop
	 * image (which has no such ancestor) requires one.
	 *
	 * The `jwpo-preorder-image-badge` string check guards against the product
	 * *having* a real image: `render_image()` reaches `get_image()` in that
	 * case, so `add_preorder_badge_to_loop_image()` already added one inside
	 * $block_content and adding a second here would double it up.
	 *
	 * @param string   $block_content
	 * @param array    $parsed_block
	 * @param WP_Block $instance
	 * @return string
	 */
	public static function add_preorder_badge_to_product_image_block( $block_content, $parsed_block, $instance ) {
		$post_id = isset( $instance->context['postId'] ) ? $instance->context['postId'] : 0;
		$product = $post_id ? wc_get_product( $post_id ) : null;

		if ( ! is_woocommerce() || ! $product instanceof WC_Product || false !== strpos( $block_content, 'jwpo-preorder-image-badge' ) ) {
			return $block_content;
		}

		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();

		if ( ! self::is_preorder_active( $parent_id ) || '</div>' !== substr( $block_content, -6 ) ) {
			return $block_content;
		}

		return substr( $block_content, 0, -6 ) . self::get_badge_html( $parent_id, 'jwpo-preorder-image-badge' ) . '</div>';
	}
}
