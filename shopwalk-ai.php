<?php
/**
 * Plugin Name: Shopwalk AI
 * Plugin URI:  https://shopwalk.com/woocommerce
 * Description: AI-enable your WooCommerce store in minutes. Shopwalk AI syncs your products and opens your store to AI-powered discovery, browsing, and checkout.
 * Version:     1.9.0
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

/**
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

defined( 'ABSPATH' ) || exit;

define( 'SHOPWALK_AI_VERSION', '1.9.0' );
define( 'SHOPWALK_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SHOPWALK_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// UCP Standardized Error Codes.
define( 'SHOPWALK_ERR_OUT_OF_STOCK', 'OUT_OF_STOCK' );
define( 'SHOPWALK_ERR_INVALID_COUPON', 'INVALID_COUPON' );
define( 'SHOPWALK_ERR_INVALID_SHIPPING', 'INVALID_SHIPPING' );
define( 'SHOPWALK_ERR_PAYMENT_FAILED', 'PAYMENT_FAILED' );
define( 'SHOPWALK_ERR_SESSION_NOT_FOUND', 'SESSION_NOT_FOUND' );
define( 'SHOPWALK_ERR_SESSION_EXPIRED', 'SESSION_EXPIRED' );
define( 'SHOPWALK_ERR_INVALID_ADDRESS', 'INVALID_ADDRESS' );

// Session expiry: 24 hours in seconds.
define( 'SHOPWALK_SESSION_TTL', 86400 );

/**
 * Declare WooCommerce feature compatibility.
 * - HPOS (High-Performance Order Storage)
 * - Cart and Checkout Blocks
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			// High-Performance Order Storage (HPOS / custom_order_tables).
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
			// Cart and Checkout Blocks — this plugin is server-side only and.
			// has no frontend UI that conflicts with the block-based cart/checkout.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				__FILE__,
				true
			);
		}
	}
);

/**
 * Check if WooCommerce is active.
 */
function shopwalk_ai_check_woocommerce(): bool {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="error"><p><strong>' . esc_html__( 'Shopwalk AI', 'shopwalk-ai' ) . '</strong> '
				. esc_html__( 'requires WooCommerce to be installed and active.', 'shopwalk-ai' ) . '</p></div>';
			}
		);
		return false;
	}
	return true;
}

/**
 * Initialize the plugin.
 *
 * Wrapped in try/catch so any fatal error during boot deactivates the plugin
 * gracefully and shows an admin notice — never kills wp-admin.
 */
function shopwalk_ai_init(): void {
	if ( ! shopwalk_ai_check_woocommerce() ) {
		return;
	}

	try {
		// Load includes.
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
		require_once SHOPWALK_AI_PLUGIN_DIR . 'includes/class-shopwalk-wc-cdn.php';

		// Boot.
		Shopwalk_WC::instance();
		Shopwalk_WC_CDN::init();
	} catch ( \Throwable $e ) {
		// Log the error and deactivate gracefully — never bring down wp-admin.
		error_log( 'Shopwalk AI fatal error during init: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
		add_action(
			'admin_notices',
			function () use ( $e ) {
				echo '<div class="notice notice-error"><p>'
					. '<strong>' . esc_html__( 'Shopwalk AI failed to load', 'shopwalk-ai' ) . '</strong><br>'
					. esc_html( $e->getMessage() )
					. '</p></div>';
			}
		);
		// Deactivate the plugin so it doesn't run again on next load.
		add_action(
			'admin_init',
			function () {
				deactivate_plugins( plugin_basename( SHOPWALK_AI_PLUGIN_DIR . 'shopwalk-ai.php' ) );
			}
		);
	}
}
add_action( 'plugins_loaded', 'shopwalk_ai_init' );

/**
 * Activation hook.
 */
function shopwalk_ai_activate(): void {
	flush_rewrite_rules();
	// Schedule the sync queue flush cron (every 5 min).
	if ( class_exists( 'Shopwalk_WC_Sync' ) ) {
		Shopwalk_WC_Sync::schedule_cron();
	}
	// Schedule hourly license refresh cron.
	if ( ! wp_next_scheduled( 'shopwalk_license_refresh' ) ) {
		wp_schedule_event( time(), 'hourly', 'shopwalk_license_refresh' );
	}
	// Auto-register this store with Shopwalk — no manual setup required.
	// If the network call fails, a transient flag is set and retried on admin_init.
	shopwalk_ai_auto_register();
}
/**
 * Attempt to auto-register (or re-register) this store with Shopwalk.
 *
 * Called on activation and retried on admin_init when a previous attempt failed.
 * Idempotent — safe to call multiple times; the API returns the same merchant_id for the same site_url.
 *
 * Supports two channels:
 *  - Pro: define SHOPWALK_REGISTRATION_TOKEN in wp-config.php (included in Pro zip)
 *  - Free: no token — registers as free (WP.org install)
 *
 * @return bool True if registration succeeded or was already complete.
 */
function shopwalk_ai_auto_register(): bool {
	// Already registered — nothing to do.
	if ( ! empty( get_option( 'shopwalk_merchant_id', '' ) ) ) {
		delete_transient( 'shopwalk_wc_needs_registration' );
		return true;
	}

	$payload = array(
		'site_url'   => home_url(),
		'wp_version' => get_bloginfo( 'version' ),
		'wc_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
	);

	// Include registration token for Pro downloads.
	if ( defined( 'SHOPWALK_REGISTRATION_TOKEN' ) && ! empty( SHOPWALK_REGISTRATION_TOKEN ) ) {
		$payload['registration_token'] = SHOPWALK_REGISTRATION_TOKEN;
	}

	$response = wp_remote_post(
		'https://api.shopwalk.com/api/v1/plugin/register',
		array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 20,
		)
	);

	if ( is_wp_error( $response ) ) {
		set_transient( 'shopwalk_wc_needs_registration', 1, DAY_IN_SECONDS );
		return false;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code || empty( $body['merchant_id'] ) ) {
		set_transient( 'shopwalk_wc_needs_registration', 1, DAY_IN_SECONDS );
		return false;
	}

	// Store new license model fields.
	update_option( 'shopwalk_merchant_id', $body['merchant_id'] );
	update_option( 'shopwalk_license_level', $body['license_level'] ?? 'free' );
	update_option( 'shopwalk_license_status', $body['license_status'] ?? 'active' );
	update_option( 'shopwalk_license_refreshed_at', gmdate( 'c' ) );

	// Backward-compat: store API key if returned (used by sync and updater).
	if ( ! empty( $body['api_key'] ) ) {
		update_option( 'shopwalk_wc_plugin_key', $body['api_key'] );
		update_option( 'shopwalk_wc_license_status', 'active' );
	}

	delete_transient( 'shopwalk_wc_needs_registration' );
	flush_rewrite_rules();
	return true;
}

