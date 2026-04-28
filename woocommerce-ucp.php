<?php
/**
 * Plugin Name: WooCommerce UCP — Universal Commerce Protocol
 * Plugin URI:  https://github.com/shopwalk-inc/woocommerce-ucp
 * Description: Make any WooCommerce store fully purchasable by UCP-compliant AI shopping agents. Implements the Universal Commerce Protocol (ucp.dev) — checkout, OAuth identity, orders, webhooks. Optional Shopwalk network integration available with a free license.
 * Version:     3.0.55
 * Author:      Shopwalk, Inc.
 * Author URI:  https://shopwalk.com
 * Requires Plugins: woocommerce
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woocommerce-ucp
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 9.8
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ──────────────────────────────────────────────────────────────

define( 'WOOCOMMERCE_UCP_VERSION', '3.0.55' );
define( 'WOOCOMMERCE_UCP_PLUGIN_FILE', __FILE__ );
define( 'WOOCOMMERCE_UCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOOCOMMERCE_UCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// UCP namespace + table prefix.
define( 'UCP_REST_NAMESPACE', 'ucp/v1' );
define( 'UCP_TABLE_PREFIX', 'ucp_' );

// Tier 2 (Shopwalk integration) constants.
define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.com/api/v1' );
define( 'SHOPWALK_PARTNERS_URL', 'https://shopwalk.com/partners' );
define( 'SHOPWALK_SIGNUP_URL', 'https://shopwalk.com/partners/signup' );

// ─── WooCommerce feature compatibility ──────────────────────────────────────
//
// Declared as early as possible so WooCommerce sees them before feature gates
// run. Must be registered on `before_woocommerce_init` per WC guidelines.

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			// HPOS (High-Performance Order Storage): this plugin reads/writes orders
			// exclusively via the WC CRUD API (wc_get_order/$order->save()), never raw $wpdb.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );

			// Cart/Checkout Blocks: the plugin is server-side only (REST endpoints +
			// a payment gateway). It does not render or modify cart/checkout UI, so
			// it is compatible with the Blocks-based checkout out of the box.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

// ─── Activation / Deactivation ──────────────────────────────────────────────

register_activation_hook( __FILE__, array( 'WooCommerce_UCP', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WooCommerce_UCP', 'deactivate' ) );

// ─── Bootstrap ──────────────────────────────────────────────────────────────

require_once WOOCOMMERCE_UCP_PLUGIN_DIR . 'includes/class-woocommerce-ucp.php';

add_action( 'plugins_loaded', array( 'WooCommerce_UCP', 'instance' ), 5 );

// ─── Plugin action links (Plugins list page) ────────────────────────────────

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		$dashboard = '<a href="' . esc_url( admin_url( 'admin.php?page=woocommerce-ucp' ) ) . '">' . esc_html__( 'Dashboard', 'woocommerce-ucp' ) . '</a>';
		array_unshift( $links, $dashboard );
		return $links;
	}
);
