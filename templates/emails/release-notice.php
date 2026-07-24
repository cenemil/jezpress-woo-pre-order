<?php
/**
 * Pre-order release/dispatch notice email (HTML).
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

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	printf(
		/* translators: %s: order number */
		esc_html__( 'Good news — the pre-ordered item(s) in your order #%s have been released and your order is now being prepared for dispatch.', 'jezpress-woo-pre-order' ),
		esc_html( $order->get_order_number() )
	);
	?>
</p>

<?php if ( $additional_content ) : ?>
	<?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_footer', $email );
