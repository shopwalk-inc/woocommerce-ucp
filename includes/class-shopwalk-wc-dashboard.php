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

			<!-- Support -->
			<tr>
				<th><?php esc_html_e( 'Support', 'shopwalk-ai' ); ?></th>
				<td>
					<a href="mailto:support@shopwalk.com?subject=<?php echo rawurlencode( 'Support Request - ' . get_site_url() ); ?>"
					   class="button">
						✉️ <?php esc_html_e( 'Email Support', 'shopwalk-ai' ); ?>
					</a>
					&nbsp;
					<a href="https://help.shopwalk.com" target="_blank" rel="noopener noreferrer" class="button">
						📖 <?php esc_html_e( 'Help Center ↗', 'shopwalk-ai' ); ?>
					</a>
					<p class="description"><?php esc_html_e( 'Typical response time: under 4 hours on business days.', 'shopwalk-ai' ); ?></p>
				</td>
			</tr>


			<!-- How AI works (always shown when connected) -->
			<tr>
				<th style="vertical-align:top;padding-top:14px;"><?php esc_html_e( 'How AI finds you', 'shopwalk-ai' ); ?></th>
				<td>
					<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:16px 20px;">
						<p style="margin:0 0 10px;font-size:13px;color:#374151;line-height:1.6;">
							<?php esc_html_e( 'AI does not visit your website. It queries Shopwalk for products and your store answers come back instantly - no page loads, no browsing.', 'shopwalk-ai' ); ?>
						</p>
						<ul style="margin:0;padding:0 0 0 18px;font-size:13px;color:#374151;line-height:2;">
							<li><?php esc_html_e( '"Find waterproof hiking boots under $100" → your products', 'shopwalk-ai' ); ?></li>
							<li><?php esc_html_e( '"Is size 10 in stock?" → live inventory answer', 'shopwalk-ai' ); ?></li>
							<li><?php esc_html_e( '"What is the return policy?" → your store info', 'shopwalk-ai' ); ?></li>
						</ul>
					</div>
				</td>
			</tr>


			<!-- AI Preview (shown when synced) -->
			<?php if ( $synced_count > 0 ) : ?>
			<tr>
				<th style="vertical-align:top;padding-top:16px;"><?php esc_html_e( 'How AI sees you', 'shopwalk-ai' ); ?></th>
				<td>
					<p class="description" style="margin-bottom:12px;">
						<?php esc_html_e( 'This is what AI says about your store when someone asks about it.', 'shopwalk-ai' ); ?>
					</p>
					<?php
					$store_name        = get_bloginfo( 'name' );
					$store_desc        = wp_strip_all_tags( get_bloginfo( 'description' ) );
					$store_url         = get_site_url();
					$domain            = preg_replace( '#^https?://#', '', $store_url );
					$product_count_fmt = number_format( $synced_count );
					$currency          = get_woocommerce_currency();

					if ( empty( $store_desc ) ) {
						$store_desc = 'an independent online store';
					}

					$ai_message = sprintf(
						/* translators: %1$s: store name, %2$s: description, %3$s: product count, %4$s: currency */
						__( 'I found %1$s — %2$s They carry %3$s products, priced in %4$s. Would you like me to search their catalog?', 'shopwalk-ai' ),
						esc_html( $store_name ),
						esc_html( mb_strimwidth( $store_desc, 0, 140, '…' ) ) . ' ',
						esc_html( $product_count_fmt ),
						esc_html( $currency )
					);
					?>
					<div class="sw-ai-chat-preview" style="border-radius:12px;overflow:hidden;border:1px solid #1e293b;max-width:560px;">
						<!-- Window chrome -->
						<div style="background:#0f172a;padding:10px 14px;display:flex;align-items:center;gap:6px;border-bottom:1px solid #1e293b;">
							<span style="width:10px;height:10px;border-radius:50%;background:#ff5f57;display:inline-block;"></span>
							<span style="width:10px;height:10px;border-radius:50%;background:#ffbd2e;display:inline-block;"></span>
							<span style="width:10px;height:10px;border-radius:50%;background:#28c840;display:inline-block;"></span>
							<span style="font-size:11px;color:#475569;font-family:monospace;margin:0 auto;">shopwalk — AI commerce</span>
						</div>
						<!-- Chat -->
						<div style="background:#0f172a;padding:16px;display:flex;flex-direction:column;gap:12px;">
							<!-- User prompt -->
							<div style="display:flex;justify-content:flex-end;gap:8px;align-items:flex-end;">
								<div style="background:#1e293b;border-radius:12px 12px 2px 12px;padding:8px 14px;max-width:280px;">
									<p style="margin:0;font-size:13px;color:#94a3b8;">
										<?php printf( esc_html__( 'What can you tell me about %s?', 'shopwalk-ai' ), esc_html( $domain ) ); ?>
									</p>
								</div>
								<div style="width:28px;height:28px;border-radius:50%;background:#334155;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:11px;color:#94a3b8;">You</div>
							</div>
							<!-- AI response -->
							<div style="display:flex;gap:8px;align-items:flex-start;">
								<div style="width:28px;height:28px;border-radius:50%;background:rgba(14,165,233,0.15);border:1px solid rgba(14,165,233,0.3);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#0ea5e9;font-weight:700;">✦</div>
								<div style="background:#1e293b;border-radius:2px 12px 12px 12px;padding:10px 14px;flex:1;" id="sw-ai-preview-box">
									<p style="margin:0;font-size:13px;color:#e2e8f0;line-height:1.6;" id="sw-ai-preview-text"></p>
								</div>
							</div>
						</div>
					</div>

					<script>
					(function() {
						var msg = <?php echo wp_json_encode( $ai_message ); ?>;
						var el = document.getElementById('sw-ai-preview-text');
						if (!el) return;
						var i = 0;
						var delay = 400;
						setTimeout(function() {
							var iv = setInterval(function() {
								el.textContent = msg.slice(0, i + 1);
								i++;
								if (i >= msg.length) {
									clearInterval(iv);
								}
							}, 16);
						}, delay);
					})();
					</script>
				</td>
			</tr>
			<?php endif; ?>


			<!-- What happens now (shown when synced) -->
			<?php if ( $synced_count > 0 ) : ?>
			<tr>
				<th><?php esc_html_e( 'What happens now', 'shopwalk-ai' ); ?></th>
				<td>
					<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:16px 20px;margin-bottom:12px;">
						<p style="margin:0 0 4px;font-weight:700;color:#166534;font-size:14px;">
							🎉 <?php esc_html_e( 'Your store is now discoverable by AI', 'shopwalk-ai' ); ?>
						</p>
						<p style="margin:0;font-size:13px;color:#15803d;line-height:1.5;">
							<?php
							printf(
								/* translators: %d: synced product count */
								esc_html__( '%d products are indexed in the Shopwalk network. AI can now find and recommend them to shoppers.', 'shopwalk-ai' ),
								(int) $synced_count
							);
							?>
						</p>
					</div>

					<ul style="margin:0;padding:0 0 0 0;list-style:none;font-size:13px;color:#374151;line-height:2;">
						<li>✅ <?php esc_html_e( 'Your products are indexed — AI searches will find them', 'shopwalk-ai' ); ?></li>
						<li>✅ <?php esc_html_e( 'New and updated products sync automatically', 'shopwalk-ai' ); ?></li>
						<li>✅ <?php esc_html_e( 'You get 5% of any purchase AI completes through your store', 'shopwalk-ai' ); ?></li>
						<li>📊 <?php esc_html_e( 'Check your Partners Portal to see AI traffic to your store', 'shopwalk-ai' ); ?></li>
					</ul>

					<p style="margin:12px 0 0;font-size:13px;color:#6b7280;">
						<?php esc_html_e( 'Nothing more to do. Shopwalk handles discovery automatically.', 'shopwalk-ai' ); ?>
					</p>
				</td>
			</tr>
			<?php endif; ?>

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