/**
 * Check whether this store has an active Pro license.
 */
function shopwalk_is_pro(): bool {
	return get_option( 'shopwalk_license_level' ) === 'pro'
		&& get_option( 'shopwalk_license_status' ) === 'active';
}

/**
 * Hourly WP Cron handler — refreshes license status from the Shopwalk API.
 */
function shopwalk_license_refresh_handler(): void {
	$response = wp_remote_get(
		'https://api.shopwalk.com/api/v1/plugin/license',
		array(
			'headers' => array(
				'X-SW-Domain'  => home_url(),
				'Content-Type' => 'application/json',
			),
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
		return;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! empty( $body['license_level'] ) ) {
		update_option( 'shopwalk_license_level', $body['license_level'] );
	}
	if ( ! empty( $body['license_status'] ) ) {
		update_option( 'shopwalk_license_status', $body['license_status'] );
	}
	update_option( 'shopwalk_license_refreshed_at', gmdate( 'c' ) );
}
add_action( 'shopwalk_license_refresh', 'shopwalk_license_refresh_handler' );

/**
 * On admin_init, silently retry registration if a previous attempt failed.
 */
add_action(
	'admin_init',
	function (): void {
		if ( get_transient( 'shopwalk_wc_needs_registration' ) ) {
			shopwalk_ai_auto_register();
		}
	}
);
register_activation_hook( __FILE__, 'shopwalk_ai_activate' );

/**
 * Deactivation hook.
 */
function shopwalk_ai_deactivate(): void {
	flush_rewrite_rules();
	// Remove the sync queue flush cron.
	if ( class_exists( 'Shopwalk_WC_Sync' ) ) {
		Shopwalk_WC_Sync::unschedule_cron();
	}
	// Remove the hourly license refresh cron.
	$timestamp = wp_next_scheduled( 'shopwalk_license_refresh' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'shopwalk_license_refresh' );
	}
	// Notify Shopwalk so feeds stop syncing this store immediately.
	// Fire-and-forget — errors are silently ignored to never block deactivation.
	$plugin_key = get_option( 'shopwalk_wc_plugin_key', '' );
	if ( ! empty( $plugin_key ) ) {
		wp_remote_post(
			'https://api.shopwalk.com/api/v1/plugin/deactivate',
			array(
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode(
					array(
						'plugin_key' => $plugin_key,
						'site_url'   => home_url(),
					)
				),
				'timeout'  => 8,
				'blocking' => false, // Non-blocking — don't wait for response.
			)
		);
	}
}
register_deactivation_hook( __FILE__, 'shopwalk_ai_deactivate' );
