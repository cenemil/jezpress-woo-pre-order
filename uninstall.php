<?php
/**
 * Uninstall — remove all plugin options from the database.
 *
 * Product/order postmeta is intentionally left intact so existing orders
 * are not broken (same convention as jezpress-woo-pack-builder).
 *
 * @package Jezpress_Woo_Pre_Order
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'jwpo_settings' );

// Remove license option (same derivation as JWPO_License::__construct).
delete_option( 'jzwb_lic_' . substr( md5( 'jezpress-woo-pre-order' ), 0, 8 ) );

wp_clear_scheduled_hook( 'jwpo_license_check' );
wp_clear_scheduled_hook( 'jwpo_release_check' );
