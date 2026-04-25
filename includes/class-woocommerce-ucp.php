<?php
/**
 * Plugin bootstrap — loads Tier 1 (UCP core) always, Tier 2 (Shopwalk
 * integration) only when a valid license is present.
 *
 * Strict tier separation:
 * - core/ files MUST NOT import from shopwalk/. Removing the entire
 *   shopwalk/ directory must leave the plugin functional as a pure
 *   UCP adapter for any AI agent on the public internet.
 * - shopwalk/ files MAY import from core/ (e.g. to reuse OAuth client
 *   registration helpers).
 * - admin/ is the only place both tiers are surfaced in one view (the
 *   dashboard shows UCP status AND the Shopwalk CTA).
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce_UCP bootstrap.
 *
 * Loaded once on `plugins_loaded`. Owns the activation/deactivation
 * lifecycle, registers all subsystems, and decides whether the optional
 * Shopwalk integration is loaded based on WP option state.
 */
final class WooCommerce_UCP {

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
	 * Constructor — wire up everything.
	 */
	private function __construct() {
		$this->load_core();
		$this->load_updater(); // Always load — updates must work even without a license
		if ( $this->is_shopwalk_connected() ) {
			$this->load_shopwalk();
		}
		$this->load_admin();
	}

	/**
	 * Load the auto-updater. Runs unconditionally — a store must be able to
	 * receive plugin updates regardless of license/connection status.
	 *
	 * @return void
	 */
	private function load_updater(): void {
		require_once WOOCOMMERCE_UCP_PLUGIN_DIR . 'includes/shopwalk/class-shopwalk-updater.php';
		new Shopwalk_Updater();
	}

	/**
	 * Tier 1 — UCP core. Mandatory. Loads on every request.
	 *
	 * @return void
	 */
	private function load_core(): void {
		$dir = WOOCOMMERCE_UCP_PLUGIN_DIR . 'includes/core/';

		// Storage layer is loaded first — every other class queries it.
		require_once $dir . 'class-ucp-storage.php';
		require_once $dir . 'class-ucp-signing.php';

		// UCP response envelope helper — loaded before commerce classes that depend on it.
		require_once $dir . 'class-ucp-response.php';

		// Payment router + shipped adapters — loaded before checkout so the
		// /complete handler can dispatch to them.
		require_once $dir . 'class-ucp-payment-router.php';
		require_once $dir . 'class-ucp-payment-adapter-stripe.php';

		// OAuth subsystem.
		require_once $dir . 'class-ucp-oauth-clients.php';
		require_once $dir . 'class-ucp-oauth-server.php';

		// Commerce surface.
		require_once $dir . 'class-ucp-checkout.php';
		require_once $dir . 'class-ucp-direct-checkout.php';
		require_once $dir . 'class-ucp-orders.php';

		// Webhooks.
		require_once $dir . 'class-ucp-webhook-subscriptions.php';
		require_once $dir . 'class-ucp-webhook-delivery.php';

		// Store + products endpoints.
		require_once $dir . 'class-ucp-store.php';
		require_once $dir . 'class-ucp-products.php';

		// Discovery + payment gateway + self-test.
		require_once $dir . 'class-ucp-discovery.php';
		require_once $dir . 'class-ucp-payment-gateway.php';
		require_once $dir . 'class-ucp-self-test.php';
		require_once $dir . 'class-ucp-sync-trigger.php';
		require_once $dir . 'class-ucp-cli.php';

		// Bootstrap registers all REST routes under /wp-json/ucp/v1/.
		require_once $dir . 'class-ucp-bootstrap.php';
		UCP_Bootstrap::instance();
	}

	/**
	 * Tier 2 — Shopwalk integration. Optional. Loaded only when a license
	 * is present. Removing the entire shopwalk/ directory leaves Tier 1
	 * functional unchanged.
	 *
	 * @return void
	 */
	private function load_shopwalk(): void {
		$dir = WOOCOMMERCE_UCP_PLUGIN_DIR . 'includes/shopwalk/';

		require_once $dir . 'class-shopwalk-license.php';
		require_once $dir . 'class-shopwalk-sync.php';
		require_once $dir . 'class-shopwalk-connector.php';
		require_once $dir . 'class-shopwalk-dashboard-panel.php';

		Shopwalk_Sync::instance();
		Shopwalk_Connector::instance();

		// Auto-activate prefilled license if pending (set during plugin activation
		// hook when Shopwalk_License wasn't loaded yet).
		if ( get_option( 'shopwalk_license_needs_activation' ) === '1' ) {
			$key = Shopwalk_License::key();
			if ( $key !== '' ) {
				$result = Shopwalk_License::activate( $key );
				if ( $result['ok'] ?? false ) {
					delete_option( 'shopwalk_license_needs_activation' );
				}
			}
		}
	}

