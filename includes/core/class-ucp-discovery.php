<?php
/**
 * UCP Discovery — /.well-known/ucp + /.well-known/oauth-authorization-server.
 *
 * The well-known files written on plugin activation are static PHP shims
 * (so Apache shared hosts that rewrite the URI before WordPress sees it
 * still work). The shims include wp-load.php and dispatch to the REST
 * routes registered here.
 *
 * @package ShopwalkWooCommerce
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
		$base    = get_rest_url( null, UCP_REST_NAMESPACE );
		$version = UCP_Response::VERSION;

		$services = array(
			'dev.ucp.shopping.checkout'       => array(
				'version'   => $version,
				'spec'      => 'https://ucp.dev/latest/specification/checkout-rest/',
				'transport' => 'rest',
				'endpoint'  => $base,
			),
			'dev.ucp.shopping.order'          => array(
				'version'   => $version,
				'spec'      => 'https://ucp.dev/latest/specification/order/',
				'transport' => 'rest',
				'endpoint'  => $base,
			),
			'dev.ucp.shopping.catalog'        => array(
				'version'   => $version,
				'spec'      => 'https://ucp.dev/latest/specification/catalog/',
				'transport' => 'rest',
				'endpoint'  => $base,
			),
			'dev.ucp.common.identity_linking' => array(
				'version'   => $version,
				'spec'      => 'https://ucp.dev/latest/specification/identity-linking/',
				'transport' => 'rest',
				'endpoint'  => $base,
			),
		);

		// Capabilities mirror services but without transport/endpoint.
		$capabilities = array();
		foreach ( $services as $key => $svc ) {
			$capabilities[ $key ] = array(
				'version' => $svc['version'],
				'spec'    => $svc['spec'],
			);
		}

		$payment_handlers = class_exists( 'UCP_Payment_Router' )
			? UCP_Payment_Router::discovery_hints()
			: array();

		return new WP_REST_Response(
			array(
				'ucp'      => array(
					'version'          => $version,
					'services'         => $services,
					'capabilities'     => $capabilities,
					'payment_handlers' => empty( $payment_handlers ) ? (object) array() : $payment_handlers,
					'signing_keys'     => array(),
				),
				'platform' => 'woocommerce',
				'plugin'   => array(
					'name'    => 'Shopwalk for WooCommerce Adapter',
					'version' => WOOCOMMERCE_SHOPWALK_VERSION,
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
				'issuer'                                => (string) home_url(),
				'authorization_endpoint'                => $base . '/oauth/authorize',
				'token_endpoint'                        => $base . '/oauth/token',
				'revocation_endpoint'                   => $base . '/oauth/revoke',
				'userinfo_endpoint'                     => $base . '/oauth/userinfo',
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'token_endpoint_auth_methods_supported' => array( 'client_secret_post' ),
				'scopes_supported'                      => array(
					'ucp:checkout',
					'ucp:orders',
					'ucp:webhooks',
				),
			),
			200
		);
	}
}
