<?php
/**
 * Uninstall Shopwalk.
 *
 * Deactivating or deleting the plugin stops catalog sync only.
 * Your Shopwalk account and store data are preserved on Shopwalk's servers.
 * Reinstall and sign in at shopwalk.com/partners to reconnect at any time.
 *
 * @package Shopwalk
 */

// Only run if WordPress triggered this uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove local plugin options — these are just cached state, not account data.
// The Shopwalk account and indexed product data are preserved server-side.
$options = array(
	'shopwalk_license_key',
	'shopwalk_site_domain',
	'shopwalk_partner_id',
	'shopwalk_activated_at',
	'shopwalk_last_sync_at',
	'shopwalk_synced_count',
	'shopwalk_sync_queue',
	'shopwalk_notice_dismissed',
	'shopwalk_ucp_discovery_enabled',
	'shopwalk_ucp_reachable',
	'shopwalk_ucp_checked_at',
	'shopwalk_ucp_host_name',
	'shopwalk_ucp_host_phone',
	'shopwalk_ucp_host_support',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear scheduled crons.
wp_clear_scheduled_hook( 'shopwalk_flush_queue' );
wp_clear_scheduled_hook( 'shopwalk_ucp_recheck' );
