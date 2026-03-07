<?php
/**
 * Business Profile — served at /.well-known/ucp
 *
 * Implements the Universal Commerce Protocol (UCP) discovery document,
 * enabling AI agents to auto-configure against this store's capabilities.
 *
 * Spec: https://ucp.dev/latest/specification/checkout-rest/
 * Version: 2026-01-23
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_WC_Profile class.
 */
class Shopwalk_WC_Profile {

	/**
	 * Build the full UCP discovery document.
	 * Schema: UCP 2026-01-23
	 */
	public static function get_business_profile(): array {
		$site_url  = home_url();
		$site_name = get_bloginfo( 'name' );
		$rest_base = rest_url( 'shopwalk/v1' );

		$logo = has_custom_logo()
			? wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' )
			: null;

		return array(
			'ucp'              => array( 'version' => '2026-01-23' ),
			'id'               => $site_url,
			'name'             => $site_name,
			'logo'             => $logo,

			// UCP capabilities — keyed by capability URN.
			'capabilities'     => array(
				'dev.ucp.shopping.checkout' => array(
					array(
						'version' => '2026-01-23',
						'config'  => array(
							'endpoint' => $rest_base . '/checkout-sessions',
						),
					),
				),
				'dev.ucp.shopping.order'    => array(
					array(
						'version' => '2026-01-23',
						'config'  => array(
							'webhook_url' => 'https://api.shopwalk.com/api/v1/ucp/webhooks/orders',
						),
					),
				),
			),

			// Payment handlers — Shopwalk Pay uses a Stripe PaymentMethod token.
			'payment_handlers' => array(
				'com.shopwalk.payment' => array(
					array(
						'id'      => 'shopwalk_pay',
						'version' => '2026-01-23',
						'config'  => array(
							'merchant_id' => self::get_merchant_id(),
						),
					),
				),
			),

			// EC P-256 signing keys for outbound webhook JWT signatures.
			'signing_keys'     => self::get_signing_keys(),
		);
	}

	/**
	 * Return the merchant ID (option or site-URL derived).
	 */
	public static function get_merchant_id(): string {
		$configured = get_option( 'shopwalk_wc_merchant_id', '' );
		if ( ! empty( $configured ) ) {
			return $configured;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return str_replace( '.', '-', $host ?? 'unknown' );
	}

	/**
	 * Return the public signing key(s) for this store.
	 * Generates an EC P-256 keypair on first call; stores private key in WP options.
	 */
	public static function get_signing_keys(): array {
		$public_jwk = get_option( 'shopwalk_wc_signing_key_public', '' );

		if ( empty( $public_jwk ) ) {
			$keypair = self::generate_ec_keypair();
			if ( $keypair ) {
				update_option( 'shopwalk_wc_signing_key_private', $keypair['private'], false );
				update_option( 'shopwalk_wc_signing_key_public', $keypair['public'], false );
				$public_jwk = $keypair['public'];
			}
		}

		if ( empty( $public_jwk ) ) {
			return array();
		}

		$jwk = json_decode( $public_jwk, true );
		if ( ! is_array( $jwk ) ) {
			return array();
		}

		$jwk['kid'] = 'key-1';
		return array( $jwk );
	}

	/**
	 * Sign a payload with the store's EC P-256 private key.
	 * Returns a detached JWT (RFC 7797) string, or empty string on failure.
	 *
	 * @param  string $payload JSON-encoded request/webhook body.
	 * @return string Compact detached JWT header..signature
	 */
	public static function sign_payload( string $payload ): string {
		$private_pem = get_option( 'shopwalk_wc_signing_key_private', '' );
		if ( empty( $private_pem ) ) {
			return '';
		}

		$header = self::base64url_encode(
			wp_json_encode(
				array(
					'alg' => 'ES256',
					'kid' => 'key-1',
				)
			)
		);
		// Detached JWT: header + ".." + signature (empty payload per RFC 7797).
		$signing_input = $header . '.' . self::base64url_encode( $payload );

		$key = openssl_pkey_get_private( $private_pem );
		if ( ! $key ) {
			return '';
		}

		$signature = '';
		if ( ! openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			return '';
		}

		return $header . '..' . self::base64url_encode( $signature );
	}

	// -------------------------------------------------------------------------
	// Private helpers.
	// -------------------------------------------------------------------------

	/**
	 * Generate an EC P-256 keypair and return PEM private key + JWK public key.
	 * Returns null if OpenSSL EC key generation is unavailable.
	 *
	 * @return array{private: string, public: string}|null
	 */
	private static function generate_ec_keypair(): ?array {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return null;
		}

		$res = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1', // P-256.
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);

		if ( ! $res ) {
			return null;
		}

		// Export private key PEM.
		openssl_pkey_export( $res, $private_pem );

		// Export public key details.
		$details = openssl_pkey_get_details( $res );
		if ( ! $details || ! isset( $details['ec'] ) ) {
			return null;
		}

		// Build JWK from raw EC key bytes.
		$jwk = array(
			'kty' => 'EC',
			'crv' => 'P-256',
			'x'   => self::base64url_encode( $details['ec']['x'] ),
			'y'   => self::base64url_encode( $details['ec']['y'] ),
		);

		return array(
			'private' => $private_pem,
			'public'  => wp_json_encode( $jwk ),
		);
	}

	/**
	 * URL-safe base64 encoding (no padding).
	 *
	 * @param string $data Profile data.
	 */
	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
