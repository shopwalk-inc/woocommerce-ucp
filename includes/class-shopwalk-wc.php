<?php
/**
 * Main plugin class — initializes subsystems based on license state.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC class.
 */
class Shopwalk_WC {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Always active — UCP endpoints require NO license, NO account, NO API calls.
		Shopwalk_WC_UCP::instance();

		// Always active — settings page shows UCP status + connect link or licensed dashboard.
		Shopwalk_WC_Settings::instance();

		// Always activate sync and dashboard — the plugin ships with a license key
		// embedded in the zip. No manual "connect" step needed.
		Shopwalk_WC_Sync::instance();
		Shopwalk_WC_Dashboard::instance();

		// AJAX: dismiss the connect notice (kept for backward compat).
		add_action( 'wp_ajax_shopwalk_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
	}

	/**
	 * Check if the plugin is licensed (license key installed and valid format).
	 *
	 * @return bool
	 */
	public function is_licensed(): bool {
		$key = get_option( 'shopwalk_license_key', '' );
		return ! empty( $key ) && str_starts_with( (string) $key, 'sw_lic_' ) || str_starts_with( (string) $key, 'sw_site_' );
	}

	/**
	 * Show admin notice when not licensed.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		if ( $this->is_licensed() ) {
			return;
		}
		if ( get_option( 'shopwalk_notice_dismissed' ) ) {
			return;
		}
		// Only show on WooCommerce and Shopwalk pages.
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-settings', 'plugins' ), true ) ) {
			return;
		}
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=shopwalk' );
		?>
		<div class="notice notice-info is-dismissible" id="shopwalk-connect-notice">
			<p>
				<strong><?php esc_html_e( 'Shopwalk', 'shopwalk-ai' ); ?></strong>
				<?php esc_html_e( ' — Your store speaks AI. Connect to the Shopwalk network and get discovered by AI shoppers.', 'shopwalk-ai' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Connect your store →', 'shopwalk-ai' ); ?>
				</a>
			</p>
		</div>
		<script>
		(function() {
			var notice = document.getElementById('shopwalk-connect-notice');
			if (!notice) return;
			notice.addEventListener('click', function(e) {
				if (e.target.classList.contains('notice-dismiss')) {
					fetch(ajaxurl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: 'action=shopwalk_dismiss_notice&nonce=<?php echo esc_js( wp_create_nonce( 'shopwalk_dismiss' ) ); ?>'
					});
				}
			});
		}());
		</script>
		<?php
	}

	/**
	 * AJAX: dismiss the connect notice.
	 *
	 * @return void
	 */
	public function ajax_dismiss_notice(): void {
		check_ajax_referer( 'shopwalk_dismiss', 'nonce' );
		update_option( 'shopwalk_notice_dismissed', true );
		wp_send_json_success();
	}
}
