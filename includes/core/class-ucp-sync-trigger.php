<?php
/**
 * UCP Sync Trigger Endpoint
 *
 * POST /wp-json/ucp/v1/sync/trigger
 *
 * Called by the shopwalk-sync scheduler when a partner's catalog is due for
 * a re-sync. Enqueues a background product push via WP-Cron (Action Scheduler
 * if available, wp_schedule_single_event as fallback).
 *
 * Auth: HMAC-signed request from Shopwalk (X-Shopwalk-Signature header).
 *
 * @package WooCommerceUCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UCP_Sync_Trigger {

	/**
	 * Register the sync trigger route.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/sync/trigger',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_trigger' ),
				'permission_callback' => array( __CLASS__, 'verify_signature' ),
			)
		);
	}

	/**
	 * Verify the HMAC signature from Shopwalk.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return bool|WP_Error
	 */
	public static function verify_signature( WP_REST_Request $request ) {
		$secret = get_option( 'shopwalk_webhook_secret', '' );
		if ( empty( $secret ) ) {
			// No secret configured — reject for security
			return new WP_Error(
				'sync_not_configured',
				'Webhook secret not configured',
				array( 'status' => 403 )
			);
		}

		$signature = $request->get_header( 'X-Shopwalk-Signature' );
		if ( empty( $signature ) ) {
			return new WP_Error(
				'missing_signature',
				'X-Shopwalk-Signature header required',
				array( 'status' => 401 )
			);
		}

		$body     = $request->get_body();
		$expected = hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error(
				'invalid_signature',
				'Signature verification failed',
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Handle the sync trigger request.
	 *
	 * Enqueues a background product sync push. Products are pushed to
	 * Shopwalk via POST /api/v1/plugin/sync/batch in batches of 100.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_trigger( WP_REST_Request $request ): WP_REST_Response {
		$reason = $request->get_param( 'reason' ) ?? 'scheduled';

		// Use Action Scheduler if available (WooCommerce ships it)
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$action_id = as_enqueue_async_action(
				'shopwalk_sync_push_products',
				array( 'reason' => $reason ),
				'shopwalk'
			);

			return new WP_REST_Response(
				array(
					'status'    => 'queued',
					'action_id' => $action_id,
					'reason'    => $reason,
				),
				202
			);
		}

		// Fallback: wp_schedule_single_event (runs on next page load)
		$scheduled = wp_schedule_single_event(
			time(),
			'shopwalk_sync_push_products',
			array( 'reason' => $reason )
		);

		return new WP_REST_Response(
			array(
				'status'  => $scheduled ? 'scheduled' : 'failed',
				'reason'  => $reason,
				'method'  => 'wp_cron',
			),
			$scheduled ? 202 : 500
		);
	}
}
