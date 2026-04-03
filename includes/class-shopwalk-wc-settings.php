<?php
/**
 * WooCommerce Settings Tab — Shopwalk settings page.
 * Shows different UI based on license state.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Settings class.
 */
class Shopwalk_WC_Settings {

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
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_shopwalk', array( $this, 'render' ) );
		add_action( 'woocommerce_update_options_shopwalk', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX: activate license.
		add_action( 'wp_ajax_shopwalk_activate_license', array( $this, 'ajax_activate_license' ) );

		// AJAX: deactivate license.
		add_action( 'wp_ajax_shopwalk_deactivate_license', array( $this, 'ajax_deactivate_license' ) );

		// AJAX: enable UCP discovery + run probe.
		add_action( 'wp_ajax_shopwalk_enable_ucp_discovery', array( $this, 'ajax_enable_ucp_discovery' ) );

		// AJAX: run UCP probe manually.
		add_action( 'wp_ajax_shopwalk_test_ucp', array( $this, 'ajax_test_ucp' ) );

		// Cron: periodic UCP re-check (once per day).
		add_action( 'shopwalk_ucp_recheck', array( $this, 'run_ucp_probe' ) );
	}

	/**
	 * Add the Shopwalk settings tab to WooCommerce settings.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array Modified tabs.
	 */
	public function add_settings_tab( array $tabs ): array {
		$tabs['shopwalk'] = esc_html__( 'Shopwalk', 'shopwalk-ai' );
		return $tabs;
	}

