<?php
/**
 * Plugin Name: Shopwalk
 * Plugin URI:  https://shopwalk.com/woocommerce
 * Description: Make your WooCommerce store discoverable by AI shopping agents. Free UCP implementation — no account required.
 * Version:     2.0.17
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

/**
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 */

defined( 'ABSPATH' ) || exit;

define( 'SHOPWALK_VERSION', '2.0.17' );
define( 'SHOPWALK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHOPWALK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.com/api/v1' );
define( 'SHOPWALK_PARTNERS_URL', 'https://shopwalk.com/partners' );
define( 'SHOPWALK_SIGNUP_URL', 'https://shopwalk.com/partners/signup' );

// Auto-populate license key from config file (injected at download time).
if ( file_exists( SHOPWALK_PLUGIN_DIR . 'shopwalk-ai-config.php' ) ) {
	require_once SHOPWALK_PLUGIN_DIR . 'shopwalk-ai-config.php';
}
if ( defined( 'SHOPWALK_AI_PREFILLED_LICENSE' ) && ! get_option( 'shopwalk_license_key' ) ) {
	update_option( 'shopwalk_license_key', SHOPWALK_AI_PREFILLED_LICENSE );
}

// Load classes.
require_once SHOPWALK_PLUGIN_DIR . 'includes/class-shopwalk-wc-products.php';
require_once SHOPWALK_PLUGIN_DIR . 'includes/class-shopwalk-wc-ucp.php';
require_once SHOPWALK_PLUGIN_DIR . 'includes/class-shopwalk-wc-sync.php';
require_once SHOPWALK_PLUGIN_DIR . 'includes/class-shopwalk-wc-settings.php';
require_once SHOPWALK_PLUGIN_DIR . 'includes/class-shopwalk-wc-dashboard.php';
require_once SHOPWALK_PLUGIN_DIR . 'includes/class-shopwalk-wc.php';

/**
 * Initialize the plugin after all plugins are loaded.
 */
function shopwalk_init() {
	Shopwalk_WC::instance();
}
add_action( 'plugins_loaded', 'shopwalk_init' );

/**
 * Register custom cron interval (5 minutes).
 *
 * @param array $schedules Existing cron schedules.
 * @return array Modified schedules.
 */
function shopwalk_cron_intervals( array $schedules ): array {
	$schedules['shopwalk_five_minutes'] = array(
		'interval' => 300,
		'display'  => esc_html__( 'Every 5 Minutes', 'shopwalk-ai' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'shopwalk_cron_intervals' );

/**
 * Plugin activation hook.
 */
function shopwalk_activate(): void {
	if ( ! wp_next_scheduled( 'shopwalk_flush_queue' ) ) {
		wp_schedule_event( time(), 'shopwalk_five_minutes', 'shopwalk_flush_queue' );
	}

	// Auto-populate license from config file on activation.
	if ( file_exists( SHOPWALK_PLUGIN_DIR . 'shopwalk-ai-config.php' ) ) {
		require_once SHOPWALK_PLUGIN_DIR . 'shopwalk-ai-config.php';
	}
	if ( defined( 'SHOPWALK_AI_PREFILLED_LICENSE' ) && ! get_option( 'shopwalk_license_key' ) ) {
		update_option( 'shopwalk_license_key', SHOPWALK_AI_PREFILLED_LICENSE );
	}

	// Create static /.well-known/ucp.php for reliable UCP discovery on shared hosts.
	shopwalk_create_well_known_files();
}
register_activation_hook( __FILE__, 'shopwalk_activate' );

/**
 * Plugin deactivation hook.
 */
function shopwalk_deactivate(): void {
	wp_clear_scheduled_hook( 'shopwalk_flush_queue' );
	shopwalk_remove_well_known_files();
}
register_deactivation_hook( __FILE__, 'shopwalk_deactivate' );

/**
 * Create /.well-known/ucp.php and .htaccess for reliable UCP discovery.
 * Static PHP file approach works on shared hosting where WordPress rewrites fail.
 */
function shopwalk_create_well_known_files(): void {
	$dir = ABSPATH . '.well-known';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$ucp_php = <<<'PHP'
<?php
/**
 * Shopwalk UCP Business Profile — served at /.well-known/ucp
 * Created by shopwalk-ai plugin. Safe to delete if plugin is removed.
 */
if ( ! file_exists( dirname( __FILE__, 2 ) . '/wp-load.php' ) ) { exit; }
require_once dirname( __FILE__, 2 ) . '/wp-load.php';
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-cache, must-revalidate' );
header( 'Access-Control-Allow-Origin: *' );
if ( ! class_exists( 'Shopwalk_WC_UCP' ) ) {
    http_response_code( 503 );
    echo wp_json_encode( array( 'error' => 'Shopwalk plugin not active' ) );
    exit;
}
$request  = new WP_REST_Request( 'GET', '/shopwalk/v1/.well-known/ucp' );
$response = rest_do_request( $request );
$data     = rest_get_server()->response_to_data( $response, false );
echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
exit;
PHP;

	$htaccess = <<<'HTACCESS'
# Managed by shopwalk-ai plugin
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^ucp/?$ ucp.php [L]
</IfModule>
HTACCESS;

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $dir . '/ucp.php', $ucp_php );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $dir . '/.htaccess', $htaccess );
}

/**
 * Remove /.well-known/ucp.php and .htaccess on deactivation.
 */
function shopwalk_remove_well_known_files(): void {
	$dir = ABSPATH . '.well-known';
	if ( file_exists( $dir . '/ucp.php' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $dir . '/ucp.php' );
	}
	$htaccess = $dir . '/.htaccess';
	if ( file_exists( $htaccess ) && str_contains( (string) file_get_contents( $htaccess ), 'shopwalk-ai plugin' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $htaccess );
	}
}

/**
 * Add action links on the Plugins list page (Dashboard, Settings).
 *
 * @param array $links Existing plugin action links.
 * @return array Modified links.
 */
function shopwalk_plugin_action_links( array $links ): array {
	$dashboard_link = '<a href="' . esc_url( admin_url( 'admin.php?page=shopwalk' ) ) . '">' . esc_html__( 'Dashboard', 'shopwalk-ai' ) . '</a>';
	$settings_link  = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=shopwalk' ) ) . '">' . esc_html__( 'Settings', 'shopwalk-ai' ) . '</a>';
	array_unshift( $links, $dashboard_link, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'shopwalk_plugin_action_links' );
