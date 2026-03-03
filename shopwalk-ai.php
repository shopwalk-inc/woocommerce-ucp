<?php
/**
 * Plugin Name: Shopwalk AI
 * Plugin URI:  https://shopwalk.com/woocommerce
 * Description: AI-enable your WooCommerce store in minutes. Shopwalk AI syncs your products and opens your store to AI-powered discovery, browsing, and checkout.
 * Version:     1.4.0
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
 * @package ShopwalkAI
 */

/*
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

defined('ABSPATH') || exit;

define('SHOPWALK_AI_VERSION',    '1.4.0');
define('SHOPWALK_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SHOPWALK_AI_PLUGIN_URL', plugin_dir_url(__FILE__));

// UCP Standardized Error Codes
define('SHOPWALK_ERR_OUT_OF_STOCK',      'OUT_OF_STOCK');
define('SHOPWALK_ERR_INVALID_COUPON',    'INVALID_COUPON');
define('SHOPWALK_ERR_INVALID_SHIPPING',  'INVALID_SHIPPING');
define('SHOPWALK_ERR_PAYMENT_FAILED',    'PAYMENT_FAILED');
define('SHOPWALK_ERR_SESSION_NOT_FOUND', 'SESSION_NOT_FOUND');
define('SHOPWALK_ERR_SESSION_EXPIRED',   'SESSION_EXPIRED');
define('SHOPWALK_ERR_INVALID_ADDRESS',   'INVALID_ADDRESS');

// Session expiry: 24 hours in seconds
define('SHOPWALK_SESSION_TTL', 86400);

/**
 * Declare WooCommerce feature compatibility.
 * - HPOS (High-Performance Order Storage)
 * - Cart and Checkout Blocks
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        // High-Performance Order Storage (HPOS / custom_order_tables)
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
        // Cart and Checkout Blocks — this plugin is server-side only and
        // has no frontend UI that conflicts with the block-based cart/checkout.
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
});

/**
 * Check if WooCommerce is active.
 */
function shopwalk_ai_check_woocommerce(): bool {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>' . esc_html__('Shopwalk AI', 'shopwalk-ai') . '</strong> '
                . esc_html__('requires WooCommerce to be installed and active.', 'shopwalk-ai') . '</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Initialize the plugin.
 */
function shopwalk_ai_init(): void {
    if (!shopwalk_ai_check_woocommerce()) {
        return;
    }

    // Load includes
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-profile.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-products.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-checkout.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-orders.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-webhooks.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-settings.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-auth.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-sync.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-updater.php';
    require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-dashboard.php';

    // Boot
    Shopwalk_WC::instance();
}
add_action('plugins_loaded', 'shopwalk_ai_init');

/**
 * Activation hook.
 */
function shopwalk_ai_activate(): void {
    flush_rewrite_rules();
    // Schedule the sync queue flush cron (every 5 min)
    if (class_exists('Shopwalk_WC_Sync')) {
        Shopwalk_WC_Sync::schedule_cron();
    }
}
register_activation_hook(__FILE__, 'shopwalk_ai_activate');

/**
 * Deactivation hook.
 */
function shopwalk_ai_deactivate(): void {
    flush_rewrite_rules();
    // Remove the sync queue flush cron
    if (class_exists('Shopwalk_WC_Sync')) {
        Shopwalk_WC_Sync::unschedule_cron();
    }
}
register_deactivation_hook(__FILE__, 'shopwalk_ai_deactivate');
