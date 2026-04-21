<?php
/**
 * Admin Self-Test runner — wraps UCP_Self_Test in a wp-ajax handler
 * so the dashboard can run diagnostics from a button click.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce_UCP_Admin_Self_Test — AJAX endpoint.
 */
final class WooCommerce_UCP_Admin_Self_Test {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get or create the singleton.
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
	 * Wire up the AJAX action.
	 */
	private function __construct() {
		add_action( 'wp_ajax_shopwalk_self_test', array( $this, 'handle' ) );
	}

	/**
	 * AJAX handler — runs UCP_Self_Test::run_all() and returns the
	 * checks list. Capability-gated to manage_woocommerce.
	 *
	 * @return void
	 */
	public function handle(): void {
		check_ajax_referer( 'shopwalk_self_test', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'woocommerce-ucp' ) ), 403 );
		}
		wp_send_json_success(
			array(
				'checks' => UCP_Self_Test::run_all(),
			)
		);
	}
}
