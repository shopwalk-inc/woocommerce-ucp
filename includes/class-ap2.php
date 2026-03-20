<?php
/**
 * AP2 — Merchant key generation, merchant_authorization signing, and token verification.
 *
 * Key lifecycle:
 *  - EC P-256 keypair generated on plugin activation (once).
 *  - Private key encrypted with WP auth salt and stored in wp_options.
 *  - Public JWK exposed via /.well-known/ucp signing_keys.
 *
 * Signing:
 *  - JWS Detached Content (RFC 7515 Appendix F / RFC 7797) over checkout session body.
 *  - Format: <base64url-header>..<base64url-signature>
 *
 * Token verification:
 *  - POST to https://api.shopwalk.com/ap2/detokenize with token + checkout_session_id binding.
 *
 * @package ShopwalkAI
 * @license GPL-2.0-or-later
 * @copyright Copyright (c) 2024-2026 Shopwalk, Inc.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shopwalk_AP2 class.
 */
class Shopwalk_AP2 {

	/**
	 * Generate EC P-256 key pair on plugin activation if not exists.
	 * Stores private key in wp_options (encrypted with wp_salt).
	 * Stores public JWK in wp_options for /.well-known/ucp.
	 */
	public static function maybe_generate_keys(): void {
		if ( get_option( 'shopwalk_ap2_key_id' ) ) {
			return; // already generated
		}

		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			error_log( '[Shopwalk AP2] OpenSSL not available — cannot generate EC key' );
			return;
		}

		$key = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);

		if ( ! $key ) {
			error_log( '[Shopwalk AP2] Failed to generate EC key pair' );
			return;
		}

		$key_id = 'sw-merchant-' . substr( md5( get_site_url() ), 0, 8 );

		openssl_pkey_export( $key, $private_pem );
		$details    = openssl_pkey_get_details( $key );
		$pub_coords = $details['ec'];

		$jwk = array(
			'kid' => $key_id,
			'kty' => 'EC',
			'crv' => 'P-256',
			'x'   => self::base64url( $pub_coords['x'] ),
			'y'   => self::base64url( $pub_coords['y'] ),
			'use' => 'sig',
			'alg' => 'ES256',
		);

		// Encrypt private key with WP auth key before storing.
		$encrypted = self::encrypt_private_key( $private_pem );

		update_option( 'shopwalk_ap2_key_id', $key_id, false );
		update_option( 'shopwalk_ap2_private_key', $encrypted, false );
		update_option( 'shopwalk_ap2_public_jwk', wp_json_encode( $jwk ), false );

		error_log( '[Shopwalk AP2] EC P-256 keypair generated; kid=' . $key_id );
	}

	/**
	 * Sign data with the merchant's EC private key.
	 * Returns base64url-encoded DER signature (ES256).
	 *
	 * @param string $data Data to sign.
	 */
	public static function sign( string $data ): ?string {
		$encrypted_pem = get_option( 'shopwalk_ap2_private_key' );
		if ( ! $encrypted_pem ) {
			return null;
		}
		$pem = self::decrypt_private_key( $encrypted_pem );
		$key = openssl_pkey_get_private( $pem );
		if ( ! $key ) {
			return null;
		}

		openssl_sign( $data, $signature, $key, OPENSSL_ALGO_SHA256 );
		return self::base64url( $signature );
	}

	/**
	 * Create a JWS Detached Content signature (RFC 7515 Appendix F) over checkout body.
	 * Format: <base64url-header>..<base64url-signature>
	 *
	 * @param string $checkout_body_json Serialized checkout session response body.
	 */
	public static function merchant_authorization( string $checkout_body_json ): ?string {
		$key_id = get_option( 'shopwalk_ap2_key_id' );
		if ( ! $key_id ) {
			return null;
		}

		$header = self::base64url(
			wp_json_encode(
				array(
					'alg' => 'ES256',
					'kid' => $key_id,
				)
			)
		);

		// Detached: sign over header + "." + payload but omit payload in output.
		$signing_input = $header . '.' . self::base64url( $checkout_body_json );

		$encrypted_pem = get_option( 'shopwalk_ap2_private_key' );
		if ( ! $encrypted_pem ) {
			return null;
		}
		$pem = self::decrypt_private_key( $encrypted_pem );
		$key = openssl_pkey_get_private( $pem );
		if ( ! $key ) {
			return null;
		}

		if ( ! openssl_sign( $signing_input, $raw_sig, $key, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}

		$sig = self::base64url( $raw_sig );

		// Detached format: header..signature (empty payload section).
		return $header . '..' . $sig;
	}

	/**
	 * Verify an AP2 token with Shopwalk's detokenize endpoint.
	 *
	 * @param string $token               AP2 token from buyer.
	 * @param string $checkout_session_id UCP checkout session ID for binding.
	 *
	 * @return array{authorized: bool, error?: string}
	 */
	public static function verify_token( string $token, string $checkout_session_id ): array {
		$response = wp_remote_post(
			'https://api.shopwalk.com/ap2/detokenize',
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'token'   => $token,
						'binding' => array( 'checkout_session_id' => $checkout_session_id ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'authorized' => false, 'error' => $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $body['authorized'] ) ) {
			return array( 'authorized' => false, 'error' => 'detokenize_failed' );
		}

		return $body;
	}

	/**
	 * Get the public JWK for /.well-known/ucp signing_keys.
	 *
	 * @return array|null JWK array or null if not yet generated.
	 */
	public static function get_public_jwk(): ?array {
		$jwk_json = get_option( 'shopwalk_ap2_public_jwk' );
		return $jwk_json ? json_decode( $jwk_json, true ) : null;
	}

	// -------------------------------------------------------------------------
	// Private helpers.
	// -------------------------------------------------------------------------

	/**
	 * URL-safe base64 encoding without padding.
	 *
	 * @param string $data Raw binary or string data.
	 */
	private static function base64url( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Encrypt a private key PEM string using AES-256-CBC with the WP auth salt.
	 *
	 * @param string $pem Private key PEM.
	 */
	private static function encrypt_private_key( string $pem ): string {
		$salt = wp_salt( 'auth' );
		return base64_encode( openssl_encrypt( $pem, 'AES-256-CBC', $salt, 0, substr( $salt, 0, 16 ) ) );
	}

	/**
	 * Decrypt a private key PEM string encrypted by encrypt_private_key().
	 *
	 * @param string $encrypted Base64-encoded ciphertext.
	 */
	private static function decrypt_private_key( string $encrypted ): string {
		$salt = wp_salt( 'auth' );
		return openssl_decrypt( base64_decode( $encrypted ), 'AES-256-CBC', $salt, 0, substr( $salt, 0, 16 ) );
	}
}
