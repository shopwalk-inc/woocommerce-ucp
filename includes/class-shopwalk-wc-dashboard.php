<?php
/**
 * Shopwalk AI — Merchant Dashboard
 *
 * Shows store health, products indexed, AI agent activity, subscription info, and self-service tools.
 *
 * @package ShopwalkAI
 * @since   1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Shopwalk_WC_Dashboard {

	private const DASHBOARD_ENDPOINT = 'https://api.shopwalk.com/api/v1/plugin/dashboard';
	private const BILLING_ENDPOINT   = 'https://api.shopwalk.com/api/v1/plugin/billing-info';
	private const UPGRADE_ENDPOINT   = 'https://api.shopwalk.com/api/v1/plugin/upgrade';
	private const DOWNGRADE_ENDPOINT = 'https://api.shopwalk.com/api/v1/plugin/downgrade';
	private const CANCEL_ENDPOINT    = 'https://api.shopwalk.com/api/v1/plugin/cancel';
	private const MIGRATE_ENDPOINT   = 'https://api.shopwalk.com/api/v1/plugin/migrate';
	private const PORTAL_ENDPOINT    = 'https://api.shopwalk.com/api/v1/plugin/portal-url';
	private const HEALTH_ENDPOINT    = 'https://api.shopwalk.com/health';
	private const CACHE_KEY          = 'shopwalk_wc_dashboard_cache';
	private const CACHE_TTL          = 300; // 5 minutes

	public function __construct() {
		add_action( 'wp_ajax_shopwalk_fetch_dashboard', [ $this, 'ajax_fetch_dashboard' ] );
		add_action( 'wp_ajax_shopwalk_fetch_billing',   [ $this, 'ajax_fetch_billing' ] );
		add_action( 'wp_ajax_shopwalk_upgrade',         [ $this, 'ajax_upgrade' ] );
		add_action( 'wp_ajax_shopwalk_downgrade',       [ $this, 'ajax_downgrade' ] );
		add_action( 'wp_ajax_shopwalk_cancel',          [ $this, 'ajax_cancel' ] );
		add_action( 'wp_ajax_shopwalk_migrate',         [ $this, 'ajax_migrate' ] );
		add_action( 'wp_ajax_shopwalk_portal_url',      [ $this, 'ajax_portal_url' ] );
		add_action( 'wp_ajax_shopwalk_run_diagnostics', [ $this, 'ajax_run_diagnostics' ] );
	}

	/**
	 * Headers for domain-authenticated API calls (v1.7.0 license model).
	 */
	private function domain_headers(): array {
		return [
			'X-SW-Domain'  => home_url(),
			'Content-Type' => 'application/json',
		];
	}

	/**
	 * Shared nonce + capability check for all AJAX actions.
	 * Terminates with wp_send_json_error on failure.
	 */
	private function check_permission(): void {
		check_ajax_referer( 'shopwalk_dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
	}

	/**
	 * Render the dashboard page in WP Admin.
	 * Hooked by Shopwalk_WC_Settings when a plugin key exists.
	 */
	public function render(): void {
		$plugin_key    = get_option( 'shopwalk_wc_plugin_key', '' );
		$license_level = get_option( 'shopwalk_license_level', 'free' );
		$is_pro        = function_exists( 'shopwalk_is_pro' ) && shopwalk_is_pro();

		if ( empty( $plugin_key ) ) {
			echo '<div class="notice notice-warning inline"><p>' .
				esc_html__( 'Connect your store first to see dashboard stats.', 'shopwalk-ai' ) .
				'</p></div>';
			return;
		}

		$nonce = wp_create_nonce( 'shopwalk_dashboard' );
		?>
		<style>
		.sw-dashboard { max-width: 960px; margin: 20px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
		.sw-dashboard .sw-header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
		.sw-dashboard .sw-title { font-size: 22px; font-weight: 700; color: #1d2327; margin: 0; }
		.sw-dashboard .sw-subtitle { font-size: 13px; color: #646970; margin: 4px 0 0; }
		/* Stats cards */
		.sw-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
		.sw-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; }
		.sw-card .sw-card-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #646970; margin-bottom: 8px; }
		.sw-card .sw-card-value { font-size: 32px; font-weight: 700; color: #1d2327; line-height: 1; }
		.sw-card .sw-card-sub { font-size: 12px; color: #646970; margin-top: 6px; }
		.sw-card-green { border-left: 4px solid #00a32a; }
		.sw-card-yellow { border-left: 4px solid #dba617; }
		.sw-card-red { border-left: 4px solid #d63638; }
		.sw-card-blue { border-left: 4px solid #2271b1; }
		/* Status badge */
		.sw-status-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
		.sw-status-badge.ok { background: #edfaef; color: #1a7335; }
		.sw-status-badge.error { background: #fcf0f1; color: #8a1f1f; }
		.sw-status-badge.checking { background: #f0f6fc; color: #1d6fa4; }
		.sw-status-dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
		/* Sections */
		.sw-section { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 24px; margin-bottom: 20px; }
		.sw-section h3 { margin: 0 0 16px; font-size: 15px; font-weight: 700; color: #1d2327; border-bottom: 1px solid #f0f0f0; padding-bottom: 12px; }
		/* Plan badge */
		.sw-plan-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
		.sw-plan-badge.free { background: #f0f0f0; color: #646970; }
		.sw-plan-badge.pro { background: #1d2327; color: #f0b429; }
		/* Billing grid */
		.sw-billing-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
		.sw-billing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin: 16px 0 0; }
		.sw-billing-item label { display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #646970; margin-bottom: 4px; }
		.sw-billing-item span { font-size: 14px; color: #1d2327; font-weight: 500; }
		/* Buttons */
		.sw-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0f0f0; }
		.sw-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid transparent; text-decoration: none; transition: background 0.15s, color 0.15s; }
		.sw-btn-primary { background: #f0b429; color: #1d2327; border-color: #f0b429; }
		.sw-btn-primary:hover { background: #e6a817; color: #1d2327; border-color: #e6a817; }
		.sw-btn-secondary { background: #fff; color: #1d2327; border-color: #c3c4c7; }
		.sw-btn-secondary:hover { background: #f6f7f7; color: #1d2327; }
		.sw-btn-danger { background: #fff; color: #d63638; border-color: #d63638; }
		.sw-btn-danger:hover { background: #d63638; color: #fff; }
		.sw-btn:disabled { opacity: 0.5; cursor: not-allowed; }
		/* Inline result */
		.sw-inline-result { margin-top: 12px; padding: 10px 14px; border-radius: 6px; font-size: 13px; display: none; }
		.sw-inline-result.success { background: #edfaef; color: #1a7335; }
		.sw-inline-result.error { background: #fcf0f1; color: #8a1f1f; }
		/* Modal */
		.sw-modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; align-items: center; justify-content: center; }
		.sw-modal-overlay.open { display: flex; }
		.sw-modal { background: #fff; border-radius: 8px; padding: 28px 28px 24px; max-width: 480px; width: 90%; position: relative; box-shadow: 0 8px 32px rgba(0,0,0,0.18); }
		.sw-modal h3 { margin: 0 0 10px; font-size: 18px; color: #1d2327; padding-right: 24px; }
		.sw-modal p { margin: 0 0 20px; color: #646970; font-size: 14px; line-height: 1.5; }
		.sw-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
		.sw-modal input[type="url"] { width: 100%; padding: 8px 12px; border: 1px solid #c3c4c7; border-radius: 4px; font-size: 14px; margin-bottom: 16px; box-sizing: border-box; }
		.sw-close-btn { position: absolute; top: 12px; right: 14px; background: none; border: none; font-size: 18px; cursor: pointer; color: #646970; line-height: 1; padding: 4px; }
		.sw-close-btn:hover { color: #1d2327; }
		/* Diagnostics */
		.sw-diag-list { list-style: none; margin: 0; padding: 0; }
		.sw-diag-list li { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
		.sw-diag-list li:last-child { border-bottom: none; }
		.sw-diag-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
		.sw-diag-name { font-weight: 600; font-size: 13px; color: #1d2327; }
		.sw-diag-val { font-size: 12px; color: #646970; }
		.sw-diag-fix { font-size: 12px; color: #d63638; margin-top: 3px; }
		/* Misc */
		.sw-loading { opacity: 0.4; pointer-events: none; }
		.sw-refresh-btn { float: right; margin-top: -2px; }
		</style>

		<div class="sw-dashboard" id="sw-dashboard">

			<!-- Header -->
			<div class="sw-header">
				<div>
					<h2 class="sw-title">Shopwalk AI Dashboard</h2>
					<p class="sw-subtitle">
						<span id="sw-status-badge" class="sw-status-badge checking">
							<span class="sw-status-dot"></span>
							<?php esc_html_e( 'Checking…', 'shopwalk-ai' ); ?>
						</span>
						<button type="button" class="button button-small sw-refresh-btn" id="sw-refresh-btn">
							↻ <?php esc_html_e( 'Refresh', 'shopwalk-ai' ); ?>
						</button>
					</p>
				</div>
			</div>

			<!-- Stats Cards -->
			<div class="sw-cards">
				<div class="sw-card sw-card-blue">
					<div class="sw-card-label"><?php esc_html_e( 'Products Indexed', 'shopwalk-ai' ); ?></div>
					<div class="sw-card-value" id="sw-product-count">—</div>
					<div class="sw-card-sub" id="sw-last-synced"><?php esc_html_e( 'Loading…', 'shopwalk-ai' ); ?></div>
				</div>
				<div class="sw-card sw-card-green">
					<div class="sw-card-label"><?php esc_html_e( 'AI Agent Requests', 'shopwalk-ai' ); ?></div>
					<div class="sw-card-value" id="sw-ucp-requests">—</div>
					<div class="sw-card-sub" id="sw-ucp-last"><?php esc_html_e( 'Loading…', 'shopwalk-ai' ); ?></div>
				</div>
				<div class="sw-card sw-card-blue" id="sw-ucp-card">
					<div class="sw-card-label"><?php esc_html_e( 'UCP Endpoint', 'shopwalk-ai' ); ?></div>
					<div class="sw-card-value" style="font-size:18px;" id="sw-ucp-status"><?php esc_html_e( 'Checking…', 'shopwalk-ai' ); ?></div>
					<div class="sw-card-sub" id="sw-ucp-endpoint" style="word-break:break-all;font-size:11px;">—</div>
				</div>
				<div class="sw-card sw-card-blue">
					<div class="sw-card-label"><?php esc_html_e( 'Plugin Version', 'shopwalk-ai' ); ?></div>
					<div class="sw-card-value" style="font-size:24px;"><?php echo esc_html( SHOPWALK_AI_VERSION ); ?></div>
					<div class="sw-card-sub" id="sw-update-note">—</div>
				</div>
			</div>

			<!-- Subscription Section -->
			<div class="sw-section">
				<h3><?php esc_html_e( 'Subscription', 'shopwalk-ai' ); ?></h3>

				<div class="sw-billing-row">
					<span class="sw-plan-badge <?php echo esc_attr( $is_pro ? 'pro' : 'free' ); ?>">
						<?php echo esc_html( $is_pro ? 'Pro' : 'Free' ); ?>
					</span>
					<span id="sw-billing-status" style="font-size:13px;color:#646970;">
						<?php esc_html_e( 'Loading…', 'shopwalk-ai' ); ?>
					</span>
				</div>

				<div class="sw-billing-grid" id="sw-billing-grid" style="display:none;"></div>

				<div id="sw-subscription-result" class="sw-inline-result"></div>

				<div class="sw-actions">
					<?php if ( ! $is_pro ) : ?>
						<button type="button" class="sw-btn sw-btn-primary" id="sw-btn-upgrade">
							⬆ <?php esc_html_e( 'Upgrade to Pro', 'shopwalk-ai' ); ?>
						</button>
					<?php else : ?>
						<button type="button" class="sw-btn sw-btn-secondary" id="sw-btn-portal">
							💳 <?php esc_html_e( 'Update Card / Invoices', 'shopwalk-ai' ); ?>
						</button>
						<button type="button" class="sw-btn sw-btn-secondary" id="sw-btn-downgrade">
							↓ <?php esc_html_e( 'Downgrade to Free', 'shopwalk-ai' ); ?>
						</button>
						<button type="button" class="sw-btn sw-btn-danger" id="sw-btn-cancel">
							✕ <?php esc_html_e( 'Cancel Subscription', 'shopwalk-ai' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<!-- Tools Section -->
			<div class="sw-section">
				<h3><?php esc_html_e( 'Tools', 'shopwalk-ai' ); ?></h3>
				<div style="display:flex;flex-wrap:wrap;gap:10px;">
					<button type="button" class="sw-btn sw-btn-secondary" id="sw-btn-migrate">
						🏠 <?php esc_html_e( 'I moved my site', 'shopwalk-ai' ); ?>
					</button>
					<button type="button" class="sw-btn sw-btn-secondary" id="sw-btn-diagnostics">
						🔍 <?php esc_html_e( 'Run Diagnostics', 'shopwalk-ai' ); ?>
					</button>
				</div>
			</div>

		</div><!-- /.sw-dashboard -->

		<!-- Confirm Modal (downgrade / cancel) -->
		<div class="sw-modal-overlay" id="sw-modal-confirm">
			<div class="sw-modal">
				<button class="sw-close-btn" id="sw-confirm-close">✕</button>
				<h3 id="sw-confirm-title"></h3>
				<p id="sw-confirm-body"></p>
				<div class="sw-modal-actions">
					<button type="button" class="sw-btn sw-btn-secondary" id="sw-confirm-cancel-btn">
						<?php esc_html_e( 'Go Back', 'shopwalk-ai' ); ?>
					</button>
					<button type="button" class="sw-btn sw-btn-danger" id="sw-confirm-ok">
						<?php esc_html_e( 'Confirm', 'shopwalk-ai' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Migrate Modal -->
		<div class="sw-modal-overlay" id="sw-modal-migrate">
			<div class="sw-modal">
				<button class="sw-close-btn" id="sw-migrate-close">✕</button>
				<h3><?php esc_html_e( 'I Moved My Site', 'shopwalk-ai' ); ?></h3>
				<p><?php esc_html_e( 'Enter the new URL for this store. This updates your Shopwalk merchant binding so your catalog and license follow the new domain.', 'shopwalk-ai' ); ?></p>
				<input type="url" id="sw-migrate-domain" placeholder="https://newdomain.com" />
				<div class="sw-modal-actions">
					<button type="button" class="sw-btn sw-btn-secondary" id="sw-migrate-cancel-btn">
						<?php esc_html_e( 'Cancel', 'shopwalk-ai' ); ?>
					</button>
					<button type="button" class="sw-btn sw-btn-primary" id="sw-migrate-ok">
						<?php esc_html_e( 'Update Domain', 'shopwalk-ai' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Diagnostics Modal -->
		<div class="sw-modal-overlay" id="sw-modal-diagnostics">
			<div class="sw-modal" style="max-width:560px;">
				<button class="sw-close-btn" id="sw-diag-close">✕</button>
				<h3><?php esc_html_e( 'Diagnostics', 'shopwalk-ai' ); ?></h3>
				<ul class="sw-diag-list" id="sw-diag-list">
					<li><?php esc_html_e( 'Running checks…', 'shopwalk-ai' ); ?></li>
				</ul>
			</div>
		</div>

		<script>
		(function($) {
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;

			/* ---- Utilities ---- */

			function timeAgo(iso) {
				if (!iso) return '<?php echo esc_js( __( 'Never', 'shopwalk-ai' ) ); ?>';
				var d = new Date(iso), now = new Date();
				var sec = Math.floor((now - d) / 1000);
				if (sec < 60)    return '<?php echo esc_js( __( 'Just now', 'shopwalk-ai' ) ); ?>';
				if (sec < 3600)  return Math.floor(sec/60)   + '<?php echo esc_js( __( 'm ago', 'shopwalk-ai' ) ); ?>';
				if (sec < 86400) return Math.floor(sec/3600)  + '<?php echo esc_js( __( 'h ago', 'shopwalk-ai' ) ); ?>';
				return Math.floor(sec/86400) + '<?php echo esc_js( __( 'd ago', 'shopwalk-ai' ) ); ?>';
			}

			function formatDate(iso) {
				if (!iso) return '—';
				try { return new Date(iso).toLocaleDateString(); } catch(e) { return iso; }
			}

			function formatMoney(cents) {
				if (cents === undefined || cents === null) return '—';
				return '$' + (cents / 100).toFixed(2);
			}

			function esc(str) {
				return $('<div>').text(str || '').html();
			}

			function showResult(type, msg) {
				$('#sw-subscription-result').removeClass('success error').addClass(type).text(msg).show();
			}

			/* ---- Modal ---- */

			function openModal(id) {
				$('.sw-modal-overlay').removeClass('open');
				$('#' + id).addClass('open');
			}

			function closeModal(id) {
				$('#' + id).removeClass('open');
			}

			$('#sw-confirm-close, #sw-confirm-cancel-btn').on('click', function() { closeModal('sw-modal-confirm'); });
			$('#sw-migrate-close, #sw-migrate-cancel-btn').on('click', function() { closeModal('sw-modal-migrate'); });
			$('#sw-diag-close').on('click', function() { closeModal('sw-modal-diagnostics'); });

			$(document).on('click', '.sw-modal-overlay', function(e) {
				if ($(e.target).hasClass('sw-modal-overlay')) {
					$(this).removeClass('open');
				}
			});

			/* ---- Dashboard Stats ---- */

			function fetchDashboard() {
				$('#sw-dashboard').addClass('sw-loading');
				$.post(ajaxurl, { action: 'shopwalk_fetch_dashboard', nonce: nonce }, function(resp) {
					$('#sw-dashboard').removeClass('sw-loading');
					if (!resp.success || !resp.data) return;
					var d = resp.data;

					$('#sw-product-count').text(d.product_count !== undefined ? d.product_count.toLocaleString() : '—');
					$('#sw-last-synced').text(d.last_synced_at
						? '<?php echo esc_js( __( 'Last sync', 'shopwalk-ai' ) ); ?> ' + timeAgo(d.last_synced_at)
						: '<?php echo esc_js( __( 'Not synced yet', 'shopwalk-ai' ) ); ?>');

					$('#sw-ucp-requests').text(d.ucp_request_count !== undefined ? d.ucp_request_count.toLocaleString() : '0');
					$('#sw-ucp-last').text(d.ucp_last_request_at
						? '<?php echo esc_js( __( 'Last request', 'shopwalk-ai' ) ); ?> ' + timeAgo(d.ucp_last_request_at)
						: '<?php echo esc_js( __( 'Awaiting first request', 'shopwalk-ai' ) ); ?>');

					var ucpOk = d.ucp_healthy;
					$('#sw-ucp-card').removeClass('sw-card-blue sw-card-green sw-card-red')
						.addClass(ucpOk ? 'sw-card-green' : (d.ucp_health === 'not_configured' ? 'sw-card-yellow' : 'sw-card-red'));
					$('#sw-ucp-status').text(ucpOk ? '✓ Online' : (d.ucp_health === 'not_configured' ? '⚠ Not set' : '✗ Error'));
					$('#sw-ucp-endpoint').text(d.ucp_endpoint || '<?php echo esc_js( __( 'Not configured', 'shopwalk-ai' ) ); ?>');

					var badge = $('#sw-status-badge');
					badge.removeClass('ok error checking');
					if (ucpOk) {
						badge.addClass('ok').html('<span class="sw-status-dot"></span> <?php echo esc_js( __( 'Connected & Active', 'shopwalk-ai' ) ); ?>');
					} else {
						badge.addClass('error').html('<span class="sw-status-dot"></span> <?php echo esc_js( __( 'UCP Unreachable', 'shopwalk-ai' ) ); ?>');
					}

					$('#sw-update-note').text('<?php echo esc_js( __( 'Up to date', 'shopwalk-ai' ) ); ?>');
				});
			}

			/* ---- Billing ---- */

			function fetchBilling() {
				$.post(ajaxurl, { action: 'shopwalk_fetch_billing', nonce: nonce }, function(resp) {
					if (!resp.success || !resp.data) {
						$('#sw-billing-status').text('<?php echo esc_js( __( 'Could not load billing info.', 'shopwalk-ai' ) ); ?>');
						return;
					}
					renderBilling(resp.data);
				});
			}

			function renderBilling(d) {
				var statusMap = {
					active:   '<?php echo esc_js( __( 'Active', 'shopwalk-ai' ) ); ?>',
					trialing: '<?php echo esc_js( __( 'Trialing', 'shopwalk-ai' ) ); ?>',
					past_due: '<?php echo esc_js( __( 'Past Due', 'shopwalk-ai' ) ); ?>'
				};

				var statusText = statusMap[d.status] || d.status || '';
				$('#sw-billing-status').text(statusText ? '— ' + statusText : '');

				if (d.plan && d.plan !== 'free') {
					var html = '';
					if (d.next_charge) {
						html += '<div class="sw-billing-item"><label><?php echo esc_js( __( 'Next Charge', 'shopwalk-ai' ) ); ?></label>' +
							'<span>' + esc(formatDate(d.next_charge)) + ' &mdash; ' + esc(formatMoney(d.next_amount)) + '</span></div>';
					}
					if (d.card_last4) {
						var brand = d.card_brand ? d.card_brand.charAt(0).toUpperCase() + d.card_brand.slice(1) + ' ' : '';
						html += '<div class="sw-billing-item"><label><?php echo esc_js( __( 'Payment Method', 'shopwalk-ai' ) ); ?></label>' +
							'<span>' + esc(brand) + '&bull;&bull;&bull;&bull; ' + esc(d.card_last4) + '</span></div>';
					}
					if (d.trial_ends) {
						html += '<div class="sw-billing-item"><label><?php echo esc_js( __( 'Trial Ends', 'shopwalk-ai' ) ); ?></label>' +
							'<span>' + esc(formatDate(d.trial_ends)) + '</span></div>';
					}
					if (d.cancel_at) {
						html += '<div class="sw-billing-item"><label><?php echo esc_js( __( 'Cancels On', 'shopwalk-ai' ) ); ?></label>' +
							'<span>' + esc(formatDate(d.cancel_at)) + '</span></div>';
					}
					if (html) {
						$('#sw-billing-grid').html(html).show();
					}
				}
			}

			/* ---- Upgrade ---- */

			$('#sw-btn-upgrade').on('click', function() {
				var $btn = $(this).prop('disabled', true).text('<?php echo esc_js( __( 'Processing…', 'shopwalk-ai' ) ); ?>');
				$('#sw-subscription-result').hide();
				$.post(ajaxurl, { action: 'shopwalk_upgrade', nonce: nonce, plan: 'annual' }, function(resp) {
					$btn.prop('disabled', false).html('⬆ <?php echo esc_js( __( 'Upgrade to Pro', 'shopwalk-ai' ) ); ?>');
					if (resp.success) {
						if (resp.data && resp.data.redirect_url) {
							window.open(resp.data.redirect_url, '_blank');
							showResult('success', '<?php echo esc_js( __( 'Opening Stripe Checkout…', 'shopwalk-ai' ) ); ?>');
						} else {
							showResult('success', (resp.data && resp.data.message) || '<?php echo esc_js( __( 'Upgraded! Reloading…', 'shopwalk-ai' ) ); ?>');
							setTimeout(function() { location.reload(); }, 2000);
						}
					} else {
						showResult('error', (resp.data && resp.data.message) || '<?php echo esc_js( __( 'Upgrade failed. Please try again.', 'shopwalk-ai' ) ); ?>');
					}
				});
			});

			/* ---- Downgrade ---- */

			$('#sw-btn-downgrade').on('click', function() {
				$('#sw-confirm-title').text('<?php echo esc_js( __( 'Downgrade to Free?', 'shopwalk-ai' ) ); ?>');
				$('#sw-confirm-body').text('<?php echo esc_js( __( 'Your AI shopping assistant will be disabled at the end of your current billing period. Your product catalog stays indexed. You can upgrade again at any time.', 'shopwalk-ai' ) ); ?>');
				$('#sw-confirm-ok').off('click').on('click', function() {
					closeModal('sw-modal-confirm');
					doPost('shopwalk_downgrade', {}, function(resp) {
						if (resp.success) {
							showResult('success', (resp.data && resp.data.message) || '<?php echo esc_js( __( 'Downgraded to Free. Reloading…', 'shopwalk-ai' ) ); ?>');
							setTimeout(function() { location.reload(); }, 2000);
						} else {
							showResult('error', (resp.data && resp.data.message) || '<?php echo esc_js( __( 'Downgrade failed.', 'shopwalk-ai' ) ); ?>');
						}
					});
				});
				openModal('sw-modal-confirm');
			});

			/* ---- Cancel ---- */

			$('#sw-btn-cancel').on('click', function() {
				$('#sw-confirm-title').text('<?php echo esc_js( __( 'Cancel Subscription?', 'shopwalk-ai' ) ); ?>');
				$('#sw-confirm-body').text('<?php echo esc_js( __( 'Your Pro access continues until the end of the current billing period. You can undo this cancellation any time before it takes effect.', 'shopwalk-ai' ) ); ?>');
				$('#sw-confirm-ok').off('click').on('click', function() {
					closeModal('sw-modal-confirm');
					doPost('shopwalk_cancel', {}, function(resp) {
						if (resp.success) {
							showResult('success', (resp.data && resp.data.message) || '<?php echo esc_js( __( 'Subscription cancelled.', 'shopwalk-ai' ) ); ?>');
							fetchBilling();
						} else {
							showResult('error', (resp.data && resp.data.message) || '<?php echo esc_js( __( 'Cancellation failed.', 'shopwalk-ai' ) ); ?>');
						}
					});
				});
				openModal('sw-modal-confirm');
			});

			/* ---- Portal ---- */

			$('#sw-btn-portal').on('click', function() {
				var $btn = $(this).prop('disabled', true);
				$.post(ajaxurl, { action: 'shopwalk_portal_url', nonce: nonce }, function(resp) {
					$btn.prop('disabled', false);
					if (resp.success && resp.data && resp.data.url) {
						window.open(resp.data.url, '_blank');
					} else {
						showResult('error', (resp.data && resp.data.message) || '<?php echo esc_js( __( 'Could not open billing portal.', 'shopwalk-ai' ) ); ?>');
					}
				});
			});

			/* ---- Migrate ---- */

			$('#sw-btn-migrate').on('click', function() {
				$('#sw-migrate-domain').val('');
				openModal('sw-modal-migrate');
			});

			$('#sw-migrate-ok').on('click', function() {
				var domain = $.trim($('#sw-migrate-domain').val());
				if (!domain) { return; }
				closeModal('sw-modal-migrate');
				doPost('shopwalk_migrate', { new_domain: domain }, function(resp) {
					if (resp.success) {
						showResult('success', (resp.data && resp.data.message) || '<?php echo esc_js( __( 'Domain updated.', 'shopwalk-ai' ) ); ?>');
					} else {
						showResult('error', (resp.data && resp.data.message) || '<?php echo esc_js( __( 'Migration failed.', 'shopwalk-ai' ) ); ?>');
					}
				});
			});

			/* ---- Diagnostics ---- */

			$('#sw-btn-diagnostics').on('click', function() {
				$('#sw-diag-list').html('<li><?php echo esc_js( __( 'Running checks…', 'shopwalk-ai' ) ); ?></li>');
				openModal('sw-modal-diagnostics');
				$.post(ajaxurl, { action: 'shopwalk_run_diagnostics', nonce: nonce }, function(resp) {
					if (!resp.success || !resp.data || !resp.data.checks) {
						$('#sw-diag-list').html('<li><?php echo esc_js( __( 'Failed to run diagnostics.', 'shopwalk-ai' ) ); ?></li>');
						return;
					}
					var html = '';
					$.each(resp.data.checks, function(i, c) {
						html += '<li>';
						html += '<span class="sw-diag-icon">' + (c.ok ? '✅' : '❌') + '</span>';
						html += '<div>';
						html += '<div class="sw-diag-name">' + esc(c.name) + '</div>';
						html += '<div class="sw-diag-val">' + esc(c.value) + '</div>';
						if (!c.ok && c.fix) {
							html += '<div class="sw-diag-fix">' + esc(c.fix) + '</div>';
						}
						html += '</div></li>';
					});
					$('#sw-diag-list').html(html);
				});
			});

			/* ---- Shared POST helper ---- */

			function doPost(action, extra, cb) {
				$.post(ajaxurl, $.extend({ action: action, nonce: nonce }, extra), cb);
			}

			/* ---- Init ---- */

			fetchDashboard();
			fetchBilling();
			$('#sw-refresh-btn').on('click', function() {
				fetchDashboard();
				fetchBilling();
			});

		}(jQuery));
		</script>
		<?php
	}

	/* ===== AJAX: Dashboard Stats ===== */

	public function ajax_fetch_dashboard(): void {
		check_ajax_referer( 'shopwalk_dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}

		$plugin_key = get_option( 'shopwalk_wc_plugin_key', '' );
		if ( empty( $plugin_key ) ) {
			wp_send_json_error( [ 'message' => 'No plugin key configured.' ], 400 );
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( $cached !== false ) {
			wp_send_json_success( $cached );
			return;
		}

		$response = wp_remote_get( self::DASHBOARD_ENDPOINT, [
			'headers' => [
				'X-API-Key'    => $plugin_key,
				'Content-Type' => 'application/json',
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Could not reach Shopwalk API.' ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $body['dashboard'] ) ) {
			wp_send_json_error( [ 'message' => $body['message'] ?? 'Failed to load dashboard.' ] );
		}

		$data = $body['dashboard'];
		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		wp_send_json_success( $data );
	}

	/* ===== AJAX: Billing Info ===== */

	public function ajax_fetch_billing(): void {
		$this->check_permission();

		$response = wp_remote_get( self::BILLING_ENDPOINT, [
			'headers' => $this->domain_headers(),
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Could not reach Shopwalk API.' ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			wp_send_json_error( [ 'message' => $body['message'] ?? 'Failed to load billing info.' ] );
		}

		wp_send_json_success( $body );
	}

	/* ===== AJAX: Upgrade ===== */

	public function ajax_upgrade(): void {
		$this->check_permission();

		$plan     = sanitize_text_field( wp_unslash( $_POST['plan'] ?? 'annual' ) );
		$response = wp_remote_post( self::UPGRADE_ENDPOINT, [
			'headers' => $this->domain_headers(),
			'body'    => wp_json_encode( [ 'plan' => $plan ] ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Could not reach Shopwalk API.' ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			wp_send_json_error( [ 'message' => $body['message'] ?? 'Upgrade failed.' ] );
		}

		// No saved card — redirect to Stripe Checkout.
		if ( ! empty( $body['redirect_url'] ) ) {
			wp_send_json_success( [ 'redirect_url' => $body['redirect_url'] ] );
		}

		// Instant activation — update local license cache.
		update_option( 'shopwalk_license_level',        'pro' );
		update_option( 'shopwalk_license_status',       'active' );
		update_option( 'shopwalk_license_refreshed_at', gmdate( 'c' ) );

		wp_send_json_success( [ 'message' => $body['message'] ?? __( 'Upgraded to Pro!', 'shopwalk-ai' ) ] );
	}

	/* ===== AJAX: Downgrade ===== */

	public function ajax_downgrade(): void {
		$this->check_permission();

		$response = wp_remote_post( self::DOWNGRADE_ENDPOINT, [
			'headers' => $this->domain_headers(),
			'body'    => wp_json_encode( [] ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Could not reach Shopwalk API.' ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			wp_send_json_error( [ 'message' => $body['message'] ?? 'Downgrade failed.' ] );
		}

		update_option( 'shopwalk_license_level',        'free' );
		update_option( 'shopwalk_license_status',       'active' );
		update_option( 'shopwalk_license_refreshed_at', gmdate( 'c' ) );

		wp_send_json_success( [ 'message' => $body['message'] ?? __( 'Downgraded to Free.', 'shopwalk-ai' ) ] );
	}

	/* ===== AJAX: Cancel ===== */

	public function ajax_cancel(): void {
		$this->check_permission();

		$response = wp_remote_post( self::CANCEL_ENDPOINT, [
			'headers' => $this->domain_headers(),
			'body'    => wp_json_encode( [] ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Could not reach Shopwalk API.' ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			wp_send_json_error( [ 'message' => $body['message'] ?? 'Cancellation failed.' ] );
		}

		wp_send_json_success( [ 'message' => $body['message'] ?? __( 'Subscription cancelled.', 'shopwalk-ai' ) ] );
	}

	/* ===== AJAX: Migrate ===== */

	public function ajax_migrate(): void {
		$this->check_permission();

		$new_domain = esc_url_raw( wp_unslash( $_POST['new_domain'] ?? '' ) );
		if ( empty( $new_domain ) ) {
			wp_send_json_error( [ 'message' => 'New domain is required.' ] );
		}

		$response = wp_remote_post( self::MIGRATE_ENDPOINT, [
			'headers' => $this->domain_headers(),
			'body'    => wp_json_encode( [ 'new_domain' => $new_domain ] ),
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Could not reach Shopwalk API.' ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 ) {
			wp_send_json_error( [ 'message' => $body['message'] ?? 'Migration failed.' ] );
		}

		wp_send_json_success( [ 'message' => $body['message'] ?? __( 'Domain updated successfully.', 'shopwalk-ai' ) ] );
	}

	/* ===== AJAX: Portal URL ===== */

	public function ajax_portal_url(): void {
		$this->check_permission();

		$response = wp_remote_get( self::PORTAL_ENDPOINT, [
			'headers' => $this->domain_headers(),
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => 'Could not reach Shopwalk API.' ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $body['url'] ) ) {
			wp_send_json_error( [ 'message' => $body['message'] ?? 'Could not get portal URL.' ] );
		}

		wp_send_json_success( [ 'url' => $body['url'] ] );
	}

	/* ===== AJAX: Run Diagnostics ===== */

	public function ajax_run_diagnostics(): void {
		$this->check_permission();

		global $wp_version;
		$checks = [];

		// PHP >= 8.1
		$php_ok = version_compare( PHP_VERSION, '8.0', '>=' );
		$checks[] = [
			'name'  => __( 'PHP Version', 'shopwalk-ai' ),
			'ok'    => $php_ok,
			'value' => PHP_VERSION,
			'fix'   => $php_ok ? null : __( 'Upgrade PHP to 8.1 or higher in your hosting control panel.', 'shopwalk-ai' ),
		];

		// WooCommerce >= 8.0
		$wc_ver = defined( 'WC_VERSION' ) ? WC_VERSION : '0';
		$wc_ok  = version_compare( $wc_ver, '8.0', '>=' );
		$checks[] = [
			'name'  => __( 'WooCommerce Version', 'shopwalk-ai' ),
			'ok'    => $wc_ok,
			'value' => $wc_ver,
			'fix'   => $wc_ok ? null : __( 'Update WooCommerce to version 8.0 or higher.', 'shopwalk-ai' ),
		];

		// WordPress >= 6.0
		$wp_ok = version_compare( $wp_version, '6.0', '>=' );
		$checks[] = [
			'name'  => __( 'WordPress Version', 'shopwalk-ai' ),
			'ok'    => $wp_ok,
			'value' => $wp_version,
			'fix'   => $wp_ok ? null : __( 'Update WordPress to version 6.0 or higher.', 'shopwalk-ai' ),
		];

		// Memory limit >= 128M
		$mem_limit = ini_get( 'memory_limit' );
		$mem_bytes = wp_convert_hr_to_bytes( $mem_limit );
		$mem_ok    = $mem_bytes < 0 || $mem_bytes >= 128 * MB_IN_BYTES;
		$checks[] = [
			'name'  => __( 'Memory Limit', 'shopwalk-ai' ),
			'ok'    => $mem_ok,
			'value' => $mem_limit,
			'fix'   => $mem_ok ? null : __( 'Increase PHP memory_limit to at least 128M in your php.ini or wp-config.php.', 'shopwalk-ai' ),
		];

		// API connection (ping /health)
		$health_resp = wp_remote_get( self::HEALTH_ENDPOINT, [ 'timeout' => 8 ] );
		$api_ok      = ! is_wp_error( $health_resp ) && wp_remote_retrieve_response_code( $health_resp ) === 200;
		$checks[] = [
			'name'  => __( 'Shopwalk API Connection', 'shopwalk-ai' ),
			'ok'    => $api_ok,
			'value' => $api_ok ? __( 'Connected', 'shopwalk-ai' ) : __( 'Failed', 'shopwalk-ai' ),
			'fix'   => $api_ok ? null : __( 'Check your server firewall or proxy settings. The server must be able to reach api.shopwalk.com.', 'shopwalk-ai' ),
		];

		// UCP endpoint
		$ucp_url  = home_url( '/wp-json/shopwalk-wc/v1' );
		$ucp_resp = wp_remote_get( $ucp_url, [ 'timeout' => 8 ] );
		$ucp_code = is_wp_error( $ucp_resp ) ? 0 : wp_remote_retrieve_response_code( $ucp_resp );
		$ucp_ok   = in_array( $ucp_code, [ 200, 401 ], true );
		$checks[] = [
			'name'  => __( 'UCP Endpoint', 'shopwalk-ai' ),
			'ok'    => $ucp_ok,
			'value' => $ucp_ok ? __( 'Reachable', 'shopwalk-ai' ) : __( 'Unreachable', 'shopwalk-ai' ),
			'fix'   => $ucp_ok ? null : __( 'Ensure pretty permalinks are enabled (Settings → Permalinks) and that .htaccess allows REST API access.', 'shopwalk-ai' ),
		];

		// License status
		$license_status = (string) get_option( 'shopwalk_license_status', '' );
		$license_level  = (string) get_option( 'shopwalk_license_level', 'free' );
		$license_ok     = in_array( $license_status, [ 'active', 'trialing' ], true );
		$checks[] = [
			'name'  => __( 'License Status', 'shopwalk-ai' ),
			'ok'    => $license_ok,
			'value' => ucfirst( $license_level ) . ' — ' . ucfirst( $license_status ?: 'unknown' ),
			'fix'   => $license_ok ? null : __( 'Deactivate and reactivate the plugin to refresh your license, or contact support@shopwalk.com.', 'shopwalk-ai' ),
		];

		// Merchant ID set
		$merchant_id = (string) get_option( 'shopwalk_merchant_id', '' );
		$mid_ok      = ! empty( $merchant_id );
		$checks[] = [
			'name'  => __( 'Merchant ID', 'shopwalk-ai' ),
			'ok'    => $mid_ok,
			'value' => $mid_ok ? substr( $merchant_id, 0, 8 ) . '…' : __( 'Not set', 'shopwalk-ai' ),
			'fix'   => $mid_ok ? null : __( 'Deactivate and reactivate the plugin to trigger auto-registration.', 'shopwalk-ai' ),
		];

		wp_send_json_success( [ 'checks' => $checks ] );
	}
}
