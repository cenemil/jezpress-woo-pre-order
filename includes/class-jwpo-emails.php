<?php
/**
 * JWPO_Emails — registers the pre-order confirmation and release notice
 * WC_Email subclasses so they appear in WooCommerce → Settings → Emails
 * like any core email (subject/heading/enable toggle editable by admin).
 *
 * The email subclass files extend WC_Email, so they're only required
 * lazily inside the woocommerce_email_classes filter — by the time that
 * fires, WC_Email is guaranteed to be loaded.
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Emails {

	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_emails' ) );
	}

	/**
	 * @param array $email_classes
	 * @return array
	 */
	public static function register_emails( $email_classes ) {
		require_once JWPO_DIR . 'includes/emails/class-jwpo-email-preorder-confirmation.php';
		require_once JWPO_DIR . 'includes/emails/class-jwpo-email-release-notice.php';

		$email_classes['JWPO_Email_Preorder_Confirmation'] = new JWPO_Email_Preorder_Confirmation();
		$email_classes['JWPO_Email_Release_Notice']        = new JWPO_Email_Release_Notice();

		return $email_classes;
	}
}
