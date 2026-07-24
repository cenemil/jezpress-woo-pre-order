<?php
/**
 * JWPO_Scheduler — automatic Pre-order → Releasing transition by date,
 * plus the manual admin "release now" / "mark completed" order actions.
 *
 * Per spec, Releasing → Completed is manual-only — there is no automatic
 * completion, even after release. Admin confirms dispatch explicitly.
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Scheduler {

	const CRON_HOOK = 'jwpo_release_check';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_release_check' ) );

		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_order_actions' ) );
		add_action( 'woocommerce_order_action_jwpo_release_now', array( __CLASS__, 'action_release_now' ) );
		add_action( 'woocommerce_order_action_jwpo_mark_completed', array( __CLASS__, 'action_mark_completed' ) );
	}

	/**
	 * (Re)schedules the release-check cron event if the configured frequency
	 * has changed since it was last scheduled.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		$frequency = JWPO_Settings::get( 'release_check_frequency', 'daily' );
		$scheduled = wp_get_scheduled_event( self::CRON_HOOK );

		if ( $scheduled && $scheduled->schedule === $frequency ) {
			return;
		}

		if ( $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		wp_schedule_event( time(), $frequency, self::CRON_HOOK );
	}

	/**
	 * Cron callback — moves any Pre-order order whose stored release
	 * timestamp has passed into Releasing. Orders with no release date
	 * (open-ended pre-orders) are skipped and must be released manually.
	 *
	 * @return void
	 */
	public static function run_release_check() {
		$orders = wc_get_orders( array(
			'status' => 'jwpo-preorder',
			'limit'  => -1,
			'return' => 'objects',
		) );

		foreach ( $orders as $order ) {
			$raw = $order->get_meta( JWPO_Cart::ORDER_META_RELEASE_DATE );

			if ( '' === $raw ) {
				continue;
			}

			try {
				// Order-level release date is stored in UTC (see JWPO_Cart::maybe_hold_for_preorder()).
				$timestamp = ( new DateTime( $raw, new DateTimeZone( 'UTC' ) ) )->getTimestamp();
			} catch ( Exception $e ) {
				continue;
			}

			if ( $timestamp <= time() ) {
				self::release_order( $order, false );
			}
		}
	}

	/**
	 * @param WC_Order $order
	 * @param bool     $manual Whether this was triggered by an admin action
	 *                         rather than the scheduled date check.
	 * @return void
	 */
	public static function release_order( $order, $manual = false ) {
		if ( 'jwpo-preorder' !== $order->get_status() ) {
			return;
		}

		$note = $manual
			? __( 'Pre-order released manually by admin.', 'jezpress-woo-pre-order' )
			: __( 'Pre-order automatically released — scheduled release date reached.', 'jezpress-woo-pre-order' );

		$order->update_status( 'jwpo-releasing', $note );
	}

	/**
	 * @param array $actions
	 * @return array
	 */
	public static function add_order_actions( $actions ) {
		global $theorder;

		if ( ! $theorder instanceof WC_Order ) {
			return $actions;
		}

		$status = $theorder->get_status();

		if ( 'jwpo-preorder' === $status ) {
			$actions['jwpo_release_now'] = __( 'Pre-Order: release now (→ Releasing)', 'jezpress-woo-pre-order' );
		} elseif ( 'jwpo-releasing' === $status ) {
			$actions['jwpo_mark_completed'] = __( 'Pre-Order: mark completed (→ Completed)', 'jezpress-woo-pre-order' );
		}

		return $actions;
	}

	/**
	 * @param WC_Order $order
	 * @return void
	 */
	public static function action_release_now( $order ) {
		self::release_order( $order, true );
	}

	/**
	 * @param WC_Order $order
	 * @return void
	 */
	public static function action_mark_completed( $order ) {
		if ( 'jwpo-releasing' !== $order->get_status() ) {
			return;
		}

		$order->update_status( 'completed', __( 'Marked completed by admin after pre-order release.', 'jezpress-woo-pre-order' ) );
	}
}
