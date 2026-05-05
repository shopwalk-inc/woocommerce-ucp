<?php
/**
 * Tests for UCP_Direct_Checkout::is_allowed_return_url() — F-B-7.
 *
 * The post-payment redirect is restricted to a small Shopwalk-owned
 * allowlist of hostnames over https with no userinfo and no non-443
 * port. An override constant SHOPWALK_RETURN_URL_ALLOWED_HOSTS lets
 * staging environments add additional hostnames.
 *
 * @package WooCommerceUCP
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/../' );

require_once __DIR__ . '/../includes/core/class-ucp-direct-checkout.php';

final class DirectCheckoutReturnUrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// wp_parse_url is just a hardened wrapper around parse_url for these
		// tests — delegating is sufficient.
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function call_is_allowed( string $url ): bool {
		$ref = new ReflectionMethod( UCP_Direct_Checkout::class, 'is_allowed_return_url' );
		$ref->setAccessible( true );
		return (bool) $ref->invoke( null, $url );
	}

	// ── Accept paths ───────────────────────────────────────────────────────

	public function test_accepts_myshopwalk_root(): void {
		$this->assertTrue( $this->call_is_allowed( 'https://myshopwalk.com/orders/123' ) );
	}

	public function test_accepts_shopwalk_root(): void {
		$this->assertTrue( $this->call_is_allowed( 'https://shopwalk.com/orders/123' ) );
	}

	public function test_accepts_shopwalk_subdomain_partners(): void {
		$this->assertTrue( $this->call_is_allowed( 'https://partners.shopwalk.com/x' ) );
	}

	public function test_accepts_shopwalk_subdomain_api(): void {
		$this->assertTrue( $this->call_is_allowed( 'https://api.shopwalk.com/x' ) );
	}

	public function test_accepts_explicit_443_port(): void {
		$this->assertTrue( $this->call_is_allowed( 'https://shopwalk.com:443/orders/1' ) );
	}

	// ── Reject paths ───────────────────────────────────────────────────────

	public function test_rejects_http_scheme(): void {
		$this->assertFalse( $this->call_is_allowed( 'http://shopwalk.com/x' ) );
	}

	public function test_rejects_unknown_host(): void {
		$this->assertFalse( $this->call_is_allowed( 'https://evil.com/x' ) );
	}

	public function test_rejects_suffix_attack(): void {
		// Naive `endswith('shopwalk.com')` or `contains` would accept this.
		// Matcher must compare exact host or `endswith('.shopwalk.com')`.
		$this->assertFalse( $this->call_is_allowed( 'https://shopwalk.com.evil.com/x' ) );
	}

	public function test_rejects_non_443_port(): void {
		$this->assertFalse( $this->call_is_allowed( 'https://shopwalk.com:8080/x' ) );
	}

	public function test_rejects_userinfo(): void {
		$this->assertFalse( $this->call_is_allowed( 'https://a:b@shopwalk.com/x' ) );
	}

	public function test_rejects_user_only_userinfo(): void {
		$this->assertFalse( $this->call_is_allowed( 'https://attacker@shopwalk.com/x' ) );
	}

	public function test_rejects_empty_url(): void {
		$this->assertFalse( $this->call_is_allowed( '' ) );
	}

	public function test_rejects_unparseable_url(): void {
		$this->assertFalse( $this->call_is_allowed( 'http://:::::' ) );
	}

	public function test_rejects_no_host(): void {
		$this->assertFalse( $this->call_is_allowed( 'https:///path' ) );
	}

	public function test_rejects_lookalike_myshopwalk(): void {
		$this->assertFalse( $this->call_is_allowed( 'https://myshopwalk.com.evil.com/x' ) );
	}

	public function test_rejects_subdomain_of_myshopwalk(): void {
		// Allowlist for `myshopwalk.com` is exact host only — no subdomain
		// wildcard. Only `shopwalk.com` carries the subdomain wildcard.
		$this->assertFalse( $this->call_is_allowed( 'https://x.myshopwalk.com/x' ) );
	}

	// ── Override constant ──────────────────────────────────────────────────

	public function test_accepts_host_added_via_override_constant(): void {
		// Define the override at runtime — the helper must consult it.
		if ( ! defined( 'SHOPWALK_RETURN_URL_ALLOWED_HOSTS' ) ) {
			define( 'SHOPWALK_RETURN_URL_ALLOWED_HOSTS', array( 'staging.example.test' ) );
		}
		$this->assertTrue( $this->call_is_allowed( 'https://staging.example.test/x' ) );
	}
}
