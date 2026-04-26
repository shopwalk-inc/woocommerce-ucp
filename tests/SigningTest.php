<?php
/**
 * Tests for UCP_Signing — HMAC-SHA256 request + webhook signing.
 *
 * These cover the symmetric (HMAC) path, which is the baseline for every
 * UCP request signature and every outbound webhook. RS256/JWT asymmetric
 * verification requires openssl + a real JWK and is covered by integration
 * tests.
 *
 * @package WooCommerceUCP
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/core/class-ucp-signing.php';

final class SigningTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// In-memory option store so ensure_store_keypair() / store_secret()
		// behave like WP options without a real DB.
		$options = array();
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( &$options ) {
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( &$options ) {
				$options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_generate_password' )->alias( fn( $len = 12 ) => str_repeat( 'x', $len ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sign_is_deterministic(): void {
		$a = UCP_Signing::sign( 'hello world', 'secret-key' );
		$b = UCP_Signing::sign( 'hello world', 'secret-key' );
		$this->assertSame( $a, $b );
	}

	public function test_sign_varies_with_payload(): void {
		$a = UCP_Signing::sign( 'hello', 'k' );
		$b = UCP_Signing::sign( 'world', 'k' );
		$this->assertNotSame( $a, $b );
	}

	public function test_sign_varies_with_secret(): void {
		$a = UCP_Signing::sign( 'hello', 'k1' );
		$b = UCP_Signing::sign( 'hello', 'k2' );
		$this->assertNotSame( $a, $b );
	}

	public function test_sign_is_base64url_no_padding(): void {
		$sig = UCP_Signing::sign( 'some data', 'shared-secret' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $sig );
		$this->assertStringNotContainsString( '=', $sig );
		$this->assertStringNotContainsString( '+', $sig );
		$this->assertStringNotContainsString( '/', $sig );
	}

	public function test_verify_accepts_valid_signature(): void {
		$payload = '{"event":"order.created","order_id":42}';
		$secret  = 'webhook-shared-secret';
		$sig     = UCP_Signing::sign( $payload, $secret );

		$this->assertTrue( UCP_Signing::verify( $payload, $sig, $secret ) );
	}

	public function test_verify_rejects_tampered_payload(): void {
		$secret = 's';
		$sig    = UCP_Signing::sign( 'original', $secret );

		$this->assertFalse( UCP_Signing::verify( 'tampered', $sig, $secret ) );
	}

	public function test_verify_rejects_wrong_secret(): void {
		$payload = 'abc';
		$sig     = UCP_Signing::sign( $payload, 'right' );

		$this->assertFalse( UCP_Signing::verify( $payload, $sig, 'wrong' ) );
	}

	public function test_verify_rejects_garbage_signature(): void {
		$this->assertFalse( UCP_Signing::verify( 'payload', 'not-a-signature', 'secret' ) );
	}

	public function test_ensure_store_keypair_generates_and_persists_secret(): void {
		UCP_Signing::ensure_store_keypair();
		$secret = UCP_Signing::store_secret();

		$this->assertNotEmpty( $secret );
		$this->assertGreaterThanOrEqual( 32, strlen( $secret ) );
	}

	public function test_store_secret_is_stable_across_calls(): void {
		$first  = UCP_Signing::store_secret();
		$second = UCP_Signing::store_secret();

		$this->assertSame( $first, $second, 'store_secret must not rotate on every call' );
	}
}
