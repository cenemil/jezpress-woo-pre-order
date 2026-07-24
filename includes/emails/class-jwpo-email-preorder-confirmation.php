<?php
/**
 * JWPO_Email_Preorder_Confirmation — sent to the customer when an order is
 * held in the "Pre-order" status awaiting a pre-order item's release.
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Email_Preorder_Confirmation extends WC_Email {

	public function __construct() {
		$this->id             = 'jwpo_preorder_confirmation';
		$this->customer_email = true;
		$this->title          = __( 'Pre-order Confirmation', 'jezpress-woo-pre-order' );
		$this->description    = __( 'Sent to the customer when their order is held awaiting release of a pre-order item.', 'jezpress-woo-pre-order' );

		$this->template_html  = 'emails/preorder-confirmation.php';
		$this->template_plain = 'emails/plain/preorder-confirmation.php';
		$this->template_base  = JWPO_DIR . 'templates/';

		$this->placeholders = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		add_action( 'woocommerce_order_status_jwpo-preorder', array( $this, 'trigger' ), 10, 2 );

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
		return __( '[{site_title}] Your pre-order {order_number} has been received', 'jezpress-woo-pre-order' );
	}

	/**
	 * @return string
	 */
	public function get_default_heading() {
		return __( 'Thank you for your pre-order!', 'jezpress-woo-pre-order' );
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
		return __( 'We\'ll send you another email as soon as your order is ready to ship.', 'jezpress-woo-pre-order' );
	}
}
