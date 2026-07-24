<?php
/**
 * JWPO_Order_Status — registers the custom "Pre-order" and "Releasing"
 * WooCommerce order statuses.
 *
 * Registration happens through the standard WC_Order_Status extension
 * pattern (register_post_status + wc_order_statuses filter), which is
 * HPOS-safe — WooCommerce reads this same registry regardless of whether
 * orders are stored as posts or in the custom orders tables.
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Order_Status {

	const STATUS_PREORDER  = 'wc-jwpo-preorder';
	const STATUS_RELEASING = 'wc-jwpo-releasing';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_statuses' ) );
	}

	/**
	 * @return void
	 */
	public static function register_statuses() {
		register_post_status( self::STATUS_PREORDER, array(
			'label'                     => _x( 'Pre-order', 'Order status', 'jezpress-woo-pre-order' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop(
				'Pre-order <span class="count">(%s)</span>',
				'Pre-order <span class="count">(%s)</span>',
				'jezpress-woo-pre-order'
			),
		) );

		register_post_status( self::STATUS_RELEASING, array(
			'label'                     => _x( 'Releasing', 'Order status', 'jezpress-woo-pre-order' ),
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop(
				'Releasing <span class="count">(%s)</span>',
				'Releasing <span class="count">(%s)</span>',
				'jezpress-woo-pre-order'
			),
		) );
	}

	/**
	 * Insert the new statuses right after Processing so they read naturally
	 * in the order-status dropdown and order list filter.
	 *
	 * @param array $order_statuses
	 * @return array
	 */
	public static function add_statuses( $order_statuses ) {
		$new_statuses = array();

		foreach ( $order_statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;

			if ( 'wc-processing' === $key ) {
				$new_statuses[ self::STATUS_PREORDER ]  = _x( 'Pre-order', 'Order status', 'jezpress-woo-pre-order' );
				$new_statuses[ self::STATUS_RELEASING ] = _x( 'Releasing', 'Order status', 'jezpress-woo-pre-order' );
			}
		}

		return $new_statuses;
	}

	/**
	 * @param string $status Unprefixed status slug, e.g. 'jwpo-preorder'.
	 * @return string Human-readable label.
	 */
	public static function label( $status ) {
		$labels = array(
			'jwpo-preorder'  => __( 'Pre-order', 'jezpress-woo-pre-order' ),
			'jwpo-releasing' => __( 'Releasing', 'jezpress-woo-pre-order' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}
}
