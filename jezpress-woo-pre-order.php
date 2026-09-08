<?php
/**
 * Plugin Name: JezPress Woo Pre-Order
 * Plugin URI:  https://jezpress.com.au
 * Description: Pre-order support for WooCommerce — hold orders until a product's release date, with bundle-aware status for Pack Builder boxes.
 * Version:     1.8.0
 * Author:      Jezpress
 * Author URI:  https://jezpress.com.au
 * Text Domain: jezpress-woo-pre-order
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * Tested up to: 6.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JWPO_VERSION', '1.8.0' );
define( 'JWPO_DIR', plugin_dir_path( __FILE__ ) );
define( 'JWPO_URL', plugin_dir_url( __FILE__ ) );

register_activation_hook( __FILE__, 'jwpo_activate' );
register_deactivation_hook( __FILE__, 'jwpo_deactivate' );

function jwpo_activate() {}

function jwpo_deactivate() {
	$license = JWPO_License::get_instance();
	if ( $license ) {
		$license->cleanup();
	}

	wp_clear_scheduled_hook( 'jwpo_release_check' );
}

// Load updater and license early — NOT gated by plugins_loaded
// because WP cron auto-updates need the update hooks outside admin context.
require_once JWPO_DIR . 'includes/class-jwpo-updater.php';
require_once JWPO_DIR . 'includes/class-jwpo-license.php';

$_jwpo_license = JWPO_License::get_instance( __FILE__, 'jezpress-woo-pre-order', 'JezPress Woo Pre-Order' );
$_jwpo_lic_key = $_jwpo_license->get_license_key();

$_jwpo_updater = new JWPO_Updater( __FILE__ );
$_jwpo_updater->set_slug( 'jezpress-woo-pre-order' )
              ->set_api_url( 'https://updates.jezpress.com' );

if ( ! empty( $_jwpo_lic_key ) ) {
	$_jwpo_updater->set_license( $_jwpo_lic_key );
}
unset( $_jwpo_lic_key );

$_jwpo_updater->initialize();
unset( $_jwpo_updater );

add_action( 'plugins_loaded', 'jwpo_init', 20 );

function jwpo_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>JezPress Woo Pre-Order</strong> requires WooCommerce to be active.</p></div>';
		} );
		return;
	}

	require_once JWPO_DIR . 'includes/class-jwpo-settings.php';
	require_once JWPO_DIR . 'includes/class-jwpo-admin.php';

	$license = JWPO_License::get_instance();
	if ( $license ) {
		$license->init();
	}

	JWPO_Settings::init();
	JWPO_Admin::get_instance();

	if ( ! $license || ! $license->is_valid() ) {
		return;
	}

	require_once JWPO_DIR . 'includes/class-jwpo-order-status.php';
	require_once JWPO_DIR . 'includes/class-jwpo-product.php';
	require_once JWPO_DIR . 'includes/class-jwpo-cart.php';
	require_once JWPO_DIR . 'includes/class-jwpo-scheduler.php';
	require_once JWPO_DIR . 'includes/class-jwpo-emails.php';
	require_once JWPO_DIR . 'includes/class-jwpo-account.php';
	require_once JWPO_DIR . 'includes/class-jwpo-order-list.php';
	require_once JWPO_DIR . 'includes/class-jwpo-bundle-bridge.php';

	JWPO_Order_Status::init();
	JWPO_Product::init();
	JWPO_Cart::init();
	JWPO_Scheduler::init();
	JWPO_Emails::init();
	JWPO_Account::init();
	JWPO_Order_List::init();
	JWPO_Bundle_Bridge::init(); // No-ops if jezpress-woo-pack-builder isn't active.
}
