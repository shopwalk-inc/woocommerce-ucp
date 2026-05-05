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
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce_Shopwalk bootstrap.
 *
 * Loaded once on `plugins_loaded`. Owns the activation/deactivation
 * lifecycle, registers all subsystems, and decides whether the optional
 * Shopwalk integration is loaded based on WP option state.
 */
final class WooCommerce_Shopwalk {

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
		if ( $this->is_shopwalk_connected() ) {
			$this->load_shopwalk();
		}
		$this->load_admin();
	}

	/**
	 * Tier 1 — UCP core. Mandatory. Loads on every request.
	 *
	 * @return void
	 */
	private function load_core(): void {
		$dir = WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'includes/core/';

		// Storage layer is loaded first — every other class queries it.
		require_once $dir . 'class-ucp-storage.php';
		require_once $dir . 'class-ucp-signing.php';

		// UCP response envelope helper — loaded before commerce classes that depend on it.
		require_once $dir . 'class-ucp-response.php';

		// Payment router + shipped adapters — loaded before checkout so the
		// /complete handler can dispatch to them. Interface goes first
		// (the router and concrete adapter both implement it).
		require_once $dir . 'interface-ucp-payment-adapter.php';
		require_once $dir . 'class-ucp-payment-router.php';
		require_once $dir . 'class-ucp-payment-adapter-stripe.php';

		// OAuth subsystem.
		require_once $dir . 'class-ucp-oauth-clients.php';
		require_once $dir . 'class-ucp-oauth-server.php';

		// Commerce surface.
		require_once $dir . 'class-ucp-checkout.php';
		require_once $dir . 'class-ucp-direct-checkout.php';
		require_once $dir . 'class-ucp-orders.php';

		// Webhooks. URL guard is loaded first — both subscribe-time and
		// delivery-time gate on it for SSRF defense. Secret-crypto helper
		// is loaded next — both subscriptions (encrypt at create) and
		// delivery (decrypt at sign) depend on it (F-D-5).
		require_once $dir . 'class-ucp-url-guard.php';
		require_once $dir . 'class-ucp-webhook-secret-crypto.php';
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
		$dir = WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'includes/shopwalk/';

		require_once $dir . 'class-shopwalk-license.php';
		require_once $dir . 'class-shopwalk-sync.php';
		require_once $dir . 'class-shopwalk-connector.php';
		require_once $dir . 'class-shopwalk-dashboard-panel.php';
		require_once $dir . 'class-shopwalk-direct-checkout-notifier.php';

		Shopwalk_Sync::instance();
		Shopwalk_Connector::instance();
		Shopwalk_Direct_Checkout_Notifier::instance();

		// Auto-activate prefilled license if pending (set during plugin activation
		// hook when Shopwalk_License wasn't loaded yet).
		if ( get_option( 'shopwalk_license_needs_activation' ) === '1' ) {
			$key = Shopwalk_License::key();
			if ( '' !== $key ) {
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
		$dir = WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'includes/admin/';
		require_once $dir . 'class-dashboard.php';
		require_once $dir . 'class-deadletter-admin.php';

		// Shopwalk_Connect drives OAuth connect + Pro upgrade + hourly tier
		// poll. Loaded in admin always (unlicensed users need the Connect
		// button; licensed users need the upgrade button / cron).
		$connect = WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'includes/shopwalk/class-shopwalk-connect.php';
		if ( file_exists( $connect ) ) {
			require_once WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'includes/shopwalk/class-shopwalk-license.php';
			require_once $connect;
			Shopwalk_Connect::init();
		}

		WooCommerce_Shopwalk_Admin_Dashboard::instance();
		WooCommerce_Shopwalk_Admin_Deadletter::instance();
	}

	/**
	 * Whether the optional Shopwalk integration is connected.
	 *
	 * @return bool
	 */
	public function is_shopwalk_connected(): bool {
		$key = (string) get_option( 'shopwalk_license_key', '' );
		return '' !== $key && (
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
		require_once WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'includes/core/class-ucp-storage.php';
		UCP_Storage::install();

		// Generate the store signing keypair if it doesn't exist yet.
		require_once WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'includes/core/class-ucp-signing.php';
		UCP_Signing::ensure_store_keypair();

		// Schedule hourly cron backstops for session cleanup + queue flushers.
		// The queue flushers also fire on demand via wp_schedule_single_event
		// — see UCP_Webhook_Delivery::enqueue_event_for_order() and
		// Shopwalk_Sync::push_to_queue() — so organic events drain within
		// seconds. The hourly recurrence is purely a worst-case backstop
		// for events queued during a WP-Cron outage.
		if ( ! wp_next_scheduled( 'shopwalk_session_cleanup' ) ) {
			wp_schedule_event( time() + 600, 'hourly', 'shopwalk_session_cleanup' );
		}
		if ( ! wp_next_scheduled( 'shopwalk_webhook_flush' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'shopwalk_webhook_flush' );
		}
		if ( ! wp_next_scheduled( 'shopwalk_flush_queue' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'shopwalk_flush_queue' );
		}

		// Write static /.well-known/ucp.php for reliable discovery on
		// Apache shared hosts that rewrite the URI before WordPress sees it.
		self::create_well_known_files();

		// Register the WC payment gateway. WC's `woocommerce_payment_gateways`
		// filter is wired by the bootstrap on every load; activation just
		// needs the option set so existing stores see "Pay via UCP" enabled
		// out of the box.
		if ( false === get_option( 'shopwalk_gateway_enabled' ) ) {
			update_option( 'shopwalk_gateway_enabled', 'yes' );
		}

		// Auto-populate license key from optional bundled config file.
		// Store the key now; activation against shopwalk-api happens on
		// plugins_loaded (see init_instance) when Shopwalk_License is available.
		if ( file_exists( WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'shopwalk-for-woocommerce-config.php' ) ) {
			require_once WOOCOMMERCE_SHOPWALK_PLUGIN_DIR . 'shopwalk-for-woocommerce-config.php';
		}
		if ( defined( 'WOOCOMMERCE_SHOPWALK_PREFILLED_LICENSE' ) && ! get_option( 'shopwalk_license_key' ) ) {
			update_option( 'shopwalk_license_key', WOOCOMMERCE_SHOPWALK_PREFILLED_LICENSE );
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
		wp_clear_scheduled_hook( 'shopwalk_session_cleanup' );
		wp_clear_scheduled_hook( 'shopwalk_webhook_flush' );
		wp_clear_scheduled_hook( 'shopwalk_direct_checkout_cleanup' );
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
 * Created by shopwalk-for-woocommerce plugin. Safe to delete if plugin is removed.
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
 * Created by shopwalk-for-woocommerce plugin. Safe to delete if plugin is removed.
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
# Managed by shopwalk-for-woocommerce plugin
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
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return; // Filesystem credentials prompt; nothing we can do here.
		}

		$dir = ABSPATH . '.well-known';
		foreach ( array( 'ucp.php', 'oauth-authorization-server.php' ) as $file ) {
			$path = $dir . '/' . $file;
			if ( $wp_filesystem->exists( $path ) ) {
				$wp_filesystem->delete( $path );
			}
		}
		$htaccess = $dir . '/.htaccess';
		if ( $wp_filesystem->exists( $htaccess ) ) {
			$contents = (string) $wp_filesystem->get_contents( $htaccess );
			if ( str_contains( $contents, 'shopwalk-for-woocommerce plugin' ) || str_contains( $contents, 'shopwalk-ai plugin' ) ) {
				$wp_filesystem->delete( $htaccess );
			}
		}
	}
}

// No custom cron intervals — we use built-in `hourly` as the worst-case
// backstop and wp_schedule_single_event for instant drain on enqueue.
