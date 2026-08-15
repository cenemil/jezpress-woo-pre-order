<?php
/**
 * Pre-order release/dispatch notice email (plain text).
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
echo esc_html( sprintf( __( 'Good news — the pre-ordered item(s) in your order #%s have been released and your order is now being prepared for dispatch.', 'jezpress-woo-pre-order' ), $order->get_order_number() ) ) . "\n\n";

if ( $additional_content ) {
	// wptexturize() emits curly quotes as *numeric HTML entities*, so a plain-text
	// email printed them literally ("You&#8217;ll receive..."). Decoding afterwards
	// restores the real UTF-8 character; the equivalent core WC plain template has
	// the same flaw, and esc_html() is dropped because re-escaping here would
	// simply re-encode what was just decoded. wp_strip_all_tags() has already
	// removed any markup, and there is no HTML context to escape into.
	echo html_entity_decode( wp_strip_all_tags( wptexturize( $additional_content ) ), ENT_QUOTES, 'UTF-8' ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

echo "----------\n\n";

do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

echo "\n----------\n\n";

do_action( 'woocommerce_email_footer', $email );
