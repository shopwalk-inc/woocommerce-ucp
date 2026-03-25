<?php
/**
 * Uninstall Shopwalk — clean up all plugin data.
 *
 * @package Shopwalk
 */

// Only run if WordPress triggered this uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete all plugin options.
$options = array(
	'shopwalk_license_key',
	'shopwalk_site_domain',
	'shopwalk_partner_id',
	'shopwalk_activated_at',
	'shopwalk_last_sync_at',
	'shopwalk_synced_count',
	'shopwalk_sync_queue',
	'shopwalk_notice_dismissed',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Clear scheduled cron.
wp_clear_scheduled_hook( 'shopwalk_flush_queue' );
