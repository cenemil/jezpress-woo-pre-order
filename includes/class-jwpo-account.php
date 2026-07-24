<?php
/**
 * JWPO_Account — customer-facing My Account / order-received polish:
 * an "Expected release" notice on the order details view, and status
 * badge colouring for the Pre-order / Releasing statuses.
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Account {

	public static function init() {
		// Fires on both the order-received (thank you) page and the My
		// Account "View Order" page — both use the same core template.
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_release_notice' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_status_css' ) );
	}

	/**
	 * @param WC_Order $order
	 * @return void
	 */
	public static function render_release_notice( $order ) {
		$status = $order->get_status();

		if ( ! in_array( $status, array( 'jwpo-preorder', 'jwpo-releasing' ), true ) ) {
			return;
		}

		if ( 'jwpo-releasing' === $status ) {
			$message = __( 'The pre-ordered item(s) in this order have been released and it is now being prepared for dispatch.', 'jezpress-woo-pre-order' );
		} else {
			$raw = $order->get_meta( JWPO_Cart::ORDER_META_RELEASE_DATE );

			if ( '' !== $raw ) {
				try {
					$timestamp = ( new DateTime( $raw, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
					$message   = sprintf(
						/* translators: %s: expected release date */
						__( 'This order is being held as a pre-order. Expected release: %s.', 'jezpress-woo-pre-order' ),
						date_i18n( get_option( 'date_format' ), $timestamp )
					);
				} catch ( Exception $e ) {
					$message = __( 'This order is being held as a pre-order. We will notify you once it is released.', 'jezpress-woo-pre-order' );
				}
			} else {
				$message = __( 'This order is being held as a pre-order. We will notify you once it is released.', 'jezpress-woo-pre-order' );
			}
		}

		echo '<div class="woocommerce-info jwpo-account-notice">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Colours the custom status <mark> badges wherever WooCommerce renders
	 * them on the frontend (order-received page, My Account orders table,
	 * View Order page all reuse the same "status-{slug}" class convention).
	 *
	 * @return void
	 */
	public static function print_status_css() {
		if ( is_admin() ) {
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
