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

	public function test_connect_url_round_trips_oauth_params_through_signup_next(): void {
		$url = Shopwalk_Connect::connect_url();

		// Outer URL must point at the signup page.
		$this->assertStringStartsWith( 'https://shopwalk.com/partners/signup?next=', $url );

		// Parse the outer query and recover `next`.
		$query  = parse_url( $url, PHP_URL_QUERY );
		$parsed = array();
		parse_str( $query, $parsed );
		$this->assertArrayHasKey( 'next', $parsed, 'Outer URL must have a `next` query param' );

		// `state` and `callback` must NOT have leaked out as outer-level
		// params — that's exactly the bug this test is guarding against.
		$this->assertArrayNotHasKey( 'state', $parsed, 'state must live inside next, not as an outer sibling' );
		$this->assertArrayNotHasKey( 'callback', $parsed, 'callback must live inside next, not as an outer sibling' );
		$this->assertArrayNotHasKey( 'site_url', $parsed, 'site_url must live inside next, not as an outer sibling' );

		// Recover the inner URL the way shopwalk-web's signup page does:
		// `useSearchParams().get('next')` returns the URL-decoded value.
		$next = $parsed['next'];
		$this->assertStringStartsWith( '/partners/oauth/plugin/authorize?', $next );

		// The inner URL must carry all three OAuth params, intact.
		$inner_query = parse_url( $next, PHP_URL_QUERY );
		$inner       = array();
		parse_str( $inner_query, $inner );
		$this->assertSame( 'https://shopwalkstore.com', $inner['site_url'] ?? null );
		$this->assertSame( 'STATE_NONCE_FIXED', $inner['state'] ?? null );
		$this->assertSame(
			'https://shopwalkstore.com/wp-admin/admin.php?page=shopwalk-for-woocommerce&action=oauth-callback',
			$inner['callback'] ?? null,
			'callback must round-trip with its own ?page=…&action=… query string'
		);
	}
}
