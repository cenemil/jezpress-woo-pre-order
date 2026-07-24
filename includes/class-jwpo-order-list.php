<?php
/**
 * JWPO_Order_List — adds a "Release Date" column to the WooCommerce orders
 * list and colours the Pre-order / Releasing status badges.
 *
 * The status filter dropdown and the existing Status column already surface
 * the new statuses automatically (WooCommerce reads wc_get_order_statuses(),
 * which JWPO_Order_Status::add_statuses() extends) — no extra code needed
 * for that part of the spec. This class only adds the release-date column.
 *
 * Supports both the legacy post-based orders table and HPOS (custom orders
 * table) — WooCommerce exposes different column hooks for each.
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Order_List {

	const COLUMN_KEY = 'jwpo_release_date';

	public static function init() {
		if ( self::hpos_enabled() ) {
			add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_column' ) );
			add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_column_hpos' ), 10, 2 );
		} else {
			add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
			add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_legacy' ), 10, 2 );
		}

		add_action( 'admin_head', array( __CLASS__, 'print_status_css' ) );
	}

	/**
	 * @return bool
	 */
	private static function hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * @param array $columns
	 * @return array
	 */
	public static function add_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$new_columns[ self::COLUMN_KEY ] = __( 'Release Date', 'jezpress-woo-pre-order' );
			}
		}

		if ( ! isset( $new_columns[ self::COLUMN_KEY ] ) ) {
			$new_columns[ self::COLUMN_KEY ] = __( 'Release Date', 'jezpress-woo-pre-order' );
		}

		return $new_columns;
	}

	/**
	 * HPOS: WooCommerce passes the WC_Order object directly.
	 *
	 * @param string   $column
	 * @param WC_Order $order
	 * @return void
	 */
	public static function render_column_hpos( $column, $order ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		self::render_release_cell( $order );
	}

	/**
	 * Legacy: WooCommerce passes the post ID.
	 *
	 * @param string $column
	 * @param int    $post_id
	 * @return void
	 */
	public static function render_column_legacy( $column, $post_id ) {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$order = wc_get_order( $post_id );

		if ( $order ) {
			self::render_release_cell( $order );
		}
	}

	/**
	 * @param WC_Order $order
	 * @return void
	 */
	private static function render_release_cell( $order ) {
		$status = $order->get_status();

		if ( ! in_array( $status, array( 'jwpo-preorder', 'jwpo-releasing' ), true ) ) {
			echo '&ndash;';
			return;
		}

		$raw = $order->get_meta( JWPO_Cart::ORDER_META_RELEASE_DATE );

		if ( '' === $raw ) {
			esc_html_e( 'Manual release', 'jezpress-woo-pre-order' );
			return;
		}

		try {
			$timestamp = ( new DateTime( $raw, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
			echo esc_html( date_i18n( get_option( 'date_format' ), $timestamp ) );
		} catch ( Exception $e ) {
			echo '&ndash;';
		}
	}

	/**
	 * @return void
	 */
	public static function print_status_css() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		if ( 'shop_order' !== $screen->id && 'woocommerce_page_wc-orders' !== $screen->id ) {
			return;
		}
		?>
		<style>
			mark.order-status.status-jwpo-preorder { background: #fef3c7; color: #92400e; }
			mark.order-status.status-jwpo-releasing { background: #dbeafe; color: #1e40af; }
		</style>
		<?php
	}
}
