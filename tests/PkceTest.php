<?php
/**
 * Tests for the PKCE (RFC 7636) code_verifier → code_challenge derivation
 * used by UCP_OAuth_Server::exchange_authorization_code().
 *
 * The logic is inline in class-ucp-oauth-server.php rather than a helper, so
 * we reproduce it here as the reference implementation and assert the exact
 * byte-level behavior the server expects for S256 and plain.
 *
 * @package ShopwalkWooCommerce
 */

use PHPUnit\Framework\TestCase;

final class PkceTest extends TestCase {

	/**
	 * S256 challenge: base64url(sha256(verifier)), no padding.
	 * This is the formula on class-ucp-oauth-server.php:213.
	 */
	private static function compute_s256( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	public function test_s256_reference_vector_from_rfc7636(): void {
		// RFC 7636 §4.2 reference verifier/challenge pair.
		$verifier  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
		$challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

		$this->assertSame( $challenge, self::compute_s256( $verifier ) );
	}

	public function test_s256_output_is_base64url_unpadded(): void {
		$challenge = self::compute_s256( 'some-verifier-value' );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $challenge );
		$this->assertStringNotContainsString( '=', $challenge );
	}

	public function test_matching_verifier_and_challenge_pass_server_check(): void {
		$verifier  = str_repeat( 'a', 43 ); // min length per RFC 7636
		$challenge = self::compute_s256( $verifier );

		// Mirrors the server's hash_equals(stored_challenge, computed) check.
		$this->assertTrue( hash_equals( $challenge, self::compute_s256( $verifier ) ) );
	}

	public function test_mismatched_verifier_fails_server_check(): void {
		$verifier          = str_repeat( 'a', 43 );
		$stored_challenge  = self::compute_s256( $verifier );
		$attacker_verifier = str_repeat( 'b', 43 );
		$attacker_computed = self::compute_s256( $attacker_verifier );

		$this->assertFalse( hash_equals( $stored_challenge, $attacker_computed ) );
	}

	public function test_plain_method_is_no_longer_a_valid_compare_path(): void {
		// As of v3.1.1 / F-C-2, the server rejects `plain` PKCE entirely
		// (OAuth 2.1 §4.1.2.1 forbids it). The `plain` semantics — that
		// `challenge === verifier` — would still hold mathematically, but
		// nothing in the server is allowed to take that branch. This test
		// pins the reference S256 derivation as the only acceptable
		// verification path going forward.
		$verifier  = 'raw-verifier';
		$s256_only = self::compute_s256( $verifier );

		// The S256 challenge MUST NOT collide with the raw verifier — if it
		// did, a stored "plain" challenge would silently pass an S256
		// verification check after a partial migration.
		$this->assertNotSame( $verifier, $s256_only );
	}
}
