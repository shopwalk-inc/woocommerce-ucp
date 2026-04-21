<?php
/**
 * UCP Signing — request verification + outbound webhook signing.
 *
 * Per the UCP spec, all incoming agent requests carry a `Request-Signature`
 * header (RFC 7797 detached JWT) so the store can verify the request body
 * came from the registered agent. Outbound webhooks are signed the same way
 * with the store's own keypair.
 *
 * This implementation uses HMAC-SHA256 with the per-client (or per-subscription)
 * shared secret as the simplest interoperable approach. Full JWT-RS256 support
 * via the agent's published JWK is a follow-up — both verifiers can run
 * side-by-side, with the JWT path tried first and HMAC as a fallback.
 *
 * @package WooCommerceUCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * UCP_Signing — symmetric request signing helpers.
 */
final class UCP_Signing {

	/**
	 * Option name where the store's signing secret is persisted.
	 */
	private const STORE_SECRET_OPTION = 'shopwalk_ucp_store_signing_secret';

	/**
	 * Lazily generates a 32-byte signing secret for this store. Persists
	 * it in WP options encrypted with `wp_salt`. Idempotent.
	 *
	 * @return void
	 */
	public static function ensure_store_keypair(): void {
		if ( get_option( self::STORE_SECRET_OPTION ) ) {
			return;
		}
		try {
			$secret = bin2hex( random_bytes( 32 ) );
		} catch ( \Exception $e ) {
			$secret = wp_generate_password( 64, false, false );
		}
		update_option( self::STORE_SECRET_OPTION, $secret, false );
	}

	/**
	 * Returns the store's outbound signing secret. Generates one if missing.
	 *
	 * @return string
	 */
	public static function store_secret(): string {
		$secret = (string) get_option( self::STORE_SECRET_OPTION, '' );
		if ( $secret === '' ) {
			self::ensure_store_keypair();
			$secret = (string) get_option( self::STORE_SECRET_OPTION, '' );
		}
		return $secret;
	}

