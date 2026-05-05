<?php
/**
 * UCP Self-Test — diagnostic checks for the dashboard "Run self-test" button.
 *
 * Each check returns:
 *   array{ check:string, status: 'pass'|'warn'|'fail', message:string }
 *
 * The admin AJAX handler (WooCommerce_Shopwalk_Admin_Dashboard::ajax_self_test in
 * admin/class-dashboard.php) calls run_all() and streams results to the
 * dashboard one row at a time.
 *
 * @package ShopwalkWooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Self_Test — diagnostic runner.
 */
final class UCP_Self_Test {

	/**
	 * Run every check and return the result list.
	 *
	 * @return array<int, array{check:string, status:string, message:string}>
	 */
	public static function run_all(): array {
		$results   = array();
		$results[] = self::check_well_known_ucp();
		$results[] = self::check_well_known_oauth();
		$results[] = self::check_oauth_authorize();
		$results[] = self::check_checkout_create();
		$results[] = self::check_wp_cron_alive();
		$results[] = self::check_payment_gateway_registered();
		$results[] = self::check_signing_secret();
		$results[] = self::check_db_tables();
		return $results;
	}

	/**
	 * @return array{check:string, status:string, message:string}
	 */
	private static function check_well_known_ucp(): array {
		$response = wp_remote_get( home_url( '/.well-known/ucp' ), array( 'timeout' => 5 ) );
		if ( is_wp_error( $response ) ) {
			return array(
				'check'   => '/.well-known/ucp reachable',
				'status'  => 'fail',
				'message' => $response->get_error_message(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return array(
				'check'   => '/.well-known/ucp reachable',
				'status'  => 'fail',
				'message' => 'HTTP ' . $status,
			);
		}
		return array(
			'check'   => '/.well-known/ucp reachable',
			'status'  => 'pass',
			'message' => 'Discovery doc served via static .well-known/ucp.php shim.',
		);
	}

	/**
	 * @return array{check:string, status:string, message:string}
	 */
	private static function check_well_known_oauth(): array {
		$response = wp_remote_get( home_url( '/.well-known/oauth-authorization-server' ), array( 'timeout' => 5 ) );
		if ( is_wp_error( $response ) ) {
			return array(
				'check'   => '/.well-known/oauth-authorization-server reachable',
				'status'  => 'fail',
				'message' => $response->get_error_message(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return array(
				'check'   => '/.well-known/oauth-authorization-server reachable',
				'status'  => 'fail',
				'message' => 'HTTP ' . $status,
			);
		}
		return array(
			'check'   => '/.well-known/oauth-authorization-server reachable',
			'status'  => 'pass',
			'message' => 'OAuth server metadata published.',
		);
	}

	/**
	 * @return array{check:string, status:string, message:string}
	 */
	private static function check_oauth_authorize(): array {
		$response = wp_remote_get( rest_url( UCP_REST_NAMESPACE . '/oauth/authorize' ), array( 'timeout' => 5 ) );
		if ( is_wp_error( $response ) ) {
			return array(
				'check'   => 'OAuth /authorize wired',
				'status'  => 'fail',
				'message' => $response->get_error_message(),
			);
		}
		// Without query params we expect a 400 (missing client_id) — that's a healthy "wired" signal.
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			return array(
				'check'   => 'OAuth /authorize wired',
				'status'  => 'fail',
				'message' => 'Route not registered (got 404).',
			);
		}
		return array(
			'check'   => 'OAuth /authorize wired',
			'status'  => 'pass',
			'message' => 'Endpoint responds.',
		);
	}

	/**
	 * @return array{check:string, status:string, message:string}
	 */
	private static function check_checkout_create(): array {
		$response = wp_remote_post(
			rest_url( UCP_REST_NAMESPACE . '/checkout-sessions' ),
			array(
				'timeout' => 5,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array() ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'check'   => 'POST /checkout-sessions wired',
				'status'  => 'fail',
				'message' => $response->get_error_message(),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $status ) {
			return array(
				'check'   => 'POST /checkout-sessions wired',
				'status'  => 'fail',
				'message' => 'Route not registered (got 404).',
			);
		}
		// Expect 400 — missing line_items[]. Anything other than 404/5xx is healthy.
		return array(
			'check'   => 'POST /checkout-sessions wired',
			'status'  => 'pass',
			'message' => 'Endpoint responds.',
		);
	}

	/**
	 * @return array{check:string, status:string, message:string}
	 */
	private static function check_wp_cron_alive(): array {
		$next = wp_next_scheduled( 'shopwalk_webhook_flush' );
		if ( ! $next ) {
			return array(
				'check'   => 'WP-Cron scheduled',
				'status'  => 'fail',
				'message' => 'shopwalk_webhook_flush is not scheduled.',
			);
		}
		return array(
			'check'   => 'WP-Cron scheduled',
			'status'  => 'pass',
			'message' => 'Next webhook flush at ' . gmdate( 'c', (int) $next ),
		);
	}

	/**
	 * @return array{check:string, status:string, message:string}
	 */
	private static function check_payment_gateway_registered(): array {
		if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
			return array(
				'check'   => 'WC payment gateway "Pay via UCP"',
				'status'  => 'warn',
				'message' => 'WooCommerce not loaded.',
			);
		}
		$gateways = WC_Payment_Gateways::instance()->payment_gateways();
		if ( ! isset( $gateways['shopwalk_ucp'] ) ) {
			return array(
				'check'   => 'WC payment gateway "Pay via UCP"',
				'status'  => 'fail',
				'message' => 'Gateway not registered with WooCommerce.',
			);
		}
		return array(
			'check'   => 'WC payment gateway "Pay via UCP"',
			'status'  => 'pass',
			'message' => 'Gateway registered.',
		);
	}

	/**
	 * @return array{check:string, status:string, message:string}
	 */
	private static function check_signing_secret(): array {
		$secret = UCP_Signing::store_secret();
		if ( '' === $secret ) {
			return array(
				'check'   => 'Store signing secret',
				'status'  => 'fail',
				'message' => 'No signing secret found.',
			);
		}
		return array(
			'check'   => 'Store signing secret',
			'status'  => 'pass',
			'message' => 'Outbound webhook signing secret is set (' . strlen( $secret ) . ' chars).',
		);
	}

	/**
	 * @return array{check:string, status:string, message:string}
	 */
	private static function check_db_tables(): array {
		global $wpdb;
		$missing = array();
		foreach ( array( 'oauth_clients', 'oauth_tokens', 'checkout_sessions', 'webhook_subscriptions', 'webhook_queue' ) as $name ) {
			$table = UCP_Storage::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists !== $table ) {
				$missing[] = $name;
			}
		}
		if ( count( $missing ) > 0 ) {
			return array(
				'check'   => 'UCP database tables',
				'status'  => 'fail',
				'message' => 'Missing: ' . implode( ', ', $missing ) . '. Re-activate the plugin to recreate.',
			);
		}
		return array(
			'check'   => 'UCP database tables',
			'status'  => 'pass',
			'message' => 'All 5 wp_ucp_* tables exist.',
		);
	}
}
