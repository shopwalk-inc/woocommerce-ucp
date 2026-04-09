<?php
/**
 * Shopwalk_Connector — handles the "Connect to Shopwalk" CTA flow.
 *
 * Tier 2 (Shopwalk integration) only. Owns the AJAX endpoints that the
 * dashboard CTA uses for license entry, activation, and disconnect.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_Connector — activation/disconnect AJAX handlers.
 */
final class Shopwalk_Connector {

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
	 * Wire up AJAX endpoints.
	 */
	private function __construct() {
		add_action( 'wp_ajax_shopwalk_activate', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_shopwalk_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_shopwalk_full_sync', array( $this, 'ajax_full_sync' ) );

		// Kick off a full sync the first time a license is activated.
		add_action( 'shopwalk_license_activated', array( $this, 'on_license_activated' ), 10, 2 );
	}

	/**
	 * AJAX: POST a license key + activate.
	 *
	 * @return void
	 */
	public function ajax_activate(): void {
		check_ajax_referer( 'shopwalk_activate', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-ai' ) ), 403 );
		}
		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( $key === '' ) {
			wp_send_json_error( array( 'message' => __( 'License key is required.', 'shopwalk-ai' ) ), 400 );
		}
		$result = Shopwalk_License::activate( $key );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 400 );
		}
		wp_send_json_success(
			array(
				'message'    => __( 'Connected to Shopwalk.', 'shopwalk-ai' ),
				'partner_id' => $result['partner_id'] ?? '',
			)
		);
	}

	/**
	 * AJAX: disconnect from Shopwalk.
	 *
	 * @return void
	 */
	public function ajax_disconnect(): void {
		check_ajax_referer( 'shopwalk_disconnect', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-ai' ) ), 403 );
		}
		Shopwalk_License::deactivate();
		wp_send_json_success( array( 'message' => __( 'Disconnected.', 'shopwalk-ai' ) ) );
	}

	/**
	 * AJAX: trigger a full catalog sync from the dashboard "Sync now" button.
	 *
	 * @return void
	 */
	public function ajax_full_sync(): void {
		check_ajax_referer( 'shopwalk_full_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-ai' ) ), 403 );
		}
		$queued = Shopwalk_Sync::instance()->full_sync();
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of products queued */
					__( '%d products queued for sync.', 'shopwalk-ai' ),
					$queued
				),
				'queued'  => $queued,
			)
		);
	}

	/**
	 * Hook: when a license is activated for the first time, queue every
	 * product so the agent dashboard fills with data immediately.
	 *
	 * @param string $key The new license key.
	 * @param string $pid Partner id returned by shopwalk-api.
	 * @return void
	 */
	public function on_license_activated( string $key, string $pid ): void {
		unset( $key, $pid );
		Shopwalk_Sync::instance()->full_sync();
	}
}
