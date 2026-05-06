<?php
/**
 * Tests for Shopwalk_Connect::connect_url() — the URL the "Connect to
 * Shopwalk" CTA in WP Admin sends merchants to.
 *
 * Critical invariant: the inner OAuth authorize URL (with its own
 * site_url / state / callback query string) must arrive at the
 * /partners/signup page intact, so that signup → magic-link → /auth/verify
 * can route the merchant on to /partners/oauth/plugin/authorize with all
 * three params populated.
 *
 * The original 3.1.5 implementation used `add_query_arg( [ 'next' => $next ], $base )`
 * which silently failed because WP's build_query() invokes _http_build_query
 * with $urlencode = false. The inner `?` and `&` separators flowed through
 * raw, producing a URL like:
 *
 *   /partners/signup?next=/.../authorize?site_url=…&state=…&callback=…
 *
 * which the URLSearchParams parser splits on `&`, leaving `state` and
 * `callback` as siblings of `next` instead of inside it. The OAuth
 * approve page then complained that two of its three required params
 * were missing. This test pins the encoding so a re-introduction of
 * that bug fails CI before tagging.
 *
 * @package ShopwalkWooCommerce
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'SHOPWALK_SIGNUP_URL' ) || define( 'SHOPWALK_SIGNUP_URL', 'https://shopwalk.com/partners/signup' );
defined( 'SHOPWALK_API_BASE' ) || define( 'SHOPWALK_API_BASE', 'https://api.shopwalk.test/api/v1' );
defined( 'WOOCOMMERCE_SHOPWALK_VERSION' ) || define( 'WOOCOMMERCE_SHOPWALK_VERSION', '3.1.6-test' );

require_once __DIR__ . '/../includes/shopwalk/class-shopwalk-connect.php';

final class ConnectUrlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_generate_password' )->justReturn( 'STATE_NONCE_FIXED' );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( 'https://shopwalkstore.com' );
		Functions\when( 'admin_url' )->alias(
			function ( $path ) {
				return 'https://shopwalkstore.com/wp-admin/' . ltrim( $path, '/' );
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			function ( $args, $base ) {
				// Mirror WP's add_query_arg: build_query passes $urlencode=false
				// to _http_build_query, so NEW array values (the ones we
				// pass) are concatenated into the query string RAW. Caller
				// must pre-encode any value that contains `?`, `&`, `=`.
				// (Existing query params from $base would be re-encoded via
				// urlencode_deep, but we don't have any here.)
				$pairs = array();
				foreach ( $args as $k => $v ) {
					$pairs[] = rawurlencode( (string) $k ) . '=' . (string) $v; // <-- value NOT encoded, matching WP
				}
				$sep = str_contains( (string) $base, '?' ) ? '&' : '?';
				return $base . $sep . implode( '&', $pairs );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_connect_url_emits_flat_plugin_params_for_signup(): void {
		$url = Shopwalk_Connect::connect_url();

		// Outer URL must point at the signup page.
		$this->assertStringStartsWith( 'https://shopwalk.com/partners/signup?', $url );

		// Parse the outer query.
		$query  = parse_url( $url, PHP_URL_QUERY );
		$parsed = array();
		parse_str( (string) $query, $parsed );

		// No nested `next=` — that shape forced double-percent-encoding
		// (%2526, %253A …) which some WAFs flag as evasion and blocked
		// outright. We're now passing flat top-level params so signup can
		// reconstruct the OAuth URL itself.
		$this->assertArrayNotHasKey( 'next', $parsed, 'Connect URL must NOT use nested ?next= (WAF-hostile)' );

		// Source marker so signup knows to look for p_* params.
		$this->assertSame( 'plugin', $parsed['source'] ?? null );

		// All three OAuth params present and single-encoded — no `%25`
		// sequences anywhere in the URL.
		$this->assertSame( 'https://shopwalkstore.com', $parsed['p_site_url'] ?? null );
		$this->assertSame( 'STATE_NONCE_FIXED', $parsed['p_state'] ?? null );
		$this->assertSame(
			'https://shopwalkstore.com/wp-admin/admin.php?page=shopwalk-for-woocommerce&action=oauth-callback',
			$parsed['p_callback'] ?? null,
			'p_callback must round-trip with its own ?page=…&action=… query string'
		);

		// Pin the no-double-encoding invariant: the URL must not contain
		// any `%25` (encoded `%`) sequences. If a future change reintroduces
		// nested URLs, this fails immediately.
		$this->assertStringNotContainsString( '%25', $url, 'Connect URL must not double-encode' );
	}
}
