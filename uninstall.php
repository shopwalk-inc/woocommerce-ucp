<?php
/**
 * Uninstall WooCommerce UCP — Universal Commerce Protocol.
 *
 * Removes the plugin's local state cleanly:
 *  - All wp_ucp_* tables (oauth_clients, oauth_tokens, checkout_sessions,
 *    webhook_subscriptions, webhook_queue)
 *  - All shopwalk_* WP options (license, partner_id, sync queue, store
 *    signing secret, etc.)
 *  - Scheduled WP-Cron jobs (session cleanup, webhook flush, sync flush)
 *  - The static /.well-known/ucp.php and oauth-authorization-server.php
 *    files written on activation
 *
 * @package WooCommerceUCP
 */

// Only run if WordPress triggered this uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Drop UCP tables ─────────────────────────────────────────────────────────
global $wpdb;
$tables = array(
	'webhook_queue',
	'webhook_subscriptions',
	'checkout_sessions',
	'oauth_tokens',
	'oauth_clients',
);
foreach ( $tables as $name ) {
	$table = $wpdb->prefix . 'ucp_' . $name;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// ── Delete WP options ───────────────────────────────────────────────────────
$options = array(
	'shopwalk_license_key',
	'shopwalk_partner_id',
	'shopwalk_sync_queue',
	'shopwalk_synced_count',
	'shopwalk_last_sync_at',
	'shopwalk_notice_dismissed',
	'shopwalk_ucp_gateway_enabled',
	'shopwalk_ucp_store_signing_secret',
);
foreach ( $options as $option ) {
	delete_option( $option );
}

// ── Clear scheduled crons ───────────────────────────────────────────────────
wp_clear_scheduled_hook( 'shopwalk_ucp_session_cleanup' );
wp_clear_scheduled_hook( 'shopwalk_ucp_webhook_flush' );
wp_clear_scheduled_hook( 'shopwalk_flush_queue' );

// ── Remove /.well-known/ files ─────────────────────────────────────────────
require_once ABSPATH . 'wp-admin/includes/file.php';
WP_Filesystem();
global $wp_filesystem;
if ( $wp_filesystem ) {
	$well_known_dir = ABSPATH . '.well-known';
	foreach ( array( 'ucp.php', 'oauth-authorization-server.php' ) as $file ) {
		$file_path = $well_known_dir . '/' . $file;
		if ( $wp_filesystem->exists( $file_path ) ) {
			$wp_filesystem->delete( $file_path );
		}
	}
	$htaccess = $well_known_dir . '/.htaccess';
	if ( $wp_filesystem->exists( $htaccess ) ) {
		$contents = (string) $wp_filesystem->get_contents( $htaccess );
		if ( false !== strpos( $contents, 'woocommerce-ucp plugin' ) || false !== strpos( $contents, 'shopwalk-ai plugin' ) ) {
			$wp_filesystem->delete( $htaccess );
		}
	}
}

// Clear transients.
delete_transient( 'shopwalk_latest_version' );
