<?php
/**
 * JWPO_Settings — plugin option storage.
 *
 * Settings are stored as a single serialised array in wp_options under
 * the key 'jwpo_settings'. These are the defaults consumed by
 * JWPO_Product when a product doesn't override button/availability text,
 * and by JWPO_Scheduler for the release-check cron frequency.
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Settings {

	const OPTION_KEY = 'jwpo_settings';

	const VALID_FREQUENCIES = array( 'hourly', 'twicedaily', 'daily' );

	public static function init() {
		add_action( 'admin_post_jwpo_save_settings', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( $key, $default = '' ) {
		$settings = get_option( self::OPTION_KEY, array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Handle the settings form POST (admin-post.php).
	 *
	 * @return void
	 */
	public static function handle_save() {
		check_admin_referer( 'jwpo_save_settings' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'jezpress-woo-pre-order' ) );
		}

		$button_text = sanitize_text_field( wp_unslash( $_POST['default_button_text'] ?? '' ) );
		if ( '' === $button_text ) {
			$button_text = __( 'Pre-order Now', 'jezpress-woo-pre-order' );
		}

		$availability_text = sanitize_text_field( wp_unslash( $_POST['default_availability_text'] ?? '' ) );
		if ( '' === $availability_text ) {
			$availability_text = __( 'Available on {date}', 'jezpress-woo-pre-order' );
		}

		$frequency = sanitize_key( wp_unslash( $_POST['release_check_frequency'] ?? 'daily' ) );
		if ( ! in_array( $frequency, self::VALID_FREQUENCIES, true ) ) {
			$frequency = 'daily';
		}

		$settings                             = get_option( self::OPTION_KEY, array() );
		$settings['default_button_text']       = $button_text;
		$settings['default_availability_text'] = $availability_text;
		$settings['release_check_frequency']   = $frequency;
		update_option( self::OPTION_KEY, $settings );

		wp_safe_redirect(
			add_query_arg(
				array( 'tab' => 'settings', 'saved' => '1' ),
				admin_url( 'admin.php?page=jwpo-pre-order' )
			)
		);
		exit;
	}
}
