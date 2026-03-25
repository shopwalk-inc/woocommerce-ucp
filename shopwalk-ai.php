<?php
/**
 * Plugin Name: Shopwalk
 * Plugin URI:  https://shopwalk.com/woocommerce
 * Description: Make your WooCommerce store discoverable by AI shopping agents. Free UCP implementation — no account required.
 * Version:     2.0.0
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

define( 'SHOPWALK_VERSION', '2.0.0' );
define( 'SHOPWALK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHOPWALK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.com/api/v1' );
define( 'SHOPWALK_PARTNERS_URL', 'https://shopwalk.com/partners' );
define( 'SHOPWALK_SIGNUP_URL', 'https://shopwalk.com/partners/signup' );

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
}
register_activation_hook( __FILE__, 'shopwalk_activate' );

/**
 * Plugin deactivation hook.
 */
function shopwalk_deactivate(): void {
	wp_clear_scheduled_hook( 'shopwalk_flush_queue' );
}
register_deactivation_hook( __FILE__, 'shopwalk_deactivate' );
