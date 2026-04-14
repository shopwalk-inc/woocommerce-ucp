<?php
/**
 * WP Admin Dashboard — single page under "Shopwalk AI" in the WP admin
 * sidebar. Shows the UCP status panel (always visible) and either the
 * "Connect to Shopwalk" CTA or the connected-state Shopwalk panel.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_AI_Admin_Dashboard — admin menu + page renderer.
 */
final class Shopwalk_AI_Admin_Dashboard {

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
	 * Wire up admin menu + asset enqueuing.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the top-level admin menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Shopwalk AI', 'shopwalk-ai' ),
			__( 'Shopwalk AI', 'shopwalk-ai' ),
			'manage_woocommerce',
			'shopwalk-ai',
			array( $this, 'render_page' ),
			'dashicons-share-alt2',
			58
		);
	}

	/**
	 * Enqueue inline JS for the self-test + connect/disconnect buttons.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'toplevel_page_shopwalk-ai' ) {
			return;
		}
		$nonces = array(
			'self_test'  => wp_create_nonce( 'shopwalk_self_test' ),
			'activate'   => wp_create_nonce( 'shopwalk_activate' ),
			'disconnect' => wp_create_nonce( 'shopwalk_disconnect' ),
			'full_sync'  => wp_create_nonce( 'shopwalk_full_sync' ),
		);
		wp_register_script( 'shopwalk-ai-admin', '', array(), SHOPWALK_AI_VERSION, true );
		wp_enqueue_script( 'shopwalk-ai-admin' );
		wp_add_inline_script(
			'shopwalk-ai-admin',
			'window.shopwalkAIAdmin = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonces'  => $nonces,
				)
			) . ';' . self::admin_js()
		);
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$connected = Shopwalk_AI::instance()->is_shopwalk_connected();
		?>
		<div class="wrap shopwalk-ai-wrap">
			<h1><?php esc_html_e( 'Shopwalk AI — UCP Adapter', 'shopwalk-ai' ); ?></h1>
			<style>
				.ucp-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 6px; padding: 18px 22px; margin-bottom: 18px; max-width: 740px; }
				.ucp-card h2 { margin-top: 0; }
				.status-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
				.status-pill.ok { background: #d1fae5; color: #065f46; }
				.status-pill.warn { background: #fef3c7; color: #92400e; }
				.status-pill.fail { background: #fee2e2; color: #991b1b; }
				.shopwalk-ai-wrap .check-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f3f4f6; }
				.shopwalk-ai-wrap .check-row:last-child { border-bottom: 0; }
			</style>

			<?php $this->render_ucp_card(); ?>
			<?php if ( $connected ) : ?>
				<?php Shopwalk_Dashboard_Panel::render(); ?>
			<?php else : ?>
				<?php $this->render_shopwalk_cta(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * UCP status card. Always visible.
	 *
	 * @return void
	 */
	private function render_ucp_card(): void {
		$base = home_url( '/.well-known/ucp' );
		?>
		<div class="ucp-card">
			<h2><?php esc_html_e( 'Plugin Status (Tier 1 — UCP)', 'shopwalk-ai' ); ?></h2>
			<p>
				<span class="status-pill ok">✅ <?php esc_html_e( 'UCP-compliant', 'shopwalk-ai' ); ?></span>
			</p>
			<p>
				<?php esc_html_e( 'Discoverable at:', 'shopwalk-ai' ); ?>
				<br>
				<code><?php echo esc_html( $base ); ?></code>
			</p>
			<p>
				<button type="button" class="button button-primary" id="shopwalk-self-test">
					▶ <?php esc_html_e( 'Run self-test', 'shopwalk-ai' ); ?>
				</button>
			</p>
			<div id="shopwalk-self-test-results"></div>
		</div>
		<?php
	}

	/**
	 * Unconnected-state Shopwalk CTA card.
	 *
	 * @return void
	 */
	private function render_shopwalk_cta(): void {
		?>
		<div class="ucp-card">
			<h2><?php esc_html_e( 'Shopwalk', 'shopwalk-ai' ); ?> <span class="status-pill warn">⬜ Not connected</span></h2>
			<p>
				<?php esc_html_e( 'Get your store discovered by AI shoppers on Shopwalk. Free Premier listing during launch.', 'shopwalk-ai' ); ?>
			</p>
			<ul style="margin-left: 1em; list-style: disc;">
				<li><?php esc_html_e( 'Real-time inventory sync', 'shopwalk-ai' ); ?></li>
				<li><?php esc_html_e( 'Premier placement on shopwalk.com', 'shopwalk-ai' ); ?></li>
				<li><?php esc_html_e( 'Analytics dashboard', 'shopwalk-ai' ); ?></li>
				<li><?php esc_html_e( 'Faster index updates', 'shopwalk-ai' ); ?></li>
			</ul>
			<p>
				<a href="<?php echo esc_url( SHOPWALK_SIGNUP_URL ); ?>" class="button button-primary" target="_blank" rel="noopener">
					<?php esc_html_e( 'Get a license key →', 'shopwalk-ai' ); ?>
				</a>
			</p>
			<p>
				<label for="shopwalk-license-input"><strong><?php esc_html_e( 'License key:', 'shopwalk-ai' ); ?></strong></label>
				<input type="text" id="shopwalk-license-input" class="regular-text" placeholder="sw_site_..." />
				<button type="button" class="button button-primary" id="shopwalk-connect">
					<?php esc_html_e( 'Connect to Shopwalk', 'shopwalk-ai' ); ?>
				</button>
			</p>
			<p id="shopwalk-connect-status"></p>
		</div>
		<?php
	}

	/**
	 * Inline JS that powers the self-test, connect, disconnect, and
	 * full-sync buttons. Plain DOM — no jQuery dependency.
	 *
	 * @return string
	 */
	private static function admin_js(): string {
		return <<<'JS'
(function () {
	var s = window.shopwalkAIAdmin;
	if (!s) return;

	function $(id) { return document.getElementById(id); }

	function postAjax(action, body) {
		var data = new URLSearchParams();
		data.append('action', action);
		Object.keys(body || {}).forEach(function (k) { data.append(k, body[k]); });
		return fetch(s.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
			.then(function (r) { return r.json(); });
	}

	// ── Self-test ─────────────────────────────────────────────────────
	var selfTestBtn = $('shopwalk-self-test');
	if (selfTestBtn) {
		selfTestBtn.addEventListener('click', function () {
			var out = $('shopwalk-self-test-results');
			out.innerHTML = '<p>Running checks…</p>';
			postAjax('shopwalk_self_test', { nonce: s.nonces.self_test }).then(function (resp) {
				if (!resp || !resp.success) {
					out.innerHTML = '<p style="color:#991b1b;">Self-test failed: ' + (resp && resp.data && resp.data.message ? resp.data.message : 'unknown error') + '</p>';
					return;
				}
				var rows = (resp.data.checks || []).map(function (c) {
					var pill = c.status === 'pass' ? '<span class="status-pill ok">✅</span>'
						: c.status === 'warn' ? '<span class="status-pill warn">⚠</span>'
						: '<span class="status-pill fail">✖</span>';
					return '<div class="check-row"><span>' + pill + ' ' + escapeHtml(c.check) + '</span><span style="color:#6b7280;">' + escapeHtml(c.message) + '</span></div>';
				}).join('');
				out.innerHTML = rows || '<p>No checks ran.</p>';
			});
		});
	}

	// ── Connect ───────────────────────────────────────────────────────
	var connectBtn = $('shopwalk-connect');
	if (connectBtn) {
		connectBtn.addEventListener('click', function () {
			var input = $('shopwalk-license-input');
			var status = $('shopwalk-connect-status');
			if (!input || !input.value) {
				status.textContent = 'License key is required.';
				return;
			}
			status.textContent = 'Connecting…';
			postAjax('shopwalk_activate', { nonce: s.nonces.activate, license_key: input.value }).then(function (resp) {
				if (resp && resp.success) {
					status.textContent = 'Connected. Reloading…';
					setTimeout(function () { window.location.reload(); }, 600);
				} else {
					status.textContent = (resp && resp.data && resp.data.message) || 'Activation failed.';
				}
			});
		});
	}

	// ── Disconnect ────────────────────────────────────────────────────
	var disconnectBtn = $('shopwalk-disconnect');
	if (disconnectBtn) {
		disconnectBtn.addEventListener('click', function () {
			if (!confirm('Disconnect from Shopwalk? Your store will still be UCP-compliant for other agents.')) return;
			postAjax('shopwalk_disconnect', { nonce: s.nonces.disconnect }).then(function (resp) {
				if (resp && resp.success) window.location.reload();
				else alert((resp && resp.data && resp.data.message) || 'Disconnect failed.');
			});
		});
	}

	// ── Full sync ─────────────────────────────────────────────────────
	var syncBtn = $('shopwalk-sync-now');
	if (syncBtn) {
		var syncCooldown = null;
		function startCooldown(seconds) {
			syncBtn.disabled = true;
			var remaining = seconds;
			function tick() {
				if (remaining <= 0) {
					syncBtn.disabled = false;
					syncBtn.textContent = 'Sync now';
					syncCooldown = null;
					return;
				}
				var mins = Math.floor(remaining / 60);
				var secs = remaining % 60;
				syncBtn.textContent = mins > 0 ? 'Wait ' + mins + 'm ' + secs + 's' : 'Wait ' + secs + 's';
				remaining--;
				syncCooldown = setTimeout(tick, 1000);
			}
			tick();
		}
		syncBtn.addEventListener('click', function () {
			if (syncBtn.disabled) return;
			syncBtn.disabled = true;
			syncBtn.textContent = 'Syncing…';
			postAjax('shopwalk_full_sync', { nonce: s.nonces.full_sync }).then(function (resp) {
				if (resp && resp.success) {
					syncBtn.textContent = (resp.data && resp.data.message) || 'Done!';
					setTimeout(function() { syncBtn.textContent = 'Sync now'; }, 3000);
				} else {
					var cd = resp && resp.data && resp.data.cooldown_remaining;
					if (cd) {
						startCooldown(cd);
					} else {
						syncBtn.disabled = false;
						syncBtn.textContent = 'Sync now';
						alert((resp && resp.data && resp.data.message) || 'Sync failed.');
					}
				}
			});
		});
	}

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
})();
JS;
	}
}
