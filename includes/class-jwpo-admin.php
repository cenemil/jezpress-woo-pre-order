<?php
/**
 * JWPO Admin
 *
 * Admin menu, tab navigation, and settings page rendering for Pre-Order.
 *
 * @package Jezpress_Woo_Pre_Order
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JWPO_Admin {

	/** @var JWPO_Admin|null Singleton instance. */
	private static $instance = null;

	/** @var string Hook suffix returned by add_submenu_page(). */
	private $page_hook = '';

	/**
	 * @return JWPO_Admin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * @return void
	 */
	public function register_menu() {
		$this->page_hook = add_submenu_page(
			'woocommerce',
			__( 'Pre-Orders', 'jezpress-woo-pre-order' ),
			__( 'Pre-Orders', 'jezpress-woo-pre-order' ),
			'manage_woocommerce',
			'jwpo-pre-order',
			array( $this, 'render_page' )
		);
	}

	/**
	 * @param string $hook
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'jwpo-admin',
			JWPO_URL . 'assets/css/admin.css',
			array(),
			JWPO_VERSION
		);
	}

	// -------------------------------------------------------------------------
	// Page rendering
	// -------------------------------------------------------------------------

	/**
	 * @return void
	 */
	public function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		// phpcs:enable

		$tabs = array(
			'settings'  => __( 'Settings', 'jezpress-woo-pre-order' ),
			'reporting' => __( 'Reporting', 'jezpress-woo-pre-order' ),
			'license'   => __( 'License', 'jezpress-woo-pre-order' ),
		);

		$license     = JWPO_License::get_instance();
		$is_licensed = $license && $license->is_valid();

		if ( ! $is_licensed && 'license' !== $current_tab ) {
			$current_tab = 'license';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pre-Orders', 'jezpress-woo-pre-order' ); ?></h1>

			<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_key => $tab_label ) :
					if ( 'license' !== $tab_key && ! $is_licensed ) {
						continue;
					}
					$tab_url   = admin_url( 'admin.php?page=jwpo-pre-order&tab=' . $tab_key );
					$is_active = ( $current_tab === $tab_key );
					?>
					<a href="<?php echo esc_url( $tab_url ); ?>"
					   class="nav-tab<?php echo $is_active ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( 'license' === $current_tab && $license ) {
				$license->render_tab_content();
			} elseif ( 'reporting' === $current_tab ) {
				$this->render_reporting_tab();
			} elseif ( 'settings' === $current_tab ) {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	private function render_settings_tab() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Pre-Orders', 'jezpress-woo-pre-order' ) . ':</strong> ' . esc_html__( 'Settings saved.', 'jezpress-woo-pre-order' ) . '</p></div>';
		}
		// phpcs:enable
		?>

		<div class="admin-page-wrap">
			<div class="admin-page-card">
				<h2 class="admin-page-card-title"><?php esc_html_e( 'Pre-Order Defaults', 'jezpress-woo-pre-order' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Defaults used when a pre-order product does not override its own button or availability text. Individual products can override these on the Pre-Order tab of the product editor.', 'jezpress-woo-pre-order' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'jwpo_save_settings' ); ?>
					<input type="hidden" name="action" value="jwpo_save_settings">

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="default_button_text"><?php esc_html_e( 'Add to Cart Button Text', 'jezpress-woo-pre-order' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="default_button_text"
									name="default_button_text"
									class="regular-text"
									value="<?php echo esc_attr( JWPO_Settings::get( 'default_button_text', __( 'Pre-order Now', 'jezpress-woo-pre-order' ) ) ); ?>"
								>
								<p class="description"><?php esc_html_e( 'Shown instead of "Add to Cart" on pre-order products.', 'jezpress-woo-pre-order' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="default_availability_text"><?php esc_html_e( 'Availability Text', 'jezpress-woo-pre-order' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="default_availability_text"
									name="default_availability_text"
									class="regular-text"
									value="<?php echo esc_attr( JWPO_Settings::get( 'default_availability_text', __( 'Available on {date}', 'jezpress-woo-pre-order' ) ) ); ?>"
								>
								<p class="description"><?php esc_html_e( 'Use {date} as a placeholder for the product\'s release date.', 'jezpress-woo-pre-order' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Release Check Frequency', 'jezpress-woo-pre-order' ); ?></th>
							<td>
								<select name="release_check_frequency">
									<?php
									$current  = JWPO_Settings::get( 'release_check_frequency', 'daily' );
									$options  = array(
										'hourly'     => __( 'Hourly', 'jezpress-woo-pre-order' ),
										'twicedaily' => __( 'Twice Daily', 'jezpress-woo-pre-order' ),
										'daily'      => __( 'Daily', 'jezpress-woo-pre-order' ),
									);
									foreach ( $options as $value => $label ) :
										?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'How often the scheduler checks for pre-orders whose release date has passed and moves them to Releasing.', 'jezpress-woo-pre-order' ); ?></p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<?php submit_button( __( 'Save Settings', 'jezpress-woo-pre-order' ), 'primary', 'submit', false ); ?>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Pending pre-order quantity per product, across every order currently
	 * sitting in Pre-order or Releasing status — for print-run/demand
	 * planning per the spec.
	 *
	 * @return void
	 */
	private function render_reporting_tab() {
		$orders = wc_get_orders( array(
			'status' => array( 'jwpo-preorder', 'jwpo-releasing' ),
			'limit'  => -1,
			'return' => 'objects',
		) );

		$counts = array();

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( 'yes' !== $item->get_meta( JWPO_Cart::ITEM_META_IS_PREORDER ) ) {
					continue;
				}

				// Bundle line items (Pack Builder) expand into the individual
				// contained pre-order products, since demand planning cares
				// about the actual item (e.g. the book), not the box SKU.
				$bundle_json = class_exists( 'JWPO_Bundle_Bridge' )
					? $item->get_meta( JWPO_Bundle_Bridge::ITEM_META_BUNDLE_CONTENTS )
					: '';

				if ( $bundle_json ) {
					$bundle_contents = json_decode( $bundle_json, true );

					if ( is_array( $bundle_contents ) ) {
						foreach ( $bundle_contents as $contained_id => $info ) {
							if ( ! isset( $counts[ $contained_id ] ) ) {
								$counts[ $contained_id ] = array(
									'name'   => $info['name'],
									'qty'    => 0,
									'orders' => 0,
								);
							}

							$counts[ $contained_id ]['qty']    += $item->get_quantity() * (int) $info['qty_per_unit'];
							$counts[ $contained_id ]['orders']++;
						}

						continue;
					}
				}

				$product_id = $item->get_product_id();

				if ( ! isset( $counts[ $product_id ] ) ) {
					$counts[ $product_id ] = array(
						'name'   => $item->get_name(),
						'qty'    => 0,
						'orders' => 0,
					);
				}

				$counts[ $product_id ]['qty']    += $item->get_quantity();
				$counts[ $product_id ]['orders']++;
			}
		}

		uasort( $counts, function ( $a, $b ) {
			return $b['qty'] <=> $a['qty'];
		} );
		?>
		<div class="admin-page-wrap">
			<div class="admin-page-card admin-page-card-full">
				<h2 class="admin-page-card-title"><?php esc_html_e( 'Pending Pre-Order Quantities', 'jezpress-woo-pre-order' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Pre-order line item quantities across every order currently held in Pre-order or Releasing status. Useful for print-run and demand planning.', 'jezpress-woo-pre-order' ); ?>
				</p>

				<?php if ( empty( $counts ) ) : ?>
					<p><?php esc_html_e( 'No pending pre-orders right now.', 'jezpress-woo-pre-order' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Product', 'jezpress-woo-pre-order' ); ?></th>
								<th><?php esc_html_e( 'Pending Quantity', 'jezpress-woo-pre-order' ); ?></th>
								<th><?php esc_html_e( 'Orders', 'jezpress-woo-pre-order' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $counts as $product_id => $row ) : ?>
								<tr>
									<td>
										<?php if ( get_post( $product_id ) ) : ?>
											<a href="<?php echo esc_url( get_edit_post_link( $product_id ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $row['name'] ); ?>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $row['qty'] ); ?></td>
									<td><?php echo esc_html( $row['orders'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
