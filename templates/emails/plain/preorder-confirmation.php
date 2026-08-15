<?php
/**
 * Pre-order confirmation email (plain text).
 *
 * @package Jezpress_Woo_Pre_Order
 * @var WC_Order $order
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $sent_to_admin
 * @var bool     $plain_text
 * @var WC_Email $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

/* translators: %s: order number */
echo esc_html( sprintf( __( 'Your order #%s has been received and is being held as a pre-order.', 'jezpress-woo-pre-order' ), $order->get_order_number() ) ) . "\n\n";

$release_raw = $order->get_meta( JWPO_Cart::ORDER_META_RELEASE_DATE );

if ( '' !== $release_raw ) {
	try {
		$release_ts = ( new DateTime( $release_raw, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		/* translators: %s: expected release date */
		echo esc_html( sprintf( __( 'Expected release: %s. We will email you again as soon as your order is ready to ship.', 'jezpress-woo-pre-order' ), date_i18n( get_option( 'date_format' ), $release_ts ) ) ) . "\n\n";
	} catch ( Exception $e ) {
		echo esc_html__( 'We will email you again as soon as your order is ready to ship.', 'jezpress-woo-pre-order' ) . "\n\n";
	}
} else {
	echo esc_html__( 'We will email you again as soon as your order is ready to ship.', 'jezpress-woo-pre-order' ) . "\n\n";
}

if ( $additional_content ) {
	// Decoded, not escaped — see the matching note in plain/release-notice.php:
	// wptexturize() emits curly quotes as numeric HTML entities, which a plain-text
	// email would otherwise print literally.
	echo html_entity_decode( wp_strip_all_tags( wptexturize( $additional_content ) ), ENT_QUOTES, 'UTF-8' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

echo "----------\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------\n\n";

do_action( 'woocommerce_email_footer', $email );