	/**
	 * WP Admin UI. Always loaded — the dashboard surfaces both tiers.
	 *
	 * @return void
	 */
	private function load_admin(): void {
		if ( ! is_admin() ) {
			return;
		}
		$dir = WOOCOMMERCE_UCP_PLUGIN_DIR . 'includes/admin/';
		require_once $dir . 'class-dashboard.php';
		require_once $dir . 'class-self-test.php';

		// Shopwalk_Connect drives OAuth connect + Pro upgrade + hourly tier
		// poll. Loaded in admin always (unlicensed users need the Connect
		// button; licensed users need the upgrade button / cron).
		$connect = WOOCOMMERCE_UCP_PLUGIN_DIR . 'includes/shopwalk/class-shopwalk-connect.php';
		if ( file_exists( $connect ) ) {
			require_once WOOCOMMERCE_UCP_PLUGIN_DIR . 'includes/shopwalk/class-shopwalk-license.php';
			require_once $connect;
			Shopwalk_Connect::init();
		}

		WooCommerce_UCP_Admin_Dashboard::instance();
		WooCommerce_UCP_Admin_Self_Test::instance();
	}

	/**
	 * Whether the optional Shopwalk integration is connected.
	 *
	 * @return bool
	 */
	public function is_shopwalk_connected(): bool {
		$key = (string) get_option( 'shopwalk_license_key', '' );
		return $key !== '' && (
			str_starts_with( $key, 'sw_lic_' ) ||
			str_starts_with( $key, 'sw_site_' )
		);
	}

	// ── Activation / Deactivation ────────────────────────────────────────

	/**
	 * Plugin activation hook. Idempotent — safe to call repeatedly.
	 *
	 * @return void
	 */
	public static function activate(): void {
		require_once WOOCOMMERCE_UCP_PLUGIN_DIR . 'includes/core/class-ucp-storage.php';
		UCP_Storage::install();

		// Generate the store signing keypair if it doesn't exist yet.
		require_once WOOCOMMERCE_UCP_PLUGIN_DIR . 'includes/core/class-ucp-signing.php';
		UCP_Signing::ensure_store_keypair();

		// Schedule cron jobs for session cleanup + webhook delivery.
		if ( ! wp_next_scheduled( 'shopwalk_ucp_session_cleanup' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'shopwalk_ucp_session_cleanup' );
		}
		if ( ! wp_next_scheduled( 'shopwalk_ucp_webhook_flush' ) ) {
			wp_schedule_event( time() + 60, 'shopwalk_ucp_minute', 'shopwalk_ucp_webhook_flush' );
		}

		// Tier 2: keep the legacy queue cron registered too — only fires
		// when the Shopwalk module is loaded (no-op otherwise).
		if ( ! wp_next_scheduled( 'shopwalk_flush_queue' ) ) {
			wp_schedule_event( time() + 300, 'shopwalk_ucp_five_minutes', 'shopwalk_flush_queue' );
		}

		// Write static /.well-known/ucp.php for reliable discovery on
		// Apache shared hosts that rewrite the URI before WordPress sees it.
		self::create_well_known_files();

		// Register the WC payment gateway. WC's `woocommerce_payment_gateways`
		// filter is wired by the bootstrap on every load; activation just
		// needs the option set so existing stores see "Pay via UCP" enabled
		// out of the box.
		if ( false === get_option( 'shopwalk_ucp_gateway_enabled' ) ) {
			update_option( 'shopwalk_ucp_gateway_enabled', 'yes' );
		}

		// Auto-populate license key from optional bundled config file.
		// Store the key now; activation against shopwalk-api happens on
		// plugins_loaded (see init_instance) when Shopwalk_License is available.
		if ( file_exists( WOOCOMMERCE_UCP_PLUGIN_DIR . 'woocommerce-ucp-config.php' ) ) {
			require_once WOOCOMMERCE_UCP_PLUGIN_DIR . 'woocommerce-ucp-config.php';
		}
		if ( defined( 'WOOCOMMERCE_UCP_PREFILLED_LICENSE' ) && ! get_option( 'shopwalk_license_key' ) ) {
			update_option( 'shopwalk_license_key', WOOCOMMERCE_UCP_PREFILLED_LICENSE );
			// Flag that activation is pending — handled on next page load.
			update_option( 'shopwalk_license_needs_activation', '1' );
		}
	}

