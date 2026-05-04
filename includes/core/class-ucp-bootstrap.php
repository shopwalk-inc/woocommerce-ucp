<?php
/**
 * UCP Bootstrap — registers every UCP route + the WC payment gateway.
 *
 * Single entry point called from `WooCommerce_Shopwalk::load_core()`. Wires:
 *   - Discovery (/.well-known/ucp + oauth-authorization-server)
 *   - OAuth 2.0 server (authorize/token/revoke/userinfo)
 *   - Checkout sessions
 *   - Orders
 *   - Webhook subscriptions
 *   - Webhook delivery (event capture + cron worker)
 *   - WC payment gateway "Pay via UCP"
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Bootstrap — wires all UCP subsystems.
 */
final class UCP_Bootstrap {

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
	 * Wire everything up.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		// Webhook delivery owns the WC order hooks + cron worker —
		// register on plugins_loaded so it's ready before WC fires its
		// own order events.
		UCP_Webhook_Delivery::bootstrap();
		// Payment gateway registration is hooked into woocommerce_payment_gateways.
		shopwalk_ucp_register_payment_gateway();
	}

	/**
	 * Register every UCP REST route. Called once on rest_api_init.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		UCP_Discovery::register_routes();
		UCP_Store::register_routes();
		UCP_Products::register_routes();
		UCP_OAuth_Server::register_routes();
		UCP_Checkout::register_routes();
		UCP_Direct_Checkout::register_routes();
		UCP_Orders::register_routes();
		UCP_Webhook_Subscriptions::register_routes();
		UCP_Sync_Trigger::register_routes();
	}
}