	/**
	 * Enqueue admin CSS/JS on the Shopwalk settings page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['tab'] ) || 'shopwalk' !== $_GET['tab'] ) {
			return;
		}
		wp_enqueue_style(
			'shopwalk-admin',
			SHOPWALK_PLUGIN_URL . 'assets/admin.css',
			array(),
			SHOPWALK_VERSION
		);
		wp_enqueue_script(
			'shopwalk-admin',
			SHOPWALK_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			SHOPWALK_VERSION,
			true
		);
		wp_localize_script(
			'shopwalk-admin',
			'shopwalkAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'shopwalk_dashboard' ),
				'isLicensed' => $this->is_licensed(),
				'strings'    => array(
					'activating'   => __( 'Activating…', 'shopwalk-ai' ),
					'deactivating' => __( 'Deactivating…', 'shopwalk-ai' ),
					'syncing'      => __( 'Syncing…', 'shopwalk-ai' ),
					'syncDone'     => __( 'Sync complete.', 'shopwalk-ai' ),
					'confirm'      => __( 'Deactivate Shopwalk license?', 'shopwalk-ai' ),
				),
			)
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		$is_licensed = $this->is_licensed();
		?>
		<div class="sw-wrap">
			<?php if ( $is_licensed ) : ?>
				<?php $this->render_licensed(); ?>
			<?php else : ?>
				<?php $this->render_free(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render free (unlicensed) state.
	 *
	 * @return void
	 */
	private function render_free(): void {
		$product_count   = wp_count_posts( 'product' )->publish ?? 0;
		$ucp_base        = get_site_url() . '/wp-json/shopwalk/v1';
		// Build signup URL prefilled with everything we know about this store.
		$signup_url = add_query_arg(
			array(
				'store_url'     => rawurlencode( get_site_url() ),
				'store_name'    => rawurlencode( get_bloginfo( 'name' ) ),
				'product_count' => (int) ( wp_count_posts( 'product' )->publish ?? 0 ),
				'currency'      => rawurlencode( get_woocommerce_currency() ),
				'plugin_version'=> rawurlencode( SHOPWALK_VERSION ),
				'wc_version'    => rawurlencode( defined( 'WC_VERSION' ) ? WC_VERSION : '' ),
				'platform'      => 'woocommerce',
			),
			SHOPWALK_SIGNUP_URL
		);
		$ucp_enabled     = (bool) get_option( 'shopwalk_ucp_discovery_enabled', false );
		$ucp_reachable   = get_option( 'shopwalk_ucp_reachable', null );
		$ucp_checked_at  = get_option( 'shopwalk_ucp_checked_at', '' );
		$ucp_host_name   = (string) get_option( 'shopwalk_ucp_host_name', '' );
		$ucp_host_phone  = (string) get_option( 'shopwalk_ucp_host_phone', '' );

		$ucp_host_support = (string) get_option( 'shopwalk_ucp_host_support', '' );
		$nonce           = wp_create_nonce( 'shopwalk_dashboard' );
		?>
		<h2><?php esc_html_e( 'Shopwalk', 'shopwalk-ai' ); ?></h2>

		<table class="form-table sw-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'UCP Status', 'shopwalk-ai' ); ?></th>
				<td>
					<span class="sw-badge sw-badge--active">✅ <?php esc_html_e( 'Active', 'shopwalk-ai' ); ?></span>
					<p class="description">
						<?php esc_html_e( 'Your store speaks AI. UCP endpoints are live.', 'shopwalk-ai' ); ?>
					</p>
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
							/* translators: %d: product count, %s: currency */
							esc_html__( '%1$d published products · %2$s · WooCommerce %3$s', 'shopwalk-ai' ),
							(int) $product_count,
							esc_html( get_woocommerce_currency() ),
							esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : '' )
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'AI Shopping', 'shopwalk-ai' ); ?></th>
				<td>
					<?php if ( ! $ucp_enabled ) : ?>
						<span class="sw-badge sw-badge--inactive"><?php esc_html_e( 'Not enabled', 'shopwalk-ai' ); ?></span>
						<p class="description">
							<?php esc_html_e( 'Enable AI Shopping to let Shopwalk verify AI agents can reach your store.', 'shopwalk-ai' ); ?>
						</p>
						<button type="button" class="button button-secondary" id="sw-enable-ucp-btn"
							data-nonce="<?php echo esc_attr( $nonce ); ?>">
							<?php esc_html_e( 'Enable AI Shopping', 'shopwalk-ai' ); ?>
						</button>
						<div id="sw-ucp-result" class="sw-result" style="display:none;margin-top:8px;"></div>

					<?php elseif ( null === $ucp_reachable ) : ?>
						<span class="sw-badge sw-badge--inactive"><?php esc_html_e( 'Checking…', 'shopwalk-ai' ); ?></span>
						<button type="button" class="button" id="sw-test-ucp-btn"
							data-nonce="<?php echo esc_attr( $nonce ); ?>">
							<?php esc_html_e( 'Test Now', 'shopwalk-ai' ); ?>
						</button>
						<div id="sw-ucp-result" class="sw-result" style="display:none;margin-top:8px;"></div>

					<?php elseif ( $ucp_reachable ) : ?>
						<span class="sw-badge sw-badge--active">✅ <?php esc_html_e( '✅ AI Shopping enabled', 'shopwalk-ai' ); ?></span>
						<?php if ( $ucp_checked_at ) : ?>
							<p class="description"><?php printf( esc_html__( 'Last checked: %s', 'shopwalk-ai' ), esc_html( human_time_diff( strtotime( $ucp_checked_at ) ) . ' ' . __( 'ago', 'shopwalk-ai' ) ) ); ?></p>
						<?php endif; ?>
						<button type="button" class="button" id="sw-test-ucp-btn"
							data-nonce="<?php echo esc_attr( $nonce ); ?>">
							<?php esc_html_e( 'Test Now', 'shopwalk-ai' ); ?>
						</button>
						<div id="sw-ucp-result" class="sw-result" style="display:none;margin-top:8px;"></div>

					<?php else : ?>
						<?php
						$host_label = ! empty( $ucp_host_name ) ? $ucp_host_name : __( 'your hosting provider', 'shopwalk-ai' );
						?>
						<span class="sw-badge" style="background:#fcf0f1;color:#8a1f1f;">
							⚠️ <?php printf( esc_html__( 'AI Shopping blocked by %s', 'shopwalk-ai' ), esc_html( $host_label ) ); ?>
						</span>
						<div style="margin-top:12px;padding:16px;background:#fcf0f1;border:1px solid #f5c6cb;border-radius:4px;">
							<p style="margin:0 0 8px;font-weight:700;color:#8a1f1f;font-size:15px;">
								<?php printf( esc_html__( 'AI Shopping is blocked by %s', 'shopwalk-ai' ), esc_html( $host_label ) ); ?>
							</p>
							<p style="margin:0 0 12px;font-size:13px;color:#6c1717;line-height:1.5;">
								<?php esc_html_e( 'AI shoppers cannot find or browse your store. Call your hosting provider and ask them to enable AI Shopping (Shopwalk) for your account.', 'shopwalk-ai' ); ?>
							</p>
							<?php if ( ! empty( $ucp_host_phone ) ) : ?>
							<p style="margin:0 0 12px;font-size:14px;font-weight:700;color:#8a1f1f;">
								📞 <?php echo esc_html( $ucp_host_phone ); ?>
							</p>
							<?php endif; ?>
							<p style="margin:0;font-size:13px;color:#6c1717;line-height:1.5;">
								<?php esc_html_e( 'Ask them to whitelist Shopwalk and enable AI Shopping for your account.', 'shopwalk-ai' ); ?>
							</p>

							<div style="display:flex;gap:10px;flex-wrap:wrap;">
								<a href="https://shopwalk.com/hosting" target="_blank" rel="noopener noreferrer" class="button button-secondary">
									<?php esc_html_e( 'Find an AI-ready host ↗', 'shopwalk-ai' ); ?>
								</a>
								<a href="https://shopwalk.com/check" target="_blank" rel="noopener noreferrer" class="button">
									<?php esc_html_e( 'How to fix this ↗', 'shopwalk-ai' ); ?>
								</a>
							</div>
						</div>
						<?php if ( $ucp_checked_at ) : ?>
							<p class="description" style="margin-top:8px;"><?php printf( esc_html__( 'Last checked: %s', 'shopwalk-ai' ), esc_html( human_time_diff( strtotime( $ucp_checked_at ) ) . ' ' . __( 'ago', 'shopwalk-ai' ) ) ); ?></p>
						<?php endif; ?>
						<button type="button" class="button" id="sw-test-ucp-btn"
							data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-top:8px;">
							<?php esc_html_e( 'Test Again', 'shopwalk-ai' ); ?>
						</button>
						<div id="sw-ucp-result" class="sw-result" style="display:none;margin-top:8px;"></div>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Shopwalk Network', 'shopwalk-ai' ); ?></th>
				<td>
					<span class="sw-badge sw-badge--inactive"><?php esc_html_e( 'Not connected', 'shopwalk-ai' ); ?></span>
					<div class="sw-connect-box" style="margin-top:12px;padding:20px;background:#f0f7ff;border:1px solid #bfdbfe;border-radius:6px;">
						<p style="margin:0 0 6px;font-size:15px;font-weight:700;color:#1e3a5f;">
							<?php esc_html_e( 'Get AI orders sent through your store', 'shopwalk-ai' ); ?>
						</p>
						<p style="margin:0 0 14px;font-size:13px;color:#374151;line-height:1.5;">
							<?php esc_html_e( 'Connect to Shopwalk and your store will appear in AI shopping results when customers search for products like yours. Free to connect.', 'shopwalk-ai' ); ?>
						</p>
						<a href="<?php echo esc_url( $signup_url ); ?>" target="_blank" rel="noopener noreferrer"
							class="button button-primary sw-connect-btn" style="font-size:14px;height:36px;line-height:36px;padding:0 18px;">
							<?php esc_html_e( 'Connect to Shopwalk — Free', 'shopwalk-ai' ); ?>
						</a>
					</div>

					<p style="margin-top:12px;" class="description">
						<?php esc_html_e( 'Already have an account?', 'shopwalk-ai' ); ?>
						<a href="https://shopwalk.com/partners" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Sign in at shopwalk.com/partners →', 'shopwalk-ai' ); ?>
						</a>
					</p>
					<div class="sw-license-row">
						<input
							type="text"
							id="sw-license-key"
							class="regular-text"
							placeholder="sw_site_..."
							autocomplete="off"
						/>
						<button type="button" class="button button-secondary" id="sw-activate-btn">
							<?php esc_html_e( 'Activate License', 'shopwalk-ai' ); ?>
						</button>
					</div>
					<div id="sw-activate-result" class="sw-result" style="display:none;"></div>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render licensed state — delegates to dashboard class.
	 *
	 * @return void
	 */
	private function render_licensed(): void {
		Shopwalk_WC_Dashboard::instance()->render();
	}

	/**
	 * Save settings (WooCommerce hook — nothing to save here, handled via AJAX).
	 *
	 * @return void
	 */
	public function save(): void {
		// No form fields to save — license activation handled via AJAX.
	}

	/**
	 * AJAX: activate a license key.
	 *
	 * @return void
	 */
	public function ajax_activate_license(): void {
		check_ajax_referer( 'shopwalk_dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-ai' ) ), 403 );
		}

		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'License key is required.', 'shopwalk-ai' ) ) );
		}

		if ( ! str_starts_with( $key, 'sw_lic_' ) && ! str_starts_with( $key, 'sw_site_' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid license key format.', 'shopwalk-ai' ) ) );
		}

		$result = $this->activate_license( $key );
		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * AJAX: deactivate the current license.
	 *
	 * @return void
	 */
	public function ajax_deactivate_license(): void {
		check_ajax_referer( 'shopwalk_dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-ai' ) ), 403 );
		}

		$key    = get_option( 'shopwalk_license_key', '' );
		$domain = get_option( 'shopwalk_site_domain', '' );

		// Notify API (non-fatal).
		if ( ! empty( $key ) && ! empty( $domain ) ) {
			wp_remote_post(
				SHOPWALK_API_BASE . '/plugin/deactivate',
				array(
					'timeout' => 10,
					'headers' => array(
						'Content-Type'     => 'application/json',
						'X-SW-License-Key' => $key,
						'X-SW-Domain'      => $domain,
					),
				)
			);
		}

		// Clear all plugin options regardless of API response.
		$this->clear_license_options();

		wp_send_json_success( array( 'message' => __( 'License deactivated.', 'shopwalk-ai' ) ) );
	}

	/**
	 * AJAX: enable UCP discovery and run initial probe.
	 *
	 * @return void
	 */
	public function ajax_enable_ucp_discovery(): void {
		check_ajax_referer( 'shopwalk_dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-ai' ) ), 403 );
		}

		update_option( 'shopwalk_ucp_discovery_enabled', true );

		// Schedule daily re-check.
		if ( ! wp_next_scheduled( 'shopwalk_ucp_recheck' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'shopwalk_ucp_recheck' );
		}

		// Run probe immediately.
		$result = $this->run_ucp_probe();
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: manually re-run the UCP probe.
	 *
	 * @return void
	 */
	public function ajax_test_ucp(): void {
		check_ajax_referer( 'shopwalk_dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'shopwalk-ai' ) ), 403 );
		}

		$result = $this->run_ucp_probe();
		wp_send_json_success( $result );
	}

	/**
	 * Run the UCP probe via Shopwalk API.
	 * Shopwalk's servers attempt to reach this store's UCP endpoint from outside.
	 *
	 * @return array{ reachable: bool, checked_at: string, reason?: string }
	 */
	public function run_ucp_probe(): array {
		$response = wp_remote_post(
			SHOPWALK_API_BASE . '/public/ucp/probe',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'store_url' => get_site_url() ) ),
			)
		);

		$checked_at = gmdate( 'c' );

		if ( is_wp_error( $response ) ) {
			// Can't reach Shopwalk API — don't update status
			return array(
				'reachable'  => null,
				'checked_at' => $checked_at,
				'error'      => __( 'Could not reach Shopwalk API.', 'shopwalk-ai' ),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'reachable'  => null,
				'checked_at' => $checked_at,
				'error'      => sprintf( __( 'Shopwalk API returned HTTP %d.', 'shopwalk-ai' ), $code ),
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array(
				'reachable'  => null,
				'checked_at' => $checked_at,
				'error'      => __( 'Invalid response from Shopwalk API.', 'shopwalk-ai' ),
			);
		}

		$reachable = (bool) ( $body['reachable'] ?? false );

		update_option( 'shopwalk_ucp_reachable', $reachable );
		update_option( 'shopwalk_ucp_checked_at', $checked_at );
		update_option( 'shopwalk_ucp_host_name', sanitize_text_field( $body['host_name'] ?? '' ) );
		update_option( 'shopwalk_ucp_host_support', esc_url_raw( $body['host_support'] ?? '' ) );

		return array(
			'reachable'    => $reachable,
			'checked_at'   => $checked_at,
			'reason'       => $body['reason'] ?? '',
			'host_name'    => $body['host_name'] ?? '',
			'host_support' => $body['host_support'] ?? '',
		);
	}

	/**
	 * Activate a license key against the Shopwalk API.
	 *
	 * @param string $key License key to activate.
	 * @return array{ success: bool, message: string }
	 */
	private function activate_license( string $key ): array {
		$site_url = get_site_url();
		$domain   = wp_parse_url( $site_url, PHP_URL_HOST );

		$response = wp_remote_post(
			SHOPWALK_API_BASE . '/plugin/activate',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'     => 'application/json',
					'X-SW-License-Key' => $key,
					'X-SW-Domain'      => $domain,
				),
				'body'    => wp_json_encode(
					array(
						'plugin_key'          => $key,
						'site_url'            => $site_url,
						'site_domain'         => $domain,
						'plugin_version'      => SHOPWALK_VERSION,
						'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
						'wordpress_version'   => get_bloginfo( 'version' ),
						'php_version'         => PHP_VERSION,
						'product_count'       => (int) ( wp_count_posts( 'product' )->publish ?? 0 ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not connect to Shopwalk. Please try again.', 'shopwalk-ai' ),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			update_option( 'shopwalk_license_key', $key );
			update_option( 'shopwalk_site_domain', $domain );
			update_option( 'shopwalk_partner_id', $body['partner_id'] ?? '' );
			update_option( 'shopwalk_activated_at', gmdate( 'c' ) );

			// Trigger initial sync asynchronously.
			do_action( 'shopwalk_license_activated' );

			return array(
				'success' => true,
				'message' => __( 'Store connected! Syncing your catalog…', 'shopwalk-ai' ),
			);
		}

		$error_map = array(
			'invalid_license'       => __( 'Invalid license key. Please check and try again.', 'shopwalk-ai' ),
			'domain_mismatch'       => __( 'This license key is registered to a different domain.', 'shopwalk-ai' ),
			'subscription_inactive' => __( 'Your Shopwalk subscription is not active.', 'shopwalk-ai' ),
		);

		$error = $body['error'] ?? 'unknown';
		return array(
			'success' => false,
			'message' => $error_map[ $error ] ?? __( 'Activation failed. Please try again.', 'shopwalk-ai' ),
		);
	}

	/**
	 * Clear all license-related WordPress options.
	 *
	 * @return void
	 */
	private function clear_license_options(): void {
		$options = array(
			'shopwalk_license_key',
			'shopwalk_site_domain',
			'shopwalk_partner_id',
			'shopwalk_activated_at',
			'shopwalk_last_sync_at',
			'shopwalk_synced_count',
			'shopwalk_sync_queue',
		);
		foreach ( $options as $option ) {
			delete_option( $option );
		}
	}

	/**
	 * Check if a license key is installed.
	 *
	 * @return bool
	 */
	private function is_licensed(): bool {
		return str_starts_with( (string) get_option( 'shopwalk_license_key', '' ), 'sw_lic_' ) || str_starts_with( (string) get_option( 'shopwalk_license_key', '' ), 'sw_site_' );
	}
}
