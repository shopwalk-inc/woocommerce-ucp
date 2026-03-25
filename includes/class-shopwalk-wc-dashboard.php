<?php
/**
 * Licensed dashboard — minimal WP Admin UI that directs to Partners Portal.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Dashboard class.
 */
class Shopwalk_WC_Dashboard {

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
		add_action( 'wp_ajax_shopwalk_open_portal', array( $this, 'ajax_open_portal' ) );
		add_action( 'wp_ajax_shopwalk_run_diagnostics', array( $this, 'ajax_run_diagnostics' ) );
	}

	/**
	 * Render the licensed dashboard.
	 *
	 * @return void
	 */
	public function render(): void {
		$product_count = (int) ( wp_count_posts( 'product' )->publish ?? 0 );
		$synced_count  = (int) get_option( 'shopwalk_synced_count', 0 );
		$last_sync     = get_option( 'shopwalk_last_sync_at', '' );
		$queue_count   = count( get_option( 'shopwalk_sync_queue', array() ) );
		$partner_id    = (string) get_option( 'shopwalk_partner_id', '' );
		$activated_at  = (string) get_option( 'shopwalk_activated_at', '' );
		$license_key   = (string) get_option( 'shopwalk_license_key', '' );
		$masked_key    = ! empty( $license_key ) ? ( substr( $license_key, 0, 14 ) . str_repeat( '•', 8 ) ) : '';
		$ucp_base      = get_site_url() . '/wp-json/shopwalk/v1';
		$last_sync_fmt = $last_sync ? $this->time_ago( $last_sync ) : __( 'Never', 'shopwalk-ai' );
		$nonce         = wp_create_nonce( 'shopwalk_dashboard' );
		?>
		<h2><?php esc_html_e( 'Shopwalk', 'shopwalk-ai' ); ?></h2>

		<table class="form-table sw-table" role="presentation">

			<!-- UCP Status -->
			<tr>
				<th><?php esc_html_e( 'UCP Status', 'shopwalk-ai' ); ?></th>
				<td>
					<span class="sw-badge sw-badge--active">✅ <?php esc_html_e( 'Active', 'shopwalk-ai' ); ?></span>
					<ul class="sw-endpoints">
						<li>
							<strong><?php esc_html_e( 'Products:', 'shopwalk-ai' ); ?></strong>
							<code><?php echo esc_html( $ucp_base . '/products' ); ?></code>
						</li>
						<li>
							<strong><?php esc_html_e( 'Store:', 'shopwalk-ai' ); ?></strong>
							<code><?php echo esc_html( $ucp_base . '/store' ); ?></code>
						</li>
					</ul>
					<p class="description">
						<?php
						printf(
							/* translators: %1$d: product count, %2$s: currency, %3$s: WC version */
							esc_html__( '%1$d published products · %2$s · WooCommerce %3$s', 'shopwalk-ai' ),
							(int) $product_count,
							esc_html( get_woocommerce_currency() ),
							esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : '' )
						);
						?>
					</p>
				</td>
			</tr>

			<!-- Shopwalk Network -->
			<tr>
				<th><?php esc_html_e( 'Shopwalk Network', 'shopwalk-ai' ); ?></th>
				<td>
					<span class="sw-badge sw-badge--active">
						✅
						<?php
						printf(
							/* translators: %1$d: synced count, %2$s: last sync time */
							esc_html__( 'Connected · %1$d synced · Last sync: %2$s', 'shopwalk-ai' ),
							(int) $synced_count,
							esc_html( $last_sync_fmt )
						);
						?>
					</span>

					<?php if ( ! empty( $partner_id ) ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: partner ID (truncated) */
								esc_html__( 'Partner ID: %s', 'shopwalk-ai' ),
								esc_html( substr( $partner_id, 0, 8 ) . '…' )
							);
							?>
							<?php if ( ! empty( $activated_at ) ) : ?>
								· <?php echo esc_html( gmdate( 'M j, Y', strtotime( $activated_at ) ) ); ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<!-- Partners Portal CTA -->
					<div class="sw-portal-box">
						<button type="button" class="button button-primary sw-portal-btn" id="sw-portal-btn"
							data-nonce="<?php echo esc_attr( $nonce ); ?>">
							🚀 <?php esc_html_e( 'Open Partners Portal', 'shopwalk-ai' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Analytics, AI traffic, store settings and more.', 'shopwalk-ai' ); ?>
						</p>
						<div id="sw-portal-result" class="sw-result" style="display:none;"></div>
					</div>

					<hr class="sw-divider" />

					<!-- Sync status -->
					<p>
						<strong><?php esc_html_e( 'Sync', 'shopwalk-ai' ); ?></strong>
					</p>
					<p class="description" id="sw-sync-status">
						<?php
						printf(
							/* translators: %1$d: synced count, %2$s: last sync, %3$d: queue count */
							esc_html__( '%1$d products synced · Last sync: %2$s · %3$d pending', 'shopwalk-ai' ),
							(int) $synced_count,
							esc_html( $last_sync_fmt ),
							(int) $queue_count
						);
						?>
					</p>
					<button type="button" class="button" id="sw-sync-btn"
						data-nonce="<?php echo esc_attr( $nonce ); ?>">
						🔄 <?php esc_html_e( 'Sync Now', 'shopwalk-ai' ); ?>
					</button>
					<div id="sw-sync-result" class="sw-result" style="display:none;margin-top:8px;"></div>
				</td>
			</tr>

			<!-- License -->
			<tr>
				<th><?php esc_html_e( 'License', 'shopwalk-ai' ); ?></th>
				<td>
					<code><?php echo esc_html( $masked_key ); ?></code>
					&nbsp;
					<button type="button" class="button button-secondary sw-danger" id="sw-deactivate-btn"
						data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Deactivate', 'shopwalk-ai' ); ?>
					</button>
					<div id="sw-deactivate-result" class="sw-result" style="display:none;margin-top:8px;"></div>
				</td>
			</tr>

			<!-- Tools -->
			<tr>
				<th><?php esc_html_e( 'Tools', 'shopwalk-ai' ); ?></th>
				<td>
					<button type="button" class="button" id="sw-diag-btn"
						data-nonce="<?php echo esc_attr( $nonce ); ?>">
						🔍 <?php esc_html_e( 'Run Diagnostics', 'shopwalk-ai' ); ?>
					</button>
					<div id="sw-diag-results" style="display:none;margin-top:12px;"></div>
				</td>
			</tr>

		</table>
		<?php
	}

	/**
	 * AJAX: generate magic link and return Partners Portal URL.
	 *
	 * @return void
	 */
	public function ajax_open_portal(): void {
		check_ajax_referer( 'shopwalk_dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-ai' ) ), 403 );
		}

		$key    = get_option( 'shopwalk_license_key', '' );
		$domain = get_option( 'shopwalk_site_domain', '' );

		if ( empty( $key ) || empty( $domain ) ) {
			wp_send_json_success( array( 'url' => SHOPWALK_PARTNERS_URL ) );
			return;
		}

		$response = wp_remote_post(
			SHOPWALK_API_BASE . '/plugin/portal-link',
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-SW-License-Key' => $key,
					'X-SW-Domain'      => $domain,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Fallback to portal homepage.
			wp_send_json_success( array( 'url' => SHOPWALK_PARTNERS_URL ) );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$url  = $body['url'] ?? SHOPWALK_PARTNERS_URL;

		wp_send_json_success( array( 'url' => $url ) );
	}

	/**
	 * AJAX: run diagnostic checks.
	 *
	 * @return void
	 */
	public function ajax_run_diagnostics(): void {
		check_ajax_referer( 'shopwalk_dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-ai' ) ), 403 );
		}

		global $wp_version;
		$checks = array();

		// PHP >= 8.0.
		$php_ok   = version_compare( PHP_VERSION, '8.0', '>=' );
		$checks[] = array(
			'name'  => __( 'PHP Version', 'shopwalk-ai' ),
			'ok'    => $php_ok,
			'value' => PHP_VERSION,
			'fix'   => $php_ok ? '' : __( 'Upgrade PHP to 8.0 or higher.', 'shopwalk-ai' ),
		);

		// WordPress >= 6.0.
		$wp_ok    = version_compare( $wp_version, '6.0', '>=' );
		$checks[] = array(
			'name'  => __( 'WordPress Version', 'shopwalk-ai' ),
			'ok'    => $wp_ok,
			'value' => $wp_version,
			'fix'   => $wp_ok ? '' : __( 'Update WordPress to 6.0 or higher.', 'shopwalk-ai' ),
		);

		// WooCommerce >= 8.0.
		$wc_ver   = defined( 'WC_VERSION' ) ? WC_VERSION : '0';
		$wc_ok    = version_compare( $wc_ver, '8.0', '>=' );
		$checks[] = array(
			'name'  => __( 'WooCommerce Version', 'shopwalk-ai' ),
			'ok'    => $wc_ok,
			'value' => $wc_ver,
			'fix'   => $wc_ok ? '' : __( 'Update WooCommerce to 8.0 or higher.', 'shopwalk-ai' ),
		);

		// Memory limit >= 128M.
		$mem_limit = ini_get( 'memory_limit' );
		$mem_bytes = wp_convert_hr_to_bytes( $mem_limit );
		$mem_ok    = $mem_bytes < 0 || $mem_bytes >= 128 * MB_IN_BYTES;
		$checks[]  = array(
			'name'  => __( 'Memory Limit', 'shopwalk-ai' ),
			'ok'    => $mem_ok,
			'value' => $mem_limit,
			'fix'   => $mem_ok ? '' : __( 'Increase PHP memory_limit to at least 128M.', 'shopwalk-ai' ),
		);

		// Pretty permalinks.
		$permalink = get_option( 'permalink_structure', '' );
		$perm_ok   = ! empty( $permalink );
		$checks[]  = array(
			'name'  => __( 'Pretty Permalinks', 'shopwalk-ai' ),
			'ok'    => $perm_ok,
			'value' => $perm_ok ? __( 'Enabled', 'shopwalk-ai' ) : __( 'Disabled', 'shopwalk-ai' ),
			'fix'   => $perm_ok ? '' : __( 'Enable pretty permalinks under Settings → Permalinks.', 'shopwalk-ai' ),
		);

		// UCP endpoint reachable.
		$ucp_url  = get_site_url() . '/wp-json/shopwalk/v1/store';
		$ucp_resp = wp_remote_get( $ucp_url, array( 'timeout' => 8 ) );
		$ucp_code = is_wp_error( $ucp_resp ) ? 0 : wp_remote_retrieve_response_code( $ucp_resp );
		$ucp_ok   = 200 === $ucp_code;
		$checks[] = array(
			'name'  => __( 'UCP Endpoint', 'shopwalk-ai' ),
			'ok'    => $ucp_ok,
			'value' => $ucp_ok ? __( 'Reachable', 'shopwalk-ai' ) : __( 'Unreachable (HTTP ' . intval( $ucp_code ) . ')', 'shopwalk-ai' ),
			'fix'   => $ucp_ok ? '' : __( 'Check that pretty permalinks are enabled and REST API is not blocked.', 'shopwalk-ai' ),
		);

		// Shopwalk API reachable.
		$api_resp = wp_remote_get( 'https://api.shopwalk.com/health', array( 'timeout' => 8 ) );
		$api_ok   = ! is_wp_error( $api_resp ) && 200 === wp_remote_retrieve_response_code( $api_resp );
		$checks[] = array(
			'name'  => __( 'Shopwalk API', 'shopwalk-ai' ),
			'ok'    => $api_ok,
			'value' => $api_ok ? __( 'Connected', 'shopwalk-ai' ) : __( 'Failed', 'shopwalk-ai' ),
			'fix'   => $api_ok ? '' : __( 'Check server firewall settings. Must be able to reach api.shopwalk.com.', 'shopwalk-ai' ),
		);

		// License key present.
		$lic_key  = (string) get_option( 'shopwalk_license_key', '' );
		$lic_ok   = str_starts_with( $lic_key, 'sw_lic_' );
		$checks[] = array(
			'name'  => __( 'License Key', 'shopwalk-ai' ),
			'ok'    => $lic_ok,
			'value' => $lic_ok ? __( 'Installed', 'shopwalk-ai' ) : __( 'Not installed', 'shopwalk-ai' ),
			'fix'   => $lic_ok ? '' : __( 'Enter your license key in the settings page.', 'shopwalk-ai' ),
		);

		wp_send_json_success( array( 'checks' => $checks ) );
	}

	/**
	 * Convert an ISO timestamp to a human-readable "time ago" string.
	 *
	 * @param string $iso ISO 8601 timestamp.
	 * @return string Human-readable string.
	 */
	private function time_ago( string $iso ): string {
		$then = strtotime( $iso );
		$now  = time();
		$diff = $now - $then;

		if ( $diff < 60 ) {
			return __( 'Just now', 'shopwalk-ai' );
		}
		if ( $diff < 3600 ) {
			$mins = (int) floor( $diff / 60 );
			/* translators: %d: minutes */
			return sprintf( _n( '%d min ago', '%d mins ago', $mins, 'shopwalk-ai' ), $mins );
		}
		if ( $diff < 86400 ) {
			$hours = (int) floor( $diff / 3600 );
			/* translators: %d: hours */
			return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'shopwalk-ai' ), $hours );
		}
		$days = (int) floor( $diff / 86400 );
		/* translators: %d: days */
		return sprintf( _n( '%d day ago', '%d days ago', $days, 'shopwalk-ai' ), $days );
	}
}
