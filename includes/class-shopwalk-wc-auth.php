<?php
/**
 * API Authentication — API key-based auth for platform-to-business calls.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Auth class.
 */
class Shopwalk_WC_Auth {

	/**
	 * Verify the incoming request has a valid API key.
	 * API key is set in plugin settings and sent via Authorization: Bearer <key>
	 *
	 * @param WP_REST_Request $request REST request object.
	 */
	public static function verify_request( WP_REST_Request $request ): bool {
		// 1. Baked-in license from personalized download zip takes priority.
		if ( defined( 'SHOPWALK_AI_PREFILLED_LICENSE' ) && ! empty( SHOPWALK_AI_PREFILLED_LICENSE ) ) {
			$api_key = SHOPWALK_AI_PREFILLED_LICENSE;
		} else {
			// 2. Fall back to wp_options (manual setup).
			$settings = get_option( 'shopwalk_wc_settings', array() );
			$api_key  = $settings['api_key'] ?? get_option( 'shopwalk_wc_plugin_key', '' );
		}

		if ( empty( $api_key ) ) {
			// If no API key is configured, allow all requests (open mode).
			return true;
		}

		$auth_header = $request->get_header( 'Authorization' );
		if ( ! $auth_header ) {
			return false;
		}

		// Support "Bearer <key>" format.
		if ( preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
			return hash_equals( $api_key, trim( $matches[1] ) );
		}

		return false;
	}

	/**
	 * Permission callback for protected endpoints.
	 *
	 * @param WP_REST_Request $request REST request object.
	 */
	public static function check_permission( WP_REST_Request $request ): bool|WP_Error {
		if ( ! self::verify_request( $request ) ) {
			return new WP_Error(
				'shopwalk_wc_unauthorized',
				'Invalid or missing API key.',
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Permission callback for public endpoints (catalog browsing).
	 *
	 * @param WP_REST_Request $request REST request object.
	 */
	public static function check_public_permission( WP_REST_Request $request ): bool {
		return true;
	}
}
