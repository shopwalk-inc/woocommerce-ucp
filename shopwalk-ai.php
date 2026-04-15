<?php
/**
 * Plugin Name: Shopwalk AI — UCP Commerce Adapter for WooCommerce
 * Plugin URI:  https://github.com/shopwalk-inc/woocommerce-ucp
 * Description: Make any WooCommerce store fully purchasable by UCP-compliant AI shopping agents (Shopwalk, OpenAI, Anthropic, LangChain, custom). Implements the Universal Commerce Protocol (ucp.dev) — checkout, OAuth identity, orders, webhooks. Optional Shopwalk integration layered on top.
 * Version:     3.0.28
 * Author:      Shopwalk, Inc.
 * Author URI:  https://shopwalk.com
 * Requires Plugins: woocommerce
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: shopwalk-ai
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

// ─── Constants ──────────────────────────────────────────────────────────────

define( 'SHOPWALK_AI_VERSION', '3.0.28' );
define( 'SHOPWALK_AI_PLUGIN_FILE', __FILE__ );
define( 'SHOPWALK_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHOPWALK_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// UCP namespace + table prefix.
define( 'UCP_REST_NAMESPACE', 'ucp/v1' );
define( 'UCP_TABLE_PREFIX', 'ucp_' );

// Tier 2 (Shopwalk integration) constants.
define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.com/api/v1' );
define( 'SHOPWALK_PARTNERS_URL', 'https://shopwalk.com/partners' );
define( 'SHOPWALK_SIGNUP_URL', 'https://shopwalk.com/partners/signup' );

// ─── Activation / Deactivation ──────────────────────────────────────────────

register_activation_hook( __FILE__, array( 'Shopwalk_AI', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Shopwalk_AI', 'deactivate' ) );

// ─── Bootstrap ──────────────────────────────────────────────────────────────

require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-ai.php';

add_action( 'plugins_loaded', array( 'Shopwalk_AI', 'instance' ), 5 );

// ─── Plugin action links (Plugins list page) ────────────────────────────────

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		$dashboard = '<a href="' . esc_url( admin_url( 'admin.php?page=shopwalk-ai' ) ) . '">' . esc_html__( 'Dashboard', 'shopwalk-ai' ) . '</a>';
		array_unshift( $links, $dashboard );
		return $links;
	}
);