	/**
	 * Plugin deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'shopwalk_ucp_session_cleanup' );
		wp_clear_scheduled_hook( 'shopwalk_ucp_webhook_flush' );
		wp_clear_scheduled_hook( 'shopwalk_flush_queue' );
		wp_clear_scheduled_hook( 'shopwalk_status_poll' );
		self::remove_well_known_files();
	}

	// ── Static helpers (well-known files, cron schedules) ────────────────

	/**
	 * Create /.well-known/ucp.php and .htaccess for reliable UCP discovery.
	 * Apache shared hosts rewrite the URI before WordPress sees it, so we
	 * write a static PHP shim that bootstraps WP and dispatches to the
	 * UCP discovery route handler.
	 *
	 * @return void
	 */
	public static function create_well_known_files(): void {
		$dir = ABSPATH . '.well-known';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$ucp_php = <<<'PHP'
<?php
/**
 * UCP discovery — served at /.well-known/ucp
 * Created by woocommerce-ucp plugin. Safe to delete if plugin is removed.
 */
if ( ! file_exists( dirname( __FILE__, 2 ) . '/wp-load.php' ) ) { exit; }
require_once dirname( __FILE__, 2 ) . '/wp-load.php';
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-cache, must-revalidate' );
header( 'Access-Control-Allow-Origin: *' );
$request  = new WP_REST_Request( 'GET', '/ucp/v1/.well-known/ucp' );
$response = rest_do_request( $request );
echo wp_json_encode(
	rest_get_server()->response_to_data( $response, false ),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
exit;
PHP;

		$oauth_php = <<<'PHP'
<?php
/**
 * OAuth 2.0 server metadata — served at /.well-known/oauth-authorization-server (RFC 8414)
 * Created by woocommerce-ucp plugin. Safe to delete if plugin is removed.
 */
if ( ! file_exists( dirname( __FILE__, 2 ) . '/wp-load.php' ) ) { exit; }
require_once dirname( __FILE__, 2 ) . '/wp-load.php';
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-cache, must-revalidate' );
header( 'Access-Control-Allow-Origin: *' );
$request  = new WP_REST_Request( 'GET', '/ucp/v1/.well-known/oauth-authorization-server' );
$response = rest_do_request( $request );
echo wp_json_encode(
	rest_get_server()->response_to_data( $response, false ),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);
exit;
PHP;

		$htaccess = <<<'HTACCESS'
# Managed by woocommerce-ucp plugin
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^ucp/?$ ucp.php [L]
RewriteRule ^oauth-authorization-server/?$ oauth-authorization-server.php [L]
</IfModule>
HTACCESS;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing static discovery endpoint file to /.well-known/ on activation.
		file_put_contents( $dir . '/ucp.php', $ucp_php );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing static discovery endpoint file to /.well-known/ on activation.
		file_put_contents( $dir . '/oauth-authorization-server.php', $oauth_php );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing .htaccess rewrite rules to /.well-known/ on activation.
		file_put_contents( $dir . '/.htaccess', $htaccess );
	}

	/**
	 * Remove /.well-known/ucp.php and oauth-authorization-server.php on deactivation.
	 *
	 * @return void
	 */
	public static function remove_well_known_files(): void {
		$dir = ABSPATH . '.well-known';
		foreach ( array( 'ucp.php', 'oauth-authorization-server.php' ) as $file ) {
			$path = $dir . '/' . $file;
			if ( file_exists( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $path );
			}
		}
		$htaccess = $dir . '/.htaccess';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local .htaccess to check if managed by this plugin.
		if ( file_exists( $htaccess ) && ( str_contains( (string) file_get_contents( $htaccess ), 'woocommerce-ucp plugin' ) || str_contains( (string) file_get_contents( $htaccess ), 'shopwalk-ai plugin' ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $htaccess );
		}
	}
}

// Custom cron intervals — registered once globally so the activation hook
// can schedule jobs with these names regardless of which subsystem owns them.
add_filter(
	'cron_schedules',
	static function ( array $schedules ): array {
		$schedules['shopwalk_ucp_minute'] = array(
			'interval' => 60,
			'display'  => esc_html__( 'Every Minute (WooCommerce UCP)', 'woocommerce-ucp' ),
		);
		$schedules['shopwalk_ucp_five_minutes'] = array(
			'interval' => 300,
			'display'  => esc_html__( 'Every 5 Minutes (WooCommerce UCP)', 'woocommerce-ucp' ),
		);
		return $schedules;
	}
);
