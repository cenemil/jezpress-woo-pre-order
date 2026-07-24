<?php
/**
 * Pre-order confirmation email (HTML).
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

$release_raw = $order->get_meta( JWPO_Cart::ORDER_META_RELEASE_DATE );
?>

<p>
	<?php
	printf(
		/* translators: %s: order number */
		esc_html__( 'Your order #%s has been received and is being held as a pre-order.', 'jezpress-woo-pre-order' ),
		esc_html( $order->get_order_number() )
	);
	?>
</p>

<p>
	<?php if ( '' !== $release_raw ) : ?>
		<?php
		try {
			$release_ts = ( new DateTime( $release_raw, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
			printf(
				/* translators: %s: expected release date */
				esc_html__( 'Expected release: %s. We will email you again as soon as your order is ready to ship.', 'jezpress-woo-pre-order' ),
				esc_html( date_i18n( get_option( 'date_format' ), $release_ts ) )
			);
		} catch ( Exception $e ) {
			esc_html_e( 'We will email you again as soon as your order is ready to ship.', 'jezpress-woo-pre-order' );
		}
		?>
	<?php else : ?>
		<?php esc_html_e( 'We will email you again as soon as your order is ready to ship.', 'jezpress-woo-pre-order' ); ?>
	<?php endif; ?>
</p>

<?php if ( $additional_content ) : ?>
	<?php echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) ); ?>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_footer', $email );