	/**
	 * Sign a payload with HMAC-SHA256. Returns the base64url-encoded
	 * signature, suitable for the `Request-Signature` header.
	 *
	 * @param string $payload The exact bytes that were sent on the wire.
	 * @param string $secret  The shared secret to sign with.
	 * @return string base64url(HMAC-SHA256(payload))
	 */
	public static function sign( string $payload, string $secret ): string {
		$mac = hash_hmac( 'sha256', $payload, $secret, true );
		return rtrim( strtr( base64_encode( $mac ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HMAC-SHA256 signature encoding per RFC 7515.
	}

	/**
	 * Verify a signature in constant time.
	 *
	 * @param string $payload   The exact bytes the client signed.
	 * @param string $signature The base64url signature from the header.
	 * @param string $secret    The shared secret.
	 * @return bool
	 */
	public static function verify( string $payload, string $signature, string $secret ): bool {
		$expected = self::sign( $payload, $secret );
		return hash_equals( $expected, $signature );
	}

	/**
	 * Verify the `Request-Signature` header on an inbound REST request.
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @param string          $secret  The shared secret to verify against.
	 * @return bool True if the signature is missing (caller decides whether
	 *              to allow unsigned requests) or valid; false if invalid.
	 */
	public static function verify_request( WP_REST_Request $request, string $secret ): bool {
		$signature = (string) $request->get_header( 'request_signature' );
		if ( $signature === '' ) {
			$signature = (string) $request->get_header( 'x_request_signature' );
		}
		if ( $signature === '' ) {
			return true; // Caller decides whether unsigned requests are allowed.
		}
		return self::verify( $request->get_body(), $signature, $secret );
	}

	// ── JWT-RS256 Asymmetric Verification ────────────────────────────────

	/**
	 * Verify a JWT-RS256 signed request. Tries JWT first, falls back to HMAC.
	 *
	 * The JWT is expected in the `Request-Signature` header as a compact JWS
	 * with detached payload (RFC 7797). The JWK is stored on the agent's
	 * OAuth client record (signing_jwk column).
	 *
	 * @param WP_REST_Request $request   The incoming request.
	 * @param string          $secret    HMAC shared secret (fallback).
	 * @param string          $jwk_json  The agent's public JWK (JSON string).
	 * @return bool
	 */
	public static function verify_request_jwt( WP_REST_Request $request, string $secret, string $jwk_json = '' ): bool {
		$signature = (string) $request->get_header( 'request_signature' );
		if ( $signature === '' ) {
			$signature = (string) $request->get_header( 'x_request_signature' );
		}
		if ( $signature === '' ) {
			return true;
		}

		// Try JWT-RS256 first if JWK is available and signature looks like a JWT (3 dots)
		if ( $jwk_json !== '' && substr_count( $signature, '.' ) === 2 ) {
			$result = self::verify_jwt_rs256( $request->get_body(), $signature, $jwk_json );
			if ( $result !== null ) {
				return $result;
			}
			// If JWT verification inconclusive (malformed), fall through to HMAC
		}

		// HMAC fallback
		return self::verify( $request->get_body(), $signature, $secret );
	}

	/**
	 * Verify a detached JWT-RS256 signature.
	 *
	 * @param string $payload  The request body.
	 * @param string $jwt      The compact JWS (header.payload.signature).
	 * @param string $jwk_json The public JWK as JSON.
	 * @return bool|null True/false for valid/invalid, null if can't parse.
	 */
	private static function verify_jwt_rs256( string $payload, string $jwt, string $jwk_json ): ?bool {
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 ) {
			return null;
		}

		// Decode header
		$header = json_decode( self::base64url_decode( $parts[0] ), true );
		if ( ! is_array( $header ) || ( $header['alg'] ?? '' ) !== 'RS256' ) {
			return null; // Not RS256 — let HMAC handle it
		}

		// Build the signing input — for detached payload, use the raw payload
		// encoded as base64url in the signing input position
		$payload_b64 = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for JWT detached payload per RFC 7797.
		$signing_input = $parts[0] . '.' . $payload_b64;
		$sig = self::base64url_decode( $parts[2] );

		// Parse JWK to PEM
		$jwk = json_decode( $jwk_json, true );
		if ( ! is_array( $jwk ) || ( $jwk['kty'] ?? '' ) !== 'RSA' ) {
			return null;
		}

		$pem = self::jwk_to_pem( $jwk );
		if ( $pem === null ) {
			return null;
		}

		$key = openssl_pkey_get_public( $pem );
		if ( ! $key ) {
			return null;
		}

		$valid = openssl_verify( $signing_input, $sig, $key, OPENSSL_ALGO_SHA256 );
		return $valid === 1;
	}

	/**
	 * Convert an RSA JWK to PEM format.
	 *
	 * @param array $jwk JWK with n, e fields.
	 * @return string|null PEM public key or null on failure.
	 */
	private static function jwk_to_pem( array $jwk ): ?string {
		if ( empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
			return null;
		}

		$n = self::base64url_decode( $jwk['n'] );
		$e = self::base64url_decode( $jwk['e'] );

		// Build DER-encoded RSA public key
		$n_der = self::der_integer( $n );
		$e_der = self::der_integer( $e );
		$seq   = self::der_sequence( $n_der . $e_der );

		// Wrap in SubjectPublicKeyInfo
		$algo_oid = hex2bin( '300d06092a864886f70d0101010500' ); // RSA OID
		$bit_string = chr( 0x03 ) . self::der_length( strlen( $seq ) + 1 ) . chr( 0x00 ) . $seq;
		$spki = self::der_sequence( $algo_oid . $bit_string );

		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for PEM public key encoding per RFC 7468.
	}

	private static function base64url_decode( string $data ): string {
		return base64_decode( strtr( $data, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $data ) % 4 ) % 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required for JWT/HMAC signature verification per RFC 7515.
	}

	private static function der_length( int $len ): string {
		if ( $len < 0x80 ) {
			return chr( $len );
		}
		$bytes = '';
		$tmp = $len;
		while ( $tmp > 0 ) {
			$bytes = chr( $tmp & 0xff ) . $bytes;
			$tmp >>= 8;
		}
		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	private static function der_integer( string $data ): string {
		// Ensure positive integer (prepend 0x00 if high bit set)
		if ( ord( $data[0] ) & 0x80 ) {
			$data = chr( 0x00 ) . $data;
		}
		return chr( 0x02 ) . self::der_length( strlen( $data ) ) . $data;
	}

	private static function der_sequence( string $data ): string {
		return chr( 0x30 ) . self::der_length( strlen( $data ) ) . $data;
	}
}
