<?php
/**
 * JWPO_Email_Release_Notice — sent to the customer when their order moves
 * from "Pre-order" to "Releasing" (i.e. it's ready to ship).
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Email_Release_Notice extends WC_Email {

	public function __construct() {
		$this->id             = 'jwpo_release_notice';
		$this->customer_email = true;
		$this->title          = __( 'Pre-order Release Notice', 'jezpress-woo-pre-order' );
		$this->description    = __( 'Sent to the customer when their pre-ordered item is released and their order is ready to ship.', 'jezpress-woo-pre-order' );

		$this->template_html  = 'emails/release-notice.php';
		$this->template_plain = 'emails/plain/release-notice.php';
		$this->template_base  = JWPO_DIR . 'templates/';

		$this->placeholders = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		add_action( 'woocommerce_order_status_jwpo-releasing', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * @param int           $order_id
	 * @param WC_Order|bool $order
	 * @return void
	 */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( $order instanceof WC_Order ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * @return string
	 */
	public function get_default_subject() {
		return __( '[{site_title}] Your order {order_number} is ready to ship', 'jezpress-woo-pre-order' );
	}

	/**
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Your pre-order has been released!', 'jezpress-woo-pre-order' );
	}

	/**
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'              => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => false,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * @return string
	 */
	public function get_default_additional_content() {
		return __( 'Your order is now being prepared for dispatch — you\'ll receive a separate shipping notification once it\'s on its way.', 'jezpress-woo-pre-order' );
	}
}
