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
 * IMPORTANT — why register_email_actions() exists:
 *
 * A WC_Email subclass attaches its own trigger in its constructor, and those
 * constructors only run when WC()->mailer() is instantiated. WooCommerce
 * instantiates the mailer *lazily*: WC_Emails::init_transactional_emails()
 * pre-registers WC_Emails::send_transactional_email() against a hardcoded
 * list of actions, and that callback is what loads the mailer and then fires
 * "<action>_notification".
 *
 * Custom order statuses aren't in that list, so without adding them via
 * woocommerce_email_actions nothing ever loads the mailer for them and the
 * triggers are never registered — the emails silently never send. That was
 * the case for both of this plugin's emails before 1.5.0. It bit hardest on:
 *
 *  - Checkout, because WC_Order::status_transition() fires
 *    woocommerce_order_status_processing *before*
 *    woocommerce_order_status_pending_to_processing, so JWPO_Cart flipped the
 *    order into jwpo-preorder while the mailer still hadn't been loaded.
 *  - The cron release check, where nothing in the request touches the mailer
 *    at all.
 *
 * Consequence for the email subclasses: they must hook the "_notification"
 * variant of the status action (as core WC emails do), never the raw
 * woocommerce_order_status_* action. Hooking the raw action can't work — the
 * add_action() call happens while that same action is already mid-dispatch.
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
		add_filter( 'woocommerce_email_actions', array( __CLASS__, 'register_email_actions' ) );

		// Priority 20 — after the customer's pre-order confirmation (10).
		add_action( 'woocommerce_order_status_jwpo-preorder_notification', array( __CLASS__, 'trigger_admin_new_order' ), 20, 2 );
	}

	/**
	 * Tell WooCommerce that this plugin's two status transitions are
	 * email-bearing actions, so WC_Emails::init_transactional_emails() wires
	 * them up at `init` like it does for core statuses. See the class docblock
	 * — without this the emails never send.
	 *
	 * @param array $actions
	 * @return array
	 */
	public static function register_email_actions( $actions ) {
		$actions[] = 'woocommerce_order_status_jwpo-preorder';
		$actions[] = 'woocommerce_order_status_jwpo-releasing';

		return $actions;
	}

	/**
	 * Sends the shop admin the standard WooCommerce "New order" notification for
	 * a pre-order.
	 *
	 * Core only fires that email on the pending → processing/completed/on-hold
	 * transitions, and since 1.5.0 a pre-order goes straight from pending to
	 * jwpo-preorder — so without this the shop would never hear about a new
	 * pre-order at all. Safe to call unconditionally: WC_Email_New_Order::trigger()
	 * no-ops when the order's `_new_order_email_sent` meta is already set, so an
	 * order that reaches Pre-order via Processing isn't notified twice.
	 *
	 * Runs inside WC_Emails::send_transactional_email(), so the mailer is
	 * already instantiated by the time this is called.
	 *
	 * @param int           $order_id
	 * @param WC_Order|bool $order
	 * @return void
	 */
	public static function trigger_admin_new_order( $order_id, $order = false ) {
		$emails = WC()->mailer()->get_emails();

		if ( isset( $emails['WC_Email_New_Order'] ) ) {
			$emails['WC_Email_New_Order']->trigger( $order_id, $order );
		}
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
