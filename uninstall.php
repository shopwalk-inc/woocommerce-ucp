<?php
/**
 * Uninstall — runs when the plugin is deleted via the WordPress admin.
 *
 * Removes all plugin options from wp_options. Does NOT delete product data
 * or orders — those belong to the store owner.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

// If uninstall not called from WordPress, exit immediately.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Purge all store data from Shopwalk before clearing WP options ──────────
// Deactivation = soft-delete (merchant hidden, restorable).
// Deletion (this file) = hard purge — merchant, products, and license removed permanently.
$_sw_plugin_key = get_option( 'shopwalk_wc_plugin_key', '' );
$_sw_site_url   = get_option( 'siteurl', home_url() );

if ( ! empty( $_sw_plugin_key ) ) {
	wp_remote_post(
		'https://api.shopwalk.com/api/v1/plugin/purge',
		array(
			'headers'   => array( 'Content-Type' => 'application/json' ),
			'body'      => wp_json_encode(
				array(
					'plugin_key' => $_sw_plugin_key,
					'site_url'   => $_sw_site_url,
				)
			),
			'timeout'   => 10,
			'blocking'  => true,
		)
	);
}
unset( $_sw_plugin_key, $_sw_site_url );

// All plugin options to remove on uninstall.
$options = array(
	// Core settings.
	'shopwalk_wc_plugin_key',
	'shopwalk_wc_api_key',
	'shopwalk_wc_enable_sync',
	'shopwalk_wc_enable_catalog',
	'shopwalk_wc_enable_checkout',
	'shopwalk_wc_enable_webhooks',
	'shopwalk_wc_settings',
	// Sync state.
	'shopwalk_wc_last_sync',
	'shopwalk_wc_sync_status',
	'shopwalk_wc_license_status',
	// Webhooks registry.
	'shopwalk_wc_webhooks',
	// License model (v1.7.0).
	'shopwalk_merchant_id',
	'shopwalk_license_level',
	'shopwalk_license_status',
	'shopwalk_license_refreshed_at',
	// CDN (v1.8.0).
	'shopwalk_cdn_enabled',
	// Legacy keys (kept for clean migration from older versions).
	'shopwalk_wc_license_key',
	'shopwalk_wc_shopwalk_api_key',
	'shopwalk_wc_shopwalk_api_url',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Remove cached update transient.
delete_transient( 'shopwalk_wc_update_info' );
