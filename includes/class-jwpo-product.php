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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
	}

	/**
	 * @return void
	 */
	public static function enqueue_frontend_assets() {
		if ( ! is_product() ) {
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
	 * Build the "Pre-order ⓘ" badge markup for a product — the info icon
	 * carries a title attribute with the release-date availability text
	 * (e.g. "Available on 25 December 2026") shown on hover, and is only
	 * included when there's an actual date/text to show.
	 *
	 * @param WC_Product|int $product
	 * @return string
	 */
	public static function get_badge_html( $product ) {
		$product = self::resolve_product( $product );

		if ( ! $product ) {
			return '';
		}

		$tooltip = self::get_availability_text( $product );
		$icon    = '';

		if ( '' !== $tooltip ) {
			$icon = ' <span class="jwpo-preorder-info" title="' . esc_attr( $tooltip ) . '">i</span>';
		}

		return esc_html( self::get_badge_text() ) . $icon;
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

		return $title . ' <span class="jwpo-preorder-badge">' . self::get_badge_html( $post_id ) . '</span>';
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
}
