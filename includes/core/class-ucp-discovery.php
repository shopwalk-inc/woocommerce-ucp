<?php
/**
 * UCP Discovery — /.well-known/ucp + /.well-known/oauth-authorization-server.
 *
 * The well-known files written on plugin activation are static PHP shims
 * (so Apache shared hosts that rewrite the URI before WordPress sees it
 * still work). The shims include wp-load.php and dispatch to the REST
 * routes registered here.
 *
 * @package Shopwalk
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Discovery — discovery doc handlers.
 */
final class UCP_Discovery {

	/**
	 * Register discovery routes under the UCP namespace.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/.well-known/ucp',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ucp_profile' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			UCP_REST_NAMESPACE,
			'/.well-known/oauth-authorization-server',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'oauth_server_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * UCP service discovery document. Lists every endpoint the store
	 * exposes per the UCP spec.
	 *
	 * @return WP_REST_Response
	 */
	public static function ucp_profile(): WP_REST_Response {
		$base = get_rest_url( null, UCP_REST_NAMESPACE );
		return new WP_REST_Response(
			array(
				'ucp_version' => '1.0',
				'platform'    => 'woocommerce',
				'plugin'      => array(
					'name'    => 'Shopwalk AI — UCP Adapter',
					'version' => SHOPWALK_AI_VERSION,
					'source'  => 'https://github.com/shopwalk-inc/woocommerce-ucp',
				),
				'store'       => array(
					'name'        => (string) get_bloginfo( 'name' ),
					'url'         => (string) home_url(),
					'description' => (string) get_bloginfo( 'description' ),
					'currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				),
				'capabilities' => array(
					'oauth2'           => true,
					'checkout_sessions' => true,
					'orders'           => true,
					'webhooks'         => true,
					'request_signing'  => true,
				),
				'endpoints' => array(
					'oauth' => array(
						'authorization_endpoint' => $base . '/oauth/authorize',
						'token_endpoint'         => $base . '/oauth/token',
						'revocation_endpoint'    => $base . '/oauth/revoke',
						'userinfo_endpoint'      => $base . '/oauth/userinfo',
					),
					'checkout' => array(
						'create'   => $base . '/checkout-sessions',
						'retrieve' => $base . '/checkout-sessions/{id}',
						'update'   => $base . '/checkout-sessions/{id}',
						'complete' => $base . '/checkout-sessions/{id}/complete',
						'cancel'   => $base . '/checkout-sessions/{id}/cancel',
					),
					'orders' => array(
						'list'     => $base . '/orders',
						'retrieve' => $base . '/orders/{id}',
						'events'   => $base . '/orders/{id}/events',
					),
					'webhooks' => array(
						'subscribe'    => $base . '/webhooks/subscriptions',
						'subscription' => $base . '/webhooks/subscriptions/{id}',
					),
				),
			),
			200
		);
	}

	/**
	 * OAuth 2.0 authorization server metadata per RFC 8414.
	 *
	 * @return WP_REST_Response
	 */
	public static function oauth_server_metadata(): WP_REST_Response {
		$base = get_rest_url( null, UCP_REST_NAMESPACE );
		return new WP_REST_Response(
			array(
				'issuer'                          => (string) home_url(),
				'authorization_endpoint'          => $base . '/oauth/authorize',
				'token_endpoint'                  => $base . '/oauth/token',
				'revocation_endpoint'             => $base . '/oauth/revoke',
				'userinfo_endpoint'               => $base . '/oauth/userinfo',
				'response_types_supported'        => array( 'code' ),
				'grant_types_supported'           => array( 'authorization_code', 'refresh_token' ),
				'token_endpoint_auth_methods_supported' => array( 'client_secret_post' ),
				'scopes_supported' => array(
					'ucp:checkout',
					'ucp:orders',
					'ucp:webhooks',
				),
			),
			200
		);
	}
}
