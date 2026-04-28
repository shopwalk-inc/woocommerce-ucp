<?php
/**
 * WP Admin Dashboard — three tools: UCP, Sync, License.
 * Adapts based on tier (unlicensed / free / pro).
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

final class WooCommerce_UCP_Admin_Dashboard {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// AJAX handlers
		add_action( 'wp_ajax_shopwalk_self_test', array( $this, 'ajax_self_test' ) );
		add_action( 'wp_ajax_shopwalk_probe', array( $this, 'ajax_probe' ) );
		add_action( 'wp_ajax_shopwalk_activate', array( $this, 'ajax_activate' ) );
		add_action( 'wp_ajax_shopwalk_test_license', array( $this, 'ajax_test_license' ) );
		add_action( 'wp_ajax_shopwalk_disconnect', array( $this, 'ajax_disconnect' ) );
		add_action( 'wp_ajax_shopwalk_toggle_discovery', array( $this, 'ajax_toggle_discovery' ) );
		add_action( 'wp_ajax_shopwalk_sync_status', array( $this, 'ajax_sync_status' ) );
		add_action( 'wp_ajax_shopwalk_payments_status', array( $this, 'ajax_payments_status' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'UCP Commerce', 'woocommerce-ucp' ),
			__( 'UCP', 'woocommerce-ucp' ),
			'manage_woocommerce',
			'woocommerce-ucp',
			array( $this, 'render_page' ),
			'dashicons-share-alt2',
			58
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_woocommerce-ucp' !== $hook ) {
			return;
		}
		wp_register_script( 'woocommerce-ucp-admin', '', array(), WOOCOMMERCE_UCP_VERSION, true );
		wp_enqueue_script( 'woocommerce-ucp-admin' );
		wp_add_inline_script(
			'woocommerce-ucp-admin',
			'window.swAdmin = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonces'  => array(
						'self_test'        => wp_create_nonce( 'shopwalk_self_test' ),
						'probe'            => wp_create_nonce( 'shopwalk_probe' ),
						'activate'         => wp_create_nonce( 'shopwalk_activate' ),
						'test_license'     => wp_create_nonce( 'shopwalk_test_license' ),
						'disconnect'       => wp_create_nonce( 'shopwalk_disconnect' ),
						'toggle_discovery' => wp_create_nonce( 'shopwalk_toggle_discovery' ),
						'sync_status'      => wp_create_nonce( 'shopwalk_sync_status' ),
						'payments_status'  => wp_create_nonce( 'shopwalk_payments_status' ),
						'upgrade_url'      => wp_create_nonce( 'shopwalk_upgrade_url' ),
					),
				)
			) . ';' . $this->admin_js()
		);
	}

	/**
	 * Surface the result of an OAuth callback. handle_oauth_callback redirects
	 * back to the dashboard with ?sw_connect=(ok|declined|state_mismatch|exchange_failed)
	 * after consuming the code.
	 */
	/**
	 * Renders the Shopwalk status banner inline. Fetched server-side via
	 * wp_remote_get so the WP admin never makes a cross-origin call to
	 * api.shopwalk.com — see "Design A" rule: WP admin never calls the
	 * Shopwalk API directly from the browser.
	 *
	 * Cached for 5 minutes (`shopwalk_status_banner` transient) so admin
	 * page loads don't add a remote round-trip on every render. Failures
	 * are silent — the banner is informational, not critical.
	 */
	private function render_status_banner(): void {
		$cached = get_transient( 'shopwalk_status_banner' );
		if ( false === $cached ) {
			$response = wp_remote_get(
				SHOPWALK_API_BASE . '/status/banner',
				array(
					'timeout' => 5,
					'headers' => array(
						'User-Agent' => 'woocommerce-ucp-plugin/' . WOOCOMMERCE_UCP_VERSION,
					),
				)
			);
			$cached   = '';
			if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( is_array( $body ) && ! empty( $body['active'] ) && ! empty( $body['message'] ) ) {
					$cached = wp_json_encode( $body );
				}
			}
			set_transient( 'shopwalk_status_banner', $cached, 5 * MINUTE_IN_SECONDS );
		}
		if ( '' === $cached ) {
			return;
		}
		$banner = json_decode( $cached, true );
		if ( ! is_array( $banner ) ) {
			return;
		}

		$type    = isset( $banner['type'] ) ? (string) $banner['type'] : 'info';
		$message = isset( $banner['message'] ) ? (string) $banner['message'] : '';
		if ( '' === $message ) {
			return;
		}

		$palette = array(
			'maintenance' => array(
				'bg'     => '#eff6ff',
				'border' => '#bfdbfe',
				'icon'   => '🔧',
			),
			'warning'     => array(
				'bg'     => '#fefce8',
				'border' => '#fef08a',
				'icon'   => '⚠️',
			),
			'info'        => array(
				'bg'     => '#f0f9ff',
				'border' => '#bae6fd',
				'icon'   => 'ℹ️',
			),
		);
		$c       = isset( $palette[ $type ] ) ? $palette[ $type ] : $palette['info'];

		printf(
			'<div style="background:%1$s;border:1px solid %2$s;border-radius:6px;padding:12px;margin-bottom:16px;font-size:13px;">%3$s %4$s <a href="https://shopwalk.com/status" target="_blank" rel="noopener" style="margin-left:8px;">View status →</a></div>',
			esc_attr( $c['bg'] ),
			esc_attr( $c['border'] ),
			esc_html( $c['icon'] ),
			esc_html( $message )
		);
	}

	private function render_connect_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice
		$state = isset( $_GET['sw_connect'] ) ? sanitize_text_field( wp_unslash( $_GET['sw_connect'] ) ) : '';
		if ( '' === $state ) {
			return;
		}
		$reason = isset( $_GET['sw_reason'] ) ? sanitize_text_field( wp_unslash( $_GET['sw_reason'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$map = array(
			'ok'              => array( 'notice-success', __( 'Connected to Shopwalk. Free tier active.', 'woocommerce-ucp' ) ),
			'declined'        => array( 'notice-warning', __( 'Connection cancelled. No changes made.', 'woocommerce-ucp' ) ),
			'state_mismatch'  => array( 'notice-error', __( 'Connection failed: state mismatch. Please try again.', 'woocommerce-ucp' ) ),
			'exchange_failed' => array( 'notice-error', __( 'Connection failed while exchanging the code.', 'woocommerce-ucp' ) ),
		);
		if ( ! isset( $map[ $state ] ) ) {
			return;
		}
		list( $class, $msg ) = $map[ $state ];
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p><?php echo esc_html( $msg ); ?>
			<?php
			if ( '' !== $reason ) {
				echo ' <code>' . esc_html( $reason ) . '</code>';
			}
			?>
			</p>
		</div>
		<?php
	}

	// ── Tier detection ─────────────────────────────────────────────────────

	private function get_tier(): string {
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			return 'unlicensed';
		}
		$key = Shopwalk_License::key();
		if ( '' === $key ) {
			return 'unlicensed';
		}
		$plan = get_option( 'shopwalk_plan', 'free' );
		return 'pro' === $plan ? 'pro' : 'free';
	}

	// ── Page render ────────────────────────────────────────────────────────

	public function render_page(): void {
		$tier       = $this->get_tier();
		$tier_label = 'pro' === $tier ? 'Pro' : ( 'free' === $tier ? '' : '' );
		?>
		<div class="wrap sw-wrap">
			<h1>
				<?php esc_html_e( 'UCP Commerce', 'woocommerce-ucp' ); ?>
				<?php if ( 'free' === $tier || 'pro' === $tier ) : ?>
					<span class="sw-connected">✅ <?php esc_html_e( 'Connected', 'woocommerce-ucp' ); ?></span>
				<?php endif; ?>
				<?php if ( 'pro' === $tier ) : ?>
					<span class="sw-badge sw-badge-pro">PRO</span>
				<?php elseif ( 'free' === $tier ) : ?>
					<span class="sw-badge sw-badge-free">FREE</span>
				<?php endif; ?>
			</h1>

			<?php $this->render_styles(); ?>
			<?php $this->render_connect_notice(); ?>
			<?php $this->render_status_banner(); ?>
			<?php $this->render_ucp_tool( $tier ); ?>
			<?php $this->render_payments_tool(); ?>
			<?php if ( 'unlicensed' !== $tier ) : ?>
				<?php $this->render_sync_tool( $tier ); ?>
			<?php endif; ?>
			<?php $this->render_license_tool( $tier ); ?>
		</div>
		<?php
	}

	// ── UCP ────────────────────────────────────────────────────────────────

	private function render_ucp_tool( string $tier ): void {
		$product_count = wp_count_posts( 'product' )->publish ?? 0;
		?>
		<div class="sw-card">
			<h2><?php esc_html_e( 'UCP', 'woocommerce-ucp' ); ?></h2>

			<div id="sw-ucp-results">
				<p class="sw-muted"><?php esc_html_e( 'Click "Test Connectivity" to check your UCP endpoints.', 'woocommerce-ucp' ); ?></p>
			</div>

			<p class="sw-muted">
				<?php echo esc_html( sprintf( '%d products · Plugin v%s', $product_count, WOOCOMMERCE_UCP_VERSION ) ); ?>
			</p>

			<p>
				<button type="button" class="button button-primary" id="sw-probe-btn">
					<?php esc_html_e( 'Test Connectivity', 'woocommerce-ucp' ); ?>
				</button>
				<button type="button" class="button" id="sw-self-test-btn">
					<?php esc_html_e( 'Local Self-Test', 'woocommerce-ucp' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	// ── Payments ───────────────────────────────────────────────────────────

	private function render_payments_tool(): void {
		?>
		<div class="sw-card">
			<h2><?php esc_html_e( 'Payments', 'woocommerce-ucp' ); ?></h2>
			<p class="sw-muted">
				<?php esc_html_e( 'UCP agents complete payment using whichever WooCommerce gateways you already have configured. The plugin never asks for its own payment keys.', 'woocommerce-ucp' ); ?>
			</p>
			<div id="sw-payments-list" class="sw-muted">
				<?php esc_html_e( 'Loading payment gateways…', 'woocommerce-ucp' ); ?>
			</div>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?>" class="button">
					<?php esc_html_e( 'Open WooCommerce → Payments', 'woocommerce-ucp' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// ── Sync ───────────────────────────────────────────────────────────────

	private function render_sync_tool( string $tier ): void {
		?>
		<div class="sw-card">
			<h2><?php esc_html_e( 'Sync', 'woocommerce-ucp' ); ?></h2>

			<div id="sw-sync-info">
				<p class="sw-muted">Loading sync status...</p>
			</div>

			<p>
				<a href="https://shopwalk.com/partners/products" target="_blank" class="button button-primary">
					<?php esc_html_e( 'Manage Sync in Partner Portal', 'woocommerce-ucp' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// ── License ────────────────────────────────────────────────────────────

	private function render_license_tool( string $tier ): void {
		$license_key   = class_exists( 'Shopwalk_License' ) ? Shopwalk_License::key() : '';
		$partner_id    = class_exists( 'Shopwalk_License' ) ? Shopwalk_License::partner_id() : '';
		$license_state = class_exists( 'Shopwalk_License' ) ? Shopwalk_License::status() : 'unlicensed';
		$plan          = get_option( 'shopwalk_plan', 'free' );
		// No fallback to "Pro" — show whatever the API actually told us, and
		// default to "Free" for any non-pro plan so we never imply paid status
		// for a free or unrecognised plan.
		$plan_label = (string) get_option( 'shopwalk_plan_label', '' );
		if ( '' === $plan_label ) {
			$plan_label = 'pro' === $plan ? '' : 'Free';
		}
		$next_bill = get_option( 'shopwalk_next_billing_at', get_option( 'shopwalk_next_billing', '' ) );
		?>
		<div class="sw-card">
			<h2>
				<?php esc_html_e( 'License', 'woocommerce-ucp' ); ?>
				<?php if ( 'pro' === $tier ) : ?>
					<span class="sw-badge sw-badge-pro">PRO</span>
				<?php elseif ( 'free' === $tier ) : ?>
					<span class="sw-badge sw-badge-free">FREE</span>
				<?php endif; ?>
			</h2>

			<?php if ( 'unlicensed' === $tier ) : ?>
				<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:20px;margin-bottom:16px;">
					<p style="font-size:15px;font-weight:600;margin:0 0 8px;">Connect your store to Shopwalk</p>
					<p class="sw-muted" style="margin:0 0 12px;">
						Make your products discoverable by AI shopping agents. Shopwalk connects your WooCommerce store
						to the AI commerce network — your products appear in search results across Claude, ChatGPT, and
						other AI agents. 5% commission only on AI purchases made through Shopwalk.
					</p>
					<a href="<?php echo esc_url( Shopwalk_Connect::connect_url() ); ?>" class="button button-primary">
						<?php esc_html_e( 'Connect to Shopwalk', 'woocommerce-ucp' ); ?>
					</a>
					<p class="sw-muted" style="margin:12px 0 0;font-size:12px;">
						<?php esc_html_e( 'Opens shopwalk.com. After you approve, we mint a license bound to this domain.', 'woocommerce-ucp' ); ?>
					</p>
				</div>

				<h3><?php esc_html_e( 'Already have a license?', 'woocommerce-ucp' ); ?></h3>
				<p>
					<input type="text" id="sw-license-input" class="regular-text" placeholder="sw_site_..." value="" />
					<button type="button" class="button" id="sw-activate-btn">
						<?php esc_html_e( 'Activate', 'woocommerce-ucp' ); ?>
					</button>
				</p>
				<p id="sw-activate-status"></p>

			<?php else : ?>
				<table class="sw-details">
					<tr>
						<td><?php esc_html_e( 'License', 'woocommerce-ucp' ); ?></td>
						<td>
							<code id="sw-license-display"><?php echo esc_html( $license_key ); ?></code>
							<button type="button" class="button button-small" onclick="navigator.clipboard.writeText(document.getElementById('sw-license-display').textContent)">
								<?php esc_html_e( 'Copy', 'woocommerce-ucp' ); ?>
							</button>
						</td>
					</tr>
					<tr><td><?php esc_html_e( 'Partner ID', 'woocommerce-ucp' ); ?></td><td><code><?php echo esc_html( $partner_id ); ?></code></td></tr>
					<tr><td><?php esc_html_e( 'Plan', 'woocommerce-ucp' ); ?></td><td><?php echo esc_html( $plan_label ); ?></td></tr>
					<tr>
						<td><?php esc_html_e( 'Status', 'woocommerce-ucp' ); ?></td>
						<td>
							<?php
							switch ( $license_state ) {
								case 'active':
									echo '✅ ' . esc_html__( 'Active', 'woocommerce-ucp' );
									break;
								case 'expired':
									echo '⏳ ' . esc_html__( 'Expired', 'woocommerce-ucp' );
									break;
								case 'revoked':
									echo '⛔ ' . esc_html__( 'Revoked', 'woocommerce-ucp' );
									break;
								default:
									echo '— ' . esc_html__( 'Unknown', 'woocommerce-ucp' );
									break;
							}
							?>
						</td>
					</tr>
					<tr><td><?php esc_html_e( 'Domain', 'woocommerce-ucp' ); ?></td><td><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></td></tr>
					<?php if ( 'pro' === $tier && $next_bill ) : ?>
						<tr><td><?php esc_html_e( 'Next billing', 'woocommerce-ucp' ); ?></td><td><?php echo esc_html( $next_bill ); ?></td></tr>
					<?php endif; ?>
				</table>

				<p>
					<button type="button" class="button" id="sw-test-license-btn">
						<?php esc_html_e( 'Test License', 'woocommerce-ucp' ); ?>
					</button>
					<span class="sw-muted" id="sw-test-license-result"></span>
				</p>

				<h3><?php esc_html_e( 'Update License', 'woocommerce-ucp' ); ?></h3>
				<p>
					<input type="text" id="sw-license-input" class="regular-text" placeholder="sw_site_..." value="" />
					<button type="button" class="button" id="sw-activate-btn">
						<?php esc_html_e( 'Update', 'woocommerce-ucp' ); ?>
					</button>
				</p>
				<p id="sw-activate-status"></p>

				<?php if ( 'free' === $tier ) : ?>
					<div class="sw-upgrade-cta">
						<p><strong><?php esc_html_e( 'Upgrade to Pro', 'woocommerce-ucp' ); ?></strong></p>
						<p class="sw-muted"><?php esc_html_e( 'Take control of how AI represents your brand:', 'woocommerce-ucp' ); ?></p>
						<ul style="margin:4px 0 12px 16px;font-size:13px;color:#6b7280;">
							<li><?php esc_html_e( 'Analytics — see how AI agents find and recommend your products', 'woocommerce-ucp' ); ?></li>
							<li><?php esc_html_e( 'Brand Voice — control how AI describes your store and products', 'woocommerce-ucp' ); ?></li>
							<li><?php esc_html_e( 'Knowledge Base — teach AI about your shipping, returns, and policies', 'woocommerce-ucp' ); ?></li>
							<li><?php esc_html_e( 'Gap Analysis — discover what shoppers search for that you don\'t carry', 'woocommerce-ucp' ); ?></li>
							<li><?php esc_html_e( 'Priority Support — phone + email support', 'woocommerce-ucp' ); ?></li>
						</ul>
						<p>
							<button type="button" class="button button-primary sw-upgrade-btn" data-plan="annual">
								<?php esc_html_e( 'Upgrade to Pro — $278.40/yr', 'woocommerce-ucp' ); ?>
							</button>
							<button type="button" class="button sw-upgrade-btn" data-plan="monthly">
								<?php esc_html_e( 'Monthly — $29/mo', 'woocommerce-ucp' ); ?>
							</button>
							<span class="sw-muted" id="sw-upgrade-status" style="margin-left:8px;"></span>
						</p>
						<p class="sw-muted" style="font-size:12px;margin:8px 0 0;">
							<?php esc_html_e( '14-day free trial. Cancel any time before day 15 → no charge.', 'woocommerce-ucp' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<?php
				$is_paused = class_exists( 'Shopwalk_License' ) && Shopwalk_License::is_discovery_paused();
				?>
				<div class="sw-discovery-toggle">
					<label class="sw-toggle-row">
						<input
							type="checkbox"
							id="sw-discovery-toggle"
							<?php checked( ! $is_paused ); ?>
						/>
						<span class="sw-toggle-label">
							<?php esc_html_e( 'Allow Shopwalk to surface my store in AI discovery', 'woocommerce-ucp' ); ?>
						</span>
					</label>
					<p class="sw-muted sw-toggle-help">
						<?php esc_html_e( 'When off, your store and products are hidden from search, AI shopping, and store pages. Plugin stays connected; existing orders are unaffected. You can flip this back on any time.', 'woocommerce-ucp' ); ?>
					</p>
					<span class="sw-muted" id="sw-discovery-status"></span>
				</div>

				<p>
					<a href="<?php echo esc_url( SHOPWALK_PARTNERS_URL . '/dashboard' ); ?>" class="button" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open Partner Portal →', 'woocommerce-ucp' ); ?>
					</a>
					<a href="#" id="sw-disconnect-btn" class="sw-disconnect-link">
						<?php esc_html_e( 'Disconnect', 'woocommerce-ucp' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Styles ─────────────────────────────────────────────────────────────

	private function render_styles(): void {
		?>
		<style>
			.sw-wrap { max-width: 760px; }
			.sw-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; padding: 20px 24px; margin-bottom: 20px; }
			.sw-card h2 { margin-top: 0; display: flex; align-items: center; gap: 8px; }
			.sw-card h3 { margin-bottom: 8px; }
			.sw-connected { color: #065f46; font-size: 14px; font-weight: 400; }
			.sw-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: 0.5px; }
			.sw-badge-pro { background: #11CF52; color: #fff; }
			.sw-badge-free { background: #e5e7eb; color: #374151; }
			.sw-muted { color: #6b7280; font-size: 13px; }
			.sw-stats { display: flex; gap: 16px; margin-bottom: 16px; }
			.sw-stat { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; text-align: center; min-width: 80px; }
			.sw-stat-value { font-size: 24px; font-weight: 700; color: #111; }
			.sw-stat-label { font-size: 12px; color: #6b7280; margin-top: 2px; }
			.sw-details { border-collapse: collapse; width: 100%; margin-bottom: 12px; }
			.sw-details td { padding: 6px 12px 6px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
			.sw-details td:first-child { color: #6b7280; white-space: nowrap; width: 120px; }
			.sw-progress-bar { background: #e5e7eb; border-radius: 6px; height: 8px; overflow: hidden; margin-bottom: 8px; }
			.sw-progress-fill { background: #11CF52; height: 100%; width: 0%; transition: width 0.3s; border-radius: 6px; }
			.sw-upgrade-cta { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 16px; margin: 16px 0; }
			.sw-disconnect-link { color: #991b1b; text-decoration: none; margin-left: 12px; font-size: 13px; }
			.sw-disconnect-link:hover { text-decoration: underline; }
			.sw-discovery-toggle { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; margin: 16px 0; }
			.sw-toggle-row { display: flex; align-items: center; gap: 10px; cursor: pointer; }
			.sw-toggle-row input[type="checkbox"] { transform: scale(1.2); }
			.sw-toggle-label { font-weight: 500; }
			.sw-toggle-help { margin: 6px 0 0; font-size: 12px; }
			.sw-check-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
			.sw-check-row:last-child { border-bottom: 0; }
		</style>
		<?php
	}

	// ── JS ──────────────────────────────────────────────────────────────────

	private function admin_js(): string {
		return <<<'JS'
(function () {
	var s = window.swAdmin;
	if (!s) return;

	function $(id) { return document.getElementById(id); }

	function postAjax(action, body) {
		var data = new URLSearchParams();
		data.append('action', action);
		Object.keys(body || {}).forEach(function (k) { data.append(k, body[k]); });
		return fetch(s.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (r) { return r.json(); });
	}

	function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

	// ── Probe (Test Connectivity via Shopwalk) ──────────────────────────
	var probeBtn = $('sw-probe-btn');
	if (probeBtn) {
		probeBtn.addEventListener('click', function () {
			var out = $('sw-ucp-results');
			probeBtn.disabled = true;
			probeBtn.textContent = 'Testing…';
			out.innerHTML = '<p class="sw-muted">Connecting to Shopwalk servers…</p>';

			postAjax('shopwalk_probe', { nonce: s.nonces.probe }).then(function (resp) {
				probeBtn.disabled = false;
				probeBtn.textContent = 'Test Connectivity';
				if (!resp || !resp.success) {
					var errMsg = resp && resp.data && resp.data.message || 'unknown error';
					var html = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px;font-size:13px;">';
					html += '<strong>Cannot reach Shopwalk API</strong> — ' + esc(errMsg) + '<br><br>';
					html += 'This means the Shopwalk platform is temporarily unavailable. Your store and products are not affected.<br>';
					html += 'Check <a href="https://shopwalk.com/status" target="_blank">shopwalk.com/status</a> or try again in a few minutes.';
					html += '</div>';
					out.innerHTML = html;
					return;
				}
				var d = resp.data;
				var html = '';

				// Endpoints
				html += '<strong>UCP Endpoints</strong>';
				html += '<div class="sw-check-row"><span>' + (d.reachable ? '✅' : '❌') + ' Store Info — /ucp/store</span><span class="sw-muted">' + (d.latency_ms || '') + 'ms</span></div>';
				html += '<div class="sw-check-row"><span>' + (d.products_ok ? '✅' : '❌') + ' Products — /ucp/products</span><span class="sw-muted">' + (d.products_issue || '') + '</span></div>';
				html += '<div class="sw-check-row"><span>' + (d.discovery_ok ? '✅' : '❌') + ' Discovery — /.well-known/ucp</span><span class="sw-muted">' + (d.discovery_issue || '') + '</span></div>';

				// Store info
				if (d.product_count) {
					html += '<p class="sw-muted">' + d.product_count + ' products · ' + (d.in_stock_count || 0) + ' in stock · ' + (d.currency || 'USD') + '</p>';
				}

				// Connectivity
				html += '<br><strong>Connectivity</strong>';
				if (d.reachable) {
					html += '<div class="sw-check-row"><span>✅ Reachable from Shopwalk</span><span class="sw-muted">' + d.latency_ms + 'ms</span></div>';
				} else {
					html += '<div class="sw-check-row"><span>❌ ' + esc(d.reason || 'Unreachable') + '</span></div>';
					html += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px;margin:8px 0;font-size:13px;">';
					html += '<strong>Shopwalk cannot reach your store.</strong><br><br>';

					if (d.host_name) {
						html += '<strong>Your hosting provider: ' + esc(d.host_name) + '</strong><br><br>';
						html += 'Call your host and ask them to:<br>';
						html += '• Whitelist this IP address: <strong>15.204.101.254</strong><br>';
						html += '• Whitelist this User-Agent: <strong>Shopwalk-AI-Shopping</strong><br>';
						html += '• Ensure the REST API path <strong>/wp-json/ucp/v1/</strong> is not blocked by WAF or ModSecurity rules<br><br>';
						html += '<strong>What to say:</strong> "I have a WordPress plugin that uses the REST API. An external service at IP 15.204.101.254 needs to reach my site\'s /wp-json/ endpoints. Can you whitelist this IP and make sure no firewall or ModSecurity rule is blocking it?"<br><br>';
						if (d.host_phone) {
							html += 'Phone: <strong>' + esc(d.host_phone) + '</strong><br>';
						}
						if (d.host_support) {
							html += 'Support: <strong>' + esc(d.host_support) + '</strong><br>';
						}
					} else {
						html += 'Your hosting provider could not be detected. Contact your host and ask them to:<br>';
						html += '• Whitelist IP: <strong>15.204.101.254</strong><br>';
						html += '• Whitelist User-Agent: <strong>Shopwalk-AI-Shopping</strong><br>';
						html += '• Ensure <strong>/wp-json/ucp/v1/</strong> is not blocked by firewall rules<br>';
					}
					html += '</div>';
				}
				if (d.host_name) {
					html += '<div class="sw-check-row"><span>Hosting: ' + esc(d.host_name) + '</span>';
					if (d.host_phone) html += '<span class="sw-muted">' + esc(d.host_phone) + '</span>';
					html += '</div>';
				}
				if (d.ucp_version) {
					html += '<p class="sw-muted">UCP v' + esc(d.ucp_version) + (d.plugin_version ? ' · Plugin v' + esc(d.plugin_version) : '') + '</p>';
				}

				out.innerHTML = html;
			}).catch(function () {
				probeBtn.disabled = false;
				probeBtn.textContent = 'Test Connectivity';
				out.innerHTML = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px;font-size:13px;"><strong>Network error</strong> — could not connect. Check your internet connection and try again.</div>';
			});
		});
	}

	// ── Self-test (local) ───────────────────────────────────────────────
	var selfTestBtn = $('sw-self-test-btn');
	if (selfTestBtn) {
		selfTestBtn.addEventListener('click', function () {
			var out = $('sw-ucp-results');
			selfTestBtn.disabled = true;
			selfTestBtn.textContent = 'Testing…';
			postAjax('shopwalk_self_test', { nonce: s.nonces.self_test }).then(function (resp) {
				selfTestBtn.disabled = false;
				selfTestBtn.textContent = 'Local Self-Test';
				if (!resp || !resp.success) {
					out.innerHTML = '<p style="color:#991b1b;">Self-test failed</p>';
					return;
				}
				var html = '<strong>Local Self-Test</strong>';
				var hasFail = false;
				var failMessages = [];
				(resp.data.checks || []).forEach(function (c) {
					var icon = c.status === 'pass' ? '✅' : c.status === 'warn' ? '⚠️' : '❌';
					html += '<div class="sw-check-row"><span>' + icon + ' ' + esc(c.check) + '</span><span class="sw-muted">' + esc(c.message) + '</span></div>';
					if (c.status === 'fail') {
						hasFail = true;
						failMessages.push(c.check + ': ' + c.message);
					}
				});

				if (hasFail) {
					var h = resp.data.host || {};
					html += '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px;margin:8px 0;font-size:13px;">';
					html += '<strong>Server issues detected</strong> — these require changes by your hosting provider.<br><br>';
					if (h.name) {
						html += '<strong>Your host: ' + esc(h.name) + '</strong><br>';
						html += 'Contact them and ask to resolve:<br>';
						html += '<ul style="margin:4px 0 8px 16px;">';
						failMessages.forEach(function (m) { html += '<li>' + esc(m) + '</li>'; });
						html += '</ul>';
						if (h.phone) html += 'Phone: <strong>' + esc(h.phone) + '</strong><br>';
						if (h.support) html += 'Support: <strong>' + esc(h.support) + '</strong><br>';
					} else {
						html += 'Contact your hosting provider and ask them to resolve:<br>';
						html += '<ul style="margin:4px 0 0 16px;">';
						failMessages.forEach(function (m) { html += '<li>' + esc(m) + '</li>'; });
						html += '</ul>';
					}
					html += '</div>';
				}

				out.innerHTML = html;
			});
		});
	}

	// ── Activate / Update License ───────────────────────────────────────
	var activateBtn = $('sw-activate-btn');
	if (activateBtn) {
		activateBtn.addEventListener('click', function () {
			var input = $('sw-license-input');
			var status = $('sw-activate-status');
			if (!input || !input.value.trim()) {
				status.innerHTML = '<span style="color:#991b1b;">License key is required.</span>';
				return;
			}
			activateBtn.disabled = true;
			status.innerHTML = '<span class="sw-muted">Validating…</span>';

			postAjax('shopwalk_activate', { nonce: s.nonces.activate, license_key: input.value.trim() }).then(function (resp) {
				activateBtn.disabled = false;
				if (resp && resp.success) {
					status.innerHTML = '<span style="color:#065f46;">✅ ' + esc(resp.data.message || 'License activated') + '</span>';
					setTimeout(function () { window.location.reload(); }, 1000);
				} else {
					var current = $('sw-license-display');
					var msg = (resp && resp.data && resp.data.message) || 'Activation failed.';
					if (current) {
						msg += ' Your current license is unchanged.';
					}
					status.innerHTML = '<span style="color:#991b1b;">❌ ' + esc(msg) + '</span>';
				}
			});
		});
	}

	// ── Test License ────────────────────────────────────────────────────
	var testBtn = $('sw-test-license-btn');
	if (testBtn) {
		testBtn.addEventListener('click', function () {
			var result = $('sw-test-license-result');
			testBtn.disabled = true;
			result.textContent = 'Checking…';
			postAjax('shopwalk_test_license', { nonce: s.nonces.test_license }).then(function (resp) {
				testBtn.disabled = false;
				if (resp && resp.success && resp.data.valid) {
					result.innerHTML = '<span style="color:#065f46;">✅ Valid · ' + esc(resp.data.plan || 'Free') + ' plan</span>';
				} else {
					result.innerHTML = '<span style="color:#991b1b;">❌ ' + esc(resp && resp.data && resp.data.message || 'Validation failed') + '</span>';
				}
			});
		});
	}

	// ── Upgrade to Pro ──────────────────────────────────────────────────
	var upgradeStatus = $('sw-upgrade-status');
	document.querySelectorAll('.sw-upgrade-btn').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var plan = btn.getAttribute('data-plan') || 'annual';
			if (upgradeStatus) upgradeStatus.textContent = 'Opening Stripe…';
			document.querySelectorAll('.sw-upgrade-btn').forEach(function (b) { b.disabled = true; });
			postAjax('shopwalk_upgrade_url', { nonce: s.nonces.upgrade_url, plan: plan }).then(function (resp) {
				if (resp && resp.success && resp.data && resp.data.url) {
					window.location.href = resp.data.url;
					return;
				}
				document.querySelectorAll('.sw-upgrade-btn').forEach(function (b) { b.disabled = false; });
				if (upgradeStatus) upgradeStatus.textContent = 'Could not start upgrade. ' +
					((resp && resp.data && resp.data.message) ? '(' + resp.data.message + ')' : '');
			});
		});
	});

	// ── Disconnect ──────────────────────────────────────────────────────
	var disconnectBtn = $('sw-disconnect-btn');
	if (disconnectBtn) {
		disconnectBtn.addEventListener('click', function (e) {
			e.preventDefault();
			if (!confirm('Disconnect from Shopwalk? Your products will no longer be synced. UCP endpoints continue working independently.')) return;
			postAjax('shopwalk_disconnect', { nonce: s.nonces.disconnect }).then(function (resp) {
				if (resp && resp.success) window.location.reload();
				else alert((resp && resp.data && resp.data.message) || 'Disconnect failed.');
			});
		});
	}

	// ── Pause/Resume AI discovery ──────────────────────────────────────
	var discoveryToggle = $('sw-discovery-toggle');
	var discoveryStatus = $('sw-discovery-status');
	if (discoveryToggle) {
		discoveryToggle.addEventListener('change', function () {
			var enable = discoveryToggle.checked;
			var prev = !enable;
			if (!enable && !confirm('Pause AI discovery? Your store and products will be hidden from search, AI shopping, and store pages within ~2 minutes. Existing orders are unaffected.')) {
				discoveryToggle.checked = true;
				return;
			}
			discoveryToggle.disabled = true;
			if (discoveryStatus) discoveryStatus.textContent = enable ? 'Resuming…' : 'Pausing…';
			postAjax('shopwalk_toggle_discovery', { nonce: s.nonces.toggle_discovery, enable: enable ? '1' : '0' }).then(function (resp) {
				discoveryToggle.disabled = false;
				if (resp && resp.success) {
					if (discoveryStatus) discoveryStatus.textContent = enable ? 'Discovery resumed.' : 'Discovery paused.';
				} else {
					discoveryToggle.checked = prev;
					if (discoveryStatus) discoveryStatus.textContent = (resp && resp.data && resp.data.message) || 'Failed.';
				}
			});
		});
	}

	// ── Sync Status (loaded from API on page load) ────────────────────
	var syncInfo = $('sw-sync-info');

	function loadSyncStatus() {
		if (!syncInfo) return;
		postAjax('shopwalk_sync_status', { nonce: s.nonces.sync_status }).then(function (resp) {
			if (!resp || !resp.success) {
				syncInfo.innerHTML = '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:12px;font-size:13px;"><strong>Cannot reach Shopwalk API</strong><br>Check <a href="https://shopwalk.com/status" target="_blank">shopwalk.com/status</a> or try again later.</div>';
				return;
			}
			var d = resp.data;
			var st = d.sync || {};
			var synced = st.synced_count || 0;
			var total = st.product_count || 0;
			var status = st.status || 'never';
			var lastSync = st.last_synced_at ? new Date(st.last_synced_at).toLocaleString() : 'Never';

			var statusLabel = status === 'complete' ? 'Complete' : status === 'syncing' ? 'Syncing' : 'Never synced';

			var html = '<div class="sw-stats">';
			html += '<div class="sw-stat"><div class="sw-stat-value">' + (d.local_count || 0).toLocaleString() + '</div><div class="sw-stat-label">WooCommerce</div></div>';
			html += '<div class="sw-stat"><div class="sw-stat-value">' + synced.toLocaleString() + '</div><div class="sw-stat-label">Synced</div></div>';
			html += '</div>';

			html += '<table class="sw-details">';
			html += '<tr><td>Status</td><td>' + esc(statusLabel) + '</td></tr>';
			html += '<tr><td>Last synced</td><td>' + esc(lastSync) + '</td></tr>';
			html += '</table>';

			syncInfo.innerHTML = html;
		});
	}

	loadSyncStatus();

	// ── Payments (registered UCP adapters + readiness) ──────────────────
	var paymentsEl = $('sw-payments-list');
	if (paymentsEl) {
		postAjax('shopwalk_payments_status', { nonce: s.nonces.payments_status }).then(function (resp) {
			if (!resp || !resp.success) {
				paymentsEl.innerHTML = '<p class="sw-muted">Could not load payment gateways.</p>';
				return;
			}
			var gateways = (resp.data && resp.data.gateways) || [];
			if (!gateways.length) {
				paymentsEl.innerHTML = '<p class="sw-muted">No UCP payment adapters registered.</p>';
				return;
			}
			var html = '';
			gateways.forEach(function (g) {
				var icon = g.ready ? '✅' : '⬜';
				var state = g.ready ? 'Ready' + (g.mode ? ' · ' + esc(g.mode) : '') : 'Not configured';
				html += '<div class="sw-check-row">';
				html += '<span>' + icon + ' ' + esc(g.label) + ' <span class="sw-muted">' + esc(state) + '</span></span>';
				html += '<span>';
				if (g.ready) {
					html += '<a href="' + esc(g.settings_url) + '">Manage →</a>';
				} else if (g.install_url) {
					html += '<a href="' + esc(g.settings_url) + '">Add keys</a> · ';
					html += '<a href="' + esc(g.install_url) + '">' + esc(g.install_label) + '</a>';
				} else {
					html += '<a href="' + esc(g.settings_url) + '">Configure →</a>';
				}
				html += '</span>';
				html += '</div>';
			});
			paymentsEl.innerHTML = html;
		});
	}

})();
JS;
	}

	// ── AJAX Handlers ──────────────────────────────────────────────────────

	public function ajax_self_test(): void {
		check_ajax_referer( 'shopwalk_self_test', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		$checks = array();

		// 1. WooCommerce active
		$wc_active = class_exists( 'WooCommerce' );
		$checks[]  = array(
			'check'   => 'WooCommerce',
			'status'  => $wc_active ? 'pass' : 'fail',
			'message' => $wc_active ? 'v' . WC()->version : 'Not active',
		);

		// 2. Permalinks (REST API requires non-Plain)
		$permalink = get_option( 'permalink_structure', '' );
		$checks[]  = array(
			'check'   => 'Permalinks',
			'status'  => ! empty( $permalink ) ? 'pass' : 'fail',
			'message' => ! empty( $permalink ) ? $permalink : 'Plain — REST API will not work',
		);

		// 3. REST API enabled
		$rest_url = get_rest_url();
		$rest_ok  = ! empty( $rest_url );
		$checks[] = array(
			'check'   => 'REST API',
			'status'  => $rest_ok ? 'pass' : 'fail',
			'message' => $rest_ok ? 'Enabled' : 'Disabled — check for plugins blocking REST API',
		);

		// 4. License key
		$license  = get_option( 'shopwalk_license_key', '' );
		$checks[] = array(
			'check'   => 'License key',
			'status'  => ! empty( $license ) ? 'pass' : 'fail',
			'message' => ! empty( $license ) ? substr( $license, 0, 8 ) . '...' : 'Not set',
		);

		// 5. Product count
		$product_counts = wp_count_posts( 'product' );
		$total          = (int) ( $product_counts->publish ?? 0 );
		$checks[]       = array(
			'check'   => 'Published products',
			'status'  => $total > 0 ? 'pass' : 'warn',
			'message' => number_format( $total ),
		);

		// 6. PHP version
		$php_ok   = version_compare( PHP_VERSION, '7.4', '>=' );
		$checks[] = array(
			'check'   => 'PHP version',
			'status'  => $php_ok ? 'pass' : 'warn',
			'message' => PHP_VERSION,
		);

		// 7. PHP extensions
		$required_exts = array( 'json', 'curl', 'openssl', 'mbstring' );
		$missing_exts  = array();
		foreach ( $required_exts as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$missing_exts[] = $ext;
			}
		}
		$checks[] = array(
			'check'   => 'PHP extensions',
			'status'  => empty( $missing_exts ) ? 'pass' : 'fail',
			'message' => empty( $missing_exts ) ? implode( ', ', $required_exts ) : 'Missing: ' . implode( ', ', $missing_exts ),
		);

		// 8. Memory limit
		$mem_limit = ini_get( 'memory_limit' );
		$mem_bytes = wp_convert_hr_to_bytes( $mem_limit );
		$mem_ok    = $mem_bytes >= 128 * 1024 * 1024;
		$checks[]  = array(
			'check'   => 'Memory limit',
			'status'  => $mem_ok ? 'pass' : 'warn',
			'message' => $mem_limit . ( $mem_ok ? '' : ' — recommend 128M+' ),
		);

		// 9. WP Cron (needed for scheduled sync)
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$checks[]      = array(
			'check'   => 'WP Cron',
			'status'  => ! $cron_disabled ? 'pass' : 'warn',
			'message' => $cron_disabled ? 'Disabled — scheduled sync requires cron' : 'Enabled',
		);

		// 10. SSL certificate
		$is_ssl   = is_ssl();
		$checks[] = array(
			'check'   => 'SSL/HTTPS',
			'status'  => $is_ssl ? 'pass' : 'warn',
			'message' => $is_ssl ? 'Active' : 'Not HTTPS — recommended for API security',
		);

		// 11. .htaccess REST API blocking (Apache only)
		if ( function_exists( 'apache_get_modules' ) || stripos( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ), 'apache' ) !== false ) {
			$htaccess = ABSPATH . '.htaccess';
			if ( file_exists( $htaccess ) ) {
				$content     = file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local .htaccess for diagnostic check, no remote fetch.
				$blocks_json = preg_match( '/RewriteRule.*wp-json.*\[F\]|Deny.*wp-json/i', $content );
				$checks[]    = array(
					'check'   => '.htaccess',
					'status'  => $blocks_json ? 'fail' : 'pass',
					'message' => $blocks_json ? 'May block /wp-json/ — check rewrite rules' : 'OK',
				);
			}
		}

		// 12. Loopback test (can WP reach itself)
		$loop_resp = wp_remote_get(
			rest_url( 'ucp/v1/store' ),
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		);
		$loop_code = is_wp_error( $loop_resp ) ? 0 : wp_remote_retrieve_response_code( $loop_resp );
		$checks[]  = array(
			'check'   => 'Loopback',
			'status'  => 200 === $loop_code ? 'pass' : 'fail',
			'message' => 200 === $loop_code ? 'WordPress can reach its own REST API' : 'Failed — loopback blocked (HTTP ' . $loop_code . ')',
		);

		// Detect hosting provider for support CTA
		$host_info = $this->detect_hosting();

		wp_send_json_success(
			array(
				'checks' => $checks,
				'host'   => $host_info,
			)
		);
	}

	/**
	 * Detect the hosting provider from server environment.
	 */
	private function detect_hosting(): array {
		$server_sw = sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
		$hostname  = gethostname() ?: '';
		$server_ip = sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ?? '' ) );

		// Check known hosting signatures
		$hosts = array(
			'bluehost'        => array(
				'name'    => 'Bluehost',
				'phone'   => '1-888-401-4678',
				'support' => 'bluehost.com/support',
			),
			'siteground'      => array(
				'name'    => 'SiteGround',
				'phone'   => '1-800-828-9231',
				'support' => 'siteground.com/support',
			),
			'hostgator'       => array(
				'name'    => 'HostGator',
				'phone'   => '1-866-964-2867',
				'support' => 'hostgator.com/support',
			),
			'godaddy'         => array(
				'name'    => 'GoDaddy',
				'phone'   => '1-480-505-8877',
				'support' => 'godaddy.com/help',
			),
			'dreamhost'       => array(
				'name'    => 'DreamHost',
				'phone'   => '1-714-706-4182',
				'support' => 'dreamhost.com/support',
			),
			'wpengine'        => array(
				'name'    => 'WP Engine',
				'phone'   => '1-877-973-6446',
				'support' => 'wpengine.com/support',
			),
			'kinsta'          => array(
				'name'    => 'Kinsta',
				'phone'   => '',
				'support' => 'kinsta.com/support',
			),
			'cloudways'       => array(
				'name'    => 'Cloudways',
				'phone'   => '',
				'support' => 'cloudways.com/support',
			),
			'flywheel'        => array(
				'name'    => 'Flywheel',
				'phone'   => '',
				'support' => 'getflywheel.com/support',
			),
			'namecheap'       => array(
				'name'    => 'Namecheap',
				'phone'   => '1-888-401-4678',
				'support' => 'namecheap.com/support',
			),
			'inmotionhosting' => array(
				'name'    => 'InMotion Hosting',
				'phone'   => '1-888-321-HOST',
				'support' => 'inmotionhosting.com/support',
			),
			'liquidweb'       => array(
				'name'    => 'Liquid Web',
				'phone'   => '1-800-580-4985',
				'support' => 'liquidweb.com/support',
			),
			'a2hosting'       => array(
				'name'    => 'A2 Hosting',
				'phone'   => '1-888-546-8946',
				'support' => 'a2hosting.com/support',
			),
		);

		$search = strtolower( $server_sw . ' ' . $hostname . ' ' . $server_ip . ' ' . sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ?? '' ) ) );
		foreach ( $hosts as $key => $info ) {
			if ( strpos( $search, $key ) !== false ) {
				return $info;
			}
		}

		// Try reverse DNS on server IP
		if ( $server_ip ) {
			$rdns = gethostbyaddr( $server_ip );
			if ( $rdns && $rdns !== $server_ip ) {
				$rdns_lower = strtolower( $rdns );
				foreach ( $hosts as $key => $info ) {
					if ( strpos( $rdns_lower, $key ) !== false ) {
						return $info;
					}
				}
			}
		}

		return array(
			'name'    => '',
			'phone'   => '',
			'support' => '',
		);
	}

	public function ajax_payments_status(): void {
		check_ajax_referer( 'shopwalk_payments_status', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$adapters = class_exists( 'UCP_Payment_Router' ) ? UCP_Payment_Router::registry() : array();
		$out      = array();

		foreach ( $adapters as $gateway_id => $class ) {
			$adapter = new $class();
			$ready   = $adapter instanceof UCP_Payment_Adapter_Interface ? $adapter->is_ready() : false;
			$hint    = $ready ? $adapter->discovery_hint() : array();

			$out[] = array(
				'id'            => (string) $gateway_id,
				'label'         => $this->gateway_label( $gateway_id ),
				'ready'         => $ready,
				'mode'          => (string) ( $hint['mode'] ?? '' ),
				'settings_url'  => $this->gateway_settings_url( $gateway_id ),
				'install_url'   => $ready ? '' : $this->gateway_install_url( $gateway_id ),
				'install_label' => $ready ? '' : $this->gateway_install_label( $gateway_id ),
			);
		}

		wp_send_json_success( array( 'gateways' => $out ) );
	}

	/**
	 * Friendly display name for a gateway id. Third-party adapters can
	 * override via the shopwalk_ucp_payment_gateway_labels filter.
	 */
	private function gateway_label( string $id ): string {
		$labels = apply_filters(
			'shopwalk_ucp_payment_gateway_labels',
			array(
				'stripe'      => 'Stripe',
				'ppcp'        => 'PayPal',
				'square'      => 'Square',
				'authnet'     => 'Authorize.net',
				'amazon_pay'  => 'Amazon Pay',
				'woopayments' => 'WooPayments',
			)
		);
		return (string) ( $labels[ $id ] ?? ucfirst( str_replace( '_', ' ', $id ) ) );
	}

	/**
	 * Deep link to the WC settings page for a given gateway. Filterable so
	 * third-party adapters can point at their own settings page.
	 */
	private function gateway_settings_url( string $id ): string {
		$map = apply_filters(
			'shopwalk_ucp_payment_gateway_settings_urls',
			array(
				'stripe'      => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=stripe' ),
				'ppcp'        => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway' ),
				'square'      => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=square_credit_card' ),
				'woopayments' => admin_url( 'admin.php?page=wc-admin&path=/payments/overview' ),
			)
		);
		return (string) ( $map[ $id ] ?? admin_url( 'admin.php?page=wc-settings&tab=checkout' ) );
	}

	/**
	 * URL to install the underlying WC gateway plugin when the adapter
	 * reports not-ready. Filterable.
	 */
	private function gateway_install_url( string $id ): string {
		$map = apply_filters(
			'shopwalk_ucp_payment_gateway_install_urls',
			array(
				'stripe'      => admin_url( 'plugin-install.php?s=woocommerce+stripe&tab=search&type=term' ),
				'ppcp'        => admin_url( 'plugin-install.php?s=woocommerce+paypal+payments&tab=search&type=term' ),
				'square'      => admin_url( 'plugin-install.php?s=woocommerce+square&tab=search&type=term' ),
				'woopayments' => admin_url( 'plugin-install.php?s=woopayments&tab=search&type=term' ),
			)
		);
		return (string) ( $map[ $id ] ?? '' );
	}

	private function gateway_install_label( string $id ): string {
		$label = $this->gateway_label( $id );
		return sprintf( 'Install WooCommerce %s →', $label );
	}

	public function ajax_sync_status(): void {
		check_ajax_referer( 'shopwalk_sync_status', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$license_key = get_option( 'shopwalk_license_key', '' );
		if ( ! $license_key ) {
			wp_send_json_error( array( 'message' => 'No license key configured.' ) );
		}

		$api_url  = defined( 'SHOPWALK_API_URL' ) ? SHOPWALK_API_URL : 'https://api.shopwalk.com';
		$domain   = wp_parse_url( home_url(), PHP_URL_HOST );
		$response = wp_remote_get(
			$api_url . '/api/v1/plugin/status',
			array(
				'headers' => array(
					'X-API-Key' => $license_key,
				),
				'timeout' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wp_send_json_error( array( 'message' => 'HTTP ' . wp_remote_retrieve_response_code( $response ) ) );
		}

		$body        = json_decode( wp_remote_retrieve_body( $response ), true );
		$local_count = (int) ( wp_count_posts( 'product' )->publish ?? 0 );

		wp_send_json_success(
			array(
				'sync'        => $body['sync'] ?? array(),
				'local_count' => $local_count,
			)
		);
	}

	public function ajax_probe(): void {
		check_ajax_referer( 'shopwalk_probe', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$license_key = get_option( 'shopwalk_license_key', '' );
		if ( ! $license_key ) {
			wp_send_json_error( array( 'message' => 'No license key configured.' ) );
		}

		$resp = wp_remote_post(
			SHOPWALK_API_BASE . '/plugin/connectivity',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => $license_key,
					'User-Agent'   => 'woocommerce-ucp-plugin/' . WOOCOMMERCE_UCP_VERSION,
				),
				'body'    => wp_json_encode( array( 'store_url' => home_url() ) ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( array( 'message' => 'Could not reach Shopwalk API: ' . $resp->get_error_message() ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		wp_send_json_success( $body ? $body : array() );
	}

	public function ajax_activate(): void {
		check_ajax_referer( 'shopwalk_activate', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		$new_key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( ! $new_key ) {
			wp_send_json_error( array( 'message' => 'License key is required.' ) );
		}

		// Validate the new key BEFORE replacing the old one
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			require_once WOOCOMMERCE_UCP_PLUGIN_DIR . 'includes/shopwalk/class-shopwalk-license.php';
		}

		$result = Shopwalk_License::activate( $new_key );
		if ( $result['ok'] ?? false ) {
			// Shopwalk_License::activate() persists plan / plan_label /
			// next_billing_date / status to options itself; we just relay
			// the user-facing message here.
			$plan = $result['plan'] ?? '';
			$msg  = '' !== $plan ? 'License activated. Plan: ' . ucfirst( $plan ) : 'License activated.';
			wp_send_json_success( array( 'message' => $msg ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ?? 'Activation failed.' ) );
		}
	}

	public function ajax_test_license(): void {
		check_ajax_referer( 'shopwalk_test_license', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		if ( ! class_exists( 'Shopwalk_License' ) ) {
			wp_send_json_error( array( 'message' => 'License module not loaded.' ) );
		}

		$key = Shopwalk_License::key();
		if ( ! $key ) {
			wp_send_json_error( array( 'message' => 'No license key configured.' ) );
		}

		$result = Shopwalk_License::activate( $key );
		$valid  = $result['ok'] ?? false;
		$plan   = $result['plan'] ?? 'free';
		// Persistence happens inside Shopwalk_License::activate(); nothing
		// to do here beyond shaping the ajax response.

		wp_send_json_success(
			array(
				'valid'   => $valid,
				'plan'    => $plan,
				'message' => $valid ? 'License is valid' : ( $result['message'] ?? 'Validation failed' ),
			)
		);
	}

	public function ajax_disconnect(): void {
		check_ajax_referer( 'shopwalk_disconnect', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}

		if ( class_exists( 'Shopwalk_License' ) ) {
			Shopwalk_License::deactivate();
		}

		delete_option( 'shopwalk_plan' );
		delete_option( 'shopwalk_plan_label' );
		delete_option( 'shopwalk_next_billing' );
		delete_option( 'shopwalk_next_billing_at' );
		delete_option( 'shopwalk_sync_state' );
		delete_option( 'shopwalk_sync_history' );

		wp_send_json_success( array( 'message' => 'Disconnected from Shopwalk.' ) );
	}

	/**
	 * AJAX: pause or resume AI discovery for the connected store.
	 *
	 * Body: enable=1 to resume, enable=0 to pause.
	 */
	public function ajax_toggle_discovery(): void {
		check_ajax_referer( 'shopwalk_toggle_discovery', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
		}
		if ( ! class_exists( 'Shopwalk_License' ) ) {
			wp_send_json_error( array( 'message' => 'Shopwalk not connected.' ), 400 );
		}
		$enable = isset( $_POST['enable'] ) && '1' === $_POST['enable'];
		$ok     = $enable ? Shopwalk_License::resume_discovery() : Shopwalk_License::pause_discovery();
		if ( ! $ok ) {
			wp_send_json_error(
				array(
					'message' => __( 'Could not reach Shopwalk. Try again in a moment.', 'woocommerce-ucp' ),
				),
				502
			);
		}
		wp_send_json_success(
			array(
				'paused' => ! $enable,
			)
		);
	}
}
