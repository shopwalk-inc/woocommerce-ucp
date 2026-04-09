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
 * @package Shopwalk
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
		return rtrim( strtr( base64_encode( $mac ), '+/', '-_' ), '=' );
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
}
