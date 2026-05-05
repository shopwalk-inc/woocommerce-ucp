<?php
/**
 * Shopwalk_Connector — handles the "Connect to Shopwalk" CTA flow.
 *
 * Tier 2 (Shopwalk integration) only. Owns the AJAX endpoints that the
 * dashboard CTA uses for license entry, activation, and disconnect.
 *
 * @package ShopwalkWooCommerce
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
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) ), 403 );
		}
		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( '' === $key ) {
			wp_send_json_error( array( 'message' => __( 'License key is required.', 'shopwalk-for-woocommerce' ) ), 400 );
		}
		$result = Shopwalk_License::activate( $key );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 400 );
		}
		wp_send_json_success(
			array(
				'message'    => __( 'Connected to Shopwalk.', 'shopwalk-for-woocommerce' ),
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
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) ), 403 );
		}
		Shopwalk_License::deactivate();
		wp_send_json_success( array( 'message' => __( 'Disconnected.', 'shopwalk-for-woocommerce' ) ) );
	}

	/**
	 * AJAX: trigger a full catalog sync from the dashboard "Sync now" button.
	 *
	 * @return void
	 */
	/**
	 * Cooldown period in seconds between full syncs.
	 */
	private const SYNC_COOLDOWN = 3600; // 1 hour between full re-syncs.

	/**
	 * WP option key for tracking sync state.
	 */
	private const SYNC_STATE_OPTION = 'shopwalk_sync_state';

	public function ajax_full_sync(): void {
		check_ajax_referer( 'shopwalk_full_sync', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-for-woocommerce' ) ), 403 );
		}

		// Cooldown check — prevent spamming sync requests.
		$state = (array) get_option( self::SYNC_STATE_OPTION, array() );
		$last  = (int) ( $state['last_sync_at'] ?? 0 );
		$now   = time();

		if ( $last > 0 && ( $now - $last ) < self::SYNC_COOLDOWN ) {
			$remaining = self::SYNC_COOLDOWN - ( $now - $last );
			wp_send_json_error(
				array(
					'message'            => sprintf(
						/* translators: %d: seconds remaining */
						__( 'Please wait %d seconds before syncing again.', 'shopwalk-for-woocommerce' ),
						$remaining
					),
					'cooldown_remaining' => $remaining,
					'status'             => 'cooldown',
				),
				429
			);
		}

		// Mark sync as in progress.
		update_option(
			self::SYNC_STATE_OPTION,
			array(
				'status'       => 'syncing',
				'last_sync_at' => $now,
				'started_at'   => $now,
			),
			false
		);

		$sync   = Shopwalk_Sync::instance();
		$queued = $sync->full_sync();

		// Flush immediately — don't wait for WP-Cron.
		// Re-read after each flush — the queue shrinks as items go through.
		$batches = 0;
		$queue   = (array) get_option( 'shopwalk_sync_queue', array() );
		while ( ! empty( $queue ) ) {
			$sync->flush();
			++$batches;
			$queue = (array) get_option( 'shopwalk_sync_queue', array() );
		}

		$completed = time();

		// Update sync state.
		update_option(
			self::SYNC_STATE_OPTION,
			array(
				'status'       => 'complete',
				'last_sync_at' => $now,
				'products'     => $queued,
				'batches'      => $batches,
				'completed_at' => $completed,
			),
			false
		);

		// Append to sync history (keep last 10).
		$history   = (array) get_option( 'shopwalk_sync_history', array() );
		$history[] = array(
			'timestamp' => $completed,
			'type'      => 'full',
			'total'     => $queued,
			'batches'   => $batches,
		);
		if ( count( $history ) > 10 ) {
			$history = array_slice( $history, -10 );
		}
		update_option( 'shopwalk_sync_history', $history, false );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of products synced */
					__( '%1$d products synced in %2$d batches.', 'shopwalk-for-woocommerce' ),
					$queued,
					$batches
				),
				'queued'  => $queued,
				'batches' => $batches,
				'status'  => 'complete',
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
